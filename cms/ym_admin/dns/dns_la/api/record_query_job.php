<?php
// 提交解析记录查询后台任务
require_once dirname(dirname(dirname(__DIR__))) . '/core/functions.php';
require_once dirname(__DIR__) . '/core/api.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'msg'=>'Method not allowed']); exit; }

$accountId = (int)($_POST['account_id'] ?? 0);
$account   = $accountId ? dnsla_getAccount($accountId) : null;
if (!$account) { echo json_encode(['ok'=>false,'msg'=>'账号不存在']); exit; }

$rawDomains = trim($_POST['domains'] ?? '');
$recType    = (int)($_POST['rec_type'] ?? 1);
$domains    = array_values(array_filter(array_map(
    fn($d) => rtrim(strtolower(trim($d)), '.'),
    explode("\n", $rawDomains)
)));
if (empty($domains)) { echo json_encode(['ok'=>false,'msg'=>'域名列表为空']); exit; }

$params = ['account_id' => $accountId, 'rec_type' => $recType];
$jobId  = createJob('dns_la_query_records', $domains, $params);
launchJob($jobId);

echo json_encode(['ok' => true, 'job_id' => $jobId, 'total' => count($domains)]);
