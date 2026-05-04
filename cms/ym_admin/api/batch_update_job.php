<?php
require_once dirname(__DIR__) . '/core/functions.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => '仅支持 POST']); exit;
}

$body    = json_decode(file_get_contents('php://input'), true) ?: [];
$domains = $body['domains'] ?? [];
$fields  = $body['fields']  ?? [];

if (!$domains || !is_array($domains)) {
    echo json_encode(['ok' => false, 'msg' => '域名列表为空']); exit;
}
if (!$fields || !is_array($fields)) {
    echo json_encode(['ok' => false, 'msg' => '未选择任何要修改的字段']); exit;
}

$master = getMasterDB();

// 查找所有域名对应的 id（只处理数据库中存在的）
$rows      = [];
$notFound  = [];
foreach (array_unique(array_filter(array_map('trim', $domains))) as $domain) {
    $row = db_one($master, "SELECT id FROM domains WHERE domain=?", [$domain]);
    if ($row) {
        $rows[] = ['id' => $row['id'], 'domain' => $domain];
    } else {
        $notFound[] = $domain;
    }
}

if (!$rows) {
    echo json_encode(['ok' => false, 'msg' => '输入的域名在数据库中均未找到，请先导入']); exit;
}

$settings = [
    'fields'    => $fields,
    'not_found' => $notFound,
    '_total'    => count($rows),
];

$jobId = createJob('batch_update', $rows, $settings);
launchJob($jobId);

echo json_encode([
    'ok'        => true,
    'job_id'    => $jobId,
    'matched'   => count($rows),
    'not_found' => count($notFound),
], JSON_UNESCAPED_UNICODE);
