<?php
/**
 * 测试克隆站分组重定向模块
 */

require_once __DIR__ . '/modules/CloneGroupRedirect.php';

$module = new CloneGroupRedirect();

// 测试域名
$testCases = [
    ['wap.dns-diy-service.com', '/'],
    ['wap.dns-diy-service.com', '/test/'],
    ['mobile.dns-diy-service.com', '/'],
    ['www.dns-diy-service.com', '/'],
    ['m.dns-diy-service.com', '/'],
    ['dns-diy-service.com', '/'],
];

echo "克隆站分组重定向测试\n";
echo str_repeat("=", 80) . "\n\n";

foreach ($testCases as $case) {
    list($domain, $uri) = $case;
    echo "测试: {$domain}{$uri}\n";
    
    $result = $module->check($domain, $uri);
    
    if ($result) {
        echo "  ✅ 重定向到: {$result}\n";
    } else {
        echo "  ❌ 不重定向\n";
    }
    echo "\n";
}

