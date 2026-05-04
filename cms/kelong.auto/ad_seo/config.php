<?php
/**
 * WAF 拦截系统 - 核心配置文件
 * PHP 7.2+
 */

if (version_compare(PHP_VERSION, '7.2.0', '<')) {
    die('需要 PHP 7.2 或更高版本，当前版本：' . PHP_VERSION);
}

date_default_timezone_set('Asia/Shanghai');
error_reporting(0);
ini_set('display_errors', 0);

// 路径（__DIR__ 始终指向 ad_seo 目录）
$_ENV['WAF_CONFIG_PATH'] = __DIR__;

// ===== 后台设置 =====
define('ADMIN_TITLE', 'admin');
define('ADMIN_PASSWORD', 'abingou2025');

// ===== 拦截条件模式（全局，PC端和移动端共用）=====
// 'strict'  = UA头匹配 → 再验证真实IP的AB段（只用REMOTE_ADDR，防止伪造X-Forwarded-For绕过）
// 'ua_only' = 仅验证UA头，匹配则放行，不验证IP
define('INTERCEPT_MODE', 'strict');

// ===== PC端设置 =====
// PC端开关：false时PC用户全部放行
define('PC_ENABLED', false);
// 拦截后广告策略：'none'=不投放  'iframe'=全屏iframe覆盖  'redirect'=JS跳转
define('PC_AD_MODE', 'none');
// 广告地址（iframe覆盖地址 或 跳转目标地址）
define('PC_AD_URL', '');
// PC端广告关闭时是否仍注入统计脚本
define('PC_STAT_INJECT', false);

// ===== 移动端设置 =====
// 移动端开关：false时移动端+平板用户全部放行
define('MOBILE_ENABLED', false);
// 拦截后广告策略：'none'=不投放  'iframe'=全屏iframe覆盖  'redirect'=JS跳转
define('MOBILE_AD_MODE', 'iframe');
// 广告地址（iframe覆盖地址 或 跳转目标地址）
define('MOBILE_AD_URL', '');
// 移动端广告关闭时是否仍注入统计脚本
define('MOBILE_STAT_INJECT', false);


// ===== 百度统计 ID 列表（逗号分隔，支持多个）=====
define('STATISTICS_ID', '');

// ===== 51la 统计 ID 列表（逗号分隔，支持多个）=====
define('LA51_IDS', '');

// ===== 拦截模板目录 =====
// 对应 templates/ 下的子目录名
define('TEMPLATE_FOLDER', '404');

// ===== IP白名单远程同步URL =====
// 支持多个URL，第一个失败后依次尝试下一个，每5分钟自动同步一次
// 远程文件格式：每行一个IP，自动提取AB段写入 whitelist/ip_whitelist.json
define('IP_WHITELIST_URLS', [
    'http://ip.3306.site/data/baidu.txt',
]);

// ===== 爬虫UA列表（拦截条件判断用）=====
// UA包含以下任意关键词 → 视为爬虫UA，触发拦截条件检查
define('BOT_UA_LIST', [
    'Baiduspider',
    'Baiduspider-image',
    'Baiduspider-video',
    'Baiduspider-news',
    'Baiduspider-mobile',
    'Baiduspider-render',
    'BaiduMobaider',
    'baidu',
]);

// ===== 管理员绕过UA列表 =====
// UA包含以下任意字符串 → 跳过所有拦截，直接放行
define('UA_BYPASS_LIST', [
    'seo in my life',
]);

// ===== Redis 配置 =====
// 改为 true 启用 Redis 加速（需要服务器安装 php-redis 扩展）
define('WAF_REDIS_ENABLED', true);
define('WAF_REDIS_HOST', '127.0.0.1');
define('WAF_REDIS_PORT', 6379);
define('WAF_REDIS_AUTH', '');
define('WAF_REDIS_TIMEOUT', 0.5);

// Redis 存储DB库编号（0~15）
// 建议：将 WAF 数据单独存一个 DB，与其他业务数据隔离
// 例：DB 1 专门存 WAF 数据，DB 0 留给其他程序
define('WAF_REDIS_DB', 2);

// ===== 实例标识 =====
// 多站部署时每个站点填不同名称，用于区分 Redis 中各站的数据
// 留空则自动用安装路径生成（格式：auto_xxxxxxxx）
define('WAF_INSTANCE_ID', 'top');

// ===== 加载 Redis 管理器 =====
require_once __DIR__ . '/WafRedis.php';

// ===== 加载监控模块 =====
require_once __DIR__ . '/monitor.php';
