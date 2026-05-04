<?php
/**
 * 实时检查域名 NS 是否已指向 DNS-LA（直接 DNS 查询，不依赖 DNS-LA 缓存）
 * POST body: { account_id, domains: ["a.com","b.com"] }
 *
 * 先从 DNS-LA 取一个样本域名的 NS 服务器列表，作为「期望 NS」。
 * 然后对每个域名做 Cloudflare DoH NS 查询，与期望 NS 对比。
 *
 * 结果：
 *   nsMatch=true  → 已接入（NS 已指向 DNS-LA）
 *   nsMatch=false → 未接入（NS 还未指向 DNS-LA）
 *   actualNs      → 域名当前实际 NS 列表
 */
require_once dirname(dirname(dirname(__DIR__))) . '/core/functions.php';
require_once dirname(__DIR__) . '/core/api.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'POST only']); exit;
}

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$accountId = $body['account_id'] ?? '';
$domains   = array_filter(array_map(
    fn($d) => rtrim(strtolower(trim($d)), '.'),
    (array)($body['domains'] ?? [])
));

if (!$accountId || empty($domains)) {
    echo json_encode(['ok' => false, 'msg' => '缺少参数']); exit;
}

$accountsFile = dirname(dirname(dirname(__DIR__))) . '/data/dns_la_accounts.json';
$accounts     = file_exists($accountsFile) ? (json_decode(file_get_contents($accountsFile), true) ?? []) : [];
$account = null;
foreach ($accounts as $a) {
    if ((string)$a['id'] === (string)$accountId) { $account = $a; break; }
}
if (!$account) { echo json_encode(['ok' => false, 'msg' => '账号不存在']); exit; }

$token = dnsla_buildToken($account['api_id'], $account['api_secret']);
$base  = $account['api_base'] ?? '';

// ── Step 1: 获取 DNS-LA 的期望 NS（取第一个域名的 NS 系统记录）──
$expectedNs = [];
$sampleDomains = array_values($domains);

// 从账号里取一个已知接入的域名拿 NS
$sampleR = dnsla_request('GET', '/api/domainList', ['pageIndex' => 1, 'pageSize' => 1, 'nsState' => 1], [], $token, $base);
$sampleId = $sampleR['data']['list'][0]['id'] ?? '';

if (!$sampleId) {
    // 尝试直接取第一个域名
    foreach ($sampleDomains as $sd) {
        $dr = dnsla_request('GET', '/api/domain', ['domain' => $sd], [], $token, $base);
        if (!empty($dr['data']['id'])) { $sampleId = $dr['data']['id']; break; }
    }
}

if ($sampleId) {
    $nsR = dnsla_request('GET', '/api/recordList',
        ['domainId' => $sampleId, 'type' => 2, 'pageIndex' => 1, 'pageSize' => 10],
        [], $token, $base);
    foreach ($nsR['data']['results'] ?? [] as $rec) {
        if ((int)($rec['type'] ?? 0) === 2) {
            $expectedNs[] = rtrim(strtolower($rec['data'] ?? ''), '.');
        }
    }
}

// 兜底：已知 DNS-LA NS
if (empty($expectedNs)) {
    $expectedNs = ['n1.xundns.com', 'n2.xundns.com'];
}

$expectedNs = array_unique($expectedNs);

// ── Step 2: 并发 Cloudflare DoH 查询每个域名的 NS 记录 ─────────
$domains = array_values($domains);

$mh = curl_multi_init();
$handles = [];
foreach ($domains as $domain) {
    $url = 'https://cloudflare-dns.com/dns-query?name=' . urlencode($domain) . '&type=NS';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/dns-json'],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $handles[$domain] = $ch;
    curl_multi_add_handle($mh, $ch);
}

$active = null;
do {
    $mrc = curl_multi_exec($mh, $active);
    if ($active) curl_multi_select($mh, 0.5);
} while ($active && $mrc === CURLM_OK);

$results = [];
foreach ($domains as $domain) {
    $ch   = $handles[$domain];
    $body = curl_multi_getcontent($ch);
    $actualNs = [];

    if ($body) {
        $json = json_decode($body, true);
        foreach ($json['Answer'] ?? [] as $ans) {
            if ((int)($ans['type'] ?? 0) === 2) { // NS
                $actualNs[] = rtrim(strtolower($ans['data'] ?? ''), '.');
            }
        }
    }

    $matched = false;
    foreach ($actualNs as $ns) {
        if (in_array($ns, $expectedNs)) { $matched = true; break; }
    }

    $results[] = [
        'domain'    => $domain,
        'nsMatch'   => $matched,
        'actualNs'  => $actualNs,
        'expectedNs'=> $expectedNs,
        'status'    => $matched ? '已接入 ✓' : (empty($actualNs) ? 'NS查询失败' : '未接入'),
    ];

    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);

echo json_encode([
    'ok'         => true,
    'expectedNs' => $expectedNs,
    'results'    => $results,
]);
