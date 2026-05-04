<?php
$pageTitle = 'DNS-LA 域名列表';
require_once dirname(dirname(__DIR__)) . '/core/functions.php';
require_once __DIR__ . '/core/api.php';

$accountId = (int)($_GET['account_id'] ?? 0);
$account   = $accountId ? dnsla_getAccount($accountId) : null;

// 没有账号时去账号管理
if (!$account) {
    $accounts = dnsla_getAccounts();
    if (empty($accounts)) {
        header('Location: /dns/dns_la/');
        exit;
    }
    // 默认选第一个
    $account   = $accounts[0];
    $accountId = (int)$account['id'];
}

$allAccounts = dnsla_getAccounts();

// 当前页 & 搜索
$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 50;
$search  = trim($_GET['q'] ?? '');

// 拉取域名列表（实时 API）
$token  = dnsla_buildToken($account['api_id'], $account['api_secret']);
$base   = $account['api_base'] ?? '';
$apiRes = dnsla_request('GET', '/api/domainList', [
    'pageIndex' => $page,
    'pageSize'  => $perPage,
], [], $token, $base);

$domains    = [];
$totalCount = 0;
$apiError   = '';

if ($apiRes['ok']) {
    $totalCount = (int)($apiRes['data']['total'] ?? 0);
    $domains    = $apiRes['data']['results'] ?? [];
} else {
    $apiError = $apiRes['msg'] ?: '请求失败（' . $apiRes['code'] . '）';
}

$totalPages = $totalCount > 0 ? (int)ceil($totalCount / $perPage) : 1;

$nsStateLabels = [0 => '未知', 1 => '已匹配', 2 => '未匹配', 3 => '未加入'];
$nsStateBadges = [0 => 'secondary', 1 => 'success', 2 => 'warning', 3 => 'danger'];
$stateLabels   = [1 => '正常', 2 => '暂停'];
$stateBadges   = [1 => 'success', 2 => 'warning'];

require_once dirname(dirname(__DIR__)) . '/components/header.php';
?>

<!-- 面包屑 -->
<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="/dns/">DNS管理</a></li>
    <li class="breadcrumb-item"><a href="/dns/dns_la/">DNS-LA</a></li>
    <li class="breadcrumb-item active">域名列表</li>
  </ol>
</nav>

<!-- 操作栏 -->
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <!-- 账号切换 -->
  <div class="input-group" style="width:200px">
    <span class="input-group-text bg-white"><i class="bi bi-person-badge"></i></span>
    <select class="form-select form-select-sm" onchange="location.href='/dns/dns_la/domains.php?account_id='+this.value">
      <?php foreach ($allAccounts as $acc): ?>
      <option value="<?= $acc['id'] ?>" <?= $acc['id'] == $accountId ? 'selected' : '' ?>><?= h($acc['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <span class="text-muted small">共 <strong><?= $totalCount ?></strong> 个域名</span>

  <div class="ms-auto d-flex gap-2 flex-wrap">
    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addDomainModal">
      <i class="bi bi-plus-circle me-1"></i>批量添加域名
    </button>
    <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#delDomainModal">
      <i class="bi bi-trash me-1"></i>批量删除域名
    </button>
  </div>
</div>

<?php if ($apiError): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>API 请求失败：<?= h($apiError) ?></div>
<?php endif; ?>

<!-- 域名表格 -->
<div class="table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead class="table-light">
      <tr>
        <th>域名</th>
        <th>状态</th>
        <th>NS 状态</th>
        <th>套餐</th>
        <th>过期时间</th>
        <th>操作</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($domains)): ?>
      <tr><td colspan="6" class="text-center text-muted py-4">暂无域名数据</td></tr>
    <?php else: ?>
      <?php foreach ($domains as $d):
        $state   = (int)($d['state'] ?? 1);
        $nsState = (int)($d['nsState'] ?? 0);
        $expAt   = (int)($d['expiredAt'] ?? 0);
        $domain  = rtrim($d['displayDomain'] ?? $d['domain'] ?? '', '.');
        $expStr  = ($expAt > 0 && $expAt < 4102416000) ? date('Y-m-d', $expAt) : '免费（长期）';
      ?>
      <tr>
        <td class="fw-semibold">
          <?= h($domain) ?>
          <?php if (!empty($d['groupName'])): ?>
          <span class="badge bg-secondary-subtle text-secondary ms-1"><?= h($d['groupName']) ?></span>
          <?php endif; ?>
        </td>
        <td>
          <span class="badge bg-<?= $stateBadges[$state] ?? 'secondary' ?>-subtle text-<?= $stateBadges[$state] ?? 'secondary' ?> border border-<?= $stateBadges[$state] ?? 'secondary' ?>-subtle">
            <?= $stateLabels[$state] ?? $state ?>
          </span>
        </td>
        <td>
          <span class="badge bg-<?= $nsStateBadges[$nsState] ?? 'secondary' ?>-subtle text-<?= $nsStateBadges[$nsState] ?? 'secondary' ?> border border-<?= $nsStateBadges[$nsState] ?? 'secondary' ?>-subtle">
            <?= $nsStateLabels[$nsState] ?? $nsState ?>
          </span>
        </td>
        <td><?= h($d['productName'] ?: '免费版') ?></td>
        <td><?= h($expStr) ?></td>
        <td>
          <a href="/dns/dns_la/records.php?account_id=<?= $accountId ?>&domain_id=<?= urlencode($d['id']) ?>&domain=<?= urlencode($domain) ?>"
             class="btn btn-sm btn-outline-primary">
            <i class="bi bi-list-columns me-1"></i>解析记录
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- 分页 -->
<?php if ($totalPages > 1): ?>
<nav class="mt-3">
  <ul class="pagination pagination-sm justify-content-center mb-0">
    <?php if ($page > 1): ?>
    <li class="page-item"><a class="page-link" href="?account_id=<?= $accountId ?>&p=<?= $page-1 ?>">‹</a></li>
    <?php endif; ?>
    <?php for ($i = max(1, $page-3); $i <= min($totalPages, $page+3); $i++): ?>
    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
      <a class="page-link" href="?account_id=<?= $accountId ?>&p=<?= $i ?>"><?= $i ?></a>
    </li>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?>
    <li class="page-item"><a class="page-link" href="?account_id=<?= $accountId ?>&p=<?= $page+1 ?>">›</a></li>
    <?php endif; ?>
  </ul>
</nav>
<?php endif; ?>


<!-- ════ 批量添加域名 Modal ════ -->
<div class="modal fade" id="addDomainModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-plus-circle me-2 text-success"></i>批量添加域名</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info py-2 small">
          <i class="bi bi-info-circle me-1"></i>每行一个域名，将逐个添加到 DNS-LA 账号「<?= h($account['name']) ?>」
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">域名列表</label>
          <textarea class="form-control font-monospace" id="addDomainList" rows="10"
                    placeholder="example.com&#10;test.com&#10;domain.net"
                    oninput="updateAddCount()"></textarea>
          <div class="form-text">已输入 <strong id="addDomainCount">0</strong> 个域名</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
        <button type="button" class="btn btn-success" onclick="submitAddDomains()">
          <i class="bi bi-send me-1"></i>提交后台任务
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ════ 批量删除域名 Modal ════ -->
<div class="modal fade" id="delDomainModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-trash me-2 text-danger"></i>批量删除域名</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning py-2 small">
          <i class="bi bi-exclamation-triangle me-1"></i>删除后域名及其所有解析记录将从 DNS-LA 永久移除，请谨慎操作！
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">要删除的域名列表</label>
          <textarea class="form-control font-monospace" id="delDomainList" rows="10"
                    placeholder="example.com&#10;test.com"
                    oninput="updateDelCount()"></textarea>
          <div class="form-text">已输入 <strong id="delDomainCount">0</strong> 个域名</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
        <button type="button" class="btn btn-danger" onclick="submitDelDomains()">
          <i class="bi bi-send me-1"></i>提交后台任务
        </button>
      </div>
    </div>
  </div>
</div>

<script>
var accountId = <?= $accountId ?>;

function parseDomains(text) {
  return text.split('\n').map(s => s.trim()).filter(s => s.length > 0);
}

function updateAddCount() {
  document.getElementById('addDomainCount').textContent = parseDomains(document.getElementById('addDomainList').value).length;
}
function updateDelCount() {
  document.getElementById('delDomainCount').textContent = parseDomains(document.getElementById('delDomainList').value).length;
}

function submitAddDomains() {
  var domains = parseDomains(document.getElementById('addDomainList').value);
  if (domains.length === 0) { alert('请输入至少一个域名'); return; }
  if (!confirm('确认将 ' + domains.length + ' 个域名添加到 DNS-LA？')) return;
  var fd = new FormData();
  fd.append('action', 'add_domains');
  fd.append('account_id', accountId);
  fd.append('domains', domains.join('\n'));
  fetch('/dns/dns_la/api/domain_job.php', {method: 'POST', body: fd})
    .then(r => r.json()).then(d => {
      if (d.ok) { window.location.href = '/jobs/progress.php?id=' + d.job_id; }
      else { alert(d.msg || '提交失败'); }
    });
}

function submitDelDomains() {
  var domains = parseDomains(document.getElementById('delDomainList').value);
  if (domains.length === 0) { alert('请输入至少一个域名'); return; }
  if (!confirm('确认永久删除这 ' + domains.length + ' 个域名及其所有解析记录？\n此操作不可逆！')) return;
  var fd = new FormData();
  fd.append('action', 'del_domains');
  fd.append('account_id', accountId);
  fd.append('domains', domains.join('\n'));
  fetch('/dns/dns_la/api/domain_job.php', {method: 'POST', body: fd})
    .then(r => r.json()).then(d => {
      if (d.ok) { window.location.href = '/jobs/progress.php?id=' + d.job_id; }
      else { alert(d.msg || '提交失败'); }
    });
}
</script>

<?php require_once dirname(dirname(__DIR__)) . '/components/footer.php'; ?>
