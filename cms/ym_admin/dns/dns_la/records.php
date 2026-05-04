<?php
$pageTitle = 'DNS-LA 解析记录';
require_once dirname(dirname(__DIR__)) . '/core/functions.php';
require_once __DIR__ . '/core/api.php';

$accountId = (int)($_GET['account_id'] ?? 0);
$domainId  = trim($_GET['domain_id'] ?? '');
$domain    = trim($_GET['domain'] ?? '');
$account   = $accountId ? dnsla_getAccount($accountId) : null;

if (!$account || !$domainId) {
    header('Location: /dns/dns_la/');
    exit;
}

$pageTitle = "解析记录 - {$domain}";

// 拉取解析记录（实时）
$token  = dnsla_buildToken($account['api_id'], $account['api_secret']);
$base   = $account['api_base'] ?? '';
$page   = max(1, (int)($_GET['p'] ?? 1));
$perPage = 100;

$apiRes = dnsla_request('GET', '/api/recordList', [
    'pageIndex' => $page,
    'pageSize'  => $perPage,
    'domainId'  => $domainId,
], [], $token, $base);

$records    = [];
$totalCount = 0;
$apiError   = '';

if ($apiRes['ok']) {
    $totalCount = (int)($apiRes['data']['total'] ?? 0);
    $records    = $apiRes['data']['results'] ?? [];
} else {
    $apiError = $apiRes['msg'] ?: '请求失败（' . $apiRes['code'] . '）';
}

$totalPages = $totalCount > 0 ? (int)ceil($totalCount / $perPage) : 1;

require_once dirname(dirname(__DIR__)) . '/components/header.php';
?>

<!-- 面包屑 -->
<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="/dns/">DNS管理</a></li>
    <li class="breadcrumb-item"><a href="/dns/dns_la/">DNS-LA</a></li>
    <li class="breadcrumb-item"><a href="/dns/dns_la/domains.php?account_id=<?= $accountId ?>">域名列表</a></li>
    <li class="breadcrumb-item active"><?= h($domain) ?></li>
  </ol>
</nav>

<!-- 标题行 -->
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <div>
    <h6 class="mb-0 fw-bold"><?= h($domain) ?></h6>
    <small class="text-muted">共 <?= $totalCount ?> 条解析记录 · 账号：<?= h($account['name']) ?></small>
  </div>
  <div class="ms-auto d-flex gap-2 flex-wrap">
    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addRecordModal">
      <i class="bi bi-plus-circle me-1"></i>批量添加 A 记录
    </button>
    <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#updateRecordModal">
      <i class="bi bi-pencil-square me-1"></i>批量修改记录值
    </button>
    <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#delRecordModal">
      <i class="bi bi-trash me-1"></i>批量删除记录
    </button>
  </div>
</div>

<?php if ($apiError): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= h($apiError) ?></div>
<?php endif; ?>

<!-- 解析记录表格 -->
<div class="table-responsive">
  <table class="table table-hover align-middle mb-0 small">
    <thead class="table-light">
      <tr>
        <th>主机头</th>
        <th>类型</th>
        <th>记录值</th>
        <th>TTL</th>
        <th>线路</th>
        <th>状态</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($records)): ?>
      <tr><td colspan="6" class="text-center text-muted py-4">暂无解析记录</td></tr>
    <?php else: ?>
      <?php foreach ($records as $rec):
        $typeName = dnsla_recordTypeName((int)($rec['type'] ?? 0));
        $disabled = !empty($rec['disable']);
      ?>
      <tr class="<?= $disabled ? 'opacity-50' : '' ?>">
        <td class="fw-semibold font-monospace"><?= h($rec['host'] ?? '@') ?></td>
        <td><span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= h($typeName) ?></span></td>
        <td class="font-monospace text-break" style="max-width:300px"><?= h($rec['data'] ?? '') ?></td>
        <td><?= h($rec['ttl'] ?? '') ?></td>
        <td><?= h($rec['lineName'] ?? $rec['lineId'] ?? '默认') ?></td>
        <td>
          <?php if ($disabled): ?>
          <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">已暂停</span>
          <?php else: ?>
          <span class="badge bg-success-subtle text-success border border-success-subtle">正常</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3">
  <ul class="pagination pagination-sm justify-content-center mb-0">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
      <a class="page-link" href="?account_id=<?= $accountId ?>&domain_id=<?= urlencode($domainId) ?>&domain=<?= urlencode($domain) ?>&p=<?= $i ?>"><?= $i ?></a>
    </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>


<!-- ════ 批量添加 A 记录 Modal ════ -->
<div class="modal fade" id="addRecordModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-plus-circle me-2 text-success"></i>批量添加 A 记录</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info py-2 small">
          <i class="bi bi-info-circle me-1"></i>为下方域名列表批量添加 A 记录。每个域名随机取一个 IP，所有主机头均指向该 IP。
        </div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold">域名列表 <span class="text-muted small">（每行一个）</span></label>
            <textarea class="form-control font-monospace" id="recDomainList" rows="8"
                      placeholder="example.com&#10;test.com&#10;domain.net"
                      oninput="updateRecDomainCount()"></textarea>
            <div class="form-text">已输入 <strong id="recDomainCount">0</strong> 个域名</div>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">IP 列表 <span class="text-muted small">（每行一个，每域名随机取一个）</span></label>
            <textarea class="form-control font-monospace" id="recIpList" rows="8"
                      placeholder="1.1.1.1&#10;2.2.2.2&#10;3.3.3.3"></textarea>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">主机头 <span class="text-muted small">（每行一个，如 @ 或 www）</span></label>
            <textarea class="form-control font-monospace" id="recHostList" rows="3"
                      placeholder="@&#10;www">@</textarea>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">TTL</label>
            <input type="number" class="form-control" id="recTtl" value="600" min="1">
          </div>
          <div class="col-12">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="recClearA" checked>
              <label class="form-check-label" for="recClearA">
                <strong>添加前清空该域名所有 A 记录</strong>
                <span class="text-muted small">（推荐勾选，避免重复记录）</span>
              </label>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
        <button type="button" class="btn btn-success" onclick="submitAddRecords()">
          <i class="bi bi-send me-1"></i>提交后台任务
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ════ 批量修改记录值 Modal ════ -->
<div class="modal fade" id="updateRecordModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-2 text-warning"></i>批量修改记录值</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info py-2 small">
          <i class="bi bi-info-circle me-1"></i>为指定域名列表，找到匹配的记录（按主机头+类型筛选），将记录值替换为新 IP（每个域名随机取一个）。
        </div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold">域名列表</label>
            <textarea class="form-control font-monospace" id="updDomainList" rows="8"
                      placeholder="example.com&#10;test.com"
                      oninput="updateUpdDomainCount()"></textarea>
            <div class="form-text">已输入 <strong id="updDomainCount">0</strong> 个域名</div>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">新 IP 列表 <span class="text-muted small">（每域名随机取一个）</span></label>
            <textarea class="form-control font-monospace" id="updIpList" rows="8"
                      placeholder="1.1.1.1&#10;2.2.2.2"></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">主机头筛选 <span class="text-muted small">（每行一个，留空=全部）</span></label>
            <textarea class="form-control font-monospace" id="updHostList" rows="3"
                      placeholder="@&#10;www">@</textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">记录类型</label>
            <select class="form-select" id="updType">
              <option value="1">A</option>
              <option value="28">AAAA</option>
              <option value="3">CNAME</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
        <button type="button" class="btn btn-warning" onclick="submitUpdateRecords()">
          <i class="bi bi-send me-1"></i>提交后台任务
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ════ 批量删除记录 Modal ════ -->
<div class="modal fade" id="delRecordModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-trash me-2 text-danger"></i>批量删除解析记录</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning py-2 small">
          <i class="bi bi-exclamation-triangle me-1"></i>将删除指定域名列表中，匹配主机头+类型的所有解析记录。
        </div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold">域名列表</label>
            <textarea class="form-control font-monospace" id="delRecDomainList" rows="8"
                      placeholder="example.com&#10;test.com"
                      oninput="updateDelRecCount()"></textarea>
            <div class="form-text">已输入 <strong id="delRecDomainCount">0</strong> 个域名</div>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">主机头筛选 <span class="text-muted small">（每行一个，留空=全部）</span></label>
            <textarea class="form-control font-monospace" id="delRecHostList" rows="4"
                      placeholder="@&#10;www&#10;留空则删除所有主机头"></textarea>
            <label class="form-label fw-semibold mt-2">记录类型</label>
            <select class="form-select" id="delRecType">
              <option value="1">A</option>
              <option value="28">AAAA</option>
              <option value="3">CNAME</option>
              <option value="16">TXT</option>
              <option value="15">MX</option>
              <option value="0">全部类型</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
        <button type="button" class="btn btn-danger" onclick="submitDelRecords()">
          <i class="bi bi-send me-1"></i>提交后台任务
        </button>
      </div>
    </div>
  </div>
</div>

<script>
var accountId = <?= $accountId ?>;
var domainId  = <?= json_encode($domainId) ?>;
var domain    = <?= json_encode($domain) ?>;

function parseDomains(text) {
  return text.split('\n').map(s => s.trim()).filter(s => s.length > 0);
}

function updateRecDomainCount() {
  document.getElementById('recDomainCount').textContent = parseDomains(document.getElementById('recDomainList').value).length;
}
function updateUpdDomainCount() {
  document.getElementById('updDomainCount').textContent = parseDomains(document.getElementById('updDomainList').value).length;
}
function updateDelRecCount() {
  document.getElementById('delRecDomainCount').textContent = parseDomains(document.getElementById('delRecDomainList').value).length;
}

function submitAddRecords() {
  var domains = parseDomains(document.getElementById('recDomainList').value);
  var ips     = parseDomains(document.getElementById('recIpList').value);
  var hosts   = parseDomains(document.getElementById('recHostList').value);
  var ttl     = parseInt(document.getElementById('recTtl').value) || 600;
  var clearA  = document.getElementById('recClearA').checked;

  if (domains.length === 0) { alert('请输入域名列表'); return; }
  if (ips.length === 0)     { alert('请输入 IP 列表'); return; }
  if (hosts.length === 0)   { alert('请输入至少一个主机头'); return; }

  if (!confirm('确认为 ' + domains.length + ' 个域名批量添加 A 记录？')) return;

  var fd = new FormData();
  fd.append('action',     'add_records');
  fd.append('account_id', accountId);
  fd.append('domains',    domains.join('\n'));
  fd.append('ips',        ips.join('\n'));
  fd.append('hosts',      hosts.join('\n'));
  fd.append('ttl',        ttl);
  fd.append('clear_a',    clearA ? '1' : '0');
  fd.append('rec_type',   '1');
  fetch('/dns/dns_la/api/record_job.php', {method: 'POST', body: fd})
    .then(r => r.json()).then(d => {
      if (d.ok) { window.location.href = '/jobs/progress.php?id=' + d.job_id; }
      else { alert(d.msg || '提交失败'); }
    });
}

function submitUpdateRecords() {
  var domains = parseDomains(document.getElementById('updDomainList').value);
  var ips     = parseDomains(document.getElementById('updIpList').value);
  var hosts   = parseDomains(document.getElementById('updHostList').value);
  var type    = document.getElementById('updType').value;

  if (domains.length === 0) { alert('请输入域名列表'); return; }
  if (ips.length === 0)     { alert('请输入新 IP 列表'); return; }
  if (!confirm('确认为 ' + domains.length + ' 个域名批量修改记录值？')) return;

  var fd = new FormData();
  fd.append('action',     'update_records');
  fd.append('account_id', accountId);
  fd.append('domains',    domains.join('\n'));
  fd.append('ips',        ips.join('\n'));
  fd.append('hosts',      hosts.join('\n'));
  fd.append('rec_type',   type);
  fetch('/dns/dns_la/api/record_job.php', {method: 'POST', body: fd})
    .then(r => r.json()).then(d => {
      if (d.ok) { window.location.href = '/jobs/progress.php?id=' + d.job_id; }
      else { alert(d.msg || '提交失败'); }
    });
}

function submitDelRecords() {
  var domains = parseDomains(document.getElementById('delRecDomainList').value);
  var hosts   = parseDomains(document.getElementById('delRecHostList').value);
  var type    = document.getElementById('delRecType').value;

  if (domains.length === 0) { alert('请输入域名列表'); return; }
  if (!confirm('确认删除 ' + domains.length + ' 个域名中匹配的解析记录？此操作不可逆！')) return;

  var fd = new FormData();
  fd.append('action',     'del_records');
  fd.append('account_id', accountId);
  fd.append('domains',    domains.join('\n'));
  fd.append('hosts',      hosts.join('\n'));
  fd.append('rec_type',   type);
  fetch('/dns/dns_la/api/record_job.php', {method: 'POST', body: fd})
    .then(r => r.json()).then(d => {
      if (d.ok) { window.location.href = '/jobs/progress.php?id=' + d.job_id; }
      else { alert(d.msg || '提交失败'); }
    });
}
</script>

<?php require_once dirname(dirname(__DIR__)) . '/components/footer.php'; ?>
