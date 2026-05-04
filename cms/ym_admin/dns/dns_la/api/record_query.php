<?php
/**
 * 并发查询指定域名的解析记录（按类型过滤）
 * POST: account_id, domains (newline-separated), rec_type (0=全部,1=A,2=NS…)
 */
ob_start();                      // 捕获任何意外输出，防止污染 JSON
set_time_limit(300);             // 最多允许运行 5 分钟（大批量时默认30s不够）
ini_set('display_errors', '0'); // 禁止错误直接输出到响应体

require_once dirname(dirname(dirname(__DIR__))) . '/core/functions.php';
require_once dirname(__DIR__) . '/core/api.php';

header('Content-Type: application/json');

$accountId  = (int)($_POST['account_id'] ?? 0);
$rawDomains = trim($_POST['domains'] ?? '');
$recType    = (int)($_POST['rec_type'] ?? 1);
$account    = $accountId ? dnsla_getAccount($accountId) : null;

if (!$account) { ob_clean(); echo json_encode(['ok' => false, 'msg' => '账号不存在']); exit; }

$domains = array_values(array_filter(array_map(
    fn($d) => rtrim(strtolower(trim($d)), '.'),
    explode("\n", $rawDomains)
)));
if (empty($domains)) { ob_clean(); echo json_encode(['ok' => false, 'msg' => '请输入域名']); exit; }

$token = dnsla_buildToken($account['api_id'], $account['api_secret']);
$base  = $account['api_base'] ?? '';
$types = dnsla_recordTypes();

// ── Phase 1: 分批并发查询所有域名 ID（每批20，避免限速）──────────
$CONCUR = 20;
$domainIds    = []; // domain => id
$notFound     = []; // domain（code=610，真正不在账号）
$queryFailed  = []; // domain => reason（其他 API 错误）

foreach (array_chunk($domains, $CONCUR) as $batch) {
    $batch = array_values($batch);   // 重置 key，确保与 responses 下标对齐
    $reqs = array_map(
        fn($d) => ['method' => 'GET', 'path' => '/api/domain', 'query' => ['domain' => $d]],
        $batch
    );
    $responses = dnsla_requestMulti($reqs, $token, $base);
    foreach ($batch as $i => $domain) {
        $r = $responses[$i];
        if ($r['ok'] && !empty($r['data']['id'])) {
            $domainIds[$domain] = $r['data']['id'];
        } elseif ((int)($r['code'] ?? 0) === 610 || (int)($r['code'] ?? 0) === 404) {
            // 域名确实不在账号中
            $notFound[] = $domain;
        } else {
            // 其他错误（限速、网络、超时等）
            $reason = $r['msg'] ?: ('API错误码 ' . ($r['code'] ?? 0));
            $queryFailed[$domain] = $reason;
        }
    }
}

// ── Phase 2: 分批并发查询解析记录 ────────────────────────────────
$foundDomains = array_keys($domainIds);
$domainRecords = []; // domain => records[]

foreach (array_chunk($foundDomains, $CONCUR) as $batch) {
    $batch = array_values($batch);   // 重置 key
    $reqs = array_map(function($domain) use ($domainIds, $recType) {
        $q = ['pageIndex' => 1, 'pageSize' => 200, 'domainId' => $domainIds[$domain]];
        if ($recType !== 0) $q['type'] = $recType;
        return ['method' => 'GET', 'path' => '/api/recordList', 'query' => $q];
    }, $batch);

    $responses = dnsla_requestMulti($reqs, $token, $base);
    foreach ($batch as $i => $domain) {
        $rr = $responses[$i] ?? ['ok' => false];
        if (!$rr['ok']) {
            $domainRecords[$domain] = null; // 记录拉取失败
            continue;
        }
        $recs = $rr['data']['results'] ?? [];
        if ($recType !== 0) {
            $recs = array_values(array_filter($recs, fn($r) => (int)($r['type'] ?? 0) === $recType));
        }
        $rows = [];
        foreach ($recs as $rec) {
            $rows[] = [
                'id'       => $rec['id']       ?? '',
                'host'     => $rec['host']     ?? '@',
                'type'     => (int)($rec['type'] ?? 0),
                'typeName' => $types[(int)($rec['type'] ?? 0)] ?? ('TYPE' . $rec['type']),
                'data'     => $rec['data']     ?? '',
                'ttl'      => $rec['ttl']      ?? '',
                'disable'  => !empty($rec['disable']),
                'lineName' => $rec['lineName'] ?? $rec['lineId'] ?? '默认',
            ];
        }
        $domainRecords[$domain] = $rows;
    }
}

// ── Build results ────────────────────────────────────────────────
$results = [];

// 不在账号中（API 明确返回 610）
foreach ($notFound as $domain) {
    $results[] = ['domain' => $domain, 'found' => false, 'error_type' => 'not_found', 'records' => []];
}

// 查询失败（限速/网络/其他）
foreach ($queryFailed as $domain => $reason) {
    $results[] = ['domain' => $domain, 'found' => false, 'error_type' => 'api_error', 'error_msg' => $reason, 'records' => []];
}

// 在账号中的域名
foreach ($foundDomains as $domain) {
    $rows = $domainRecords[$domain] ?? null;
    if ($rows === null) {
        $results[] = ['domain' => $domain, 'found' => true, 'error_type' => 'record_fetch_failed', 'records' => []];
    } else {
        $results[] = ['domain' => $domain, 'found' => true, 'records' => $rows];
    }
}

// 按原始顺序排序
$orderMap = array_flip($domains);
usort($results, fn($a, $b) => ($orderMap[$a['domain']] ?? 999) - ($orderMap[$b['domain']] ?? 999));

ob_clean();   // 丢弃任何意外输出
echo json_encode(['ok' => true, 'results' => $results]);
