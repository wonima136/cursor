<?php
$pageTitle = '域名列表';
require_once dirname(__DIR__) . '/components/header.php';

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
if ($filters['date_from']) $_qp['date_from'] = $filters['date_from'];
if ($filters['date_to'])   $_qp['date_to']   = $filters['date_to'];
if ($perPage !== PER_PAGE) $_qp['per_page']  = $perPage;
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
if ($filters['search']) {
    $v=$filters['search'];
    $_activeBadges[] = ['label'=>'搜索', 'val'=>$v, 'onclick'=>"clearSearch()"];
}
?>

<!-- 统计卡片 -->
<div class="row g-3 mb-3">
  <?php
  $cards = [
    ['num'=>$stats['total'],   'label'=>'域名总数',    'color'=>'primary', 'icon'=>'bi-globe2'],
    ['num'=>$stats['normal'],  'label'=>'正常',        'color'=>'success', 'icon'=>'bi-check-circle'],
    ['num'=>$stats['paused'],  'label'=>'暂停解析',    'color'=>'warning', 'icon'=>'bi-pause-circle'],
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
<div class="filter-bar mb-3">
  <form id="filterForm" onsubmit="return _submitFilterForm(event)">
    <div class="row g-2 align-items-center">
      <!-- 搜索 -->
      <div class="col-12 col-md-4">
        <input type="text" name="search" class="form-control form-control-sm"
               placeholder="搜索域名..." value="<?= h($filters['search']) ?>">
      </div>

      <!-- 到期筛选 -->
      <div class="col-12 col-md-auto">
        <div class="d-flex align-items-center gap-1 flex-wrap">
          <button type="button"
                  class="btn btn-sm text-nowrap <?= $filters['expire_warn']==='expired' ? 'btn-danger' : 'btn-outline-danger' ?>"
                  onclick="setExpireWarn('expired')" title="筛选已过期域名">
            已过期
          </button>
          <button type="button"
                  class="btn btn-sm text-nowrap <?= $filters['expire_warn']==='today' ? 'btn-warning' : 'btn-outline-warning' ?>"
                  onclick="setExpireWarn('today')" title="筛选今天到期的域名">
            今天到期
          </button>
          <div class="input-group input-group-sm" style="width:160px">
            <input type="number" id="expireDaysInput" class="form-control" min="1" max="9999"
                   placeholder="天数"
                   value="<?= (is_numeric($filters['expire_warn']) && (int)$filters['expire_warn']>0) ? (int)$filters['expire_warn'] : 7 ?>">
            <button type="button"
                    class="btn text-nowrap <?= (is_numeric($filters['expire_warn']) && (int)$filters['expire_warn']>0) ? 'btn-warning' : 'btn-outline-warning' ?>"
                    onclick="setExpireWarn(document.getElementById('expireDaysInput').value)"
                    title="筛选未来 N 天内即将到期的域名（不含已过期）">
              天内到期
            </button>
          </div>
          <input type="hidden" name="expire_warn" id="expireWarnInput" value="<?= h($filters['expire_warn']) ?>">
        </div>
      </div>

      <!-- 提交/重置 -->
      <div class="col-auto d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm">搜索</button>
        <a href="/admin/index.php" class="btn btn-outline-secondary btn-sm">重置</a>
      </div>
    </div>

    <!-- 过期时间范围筛选 -->
    <div class="row g-2 align-items-center mt-1 pt-1 border-top">
      <div class="col-auto">
        <span class="small text-muted"><i class="bi bi-calendar-range me-1"></i>过期时间</span>
      </div>
      <div class="col-auto">
        <input type="date" name="date_from" class="form-control form-control-sm"
               value="<?= h($filters['date_from']) ?>" title="过期开始日期（含）">
      </div>
      <div class="col-auto text-muted small">~</div>
      <div class="col-auto">
        <input type="date" name="date_to" class="form-control form-control-sm"
               value="<?= h($filters['date_to']) ?>" title="过期结束日期（含）">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-outline-primary btn-sm">筛选</button>
        <?php if ($filters['date_from'] || $filters['date_to']): ?>
        <button type="button" class="btn btn-outline-secondary btn-sm ms-1" onclick="clearDateFilter()">清除</button>
        <?php endif; ?>
      </div>
    </div>


  </form>
</div>

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
  <a href="/admin/index.php" class="btn btn-sm py-0 px-2 ms-1"
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
$_aggCached    = file_exists($_aggCacheFile) ? @file_get_contents($_aggCacheFile) : null;
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
        <i class="bi bi-bar-chart"></i> 统计未加载 &nbsp;
        <button class="btn btn-sm btn-primary" onclick="loadAggStats(true)">立即统计</button>
      </div>
    </div>
  </div>
</div>
<style>
.ring-active { outline: 2px solid #0d6efd; outline-offset: 1px; }
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
      // 有内容行（textarea 专用）：绿色展示，不可点击
      if (row._has) {
        html += '<span class="badge rounded-pill border px-2 py-1 bg-light text-success border-success" title="已填写该字段的域名数量">'
              + '有内容 <span class="ms-1 opacity-75">' + cnt + '</span></span>';
        return;
      }
      // 空值行：灰色展示，不可点击
      if (row._empty) {
        html += '<span class="badge rounded-pill border px-2 py-1 bg-light text-muted border-light" title="未设置该字段的域名数量">'
              + '(空) <span class="ms-1 opacity-75">' + cnt + '</span></span>';
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
              + ' class="tag-pill d-inline-flex align-items-center gap-1' + (active?' ring-active':'') + '">'
              + _esc(val) + '<span class="badge bg-white text-dark ms-1 rounded-pill">' + cnt + '</span>'
              + (active ? '<span class="ms-1" style="font-size:10px">✕</span>' : '')
              + '</span>';
      } else {
        html += '<span onclick="' + onclick + '" style="cursor:pointer"'
              + ' class="badge rounded-pill border px-2 py-1 ' + (active?'bg-primary text-white border-primary':'bg-light text-dark border-secondary') + '">'
              + _esc(label) + ' <span class="ms-1 opacity-75">' + cnt + '</span>'
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
  var url = '/api/agg_stats.php' + (_aggQs ? '?' + _aggQs : '');
  fetch(url, { method: forceRefresh ? 'POST' : 'GET' })
    .then(function(r){ return r.json(); })
    .then(function(data) {
      if (data && data.error) {
        document.getElementById('agg-placeholder').innerHTML =
          '<span class="text-danger small"><i class="bi bi-exclamation-circle"></i> 统计出错: ' +
          data.error.substring(0, 120) + '</span>' +
          ' &nbsp;<button class="btn btn-sm btn-outline-danger ms-1" onclick="loadAggStats(true)">重试</button>';
        document.getElementById('agg-placeholder').style.display = '';
      } else if (data && data.cached !== false) {
        showAgg(data);
        _aggCached = data;
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

// 页面加载：有缓存直接渲染；有筛选条件时自动触发统计（层层筛选）
document.addEventListener('DOMContentLoaded', function() {
  var _hasFilters = _activeTags.length > 0 || Object.keys(_activeCf).length > 0 ||
    Object.keys(_activeFilters).some(function(k) {
      var v = _activeFilters[k];
      return Array.isArray(v) ? v.length > 0 : (v !== '' && v !== null);
    });

  if (_aggCached && _aggCached.cached !== false) {
    // 先显示缓存；若有筛选条件，后台异步刷新确保数据准确
    showAgg(_aggCached);
    if (_hasFilters) {
      setTimeout(function() { loadAggStats(true); }, 300);
    }
  } else if (_hasFilters) {
    // 无缓存且有筛选条件时立即计算
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
      <a href="<?= '/api/export_domains.php?action=csv&' . $queryStr ?>" class="btn btn-sm btn-outline-success" title="导出为CSV">
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
          <th>注册商</th>
          <th>账号</th>
          <th>注册时间</th>
          <th>过期时间</th>
          <th>状态</th>
          <th>备案</th>
          <th>DNS</th>
          <th>标签</th>
          <?php foreach ($listFields as $lf): ?>
          <th class="small"><?= h($lf['label']) ?></th>
          <?php endforeach; ?>
          <th width="90">操作</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$domains): ?>
        <tr><td colspan="<?= 11 + count($listFields) ?>" class="text-center text-muted py-5">暂无数据</td></tr>
        <?php endif; ?>
        <?php foreach ($domains as $d): ?>
        <?php $expClass = getDomainExpireClass($d['expire_date'] ?? ''); ?>
        <tr>
          <td><input type="checkbox" class="form-check-input row-check" value="<?= $d['id'] ?>"></td>
          <td>
            <a href="/admin/detail.php?id=<?= $d['id'] ?>" class="domain-name"><?= h($d['domain']) ?></a>
            <?php if ($d['group_name'] ?? ''): ?>
            <small class="text-muted d-block"><?= h($d['group_name']) ?></small>
            <?php endif; ?>
          </td>
          <td class="text-muted small"><?= h($d['registrar_name'] ?? '-') ?></td>
          <td class="text-muted small"><?= h($d['account_name'] ?? '-') ?></td>
          <td class="small text-muted"><?= h($d['register_date'] ?? '-') ?></td>
          <td>
            <span class="small <?= $expClass ?>"><?= h($d['expire_date'] ?: '-') ?></span>
            <?= getDomainExpireBadge($d['expire_date'] ?? '') ?>
          </td>
          <td>
            <?php $s = STATUS_LABELS[$d['status']] ?? ['label' => $d['status'], 'class' => 'secondary']; ?>
            <span class="badge bg-<?= $s['class'] ?>"><?= h($s['label']) ?></span>
          </td>
          <td>
            <?php $icp = ICP_LABELS[$d['icp_type']] ?? ['label' => $d['icp_type'], 'class' => 'secondary']; ?>
            <span class="badge bg-<?= $icp['class'] ?>"><?= h($icp['label']) ?></span>
          </td>
          <td class="small text-muted" style="max-width:160px;word-break:break-all">
            <?php if ($d['dns_servers'] ?? ''): ?>
            <?php foreach (explode(',', $d['dns_servers']) as $_dns): ?>
            <div><?= h(trim($_dns)) ?></div>
            <?php endforeach; ?>
            <?php else: ?>-<?php endif; ?>
          </td>
          <td>
            <?php foreach (($d['tags'] ?? []) as $tag): ?>
            <span class="tag-pill" style="background:<?= h($tag['color']) ?>"><?= h($tag['name']) ?></span>
            <?php endforeach; ?>
          </td>
          <?php if ($listFields): ?>
          <?php $cfData = json_decode($d['custom_data'] ?? '{}', true) ?: []; ?>
          <?php foreach ($listFields as $lf): ?>
          <td class="small text-muted"><?= h($cfData[$lf['name']] ?? '') ?></td>
          <?php endforeach; ?>
          <?php endif; ?>
          <td>
            <a href="/admin/detail.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-1" title="档案"><i class="bi bi-clock-history"></i></a>
            <a href="/admin/edit.php?id=<?= $d['id'] ?>"   class="btn btn-sm btn-outline-secondary py-0 px-1" title="编辑"><i class="bi bi-pencil"></i></a>
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
  location.href = '/admin/index.php?' + params.toString();
}

function toggleTagFilter(tagId) {
  var params  = new URLSearchParams(window.location.search);
  var tags    = (params.get('tags') || '').split(',').filter(function(t){ return t; });
  var sid     = String(tagId);
  var idx     = tags.indexOf(sid);
  if (idx >= 0) tags.splice(idx, 1); else tags.push(sid);
  if (tags.length) params.set('tags', tags.join(',')); else params.delete('tags');
  params.delete('page');
  location.href = '/admin/index.php?' + params.toString();
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
  location.href = '/admin/index.php?' + params.toString();
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
  location.href = '/admin/index.php?' + params.toString();
}

function clearSearch() {
  var params = new URLSearchParams(window.location.search);
  params.delete('search'); params.delete('page');
  location.href = '/admin/index.php?' + params.toString();
}

function togglePlainFilter(field, value) {
  var params = new URLSearchParams(window.location.search);
  if (params.get(field) === value) { params.delete(field); }
  else { params.set(field, value); }
  params.delete('page');
  location.href = '/admin/index.php?' + params.toString();
}

function toggleCfFilter(cfName, value) {
  var params = new URLSearchParams(window.location.search);
  var key    = 'cf[' + cfName + ']';
  if (params.get(key) === value) { params.delete(key); }
  else { params.set(key, value); }
  params.delete('page');
  location.href = '/admin/index.php?' + params.toString();
}

function setPerPage(val) {
  var n = parseInt(val, 10);
  if (!n || n < 10) { alert('最少 10 条'); return; }
  if (n > 500)      { alert('最多 500 条'); return; }
  var params = new URLSearchParams(window.location.search);
  if (n === <?= PER_PAGE ?>) { params.delete('per_page'); }
  else { params.set('per_page', n); }
  params.delete('page');
  location.href = '/admin/index.php?' + params.toString();
}

function clearDateFilter() {
  var params = new URLSearchParams(window.location.search);
  params.delete('date_from');
  params.delete('date_to');
  params.delete('date_field');
  params.delete('page');
  location.href = '/admin/index.php?' + params.toString();
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
  var url = '/api/export_domains.php?action=copy' + (qs ? '&' + qs.slice(1) : '');
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

  fetch('/api/clear_domains.php', { method: 'POST' })
    .then(function(r){ return r.json(); })
    .then(function(data) {
      if (data.ok && data.job_id) {
        location.href = '/admin/job_progress.php?id=' + encodeURIComponent(data.job_id);
      } else {
        alert('操作失败：' + (data.msg || '未知错误'));
      }
    });
}
</script>

<?php require_once dirname(__DIR__) . '/components/footer.php'; ?>
