<?php
/**
 * 地图重定向 - 预抓取后台Worker
 */

$taskId = $argv[1] ?? '';
if (empty($taskId)) {
    exit(1);
}

// 加载配置和函数
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/redis_config.php';
require_once __DIR__ . '/sitemap_functions.php';

// 检查Redis
if (!extension_loaded('redis')) {
    error_log("Sitemap worker: Redis扩展未加载");
    exit(1);
}

$redis = getRedis();
if (!$redis) {
    error_log("Sitemap worker: 无法连接Redis");
    exit(1);
}

$prefix = REDIS_SITEMAP_PREFIX;
$statusKey = "{$prefix}task:{$taskId}:prefetch_status";

try {
    // 设置状态为运行中
    $redis->set($statusKey, 'running');
    $redis->expire($statusKey, 3600); // 1小时过期
    
    error_log("Sitemap worker: 开始抓取 {$taskId}");
    
    // 执行预抓取
    $result = _sitemap_prefetch($taskId);
    
    if ($result['success']) {
        error_log("Sitemap worker: 完成 {$taskId}，成功 {$result['success_count']}/{$result['total']}");
        // 设置状态为完成
        $redis->set($statusKey, 'completed');
        $redis->expire($statusKey, 86400); // 保留24小时
    } else {
        error_log("Sitemap worker: 失败 {$taskId}");
        $redis->set($statusKey, 'failed');
        $redis->expire($statusKey, 86400);
    }
} catch (Exception $e) {
    error_log("Sitemap worker: 异常 {$taskId} - " . $e->getMessage());
    $redis->set($statusKey, 'failed');
    $redis->expire($statusKey, 86400);
}

exit(0);

