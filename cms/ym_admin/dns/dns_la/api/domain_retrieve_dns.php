<?php
/**
 * 直接查 DNS TXT，验证找回记录是否已传播
 * 不依赖 DNS-LA 的检测队列
 * POST body: { account_id, domains: ["a.com"] }
 * Returns: { results: [{domain, host, expected, txt_found, txt_match, dnsla_state, reason}] }
 */
require_once dirname(dirname(dirname(__DIR__))) . '/core/functions.php';
require_once dirname(__DIR__) . '/core/api.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'POST only']);
    exit;
}

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$accountId = $body['account_id'] ?? '';
$domains   = array_filter(array_map('trim', (array)($body['domains'] ?? [])));

if (!$accountId || empty($domains)) {
    echo json_encode(['ok' => false, 'msg' => '缺少参数']);
    exit;
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
$domains = array_values($domains);

// ── Step 1: 并发获取/创建找回任务（拿到 host+expected TXT）──────
$createReqs = array_map(fn($d) => [
    'method' => 'POST', 'path' => '/api/domainRetrieve',
    'body'   => ['domain' => strtolower(rtrim(trim($d), '.')), 'type' => 1],
], $domains);
$createRes = dnsla_requestMulti($createReqs, $token, $base);

// 拿任务ID
$taskMap = [];
$failedDomains = [];
foreach ($domains as $i => $domain) {
    $cr = $createRes[$i];
    if ($cr['ok'] && !empty($cr['data']['id'])) {
        $taskMap[$domain] = $cr['data']['id'];
    } else {
        $failedDomains[] = $domain;
    }
}

// ── Step 2: 并发查任务详情 ──────────────────────────────────────
$detailDomains = array_keys($taskMap);
$detailReqs    = array_map(fn($d) => [
    'method' => 'GET', 'path' => '/api/domainRetrieve',
    'query'  => ['id' => $taskMap[$d]],
], $detailDomains);
$detailRes = empty($detailReqs) ? [] : dnsla_requestMulti($detailReqs, $token, $base);

$taskDetails = []; // domain => {state, host, data, reason, createdAt, updatedAt}
foreach ($detailDomains as $j => $domain) {
    $dr = $detailRes[$j] ?? ['ok' => false];
    if ($dr['ok'] && !empty($dr['data'])) {
        $taskDetails[$domain] = $dr['data'];
    }
}

// ── Step 3: 并发 DNS TXT 查询（Cloudflare DoH）──────────────────
function doh_txt_query(string $name): array {
    $url = 'https://cloudflare-dns.com/dns-query?name=' . urlencode($name) . '&type=TXT';
    $ctx = stream_context_create([
        'http' => [
            'header'  => "Accept: application/dns-json\r\n",
            'timeout' => 6,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return [];
    $json = json_decode($raw, true);
    $answers = $json['Answer'] ?? [];
    $txts = [];
    foreach ($answers as $ans) {
        if ((int)($ans['type'] ?? 0) === 16) { // TXT
            $txts[] = trim($ans['data'] ?? '', '"');
        }
    }
    return $txts;
}

// 串行查询（PHP 无多线程，用 curl_multi 模拟并发）
$dnsResults = []; // domain => [txt values]
$dnsReqs = [];
foreach ($taskDetails as $domain => $detail) {
    $host = rtrim($detail['host'] ?? '@', '.');
    $queryName = ($host === '@') ? $domain : ($host . '.' . $domain);
    $dnsReqs[$domain] = $queryName;
}

// 使用 curl_multi 并发查 DNS
$mh = curl_multi_init();
$handles = [];
foreach ($dnsReqs as $domain => $qname) {
    $url = 'https://cloudflare-dns.com/dns-query?name=' . urlencode($qname) . '&type=TXT';
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

foreach ($handles as $domain => $ch) {
    $body = curl_multi_getcontent($ch);
    $txts = [];
    if ($body) {
        $json = json_decode($body, true);
        foreach ($json['Answer'] ?? [] as $ans) {
            if ((int)($ans['type'] ?? 0) === 16) {
                $txts[] = trim($ans['data'] ?? '', '"');
            }
        }
    }
    $dnsResults[$domain] = $txts;
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);

// ── Step 4: 对 TXT 已传播的域名并发触发 domainRetrieveNow ────────
$triggerReqs = [];
$triggerDomains = [];
foreach ($taskDetails as $domain => $detail) {
    $txts = $dnsResults[$domain] ?? [];
    $expected = $detail['data'] ?? '';
    if ($expected && in_array($expected, $txts)) {
        $triggerReqs[]    = ['method' => 'POST', 'path' => '/api/domainRetrieveNow',
                             'body'   => ['id' => (string)($taskMap[$domain] ?? '')]];
        $triggerDomains[] = $domain;
    }
}
if (!empty($triggerReqs)) {
    dnsla_requestMulti($triggerReqs, $token, $base); // fire & forget
    // 短暂等待后重新拉详情（看状态变化）
    usleep(800000); // 0.8s
    $refetchReqs = array_map(fn($d) => [
        'method' => 'GET', 'path' => '/api/domainRetrieve',
        'query'  => ['id' => (string)($taskMap[$d] ?? '')],
    ], $triggerDomains);
    $refetchRes = dnsla_requestMulti($refetchReqs, $token, $base);
    foreach ($triggerDomains as $k => $domain) {
        $rf = $refetchRes[$k] ?? ['ok' => false];
        if ($rf['ok'] && !empty($rf['data'])) {
            $taskDetails[$domain] = array_merge($taskDetails[$domain], $rf['data']);
        }
    }
}

// ── Build final results ──────────────────────────────────────────
$results = [];
foreach ($domains as $domain) {
    if (isset($taskDetails[$domain])) {
        $detail   = $taskDetails[$domain];
        $expected = $detail['data']  ?? '';
        $host     = $detail['host']  ?? '@';
        $state    = (int)($detail['state'] ?? 0);
        $reason   = $detail['reason'] ?? '';
        $txts     = $dnsResults[$domain] ?? [];
        $txtMatch = $expected && in_array($expected, $txts);

        $results[] = [
            'domain'     => $domain,
            'host'       => $host,
            'expected'   => $expected,
            'txt_values' => $txts,
            'txt_match'  => $txtMatch,
            'dnsla_state'=> $state,
            'reason'     => $reason,
            'createdAt'  => (int)($detail['createdAt'] ?? 0),
            'updatedAt'  => (int)($detail['updatedAt'] ?? 0),
        ];
    } else {
        $results[] = [
            'domain'     => $domain,
            'host'       => '',
            'expected'   => '',
            'txt_values' => [],
            'txt_match'  => false,
            'dnsla_state'=> -1,
            'reason'     => '获取任务失败',
            'createdAt'  => 0,
            'updatedAt'  => 0,
        ];
    }
}

echo json_encode(['ok' => true, 'results' => $results]);
