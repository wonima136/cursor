<?php
// 批量域名任务提交（添加 / 删除）
require_once dirname(dirname(dirname(__DIR__))) . '/core/functions.php';
require_once dirname(__DIR__) . '/core/api.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'msg'=>'Method not allowed']); exit; }

$action    = trim($_POST['action'] ?? '');
$accountId = (int)($_POST['account_id'] ?? 0);
$account   = $accountId ? dnsla_getAccount($accountId) : null;

if (!$account) { echo json_encode(['ok'=>false,'msg'=>'账号不存在']); exit; }

$rawDomains = trim($_POST['domains'] ?? '');
$domains    = array_values(array_filter(array_map('trim', explode("\n", $rawDomains))));

if (empty($domains)) { echo json_encode(['ok'=>false,'msg'=>'域名列表为空']); exit; }

if ($action === 'add_domains') {
    $jobId = createJob('dns_la_add_domains', $domains, ['account_id' => $accountId]);
    launchJob($jobId);
    echo json_encode(['ok' => true, 'job_id' => $jobId]);
    exit;
}

if ($action === 'del_domains') {
    $jobId = createJob('dns_la_del_domains', $domains, ['account_id' => $accountId]);
    launchJob($jobId);
    echo json_encode(['ok' => true, 'job_id' => $jobId]);
    exit;
}

echo json_encode(['ok'=>false,'msg'=>'未知操作']);
