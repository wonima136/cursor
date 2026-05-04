<?php
/**
 * 301重定向系统 - 配置文件
 */

// 后台登录密码（请修改为你自己的密码）
define('ADMIN_PASSWORD', 'abingou2025');

// 后台路径（相对于网站根目录）
define('ADMIN_PATH', '/301/admin');

// 数据存储目录
define('REDIRECT301_DATA_DIR', __DIR__ . '/data');

// 日志目录
define('REDIRECT301_LOG_DIR', __DIR__ . '/log');

// Session名称
define('SESSION_NAME', 'redirect301_admin');

// 登录失败锁定次数
define('MAX_LOGIN_ATTEMPTS', 3);

// 锁定时间（秒）
define('LOCKOUT_TIME', 3000);

// 时区设置
date_default_timezone_set('Asia/Shanghai');

// 确保数据目录存在
if (!is_dir(REDIRECT301_DATA_DIR)) {
    @mkdir(REDIRECT301_DATA_DIR, 0755, true);
}

if (!is_dir(REDIRECT301_LOG_DIR)) {
    @mkdir(REDIRECT301_LOG_DIR, 0755, true);
}

