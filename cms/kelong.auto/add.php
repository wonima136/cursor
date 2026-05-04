<?php
/**
 * 统一接入文件 - add.php
 *
 * 按照指定顺序处理各个功能：
 * 1. WAF 拦截（UA/IP验证）
 * 2. 通过访问后统计访问信息
 * 3. 301跳转接入
 * 4. 友情链接接入
 *
 * 使用方法：在网站的 index.php 开头引入
 * require_once 'add.php';
 */

// 防止重复加载
if (defined('_UNIFIED_ADD_LOADED_')) {
    return;
}
define('_UNIFIED_ADD_LOADED_', true);

// ===== 系统文件夹排除列表 =====
$_add_excluded_paths = [
    '/data/',
    '/inc/',
];

$_add_request_uri = $_SERVER['REQUEST_URI'] ?? '/';
foreach ($_add_excluded_paths as $_add_excluded_path) {
    if (strpos($_add_request_uri, $_add_excluded_path) === 0) {
        return;
    }
}

$_add_base_path = __DIR__;

try {

// ===== 1. WAF 拦截（UA/IP验证）=====
$waf_config = $_add_base_path . '/ad_seo/config.php';

if (file_exists($waf_config)) {
    try {
        require_once $waf_config;
    } catch (Exception $e) {
        error_log('WAF Error(Exception): ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    } catch (Error $e) {
        error_log('WAF Error(FatalError): ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
} else {
    error_log('WAF Error: 文件不存在 - ' . $waf_config);
}

// ===== 2. 通过访问后统计访问信息 =====
$stats_script = $_add_base_path . '/hnseo/2.php';
$stats_dir = $_add_base_path . '/hnseo';

if (file_exists($stats_script) && is_dir($stats_dir)) {
    try {
        $original_cwd = getcwd();
        chdir($stats_dir);
        include '2.php';
        chdir($original_cwd);
    } catch (Exception $e) {
        error_log('Stats Error: ' . $e->getMessage());
        if (isset($original_cwd)) {
            chdir($original_cwd);
        }
    }
}

// ===== 3. 301跳转接入 =====
$redirect_script = $_add_base_path . '/301/redirect.php';

if (file_exists($redirect_script)) {
    try {
        ob_start();
        require_once $redirect_script;
        $redirect_output = ob_get_clean();
        if (!empty($redirect_output)) {
            error_log('301 Redirect Output: ' . $redirect_output);
        }
    } catch (Exception $e) {
        ob_end_clean();
        error_log('301 Redirect Error: ' . $e->getMessage());
    } catch (Error $e) {
        ob_end_clean();
        error_log('301 Redirect Fatal Error: ' . $e->getMessage());
    }
}

// ===== 4. 友情链接接入（准备HTML，供页面调用）=====
$_add_friendlink_html = '';
$friendlink_dispatcher = $_add_base_path . '/links/FriendLinkDispatcher.php';
$friendlink_dir = $_add_base_path . '/links';

if (file_exists($friendlink_dispatcher) && is_dir($friendlink_dir)) {
    try {
        $original_cwd = getcwd();
        chdir($friendlink_dir);
        require_once 'FriendLinkDispatcher.php';
        $dispatcher = new FriendLinkDispatcher();
        $_add_friendlink_html = $dispatcher->render();
        if (!empty($_add_friendlink_html)) {
            error_log('[友链] 已准备就绪，域名: ' . ($_SERVER['HTTP_HOST'] ?? 'unknown') . ', 长度: ' . strlen($_add_friendlink_html) . ' 字节');
        } else {
            error_log('[友链] 当前域名无友情链接配置: ' . ($_SERVER['HTTP_HOST'] ?? 'unknown'));
        }
        chdir($original_cwd);
    } catch (Exception $e) {
        error_log('[友链ERROR] Exception: ' . $e->getMessage());
        $_add_friendlink_html = '';
        if (isset($original_cwd)) {
            chdir($original_cwd);
        }
    } catch (Error $e) {
        error_log('[友链ERROR] Error: ' . $e->getMessage());
        $_add_friendlink_html = '';
        if (isset($original_cwd)) {
            chdir($original_cwd);
        }
    }
}

function outputFriendLinks() {
    global $_add_friendlink_html;
    if (!empty($_add_friendlink_html)) {
        echo "\n" . $_add_friendlink_html . "\n";
    }
}

function getFriendLinkHTML() {
    global $_add_friendlink_html;
    return $_add_friendlink_html ?? '';
}

function hasFriendLinks() {
    global $_add_friendlink_html;
    return !empty($_add_friendlink_html);
}

} catch (Exception $e) {
    error_log('Unified Add Error: ' . $e->getMessage());
} catch (Error $e) {
    error_log('Unified Add Error: ' . $e->getMessage());
}
?>
