<?php
$pageTitle = '域名卡片';
require_once dirname(__DIR__) . '/components/header.php';

$master   = getMasterDB();
$msg = ''; $error = '';
$today = date('Y-m-d');
$warn7 = date('Y-m-d', strtotime('+7 days'));
$CACHE_DIR = DATA_DIR . '/agg';
if (!is_dir($CACHE_DIR)) mkdir($CACHE_DIR, 0755, true);
$CACHE_TTL = 300; // 5 分钟

// DB 迁移：确保 not_found_domains 列存在
try {
    $master->exec("ALTER TABLE domain_cards ADD COLUMN not_found_domains TEXT DEFAULT '[]'");
} catch (Exception $e) { /* 列已存在，忽略 */ }

// ── POST 操作 ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name    = trim($_POST['name'] ?? '');
        $domains = array_unique(array_filter(array_map('trim', explode("\n", $_POST['domains'] ?? ''))));

        if (!$name) { $error = '卡片名称不能为空'; }
        elseif (db_one($master, "SELECT id FROM domain_cards WHERE name=?", [$name])) {
            $error = "卡片名称「{$name}」已存在，请换一个";
        } else {
            $found = []; $notFound = [];
            foreach ($domains as $d) {
                $row = db_one($master, "SELECT id FROM domains WHERE domain=?", [$d]);
                if ($row) $found[] = (int)$row['id'];
                else      $notFound[] = $d;
            }
            $cardId = db_insert($master, 'domain_cards', [
                'name'              => $name,
                'note'              => trim($_POST['note'] ?? ''),
                'not_found_domains' => $notFound ? json_encode(array_values($notFound), JSON_UNESCAPED_UNICODE) : '[]',
            ]);
            foreach ($found as $did)
                db_exec($master,
                    "INSERT OR IGNORE INTO domain_card_items (card_id,domain_id) VALUES (?,?)",
                    [$cardId, $did]);

            $msg = "卡片「{$name}」创建成功，加入 " . count($found) . " 个域名";
            if ($notFound)
                $msg .= "，<strong>" . count($notFound) . " 个域名在系统中不存在</strong>，已记录到「不存在域名」";
        }
    }

    if ($action === 'delete') {
        $cid = (int)($_POST['card_id'] ?? 0);
        $c   = $cid ? db_one($master, "SELECT name FROM domain_cards WHERE id=?", [$cid]) : null;
        if ($c) {
            db_exec($master, "DELETE FROM domain_card_items WHERE card_id=?", [$cid]);
            db_exec($master, "DELETE FROM domain_cards WHERE id=?", [$cid]);
            @unlink($CACHE_DIR . "/card_{$cid}.json");
            $msg = "已删除卡片「{$c['name']}」";
        }
    }

    if ($action === 'rename') {
        $cid = (int)($_POST['card_id'] ?? 0);
        $nn  = trim($_POST['new_name'] ?? '');
        $no  = trim($_POST['new_note'] ?? '');
        if ($cid && $nn) {
            db_exec($master,
                "UPDATE domain_cards SET name=?,note=?,updated_at=datetime('now','localtime') WHERE id=?",
                [$nn, $no, $cid]);
            $msg = '卡片已更新';
        }
    }

    if ($action === 'append_domains') {
        $cid  = (int)($_POST['card_id'] ?? 0);
        $card = $cid ? db_one($master, "SELECT * FROM domain_cards WHERE id=?", [$cid]) : null;
        if ($card) {
            $lines    = array_unique(array_filter(array_map('trim', explode("\n", $_POST['domains'] ?? ''))));
            $found    = 0;
            $notFound = [];
            foreach ($lines as $d) {
                $row = db_one($master, "SELECT id FROM domains WHERE domain=?", [$d]);
                if ($row) {
                    db_exec($master,
                        "INSERT OR IGNORE INTO domain_card_items (card_id, domain_id) VALUES (?,?)",
                        [$cid, (int)$row['id']]);
                    $found++;
                } else {
                    $notFound[] = $d;
                }
            }
            // 合并不存在域名（去重）
            if ($notFound) {
                $existing = json_decode($card['not_found_domains'] ?? '[]', true) ?: [];
                $merged   = array_values(array_unique(array_merge($existing, $notFound)));
                db_exec($master,
                    "UPDATE domain_cards SET not_found_domains=? WHERE id=?",
                    [json_encode($merged, JSON_UNESCAPED_UNICODE), $cid]);
            }
            // 清除聚合缓存
            @unlink($CACHE_DIR . "/card_{$cid}.json");
            $msg = "已向卡片「{$card['name']}」追加 {$found} 个域名";
            if ($notFound)
                $msg .= "，<strong>" . count($notFound) . " 个不存在于系统中</strong>，已记录到「不存在域名」";
        }
    }
}

// ── 读取所有卡片 + 数字统计 ──────────────────────────────────
$cards = db_all($master, "
    SELECT c.id, c.name, c.note, c.created_at, c.not_found_domains,
           COUNT(dci.domain_id) AS total,
           SUM(CASE WHEN d.status IN ('normal','正常') OR d.status='' OR d.status IS NULL THEN 1 ELSE 0 END) AS cnt_normal,
           SUM(CASE WHEN d.status IN ('paused','暂停解析')                             THEN 1 ELSE 0 END) AS cnt_paused,
           SUM(CASE WHEN d.expire_date!='' AND d.expire_date<?                        THEN 1 ELSE 0 END) AS cnt_expired,
           SUM(CASE WHEN d.expire_date!='' AND d.expire_date>=? AND d.expire_date<=?  THEN 1 ELSE 0 END) AS cnt_soon7
    FROM domain_cards c
    LEFT JOIN domain_card_items dci ON dci.card_id = c.id
    LEFT JOIN domains d             ON d.id        = dci.domain_id
    GROUP BY c.id ORDER BY c.created_at DESC
", [$today, $today, $warn7]);

// 未分配
$unassigned = db_one($master, "
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN d.status IN ('normal','正常') OR d.status='' OR d.status IS NULL THEN 1 ELSE 0 END) AS cnt_normal,
           SUM(CASE WHEN d.status IN ('paused','暂停解析')                             THEN 1 ELSE 0 END) AS cnt_paused,
           SUM(CASE WHEN d.expire_date!='' AND d.expire_date<?                        THEN 1 ELSE 0 END) AS cnt_expired,
           SUM(CASE WHEN d.expire_date!='' AND d.expire_date>=? AND d.expire_date<=?  THEN 1 ELSE 0 END) AS cnt_soon7
    FROM domains d WHERE d.id NOT IN (SELECT domain_id FROM domain_card_items)
", [$today, $today, $warn7]);

// ── 跨卡片重复域名计数（一次查询搞定所有卡片）────────────────
// 找出出现在 2 张以上卡片的 domain_id，再按卡片统计各自重复数量
$_dupRows = db_all($master, "
    SELECT card_id, COUNT(DISTINCT domain_id) AS dup_count
    FROM domain_card_items
    WHERE domain_id IN (
        SELECT domain_id FROM domain_card_items
        GROUP BY domain_id HAVING COUNT(DISTINCT card_id) > 1
    )
    GROUP BY card_id
") ?: [];
$dupCounts = []; // card_id => dup_count
foreach ($_dupRows as $_dr) {
    $dupCounts[(int)$_dr['card_id']] = (int)$_dr['dup_count'];
}

// ── 辅助：读取缓存 ────────────────────────────────────────────
function _readAggCache(string $cacheDir, string $key, int $ttl): ?array {
    $f = $cacheDir . '/' . $key . '.json';
    if (!file_exists($f)) return null;
    if ((time() - filemtime($f)) > $ttl) return null; // 过期
    $raw = @file_get_contents($f);
    return $raw ? json_decode($raw, true) : null;
}

// 读取各卡片缓存
$cardAgg = []; // card_id => agg data or null
foreach ($cards as $c) {
    $cardAgg[$c['id']] = _readAggCache($CACHE_DIR, 'card_' . $c['id'], $CACHE_TTL);
}
$unassignedAgg = _readAggCache($CACHE_DIR, 'card_unassigned', $CACHE_TTL);

// 需要自动刷新的卡片列表（无缓存或缓存过期）
$staleCards = [];
foreach ($cards as $c) {
    if ($cardAgg[$c['id']] === null) $staleCards[] = (string)$c['id'];
}
if ($unassignedAgg === null) $staleCards[] = 'unassigned';
?>

<?php if ($msg): ?>
<div class="alert alert-success alert-dismissible fade show py-2">
  <i class="bi bi-check-circle me-2"></i><?= h($msg) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show py-2">
  <i class="bi bi-exclamation-circle me-2"></i><?= h($error) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- 顶栏 -->
<div class="d-flex align-items-center justify-content-between mb-3">
  <div class="text-muted small">
    共 <strong><?= count($cards) ?></strong> 个卡片，
    未分配 <strong><?= (int)($unassigned['total']??0) ?></strong> 个域名
  </div>
  <button class="btn btn-primary btn-sm" onclick="toggleCreatePanel()">
    <i class="bi bi-plus-lg me-1"></i>创建卡片
  </button>
</div>

<!-- 创建面板 -->
<div id="createPanel" class="form-card mb-4" style="display:none">
  <div class="section-title mb-3">创建新卡片</div>
  <form method="POST">
    <input type="hidden" name="action" value="create">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label fw-semibold">卡片名称 <span class="text-danger">*</span></label>
        <input type="text" name="name" class="form-control" placeholder="例如：生产环境、备用域名…" required>
        <label class="form-label fw-semibold mt-3">备注（可选）</label>
        <textarea name="note" class="form-control" rows="2" placeholder="卡片用途说明…"></textarea>
        <button type="submit" class="btn btn-primary w-100 mt-3">
          <i class="bi bi-check-lg me-1"></i>创建卡片
        </button>
      </div>
      <div class="col-md-8">
        <label class="form-label fw-semibold">域名列表（每行一个）</label>
        <textarea name="domains" class="form-control font-monospace" rows="10"
                  placeholder="example.com&#10;test.cn&#10;…"></textarea>
        <div class="text-muted small mt-1">
          <i class="bi bi-info-circle me-1"></i>只加入已导入数据库的域名，未找到的将跳过并提示
        </div>
      </div>
    </div>
  </form>
</div>

<!-- ── 未分配域名卡 ──────────────────────────────────────────── -->
<?= _renderCard([
    'id'          => 0,
    'name'        => '未分配域名',
    'note'        => '',
    'created_at'  => '',
    'is_unassigned' => true,
], $unassigned, $unassignedAgg) ?>

<!-- ── 用户卡片列表 ──────────────────────────────────────────── -->
<?php if ($cards): ?>
  <?php foreach ($cards as $c): ?>
    <?= _renderCard($c, $c, $cardAgg[$c['id']] ?? null, $dupCounts[(int)$c['id']] ?? 0) ?>
  <?php endforeach; ?>
<?php else: ?>
<div class="text-center text-muted py-5">
  <i class="bi bi-inbox fs-1 d-block mb-3 opacity-25"></i>
  还没有卡片，点击右上角「创建卡片」开始使用
</div>
<?php endif; ?>

<!-- 重命名弹窗 -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="rename">
        <input type="hidden" name="card_id" id="editCardId">
        <div class="modal-header py-2">
          <h6 class="modal-title">编辑卡片</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <label class="form-label small fw-semibold">卡片名称</label>
          <input type="text" name="new_name" id="editName" class="form-control mb-2" required>
          <label class="form-label small fw-semibold">备注</label>
          <textarea name="new_note" id="editNote" class="form-control" rows="2"></textarea>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">取消</button>
          <button type="submit" class="btn btn-sm btn-primary">保存</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// 需要自动刷新的卡片列表（PHP 传入）
var _staleCards = <?= json_encode(array_values($staleCards)) ?>;

function toggleCreatePanel() {
  var p = document.getElementById('createPanel');
  p.style.display = p.style.display === 'none' ? '' : 'none';
}
function openEdit(id, name, note) {
  document.getElementById('editCardId').value = id;
  document.getElementById('editName').value   = name;
  document.getElementById('editNote').value   = note || '';
  new bootstrap.Modal(document.getElementById('editModal')).show();
}
function _esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// 刷新单张卡片聚合（手动按钮或自动调用）
function refreshCardAgg(cardKey, aggBodyId, timeId, btnId) {
  var btn  = btnId ? document.getElementById(btnId) : null;
  var body = document.getElementById(aggBodyId);
  var ti   = document.getElementById(timeId);
  if (btn) btn.disabled = true;

  var url = '/cards/api/agg.php?' + (cardKey === 'unassigned' ? 'type=unassigned' : 'id=' + cardKey);
  return fetch(url, { method: 'POST' })
    .then(function(r){ return r.json(); })
    .then(function(data){
      if (data && data.groups) {
        renderAggBody(body, data.groups);
        if (ti) ti.textContent = '统计: ' + (data.generated_at || '');
      }
    })
    .catch(function(){ if (ti) ti.textContent = '更新失败'; })
    .finally(function(){ if (btn) btn.disabled = false; });
}

// 自动并发刷新队列（最多同时发 concurrency 个请求）
function _autoRefreshQueue(queue, concurrency) {
  if (!queue || !queue.length) return;
  var running = 0;
  function next() {
    while (running < concurrency && queue.length) {
      (function(cardKey) {
        running++;
        var aggBodyId = 'agg-body-' + cardKey;
        var timeId    = 'agg-time-' + cardKey;
        refreshCardAgg(cardKey, aggBodyId, timeId, null)
          .finally(function() { running--; next(); });
      })(queue.shift());
    }
  }
  next();
}

// 页面加载：自动刷新所有缺少新鲜缓存的卡片
document.addEventListener('DOMContentLoaded', function() {
  if (_staleCards && _staleCards.length) {
    _autoRefreshQueue(_staleCards.slice(), 3); // 最多同时 3 个并发
  }
});

// 渲染聚合分组 HTML（支持固定字段 + 自定义字段 cf_*）
function renderAggBody(el, groups) {
  if (!el || !groups) return;
  var fixedDefs = [
    { key:'status',    label:'状态',   isTag:false },
    { key:'icp_type',  label:'备案',   isTag:false },
    { key:'dns',       label:'DNS',    isTag:false },
    { key:'registrar', label:'注册商', isTag:false },
    { key:'account',   label:'账号',   isTag:false },
    { key:'tags',      label:'标签',   isTag:true  },
  ];
  var html = '<div class="d-flex flex-wrap gap-3 mt-2 pt-2 border-top">';

  function renderGroup(label, rows, isTag) {
    if (!rows || !rows.length) return '';
    var g = '<div class="d-flex align-items-center flex-wrap gap-1"><span class="text-muted small fw-semibold me-1 text-nowrap">' + _esc(label) + '：</span>';
    rows.forEach(function(row) {
      var val = String(row.val||''), cnt = row.cnt||0;
      if (row._empty) {
        g += '<span class="ci-pill ci-pill-empty">【空】 <span class="ci-cnt">数量: '+cnt+'</span></span>';
      } else if (row._has) {
        g += '<span class="ci-pill ci-pill-muted">【有内容】 <span class="ci-cnt">数量: '+cnt+'</span></span>';
      } else if (isTag) {
        var color = row.color||'#adb5bd';
        g += '<span class="ci-pill ci-pill-tag" style="background:'+color+'">【'+_esc(val)+'】 <span class="ci-cnt">数量: '+cnt+'</span></span>';
      } else {
        g += '<span class="ci-pill">【'+_esc(val)+'】 <span class="ci-cnt">数量: '+cnt+'</span></span>';
      }
    });
    g += '</div>';
    return g;
  }

  // 固定字段
  fixedDefs.forEach(function(gd) {
    html += renderGroup(gd.label, groups[gd.key], gd.isTag);
  });
  // 自定义字段（cf_* 开头的 key）
  Object.keys(groups).forEach(function(key) {
    if (key.indexOf('cf_') !== 0) return;
    var g = groups[key];
    html += renderGroup(g.label || key, g.rows, false);
  });

  html += '</div>';
  el.innerHTML = html;
}
</script>

<?php
// ── 辅助：渲染单张卡片 ───────────────────────────────────────
function _renderCard(array $c, ?array $stats, ?array $agg, int $dupCount = 0): string {
    $isUnassigned = !empty($c['is_unassigned']);
    $cardId       = (int)($c['id'] ?? 0);
    $cardKey      = $isUnassigned ? 'unassigned' : $cardId;
    $detailUrl    = $isUnassigned ? '/cards/detail.php?type=unassigned' : '/cards/detail.php?id=' . $cardId;

    $total   = (int)($stats['total']       ?? 0);
    $normal  = (int)($stats['cnt_normal']  ?? 0);
    $paused  = (int)($stats['cnt_paused']  ?? 0);
    $expired = (int)($stats['cnt_expired'] ?? 0);
    $soon7   = (int)($stats['cnt_soon7']   ?? 0);

    $aggBodyId = 'agg-body-' . $cardKey;
    $aggTimeId = 'agg-time-' . $cardKey;
    $aggBtnId  = 'agg-btn-'  . $cardKey;

    // 聚合 HTML（从缓存渲染，或显示提示）
    $aggHtml = '';
    if ($agg && !empty($agg['groups'])) {
        $groups  = $agg['groups'];
        $genTime = $agg['generated_at'] ?? '';
        $defs    = [
            ['key'=>'status',    'label'=>'状态',   'isTag'=>false],
            ['key'=>'icp_type',  'label'=>'备案',   'isTag'=>false],
            ['key'=>'dns',       'label'=>'DNS',    'isTag'=>false],
            ['key'=>'registrar', 'label'=>'注册商', 'isTag'=>false],
            ['key'=>'tags',      'label'=>'标签',   'isTag'=>true],
        ];
        // 固定字段
        $fixedDefs = [
            ['key'=>'status',    'label'=>'状态',   'isTag'=>false],
            ['key'=>'icp_type',  'label'=>'备案',   'isTag'=>false],
            ['key'=>'dns',       'label'=>'DNS',    'isTag'=>false],
            ['key'=>'registrar', 'label'=>'注册商', 'isTag'=>false],
            ['key'=>'account',   'label'=>'账号',   'isTag'=>false],
            ['key'=>'tags',      'label'=>'标签',   'isTag'=>true],
        ];
        // 自定义字段（cf_* 开头的 key）
        $cfDefs = [];
        foreach (array_keys($groups) as $gkey) {
            if (strncmp($gkey, 'cf_', 3) === 0) {
                $g = $groups[$gkey];
                $cfDefs[] = ['key'=>$gkey, 'label'=>$g['label']??$gkey, 'isTag'=>false, 'cfRows'=>$g['rows']??[]];
            }
        }
        $allDefs = array_merge($fixedDefs, $cfDefs);

        $inner = '';
        foreach ($allDefs as $gd) {
            $rows = isset($gd['cfRows']) ? $gd['cfRows'] : ($groups[$gd['key']] ?? []);
            if (!$rows) continue;
            $inner .= '<div class="d-flex align-items-center flex-wrap gap-1"><span class="text-muted small fw-semibold me-1 text-nowrap">'.h($gd['label']).'：</span>';
            foreach ($rows as $row) {
                $val = (string)($row['val'] ?? '');
                $cnt = (int)($row['cnt'] ?? 0);
                if (!empty($row['_empty'])) {
                    $inner .= '<span class="ci-pill ci-pill-empty">【空】 <span class="ci-cnt">数量: '.$cnt.'</span></span>';
                } elseif (!empty($row['_has'])) {
                    $inner .= '<span class="ci-pill ci-pill-muted">【有内容】 <span class="ci-cnt">数量: '.$cnt.'</span></span>';
                } elseif ($gd['isTag']) {
                    $color = htmlspecialchars($row['color'] ?? '#adb5bd');
                    $inner .= '<span class="ci-pill ci-pill-tag" style="background:'.$color.'">【'.h($val).'】 <span class="ci-cnt">数量: '.$cnt.'</span></span>';
                } else {
                    $inner .= '<span class="ci-pill">【'.h($val).'】 <span class="ci-cnt">数量: '.$cnt.'</span></span>';
                }
            }
            $inner .= '</div>';
        }
        $aggHtml = '<div class="d-flex flex-wrap gap-3 mt-2 pt-2 border-top" id="'.$aggBodyId.'">' . $inner . '</div>';
    } else {
        $genTime = '';
        $aggHtml = '<div id="'.$aggBodyId.'" class="mt-2 pt-2 border-top">'
                 . '<span class="text-muted small" id="agg-loading-'.$cardKey.'"><i class="bi bi-hourglass-split me-1"></i>正在自动统计中…</span>'
                 . '</div>';
    }

    $html  = '<div class="table-card mb-2">';
    $html .= '<div class="px-3 py-3">';

    // 顶行：名称 + 操作按钮
    $html .= '<div class="d-flex align-items-start justify-content-between gap-2">';
    $html .= '<div class="flex-grow-1 min-w-0">';

    // 卡片名称行
    $html .= '<div class="d-flex align-items-center gap-2 flex-wrap mb-1">';
    if ($isUnassigned) {
        $html .= '<span class="fw-semibold text-secondary"><i class="bi bi-inbox me-1"></i>' . h($c['name']) . '</span>';
    } else {
        $html .= '<a href="' . $detailUrl . '" class="fw-semibold text-decoration-none">' . h($c['name']) . '</a>';
    }
    if (!empty($c['note'])) {
        $html .= '<span class="text-muted small">· ' . h($c['note']) . '</span>';
    }
    if (!$isUnassigned && !empty($c['created_at'])) {
        $html .= '<span class="text-muted small" style="font-size:11px">' . h($c['created_at']) . '</span>';
    }
    $html .= '</div>';

    // 不存在域名
    $nfList  = !$isUnassigned ? (json_decode($c['not_found_domains'] ?? '[]', true) ?: []) : [];
    $nfCount = count($nfList);

    // 数字徽章行
    $html .= '<div class="d-flex gap-2 flex-wrap align-items-center">';
    $html .= '<span class="badge bg-primary rounded-pill px-2">共 '.$total.' 个</span>';
    if ($normal)  $html .= '<span class="badge bg-success rounded-pill px-2">正常 '.$normal.'</span>';
    if ($paused)  $html .= '<span class="badge bg-warning text-dark rounded-pill px-2">暂停 '.$paused.'</span>';
    if ($expired) $html .= '<span class="badge bg-danger rounded-pill px-2">已过期 '.$expired.'</span>';
    if ($soon7)   $html .= '<span class="badge bg-danger rounded-pill px-2"><i class="bi bi-alarm me-1"></i>7天到期 '.$soon7.'</span>';
    if ($nfCount)   $html .= '<a href="'.$detailUrl.'&show_nf=1" class="badge bg-secondary rounded-pill px-2 text-decoration-none" title="查看不存在于系统的域名"><i class="bi bi-exclamation-triangle me-1"></i>不存在 '.$nfCount.' 个</a>';
    if ($dupCount && !$isUnassigned) $html .= '<a href="'.$detailUrl.'&show_dup=1" class="badge rounded-pill px-2 text-decoration-none text-dark" style="background:#fd7e14" title="查看在其他卡片中重复出现的域名"><i class="bi bi-copy me-1"></i>跨卡重复 '.$dupCount.' 个</a>';

    // 统计时间 + 更新按钮（内联）
    $html .= '<span class="text-muted ms-1" style="font-size:11px" id="'.$aggTimeId.'">'
           . ($genTime ? '统计: '.$genTime : '') . '</span>';
    $html .= '<button class="btn btn-sm btn-outline-secondary py-0 px-1" style="font-size:11px;line-height:1.4" id="'.$aggBtnId.'"'
           . ' onclick="refreshCardAgg(\'' . $cardKey . '\',\'' . $aggBodyId . '\',\'' . $aggTimeId . '\',\'' . $aggBtnId . '\')"'
           . ' title="更新统计">'
           . '<i class="bi bi-arrow-clockwise"></i> 更新统计</button>';
    $html .= '</div>';

    // 聚合明细
    $html .= $aggHtml;
    $html .= '</div>'; // flex-grow-1

    // 右侧操作按钮
    $html .= '<div class="d-flex gap-1 flex-shrink-0 ms-2">';
    $html .= '<a href="' . $detailUrl . '" class="btn btn-sm btn-outline-primary" title="查看域名列表"><i class="bi bi-eye"></i></a>';
    if (!$isUnassigned) {
        $html .= '<button class="btn btn-sm btn-outline-success" title="追加域名列表"'
               . ' onclick="openAppend(' . $cardId . ',' . htmlspecialchars(json_encode($c['name'])) . ')">'
               . '<i class="bi bi-plus-circle"></i></button>';
        $html .= '<button class="btn btn-sm btn-outline-secondary" title="编辑"'
               . ' onclick="openEdit(' . $cardId . ',' . htmlspecialchars(json_encode($c['name'])) . ',' . htmlspecialchars(json_encode($c['note']??'')) . ')"><i class="bi bi-pencil"></i></button>';
        $html .= '<form method="POST" onsubmit="return confirm(\'确定删除卡片「' . addslashes(h($c['name'])) . '」吗？域名本身不受影响。\')">'
               . '<input type="hidden" name="action" value="delete">'
               . '<input type="hidden" name="card_id" value="' . $cardId . '">'
               . '<button class="btn btn-sm btn-outline-danger" type="submit" title="删除"><i class="bi bi-trash"></i></button>'
               . '</form>';
    }
    $html .= '</div>';
    $html .= '</div>'; // d-flex top row
    $html .= '</div>'; // px-3 py-3
    $html .= '</div>'; // table-card
    return $html;
}
?>

<style>
.ci-pill {
  display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:4px;
  font-size:12px;border:1px solid #dee2e6;background:#f8f9fa;color:#343a40;line-height:1.5;
}
.ci-pill-empty  { color:#adb5bd;border-color:#e9ecef; }
.ci-pill-muted  { color:#198754;border-color:#d1e7dd; }
.ci-pill-tag    { border-radius:20px!important;color:#fff;border:none!important; }
.ci-cnt { font-size:11px;opacity:.75;white-space:nowrap; }
</style>

<!-- 追加域名 Modal -->
<div class="modal fade" id="appendModal" tabindex="-1" aria-labelledby="appendModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" id="appendForm">
        <input type="hidden" name="action" value="append_domains">
        <input type="hidden" name="card_id" id="appendCardId" value="">
        <div class="modal-header">
          <h5 class="modal-title" id="appendModalLabel">
            <i class="bi bi-plus-circle me-2"></i>追加域名到卡片
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-2">
            向卡片 <strong id="appendCardName"></strong> 追加域名。已存在的域名会自动跳过（不重复添加）。
          </p>
          <textarea name="domains" id="appendDomains" class="form-control font-monospace"
                    rows="10" placeholder="每行输入一个域名，例如：&#10;example.com&#10;test.cn&#10;hello.net"></textarea>
          <div class="text-muted small mt-1" id="appendLineCount">0 行</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
          <button type="submit" class="btn btn-success">
            <i class="bi bi-plus-circle me-1"></i>确认追加
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openAppend(cardId, cardName) {
  document.getElementById('appendCardId').value   = cardId;
  document.getElementById('appendCardName').textContent = cardName;
  document.getElementById('appendDomains').value  = '';
  document.getElementById('appendLineCount').textContent = '0 行';
  var modal = new bootstrap.Modal(document.getElementById('appendModal'));
  modal.show();
  setTimeout(function() { document.getElementById('appendDomains').focus(); }, 300);
}
document.getElementById('appendDomains').addEventListener('input', function() {
  var lines = this.value.split('\n').filter(function(l){ return l.trim() !== ''; });
  document.getElementById('appendLineCount').textContent = lines.length + ' 行';
});
</script>

<?php require_once dirname(__DIR__) . '/components/footer.php'; ?>
