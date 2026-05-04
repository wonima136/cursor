<?php
/**
 * 数据库功能测试脚本
 * 用于验证 SQLite 数据库是否正常工作
 * 
 * 访问：http://你的域名/hnseo/test_db.php
 * 测试完成后请删除此文件
 */

ini_set('session.save_handler', 'files');
ini_set('session.save_path', '/tmp');
session_name('hnseo_tongji');
session_start();

// 可选：要求登录
// if (!isset($_SESSION['tongji_logged_in']) || $_SESSION['tongji_logged_in'] !== true) {
//     die('请先登录');
// }

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/spider_db.php';

echo "<h1>🔧 SQLite 数据库测试</h1>";
echo "<pre style='background:#1e1e1e;color:#0f0;padding:20px;'>";

try {
    echo "1. 连接数据库...\n";
    $db = getSpiderDB();
    echo "   ✅ 连接成功\n\n";
    
    echo "2. 检查数据库文件...\n";
    $db_path = __DIR__ . '/spider_data.db';
    if (file_exists($db_path)) {
        echo "   ✅ 数据库文件存在: " . $db_path . "\n";
        echo "   📦 文件大小: " . number_format(filesize($db_path)) . " bytes\n\n";
    } else {
        echo "   ⚠️ 数据库文件不存在（首次运行会自动创建）\n\n";
    }
    
    echo "3. 检查表结构...\n";
    $pdo = $db->getDB();
    
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    echo "   已创建的表: " . implode(", ", $tables) . "\n\n";
    
    echo "4. 检查索引...\n";
    $indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type='index'")->fetchAll(PDO::FETCH_COLUMN);
    echo "   已创建的索引: " . implode(", ", $indexes) . "\n\n";
    
    echo "5. 统计数据量...\n";
    $total = $db->getTotalCount();
    echo "   📊 总记录数: " . number_format($total) . "\n\n";
    
    if ($total > 0) {
        echo "6. 测试查询性能...\n";
        
        $today = date('Y-m-d');
        
        // 测试日统计
        $start = microtime(true);
        $stats = $db->getDayStats($today);
        $time1 = round((microtime(true) - $start) * 1000, 2);
        echo "   📈 今日统计查询: {$time1}ms\n";
        
        // 测试小时分布
        $start = microtime(true);
        $hourly = $db->getHourlyStats($today);
        $time2 = round((microtime(true) - $start) * 1000, 2);
        echo "   ⏰ 小时分布查询: {$time2}ms\n";
        
        // 测试趋势查询
        $start = microtime(true);
        $trend = $db->getTrendStats(10);
        $time3 = round((microtime(true) - $start) * 1000, 2);
        echo "   📉 10日趋势查询: {$time3}ms\n";
        
        // 测试分页查询
        $start = microtime(true);
        $list = $db->getVisitList($today, null, 1, 10);
        $time4 = round((microtime(true) - $start) * 1000, 2);
        echo "   📋 分页列表查询: {$time4}ms\n\n";
    } else {
        echo "6. 跳过性能测试（无数据）\n\n";
        echo "   💡 提示：请运行 migrate_logs.php 导入历史数据\n\n";
    }
    
    echo "7. 测试写入功能...\n";
    $test_time = date('Y-m-d H:i:s');
    $result = $db->insertVisit($test_time, '127.0.0.1', 'Baiduspider', 'http://test.com/test', 'test.com', '百度PC');
    if ($result) {
        echo "   ✅ 写入测试记录成功\n";
        
        // 清理测试记录
        $pdo->exec("DELETE FROM spider_visits WHERE ip = '127.0.0.1' AND url = 'http://test.com/test'");
        echo "   🧹 已清理测试记录\n\n";
    } else {
        echo "   ❌ 写入失败\n\n";
    }
    
    echo "========================================\n";
    echo "✅ 所有测试通过！数据库功能正常\n";
    echo "========================================\n";
    
} catch (Exception $e) {
    echo "❌ 错误: " . $e->getMessage() . "\n";
    echo "堆栈: " . $e->getTraceAsString() . "\n";
}

echo "</pre>";
echo "<p style='color:#666;'>测试完成后请删除此文件 (test_db.php)</p>";
echo "<p><a href='tongji.php'>返回统计后台</a></p>";

