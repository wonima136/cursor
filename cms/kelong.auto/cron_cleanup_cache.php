<?php
/**
 * 访问触发的缓存清理
 * 每次访问时有一定概率触发清理
 */

// 设置触发概率（1/100 = 1%的访问会触发清理）
$cleanupProbability = 100;

// 随机判断是否触发清理
if (rand(1, $cleanupProbability) !== 1) {
    // 不触发清理，直接返回
    return;
}

// 触发清理
require_once __DIR__ . '/inc/CacheManager.php';

$cacheManager = new CacheManager();

error_log("[缓存清理] 触发自动清理");

$deleted = $cacheManager->cleanupExpiredCaches();

if (!empty($deleted)) {
    error_log("[缓存清理] 已清理 " . count($deleted) . " 个过期缓存: " . implode(', ', $deleted));
} else {
    error_log("[缓存清理] 没有过期缓存");
}
