<?php
require_once dirname(__DIR__) . '/core/functions.php';
header('Content-Type: application/json; charset=utf-8');

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';
$ids    = array_values(array_filter(array_map('intval', $input['ids'] ?? [])));

if (!$ids) {
    echo json_encode(['ok' => false, 'msg' => '未选择域名']);
    exit;
}

if (!in_array($action, ['delete', 'normal', 'pause'])) {
    echo json_encode(['ok' => false, 'msg' => '未知操作']);
    exit;
}

// 将 ids 转为统一的 rows 格式，方便 worker 读取和计算进度
$rows  = array_map(function($id) { return ['id' => $id]; }, $ids);
$jobId = createJob('batch_action', $rows, ['action' => $action]);
launchJob($jobId);

echo json_encode(['ok' => true, 'job_id' => $jobId]);
