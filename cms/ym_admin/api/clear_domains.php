<?php
require_once dirname(__DIR__) . '/core/functions.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => '仅支持 POST']);
    exit;
}

$master = getMasterDB();
$total  = (int)db_val($master, "SELECT COUNT(*) FROM domains");

if ($total === 0) {
    echo json_encode(['ok' => false, 'msg' => '域名库已经是空的']);
    exit;
}

// 用空 rows + clear_all 类型创建任务，total 通过 settings 传递
$jobId = createJob('clear_all', [], ['_total' => $total]);
launchJob($jobId);

echo json_encode(['ok' => true, 'job_id' => $jobId]);
