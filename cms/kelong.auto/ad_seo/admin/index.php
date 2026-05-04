<?php
/**
 * WAF 拦截系统后台 - 登录入口
 */
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

define('CONFIG_DIR',  dirname(__DIR__));
define('CONFIG_FILE', CONFIG_DIR . '/config.php');

function checkLogin(): bool {
    return isset($_SESSION['waf_admin_logged_in']) && $_SESSION['waf_admin_logged_in'] === true;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

if (checkLogin()) {
    header('Location: config_manage.php');
    exit;
}

// 读取配置中的密码和标题
$adminPassword = 'admin2025';
$pageTitle     = 'WAF拦截系统';
if (file_exists(CONFIG_FILE)) {
    $cfg = file_get_contents(CONFIG_FILE);
    if (preg_match("/define\('ADMIN_PASSWORD',\s*'([^']*)'\);/", $cfg, $m)) $adminPassword = $m[1];
    if (preg_match("/define\('ADMIN_TITLE',\s*'([^']*)'\);/", $cfg, $m))    $pageTitle     = $m[1];
}

$error = '';
if (isset($_POST['login'])) {
    if ($_POST['password'] === $adminPassword) {
        $_SESSION['waf_admin_logged_in'] = true;
        header('Location: config_manage.php');
        exit;
    }
    $error = '密码错误，请重试';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 — <?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="admin.css">
</head>
<body class="login-page">
    <div class="login-wrap">
        <div class="login-box">
            <div class="login-icon">🛡️</div>
            <h1 class="login-title"><?php echo htmlspecialchars($pageTitle); ?></h1>
            <p class="login-sub">拦截系统配置管理</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="php-badge <?php echo version_compare(PHP_VERSION, '7.2.0', '>=') ? 'badge-ok' : 'badge-fail'; ?>">
                <?php if (version_compare(PHP_VERSION, '7.2.0', '>=')): ?>
                    ✅ PHP <?php echo PHP_VERSION; ?> &nbsp;满足要求（7.2+）
                <?php else: ?>
                    ❌ 需要 PHP 7.2+，当前 <?php echo PHP_VERSION; ?>
                <?php endif; ?>
            </div>

            <form method="post" autocomplete="off">
                <div class="form-group">
                    <label class="form-label">管理密码</label>
                    <input type="password" name="password" class="form-input" required autofocus placeholder="请输入管理密码">
                </div>
                <button type="submit" name="login" class="btn btn-primary" style="width:100%;margin-top:8px;">🔐 登录后台</button>
            </form>

            <p class="login-footer">WAF 拦截系统 &copy; <?php echo date('Y'); ?></p>
        </div>
    </div>
    <script src="admin.js"></script>
</body>
</html>
