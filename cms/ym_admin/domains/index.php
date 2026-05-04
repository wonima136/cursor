<?php
$pageTitle = '域名列表';
require_once dirname(__DIR__) . '/components/header.php';
$_sysCfg = getSysFieldConfig(); // 系统字段显隐配置

// ── 批量域名查找 ──────────────────────────────────────────────
if (!isset($_SESSION)) @session_start();

// 清除后台查询结果
if (isset($_GET['sr_clear'])) {
    $qArr = $_GET;
    unset($qArr['sr_clear'], $qArr['sr']);
    header('Location: /domains/?' . http_build_query($qArr));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'domain_list_search') {
    $raw  = trim($_POST['domain_input'] ?? '');
    $list = $raw
        ? array_values(array_unique(array_filter(array_map('trim', explode("\n", $raw)))))
        : [];
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    parse_str($qs, $qArr);
    unset($qArr['page'], $qArr['sr'], $qArr['dl']);

    if (count($list) > 500) {
        // 大列表 → 后台进程查询
        require_once dirname(__DIR__) . '/core/functions.php'; // 已在 header 中引入，保险起见
        $jobId = createJob('domain_search', $list, []);
        launchJob($jobId);
        header('Location: /jobs/progress.php?id=' . $jobId);
        exit;
    } elseif ($list) {
        $_SESSION['domain_list_filter'] = $list;
        $qArr['dl'] = '1';
    } else {
        unset($_SESSION['domain_list_filter']);
    }
    header('Location: /domains/?' . http_build_query($qArr));
    exit;
}
if (isset($_GET['dl_clear'])) {
    unset($_SESSION['domain_list_filter']);
    $qArr = $_GET;
    unset($qArr['dl_clear'], $qArr['dl']);
    header('Location: /domains/?' . http_build_query($qArr));
    exit;
}

// ── 读取查询结果：session(dl) 或 后台job结果(sr) ─────────────
$_domainListActive = false;
$_domainListData   = [];
$_srJobId          = trim($_GET['sr'] ?? '');

if ($_srJobId) {
    // 后台job查询结果
    $srFile = DATA_DIR . '/search_results/sr_' . preg_replace('/[^a-zA-Z0-9_.]/', '', $_srJobId) . '.json';
    if (file_exists($srFile)) {
        $srData = json_decode(file_get_contents($srFile), true);
        if (!empty($srData['domains'])) {
            $_domainListActive = true;
            $_domainListData   = $srData['domains'];
        }
    }
} elseif (!empty($_GET['dl']) && !empty($_SESSION['domain_list_filter'])) {
    $_domainListActive = true;
    $_domainListData   = $_SESSION['domain_list_filter'];
}

$master        = getMasterDB();
$allTags       = db_all($master, "SELECT * FROM tags ORDER BY sort_order, name");
$allRegistrars = db_all($master, "SELECT * FROM registrars ORDER BY name");

// 多选参数读取（status/icp_type/registrar_id 均支持数组）
$filters = [
    'search'       => trim($_GET['search'] ?? ''),
    'status'       => (array)($_GET['status'] ?? []),
    'registrar_id' => (array)($_GET['registrar_id'] ?? []),
    'icp_type'     => (array)($_GET['icp_type'] ?? []),
    'group_name'   => $_GET['group_name'] ?? '',
    'dns'          => $_GET['dns'] ?? '',
    'account_id'   => $_GET['account_id'] ?? '',
    'expire_warn'  => $_GET['expire_warn'] ?? '',
    'cf'           => (array)($_GET['cf'] ?? []),
    'date_field'   => 'expire_date',
    'date_from'    => $_GET['date_from'] ?? '',
    'date_to'      => $_GET['date_to']   ?? '',
    'domain_list'     => $_domainListData,   // 批量域名查找
    'exclude_expired' => !empty($_GET['excl_exp']) ? '1' : '',
    'excl_days'       => max(0, (int)($_GET['excl_days'] ?? 0)),
];
// 兼容旧式单值 string 传参
foreach (['status','registrar_id','icp_type'] as $_fk) {
    if (isset($_GET[$_fk]) && is_string($_GET[$_fk]) && $_GET[$_fk] !== '') {
        $filters[$_fk] = [$_GET[$_fk]];
    }
}
$filters['status']       = array_values(array_filter($filters['status']));
$filters['registrar_id'] = array_values(array_filter($filters['registrar_id']));
$filters['icp_type']     = array_values(array_filter($filters['icp_type']));
$filters['cf']           = array_filter($filters['cf'], function($v){ return $v !== ''; });

$tagFilter = array_values(array_filter(array_map('intval', explode(',', $_GET['tags'] ?? ''))));
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = max(10, min(500, (int)($_GET['per_page'] ?? PER_PAGE)));

$total   = countMasterDomains($filters, $tagFilter);
$pager   = paginate($total, $page, $perPage);
$domains = getMasterDomains($filters, $tagFilter, $pager['offset'], $perPage);

$stats      = getStats();
// 要在列表中显示的自定义字段
$listFields    = db_all($master, "SELECT * FROM custom_fields WHERE show_in_list=1 ORDER BY sort_order, id");
// 全部自定义字段（用于筛选栏）
$allCfFields   = getCustomFields();
// 从数据库取真实的不重复值（用于筛选下拉）
$distinctStatus = array_column(db_all($master, "SELECT DISTINCT status FROM domains WHERE status!='' ORDER BY status"), 'status');
$distinctIcp    = array_column(db_all($master, "SELECT DISTINCT icp_type FROM domains WHERE icp_type!='' ORDER BY icp_type"), 'icp_type');
$distinctReg    = db_all($master, "SELECT id, name FROM registrars ORDER BY name");
// 构建分页 queryString
$_qp = [];
if ($filters['search'])       $_qp['search']       = $filters['search'];
if ($filters['status'])       $_qp['status']        = $filters['status'];
if ($filters['registrar_id']) $_qp['registrar_id']  = $filters['registrar_id'];
if ($filters['icp_type'])     $_qp['icp_type']      = $filters['icp_type'];
if ($filters['group_name'])   $_qp['group_name']    = $filters['group_name'];
if ($filters['dns'])          $_qp['dns']           = $filters['dns'];
if ($filters['account_id'])   $_qp['account_id']    = $filters['account_id'];
if ($filters['expire_warn'])  $_qp['expire_warn']   = $filters['expire_warn'];
if ($filters['cf'])           $_qp['cf']            = $filters['cf'];
if ($tagFilter)               $_qp['tags']          = implode(',', $tagFilter);
if ($filters['date_from'])                    $_qp['date_from']  = $filters['date_from'];
if ($filters['date_to'])                      $_qp['date_to']    = $filters['date_to'];
if ($filters['exclude_expired'])              $_qp['excl_exp']   = '1';
if (($filters['excl_days'] ?? 0) > 0)        $_qp['excl_days']  = $filters['excl_days'];
if ($perPage !== PER_PAGE)                    $_qp['per_page']   = $perPage;
if ($_domainListActive) {
    if ($_srJobId) {
        $_qp['sr'] = $_srJobId;   // 后台job结果模式
    } else {
        $_qp['dl'] = '1';          // session模式
    }
}
$queryStr = http_build_query($_qp);

// ── 构建"当前筛选"标签条 ──────────────────────────────────────
$_sq = function($s) { return "'" . str_replace(["\\","'"], ["\\\\","\\'"], (string)$s) . "'"; };
$_registrarMap = array_column($distinctReg, 'name', 'id');
$_activeBadges = [];
foreach ($filters['status']       as $v)  $_activeBadges[] = ['label'=>'状态',  'val'=>$v,                      'onclick'=>"toggleMultiFilter('status',{$_sq($v)})"];
foreach ($filters['icp_type']     as $v)  $_activeBadges[] = ['label'=>'备案',  'val'=>$v,                      'onclick'=>"toggleMultiFilter('icp_type',{$_sq($v)})"];
foreach ($filters['registrar_id'] as $v)  $_activeBadges[] = ['label'=>'注册商','val'=>$_registrarMap[$v]??$v,  'onclick'=>"toggleMultiFilter('registrar_id',{$_sq((string)$v)})"];
if ($filters['group_name'])   { $v=$filters['group_name'];  $_activeBadges[] = ['label'=>'分组',  'val'=>$v, 'onclick'=>"togglePlainFilter('group_name',{$_sq($v)})"]; }
if ($filters['dns'])          { $v=$filters['dns'];         $_activeBadges[] = ['label'=>'DNS',   'val'=>$v, 'onclick'=>"togglePlainFilter('dns',{$_sq($v)})"]; }
if ($filters['account_id'])   { $v=$filters['account_id']; $_activeBadges[] = ['label'=>'账号',  'val'=>$v, 'onclick'=>"togglePlainFilter('account_id',{$_sq($v)})"]; }
if ($filters['expire_warn'])  {
    $v = $filters['expire_warn'];
    $_ewLabel = $v === 'expired' ? '已过期' : ($v === 'today' ? '今天到期' : (is_numeric($v) ? "{$v}天内到期(不含过期)" : $v));
    $_activeBadges[] = ['label'=>'到期', 'val'=>$_ewLabel, 'onclick'=>"togglePlainFilter('expire_warn',{$_sq($v)})"];
}
if ($filters['date_from'] && $filters['date_to']) {
    $_activeBadges[] = ['label'=>'过期时间', 'val'=>$filters['date_from'].'~'.$filters['date_to'], 'onclick'=>"clearDateFilter()"];
} elseif ($filters['date_from']) {
    $_activeBadges[] = ['label'=>'过期时间', 'val'=>'从'.$filters['date_from'], 'onclick'=>"clearDateFilter()"];
} elseif ($filters['date_to']) {
    $_activeBadges[] = ['label'=>'过期时间', 'val'=>'至'.$filters['date_to'], 'onclick'=>"clearDateFilter()"];
}
foreach ($tagFilter as $tid) {
    $_tname=''; foreach ($allTags as $_t) { if ($_t['id']==$tid){$_tname=$_t['name'];break;} }
    $_activeBadges[] = ['label'=>'标签', 'val'=>$_tname?:$tid, 'onclick'=>"toggleTagFilter($tid)"];
}
foreach ($filters['cf'] as $_cfName => $_cfVal) {
    $_cfLabel=$_cfName; foreach ($allCfFields as $_cf){if($_cf['name']===$_cfName){$_cfLabel=$_cf['label'];break;}}
    $_activeBadges[] = ['label'=>$_cfLabel, 'val'=>$_cfVal, 'onclick'=>"toggleCfFilter({$_sq($_cfName)},{$_sq($_cfVal)})"];
}
if ($_domainListActive) {
    $_activeBadges[] = ['label'=>'域名列表', 'val'=>count($_domainListData).'个', 'onclick'=>"window.location='?'.document.location.search.replace(/[?&]?dl_clear=1/,'')+'&dl_clear=1'"];
}
if ($filters['search']) {
    $v=$filters['search'];
    $_activeBadges[] = ['label'=>'搜索', 'val'=>$v, 'onclick'=>"clearSearch()"];
}
?>

<!-- 批量域名查找面板 -->
<div class="mb-3">
  <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
    <?php
      // ── 排除已过期 ──
      $_exclActive  = !empty($filters['exclude_expired']);
      $_exclQp      = array_merge($_qp, ['page' => null]);
      unset($_exclQp['page']);
      if ($_exclActive) { unset($_exclQp['excl_exp']); } else { $_exclQp['excl_exp'] = '1'; }

      // ── 过滤 N 天内到期 ──
      $_exclDays    = (int)($filters['excl_days'] ?? 0);
      $_exclDaysQp  = $_qp;
      unset($_exclDaysQp['page'], $_exclDaysQp['excl_days']);
    ?>
    <!-- 排除已过期 -->
    <a href="?<?= h(http_build_query($_exclQp)) ?>"
       class="btn btn-sm <?= $_exclActive ? 'btn-danger' : 'btn-outline-danger' ?>"
       title="<?= $_exclActive ? '点击取消排除' : '点击排除已过期域名' ?>">
      <i class="bi bi-clock-history me-1"></i>
      <?= $_exclActive ? '✓ 已排除过期' : '排除已过期' ?>
    </a>

    <!-- 过滤 N 天内到期 -->
    <?php if ($_exclDays > 0): ?>
    <span class="badge bg-danger align-middle py-1 px-2" style="font-size:13px">
      <i class="bi bi-funnel me-1"></i>已过滤<?= $_exclDays ?>天内
    </span>
    <a href="?<?= h(http_build_query($_exclDaysQp)) ?>"
       class="btn btn-sm btn-outline-secondary" title="取消天数过滤">
      <i class="bi bi-x-lg"></i>
    </a>
    <?php else: ?>
    <form method="GET" action="/domains/" class="d-flex align-items-center gap-1" style="margin:0">
      <?php foreach ($_qp as $_k => $_v): ?>
        <?php if (is_array($_v)): foreach ($_v as $_vv): ?>
          <input type="hidden" name="<?= h($_k) ?>[]" value="<?= h($_vv) ?>">
        <?php endforeach; else: ?>
          <input type="hidden" name="<?= h($_k) ?>" value="<?= h($_v) ?>">
        <?php endif; ?>
      <?php endforeach; ?>
      <input type="number" name="excl_days" min="1" max="3650"
             class="form-control form-control-sm" style="width:70px"
             placeholder="天数" title="输入天数，过滤该天数内到期的域名">
      <button type="submit" class="btn btn-sm btn-outline-danger" title="过滤 N 天内到期域名">
        <i class="bi bi-funnel me-1"></i>过滤到期
      </button>
    </form>
    <?php endif; ?>

    <button class="btn btn-sm <?= $_domainListActive ? 'btn-warning' : 'btn-outline-secondary' ?>"
            onclick="toggleDomainSearchPanel()" id="dlToggleBtn">
      <i class="bi bi-search me-1"></i>
      <?php if ($_domainListActive): ?>
        已查找 <?= count($_domainListData) ?> 个域名 · 点击修改
      <?php else: ?>
        输入域名列表查找
      <?php endif; ?>
    </button>
    <?php if ($_domainListActive && $_srJobId): ?>
    <span class="badge bg-info text-dark">后台查询结果</span>
    <a href="?<?= h(http_build_query(array_merge(array_diff_key($_qp, ['sr'=>1,'dl'=>1]), ['sr_clear'=>'1']))) ?>"
       class="btn btn-sm btn-outline-danger" title="清除查询结果">
      <i class="bi bi-x-lg me-1"></i>清除查找
    </a>
    <?php elseif ($_domainListActive): ?>
    <a href="?<?= h(http_build_query(array_merge($_qp, ['dl_clear'=>'1']))) ?>"
       class="btn btn-sm btn-outline-danger" title="清除域名列表查找">
      <i class="bi bi-x-lg me-1"></i>清除查找
    </a>
    <?php endif; ?>
  </div>

  <div id="domainSearchPanel" style="display:<?= $_domainListActive ? 'block' : 'none' ?>">
    <form method="POST" action="/domains/">
      <?php foreach ($_qp as $k => $v): ?>
        <?php if (is_array($v)): foreach ($v as $vv): ?>
          <input type="hidden" name="<?= h($k) ?>[]" value="<?= h($vv) ?>">
        <?php endforeach; else: ?>
          <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
        <?php endif; ?>
      <?php endforeach; ?>
      <input type="hidden" name="_action" value="domain_list_search">
      <div class="card border-0 shadow-sm">
        <div class="card-body py-2 px-3">
          <div class="row g-2 align-items-start">
            <div class="col">
              <textarea name="domain_input" class="form-control form-control-sm font-monospace"
                        rows="5" placeholder="每行输入一个域名，例如：&#10;example.com&#10;test.cn&#10;hello.net"
                        id="domainSearchInput"><?= h(implode("\n", $_domainListData)) ?></textarea>
              <div class="text-muted small mt-1" id="dlLineCount">
                <?= count($_domainListData) ?> 行
              </div>
            </div>
            <div class="col-auto d-flex flex-column gap-2">
              <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-search me-1"></i>查找
              </button>
              <button type="button" class="btn btn-outline-secondary btn-sm"
                      onclick="document.getElementById('domainSearchInput').value='';document.getElementById('dlLineCount').textContent='0 行'">
                清空
              </button>
            </div>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- 统计卡片 -->
<div class="row g-3 mb-3">
  <?php
  $cards = [
    ['num'=>$stats['total'],   'label'=>'域名总数',    'color'=>'primary', 'icon'=>'bi-globe2'],
    ['num'=>$stats['expired'], 'label'=>'已过期',      'color'=>'danger',  'icon'=>'bi-x-circle'],
    ['num'=>$stats['soon7'],   'label'=>'7天内到期',   'color'=>'danger',  'icon'=>'bi-alarm'],
    ['num'=>$stats['soon30'],  'label'=>'30天内到期',  'color'=>'warning', 'icon'=>'bi-clock-history'],
  ];
  foreach ($cards as $c): ?>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="d-flex align-items-center gap-2 mb-1">
        <i class="bi <?= $c['icon'] ?> text-<?= $c['color'] ?>"></i>
        <span class="stat-num text-<?= $c['color'] ?>"><?= $c['num'] ?></span>
      </div>
      <div class="stat-label"><?= $c['label'] ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- 筛选栏 -->
<?php
// 辅助：生成多选下拉按钮
function _multiDropdown(string $name, string $placeholder, array $options, array $selected): string {
    $cnt   = count($selected);
    $label = $cnt ? "已选 {$cnt} 个" : $placeholder;
    $uid   = 'dd_' . $name;
    $html  = '<div class="dropdown">';
    $html .= '<button class="btn btn-outline-secondary btn-sm dropdown-toggle w-100 text-start" type="button"
               data-bs-toggle="dropdown" data-bs-auto-close="outside">' . h($label) . '</button>';
    $html .= '<div class="dropdown-menu p-2" style="min-width:160px;max-height:260px;overflow-y:auto">';
    foreach ($options as $val => $text) {
        $chk  = in_array((string)$val, array_map('strval', $selected)) ? 'checked' : '';
        $html .= '<div class="form-check"><input class="form-check-input" type="checkbox"
                    name="' . h($name) . '[]" value="' . h($val) . '" ' . $chk . '>
                  <label class="form-check-label small">' . h($text) . '</label></div>';
    }
    $html .= '</div></div>';
    return $html;
}
// 筛选选项直接用数据库实际值
$statusOpts    = array_combine($distinctStatus, $distinctStatus);
$icpOpts       = array_combine($distinctIcp, $distinctIcp);
$registrarOpts = array_column($distinctReg, 'name', 'id');
?>

<?php if ($_activeBadges): ?>
<!-- 当前筛选条件显示栏 -->
<div class="d-flex align-items-center flex-wrap gap-2 mb-3 px-3 py-2 rounded"
     style="background:#eef2ff;border:1px solid #c5cfff">
  <span class="text-primary small fw-semibold text-nowrap">
    <i class="bi bi-funnel-fill me-1"></i>当前筛选：
  </span>
  <?php foreach ($_activeBadges as $_b): ?>
  <span class="badge d-inline-flex align-items-center gap-1 px-2 py-1 fw-normal"
        style="background:#3b5bdb;font-size:12.5px;border-radius:20px">
    <span style="opacity:.75;font-size:11px"><?= h($_b['label']) ?>：</span>
    <span><?= h($_b['val']) ?></span>
    <span onclick="<?= h($_b['onclick']) ?>"
          style="cursor:pointer;margin-left:1px;opacity:.8;font-size:10px"
          title="移除此筛选">✕</span>
  </span>
  <?php endforeach; ?>
  <a href="/domains/" class="btn btn-sm py-0 px-2 ms-1"
     style="border:1px solid #3b5bdb;color:#3b5bdb;border-radius:20px;font-size:12px">
    <i class="bi bi-x-circle me-1"></i>清除全部
  </a>
</div>
<?php endif; ?>

<?php
// ── 汇总统计缓存读取 ──────────────────────────────────────────
$_aggCacheDir  = dirname(__DIR__) . '/data/agg';
$_aggCacheKey  = md5(serialize(['f' => $filters, 't' => $tagFilter]));
$_aggCacheFile = $_aggCacheDir . '/agg_' . $_aggCacheKey . '.json';
$_aggTTL       = 300; // 5 分钟
$_aggCached    = null;
if (file_exists($_aggCacheFile) && (time() - filemtime($_aggCacheFile)) <= $_aggTTL) {
    $_aggCached = @file_get_contents($_aggCacheFile);
}
?>
<!-- 汇总标签区块 -->
<div class="card shadow-sm border-0 mb-3" id="agg-card">
  <div class="card-body py-2 px-3">
    <div class="d-flex align-items-center justify-content-between mb-1">
      <small class="text-muted" id="agg-time-text"></small>
      <div class="d-flex align-items-center gap-2">
        <span id="agg-spin" class="text-muted small d-none"><i class="bi bi-hourglass-split"></i> <span id="agg-spin-text">统计中…</span></span>
        <button class="btn btn-sm btn-outline-secondary" onclick="loadAggStats(true)" id="agg-refresh-btn"
                title="无筛选时：重新计算全局统计并预热所有筛选缓存&#10;有筛选时：计算当前筛选条件的统计">
          <i class="bi bi-arrow-clockwise"></i> 更新统计
        </button>
      </div>
    </div>
    <div id="agg-container">
      <div class="text-center text-muted small py-2" id="agg-placeholder">
        <i class="bi bi-hourglass-split"></i> 正在自动统计中，请稍候…
      </div>
    </div>
  </div>
</div>
<style>
.ring-active { outline: 2px solid #0d6efd; outline-offset: 1px; }
/* 聚合汇总 pill 样式 */
.agg-pill {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 2px 8px; border-radius: 4px; font-size: 12.5px; cursor: pointer;
  border: 1px solid #dee2e6; background: #f8f9fa; color: #343a40;
  line-height: 1.6;
}
.agg-pill:hover { background: #e9ecef; border-color: #adb5bd; }
.agg-pill-active { background: #0d6efd !important; color: #fff !important; border-color: #0d6efd !important; }
.agg-pill-active:hover { background: #0b5ed7 !important; }
.agg-pill-empty { color: #6c757d; border-color: #dee2e6; cursor: pointer; }
.agg-pill-empty:hover { background: #e9ecef; border-color: #adb5bd; }
.agg-pill-muted { color: #198754; border-color: #d1e7dd; cursor: default; }
.agg-pill-tag { border-radius: 20px !important; color: #fff; border: none !important; }
.agg-cnt { font-size: 11px; opacity: .75; white-space: nowrap; }
</style>
<script>
// ── 当前筛选参数（PHP 传入，供 JS 判断激活状态）──────────────────
var _activeFilters = <?= json_encode([
    'status'       => $filters['status'],
    'icp_type'     => $filters['icp_type'],
    'registrar_id' => array_map('strval', $filters['registrar_id']),
    'group_name'   => $filters['group_name'],
    'dns'          => $filters['dns'],
    'account_id'   => $filters['account_id'],
]) ?>;
var _activeTags = <?= json_encode(array_map('strval', $tagFilter)) ?>;
var _activeCf   = <?= json_encode((object)$filters['cf']) ?>;
// 当前页面的筛选参数串（用于 API 请求）
var _aggQs = <?= json_encode($queryStr) ?>;
// 缓存数据（若已有，PHP 直接传入）
var _aggCached = <?= $_aggCached ? $_aggCached : 'null' ?>;

function toggleDomainSearchPanel() {
  var p = document.getElementById('domainSearchPanel');
  p.style.display = p.style.display === 'none' ? 'block' : 'none';
}
// 实时统计行数
document.addEventListener('DOMContentLoaded', function() {
  var ta = document.getElementById('domainSearchInput');
  var lc = document.getElementById('dlLineCount');
  if (ta && lc) {
    ta.addEventListener('input', function() {
      var lines = this.value.split('\n').filter(function(l){ return l.trim(); });
      lc.textContent = lines.length + ' 行';
    });
  }
});
function _esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
// 生成单引号 JS 字符串，安全嵌入 onclick="..." 双引号 HTML 属性
function _jsq(s) {
  return "'" + String(s).replace(/\\/g,'\\\\').replace(/'/g,"\\'") + "'";
}

function renderAggGroups(groups) {
  var html = '';
  var keys = Object.keys(groups);
  if (!keys.length) {
    html = '<div class="text-muted small py-1">暂无统计数据</div>';
  }
  keys.forEach(function(k) {
    var g = groups[k];
    if (!g.rows || !g.rows.length) return;
    html += '<div class="d-flex align-items-start gap-2 py-1 border-bottom border-light">';
    html += '<span class="text-muted small fw-semibold text-nowrap pt-1" style="min-width:70px">' + _esc(g.label) + '</span>';
    html += '<div class="d-flex flex-wrap gap-1">';
    g.rows.forEach(function(row) {
      var cnt = row.cnt | 0;
      // 有内容行（textarea 专用）
      if (row._has) {
        html += '<span class="agg-pill agg-pill-muted">'
              + '【有内容】 <span class="agg-cnt">数量: ' + cnt + '</span></span>';
        return;
      }
      // 空值行（可点击过滤）
      if (row._empty) {
        var emptyOnclick = '', emptyActive = false;
        if (g.type === 'multi') {
          emptyActive  = (_activeFilters[g.field] || []).indexOf('__EMPTY__') >= 0;
          emptyOnclick = 'toggleMultiFilter(' + _jsq(g.field) + ',\'__EMPTY__\')';
        } else if (g.type === 'plain') {
          emptyActive  = String(_activeFilters[g.field] || '') === '__EMPTY__';
          emptyOnclick = 'togglePlainFilter(' + _jsq(g.field) + ',\'__EMPTY__\')';
        } else if (g.type === 'cf') {
          emptyActive  = String((_activeCf || {})[g.cf_name] || '') === '__EMPTY__';
          emptyOnclick = 'toggleCfFilter(' + _jsq(g.cf_name) + ',\'__EMPTY__\')';
        }
        html += '<span onclick="' + emptyOnclick + '" style="cursor:pointer"'
              + ' class="agg-pill agg-pill-empty' + (emptyActive ? ' agg-pill-active' : '') + '">'
              + '【空】 <span class="agg-cnt">数量: ' + cnt + '</span>'
              + (emptyActive ? ' ✕' : '')
              + '</span>';
        return;
      }
      var val   = row.val !== undefined ? row.val : (row.name || '');
      var label = (row.label && row.label !== '') ? row.label : val;
      var active = false, onclick = '';
      if (g.type === 'multi') {
        var fval = String(row.val);
        active = (_activeFilters[g.field] || []).indexOf(fval) >= 0;
        onclick = 'toggleMultiFilter(' + _jsq(g.field) + ',' + _jsq(fval) + ')';
      } else if (g.type === 'plain') {
        var fval = String(row.val);
        active = String(_activeFilters[g.field] || '') === fval;
        onclick = 'togglePlainFilter(' + _jsq(g.field) + ',' + _jsq(fval) + ')';
      } else if (g.type === 'tag') {
        active = _activeTags.indexOf(String(row.id)) >= 0;
        onclick = 'toggleTagFilter(' + row.id + ')';
      } else if (g.type === 'cf') {
        var fval = String(row.val);
        active = String((_activeCf || {})[g.cf_name] || '') === fval;
        onclick = 'toggleCfFilter(' + _jsq(g.cf_name) + ',' + _jsq(fval) + ')';
      }
      if (g.type === 'tag') {
        html += '<span onclick="' + onclick + '" style="background:' + _esc(row.color) + ';cursor:pointer;opacity:' + (active?'1':'0.72') + '"'
              + ' class="agg-pill agg-pill-tag d-inline-flex align-items-center gap-1' + (active?' ring-active':'') + '">'
              + '【' + _esc(val) + '】'
              + ' <span class="agg-cnt">数量: ' + cnt + '</span>'
              + (active ? ' <span style="font-size:10px">✕</span>' : '')
              + '</span>';
      } else {
        html += '<span onclick="' + onclick + '" style="cursor:pointer"'
              + ' class="agg-pill' + (active?' agg-pill-active':'') + '">'
              + '【' + _esc(label) + '】'
              + ' <span class="agg-cnt">数量: ' + cnt + '</span>'
              + (active ? ' ✕' : '')
              + '</span>';
      }
    });
    if (g.truncated) {
      html += '<span class="badge rounded-pill bg-light text-muted border border-secondary px-2 py-1" style="font-size:11px">仅显示前 Top 20…</span>';
    }
    html += '</div></div>';
  });
  return html;
}

function showAgg(data) {
  document.getElementById('agg-placeholder').style.display = 'none';
  document.getElementById('agg-container').innerHTML = renderAggGroups(data.groups || {});
  if (data.generated_at) {
    var t = '统计时间: ' + data.generated_at;
    if (data.prewarmed > 0) {
      t += ' · 已预热 ' + data.prewarmed + ' 个筛选项缓存，点击任意标签秒显示';
    }
    if (data.is_global) {
      document.getElementById('agg-refresh-btn').title = '重新计算全局统计并预热所有筛选缓存';
    }
    document.getElementById('agg-time-text').textContent = t;
  }
}

function loadAggStats(forceRefresh) {
  var btn  = document.getElementById('agg-refresh-btn');
  var spin = document.getElementById('agg-spin');
  var spinText = document.getElementById('agg-spin-text');
  btn.disabled = true;
  spin.classList.remove('d-none');
  // 根据是否有筛选条件显示不同提示
  var hasFilters = _activeTags.length > 0 || Object.keys(_activeCf).length > 0 ||
    Object.keys(_activeFilters).some(function(k) {
      var v = _activeFilters[k]; return Array.isArray(v) ? v.length > 0 : (v !== '');
    });
  if (spinText) spinText.textContent = hasFilters ? '统计中…' : '统计并预热缓存，请稍候…';
  var url = '/domains/api/agg_stats.php' + (_aggQs ? '?' + _aggQs : '');
  fetch(url, { method: forceRefresh ? 'POST' : 'GET' })
    .then(function(r){ return r.json(); })
    .then(function(data) {
      if (data && data.error) {
        document.getElementById('agg-placeholder').innerHTML =
          '<span class="text-danger small"><i class="bi bi-exclamation-circle"></i> 统计出错: ' +
          data.error.substring(0, 120) + '</span>' +
          ' &nbsp;<button class="btn btn-sm btn-outline-danger ms-1" onclick="loadAggStats(true)">重试</button>';
        document.getElementById('agg-placeholder').style.display = '';
      } else if (data && data.groups) {
        // 有 groups 数据就显示（无论 cached 是 true 还是 false）
        showAgg(data);
        if (data.cached) _aggCached = data; // 只在缓存有效时存本地
      } else if (!forceRefresh) {
        document.getElementById('agg-placeholder').style.display = '';
      }
    })
    .catch(function(e){
      document.getElementById('agg-placeholder').innerHTML =
        '<span class="text-danger small"><i class="bi bi-exclamation-circle"></i> 请求失败，请检查网络或刷新页面</span>';
      document.getElementById('agg-placeholder').style.display = '';
    })
    .finally(function(){
      btn.disabled = false;
      spin.classList.add('d-none');
    });
}

// 页面加载：有新鲜缓存直接渲染，同时 5 分钟后自动刷新；无缓存时立即自动触发统计
document.addEventListener('DOMContentLoaded', function() {
  if (_aggCached && _aggCached.cached !== false) {
    // 有新鲜缓存 → 直接渲染，5 分钟后自动后台刷新
    showAgg(_aggCached);
    setTimeout(function() { loadAggStats(true); }, 5 * 60 * 1000);
  } else {
    // 无缓存或缓存已过期 → 自动立即计算，无需手动点击
    loadAggStats(true);
  }
});
</script>

<!-- 批量操作栏 -->
<div id="batch-bar" class="mb-3 gap-3 align-items-center">
  <span>已选 <strong id="batch-count">0</strong> 个域名</span>
  <div class="d-flex gap-2">
    <button onclick="doBatchAction('normal')" class="btn btn-sm btn-light">设为正常</button>
    <button onclick="doBatchAction('pause')"  class="btn btn-sm btn-warning">暂停解析</button>
    <button onclick="doBatchAction('delete')" class="btn btn-sm btn-danger">删除</button>
  </div>
</div>

<!-- 危险区操作 -->
<div class="d-flex justify-content-end mb-2">
  <button onclick="clearAllDomains(<?= $stats['total'] ?>)"
          class="btn btn-sm btn-outline-danger">
    <i class="bi bi-trash3 me-1"></i>清空全部域名
  </button>
</div>

<!-- 域名表格 -->
<?php
// 分页 HTML（上下共用）
$_pagerHtml = '';
if ($pager['total_pages'] > 1) {
    $qs = $queryStr;
    $_pagerHtml .= '<div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">';
    $_pagerHtml .= '<span class="text-muted small">第 ' . $pager['page'] . '/' . $pager['total_pages'] . ' 页，共 ' . $total . ' 条</span>';
    $_pagerHtml .= '<nav><ul class="pagination pagination-sm mb-0">';
    // 首页
    if ($pager['page'] > 1) {
        $_pagerHtml .= '<li class="page-item"><a class="page-link" href="?' . $qs . '&page=1">«</a></li>';
    }
    for ($i = max(1, $pager['page']-3); $i <= min($pager['total_pages'], $pager['page']+3); $i++) {
        $active = $i === $pager['page'] ? ' active' : '';
        $_pagerHtml .= '<li class="page-item' . $active . '"><a class="page-link" href="?' . $qs . '&page=' . $i . '">' . $i . '</a></li>';
    }
    // 末页
    if ($pager['page'] < $pager['total_pages']) {
        $_pagerHtml .= '<li class="page-item"><a class="page-link" href="?' . $qs . '&page=' . $pager['total_pages'] . '">»</a></li>';
    }
    $_pagerHtml .= '</ul></nav></div>';
}
?>
<div class="table-card">
  <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
    <div class="d-flex align-items-center gap-3">
      <span class="text-muted small">
        共 <strong><?= $total ?></strong> 个域名
      </span>
      <div class="d-flex align-items-center gap-1">
        <span class="text-muted small text-nowrap">每页</span>
        <input type="number" id="perPageInput" class="form-control form-control-sm text-center"
               style="width:70px" min="10" max="500" value="<?= $perPage ?>"
               onkeydown="if(event.key==='Enter'){setPerPage(this.value)}"
               title="输入每页显示条数后按回车">
        <span class="text-muted small text-nowrap">条</span>
        <button class="btn btn-sm btn-outline-secondary" onclick="setPerPage(document.getElementById('perPageInput').value)" title="应用每页条数">✓</button>
      </div>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-sm btn-outline-secondary" onclick="copyDomains(this)" title="复制当前筛选结果的域名列表">
        <i class="bi bi-clipboard"></i> 复制域名
      </button>
      <a href="<?= '/domains/api/export.php?action=csv&' . $queryStr ?>" class="btn btn-sm btn-outline-success" title="导出为CSV">
        <i class="bi bi-download"></i> 导出CSV
      </a>
    </div>
  </div>
  <?= $_pagerHtml ?>
  <div class="table-responsive">
    <table class="table table-hover">
      <thead>
        <tr>
          <th width="36"><input type="checkbox" id="selectAll" class="form-check-input"></th>
          <th>域名</th>
          <?php foreach ($_sysCfg as $_sf): if (!$_sf['show_in_list']) continue; ?>
          <th><?= h($_sf['label']) ?></th>
          <?php endforeach; ?>
          <?php foreach ($listFields as $lf): ?>
          <th class="small"><?= h($lf['label']) ?></th>
          <?php endforeach; ?>
          <th width="90">操作</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$domains): ?>
        <?php $_sysCols = count(array_filter(array_column($_sysCfg, 'show_in_list'))); ?>
        <tr><td colspan="<?= 3 + $_sysCols + count($listFields) ?>" class="text-center text-muted py-5">暂无数据</td></tr>
        <?php endif; ?>
        <?php foreach ($domains as $d): ?>
        <?php $expClass = getDomainExpireClass($d['expire_date'] ?? ''); ?>
        <tr>
          <td><input type="checkbox" class="form-check-input row-check" value="<?= $d['id'] ?>"></td>
          <td>
            <a href="/domains/detail.php?id=<?= $d['id'] ?>" class="domain-name"><?= h($d['domain']) ?></a>
            <?php if (($_sysCfg['group_name']['show_in_list'] ?? false) === false && ($d['group_name'] ?? '')): ?>
            <small class="text-muted d-block"><?= h($d['group_name']) ?></small>
            <?php endif; ?>
          </td>
          <?php foreach ($_sysCfg as $_sf): if (!$_sf['show_in_list']) continue; ?>
          <?php switch ($_sf['name']): case 'registrar': ?>
            <td class="text-muted small"><?= h($d['registrar_name'] ?? '-') ?></td>
          <?php break; case 'account': ?>
            <td class="text-muted small"><?= h($d['account_name'] ?? '-') ?></td>
          <?php break; case 'register_date': ?>
            <td class="small text-muted"><?= h($d['register_date'] ?? '-') ?></td>
          <?php break; case 'expire_date': ?>
            <td>
              <span class="small <?= $expClass ?>"><?= h($d['expire_date'] ?: '-') ?></span>
              <?= getDomainExpireBadge($d['expire_date'] ?? '') ?>
            </td>
          <?php break; case 'status': ?>
            <td>
              <?php $s = STATUS_LABELS[$d['status']] ?? ['label' => $d['status'], 'class' => 'secondary']; ?>
              <span class="badge bg-<?= $s['class'] ?>"><?= h($s['label']) ?></span>
            </td>
          <?php break; case 'icp_type': ?>
            <td>
              <?php $icp = ICP_LABELS[$d['icp_type']] ?? ['label' => $d['icp_type'], 'class' => 'secondary']; ?>
              <span class="badge bg-<?= $icp['class'] ?>"><?= h($icp['label']) ?></span>
            </td>
          <?php break; case 'dns_servers': ?>
            <td class="small text-muted" style="max-width:160px;word-break:break-all">
              <?php if ($d['dns_servers'] ?? ''): ?>
              <?php foreach (explode(',', $d['dns_servers']) as $_dns): ?>
              <div><?= h(trim($_dns)) ?></div>
              <?php endforeach; ?>
              <?php else: ?>-<?php endif; ?>
            </td>
          <?php break; case 'group_name': ?>
            <td class="small text-muted"><?= h($d['group_name'] ?? '-') ?></td>
          <?php break; case 'admin_password': ?>
            <td class="small text-muted"><?= h($d['admin_password'] ?? '-') ?></td>
          <?php break; default: ?>
            <td>-</td>
          <?php endswitch; ?>
          <?php endforeach; ?>
          <?php if ($listFields): ?>
          <?php $cfData = json_decode($d['custom_data'] ?? '{}', true) ?: []; ?>
          <?php foreach ($listFields as $lf): ?>
          <td class="small text-muted"><?= h($cfData[$lf['name']] ?? '') ?></td>
          <?php endforeach; ?>
          <?php endif; ?>
          <td>
            <a href="/domains/detail.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-1" title="档案"><i class="bi bi-clock-history"></i></a>
            <a href="/domains/edit.php?id=<?= $d['id'] ?>"   class="btn btn-sm btn-outline-secondary py-0 px-1" title="编辑"><i class="bi bi-pencil"></i></a>
            <button onclick="deleteDomain(<?= $d['id'] ?>,'<?= h($d['domain']) ?>')" class="btn btn-sm btn-outline-danger py-0 px-1" title="删除"><i class="bi bi-trash"></i></button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= $_pagerHtml ?>
</div>

<script>
// ── 汇总标签点击：切换多选过滤 ──────────────────────────────────
function toggleMultiFilter(field, value) {
  var params = new URLSearchParams(window.location.search);
  var key    = field + '[]';
  var existing = params.getAll(key);
  params.delete(key);
  if (existing.includes(value)) {
    existing.filter(function(v){ return v !== value; }).forEach(function(v){ params.append(key, v); });
  } else {
    existing.forEach(function(v){ params.append(key, v); });
    params.append(key, value);
  }
  params.delete('page');
  location.href = '/domains/?' + params.toString();
}

function toggleTagFilter(tagId) {
  var params  = new URLSearchParams(window.location.search);
  var tags    = (params.get('tags') || '').split(',').filter(function(t){ return t; });
  var sid     = String(tagId);
  var idx     = tags.indexOf(sid);
  if (idx >= 0) tags.splice(idx, 1); else tags.push(sid);
  if (tags.length) params.set('tags', tags.join(',')); else params.delete('tags');
  params.delete('page');
  location.href = '/domains/?' + params.toString();
}

// 表单提交：在现有 URL 参数基础上更新 search/expire_warn/date，保留 pill 点击带来的其他筛选
function _submitFilterForm(e) {
  e.preventDefault();
  var params = new URLSearchParams(window.location.search);
  // 搜索
  var search = (document.querySelector('#filterForm [name="search"]') || {}).value || '';
  if (search.trim()) params.set('search', search.trim()); else params.delete('search');
  // 每页条数保持
  var pp = params.get('per_page');
  if (pp) params.set('per_page', pp);
  // 到期
  var ew = (document.getElementById('expireWarnInput') || {}).value || '';
  if (ew) params.set('expire_warn', ew); else params.delete('expire_warn');
  // 日期范围
  var df = (document.querySelector('#filterForm [name="date_from"]') || {}).value || '';
  var dt = (document.querySelector('#filterForm [name="date_to"]')   || {}).value || '';
  if (df) params.set('date_from', df); else params.delete('date_from');
  if (dt) params.set('date_to',   dt); else params.delete('date_to');
  params.delete('page');
  location.href = '/domains/?' + params.toString();
  return false;
}

function setExpireWarn(val) {
  var params = new URLSearchParams(window.location.search);
  if (val === 'expired' || val === 'today') {
    if (params.get('expire_warn') === val) { params.delete('expire_warn'); }
    else { params.set('expire_warn', val); }
  } else {
    var days = parseInt(val, 10);
    if (!days || days < 1) { alert('请输入有效天数（最少 1 天）'); return; }
    params.set('expire_warn', String(days));
  }
  params.delete('page');
  location.href = '/domains/?' + params.toString();
}

function clearSearch() {
  var params = new URLSearchParams(window.location.search);
  params.delete('search'); params.delete('page');
  location.href = '/domains/?' + params.toString();
}

function togglePlainFilter(field, value) {
  var params = new URLSearchParams(window.location.search);
  if (params.get(field) === value) { params.delete(field); }
  else { params.set(field, value); }
  params.delete('page');
  location.href = '/domains/?' + params.toString();
}

function toggleCfFilter(cfName, value) {
  var params = new URLSearchParams(window.location.search);
  var key    = 'cf[' + cfName + ']';
  if (params.get(key) === value) { params.delete(key); }
  else { params.set(key, value); }
  params.delete('page');
  location.href = '/domains/?' + params.toString();
}

function setPerPage(val) {
  var n = parseInt(val, 10);
  if (!n || n < 10) { alert('最少 10 条'); return; }
  if (n > 500)      { alert('最多 500 条'); return; }
  var params = new URLSearchParams(window.location.search);
  if (n === <?= PER_PAGE ?>) { params.delete('per_page'); }
  else { params.set('per_page', n); }
  params.delete('page');
  location.href = '/domains/?' + params.toString();
}

function clearDateFilter() {
  var params = new URLSearchParams(window.location.search);
  params.delete('date_from');
  params.delete('date_to');
  params.delete('date_field');
  params.delete('page');
  location.href = '/domains/?' + params.toString();
}

// ── 复制域名 ──────────────────────────────────────────────────
function _clipboardCopy(text) {
  // 优先使用 Clipboard API（需 HTTPS），否则回退到 execCommand
  if (navigator.clipboard && navigator.clipboard.writeText) {
    return navigator.clipboard.writeText(text);
  }
  return new Promise(function(resolve, reject) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;width:1px;height:1px';
    document.body.appendChild(ta);
    ta.focus(); ta.select();
    try {
      document.execCommand('copy') ? resolve() : reject();
    } catch(e) { reject(e); }
    document.body.removeChild(ta);
  });
}

function copyDomains(btn) {
  var qs  = window.location.search;
  var url = '/domains/api/export.php?action=copy' + (qs ? '&' + qs.slice(1) : '');
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> 获取中…';
  fetch(url).then(function(r){
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.text();
  }).then(function(text) {
    var lines = text.split('\n').filter(function(l){ return l.trim(); });
    _clipboardCopy(text).then(function(){
      btn.innerHTML = '<i class="bi bi-check2"></i> 已复制 (' + lines.length + ' 个)';
      setTimeout(function(){ btn.innerHTML = '<i class="bi bi-clipboard"></i> 复制域名'; btn.disabled = false; }, 2500);
    }).catch(function(){
      prompt('请手动复制以下域名列表（Ctrl+A 全选后 Ctrl+C）：', text);
      btn.innerHTML = '<i class="bi bi-clipboard"></i> 复制域名'; btn.disabled = false;
    });
  }).catch(function(e){
    alert('获取域名列表失败：' + e.message + '\n请刷新页面后重试');
    btn.innerHTML = '<i class="bi bi-clipboard"></i> 复制域名'; btn.disabled = false;
  });
}

function clearAllDomains(total) {
  if (!total) { alert('当前没有域名数据'); return; }
  var word = prompt('此操作将删除全部 ' + total + ' 个域名及其档案，不可恢复！\n\n请输入「确认清空」继续：');
  if (word === null) return;
  if (word.trim() !== '确认清空') { alert('输入不正确，操作已取消'); return; }

  fetch('/domains/api/clear.php', { method: 'POST' })
    .then(function(r){ return r.json(); })
    .then(function(data) {
      if (data.ok && data.job_id) {
        location.href = '/jobs/progress.php?id=' + encodeURIComponent(data.job_id);
      } else {
        alert('操作失败：' + (data.msg || '未知错误'));
      }
    });
}
</script>

<?php require_once dirname(__DIR__) . '/components/footer.php'; ?>
