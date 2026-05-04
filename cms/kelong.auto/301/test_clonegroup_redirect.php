<?php
/**
 * 测试克隆站分组重定向
 */

// 模拟访问环境
$_SERVER['HTTP_HOST'] = 'test1.dns-diy-service.com';
$_SERVER['REQUEST_URI'] = '/page1/';
$_SERVER['HTTP_USER_AGENT'] = 'seo in my life';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

echo "=== 测试克隆站分组重定向 ===\n\n";
echo "访问: http://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}\n\n";

// 加载必要的类
require_once __DIR__ . '/core/Cache.php';
require_once __DIR__ . '/core/Logger.php';
require_once __DIR__ . '/core/Config.php';
require_once __DIR__ . '/core/RedisManager.php';
require_once __DIR__ . '/utils/SpiderDetector.php';
require_once __DIR__ . '/utils/SpiderValidator.php';
require_once __DIR__ . '/utils/PlaceholderHelper.php';
require_once __DIR__ . '/modules/RedirectModule.php';
require_once __DIR__ . '/modules/CloneGroupRedirect.php';

try {
    $logger = new \Redirect301\Core\Logger();
    $config = new \Redirect301\Core\Config();
    $redis = \Redirect301\Core\RedisManager::getInstance();
    
    echo "1. 初始化模块...\n";
    $module = new \Redirect301\Modules\CloneGroupRedirect($logger, $config, $redis);
    echo "   ✅ 模块初始化成功\n\n";
    
    echo "2. 检查是否需要重定向...\n";
    
    // 因为check()会执行exit，我们需要捕获输出
    ob_start();
    try {
        $result = $module->check();
        echo "   返回值: " . var_export($result, true) . "\n";
    } catch (Exception $e) {
        echo "   ❌ 异常: " . $e->getMessage() . "\n";
    }
    $output = ob_get_clean();
    
    if ($output) {
        echo $output;
    }
    
    echo "\n3. 检查日志记录...\n";
    $db = new SQLite3(__DIR__ . '/log/redirects.db');
    $result = $db->query("
        SELECT datetime, task_name, source_url, target_url 
        FROM redirect_logs 
        WHERE feature='clonegroup' 
        ORDER BY timestamp DESC 
        LIMIT 5
    ");
    
    $count = 0;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $count++;
        echo "   {$count}. [{$row['datetime']}] {$row['task_name']}\n";
        echo "      {$row['source_url']} -> {$row['target_url']}\n";
    }
    
    if ($count === 0) {
        echo "   ❌ 没有日志记录\n";
    } else {
        echo "   ✅ 找到 {$count} 条记录\n";
    }
    
    $db->close();
    
} catch (Exception $e) {
    echo "❌ 错误: " . $e->getMessage() . "\n";
    echo "堆栈: " . $e->getTraceAsString() . "\n";
}

echo "\n✅ 测试完成！\n";

