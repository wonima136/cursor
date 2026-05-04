<?php
$pageTitle = '批量续费';
require_once dirname(__DIR__) . '/components/header.php';
?>

<div class="row">
  <div class="col-xl-7">
    <div class="form-card mb-3">
      <div class="section-title">续费模式说明</div>
      <ul class="small text-muted mb-0">
        <li><strong>统一过期时间</strong>：所有域名设置同一个过期日期，数据只需一列（域名）</li>
        <li><strong>每行指定</strong>：每行格式 <code>域名,新过期时间</code>，例如 <code>example.com,2026-01-01</code></li>
      </ul>
    </div>

    <form method="POST" action="/api/renew_job.php">
      <div class="form-card mb-3">
        <div class="section-title">续费数据</div>
        <div class="mb-3">
          <label class="form-label">续费模式</label>
          <select name="mode" class="form-select" id="modeSelect" onchange="toggleMode(this.value)">
            <option value="single">统一过期时间</option>
            <option value="perline">每行指定过期时间</option>
          </select>
        </div>
        <div class="mb-3" id="singleDateBlock">
          <label class="form-label">新的过期时间</label>
          <input type="date" name="expire_date" class="form-control">
        </div>
        <div>
          <label class="form-label">域名列表</label>
          <textarea name="data" class="form-control font-monospace" rows="12"
                    placeholder="统一模式：每行一个域名&#10;example.com&#10;test.cn&#10;&#10;每行指定模式：&#10;example.com,2026-01-01&#10;test.cn,2026-06-01" required></textarea>
        </div>
      </div>
      <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>提交后台处理</button>
    </form>
  </div>
</div>

<script>
function toggleMode(v) {
  document.getElementById('singleDateBlock').style.display = v === 'single' ? '' : 'none';
}
</script>

<?php require_once dirname(__DIR__) . '/components/footer.php'; ?>
