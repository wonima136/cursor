<?php
$pageTitle = '批量续费';
require_once dirname(__DIR__) . '/components/header.php';
?>

<div class="row">
  <div class="col-xl-7">
    <div class="form-card mb-3">
      <div class="section-title">续费模式说明</div>
      <ul class="small text-muted mb-0">
        <li><strong>在原日期上续费N年</strong>（推荐）：只需输入域名列表，系统自动在每个域名现有过期时间上加 N 年，各自日期不变</li>
        <li><strong>统一过期时间</strong>：所有域名设置同一个过期日期</li>
        <li><strong>每行指定</strong>：每行格式 <code>域名,新过期时间</code>，例如 <code>example.com,2027-04-28</code></li>
      </ul>
    </div>

    <form method="POST" action="/batch/api/renew_job.php">
      <div class="form-card mb-3">
        <div class="section-title">续费数据</div>
        <div class="mb-3">
          <label class="form-label">续费模式</label>
          <select name="mode" class="form-select" id="modeSelect" onchange="toggleMode(this.value)">
            <option value="add_years" selected>在原日期上续费N年（推荐）</option>
            <option value="single">统一过期时间</option>
            <option value="perline">每行指定过期时间</option>
          </select>
        </div>

        <!-- add_years 模式 -->
        <div class="mb-3" id="addYearsBlock">
          <label class="form-label">续费年数</label>
          <div class="input-group" style="max-width:200px">
            <input type="number" name="renew_years" class="form-control" value="1" min="1" max="10">
            <span class="input-group-text">年</span>
          </div>
          <div class="form-text">系统自动将每个域名现有的过期时间 + 续费年数</div>
        </div>

        <!-- single 模式 -->
        <div class="mb-3 d-none" id="singleDateBlock">
          <label class="form-label">新的过期时间</label>
          <input type="date" name="expire_date" class="form-control">
        </div>

        <div>
          <label class="form-label">域名列表</label>
          <textarea name="data" class="form-control font-monospace" rows="14"
                    id="domainData"
                    placeholder="续费N年 / 统一模式：每行一个域名&#10;qiumojiqi.cn&#10;jiushu.net&#10;&#10;每行指定模式：&#10;example.com,2027-04-28&#10;test.cn,2027-06-01" required></textarea>
          <div class="form-text mt-1" id="domainCount">0 个域名</div>
        </div>
      </div>
      <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>提交后台处理</button>
    </form>
  </div>

  <div class="col-xl-5">
    <div class="form-card">
      <div class="section-title">示例：续费N年模式</div>
      <p class="small text-muted">续费1年，只需粘贴域名列表，系统自动计算新日期：</p>
      <table class="table table-sm table-bordered small mb-0">
        <thead class="table-light"><tr><th>域名</th><th>现有过期时间</th><th>续费后</th></tr></thead>
        <tbody>
          <tr><td>qiumojiqi.cn</td><td>2026-04-28</td><td class="text-success fw-bold">2027-04-28</td></tr>
          <tr><td>jiushu.net</td><td>2026-04-29</td><td class="text-success fw-bold">2027-04-29</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
function toggleMode(v) {
  document.getElementById('addYearsBlock').classList.toggle('d-none', v !== 'add_years');
  document.getElementById('singleDateBlock').classList.toggle('d-none', v !== 'single');
}

// 实时统计域名数量
document.getElementById('domainData').addEventListener('input', function() {
  var lines = this.value.split('\n').filter(function(l){ return l.trim(); });
  document.getElementById('domainCount').textContent = lines.length + ' 个域名';
});
</script>

<?php require_once dirname(__DIR__) . '/components/footer.php'; ?>
