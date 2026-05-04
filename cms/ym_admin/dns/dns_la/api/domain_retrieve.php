<?php
/**
 * 批量找回域名（同步）
 * POST  body: { account_id, domains: ["a.com","b.com"] }
 * Returns: { results: [ {ok,domain,host,data,state,reason,msg} ] }
 */
require_once dirname(dirname(dirname(__DIR__))) . '/core/functions.php';
require_once dirname(__DIR__) . '/core/api.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'POST only']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$accountId = $body['account_id'] ?? '';
$domains   = array_filter(array_map('trim', (array)($body['domains'] ?? [])));

if (!$accountId || empty($domains)) {
    echo json_encode(['ok' => false, 'msg' => '缺少参数']);
    exit;
}

// 加载账号
$accountsFile = dirname(dirname(dirname(__DIR__))) . '/data/dns_la_accounts.json';
$accounts = file_exists($accountsFile) ? (json_decode(file_get_contents($accountsFile), true) ?? []) : [];
$account = null;
foreach ($accounts as $a) {
    if ((string)$a['id'] === (string)$accountId) { $account = $a; break; }
}
if (!$account) {
    echo json_encode(['ok' => false, 'msg' => '账号不存在']);
    exit;
}

// 并发：每批 20 个
$results = [];
$chunks = array_chunk(array_values($domains), 20);

foreach ($chunks as $chunk) {
    // Build concurrent POST requests to create retrieve tasks
    $token = dnsla_buildToken($account['api_id'], $account['api_secret']);
    $base  = $account['api_base'] ?? '';

    $createReqs = [];
    foreach ($chunk as $domain) {
        $createReqs[] = ['method' => 'POST', 'path' => '/api/domainRetrieve', 'body' => ['domain' => strtolower(trim($domain)), 'type' => 1]];
    }
    $createRes = dnsla_requestMulti($createReqs, $token, $base);

    // Collect IDs and build detail requests
    $detailReqs  = [];
    $domainOrder = [];
    foreach ($chunk as $idx => $domain) {
        $cr = $createRes[$idx];
        if (!$cr['ok']) {
            $results[] = ['ok' => false, 'domain' => $domain, 'host' => '', 'data' => '', 'state' => -1, 'reason' => '', 'msg' => $cr['msg'] ?: ('code:' . ($cr['code'] ?? '?'))];
            $domainOrder[] = null; // sentinel
            $detailReqs[]  = null;
        } else {
            $id = $cr['data']['id'] ?? '';
            if (!$id) {
                $results[] = ['ok' => false, 'domain' => $domain, 'host' => '', 'data' => '', 'state' => -1, 'reason' => '', 'msg' => '未返回任务ID'];
                $domainOrder[] = null;
                $detailReqs[]  = null;
            } else {
                $domainOrder[] = $domain;
                $detailReqs[]  = ['method' => 'GET', 'path' => '/api/domainRetrieve', 'query' => ['id' => $id]];
            }
        }
    }

    // Execute detail requests (skip nulls)
    $realDetailReqs = array_filter($detailReqs, fn($r) => $r !== null);
    $realDetailRes  = empty($realDetailReqs) ? [] : dnsla_requestMulti(array_values($realDetailReqs), $token, $base);

    $detailIdx = 0;
    foreach ($domainOrder as $domain) {
        if ($domain === null) continue;
        $dr = $realDetailRes[$detailIdx++] ?? ['ok' => false, 'data' => []];
        if (!$dr['ok'] || empty($dr['data'])) {
            $results[] = ['ok' => false, 'domain' => $domain, 'host' => '', 'data' => '', 'state' => -1, 'reason' => '', 'msg' => '获取详情失败'];
        } else {
            $results[] = [
                'ok'     => true,
                'domain' => $domain,
                'host'   => $dr['data']['host']   ?? '@',
                'data'   => $dr['data']['data']   ?? '',
                'state'  => (int)($dr['data']['state']  ?? 0),
                'reason' => $dr['data']['reason'] ?? '',
                'msg'    => '',
            ];
        }
    }
}

echo json_encode(['ok' => true, 'results' => $results]);
