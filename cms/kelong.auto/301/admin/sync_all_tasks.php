<?php
/**
 * 通过Web访问来同步所有任务到Redis
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/focus_functions.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>同步所有任务到Redis</h2>";

// 获取所有任务
$db = _focus_getDB();
$result = $db->query("SELECT id, name, enabled FROM focus_tasks");

echo "<table border='1' cellpadding='10'>";
echo "<tr><th>任务ID</th><th>任务名称</th><th>状态</th><th>同步结果</th></tr>";

$count = 0;
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $taskId = $row['id'];
    $taskName = $row['name'];
    $enabled = $row['enabled'] ? '启用' : '禁用';
    
    echo "<tr>";
    echo "<td>{$taskId}</td>";
    echo "<td>{$taskName}</td>";
    echo "<td>{$enabled}</td>";
    
    $syncResult = _focus_syncTaskToRedis($taskId);
    
    if ($syncResult) {
        echo "<td style='color:green'>✓ 成功</td>";
        $count++;
    } else {
        echo "<td style='color:red'>✗ 失败</td>";
    }
    echo "</tr>";
}

echo "</table>";
echo "<br><p>总计同步: <strong>{$count}</strong> 个任务</p>";

