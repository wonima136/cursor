<?php
require_once __DIR__ . '/admin/config.php';
require_once __DIR__ . '/admin/focus_functions.php';

// 获取所有任务
$db = _focus_getDB();
$result = $db->query("SELECT id, name FROM focus_tasks");

echo "=== 同步所有任务到Redis ===\n\n";

$count = 0;
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $taskId = $row['id'];
    $taskName = $row['name'];
    
    echo "同步任务: {$taskName} ({$taskId})\n";
    
    $syncResult = _focus_syncTaskToRedis($taskId);
    
    if ($syncResult) {
        echo "  ✓ 成功\n";
        $count++;
    } else {
        echo "  ✗ 失败\n";
    }
    echo "\n";
}

echo "总计同步: {$count} 个任务\n";

