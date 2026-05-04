<?php
/**
 * 调试克隆站分组统计
 */

// 1. 检查日志数据库
echo "=== 检查日志数据库 ===\n";
$logDbPath = __DIR__ . '/log/redirects.db';
if (file_exists($logDbPath)) {
    $db = new SQLite3($logDbPath);
    
    // 查询克隆站分组的所有日志
    echo "\n1. 克隆站分组的所有日志记录：\n";
    $result = $db->query("
        SELECT datetime, task_name, source_url, target_url 
        FROM redirect_logs 
        WHERE feature = 'clonegroup' 
        ORDER BY timestamp DESC 
        LIMIT 10
    ");
    
    $count = 0;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $count++;
        echo "  {$count}. [{$row['datetime']}] {$row['task_name']}: {$row['source_url']} -> {$row['target_url']}\n";
    }
    
    if ($count === 0) {
        echo "  ❌ 没有找到任何克隆站分组的日志记录！\n";
    }
    
    // 统计每个分组的跳转次数
    echo "\n2. 按分组统计：\n";
    $result = $db->query("
        SELECT task_name, COUNT(*) as count 
        FROM redirect_logs 
        WHERE feature = 'clonegroup' 
        GROUP BY task_name
    ");
    
    $count = 0;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $count++;
        echo "  - {$row['task_name']}: {$row['count']} 次\n";
    }
    
    if ($count === 0) {
        echo "  ❌ 没有统计数据！\n";
    }
    
    $db->close();
} else {
    echo "❌ 日志数据库不存在: {$logDbPath}\n";
}

// 2. 检查分组数据库
echo "\n\n=== 检查分组数据库 ===\n";
$groupDbPath = __DIR__ . '/admin/data/clonegroupsite.db';
if (file_exists($groupDbPath)) {
    $db = new SQLite3($groupDbPath);
    
    // 查询所有分组
    echo "\n1. 所有分组：\n";
    $result = $db->query("SELECT group_name, group_title, domain_count FROM clonegroupsite_groups LIMIT 10");
    
    $count = 0;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $count++;
        echo "  {$count}. {$row['group_name']} ({$row['group_title']}) - {$row['domain_count']} 个域名\n";
    }
    
    if ($count === 0) {
        echo "  ❌ 没有分组数据！\n";
    }
    
    // 查询 dns-diy-service.com 所属的分组
    echo "\n2. dns-diy-service.com 所属分组：\n";
    $result = $db->query("
        SELECT g.group_name, g.group_title, d.domain 
        FROM clonegroupsite_groups g
        INNER JOIN clonegroupsite_domains d ON g.group_name = d.group_name
        WHERE d.domain = 'dns-diy-service.com'
    ");
    
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if ($row) {
        echo "  ✅ 分组名称: {$row['group_name']}\n";
        echo "  ✅ 分组标题: {$row['group_title']}\n";
        echo "  ✅ 域名: {$row['domain']}\n";
    } else {
        echo "  ❌ dns-diy-service.com 不属于任何分组！\n";
    }
    
    $db->close();
} else {
    echo "❌ 分组数据库不存在: {$groupDbPath}\n";
}

// 3. 测试统计函数
echo "\n\n=== 测试统计函数 ===\n";
require_once __DIR__ . '/admin/clonegroupsite_functions.php';

echo "\n1. 全局统计：\n";
$globalStats = _clonegroupsite_getGlobalStats();
echo "  - 总跳转: {$globalStats['total_redirects']}\n";
echo "  - 今日跳转: {$globalStats['today_redirects']}\n";
echo "  - TOP分组: " . count($globalStats['top_groups']) . " 个\n";
foreach ($globalStats['top_groups'] as $i => $group) {
    echo "    " . ($i+1) . ". {$group['name']}: {$group['count']} 次\n";
}

echo "\n2. 批量统计：\n";
$allGroupsStats = _clonegroupsite_getAllGroupsStats();
if (empty($allGroupsStats)) {
    echo "  ❌ 没有统计数据！\n";
} else {
    foreach ($allGroupsStats as $groupName => $stats) {
        echo "  - {$groupName}: {$stats['total']} 次\n";
    }
}

echo "\n✅ 调试完成！\n";

