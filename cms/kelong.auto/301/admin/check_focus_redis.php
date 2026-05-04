<?php
// 检查Redis中的focus任务配置
require_once __DIR__ . '/redis_config.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>智能集权任务Redis配置检查</h2>";
echo "<pre>";

try {
    $redis = getRedis();
    
    // 获取所有focus任务的key
    $keys = $redis->keys('focus:task:*');
    
    echo "找到 " . count($keys) . " 个任务\n\n";
    
    foreach ($keys as $key) {
        echo "==========================================\n";
        echo "Redis Key: $key\n";
        echo "==========================================\n";
        
        $data = $redis->get($key);
        
        if ($data) {
            $task = json_decode($data, true);
            
            echo "任务ID: " . ($task['id'] ?? 'N/A') . "\n";
            echo "任务名称: " . ($task['name'] ?? 'N/A') . "\n";
            echo "启用状态: " . ($task['enabled'] ? '是' : '否') . "\n";
            echo "跳转类型: " . ($task['redirect_type'] ?? 'N/A') . "\n";
            echo "概率: " . ($task['probability'] ?? 'N/A') . "%\n";
            echo "\n";
            
            echo "蜘蛛筛选配置:\n";
            $spiderFilter = $task['spider_filter'] ?? [];
            echo "  启用: " . ($spiderFilter['enabled'] ? '是' : '否') . "\n";
            if (isset($spiderFilter['types'])) {
                echo "  百度PC: " . ($spiderFilter['types']['baidu_pc'] ? '✓' : '✗') . "\n";
                echo "  百度移动: " . ($spiderFilter['types']['baidu_mobile'] ? '✓' : '✗') . "\n";
                echo "  谷歌蜘蛛: " . ($spiderFilter['types']['google'] ? '✓' : '✗') . "\n";
                echo "  搜狗蜘蛛: " . ($spiderFilter['types']['sogou'] ? '✓' : '✗') . "\n";
            }
            echo "\n";
            
            echo "目标URLs:\n";
            $targetUrls = $task['target_urls'] ?? [];
            foreach ($targetUrls as $url) {
                echo "  - $url\n";
            }
            echo "\n";
            
            echo "原始JSON:\n";
            echo json_encode($task, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        } else {
            echo "无法读取数据\n";
        }
        
        echo "\n\n";
    }
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    echo "堆栈: " . $e->getTraceAsString() . "\n";
}

echo "</pre>";

