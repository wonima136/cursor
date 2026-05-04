<?php
/**
 * 301重定向系统 - 新版入口文件
 * 
 * 基于模块化架构重构
 * 
 * 跳转优先级：
 * 1. 整站重定向 - 优先级1
 * 2. 大站池 - 优先级2
 * 3. 寄生重定向 - 优先级3
 * 4. 地图重定向 - 优先级4.5
 * 5. 消耗池 - 优先级4
 * 6. 站群链轮 - 优先级5
 */

// /加载引导文件.
require_once __DIR__ . '/bootstrap.php';

use Redirect301\Core\Logger;
use Redirect301\Core\Config;
use Redirect301\Core\Cache;
use Redirect301\Core\RedisManager;
use Redirect301\Modules\SitewideRedirect;
use Redirect301\Modules\FocusRedirect;
use Redirect301\Modules\BigsiteRedirect;
use Redirect301\Modules\ParasiteRedirect;
use Redirect301\Modules\SitemapRedirect;
use Redirect301\Modules\TaskPoolRedirect;
use Redirect301\Modules\GroupRedirect;
use Redirect301\Modules\CloneRedirect;

// ==================== 预检查 ====================

// 排除后台目录
if (strpos($_SERVER['REQUEST_URI'] ?? '', '/admin/') === 0) {
    return;
}

// 排除静态资源
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$staticExtensions = ['jpg', 'jpeg', 'png', 'gif', 'css', 'js', 'webp', 'ico', 'svg', 'woff', 'woff2', 'ttf', 'eot'];
$pathInfo = pathinfo(strtok($uri, '?'));
if (isset($pathInfo['extension']) && in_array(strtolower($pathInfo['extension']), $staticExtensions)) {
    return;
}

// ==================== 全局蜘蛛IP验证（防假冒） ====================

require_once __DIR__ . '/utils/SpiderDetector.php';
require_once __DIR__ . '/utils/SpiderValidator.php';

use Redirect301\Utils\SpiderDetector;
use Redirect301\Utils\SpiderValidator;

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$clientIP = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

// 提取第一个IP（如果有多个代理）
if (strpos($clientIP, ',') !== false) {
    $clientIP = trim(explode(',', $clientIP)[0]);
}

error_log("redirect.php: 全局验证开始 - UA: {$userAgent}, IP: {$clientIP}");

// 检查是否是白名单UA（最高优先级，跳过所有验证）
if (stripos($userAgent, 'seo in my life') === false) {
    // 不是白名单UA，需要进行蜘蛛验证
    $spiderInfo = SpiderDetector::detect($userAgent);
    $spiderType = $spiderInfo['type'] ?? '';
    error_log("redirect.php: 蜘蛛类型 - {$spiderType}");
    
    // 如果是目标蜘蛛（百度/谷歌/搜狗），必须验证IP
    if (in_array($spiderType, ['baidu_spider', 'google_spider', 'sogou_spider'])) {
        error_log("redirect.php: 是目标蜘蛛，开始IP验证");
        // 验证IP是否在白名单
        $ipValid = SpiderValidator::checkIPWhitelist($clientIP);
        error_log("redirect.php: IP验证结果 - " . ($ipValid ? '通过' : '失败'));
        
        if (!$ipValid) {
            // 假冒蜘蛛，返回404
            error_log("redirect.php: IP验证失败，返回404");
            http_response_code(404);
            echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>404 Not Found</title>
</head>
<body>
    <h1>404 Not Found</h1>
    <p>The requested URL was not found on this server.</p>
</body>
</html>';
            exit;
        }
        error_log("redirect.php: IP验证通过");
    } else {
        error_log("redirect.php: 不是目标蜘蛛，跳过IP验证");
    }
} else {
    error_log("redirect.php: 白名单UA，跳过所有验证");
}

error_log("redirect.php: 全局验证完成，继续执行模块");

// ==================== 初始化核心组件 ====================

try {
    // 日志记录器（使用与后台一致的路径）
    $logDbPath = __DIR__ . '/admin/data/logs.db';
    $logger = new Logger($logDbPath);
    
    // 缓存管理器
    $cache = new Cache(__DIR__ . '/data/cache', 300);
    
    // 配置管理器
    $config = new Config(__DIR__ . '/admin/data', $cache);
    
    // Redis管理器
    // 优先使用新常量，兼容旧常量 SITE_ID
    $siteId = '';
    if (defined('_REDIRECT301_SITE_ID_')) {
        $siteId = _REDIRECT301_SITE_ID_;
    } elseif (defined('SITE_ID')) {
        $siteId = SITE_ID;
    }
    
    // 调试日志
    error_log("redirect.php: SITE_ID = " . (defined('SITE_ID') ? SITE_ID : 'undefined'));
    error_log("redirect.php: _REDIRECT301_SITE_ID_ = " . (defined('_REDIRECT301_SITE_ID_') ? _REDIRECT301_SITE_ID_ : 'undefined'));
    error_log("redirect.php: Final siteId = " . $siteId);
    error_log("redirect.php: REDIS_TASK_PREFIX = " . (defined('REDIS_TASK_PREFIX') ? REDIS_TASK_PREFIX : 'undefined'));
    
    $redisConfig = [
        'host' => defined('_REDIRECT301_REDIS_HOST_') ? _REDIRECT301_REDIS_HOST_ : '127.0.0.1',
        'port' => defined('_REDIRECT301_REDIS_PORT_') ? _REDIRECT301_REDIS_PORT_ : 6379,
        'password' => defined('_REDIRECT301_REDIS_PASSWORD_') ? _REDIRECT301_REDIS_PASSWORD_ : '',
        'database' => defined('_REDIRECT301_REDIS_DB_') ? _REDIRECT301_REDIS_DB_ : 1,
        'site_id' => $siteId,
    ];
    $redis = RedisManager::getInstance($redisConfig);
    
} catch (\Exception $e) {
    // error_log('Failed to initialize redirect system: ' . $e->getMessage());
    return;
}

// ==================== 初始化重定向模块 ====================

$modules = [];

try {
    // 加载克隆站重定向模块（最高优先级）
    if (file_exists(__DIR__ . '/modules/CloneRedirect.php')) {
        require_once __DIR__ . '/modules/CloneRedirect.php';
        $modules[] = new CloneRedirect($logger, $config, $redis);
    }
    
    // 加载克隆站分组重定向模块
    if (file_exists(__DIR__ . '/modules/CloneGroupRedirect.php')) {
        require_once __DIR__ . '/modules/CloneGroupRedirect.php';
        $modules[] = new \Redirect301\Modules\CloneGroupRedirect($logger, $config, $redis);
    }
    
    $modules[] = new SitewideRedirect($logger, $config, $redis);
    
    // 加载智能集权重定向模块
    if (file_exists(__DIR__ . '/modules/FocusRedirect.php')) {
        require_once __DIR__ . '/modules/FocusRedirect.php';
        $modules[] = new FocusRedirect($logger, $config, $redis);
    }
    
    $modules[] = new BigsiteRedirect($logger, $config, $redis);
    $modules[] = new ParasiteRedirect($logger, $config, $redis);
    
    // 加载地图重定向模块
    if (file_exists(__DIR__ . '/modules/SitemapRedirect.php')) {
        require_once __DIR__ . '/modules/SitemapRedirect.php';
        $modules[] = new SitemapRedirect($logger, $config, $redis);
    }
    
    $modules[] = new TaskPoolRedirect($logger, $config, $redis);
    $modules[] = new GroupRedirect($logger, $config, $redis);
} catch (\Exception $e) {
    // error_log('Failed to initialize redirect modules: ' . $e->getMessage());
    return;
}

// 按优先级排序
usort($modules, function($a, $b) {
    return $a->getPriority() - $b->getPriority();
});

// ==================== 执行重定向检查 ====================

foreach ($modules as $module) {
    try {
        $result = $module->check();
        
        // 如果模块返回了结果（执行了重定向），则不会到达这里
        // 因为 redirect() 方法会调用 exit
        
    } catch (\Exception $e) {
        // error_log('Module ' . $module->getName() . ' failed: ' . $e->getMessage());
        continue;
    }
}

// 如果所有模块都没有匹配，则不做任何处理
// 让请求继续到原始页面

