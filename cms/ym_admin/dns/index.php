<?php
$pageTitle = 'DNS 管理';
require_once dirname(__DIR__) . '/core/functions.php';
require_once __DIR__ . '/dns_la/core/api.php';

$dnslaAccounts = dnsla_getAccounts();

require_once dirname(__DIR__) . '/components/header.php';
?>

<div class="mb-4">
  <h6 class="text-muted">已接入 DNS 平台</h6>
</div>

<div class="row g-4">
  <!-- DNS-LA 卡片 -->
  <div class="col-12 col-md-6 col-xl-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="rounded-3 bg-primary-subtle p-3">
            <i class="bi bi-diagram-3 fs-3 text-primary"></i>
          </div>
          <div>
            <h5 class="fw-bold mb-0">DNS-LA</h5>
            <small class="text-muted">api.dns.la</small>
          </div>
        </div>
        <p class="text-muted small mb-3">
          DNS-LA 智能解析平台，支持批量添加/删除域名及解析记录，全量操作走后台异步处理。
        </p>
        <div class="d-flex align-items-center justify-content-between mb-3">
          <span class="text-muted small">已配置账号</span>
          <span class="badge bg-primary fs-6"><?= count($dnslaAccounts) ?> 个</span>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <a href="/dns/dns_la/" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-gear me-1"></i>账号管理
          </a>
          <?php if (!empty($dnslaAccounts)): ?>
          <a href="/dns/dns_la/domains.php?account_id=<?= $dnslaAccounts[0]['id'] ?>" class="btn btn-sm btn-primary">
            <i class="bi bi-list-ul me-1"></i>域名列表
          </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- 占位：未来平台 -->
  <div class="col-12 col-md-6 col-xl-4">
    <div class="card border-0 shadow-sm h-100 border-dashed opacity-50">
      <div class="card-body d-flex flex-column align-items-center justify-content-center text-center py-5">
        <i class="bi bi-plus-circle fs-2 text-muted mb-2"></i>
        <p class="text-muted small mb-0">后续可接入更多 DNS 平台</p>
        <small class="text-muted">如 Cloudflare、阿里云 DNS 等</small>
      </div>
    </div>
  </div>
</div>

<?php require_once dirname(__DIR__) . '/components/footer.php'; ?>
