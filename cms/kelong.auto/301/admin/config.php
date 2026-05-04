<?php

// 地图重定向模块概率
$config['sitemap_probability'] = 30;
/**
 * 系统配置文件
 */

// 后台登录密码（请修改为你自己的密码）
define('ADMIN_PASSWORD', 'abingou2025');

// 系统名称配置文件
define('SYSTEM_NAME_FILE', __DIR__ . '/data/system_name.txt');

// 占位符配置文件
define('PLACEHOLDERS_FILE', __DIR__ . '/data/placeholders.json');

// Session配置
define('SESSION_NAME', 'redirect_admin');
define('SESSION_LIFETIME', 86400); // 24小时

// 数据文件路径
define('REDIRECT301_DATA_DIR', __DIR__ . '/data');
define('SETTINGS_FILE', REDIRECT301_DATA_DIR . '/settings.json');
define('DOMAINS_FILE', REDIRECT301_DATA_DIR . '/domains.json');
define('LINKS_POOL_FILE', REDIRECT301_DATA_DIR . '/links_pool.json');
define('BIGSITE_POOL_FILE', REDIRECT301_DATA_DIR . '/bigsite_pool.json');
define('BIGSITE_CACHE_FILE', REDIRECT301_DATA_DIR . '/bigsite_cache.json');
define('REDIRECT301_LOG_DIR', __DIR__ . '/../log');

// 确保数据目录存在
if (!is_dir(REDIRECT301_DATA_DIR)) {
    @mkdir(REDIRECT301_DATA_DIR, 0755, true);
}
if (!is_dir(REDIRECT301_LOG_DIR)) {
    @mkdir(REDIRECT301_LOG_DIR, 0755, true);
}

/**
 * 获取系统设置
 */
function getSettings() {
    $defaults = [
        'global_enabled' => true,           // 全局开关
        'redirect_probability' => 20,       // 全局跳转概率
        'redirect_type' => '301',           // 跳转类型：301/302/307/meta/js
        'page_filter' => 'inner',           // 页面过滤：all/inner（仅内页）
        'path_mode' => 'keep',              // 路径模式：keep/home/custom
        'keep_subdomain' => false,          // 是否保留二级域名前缀
        
        // 来源判断
        'source_detection' => [
            'enabled' => false,
            // 百度蜘蛛细分设置
            'baidu_spider' => [
                'enabled' => true,
                'baidu_render' => true,   // 渲染爬虫
                'baidu_mobile' => true,   // 移动端
                'baidu_pc' => true,       // PC端
                'ip_filter' => '',        // IP 前缀过滤（空表示不限制）
            ],
            'google_spider' => ['enabled' => true],
            'sogou_spider' => ['enabled' => true],
            'so_spider' => ['enabled' => true],
            'bing_spider' => ['enabled' => true],
            'other_spider' => ['enabled' => true],
            'normal_user' => ['enabled' => false],
        ],
        
        // 排除规则
        'exclude_rules' => [
            'urls' => [],      // 排除的URL（精确匹配）
            'patterns' => [],  // 排除的URL模式（正则）
            'directories' => [], // 排除的目录
        ],
        
        // 大站池设置（优先级最高）
        'bigsite_pool' => [
            'enabled' => false,              // 大站池开关
            'default_count' => 1,            // 默认跳转次数
        ],
        
        // 跳转模式
        'redirect_mode' => 'domain',  // domain/links/mixed
        'mixed_ratio' => 70,          // 混合模式时链接池占比
        
        // 链接池设置
        'links_pool' => [
            'default_count' => 1,            // 默认跳转次数
            'consume_condition' => 'all',    // all/spider
            'empty_action' => 'fallback',    // stop/fallback
            'selection_mode' => 'random',    // random/sequential
        ],
    ];
    
    if (file_exists(SETTINGS_FILE)) {
        $saved = json_decode(file_get_contents(SETTINGS_FILE), true);
        if ($saved) {
            return array_replace_recursive($defaults, $saved);
        }
    }
    
    return $defaults;
}

/**
 * 保存系统设置
 */
function saveSettings($settings) {
    return file_put_contents(SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * 获取域名列表
 */
function getDomains() {
    $defaults = [
        'list' => [],
        'groups' => [],
    ];
    
    if (file_exists(DOMAINS_FILE)) {
        $saved = json_decode(file_get_contents(DOMAINS_FILE), true);
        if ($saved) {
            return array_merge($defaults, $saved);
        }
    }
    
    // 尝试从旧的domain.txt导入
    $oldFile = __DIR__ . '/../domain.txt';
    if (file_exists($oldFile)) {
        $lines = file($oldFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line) && strpos($line, '#') !== 0) {
                $defaults['list'][] = [
                    'domain' => $line,
                    'weight' => 1,
                    'enabled' => true,
                    'group' => 'default',
                    'created_at' => date('Y-m-d H:i:s'),
                ];
            }
        }
        saveDomains($defaults);
    }
    
    return $defaults;
}

/**
 * 保存域名列表
 */
function saveDomains($domains) {
    return file_put_contents(DOMAINS_FILE, json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * 获取链接池
 */
function getLinksPool() {
    $defaults = [
        'links' => [],
        'stats' => [
            'total_links' => 0,
            'completed_links' => 0,
            'active_links' => 0,
            'total_remaining' => 0,
            'today_consumed' => 0,
            'today_date' => date('Y-m-d'),
        ],
    ];
    
    if (file_exists(LINKS_POOL_FILE)) {
        $saved = json_decode(file_get_contents(LINKS_POOL_FILE), true);
        if ($saved) {
            // 重置今日统计（如果日期变了）
            if (isset($saved['stats']['today_date']) && $saved['stats']['today_date'] !== date('Y-m-d')) {
                $saved['stats']['today_consumed'] = 0;
                $saved['stats']['today_date'] = date('Y-m-d');
            }
            return array_merge($defaults, $saved);
        }
    }
    
    return $defaults;
}

/**
 * 保存链接池
 */
function saveLinksPool($pool) {
    return file_put_contents(LINKS_POOL_FILE, json_encode($pool, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * 获取大站池
 */
function getBigsitePool() {
    $defaults = [
        'sites' => [],      // 大站URL列表
        'rules' => [],      // 二级域名映射规则
    ];
    
    if (file_exists(BIGSITE_POOL_FILE)) {
        $saved = json_decode(file_get_contents(BIGSITE_POOL_FILE), true);
        if ($saved) {
            return array_merge($defaults, $saved);
        }
    }
    
    return $defaults;
}

/**
 * 保存大站池
 */
function saveBigsitePool($pool) {
    return file_put_contents(BIGSITE_POOL_FILE, json_encode($pool, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * 更新链接池统计
 */
function updateLinksPoolStats(&$pool) {
    $total = count($pool['links']);
    $completed = 0;
    $active = 0;
    $remaining = 0;
    
    foreach ($pool['links'] as $link) {
        if ($link['remaining'] <= 0) {
            $completed++;
        } else {
            $active++;
            $remaining += $link['remaining'];
        }
    }
    
    $pool['stats']['total_links'] = $total;
    $pool['stats']['completed_links'] = $completed;
    $pool['stats']['active_links'] = $active;
    $pool['stats']['total_remaining'] = $remaining;
}

/**
 * 检查登录状态
 */
function checkLogin() {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_start();
    }
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * 执行登录
 */
function doLogin($password) {
    if ($password === ADMIN_PASSWORD) {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            session_start();
        }
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        return true;
    }
    return false;
}

/**
 * 执行登出
 */
function doLogout() {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_start();
    }
    session_destroy();
}

/**
 * 获取系统名称
 */
function getSystemName() {
    if (file_exists(SYSTEM_NAME_FILE)) {
        $name = trim(file_get_contents(SYSTEM_NAME_FILE));
        return !empty($name) ? $name : '301重定向管理系统';
    }
    return '301重定向管理系统';
}

/**
 * 保存系统名称
 */
function saveSystemName($name) {
    $name = trim($name);
    if (empty($name)) {
        $name = '301重定向管理系统';
    }
    
    // 确保目录存在
    $dir = dirname(SYSTEM_NAME_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    return file_put_contents(SYSTEM_NAME_FILE, $name) !== false;
}

/**
 * 获取占位符配置
 */
function getPlaceholders() {
    if (file_exists(PLACEHOLDERS_FILE)) {
        $data = json_decode(file_get_contents(PLACEHOLDERS_FILE), true);
        if (is_array($data)) {
            return $data;
        }
    }
    
    // 返回默认配置（30个空参数）
    $defaults = [];
    for ($i = 1; $i <= 30; $i++) {
        $defaults["自定义参数{$i}"] = '';
    }
    return $defaults;
}

/**
 * 保存占位符配置
 */
function savePlaceholders($data) {
    // 确保目录存在
    $dir = dirname(PLACEHOLDERS_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    // 只保存30个参数
    $toSave = [];
    for ($i = 1; $i <= 30; $i++) {
        $key = "自定义参数{$i}";
        $toSave[$key] = isset($data[$key]) ? trim($data[$key]) : '';
    }
    
    return file_put_contents(PLACEHOLDERS_FILE, json_encode($toSave, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
}

/**
 * 获取跳转日志
 */
function getRedirectLogs($domain = null, $limit = 100) {
    $logs = [];
    
    if ($domain) {
        $safeFileName = preg_replace('/[^a-zA-Z0-9\.\-]/', '_', $domain);
        $logFile = REDIRECT301_LOG_DIR . '/' . $safeFileName . '.log';
        if (file_exists($logFile)) {
            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $lines = array_reverse($lines);
            $logs = array_slice($lines, 0, $limit);
        }
    } else {
        // 获取所有日志文件
        $files = glob(REDIRECT301_LOG_DIR . '/*.log');
        $allLines = [];
        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $allLines[] = $line;
            }
        }
        // 按时间排序（假设日志格式以时间开头）
        rsort($allLines);
        $logs = array_slice($allLines, 0, $limit);
    }
    
    return $logs;
}

/**
 * 清空日志
 */
function clearLogs($domain = null) {
    if ($domain) {
        $safeFileName = preg_replace('/[^a-zA-Z0-9\.\-]/', '_', $domain);
        $logFile = REDIRECT301_LOG_DIR . '/' . $safeFileName . '.log';
        if (file_exists($logFile)) {
            @unlink($logFile);
        }
    } else {
        $files = glob(REDIRECT301_LOG_DIR . '/*.log');
        foreach ($files as $file) {
            @unlink($file);
        }
    }
}

