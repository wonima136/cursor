<?php
/**
 * 跳转日志数据库优化脚本
 * 功能：添加索引、优化查询性能
 */

require_once __DIR__ . '/config.php';

// 检查登录状态
if (!checkLogin()) {
    die('请先登录');
}

$dbFile = REDIRECT301_DATA_DIR . '/logs.db';

if (!file_exists($dbFile)) {
    die("❌ 数据库文件不存在: {$dbFile}\n");
}

echo "<h2>🚀 跳转日志数据库优化</h2>";
echo "<pre>";

try {
    $db = new SQLite3($dbFile);
    $db->busyTimeout(30000); // 30秒超时
    
    echo "✅ 连接数据库成功\n\n";
    
    // 获取当前记录数
    $result = $db->query("SELECT COUNT(*) as total FROM redirect_logs");
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $totalRecords = $row['total'];
    echo "📊 当前记录数: " . number_format($totalRecords) . " 条\n\n";
    
    // 获取数据库大小
    $dbSize = filesize($dbFile);
    echo "💾 数据库大小: " . formatBytes($dbSize) . "\n\n";
    
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "开始优化...\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    // 1. 检查并创建索引
    echo "【步骤1】检查和创建索引\n\n";
    
    $indexes = [
        'idx_timestamp' => 'CREATE INDEX IF NOT EXISTS idx_timestamp ON redirect_logs(timestamp DESC)',
        'idx_feature' => 'CREATE INDEX IF NOT EXISTS idx_feature ON redirect_logs(feature)',
        'idx_task_name' => 'CREATE INDEX IF NOT EXISTS idx_task_name ON redirect_logs(task_name)',
        'idx_spider_type' => 'CREATE INDEX IF NOT EXISTS idx_spider_type ON redirect_logs(spider_type)',
        'idx_feature_task_time' => 'CREATE INDEX IF NOT EXISTS idx_feature_task_time ON redirect_logs(feature, task_name, timestamp DESC)',
        'idx_feature_time' => 'CREATE INDEX IF NOT EXISTS idx_feature_time ON redirect_logs(feature, timestamp DESC)'
    ];
    
    foreach ($indexes as $name => $sql) {
        echo "  创建索引: {$name}... ";
        try {
            $db->exec($sql);
            echo "✅\n";
        } catch (Exception $e) {
            echo "⚠️ " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n";
    
    // 2. 分析表统计信息
    echo "【步骤2】分析表统计信息\n\n";
    echo "  执行 ANALYZE... ";
    try {
        $db->exec("ANALYZE redirect_logs");
        echo "✅\n\n";
    } catch (Exception $e) {
        echo "⚠️ " . $e->getMessage() . "\n\n";
    }
    
    // 3. 优化数据库文件
    echo "【步骤3】优化数据库文件\n\n";
    echo "  执行 VACUUM... ";
    try {
        $db->exec("VACUUM");
        echo "✅\n\n";
    } catch (Exception $e) {
        echo "⚠️ " . $e->getMessage() . "\n\n";
    }
    
    // 4. 显示索引信息
    echo "【步骤4】索引信息\n\n";
    $result = $db->query("SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name='redirect_logs' ORDER BY name");
    
    $indexCount = 0;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (!empty($row['sql'])) { // 排除自动创建的主键索引
            $indexCount++;
            echo "  {$indexCount}. {$row['name']}\n";
        }
    }
    
    echo "\n";
    
    // 5. 优化后的数据库大小
    clearstatcache();
    $newDbSize = filesize($dbFile);
    $sizeDiff = $dbSize - $newDbSize;
    
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "优化完成！\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    echo "📊 优化结果：\n";
    echo "  记录数: " . number_format($totalRecords) . " 条\n";
    echo "  索引数: {$indexCount} 个\n";
    echo "  优化前大小: " . formatBytes($dbSize) . "\n";
    echo "  优化后大小: " . formatBytes($newDbSize) . "\n";
    
    if ($sizeDiff > 0) {
        echo "  节省空间: " . formatBytes($sizeDiff) . " (" . round($sizeDiff / $dbSize * 100, 2) . "%)\n";
    } elseif ($sizeDiff < 0) {
        echo "  增加空间: " . formatBytes(abs($sizeDiff)) . " (索引占用)\n";
    }
    
    echo "\n✅ 数据库优化成功！查询速度将显著提升。\n";
    
    $db->close();
    
} catch (Exception $e) {
    echo "❌ 优化失败: " . $e->getMessage() . "\n";
}

echo "</pre>";

echo "<p><a href='logs.php' class='btn btn-primary'>返回跳转日志</a></p>";

/**
 * 格式化字节大小
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

