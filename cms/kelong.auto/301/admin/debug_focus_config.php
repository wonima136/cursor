<?php
// 直接调试脚本，查看任务配置
require_once __DIR__ . '/redis_config.php';
require_once __DIR__ . '/focus_functions.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== 智能集权任务配置调试 ===\n\n";

try {
    // 1. 从SQLite获取任务
    $tasks = _focus_getTasks();
    
    if ($tasks['success']) {
        echo "SQLite中的任务数量: " . count($tasks['tasks']) . "\n\n";
        
        foreach ($tasks['tasks'] as $task) {
            echo "任务ID: " . $task['id'] . "\n";
            echo "任务名称: " . $task['name'] . "\n";
            echo "启用状态: " . ($task['enabled'] ? '是' : '否') . "\n";
            echo "蜘蛛筛选配置: " . json_encode($task['spider_filter'], JSON_UNESCAPED_UNICODE) . "\n";
            echo "\n";
        }
    }
    
    // 2. 从Redis获取任务
    echo "\n=== Redis中的任务配置 ===\n\n";
    
    $redis = getRedis();
    $keys = $redis->keys('focus:task:*');
    
    echo "Redis中的任务数量: " . count($keys) . "\n\n";
    
    foreach ($keys as $key) {
        $data = $redis->get($key);
        if ($data) {
            $task = json_decode($data, true);
            echo "Redis Key: $key\n";
            echo "任务ID: " . ($task['id'] ?? 'N/A') . "\n";
            echo "任务名称: " . ($task['name'] ?? 'N/A') . "\n";
            echo "启用状态: " . ($task['enabled'] ? '是' : '否') . "\n";
            echo "蜘蛛筛选配置: " . json_encode($task['spider_filter'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
            echo "\n";
        }
    }
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    echo "堆栈: " . $e->getTraceAsString() . "\n";
}

