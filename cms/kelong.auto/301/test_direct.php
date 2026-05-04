<?php
/**
 * 直接测试重定向
 */

$_SERVER['HTTP_HOST'] = 'wap.dns-diy-service.com';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_USER_AGENT'] = 'seo in my life';

echo "开始测试重定向...\n";
echo "Host: {$_SERVER['HTTP_HOST']}\n";
echo "URI: {$_SERVER['REQUEST_URI']}\n\n";

// 引入 redirect.php
require_once __DIR__ . '/redirect.php';

echo "\n如果看到这行，说明没有重定向\n";

