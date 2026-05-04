<?php
/**
 * 任务导出 API
 * 可通过 URL 直接访问获取 JSON 数据
 * 
 * 使用方式：
 * task_export.php?id=任务ID&key=访问密钥
 */

require_once __DIR__ . '/task_functions.php';

// 获取参数
$taskId = $_GET['id'] ?? '';
$accessKey = $_GET['key'] ?? '';

// 验证任务ID
if (empty($taskId)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['error' => '缺少任务ID参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取任务
$task = _r301task_getById($taskId);
if (!$task) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode(['error' => '任务不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 验证访问密钥（可选，用于安全性）
$expectedKey = $task['export_key'] ?? '';
if (!empty($expectedKey) && $accessKey !== $expectedKey) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['error' => '访问密钥错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 导出数据
$exportData = _r301task_export($taskId, true);

if (!$exportData) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => '导出失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

// JSON 内容
$jsonContent = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// 检查是否为下载模式
$isDownload = isset($_GET['download']) && $_GET['download'] == '1';

if ($isDownload) {
    // 下载文件模式
    $filename = 'task_' . preg_replace('/[^a-zA-Z0-9_]/', '', $task['name']) . '_' . date('Ymd_His') . '.json';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($jsonContent));
} else {
    // API 模式
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
}

header('Cache-Control: no-cache, no-store, must-revalidate');
echo $jsonContent;

