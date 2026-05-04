<?php
$pageTitle = 'DNS-LA 托管';
require_once dirname(dirname(__DIR__)) . '/core/functions.php';
require_once __DIR__ . '/core/api.php';

// ── POST 处理（账号管理）────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_account_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['_account_action'];

    if ($action === 'save') {
        $accounts  = dnsla_getAccounts();
        $name      = trim($_POST['name'] ?? '');
        $apiId     = trim($_POST['api_id'] ?? '');
        $apiSecret = trim($_POST['api_secret'] ?? '');
        $apiBase   = trim($_POST['api_base'] ?? '') ?: DNSLA_API_BASE_DEFAULT;
        $editId    = (int)($_POST['edit_id'] ?? 0);
        if (!$name || !$apiId || !$apiSecret) {
            echo json_encode(['ok' => false, 'msg' => '名称、APIID、APISecret 均不能为空']); exit;
        }
        if ($editId > 0) {
            foreach ($accounts as &$acc) {
                if ((int)$acc['id'] === $editId) {
                    $acc['name'] = $name; $acc['api_id'] = $apiId;
                    $acc['api_secret'] = $apiSecret; $acc['api_base'] = $apiBase;
                    break;
                }
            }
            unset($acc);
        } else {
            $maxId = 0;
            foreach ($accounts as $acc) $maxId = max($maxId, (int)$acc['id']);
            $accounts[] = ['id' => $maxId + 1, 'name' => $name,
                           'api_id' => $apiId, 'api_secret' => $apiSecret, 'api_base' => $apiBase];
        }
        dnsla_saveAccounts($accounts);
        echo json_encode(['ok' => true]); exit;
    }
    if ($action === 'delete') {
        $delId    = (int)($_POST['id'] ?? 0);
        $accounts = array_values(array_filter(dnsla_getAccounts(), fn($a) => (int)$a['id'] !== $delId));
        dnsla_saveAccounts($accounts);
        echo json_encode(['ok' => true]); exit;
    }
    if ($action === 'test') {
        $apiId     = trim($_POST['api_id'] ?? '');
        $apiSecret = trim($_POST['api_secret'] ?? '');
        $apiBase   = trim($_POST['api_base'] ?? '') ?: DNSLA_API_BASE_DEFAULT;
        $token     = dnsla_buildToken($apiId, $apiSecret);
        $r         = dnsla_request('GET', '/api/domainList', ['pageIndex' => 1, 'pageSize' => 1], [], $token, $apiBase);
        echo json_encode(['ok' => $r['ok'], 'msg' => $r['ok'] ? '连接成功' : ('连接失败：' . $r['msg'])]); exit;
    }
    echo json_encode(['ok' => false, 'msg' => '未知操作']); exit;
}

$accounts = dnsla_getAccounts();
require_once dirname(dirname(__DIR__)) . '/components/header.php';
?>

<style>
.dns-hero { background: linear-gradient(135deg,#1e3a5f 0%,#0d6efd 100%); border-radius:16px; color:#fff; padding:28px 32px; margin-bottom:28px; }
.dns-hero h4 { font-weight:700; margin-bottom:4px; }
.acc-card { border:1.5px solid #e9ecef; border-radius:14px; transition:.15s; }
.acc-card:hover { border-color:#0d6efd40; box-shadow:0 4px 18px #0d6efd18; }
.acc-avatar { width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0; }
.op-card { border:0; border-radius:16px; box-shadow:0 2px 16px rgba(0,0,0,.07); }
.op-tab-btn { border:0; background:transparent; padding:10px 20px; border-radius:10px; font-weight:500; color:#6c757d; transition:.15s; cursor:pointer; }
.op-tab-btn:hover { background:#f1f3f5; color:#212529; }
.op-tab-btn.active { background:#0d6efd; color:#fff; box-shadow:0 2px 10px #0d6efd40; }
.op-tab-bar { background:#f8f9fa; border-radius:12px; padding:4px; display:inline-flex; gap:4px; margin-bottom:24px; }
.input-card { background:#f8f9fa; border-radius:12px; padding:16px; }
.input-card label { font-size:.8rem; font-weight:600; color:#495057; text-transform:uppercase; letter-spacing:.04em; margin-bottom:6px; }
.input-card textarea, .input-card input, .input-card select { border-radius:8px; border:1.5px solid #dee2e6; font-size:.88rem; }
.input-card textarea:focus, .input-card input:focus, .input-card select:focus { border-color:#0d6efd80; box-shadow:0 0 0 3px #0d6efd15; }
.counter-badge { font-size:.75rem; color:#6c757d; }
.btn-submit { border-radius:10px; padding:10px 0; font-weight:600; font-size:.95rem; }
.switch-row { background:#fff; border:1.5px solid #e9ecef; border-radius:10px; padding:12px 16px; }
</style>

<!-- Hero 区 -->
<div class="dns-hero d-flex align-items-center justify-content-between">
  <div>
    <h4><i class="bi bi-diagram-3-fill me-2"></i>DNS-LA 智能解析</h4>
    <p class="mb-0 opacity-75 small">批量管理解析记录，所有操作均通过后台任务异步处理</p>
  </div>
  <button class="btn btn-light btn-sm fw-semibold" onclick="openAddModal()">
    <i class="bi bi-plus-lg me-1"></i>添加账号
  </button>
</div>

<!-- ══ 账号列表 ══ -->
<?php if (empty($accounts)): ?>
<div class="card border-0 shadow-sm rounded-4 mb-4">
  <div class="card-body text-center py-5">
    <div class="mb-3" style="font-size:3rem;opacity:.25">🔑</div>
    <h6 class="text-muted mb-1">暂无 API 账号</h6>
    <p class="text-muted small mb-3">请先配置 DNS-LA API 账号，才能进行解析操作</p>
    <button class="btn btn-primary" onclick="openAddModal()"><i class="bi bi-plus-lg me-1"></i>立即添加</button>
  </div>
</div>
<?php else: ?>
<div class="row g-3 mb-4">
  <?php foreach ($accounts as $acc):
    $colors = ['primary','success','info','warning','danger','purple'];
    $color  = $colors[$acc['id'] % count($colors)];
  ?>
  <div class="col-12 col-md-6 col-xl-4">
    <div class="acc-card card h-100">
      <div class="card-body d-flex align-items-center gap-3 px-4 py-3">
        <div class="acc-avatar bg-<?= $color ?>-subtle text-<?= $color ?>">
          <i class="bi bi-person-badge-fill"></i>
        </div>
        <div class="flex-grow-1 min-w-0">
          <div class="fw-bold text-truncate"><?= h($acc['name']) ?></div>
          <div class="small text-muted">
            <code class="text-primary"><?= h($acc['api_id']) ?></code>
          </div>
          <div class="small text-muted opacity-75"><?= h($acc['api_base'] ?? DNSLA_API_BASE_DEFAULT) ?></div>
        </div>
        <div class="d-flex flex-column gap-1">
          <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" title="测试连接"
                  onclick="testAccount(<?= h(json_encode($acc['api_id'])) ?>,<?= h(json_encode($acc['api_secret'])) ?>,<?= h(json_encode($acc['api_base'] ?? '')) ?>)">
            <i class="bi bi-activity"></i>
          </button>
          <button class="btn btn-sm btn-outline-warning rounded-pill px-3" onclick="openEditModal(<?= h(json_encode($acc)) ?>)">
            <i class="bi bi-pencil"></i>
          </button>
          <button class="btn btn-sm btn-outline-danger rounded-pill px-3" onclick="deleteAccount(<?= $acc['id'] ?>,'<?= h($acc['name']) ?>')">
            <i class="bi bi-trash"></i>
          </button>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ══ 解析操作卡片 ══ -->
<div class="op-card card">
  <div class="card-body p-4">

    <!-- 标题栏 -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <div>
        <h5 class="fw-bold mb-0"><i class="bi bi-lightning-charge-fill text-warning me-2"></i>批量解析操作</h5>
        <small class="text-muted">选择账号和操作类型，填写域名列表后提交</small>
      </div>
      <div class="d-flex align-items-center gap-2">
        <span class="text-muted small">账号</span>
        <select class="form-select form-select-sm rounded-pill" id="opAccountId" style="width:auto;min-width:130px">
          <?php foreach ($accounts as $acc): ?>
          <option value="<?= $acc['id'] ?>"><?= h($acc['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- Tab 切换按钮 -->
    <div class="op-tab-bar">
      <button class="op-tab-btn active" data-tab="adddom">
        <i class="bi bi-globe-americas me-1"></i>添加域名
      </button>
      <button class="op-tab-btn" data-tab="deldom">
        <i class="bi bi-globe2 me-1"></i>删除域名
      </button>
      <button class="op-tab-btn" data-tab="addrec">
        <i class="bi bi-plus-circle-fill me-1"></i>添加解析记录
      </button>
      <button class="op-tab-btn" data-tab="updrec">
        <i class="bi bi-arrow-repeat me-1"></i>修改记录值
      </button>
      <button class="op-tab-btn" data-tab="delrec">
        <i class="bi bi-trash-fill me-1"></i>删除记录
      </button>
      <button class="op-tab-btn" data-tab="domlist">
        <i class="bi bi-table me-1"></i>域名列表
      </button>
      <button class="op-tab-btn" data-tab="recquery">
        <i class="bi bi-search me-1"></i>查询解析记录
      </button>
      <button class="op-tab-btn" data-tab="nsquery">
        <i class="bi bi-diagram-3 me-1"></i>查询 NS 值
      </button>
      <button class="op-tab-btn" data-tab="retrieve">
        <i class="bi bi-arrow-repeat me-1"></i>找回域名
      </button>
    </div>

    <!-- ── Tab: 添加域名 ── -->
    <div id="tab-adddom" class="tab-pane">
      <div class="row g-3">
        <div class="col-lg-5">
          <div class="input-card h-100">
            <label><i class="bi bi-list-ul me-1"></i>域名列表 <span class="text-muted fw-normal">（每行一个）</span></label>
            <textarea class="form-control font-monospace" id="addDomDomains" rows="12"
                      placeholder="example.com&#10;test.com&#10;domain.net"
                      oninput="countLines(this,'addDomCount')"></textarea>
            <div class="counter-badge mt-1"><i class="bi bi-check2 me-1"></i><strong id="addDomCount">0</strong> 个域名</div>
          </div>
        </div>
        <div class="col-lg-7">
          <div class="input-card h-100 d-flex flex-column gap-3">
            <div class="alert alert-info border-0 rounded-3 small mb-0">
              <i class="bi bi-info-circle-fill me-1"></i>
              将域名列表批量添加到 DNS-LA 账号，添加后需在域名注册商处将 NS 服务器指向 DNS-LA。
            </div>
            <div class="mt-auto">
              <button class="btn btn-success btn-submit w-100" onclick="submitAddDom()">
                <i class="bi bi-send-fill me-2"></i>提交后台任务
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Tab: 删除域名 ── -->
    <div id="tab-deldom" class="tab-pane d-none">
      <div class="row g-3">
        <div class="col-lg-5">
          <div class="input-card h-100">
            <label><i class="bi bi-list-ul me-1"></i>域名列表 <span class="text-muted fw-normal">（每行一个）</span></label>
            <textarea class="form-control font-monospace" id="delDomDomains" rows="12"
                      placeholder="example.com&#10;test.com"
                      oninput="countLines(this,'delDomCount')"></textarea>
            <div class="counter-badge mt-1"><i class="bi bi-check2 me-1"></i><strong id="delDomCount">0</strong> 个域名</div>
          </div>
        </div>
        <div class="col-lg-7">
          <div class="input-card h-100 d-flex flex-column gap-3">
            <div class="alert alert-danger border-0 rounded-3 small mb-0">
              <i class="bi bi-exclamation-triangle-fill me-1"></i>
              <strong>不可逆操作</strong><br>
              从 DNS-LA 删除域名后，该域名的所有解析记录将永久清除，无法恢复。
            </div>
            <div class="mt-auto">
              <button class="btn btn-danger btn-submit w-100" onclick="submitDelDom()">
                <i class="bi bi-send-fill me-2"></i>提交后台任务
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Tab: 添加解析记录 ── -->
    <div id="tab-addrec" class="tab-pane d-none">
      <div class="row g-3">
        <div class="col-lg-4">
          <div class="input-card h-100">
            <label><i class="bi bi-list-ul me-1"></i>域名列表</label>
            <textarea class="form-control font-monospace" id="addDomains" rows="11"
                      placeholder="example.com&#10;test.com&#10;domain.net"
                      oninput="countLines(this,'addDomainCount')"></textarea>
            <div class="counter-badge mt-1"><i class="bi bi-check2 me-1"></i><strong id="addDomainCount">0</strong> 个域名</div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="input-card h-100 d-flex flex-column gap-3">
            <div>
              <label><i class="bi bi-hdd-network me-1"></i>记录值列表 <span class="text-muted fw-normal">（每域名随机取一个）</span></label>
              <textarea class="form-control font-monospace" id="addIps" rows="5"
                        placeholder="A记录填IP：1.1.1.1&#10;CNAME填域名：cdn.example.com"></textarea>
            </div>
            <div>
              <label><i class="bi bi-at me-1"></i>主机头 <span class="text-muted fw-normal">（每行一个）</span></label>
              <textarea class="form-control font-monospace" id="addHosts" rows="4"
                        placeholder="@&#10;www">@</textarea>
            </div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="input-card h-100 d-flex flex-column gap-3">
            <div>
              <label><i class="bi bi-tag me-1"></i>记录类型</label>
              <select class="form-select" id="addRecType" onchange="onRecTypeChange()">
                <option value="1">A（IPv4地址）</option>
                <option value="28">AAAA（IPv6地址）</option>
                <option value="5">CNAME（别名）</option>
                <option value="16">TXT（文本）</option>
                <option value="15">MX（邮件）</option>
                <option value="2">NS（名称服务器）</option>
                <option value="33">SRV</option>
                <option value="257">CAA</option>
                <option value="256">URL转发</option>
              </select>
            </div>
            <div>
              <label><i class="bi bi-clock me-1"></i>TTL（秒）</label>
              <input type="number" class="form-control" id="addTtl" value="600" min="1">
            </div>
            <div class="switch-row" id="clearARow">
              <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" id="addClearA" checked role="switch">
                <label class="form-check-label" for="addClearA">
                  <span class="fw-semibold">添加前清空同类型记录</span><br>
                  <small class="text-muted">避免产生重复解析记录</small>
                </label>
              </div>
            </div>
            <div class="mt-auto pt-2">
              <button class="btn btn-success btn-submit w-100" onclick="submitAdd()">
                <i class="bi bi-send-fill me-2"></i>提交后台任务
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Tab: 修改记录值 ── -->
    <div id="tab-updrec" class="tab-pane d-none">
      <div class="row g-3">
        <div class="col-lg-4">
          <div class="input-card h-100">
            <label><i class="bi bi-list-ul me-1"></i>域名列表</label>
            <textarea class="form-control font-monospace" id="updDomains" rows="11"
                      placeholder="example.com&#10;test.com"
                      oninput="countLines(this,'updDomainCount')"></textarea>
            <div class="counter-badge mt-1"><i class="bi bi-check2 me-1"></i><strong id="updDomainCount">0</strong> 个域名</div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="input-card h-100 d-flex flex-column gap-3">
            <div>
              <label><i class="bi bi-hdd-network me-1"></i>新 IP 列表 <span class="text-muted fw-normal">（每域名随机取一个）</span></label>
              <textarea class="form-control font-monospace" id="updIps" rows="5"
                        placeholder="1.1.1.1&#10;2.2.2.2"></textarea>
            </div>
            <div>
              <label><i class="bi bi-at me-1"></i>主机头筛选 <span class="text-muted fw-normal">（留空=全部）</span></label>
              <textarea class="form-control font-monospace" id="updHosts" rows="4"
                        placeholder="@&#10;www">@</textarea>
            </div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="input-card h-100 d-flex flex-column gap-3">
            <div>
              <label><i class="bi bi-tag me-1"></i>记录类型</label>
              <select class="form-select" id="updType">
                <option value="1">A（IPv4）</option>
                <option value="28">AAAA（IPv6）</option>
                <option value="5">CNAME（别名）</option>
                <option value="16">TXT</option>
                <option value="15">MX</option>
                <option value="2">NS</option>
              </select>
            </div>
            <div class="mt-auto pt-2">
              <button class="btn btn-warning btn-submit w-100" onclick="submitUpdate()">
                <i class="bi bi-send-fill me-2"></i>提交后台任务
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Tab: 删除记录 ── -->
    <div id="tab-delrec" class="tab-pane d-none">
      <div class="row g-3">
        <div class="col-lg-4">
          <div class="input-card h-100">
            <label><i class="bi bi-list-ul me-1"></i>域名列表</label>
            <textarea class="form-control font-monospace" id="delDomains" rows="11"
                      placeholder="example.com&#10;test.com"
                      oninput="countLines(this,'delDomainCount')"></textarea>
            <div class="counter-badge mt-1"><i class="bi bi-check2 me-1"></i><strong id="delDomainCount">0</strong> 个域名</div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="input-card h-100 d-flex flex-column gap-3">
            <div>
              <label><i class="bi bi-at me-1"></i>主机头筛选 <span class="text-muted fw-normal">（留空=匹配全部）</span></label>
              <textarea class="form-control font-monospace" id="delHosts" rows="6"
                        placeholder="@&#10;www&#10;（留空则不限主机头）"></textarea>
            </div>
            <div>
              <label><i class="bi bi-tag me-1"></i>记录类型</label>
              <select class="form-select" id="delType">
                <option value="1">A（IPv4）</option>
                <option value="28">AAAA（IPv6）</option>
                <option value="5">CNAME（别名）</option>
                <option value="16">TXT</option>
                <option value="15">MX</option>
                <option value="2">NS</option>
                <option value="33">SRV</option>
                <option value="257">CAA</option>
                <option value="256">URL转发</option>
                <option value="0">全部类型</option>
              </select>
            </div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="input-card h-100 d-flex flex-column">
            <div class="alert alert-danger border-0 rounded-3 small mb-3">
              <i class="bi bi-exclamation-triangle-fill me-1"></i>
              <strong>不可逆操作</strong><br>
              删除后无法恢复，请确认域名列表和筛选条件无误。
            </div>
            <div class="mt-auto">
              <button class="btn btn-danger btn-submit w-100" onclick="submitDel()">
                <i class="bi bi-send-fill me-2"></i>提交后台任务
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Tab: 域名列表 ── -->
    <div id="tab-domlist" class="tab-pane d-none">
      <div class="d-flex align-items-center gap-2 mb-3">
        <button class="btn btn-primary btn-sm rounded-pill px-3" onclick="loadDomainList(1)">
          <i class="bi bi-arrow-clockwise me-1"></i>加载 / 刷新
        </button>
        <span id="domlistInfo" class="text-muted small"></span>
        <div id="domlistSpinner" class="spinner-border spinner-border-sm text-primary d-none" role="status"></div>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>域名</th>
              <th>状态</th>
              <th>NS 状态</th>
              <th>套餐</th>
              <th>过期时间</th>
              <th>分组</th>
            </tr>
          </thead>
          <tbody id="domlistBody">
            <tr><td colspan="7" class="text-center text-muted py-4">点击「加载 / 刷新」查看域名列表</td></tr>
          </tbody>
        </table>
      </div>

      <!-- 分页 -->
      <div id="domlistPager" class="d-flex justify-content-center gap-1 mt-3 flex-wrap"></div>
    </div>

    <!-- ── Tab: 查询 NS 值 ── -->
    <div id="tab-nsquery" class="tab-pane d-none">
      <div class="row g-3">
        <div class="col-lg-4">
          <div class="input-card h-100 d-flex flex-column gap-3">
            <div>
              <label><i class="bi bi-list-ul me-1"></i>域名列表 <span class="text-muted fw-normal">（每行一个）</span></label>
              <textarea class="form-control font-monospace" id="nsDomains" rows="12"
                        placeholder="example.com&#10;test.com"
                        oninput="countLines(this,'nsDomainCount')"></textarea>
              <div class="counter-badge mt-1"><i class="bi bi-check2 me-1"></i><strong id="nsDomainCount">0</strong> 个域名</div>
            </div>
            <div class="d-flex flex-column gap-2 mt-auto">
              <button class="btn btn-primary btn-submit w-100" id="btnNsQuery" onclick="submitNsQuery()">
                <i class="bi bi-diagram-3 me-2"></i>查询 NS 服务器值
              </button>
              <button class="btn btn-outline-success w-100" id="btnNsStateCheck" onclick="submitNsStateCheck()">
                <i class="bi bi-shield-check me-2"></i>检查 NS 接入状态
              </button>
            </div>
          </div>
        </div>

        <div class="col-lg-8 d-flex flex-column gap-3">
          <!-- NS 服务器值 -->
          <div class="input-card">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <label class="mb-0"><i class="bi bi-card-list me-1"></i>NS 服务器值</label>
              <div class="d-flex gap-2 align-items-center">
                <span id="nsStats" class="text-muted small"></span>
                <button class="btn btn-xs btn-outline-secondary d-none" id="btnCopyNs" onclick="copyNsResult()">
                  <i class="bi bi-clipboard me-1"></i>复制全部 NS
                </button>
              </div>
            </div>
            <div id="nsResult" style="max-height:280px;overflow-y:auto">
              <div class="text-center text-muted py-4 small">输入域名后点击查询，获取需要在注册商处填写的 NS 服务器</div>
            </div>
          </div>
          <!-- NS 接入状态 -->
          <div class="input-card d-none" id="nsStateCard">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <label class="mb-0"><i class="bi bi-shield-check me-1"></i>NS 接入状态</label>
              <span id="nsStateStats" class="text-muted small"></span>
            </div>
            <div id="nsStateResult" style="max-height:260px;overflow-y:auto">
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Tab: 找回域名 ── -->
    <div id="tab-retrieve" class="tab-pane d-none">
      <div class="row g-3">
        <!-- 左侧：输入 + 操作 -->
        <div class="col-lg-4">
          <div class="input-card h-100 d-flex flex-column gap-3">
            <div>
              <label><i class="bi bi-list-ul me-1"></i>需找回的域名 <span class="text-muted fw-normal">（每行一个）</span></label>
              <textarea class="form-control font-monospace" id="retrieveDomains" rows="10"
                        placeholder="example.com&#10;test.com"
                        oninput="countLines(this,'retrieveDomainCount')"></textarea>
              <div class="counter-badge mt-1"><i class="bi bi-check2 me-1"></i><strong id="retrieveDomainCount">0</strong> 个域名</div>
            </div>
            <!-- 操作说明 -->
            <div class="small text-muted px-1" style="line-height:1.7">
              <div class="fw-semibold text-body mb-1"><i class="bi bi-info-circle me-1 text-primary"></i>操作流程</div>
              <div><span class="badge bg-primary rounded-pill me-1">1</span>点击「生成找回任务」→ 获取 TXT 记录</div>
              <div><span class="badge bg-primary rounded-pill me-1">2</span>在注册商处添加对应 TXT 解析</div>
              <div><span class="badge bg-primary rounded-pill me-1">3</span>点击「查询验证状态」→ 查看是否成功</div>
              <div class="text-muted mt-1"><i class="bi bi-clock me-1"></i>DNS-LA <strong>自动检测</strong>，通常 10-60 分钟</div>
              <div class="text-warning mt-1"><i class="bi bi-exclamation-triangle me-1"></i>状态「验证失败」时可点「重新触发」重置任务</div>
            </div>
            <div class="d-flex flex-column gap-2 mt-auto">
              <button class="btn btn-primary btn-submit w-100" id="btnRetrieve" onclick="submitRetrieve()">
                <i class="bi bi-arrow-repeat me-2"></i>第一步：生成找回任务
              </button>
              <button class="btn btn-success w-100" id="btnRetrieveDns" onclick="submitRetrieveDnsCheck()">
                <i class="bi bi-wifi me-2"></i>第三步：直接验证 TXT（推荐）
              </button>
              <button class="btn btn-outline-secondary w-100" id="btnRetrieveCheck" onclick="submitRetrieveCheck(false)">
                <i class="bi bi-cloud-check me-2"></i>查询 DNS-LA 平台状态
              </button>
              <button class="btn btn-outline-warning w-100" id="btnRetrieveRetry" onclick="submitRetrieveCheck(true)">
                <i class="bi bi-arrow-clockwise me-2"></i>重置失败任务
              </button>
            </div>
          </div>
        </div>

        <!-- 右侧：结果显示 -->
        <div class="col-lg-8">
          <!-- TXT 记录 -->
          <div class="input-card mb-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <label class="mb-0"><i class="bi bi-key me-1"></i><strong>第二步：在注册商添加以下 TXT 记录</strong></label>
              <div class="d-flex gap-2 align-items-center">
                <span id="retrieveStats" class="text-muted small"></span>
                <button class="btn btn-xs btn-outline-secondary d-none" id="btnCopyRetrieve" onclick="copyRetrieveResult()">
                  <i class="bi bi-clipboard me-1"></i>复制全部
                </button>
              </div>
            </div>
            <div class="text-muted small mb-1" style="font-family:monospace">格式：域名 | 主机记录 | 记录类型 | 记录值</div>
            <textarea class="form-control font-monospace" id="retrieveResult" rows="6"
                      readonly placeholder="点击「第一步：生成找回任务」后在此显示 TXT 记录…" style="font-size:12px;resize:none;background:#f8f9fa"></textarea>
            <div id="retrieveFailList" class="mt-2 d-none">
              <div class="text-danger small fw-semibold mb-1"><i class="bi bi-x-circle me-1"></i>创建任务失败</div>
              <textarea class="form-control font-monospace text-danger" rows="3" readonly id="retrieveFailContent" style="font-size:11px;resize:none"></textarea>
            </div>
          </div>
          <!-- 验证状态 -->
          <div class="input-card d-none" id="retrieveStatusCard">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <label class="mb-0"><i class="bi bi-shield-check me-1"></i><strong>验证状态</strong></label>
              <span id="retrieveStatusStats" class="text-muted small"></span>
            </div>
            <div id="retrieveStatusList" style="max-height:220px;overflow-y:auto"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Tab: 查询解析记录 ── -->
    <div id="tab-recquery" class="tab-pane d-none">
      <div class="row g-3">
        <div class="col-lg-4">
          <div class="input-card h-100 d-flex flex-column gap-3">
            <div>
              <label><i class="bi bi-list-ul me-1"></i>域名列表 <span class="text-muted fw-normal">（每行一个，最多100个）</span></label>
              <textarea class="form-control font-monospace" id="queryDomains" rows="10"
                        placeholder="example.com&#10;test.com"
                        oninput="countLines(this,'queryDomainCount')"></textarea>
              <div class="counter-badge mt-1"><i class="bi bi-check2 me-1"></i><strong id="queryDomainCount">0</strong> 个域名</div>
            </div>
            <div>
              <label><i class="bi bi-tag me-1"></i>记录类型</label>
              <select class="form-select" id="queryRecType">
                <option value="1">A（IPv4）</option>
                <option value="28">AAAA（IPv6）</option>
                <option value="5">CNAME</option>
                <option value="16">TXT</option>
                <option value="15">MX</option>
                <option value="2">NS</option>
                <option value="33">SRV</option>
                <option value="257">CAA</option>
                <option value="256">URL转发</option>
                <option value="0">全部类型</option>
              </select>
            </div>
            <div class="mt-auto">
              <button class="btn btn-primary btn-submit w-100" id="btnQuery" onclick="submitQuery()">
                <i class="bi bi-search me-2"></i>立即查询
              </button>
            </div>
          </div>
        </div>

        <div class="col-lg-8">
          <div class="input-card h-100">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <label class="mb-0"><i class="bi bi-card-list me-1"></i>查询结果</label>
              <div class="d-flex gap-2 align-items-center">
                <span id="queryStats" class="text-muted small"></span>
                <button class="btn btn-xs btn-outline-secondary" onclick="copyQueryResult()" id="btnCopyQuery" style="display:none">
                  <i class="bi bi-clipboard me-1"></i>复制所有记录值
                </button>
              </div>
            </div>
            <div id="queryResult" style="max-height:560px;overflow-y:auto">
              <div class="text-center text-muted py-5 small">输入域名后点击查询</div>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>
<?php endif; ?>


<!-- ══ 账号弹窗 ══ -->
<div class="modal fade" id="accountModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow rounded-4">
      <div class="modal-header border-0 pb-0 px-4 pt-4">
        <h5 class="modal-title fw-bold" id="accountModalTitle">添加账号</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4">
        <input type="hidden" id="editId" value="0">
        <div class="mb-3">
          <label class="form-label fw-semibold small text-uppercase text-muted">备注名称 <span class="text-danger">*</span></label>
          <input type="text" class="form-control rounded-3" id="accName" placeholder="如：主账号、测试账号">
        </div>
        <div class="row g-3 mb-3">
          <div class="col-6">
            <label class="form-label fw-semibold small text-uppercase text-muted">APIID <span class="text-danger">*</span></label>
            <input type="text" class="form-control rounded-3" id="accApiId">
          </div>
          <div class="col-6">
            <label class="form-label fw-semibold small text-uppercase text-muted">APISecret <span class="text-danger">*</span></label>
            <input type="text" class="form-control rounded-3" id="accApiSecret">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold small text-uppercase text-muted">API 地址</label>
          <input type="text" class="form-control rounded-3" id="accApiBase" placeholder="<?= DNSLA_API_BASE_DEFAULT ?>">
          <div class="form-text">留空使用默认 <?= DNSLA_API_BASE_DEFAULT ?></div>
        </div>
        <div id="testResult" class="d-none rounded-3"></div>
      </div>
      <div class="modal-footer border-0 px-4 pb-4 pt-0">
        <button class="btn btn-outline-secondary rounded-pill" id="btnTestModal" onclick="testAccountModal()">
          <i class="bi bi-activity me-1"></i>测试连接
        </button>
        <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">取消</button>
        <button type="button" class="btn btn-primary rounded-pill px-4" onclick="saveAccount()">
          <i class="bi bi-check-lg me-1"></i>保存
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// ── Tab 切换 ─────────────────────────────────────────────────────
document.querySelectorAll('.op-tab-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.op-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.add('d-none'));
    this.classList.add('active');
    document.getElementById('tab-' + this.dataset.tab).classList.remove('d-none');
  });
});

// 切换记录类型时更新提示文字
function onRecTypeChange() {
  var t = document.getElementById('addRecType').value;
  var ph = (t === '1' || t === '28') ? 'IP地址（如 1.1.1.1）' : (t === '5' ? 'CNAME目标（如 cdn.example.com）' : '记录值');
  document.getElementById('addIps').placeholder = ph;
}

// ── 提交：添加域名 ────────────────────────────────────────────────
function submitAddDom() {
  var domains = parseDomains(document.getElementById('addDomDomains').value);
  if (!domains.length) { alert('请输入域名列表'); return; }
  if (!confirm('确认将 ' + domains.length + ' 个域名添加到 DNS-LA？')) return;
  var fd = new FormData();
  fd.append('action', 'add_domains'); fd.append('account_id', getAccountId());
  fd.append('domains', domains.join('\n'));
  fetch('/dns/dns_la/api/domain_job.php', {method:'POST', body:fd})
    .then(r=>r.json()).then(d=>{
      if(d.ok) window.location.href='/jobs/progress.php?id='+d.job_id;
      else alert(d.msg||'提交失败');
    });
}

// ── 提交：删除域名 ────────────────────────────────────────────────
function submitDelDom() {
  var domains = parseDomains(document.getElementById('delDomDomains').value);
  if (!domains.length) { alert('请输入域名列表'); return; }
  if (!confirm('确认从 DNS-LA 永久删除这 ' + domains.length + ' 个域名及其所有解析记录？\n此操作不可逆！')) return;
  var fd = new FormData();
  fd.append('action', 'del_domains'); fd.append('account_id', getAccountId());
  fd.append('domains', domains.join('\n'));
  fetch('/dns/dns_la/api/domain_job.php', {method:'POST', body:fd})
    .then(r=>r.json()).then(d=>{
      if(d.ok) window.location.href='/jobs/progress.php?id='+d.job_id;
      else alert(d.msg||'提交失败');
    });
}

function parseDomains(text) {
  return text.split('\n').map(s => s.trim()).filter(s => s.length > 0);
}
function countLines(el, countId) {
  document.getElementById(countId).textContent = parseDomains(el.value).length;
}
function getAccountId() {
  var sel = document.getElementById('opAccountId');
  return sel ? sel.value : '';
}

// ── 提交：添加解析记录 ───────────────────────────────────────────
function submitAdd() {
  var domains = parseDomains(document.getElementById('addDomains').value);
  var vals    = parseDomains(document.getElementById('addIps').value);
  var hosts   = parseDomains(document.getElementById('addHosts').value);
  var ttl     = document.getElementById('addTtl').value || 600;
  var clearA  = document.getElementById('addClearA').checked ? '1' : '0';
  var recType = document.getElementById('addRecType').value;
  if (!domains.length) { alert('请输入域名列表'); return; }
  if (!vals.length)    { alert('请输入记录值列表'); return; }
  if (!hosts.length)   { alert('请输入主机头'); return; }
  if (!confirm('确认为 ' + domains.length + ' 个域名批量添加解析记录？')) return;
  var fd = new FormData();
  fd.append('action','add_records'); fd.append('account_id',getAccountId());
  fd.append('domains',domains.join('\n')); fd.append('ips',vals.join('\n'));
  fd.append('hosts',hosts.join('\n')); fd.append('ttl',ttl);
  fd.append('clear_a',clearA); fd.append('rec_type',recType);
  fetch('/dns/dns_la/api/record_job.php', {method:'POST',body:fd})
    .then(r=>r.json()).then(d=>{
      if(d.ok) window.location.href='/jobs/progress.php?id='+d.job_id;
      else alert(d.msg||'提交失败');
    });
}

// ── 提交：修改记录值 ─────────────────────────────────────────────
function submitUpdate() {
  var domains = parseDomains(document.getElementById('updDomains').value);
  var ips     = parseDomains(document.getElementById('updIps').value);
  var hosts   = parseDomains(document.getElementById('updHosts') ? document.getElementById('updHosts').value : '');
  var type    = document.getElementById('updType').value;
  if (!domains.length) { alert('请输入域名列表'); return; }
  if (!ips.length)     { alert('请输入新 IP 列表'); return; }
  if (!confirm('确认修改 '+domains.length+' 个域名的解析记录值？')) return;
  var fd = new FormData();
  fd.append('action','update_records'); fd.append('account_id',getAccountId());
  fd.append('domains',domains.join('\n')); fd.append('ips',ips.join('\n'));
  fd.append('hosts',hosts.join('\n')); fd.append('rec_type',type);
  fetch('/dns/dns_la/api/record_job.php', {method:'POST',body:fd})
    .then(r=>r.json()).then(d=>{
      if(d.ok) window.location.href='/jobs/progress.php?id='+d.job_id;
      else alert(d.msg||'提交失败');
    });
}

// ── 提交：删除记录 ───────────────────────────────────────────────
function submitDel() {
  var domains = parseDomains(document.getElementById('delDomains').value);
  var hosts   = parseDomains(document.getElementById('delHosts').value);
  var type    = document.getElementById('delType').value;
  if (!domains.length) { alert('请输入域名列表'); return; }
  if (!confirm('确认删除 '+domains.length+' 个域名中匹配的解析记录？此操作不可逆！')) return;
  var fd = new FormData();
  fd.append('action','del_records'); fd.append('account_id',getAccountId());
  fd.append('domains',domains.join('\n')); fd.append('hosts',hosts.join('\n'));
  fd.append('rec_type',type);
  fetch('/dns/dns_la/api/record_job.php', {method:'POST',body:fd})
    .then(r=>r.json()).then(d=>{
      if(d.ok) window.location.href='/jobs/progress.php?id='+d.job_id;
      else alert(d.msg||'提交失败');
    });
}

// ── 账号弹窗 ────────────────────────────────────────────────────
function openAddModal() {
  document.getElementById('accountModalTitle').textContent = '添加账号';
  document.getElementById('editId').value = '0';
  ['accName','accApiId','accApiSecret','accApiBase'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('testResult').className = 'd-none';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('accountModal')).show();
}
function openEditModal(acc) {
  document.getElementById('accountModalTitle').textContent = '编辑账号';
  document.getElementById('editId').value = acc.id;
  document.getElementById('accName').value = acc.name;
  document.getElementById('accApiId').value = acc.api_id;
  document.getElementById('accApiSecret').value = acc.api_secret;
  document.getElementById('accApiBase').value = acc.api_base || '';
  document.getElementById('testResult').className = 'd-none';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('accountModal')).show();
}
function saveAccount() {
  var fd = new FormData();
  fd.append('_account_action','save');
  fd.append('edit_id',    document.getElementById('editId').value);
  fd.append('name',       document.getElementById('accName').value.trim());
  fd.append('api_id',     document.getElementById('accApiId').value.trim());
  fd.append('api_secret', document.getElementById('accApiSecret').value.trim());
  fd.append('api_base',   document.getElementById('accApiBase').value.trim());
  fetch('', {method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(d.ok) location.reload(); else alert(d.msg||'保存失败');
  });
}
function deleteAccount(id, name) {
  if (!confirm('确认删除账号「'+name+'」？')) return;
  var fd = new FormData(); fd.append('_account_action','delete'); fd.append('id',id);
  fetch('', {method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.ok) location.reload(); });
}
function testAccountModal() {
  var btn = document.getElementById('btnTestModal');
  btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>测试中…';
  var fd = new FormData();
  fd.append('_account_action','test');
  fd.append('api_id',     document.getElementById('accApiId').value.trim());
  fd.append('api_secret', document.getElementById('accApiSecret').value.trim());
  fd.append('api_base',   document.getElementById('accApiBase').value.trim());
  fetch('', {method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    var el = document.getElementById('testResult');
    el.className = 'alert '+(d.ok?'alert-success':'alert-danger')+' py-2 small';
    el.textContent = d.msg;
    btn.disabled = false; btn.innerHTML = '<i class="bi bi-activity me-1"></i>测试连接';
  });
}
function testAccount(apiId, apiSecret, apiBase) {
  var fd = new FormData();
  fd.append('_account_action','test');
  fd.append('api_id',apiId); fd.append('api_secret',apiSecret); fd.append('api_base',apiBase);
  fetch('', {method:'POST',body:fd}).then(r=>r.json()).then(d=>alert(d.msg));
}

// ── 域名列表 ──────────────────────────────────────────────────────
var _domlistPage = 1;
function loadDomainList(page) {
  _domlistPage = page || 1;
  var accId = getAccountId();
  var spinner = document.getElementById('domlistSpinner');
  var info    = document.getElementById('domlistInfo');
  var tbody   = document.getElementById('domlistBody');
  spinner.classList.remove('d-none');
  info.textContent = '加载中…';
  tbody.innerHTML  = '<tr><td colspan="7" class="text-center text-muted py-3"><div class="spinner-border spinner-border-sm me-2"></div>请求中…</td></tr>';

  fetch('/dns/dns_la/api/domain_list.php?account_id=' + encodeURIComponent(accId) + '&p=' + _domlistPage)
    .then(r => r.json())
    .then(function(d) {
      spinner.classList.add('d-none');
      if (!d.ok) {
        info.textContent = '加载失败：' + d.msg;
        tbody.innerHTML  = '<tr><td colspan="7" class="text-center text-danger py-3">' + d.msg + '</td></tr>';
        return;
      }
      info.textContent = '共 ' + d.total + ' 个域名，第 ' + d.page + ' / ' + d.totalPages + ' 页';

      var nsColors = {0:'secondary',1:'success',2:'warning',3:'danger'};
      var stColors = {1:'success',2:'warning'};
      var rows = d.rows.map(function(row, i) {
        return '<tr>'
          + '<td class="text-muted">' + ((_domlistPage - 1) * d.pageSize + i + 1) + '</td>'
          + '<td class="fw-semibold">' + row.domain
            + (row.groupName ? '<span class="badge bg-secondary-subtle text-secondary ms-1">' + row.groupName + '</span>' : '')
          + '</td>'
          + '<td><span class="badge bg-' + (stColors[row.state]||'secondary') + '-subtle text-' + (stColors[row.state]||'secondary') + ' border border-' + (stColors[row.state]||'secondary') + '-subtle">' + row.stateLabel + '</span></td>'
          + '<td><span class="badge bg-' + (nsColors[row.nsState]||'secondary') + '-subtle text-' + (nsColors[row.nsState]||'secondary') + ' border border-' + (nsColors[row.nsState]||'secondary') + '-subtle">' + row.nsStateLabel + '</span></td>'
          + '<td>' + row.product + '</td>'
          + '<td>' + row.expiredAt + '</td>'
          + '<td>' + (row.groupName || '<span class="text-muted">默认</span>') + '</td>'
          + '</tr>';
      });
      tbody.innerHTML = rows.length ? rows.join('') : '<tr><td colspan="7" class="text-center text-muted py-4">暂无域名</td></tr>';

      // 分页按钮
      var pager = document.getElementById('domlistPager');
      pager.innerHTML = '';
      if (d.totalPages > 1) {
        for (var pg = 1; pg <= d.totalPages; pg++) {
          (function(p) {
            var btn = document.createElement('button');
            btn.className = 'btn btn-sm rounded-pill ' + (p === d.page ? 'btn-primary' : 'btn-outline-secondary');
            btn.style.minWidth = '36px';
            btn.textContent = p;
            btn.onclick = function() { loadDomainList(p); };
            pager.appendChild(btn);
          })(pg);
        }
      }
    })
    .catch(function(e) {
      spinner.classList.add('d-none');
      info.textContent = '请求失败';
      tbody.innerHTML  = '<tr><td colspan="7" class="text-danger text-center py-3">网络错误：' + e.message + '</td></tr>';
    });
}

// 切换到域名列表 Tab 时自动加载
document.querySelectorAll('.op-tab-btn').forEach(function(btn) {
  if (btn.dataset.tab === 'domlist') {
    btn.addEventListener('click', function() {
      setTimeout(function() { if (!document.getElementById('tab-domlist').classList.contains('d-none')) loadDomainList(1); }, 50);
    });
  }
});

// ── 查询解析记录（后台任务版）────────────────────────────────────
var _queryAllData  = [];
var _queryNotFound = [];
var _queryNoRecord = [];
var _queryJobTimer = null;

function submitQuery() {
  var domains = parseDomains(document.getElementById('queryDomains').value);
  if (!domains.length) { alert('请输入域名列表'); return; }

  var btn     = document.getElementById('btnQuery');
  var result  = document.getElementById('queryResult');
  var stats   = document.getElementById('queryStats');
  var copyBtn = document.getElementById('btnCopyQuery');
  _queryAllData = []; _queryNotFound = []; _queryNoRecord = [];
  copyBtn.style.display = 'none';
  if (_queryJobTimer) { clearInterval(_queryJobTimer); _queryJobTimer = null; }

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>提交中…';
  result.innerHTML = '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm me-2"></div>正在提交查询任务…</div>';
  stats.textContent = '';

  var fd = new FormData();
  fd.append('account_id', getAccountId());
  fd.append('domains',    domains.join('\n'));
  fd.append('rec_type',   document.getElementById('queryRecType').value);

  fetch('/dns/dns_la/api/record_query_job.php', {method: 'POST', body: fd})
    .then(function(r){ return r.json(); })
    .then(function(d) {
      if (!d.ok) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-search me-2"></i>立即查询';
        result.innerHTML = '<div class="alert alert-danger">' + d.msg + '</div>';
        return;
      }
      var jobId = d.job_id;
      var total = d.total;
      result.innerHTML = '<div class="text-center py-3">'
        + '<div class="spinner-border spinner-border-sm text-primary me-2"></div>'
        + '<span id="qJobMsg">后台处理中… 0 / ' + total + '</span>'
        + '<div class="progress mt-2" style="height:6px"><div id="qJobBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div></div>'
        + '</div>';

      // 轮询进度
      _queryJobTimer = setInterval(function() {
        fetch('/jobs/api/status.php?id=' + encodeURIComponent(jobId))
          .then(function(r){ return r.json(); })
          .then(function(s) {
            if (!s.ok) return;
            var job = s.job;
            var msgEl = document.getElementById('qJobMsg');
            var barEl = document.getElementById('qJobBar');
            if (msgEl) msgEl.textContent = (job.message || '处理中…') + '  ' + job.progress + ' / ' + job.total;
            if (barEl) barEl.style.width = job.pct + '%';

            if (job.status === 'done' || job.status === 'failed') {
              clearInterval(_queryJobTimer); _queryJobTimer = null;
              btn.disabled = false;
              btn.innerHTML = '<i class="bi bi-search me-2"></i>立即查询';

              if (job.status === 'failed') {
                result.innerHTML = '<div class="alert alert-danger">任务失败：' + job.message + '</div>';
                return;
              }
              // 拉取结果
              fetch('/dns/dns_la/api/record_query_result.php?job_id=' + encodeURIComponent(jobId))
                .then(function(r){ return r.json(); })
                .then(function(data) {
                  if (!data.ok) { result.innerHTML = '<div class="alert alert-danger">' + data.msg + '</div>'; return; }
                  renderQueryResults(data.results, stats, result, copyBtn);
                });
            }
          }).catch(function(){});
      }, 1500);
    })
    .catch(function(e) {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-search me-2"></i>立即查询';
      result.innerHTML = '<div class="alert alert-danger">提交失败：' + e.message + '</div>';
    });
}

// ── 渲染查询解析记录结果 ───────────────────────────────────────────
function renderQueryResults(resultList, statsEl, resultEl, copyBtn) {
  var totalRecs      = 0;
  var notFound       = 0;
  var noRecords      = 0;
  var apiFailed      = 0;
  var rows           = '';
  var rowIdx         = 0;
  var apiFailed_list = [];
  _queryAllData  = [];
  _queryNotFound = [];
  _queryNoRecord = [];

  resultList.forEach(function(item) {
    if (!item.found) {
      if (item.error_type === 'api_error') {
        apiFailed++;
        apiFailed_list.push([item.domain, item.error_msg || '未知错误']);
      } else {
        notFound++;
        _queryNotFound.push(item.domain);
      }
      return;
    }
    if (!item.records || item.records.length === 0) {
      noRecords++;
      _queryNoRecord.push(item.domain);
      return;
    }
    totalRecs += item.records.length;
    item.records.forEach(function(r) { _queryAllData.push(r.data); });

    var hosts  = item.records.map(function(r) { return r.host === '@' ? '<b class="text-primary">@</b>' : r.host; });
    var datas  = item.records.map(function(r) { return r.data; });
    var ttls   = item.records.map(function(r) { return r.ttl + 's'; });
    var lines  = item.records.map(function(r) { return r.lineName || '默认'; });
    var states = item.records.map(function(r) {
      return r.disable
        ? '<span class="badge bg-secondary-subtle text-secondary">暂停</span>'
        : '<span class="badge bg-success-subtle text-success">正常</span>';
    });
    var sep = '<span class="text-muted mx-1">,</span>';
    var bg  = rowIdx % 2 === 0 ? '' : ' style="background:#f8f9ff"';
    rowIdx++;
    rows += '<tr' + bg + '>'
      + '<td class="font-monospace text-nowrap fw-semibold">' + item.domain + '</td>'
      + '<td class="font-monospace text-nowrap">' + hosts.join(sep) + '</td>'
      + '<td class="font-monospace text-break" style="max-width:280px">' + datas.join(sep) + '</td>'
      + '<td class="text-nowrap">' + ttls.join(sep) + '</td>'
      + '<td class="text-nowrap">' + lines.join(sep) + '</td>'
      + '<td class="text-nowrap">' + states.join(sep) + '</td>'
      + '</tr>';
  });

  var html = '';
  if (rows) {
    html += '<div class="table-responsive">'
      + '<table class="table table-sm table-bordered mb-0 small align-middle">'
      + '<thead class="table-light sticky-top"><tr><th>域名</th><th>主机头</th><th>记录值</th><th>TTL</th><th>线路</th><th>状态</th></tr></thead>'
      + '<tbody>' + rows + '</tbody></table></div>';
  }
  if (_queryNotFound.length) {
    html += '<div class="mt-3 border border-danger-subtle rounded-3 overflow-hidden">'
      + '<div class="d-flex align-items-center gap-2 px-3 py-2 bg-danger-subtle">'
      + '<span class="badge bg-danger">' + _queryNotFound.length + ' 个</span>'
      + '<span class="fw-semibold text-danger small">不在账号中（域名未托管）</span>'
      + '<button class="btn btn-xs btn-outline-danger ms-auto" onclick="copyList(_queryNotFound)"><i class="bi bi-clipboard me-1"></i>复制</button>'
      + '</div><div class="p-2 d-flex flex-wrap gap-1">'
      + _queryNotFound.map(function(d){ return '<span class="badge bg-danger-subtle text-danger border border-danger-subtle font-monospace">' + d + '</span>'; }).join('')
      + '</div></div>';
  }
  if (_queryNoRecord.length) {
    html += '<div class="mt-2 border border-secondary-subtle rounded-3 overflow-hidden">'
      + '<div class="d-flex align-items-center gap-2 px-3 py-2 bg-secondary-subtle">'
      + '<span class="badge bg-secondary">' + _queryNoRecord.length + ' 个</span>'
      + '<span class="fw-semibold text-secondary small">无解析记录（账号内存在但无匹配记录）</span>'
      + '<button class="btn btn-xs btn-outline-secondary ms-auto" onclick="copyList(_queryNoRecord)"><i class="bi bi-clipboard me-1"></i>复制</button>'
      + '</div><div class="p-2 d-flex flex-wrap gap-1">'
      + _queryNoRecord.map(function(d){ return '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle font-monospace">' + d + '</span>'; }).join('')
      + '</div></div>';
  }
  if (apiFailed_list.length) {
    var groups = {};
    apiFailed_list.forEach(function(item) {
      if (!groups[item[1]]) groups[item[1]] = [];
      groups[item[1]].push(item[0]);
    });
    html += '<div class="mt-2 border border-warning-subtle rounded-3 overflow-hidden">'
      + '<div class="d-flex align-items-center gap-2 px-3 py-2 bg-warning-subtle">'
      + '<span class="badge bg-warning text-dark">' + apiFailed_list.length + ' 个</span>'
      + '<span class="fw-semibold text-warning-emphasis small">查询失败（建议重新查询）</span></div>';
    Object.keys(groups).forEach(function(reason) {
      html += '<div class="px-3 pt-2 pb-1 border-top border-warning-subtle">'
        + '<div class="small text-muted mb-1">原因：' + reason + '</div>'
        + '<div class="d-flex flex-wrap gap-1">'
        + groups[reason].map(function(d){ return '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle font-monospace">' + d + '</span>'; }).join('')
        + '</div></div>';
    });
    html += '</div>';
  }

  resultEl.innerHTML = html || '<div class="text-muted text-center py-4">无结果</div>';
  statsEl.textContent = resultList.length + ' 个域名，' + totalRecs + ' 条记录'
    + (notFound  > 0 ? '，' + notFound  + ' 个不在账号' : '')
    + (noRecords > 0 ? '，' + noRecords + ' 个无记录'   : '')
    + (apiFailed > 0 ? '，' + apiFailed + ' 个查询失败' : '');
  if (_queryAllData.length > 0) copyBtn.style.display = '';
}

// ── 查询 NS 值（分批 + 按 NS 组聚合）──────────────────────────────
var _nsAllData   = [];  // 去重 NS 列表（用于"复制全部 NS"）
var _nsGroupData = {};  // key=NS组合字符串, value={ns:[], domains:[]}
var _nsNotFound  = [];  // 域名不在账号中
var _nsNoRecords = [];  // 域名在账号中但无 NS 记录

function submitNsQuery() {
  var allDomains = parseDomains(document.getElementById('nsDomains').value);
  if (!allDomains.length) { alert('请输入域名列表'); return; }

  var btn     = document.getElementById('btnNsQuery');
  var result  = document.getElementById('nsResult');
  var stats   = document.getElementById('nsStats');
  var copyBtn = document.getElementById('btnCopyNs');
  _nsAllData   = [];
  _nsGroupData = {};
  _nsNotFound  = [];
  _nsNoRecords = [];
  copyBtn.classList.add('d-none');

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>查询中 0 / ' + allDomains.length + '…';
  result.innerHTML = '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm me-2"></div>正在查询 ' + allDomains.length + ' 个域名的 NS…</div>';
  stats.textContent = '';

  var BATCH = 50;
  var batches = [];
  for (var i = 0; i < allDomains.length; i += BATCH) {
    batches.push(allDomains.slice(i, i + BATCH));
  }

  var done = 0;

  function runBatch(idx) {
    if (idx >= batches.length) {
      // 全部完成，渲染
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-diagram-3 me-2"></i>查询 NS 值';
      renderNsGroups(allDomains.length, btn, result, stats, copyBtn);
      return;
    }

    var fd = new FormData();
    fd.append('account_id', getAccountId());
    fd.append('domains',    batches[idx].join('\n'));
    fd.append('rec_type',   '2');

    fetch('/dns/dns_la/api/record_query.php', {method: 'POST', body: fd})
      .then(r => r.json())
      .then(function(d) {
        if (!d.ok) { throw new Error(d.msg || '请求失败'); }

        d.results.forEach(function(item) {
          if (!item.found) {
            _nsNotFound.push(item.domain);
            return;
          }
          if (!item.records.length) {
            _nsNoRecords.push(item.domain);
            return;
          }
          var nsServers = item.records
            .map(function(r) { return r.data.replace(/\.$/, '').toLowerCase(); })
            .sort();
          var key = nsServers.join('|');
          if (!_nsGroupData[key]) _nsGroupData[key] = { ns: nsServers, domains: [] };
          _nsGroupData[key].domains.push(item.domain);
          nsServers.forEach(function(ns) {
            if (_nsAllData.indexOf(ns) === -1) _nsAllData.push(ns);
          });
        });

        done += batches[idx].length;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>查询中 ' + done + ' / ' + allDomains.length + '…';
        runBatch(idx + 1);
      })
      .catch(function(e) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-diagram-3 me-2"></i>查询 NS 值';
        result.innerHTML = '<div class="alert alert-danger">第 ' + (idx+1) + ' 批请求失败：' + e.message + '</div>';
      });
  }

  runBatch(0);
}

function renderNsGroups(total, btn, result, stats, copyBtn) {
  var groups = Object.values(_nsGroupData);
  // 按域名数量降序排列
  groups.sort(function(a, b) { return b.domains.length - a.domains.length; });

  if (!groups.length && !_nsNotFound.length) {
    result.innerHTML = '<div class="text-muted text-center py-4">无结果</div>';
    return;
  }

  var html = '';

  groups.forEach(function(g, gi) {
    var nsKey = g.ns.join('|');
    var nsHtml = g.ns.map(function(ns) {
      return '<div class="d-flex align-items-center gap-2 mb-1">'
           + '<i class="bi bi-arrow-right-circle text-primary small"></i>'
           + '<code class="bg-light px-2 py-1 rounded flex-grow-1 user-select-all">' + ns + '</code>'
           + '<button class="btn btn-xs btn-outline-secondary flex-shrink-0" onclick="copyText(\'' + ns + '\',this)"><i class="bi bi-clipboard"></i></button>'
           + '</div>';
    }).join('');

    var domsHtml = g.domains.map(function(d) {
      return '<span class="badge bg-secondary-subtle text-secondary border font-monospace fw-normal">' + d + '</span>';
    }).join(' ');

    html += '<div class="mb-3 p-3 rounded-3 border bg-white">'
         + '<div class="d-flex align-items-center gap-2 mb-2">'
         + '<span class="badge bg-primary rounded-pill">' + g.domains.length + ' 个域名</span>'
         + '<button class="btn btn-xs btn-outline-secondary ms-auto" onclick="copyDomainsInGroup(' + gi + ')"><i class="bi bi-clipboard me-1"></i>复制域名</button>'
         + '<button class="btn btn-xs btn-outline-primary" onclick="copyNsInGroup(' + gi + ')"><i class="bi bi-clipboard me-1"></i>复制 NS</button>'
         + '</div>'
         + '<div class="mb-2">' + nsHtml + '</div>'
         + '<div class="d-flex flex-wrap gap-1 pt-2 border-top">' + domsHtml + '</div>'
         + '</div>';
  });

  if (_nsNoRecords.length) {
    var noRecHtml = _nsNoRecords.map(function(d) {
      return '<span class="badge bg-warning-subtle text-warning border font-monospace fw-normal">' + d + '</span>';
    }).join(' ');
    html += '<div class="mb-2 p-3 rounded-3 border border-warning-subtle bg-warning-subtle">'
         + '<div class="d-flex align-items-center gap-2 mb-2">'
         + '<i class="bi bi-exclamation-triangle text-warning"></i>'
         + '<span class="badge bg-warning text-dark">' + _nsNoRecords.length + ' 个域名在账号中但无 NS 记录</span>'
         + '<small class="text-muted">（域名刚添加可能尚未分配 NS，稍后重试）</small>'
         + '</div>'
         + '<div class="d-flex flex-wrap gap-1">' + noRecHtml + '</div>'
         + '</div>';
  }

  if (_nsNotFound.length) {
    var notFoundHtml = _nsNotFound.map(function(d) {
      return '<span class="badge bg-danger-subtle text-danger border font-monospace fw-normal">' + d + '</span>';
    }).join(' ');
    html += '<div class="mb-2 p-3 rounded-3 border border-danger-subtle bg-danger-subtle">'
         + '<div class="d-flex align-items-center gap-2 mb-2">'
         + '<i class="bi bi-x-circle text-danger"></i>'
         + '<span class="badge bg-danger">' + _nsNotFound.length + ' 个不在当前账号中</span>'
         + '</div>'
         + '<div class="d-flex flex-wrap gap-1">' + notFoundHtml + '</div>'
         + '</div>';
  }

  result.innerHTML = html;
  var foundCount = groups.reduce(function(s, g) { return s + g.domains.length; }, 0);
  var parts = [groups.length + ' 组 NS，共 ' + foundCount + ' 个域名'];
  if (_nsNoRecords.length) parts.push(_nsNoRecords.length + ' 个无NS记录');
  if (_nsNotFound.length)  parts.push(_nsNotFound.length  + ' 个不在账号中');
  stats.textContent = parts.join('，');
  if (_nsAllData.length > 0) copyBtn.classList.remove('d-none');
}

// 供渲染时调用的分组数据访问
var _nsGroupsArr = [];
function copyDomainsInGroup(gi) {
  var groups = Object.values(_nsGroupData).sort(function(a, b) { return b.domains.length - a.domains.length; });
  if (!groups[gi]) return;
  var text = groups[gi].domains.join('\n');
  _doCopy(text, groups[gi].domains.length + ' 个域名');
}
function copyNsInGroup(gi) {
  var groups = Object.values(_nsGroupData).sort(function(a, b) { return b.domains.length - a.domains.length; });
  if (!groups[gi]) return;
  var text = groups[gi].ns.join('\n');
  _doCopy(text, groups[gi].ns.length + ' 个 NS');
}

function copyNsResult() {
  _doCopy(_nsAllData.join('\n'), _nsAllData.length + ' 个 NS 服务器');
}

// ── 检查 NS 接入状态 ──────────────────────────────────────────────
async function submitNsStateCheck() {
  var accountId = getAccountId();
  if (!accountId) { alert('请先选择账号'); return; }
  var raw = document.getElementById('nsDomains').value.trim();
  if (!raw) { alert('请输入域名列表'); return; }
  var domains = raw.split('\n').map(function(d){ return d.trim(); }).filter(Boolean);
  if (!domains.length) return;

  var btn = document.getElementById('btnNsStateCheck');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>检查中…';

  var card   = document.getElementById('nsStateCard');
  var listEl = document.getElementById('nsStateResult');
  var statsEl= document.getElementById('nsStateStats');
  card.classList.remove('d-none');
  listEl.innerHTML = '<div class="text-center text-muted py-3 small"><span class="spinner-border spinner-border-sm me-1"></span>正在并发查询…</div>';

  var allResults = [];
  var expectedNs = [];
  var BATCH = 50;
  for (var i = 0; i < domains.length; i += BATCH) {
    var chunk = domains.slice(i, i + BATCH);
    try {
      var res  = await fetch('api/ns_state_check.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({account_id: accountId, domains: chunk})
      });
      var json = await res.json();
      if (json.ok && json.results) {
        allResults = allResults.concat(json.results);
        if (json.expectedNs && json.expectedNs.length) expectedNs = json.expectedNs;
      }
    } catch(e) {
      chunk.forEach(function(d){ allResults.push({domain:d, nsMatch:false, actualNs:[], status:'网络错误'}); });
    }
    listEl.innerHTML = '<div class="text-center text-muted py-2 small">已查询 '+allResults.length+' / '+domains.length+'…</div>';
  }

  // 统计
  var ok   = allResults.filter(function(r){ return r.nsMatch; });
  var fail = allResults.filter(function(r){ return !r.nsMatch && r.actualNs && r.actualNs.length > 0; });
  var noNs = allResults.filter(function(r){ return !r.nsMatch && (!r.actualNs || !r.actualNs.length); });

  // 渲染
  var html = '';
  if (expectedNs.length) {
    html += '<div class="alert alert-secondary py-2 small mb-2">'
          + '<i class="bi bi-info-circle me-1"></i>DNS-LA NS 服务器：<code>' + expectedNs.join('</code>、<code>') + '</code>'
          + '</div>';
  }

  // 已接入
  if (ok.length) {
    html += '<div class="mb-2"><div class="fw-semibold small text-success mb-1"><i class="bi bi-check-circle-fill me-1"></i>已接入 (' + ok.length + ')</div>';
    html += '<div class="d-flex flex-wrap gap-1">';
    ok.forEach(function(r){
      html += '<span class="badge bg-success-subtle text-success border font-monospace fw-normal">'+r.domain+'</span>';
    });
    html += '</div></div>';
  }

  // 未接入（有 NS 但不是 DNS-LA 的）
  if (fail.length) {
    html += '<div class="mb-2"><div class="fw-semibold small text-danger mb-1"><i class="bi bi-x-circle me-1"></i>未接入（NS 未改或改错）(' + fail.length + ')</div>';
    html += '<div class="list-group list-group-flush small">';
    fail.forEach(function(r){
      html += '<div class="list-group-item px-1 py-1 d-flex align-items-center gap-2">'
            + '<span class="font-monospace flex-grow-1">'+r.domain+'</span>'
            + '<small class="text-muted">当前NS: '+r.actualNs.join(', ')+'</small>'
            + '</div>';
    });
    html += '</div></div>';
  }

  // NS 查询失败（域名可能不存在或超时）
  if (noNs.length) {
    html += '<div class="mb-2"><div class="fw-semibold small text-secondary mb-1"><i class="bi bi-question-circle me-1"></i>NS 查询无结果 (' + noNs.length + ')</div>';
    html += '<div class="d-flex flex-wrap gap-1">';
    noNs.forEach(function(r){
      html += '<span class="badge bg-secondary-subtle text-secondary border font-monospace fw-normal">'+r.domain+'</span>';
    });
    html += '</div></div>';
  }

  listEl.innerHTML = html || '<div class="text-muted text-center py-3">无结果</div>';

  var parts = [];
  if (ok.length)   parts.push('<span class="text-success fw-semibold">'+ok.length+' 已接入</span>');
  if (fail.length)  parts.push('<span class="text-danger">'+fail.length+' 未接入</span>');
  if (noNs.length)  parts.push('<span class="text-muted">'+noNs.length+' NS无结果</span>');
  statsEl.innerHTML = parts.join(' · ');

  btn.disabled = false;
  btn.innerHTML = '<i class="bi bi-shield-check me-2"></i>检查 NS 接入状态';
}

function _doCopy(text, label) {
  try {
    navigator.clipboard.writeText(text).then(function() { alert('已复制 ' + label); });
  } catch(e) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position='fixed'; ta.style.opacity='0';
    document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
    alert('已复制 ' + label);
  }
}

function copyText(text, btn) {
  try {
    navigator.clipboard.writeText(text).then(function() {
      btn.innerHTML = '<i class="bi bi-check2"></i>';
      setTimeout(function() { btn.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 1500);
    });
  } catch(e) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position='fixed'; ta.style.opacity='0';
    document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
    btn.innerHTML = '<i class="bi bi-check2"></i>';
    setTimeout(function() { btn.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 1500);
  }
}

function copyQueryResult() {
  var text = _queryAllData.join('\n');
  try {
    navigator.clipboard.writeText(text).then(function() { alert('已复制 ' + _queryAllData.length + ' 条记录值'); });
  } catch(e) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    alert('已复制 ' + _queryAllData.length + ' 条记录值');
  }
}

// ── 找回域名 ─────────────────────────────────────────────────────
var _retrieveLines = [];

async function submitRetrieve() {
  var accountId = getAccountId();
  if (!accountId) { alert('请先选择账号'); return; }

  var raw = document.getElementById('retrieveDomains').value.trim();
  if (!raw) { alert('请输入需要找回的域名'); return; }

  var domains = raw.split('\n').map(function(d){ return d.trim(); }).filter(Boolean);
  if (!domains.length) { alert('没有有效域名'); return; }

  var btn = document.getElementById('btnRetrieve');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>处理中…';

  document.getElementById('retrieveResult').value = '请稍候，正在创建找回任务…';
  document.getElementById('retrieveStats').textContent = '';
  document.getElementById('btnCopyRetrieve').classList.add('d-none');
  document.getElementById('retrieveFailList').classList.add('d-none');

  _retrieveLines = [];
  var failList = [];
  var BATCH = 20;
  var total = domains.length;
  var done = 0;

  for (var i = 0; i < domains.length; i += BATCH) {
    var chunk = domains.slice(i, i + BATCH);
    try {
      var res = await fetch('api/domain_retrieve.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({account_id: accountId, domains: chunk})
      });
      var json = await res.json();
      if (json.ok && json.results) {
        json.results.forEach(function(r) {
          if (r.ok) {
            // format: 域名|主机记录|记录类型|记录值
            _retrieveLines.push(r.domain + '|' + r.host + '|TXT|' + r.data);
          } else {
            failList.push(r.domain + ' (' + (r.msg || '失败') + ')');
          }
        });
      } else {
        chunk.forEach(function(d){ failList.push(d + ' (' + (json.msg || '接口错误') + ')'); });
      }
    } catch(e) {
      chunk.forEach(function(d){ failList.push(d + ' (网络错误)'); });
    }
    done += chunk.length;
    document.getElementById('retrieveResult').value =
      '已处理 ' + done + ' / ' + total + '，成功 ' + _retrieveLines.length + ' 个…\n' +
      _retrieveLines.join('\n');
  }

  // Final output
  document.getElementById('retrieveResult').value = _retrieveLines.join('\n');
  document.getElementById('retrieveStats').textContent =
    '成功 ' + _retrieveLines.length + ' / ' + total;
  if (_retrieveLines.length > 0) {
    document.getElementById('btnCopyRetrieve').classList.remove('d-none');
  }
  if (failList.length > 0) {
    document.getElementById('retrieveFailList').classList.remove('d-none');
    document.getElementById('retrieveFailContent').value = failList.join('\n');
  }

  btn.disabled = false;
  btn.innerHTML = '<i class="bi bi-arrow-repeat me-2"></i>生成找回任务';
}

function copyRetrieveResult() {
  var text = _retrieveLines.join('\n');
  _doCopy(text, _retrieveLines.length + ' 条 TXT 记录');
}

// 直接通过 DNS 查询 TXT，不依赖 DNS-LA 排队
async function submitRetrieveDnsCheck() {
  var accountId = getAccountId();
  if (!accountId) { alert('请先选择账号'); return; }
  var raw = document.getElementById('retrieveDomains').value.trim();
  if (!raw) { alert('请输入域名列表'); return; }
  var domains = raw.split('\n').map(function(d){ return d.trim(); }).filter(Boolean);
  if (!domains.length) return;

  var btn = document.getElementById('btnRetrieveDns');
  btn.disabled = true;
  var origHtml = btn.innerHTML;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>查询中…';

  var card   = document.getElementById('retrieveStatusCard');
  var listEl = document.getElementById('retrieveStatusList');
  var statsEl= document.getElementById('retrieveStatusStats');
  card.classList.remove('d-none');
  listEl.innerHTML = '<div class="text-center text-muted py-3 small"><span class="spinner-border spinner-border-sm me-1"></span>正在通过 DNS 直接验证 TXT 记录，请稍候…</div>';

  var allResults = [];
  var BATCH = 20;
  for (var i = 0; i < domains.length; i += BATCH) {
    var chunk = domains.slice(i, i + BATCH);
    try {
      var res  = await fetch('api/domain_retrieve_dns.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({account_id: accountId, domains: chunk})
      });
      var json = await res.json();
      if (json.ok && json.results) {
        allResults = allResults.concat(json.results);
      }
    } catch(e) {
      chunk.forEach(function(d){ allResults.push({domain:d, txt_match:false, dnsla_state:-1, reason:'网络错误'}); });
    }
    listEl.innerHTML = '<div class="text-center text-muted py-2 small">已查询 ' + allResults.length + ' / ' + domains.length + '…</div>';
  }

  // 渲染
  var ok = allResults.filter(function(r){ return r.txt_match && r.dnsla_state === 1; });
  var dnsOk = allResults.filter(function(r){ return r.txt_match && r.dnsla_state !== 1; });
  var dnsNo = allResults.filter(function(r){ return !r.txt_match && r.dnsla_state !== 1; });
  var done  = allResults.filter(function(r){ return r.dnsla_state === 1; });

  var html = '';

  if (done.length) {
    html += '<div class="alert alert-success py-2 small mb-2">'
          + '<i class="bi bi-check-circle-fill me-1"></i><strong>' + done.length + ' 个域名已成功找回！</strong>'
          + '</div>';
  }
  if (dnsOk.length) {
    html += '<div class="alert alert-info py-2 small mb-2">'
          + '<i class="bi bi-lightning me-1"></i><strong>已为 ' + dnsOk.length + ' 个 TXT 已传播的域名触发立即验证</strong>'
          + ' — 结果通常在几秒内更新，可再次点击刷新。'
          + '</div>';
  }
  if (dnsNo.length) {
    html += '<div class="alert alert-danger py-2 small mb-2">'
          + '<i class="bi bi-exclamation-triangle me-1"></i><strong>' + dnsNo.length + ' 个域名 TXT 未找到，需在注册商添加</strong>'
          + ' — 添加后点击「重新触发验证」。'
          + '</div>';
  }

  html += '<div class="list-group list-group-flush small">';
  allResults.forEach(function(r) {
    var icon, badge, cls;
    if (r.dnsla_state === 1) {
      icon='bi-check-circle-fill'; cls='success'; badge='已找回 ✓';
    } else if (r.txt_match) {
      icon='bi-clock-history'; cls='info'; badge='TXT已传播，已触发验证';
    } else {
      icon='bi-x-circle'; cls='danger'; badge='TXT未找到，需添加';
    }
    var hostInfo = r.host && r.host !== '@' ? '<small class="text-muted d-block">主机: <code>' + r.host + '.' + r.domain + '</code> TXT</small>' : '';
    html += '<div class="list-group-item px-1 py-1">'
          + '<div class="d-flex align-items-center gap-2">'
          + '<i class="bi ' + icon + ' text-' + cls + ' flex-shrink-0"></i>'
          + '<span class="font-monospace flex-grow-1">' + r.domain + '</span>'
          + '<span class="badge bg-' + cls + '-subtle text-' + cls + ' border flex-shrink-0">' + badge + '</span>'
          + '</div>' + hostInfo
          + '</div>';
  });
  html += '</div>';
  listEl.innerHTML = html;

  var parts = [];
  if (done.length)  parts.push('<span class="text-success fw-semibold">' + done.length + ' 已找回</span>');
  if (dnsOk.length) parts.push('<span class="text-info fw-semibold">' + dnsOk.length + ' TXT已传播</span>');
  if (dnsNo.length) parts.push('<span class="text-danger">' + dnsNo.length + ' TXT未找到</span>');
  statsEl.innerHTML = parts.join(' · ');

  btn.disabled = false;
  btn.innerHTML = origHtml;
}

// 查询 / 重新触发验证状态
async function submitRetrieveCheck(retry) {
  var accountId = getAccountId();
  if (!accountId) { alert('请先选择账号'); return; }

  var raw = document.getElementById('retrieveDomains').value.trim();
  if (!raw) { alert('请输入域名列表'); return; }
  var domains = raw.split('\n').map(function(d){ return d.trim(); }).filter(Boolean);
  if (!domains.length) return;

  var btnId = retry ? 'btnRetrieveRetry' : 'btnRetrieveCheck';
  var btn = document.getElementById(btnId);
  btn.disabled = true;
  var origHtml = btn.innerHTML;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>查询中…';

  var card     = document.getElementById('retrieveStatusCard');
  var listEl   = document.getElementById('retrieveStatusList');
  var statsEl  = document.getElementById('retrieveStatusStats');
  card.classList.remove('d-none');
  listEl.innerHTML = '<div class="text-center text-muted py-3 small"><span class="spinner-border spinner-border-sm me-1"></span>查询中…</div>';

  var allResults = [];
  var BATCH = 20;
  for (var i = 0; i < domains.length; i += BATCH) {
    var chunk = domains.slice(i, i + BATCH);
    try {
      var res  = await fetch('api/domain_retrieve_verify.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({account_id: accountId, domains: chunk, retry: retry})
      });
      var json = await res.json();
      if (json.ok && json.results) {
        allResults = allResults.concat(json.results);
      }
    } catch(e) {
      chunk.forEach(function(d){ allResults.push({domain: d, state: -1, msg: '网络错误'}); });
    }
  }

  // 渲染状态列表
  var stateMap = {
    '-1': {cls:'danger',   icon:'bi-x-circle',              label:'错误'},
    '0':  {cls:'secondary',icon:'bi-hourglass-split',        label:'排队检测中'},
    '1':  {cls:'success',  icon:'bi-check-circle-fill',      label:'已找回 ✓'},
    '2':  {cls:'warning',  icon:'bi-exclamation-triangle',   label:'验证失败'},
  };
  var counts = {'0':0,'1':0,'2':0,'-1':0};
  var now = Math.floor(Date.now() / 1000);

  var html = '';

  // 说明提示（当有待检测任务时）
  var pending = allResults.filter(function(r){ return r.state === 0; });
  if (pending.length) {
    html += '<div class="alert alert-info py-2 small mb-2">'
          + '<i class="bi bi-info-circle me-1"></i>'
          + '<strong>DNS-LA 自动异步检测，无需手动触发。</strong>'
          + ' 添加完 TXT 记录后，DNS-LA 会自动排队检测（通常 <strong>10–60 分钟</strong>内完成）。'
          + ' 请稍等后再点「查询验证状态」刷新。'
          + '</div>';
  }

  html += '<div class="list-group list-group-flush small">';
  allResults.forEach(function(r) {
    var s = String(r.state !== undefined ? r.state : -1);
    var sm = stateMap[s] || {cls:'secondary',icon:'bi-question-circle',label:'未知'};
    counts[s] = (counts[s] || 0) + 1;

    // 创建时间
    var timeHtml = '';
    if (r.createdAt) {
      var ageSec = now - r.createdAt;
      var ageStr = ageSec < 60 ? ageSec + '秒前' :
                   ageSec < 3600 ? Math.floor(ageSec/60) + '分钟前' :
                   Math.floor(ageSec/3600) + '小时前';
      var isChecked = r.updatedAt && r.updatedAt !== r.createdAt;
      timeHtml = '<small class="text-muted ms-1">'
               + '创建 ' + ageStr
               + (isChecked ? ' · 已检测' : ' · 未检测')
               + '</small>';
    }

    var reason = r.reason ? '<small class="text-muted d-block">原因: ' + r.reason + '</small>' : '';
    html += '<div class="list-group-item px-1 py-1">'
          + '<div class="d-flex align-items-center gap-2">'
          + '<i class="bi ' + sm.icon + ' text-' + sm.cls + ' flex-shrink-0"></i>'
          + '<span class="font-monospace flex-grow-1">' + r.domain + '</span>'
          + '<span class="badge bg-' + sm.cls + '-subtle text-' + sm.cls + ' border flex-shrink-0">' + sm.label + '</span>'
          + '</div>'
          + timeHtml + reason
          + '</div>';
  });
  html += '</div>';
  listEl.innerHTML = html;

  var parts = [];
  if (counts['1'])  parts.push('<span class="text-success fw-semibold">' + counts['1'] + ' 已找回</span>');
  if (counts['0'])  parts.push('<span class="text-secondary">' + counts['0'] + ' 排队检测中</span>');
  if (counts['2'])  parts.push('<span class="text-warning fw-semibold">' + counts['2'] + ' 验证失败</span>');
  if (counts['-1']) parts.push('<span class="text-danger">' + counts['-1'] + ' 错误</span>');
  statsEl.innerHTML = parts.join(' · ');

  btn.disabled = false;
  btn.innerHTML = origHtml;
}
</script>

<?php require_once dirname(dirname(__DIR__)) . '/components/footer.php'; ?>
