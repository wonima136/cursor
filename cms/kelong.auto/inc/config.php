<?php
/**
 * 全局路径配置
 * 定义项目的所有绝对路径，避免使用相对路径
 */

// 项目根目录
define('KELONG_ROOT_DIR', dirname(__DIR__));

// 核心目录
define('KELONG_INC_DIR', KELONG_ROOT_DIR . '/inc');
define('KELONG_DATA_DIR', KELONG_ROOT_DIR . '/data');
define('KELONG_TEST_DIR', KELONG_ROOT_DIR . '/test');
define('KELONG_MD_DIR', KELONG_ROOT_DIR . '/md');

// 数据子目录
define('KELONG_DOMAIN_DIR', KELONG_DATA_DIR . '/domain');
define('KELONG_DOMAIN_GROUPS_DIR', KELONG_DATA_DIR . '/domain_groups');
define('KELONG_MIRRORS_DIR', KELONG_DATA_DIR . '/mirrors');
define('KELONG_DATA_KEY_DIR', KELONG_DATA_DIR . '/data_key');
define('KELONG_ADMIN_DIR', KELONG_DATA_DIR . '/admin');
define('KELONG_API_DIR', KELONG_DATA_DIR . '/api');
define('KELONG_TASKS_DIR', KELONG_DATA_DIR . '/tasks');

// 数据库文件
define('KELONG_GROUPS_DB', KELONG_DOMAIN_GROUPS_DIR . '/groups.db');
define('KELONG_DOMAINS_DB', KELONG_DOMAIN_GROUPS_DIR . '/domains.db');
define('KELONG_GROUPS_JSON', KELONG_DOMAIN_GROUPS_DIR . '/groups.json');

// 缓存目录
define('KELONG_CACHE_DIR', KELONG_ROOT_DIR . '/cachefile_yuan');

// 其他文件
define('KELONG_CONFIG_MODE_FILE', KELONG_DATA_DIR . '/config_mode.txt');
define('KELONG_FUHAO_FILE', KELONG_DATA_DIR . '/fuhao.txt');
define('KELONG_404_FILE', KELONG_ROOT_DIR . '/404.html');

// 301 相关
define('KELONG_301_DIR', KELONG_ROOT_DIR . '/301');
define('KELONG_301_ADMIN_DIR', KELONG_301_DIR . '/admin');
define('KELONG_301_LOG_DIR', KELONG_301_DIR . '/log');
define('KELONG_301_DATA_DIR', KELONG_301_ADMIN_DIR . '/data');

// 帮助函数：获取域名缓存目录
function getKelongDomainCacheDir($domain) {
    return KELONG_CACHE_DIR . '/' . $domain;
}

// 帮助函数：获取域名配置文件
function getKelongDomainConfigFile($domain) {
    return KELONG_DOMAIN_DIR . '/' . $domain . '.json';
}

// 帮助函数：获取域名TXT配置文件（旧格式）
function getKelongDomainTxtFile($domain) {
    return KELONG_DOMAIN_DIR . '/' . $domain . '.txt';
}

// 帮助函数：获取镜像源目录
function getKelongMirrorDir($mirrorId) {
    return KELONG_MIRRORS_DIR . '/' . $mirrorId;
}
