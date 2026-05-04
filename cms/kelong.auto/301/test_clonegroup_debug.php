<?php
/**
 * 调试克隆站分组重定向模块
 */

// 测试 extractBaseDomain 函数
function extractBaseDomain($domain) {
    // 去除协议
    $domain = preg_replace('#^https?://#', '', $domain);
    // 去除路径
    $domain = preg_replace('#/.*$#', '', $domain);
    // 去除端口
    $domain = preg_replace('#:\d+$#', '', $domain);
    // 去除 @ 前缀
    $domain = ltrim($domain, '@.');
    // 去除www和m前缀
    $domain = preg_replace('#^(www\.|m\.)#', '', $domain);
    
    return strtolower(trim($domain));
}

$testDomains = [
    'wap.dns-diy-service.com',
    'www.dns-diy-service.com',
    'm.dns-diy-service.com',
    'dns-diy-service.com',
];

echo "域名提取测试\n";
echo str_repeat("=", 80) . "\n\n";

foreach ($testDomains as $domain) {
    $base = extractBaseDomain($domain);
    echo "{$domain} => {$base}\n";
}

echo "\n\n数据库查询测试\n";
echo str_repeat("=", 80) . "\n\n";

$dbPath = __DIR__ . '/admin/data/clonegroupsite.db';
$db = new SQLite3($dbPath);

foreach ($testDomains as $domain) {
    $base = extractBaseDomain($domain);
    echo "查询域名: {$base}\n";
    
    $stmt = $db->prepare("
        SELECT g.*, d.domain as matched_domain
        FROM clonegroupsite_groups g
        INNER JOIN clonegroupsite_domains d ON g.group_name = d.group_name
        WHERE d.domain = ?
        LIMIT 1
    ");
    $stmt->bindValue(1, $base, SQLITE3_TEXT);
    $result = $stmt->execute();
    $group = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($group) {
        echo "  ✅ 找到分组: {$group['group_name']}\n";
        echo "  匹配域名: {$group['matched_domain']}\n";
    } else {
        echo "  ❌ 未找到\n";
    }
    echo "\n";
}

echo "\n所有域名列表:\n";
echo str_repeat("=", 80) . "\n";
$result = $db->query("SELECT domain FROM clonegroupsite_domains ORDER BY domain");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    echo "  - {$row['domain']}\n";
}

