<?php
/**
 * 检查日志路径和权限
 */

echo "=== 检查日志系统 ===\n\n";

// 1. 检查日志目录
$logDir = __DIR__ . '/log';
echo "1. 日志目录: {$logDir}\n";
echo "   - 是否存在: " . (is_dir($logDir) ? "✅ 是" : "❌ 否") . "\n";

if (!is_dir($logDir)) {
    echo "   - 尝试创建目录...\n";
    if (mkdir($logDir, 0755, true)) {
        echo "   - ✅ 创建成功\n";
    } else {
        echo "   - ❌ 创建失败\n";
    }
} else {
    echo "   - 可写: " . (is_writable($logDir) ? "✅ 是" : "❌ 否") . "\n";
    echo "   - 权限: " . substr(sprintf('%o', fileperms($logDir)), -4) . "\n";
}

// 2. 检查日志文件
$logFile = $logDir . '/redirects.db';
echo "\n2. 日志文件: {$logFile}\n";
echo "   - 是否存在: " . (file_exists($logFile) ? "✅ 是" : "❌ 否") . "\n";

if (file_exists($logFile)) {
    echo "   - 可写: " . (is_writable($logFile) ? "✅ 是" : "❌ 否") . "\n";
    echo "   - 权限: " . substr(sprintf('%o', fileperms($logFile)), -4) . "\n";
    echo "   - 大小: " . filesize($logFile) . " 字节\n";
}

// 3. 测试Logger类
echo "\n3. 测试Logger类:\n";
require_once __DIR__ . '/core/Logger.php';

try {
    $logger = new \Redirect301\Core\Logger();
    echo "   - ✅ Logger实例化成功\n";
    
    // 尝试写入一条测试日志
    $result = $logger->log([
        'client_ip' => '127.0.0.1',
        'user_agent' => 'test',
        'spider_type' => 'test',
        'spider_subtype' => 'test',
        'feature' => 'clonegroup',
        'source_url' => 'http://test.example.com',
        'target_url' => 'http://target.example.com',
        'task_name' => 'test_group',
        'status_code' => 301
    ]);
    
    if ($result) {
        echo "   - ✅ 测试日志写入成功\n";
    } else {
        echo "   - ❌ 测试日志写入失败\n";
    }
    
    // 检查文件是否创建
    if (file_exists($logFile)) {
        echo "   - ✅ 日志文件已创建\n";
        echo "   - 文件大小: " . filesize($logFile) . " 字节\n";
        
        // 查询测试记录
        $db = new SQLite3($logFile);
        $result = $db->query("SELECT COUNT(*) as count FROM redirect_logs WHERE feature='clonegroup'");
        $row = $result->fetchArray(SQLITE3_ASSOC);
        echo "   - 克隆站分组记录数: {$row['count']}\n";
        $db->close();
    }
    
} catch (Exception $e) {
    echo "   - ❌ 错误: " . $e->getMessage() . "\n";
}

// 4. 检查PHP错误日志
echo "\n4. PHP错误日志:\n";
$errorLog = ini_get('error_log');
echo "   - 路径: {$errorLog}\n";
if ($errorLog && file_exists($errorLog)) {
    echo "   - 最后10行:\n";
    $lines = file($errorLog);
    $lastLines = array_slice($lines, -10);
    foreach ($lastLines as $line) {
        if (stripos($line, 'redirect') !== false || stripos($line, 'logger') !== false) {
            echo "     " . trim($line) . "\n";
        }
    }
}

echo "\n✅ 检查完成！\n";

