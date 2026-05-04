<?php
/**
 * 手动触发 Redis 同步脚本
 * 用于测试和修复 Redis 缓存
 */

// 引入必要的文件
define('REDIRECT301_ROOT', __DIR__);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/admin/clone_functions.php';

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║         克隆站群重定向 - 手动同步 Redis 缓存                  ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// 获取所有站群组
$groups = _r301clone_getAllGroups();

if (empty($groups)) {
    echo "❌ 没有找到任何站群组\n";
    exit(1);
}

echo "📋 找到 " . count($groups) . " 个站群组\n\n";

// 遍历所有站群组，同步到 Redis
$successCount = 0;
$failCount = 0;

foreach ($groups as $groupId => $group) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "站群组: " . $group['name'] . " (ID: $groupId)\n";
    echo "状态: " . ($group['enabled'] ? '✓ 启用' : '✕ 禁用') . "\n";
    echo "域名数量: " . count($group['domains'] ?? []) . "\n";
    
    if (empty($group['domains'])) {
        echo "⚠️  该站群组没有域名，跳过同步\n\n";
        continue;
    }
    
    echo "域名列表:\n";
    foreach ($group['domains'] as $domain) {
        echo "  - $domain\n";
    }
    
    echo "\n🔄 开始同步到 Redis...\n";
    
    $result = _r301clone_syncGroupToRedis($groupId, $group);
    
    if ($result) {
        echo "✅ 同步成功\n";
        $successCount++;
        
        // 验证 Redis 中的数据
        echo "\n🔍 验证 Redis 缓存:\n";
        $redis = _r301clone_getRedis();
        if ($redis) {
            foreach ($group['domains'] as $domain) {
                $key = REDIS_CLONE_PREFIX . 'config:' . $domain;
                $value = $redis->get($key);
                if ($value) {
                    echo "  ✓ $key 存在\n";
                    $config = json_decode($value, true);
                    echo "    跳转类型: " . ($config['target_type'] ?? 'N/A') . "\n";
                    echo "    状态码: " . ($config['redirect_type'] ?? 'N/A') . "\n";
                } else {
                    echo "  ✗ $key 不存在\n";
                }
            }
        }
    } else {
        echo "❌ 同步失败\n";
        $failCount++;
    }
    
    echo "\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📊 同步结果汇总:\n";
echo "  ✅ 成功: $successCount 个\n";
echo "  ❌ 失败: $failCount 个\n";
echo "\n";

// 列出所有 clone:* 相关的 Redis 键
echo "🔑 Redis 中所有 clone:* 相关的键:\n";
$redis = _r301clone_getRedis();
if ($redis) {
    $keys = $redis->keys(REDIS_CLONE_PREFIX . '*');
    if (empty($keys)) {
        echo "  (无)\n";
    } else {
        foreach ($keys as $key) {
            echo "  - $key\n";
        }
    }
} else {
    echo "  ❌ 无法连接 Redis\n";
}

echo "\n✅ 同步完成\n";

