<?php
ob_start();
session_start();
require_once dirname(__DIR__) . '/core/functions.php';

// ── 登录验证 ──────────────────────────────────────────────────
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: /auth/login.php');
    exit;
}

$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir  = basename(dirname($_SERVER['PHP_SELF']));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle ?? SITE_NAME) ?> - <?= SITE_NAME ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="d-flex" id="wrapper">

<!-- 侧边栏 -->
<div id="sidebar">
  <div class="sidebar-brand">
    <i class="bi bi-globe2"></i>
    <span>域名管理</span>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">概览</div>
    <a href="/domains/" class="nav-item <?= $currentPage === 'index.php' && $currentDir === 'domains' ? 'active' : '' ?>">
      <i class="bi bi-grid-1x2"></i> 域名列表
    </a>

    <div class="nav-section">批量操作</div>
    <a href="/batch/import.php" class="nav-item <?= $currentPage === 'import.php' && $currentDir === 'batch' ? 'active' : '' ?>">
      <i class="bi bi-upload"></i> 批量导入
    </a>
    <a href="/batch/query.php" class="nav-item <?= $currentPage === 'query.php' && $currentDir === 'batch' ? 'active' : '' ?>">
      <i class="bi bi-search"></i> 批量查询
    </a>
    <a href="/batch/renew.php" class="nav-item <?= $currentPage === 'renew.php' && $currentDir === 'batch' ? 'active' : '' ?>">
      <i class="bi bi-arrow-clockwise"></i> 批量续费
    </a>
    <a href="/batch/update.php" class="nav-item <?= $currentPage === 'update.php' && $currentDir === 'batch' ? 'active' : '' ?>">
      <i class="bi bi-pencil-square"></i> 批量修改字段
    </a>

    <div class="nav-section">分组管理</div>
    <a href="/cards/" class="nav-item <?= $currentDir === 'cards' ? 'active' : '' ?>">
      <i class="bi bi-collection"></i> 域名卡片
    </a>

    <div class="nav-section">DNS 管理</div>
    <a href="/dns/dns_la/index.php" class="nav-item <?= $currentDir === 'dns_la' || $currentDir === 'dns' ? 'active' : '' ?>">
      <i class="bi bi-diagram-3"></i> DNS-LA 托管
    </a>

    <div class="nav-section">系统管理</div>
    <a href="/jobs/" class="nav-item <?= $currentPage === 'index.php' && $currentDir === 'jobs' ? 'active' : '' ?>">
      <i class="bi bi-cpu"></i> 后台任务
    </a>
    <a href="/settings/custom_fields.php" class="nav-item <?= $currentPage === 'custom_fields.php' ? 'active' : '' ?>">
      <i class="bi bi-layout-three-columns"></i> 自定义字段
    </a>
    <div class="nav-section">账户</div>
    <a href="/auth/logout.php" class="nav-item text-danger">
      <i class="bi bi-box-arrow-right"></i> 退出登录
    </a>
  </nav>
</div>

<!-- 主内容 -->
<div id="content">
  <div class="topbar d-flex align-items-center justify-content-between px-4">
    <h5 class="mb-0 fw-semibold"><?= h($pageTitle ?? '域名列表') ?></h5>
    <?php if ($currentDir !== 'dns_la' && $currentDir !== 'dns'): ?>
    <a href="/domains/add.php" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg"></i> 添加域名
    </a>
    <?php endif; ?>
  </div>
  <div class="main-body px-4 py-3">
<?php
// 显示 flash 消息
$successMsg = getFlash('success');
$errorMsg   = getFlash('error');
if ($successMsg): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle me-2"></i><?= h($successMsg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>
<?php if ($errorMsg): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-circle me-2"></i><?= h($errorMsg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>
<?php $activeJob = getActiveJob(); if ($activeJob): ?>
  <div id="job-bar" class="alert alert-info alert-dismissible fade show py-2" role="alert">
    <div class="d-flex align-items-center gap-2 mb-1">
      <div class="spinner-border spinner-border-sm text-info flex-shrink-0" id="job-spinner" role="status"></div>
      <span>
        后台任务进行中：
        <strong id="job-progress-text">...</strong>
        &nbsp;—&nbsp;
        <a href="/jobs/progress.php?id=<?= h($activeJob['id']) ?>" class="alert-link">查看详情</a>
      </span>
      <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
    <div class="progress" style="height:6px">
      <div id="job-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div>
    </div>
  </div>
  <script>
  (function(){
    var jobId = <?= json_encode($activeJob['id']) ?>;
    var bar   = document.getElementById('job-progress-bar');
    var txt   = document.getElementById('job-progress-text');
    var spin  = document.getElementById('job-spinner');
    var timer = null;

    function poll(){
      fetch('/jobs/api/status.php?id=' + encodeURIComponent(jobId))
        .then(function(r){ return r.json(); })
        .then(function(data){
          if (!data.ok) return;
          var job = data.job;
          var pct = job.pct || 0;
          bar.style.width = pct + '%';
          txt.textContent = job.progress + ' / ' + job.total + ' 条（' + pct + '%）';

          if (job.status === 'done' || job.status === 'failed') {
            clearInterval(timer);
            spin.style.display = 'none';
            bar.classList.remove('progress-bar-animated','bg-primary');
            bar.classList.add(job.status === 'done' ? 'bg-success' : 'bg-danger');
            txt.textContent = job.status === 'done'
              ? '✓ 处理完成（' + job.total + ' 条）'
              : '✗ 处理失败';
            // 清除 session 标记，3 秒后淡出提示条
            fetch('/jobs/api/status.php?id=' + encodeURIComponent(jobId) + '&clear=1');
            setTimeout(function(){
              var el = document.getElementById('job-bar');
              if (el) el.style.opacity = '0';
            }, 3000);
          }
        }).catch(function(){});
    }

    poll();
    timer = setInterval(poll, 2000);
  })();
  </script>
<?php endif; ?>
