<?php
/**
 * 301重定向系统 - 引导文件
 * 负责自动加载类文件和初始化配置
 */

// 防止重复加载
if (defined('REDIRECT301_BOOTSTRAPPED')) {
    return;
}
define('REDIRECT301_BOOTSTRAPPED', true);

// 定义根目录
if (!defined('REDIRECT301_ROOT')) {
    define('REDIRECT301_ROOT', __DIR__);
}

// 定义命名空间前缀
if (!defined('REDIRECT301_NAMESPACE')) {
    define('REDIRECT301_NAMESPACE', 'Redirect301');
}

/**
 * 自动加载器
 */
spl_autoload_register(function ($class) {
    // 只处理我们的命名空间
    $prefix = REDIRECT301_NAMESPACE . '\\';
    $len = strlen($prefix);
    
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    // 获取相对类名
    $relativeClass = substr($class, $len);
    
    // 将命名空间分隔符替换为目录分隔符
    $relativeClass = str_replace('\\', '/', $relativeClass);
    
    // 构建文件路径
    // 目录名转小写，文件名保持原样
    $parts = explode('/', $relativeClass);
    $fileName = array_pop($parts); // 取出文件名
    $dirPath = !empty($parts) ? strtolower(implode('/', $parts)) : '';
    
    $file = REDIRECT301_ROOT . '/' . ($dirPath ? $dirPath . '/' : '') . $fileName . '.php';
    
    // 如果文件存在，加载它
    if (file_exists($file)) {
        require_once $file;
    }
});

// 加载配置文件
require_once REDIRECT301_ROOT . '/config.php';
require_once REDIRECT301_ROOT . '/domain_suffixes.php';

// 加载旧的Redis配置（兼容性）
if (file_exists(REDIRECT301_ROOT . '/admin/redis_config.php')) {
    require_once REDIRECT301_ROOT . '/admin/redis_config.php';
}

