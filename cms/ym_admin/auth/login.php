<?php
ob_start();
session_start();
require_once dirname(__DIR__) . '/config/config.php';

// 已登录直接跳首页
if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: /domains/');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pwd = $_POST['password'] ?? '';
    if ($pwd && password_verify($pwd, ADMIN_PASSWORD_HASH)) {
        $_SESSION['admin_logged_in'] = true;
        session_regenerate_id(true);
        header('Location: /domains/');
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
<title>登录 — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
.login-card { width: 360px; background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,.08); padding: 2.5rem 2rem; }
.login-logo { font-size: 2rem; color: var(--bs-primary); margin-bottom: .5rem; }
</style>
</head>
<body>
<div class="login-card">
  <div class="text-center mb-4">
    <div class="login-logo"><i class="bi bi-globe2"></i></div>
    <h5 class="fw-bold mb-0"><?= SITE_NAME ?></h5>
    <small class="text-muted">请输入后台密码</small>
  </div>

  <?php if ($error): ?>
  <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" autocomplete="off">
    <div class="mb-3">
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-lock"></i></span>
        <input type="password" name="password" class="form-control form-control-lg"
               placeholder="请输入密码" autofocus required>
      </div>
    </div>
    <button type="submit" class="btn btn-primary w-100 btn-lg">
      <i class="bi bi-box-arrow-in-right me-1"></i>进入后台
    </button>
  </form>
</div>
</body>
</html>
<?php ob_end_flush(); ?>
