<?php
/**
 * 查询找回任务状态 + 可选重新触发验证
 * POST body: { account_id, domains: ["a.com"], retry: false }
 * Returns: { results: [{domain, state, host, data, reason, msg}] }
 *
 * state: 0=待检测  1=已找回  2=验证失败
 * retry=true: 对 state=2 的任务执行 DELETE + 重建，触发新一轮检测
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
$retry     = !empty($body['retry']);

if (!$accountId || empty($domains)) {
    echo json_encode(['ok' => false, 'msg' => '缺少参数']);
    exit;
}

$accountsFile = dirname(dirname(dirname(__DIR__))) . '/data/dns_la_accounts.json';
$accounts     = file_exists($accountsFile) ? (json_decode(file_get_contents($accountsFile), true) ?? []) : [];
$account      = null;
foreach ($accounts as $a) {
    if ((string)$a['id'] === (string)$accountId) { $account = $a; break; }
}
if (!$account) { echo json_encode(['ok' => false, 'msg' => '账号不存在']); exit; }

$token = dnsla_buildToken($account['api_id'], $account['api_secret']);
$base  = $account['api_base'] ?? '';

$results = [];
$domains = array_values($domains);
$BATCH   = 20;

foreach (array_chunk($domains, $BATCH) as $chunk) {
    // ── Step 1: 并发拉当前任务列表，拿到 domain→task 映射 ───────────
    // 先并发 POST 创建/获取任务（幂等）
    $createReqs = array_map(fn($d) => [
        'method' => 'POST', 'path' => '/api/domainRetrieve',
        'body'   => ['domain' => strtolower(trim($d)), 'type' => 1],
    ], $chunk);
    $createRes = dnsla_requestMulti($createReqs, $token, $base);

    // 收集任务 ID
    $idMap = []; // domain => taskId
    foreach ($chunk as $i => $domain) {
        $cr = $createRes[$i];
        if ($cr['ok'] && !empty($cr['data']['id'])) {
            $idMap[$domain] = $cr['data']['id'];
        }
    }

    if (empty($idMap)) {
        foreach ($chunk as $domain) {
            $results[] = ['domain' => $domain, 'state' => -1, 'msg' => '获取任务ID失败'];
        }
        continue;
    }

    // ── Step 2: 并发查任务详情 ─────────────────────────────────────
    $detailDomains = array_keys($idMap);
    $detailReqs    = array_map(fn($d) => [
        'method' => 'GET', 'path' => '/api/domainRetrieve',
        'query'  => ['id' => $idMap[$d]],
    ], $detailDomains);
    $detailRes = dnsla_requestMulti($detailReqs, $token, $base);

    foreach ($detailDomains as $j => $domain) {
        $dr    = $detailRes[$j] ?? ['ok' => false];
        $state = (int)($dr['data']['state'] ?? -1);
        $host  = $dr['data']['host']   ?? '';
        $data  = $dr['data']['data']   ?? '';
        $reason= $dr['data']['reason'] ?? '';

        // ── retry: 直接调 /api/domainRetrieveNow 立即触发验证 ─────
        if ($retry) {
            $nowR = dnsla_request('POST', '/api/domainRetrieveNow',
                [], ['id' => $idMap[$domain]], $token, $base);
            // 触发后重新查状态
            $detR2 = dnsla_request('GET', '/api/domainRetrieve', ['id' => $idMap[$domain]], [], $token, $base);
            if ($detR2['ok'] && !empty($detR2['data'])) {
                $state  = (int)($detR2['data']['state']  ?? $state);
                $host   = $detR2['data']['host']   ?? $host;
                $data   = $detR2['data']['data']   ?? $data;
                $reason = $detR2['data']['reason'] ?? $reason;
            }
        }

        $results[] = [
            'domain'    => $domain,
            'state'     => $state,
            'host'      => $host,
            'data'      => $data,
            'reason'    => $reason,
            'createdAt' => (int)($dr['data']['createdAt'] ?? 0),
            'updatedAt' => (int)($dr['data']['updatedAt'] ?? 0),
            'msg'       => '',
        ];
    }

    // 没有拿到ID的域名
    foreach ($chunk as $domain) {
        if (!isset($idMap[$domain])) {
            $results[] = ['domain' => $domain, 'state' => -1, 'msg' => '获取任务ID失败'];
        }
    }
}

echo json_encode(['ok' => true, 'results' => $results]);
