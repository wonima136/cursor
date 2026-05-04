<?php
require_once dirname(__DIR__) . '/core/functions.php';

$master = getMasterDB();
$type   = trim($_GET['type'] ?? '');
$cardId = (int)($_GET['id'] ?? 0);
$today  = date('Y-m-d');
$warn7  = date('Y-m-d', strtotime('+7 days'));

// ── 卡片内域名查找（session 存储）────────────────────────────
if (!isset($_SESSION)) @session_start();
$_cardKey     = $type === 'unassigned' ? 'unassigned' : (string)$cardId;
$_sessKey     = 'card_dl_' . $_cardKey;

// 排除过期 / N 天内 参数（早于 $_baseUrl 读取，以便嵌入 URL）
$_cardExclExp  = !empty($_GET['excl_exp']);
$_cardExclDays = max(0, (int)($_GET['excl_days'] ?? 0));

$_baseUrl     = '/cards/detail.php?' . ($type === 'unassigned' ? 'type=unassigned' : 'id=' . $cardId);
if ($_cardExclExp)          $_baseUrl .= '&excl_exp=1';
if ($_cardExclDays > 0)     $_baseUrl .= '&excl_days=' . $_cardExclDays;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'card_dl_search') {
    $raw = trim($_POST['domain_input'] ?? '');
    if ($raw) {
        $_SESSION[$_sessKey] = array_values(array_unique(
            array_filter(array_map('trim', explode("\n", $raw)))
        ));
    } else {
        unset($_SESSION[$_sessKey]);
    }
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    parse_str($qs, $qArr); $qArr['dl'] = '1'; unset($qArr['page']);
    header('Location: ' . $_baseUrl . '&' . http_build_query(array_diff_key($qArr, ['id'=>1,'type'=>1])));
    exit;
}
if (isset($_GET['dl_clear'])) {
    unset($_SESSION[$_sessKey]);
    header('Location: ' . $_baseUrl);
    exit;
}
$_dlActive = !empty($_GET['dl']) && !empty($_SESSION[$_sessKey]);
$_dlList   = $_dlActive ? $_SESSION[$_sessKey] : [];

// ── 确定卡片信息 ─────────────────────────────────────────────
$_showNf  = !empty($_GET['show_nf']);  // 展示不存在域名模式
$_showDup = !empty($_GET['show_dup']); // 展示跨卡重复域名模式
if ($type === 'unassigned') {
    $cardName        = '未分配域名';
    $cardNote        = '';
    $notFoundDomains = [];
} elseif ($cardId) {
    $card = db_one($master, "SELECT * FROM domain_cards WHERE id=?", [$cardId]);
    if (!$card) { header('Location: /cards/'); exit; }
    $cardName        = $card['name'];
    $cardNote        = $card['note'] ?? '';
    $notFoundDomains = json_decode($card['not_found_domains'] ?? '[]', true) ?: [];
} else {
    header('Location: /cards/'); exit;
}

// ── 跨卡重复域名数据 ────────────────────────────────────────
// 对有卡片ID的情况（包括 unassigned 无重复）统计重复域名
$dupDomains = []; // [ domain => [card_name, ...], ... ]
$dupCount   = 0;
if ($cardId) {
    // 单次JOIN查询：该卡片内同时存在于其他卡片的域名及对应卡片名
    $dupRows = db_all($master, "
        SELECT d.domain, dc.name AS other_card
        FROM domain_card_items dci
        JOIN domains d              ON d.id  = dci.domain_id
        JOIN domain_card_items dci2 ON dci2.domain_id = dci.domain_id
                                    AND dci2.card_id  != dci.card_id
        JOIN domain_cards dc        ON dc.id = dci2.card_id
        WHERE dci.card_id = ?
        ORDER BY d.domain, dc.name
    ", [$cardId]) ?: [];
    foreach ($dupRows as $dr) {
        $dom = $dr['domain'];
        if (!isset($dupDomains[$dom])) $dupDomains[$dom] = [];
        $dupDomains[$dom][] = $dr['other_card'];
    }
    $dupCount = count($dupDomains);
}

// ── POST 操作（添加/移除域名，仅用户卡片）────────────────────
$msg = '';
if ($cardId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_domains') {
        $lines = array_unique(array_filter(array_map('trim', explode("\n", $_POST['domains'] ?? ''))));
        $found = 0; $notFound = [];
        foreach ($lines as $d) {
            $row = db_one($master, "SELECT id FROM domains WHERE domain=?", [$d]);
            if ($row) {
                db_exec($master,
                    "INSERT OR IGNORE INTO domain_card_items (card_id, domain_id) VALUES (?,?)",
                    [$cardId, (int)$row['id']]);
                $found++;
            } else { $notFound[] = $d; }
        }
        // 合并不存在域名列表（去重）
        if ($notFound) {
            $existing = json_decode(
                db_val($master, "SELECT not_found_domains FROM domain_cards WHERE id=?", [$cardId]) ?? '[]',
                true
            ) ?: [];
            $merged = array_values(array_unique(array_merge($existing, $notFound)));
            db_exec($master,
                "UPDATE domain_cards SET not_found_domains=? WHERE id=?",
                [json_encode($merged, JSON_UNESCAPED_UNICODE), $cardId]
            );
        }
        $msg = "已添加 {$found} 个域名";
        if ($notFound)
            $msg .= "，<strong>" . count($notFound) . " 个不存在于系统中</strong>，已记录到「不存在域名」";
        // 清除该卡片聚合缓存
        $cf = DATA_DIR . '/agg/' . ($cardId ? "card_{$cardId}" : 'card_unassigned') . '.json';
        if (file_exists($cf)) @unlink($cf);
    }

    if ($action === 'remove_domain') {
        $did = (int)($_POST['domain_id'] ?? 0);
        if ($did) {
            db_exec($master, "DELETE FROM domain_card_items WHERE card_id=? AND domain_id=?",
                [$cardId, $did]);
            $msg = '已从卡片移除该域名';
            $cf  = DATA_DIR . "/agg/card_{$cardId}.json";
            if (file_exists($cf)) @unlink($cf);
        }
    }

    if ($action === 'batch_remove') {
        $dids = array_map('intval', (array)($_POST['domain_ids'] ?? []));
        $dids = array_filter($dids);
        if ($dids) {
            foreach ($dids as $did) {
                db_exec($master,
                    "DELETE FROM domain_card_items WHERE card_id=? AND domain_id=?",
                    [$cardId, $did]);
            }
            $cf = DATA_DIR . "/agg/card_{$cardId}.json";
            if (file_exists($cf)) @unlink($cf);
            $msg = '已从卡片移除 ' . count($dids) . ' 个域名';
        }
    }

    if ($action === 'remove_nf') {
        $nfDomain = trim($_POST['nf_domain'] ?? '');
        if ($nfDomain) {
            $existing = json_decode(
                db_val($master, "SELECT not_found_domains FROM domain_cards WHERE id=?", [$cardId]) ?? '[]',
                true
            ) ?: [];
            $updated = array_values(array_filter($existing, function($d) use ($nfDomain) {
                return $d !== $nfDomain;
            }));
            db_exec($master,
                "UPDATE domain_cards SET not_found_domains=? WHERE id=?",
                [json_encode($updated, JSON_UNESCAPED_UNICODE), $cardId]
            );
            // 更新当前页面用的变量
            $notFoundDomains = $updated;
            $msg = "已从不存在列表移除：{$nfDomain}";
        }
    }
}

// ── 构建 WHERE ───────────────────────────────────────────────
if ($type === 'unassigned') {
    $joinClause  = '';
    $whereClause = "WHERE d.id NOT IN (SELECT domain_id FROM domain_card_items)";
    $baseParams  = [];
    $aggQs       = 'type=unassigned';
} else {
    $joinClause  = "JOIN domain_card_items dci ON dci.domain_id = d.id AND dci.card_id = ?";
    $whereClause = '';
    $baseParams  = [$cardId];
    $aggQs       = "id={$cardId}";
}
// dl 模式：把 dl=1 + 当前卡片 sessKey 传给 agg API，让它按搜索结果汇总
if ($_dlActive) {
    $aggQs .= '&dl=1&sess_key=' . urlencode($_sessKey);
}

// ── 数字统计（每次实时计算，很快）───────────────────────────
$stats = db_one($master, "
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN d.status IN ('normal','正常') OR d.status='' OR d.status IS NULL THEN 1 ELSE 0 END) AS cnt_normal,
           SUM(CASE WHEN d.status IN ('paused','暂停解析')                             THEN 1 ELSE 0 END) AS cnt_paused,
           SUM(CASE WHEN d.expire_date!='' AND d.expire_date<?                        THEN 1 ELSE 0 END) AS cnt_expired,
           SUM(CASE WHEN d.expire_date!='' AND d.expire_date>=? AND d.expire_date<=?  THEN 1 ELSE 0 END) AS cnt_soon7
    FROM domains d $joinClause $whereClause
", array_merge([$today, $today, $warn7], $baseParams));

$total = (int)($stats['total'] ?? 0);

// ── 读取聚合缓存（用于初始渲染）────────────────────────────
$cacheDir  = DATA_DIR . '/agg';
$cacheFile = $cacheDir . '/' . ($type === 'unassigned' ? 'card_unassigned' : "card_{$cardId}") . '.json';
$CACHE_TTL = 300; // 5 分钟

$initAgg = null;
// dl 过滤模式下，不使用全局缓存（JS 会自动触发实时计算）
if (!$_dlActive && file_exists($cacheFile) && (time() - filemtime($cacheFile)) <= $CACHE_TTL) {
    $raw = @file_get_contents($cacheFile);
    if ($raw) $initAgg = json_decode($raw, true);
}

// ── URL 点击过滤参数 ─────────────────────────────────────────
$_fStatus = trim($_GET['f_status'] ?? '');
$_fIcp    = trim($_GET['f_icp']    ?? '');
$_fDns    = trim($_GET['f_dns']    ?? '');
$_fReg    = trim($_GET['f_reg']    ?? '');
$_fAcc    = trim($_GET['f_acc']    ?? '');
$_fTag    = trim($_GET['f_tag']    ?? '');
$_activeFilters = array_filter([
    'f_status' => $_fStatus, 'f_icp' => $_fIcp, 'f_dns' => $_fDns,
    'f_reg' => $_fReg,       'f_acc' => $_fAcc, 'f_tag' => $_fTag,
]);
$_filterLabels = [
    'f_status' => '状态', 'f_icp' => '备案', 'f_dns' => 'DNS',
    'f_reg' => '注册商',  'f_acc' => '账号', 'f_tag' => '标签',
];

// ── 构建额外过滤条件（字段过滤 + 域名列表搜索）──────────────
$_extraConds  = [];
$_extraParams = [];

// __EMPTY__ 哨兵 = 筛选该字段为空的域名
if ($_fStatus === '__EMPTY__') { $_extraConds[] = "(d.status='' OR d.status IS NULL)"; }
elseif ($_fStatus) { $_extraConds[] = 'd.status = ?';      $_extraParams[] = $_fStatus; }

if ($_fIcp === '__EMPTY__') { $_extraConds[] = "(d.icp_type='' OR d.icp_type IS NULL)"; }
elseif ($_fIcp) { $_extraConds[] = 'd.icp_type = ?';       $_extraParams[] = $_fIcp; }

if ($_fDns === '__EMPTY__') { $_extraConds[] = "(d.dns_servers='' OR d.dns_servers IS NULL)"; }
elseif ($_fDns) { $_extraConds[] = 'd.dns_servers = ?';    $_extraParams[] = $_fDns; }

if ($_fReg === '__EMPTY__') { $_extraConds[] = "(d.registrar_id IS NULL OR d.registrar_id=0)"; }
elseif ($_fReg) {
    $_extraConds[] = 'd.registrar_id = (SELECT id FROM registrars WHERE name=? LIMIT 1)';
    $_extraParams[] = $_fReg;
}
if ($_fAcc === '__EMPTY__') { $_extraConds[] = "(d.account_id IS NULL OR d.account_id=0)"; }
elseif ($_fAcc) {
    $_extraConds[] = 'd.account_id = (SELECT id FROM accounts WHERE username=? LIMIT 1)';
    $_extraParams[] = $_fAcc;
}
if ($_fTag) {
    $_extraConds[] = 'EXISTS (SELECT 1 FROM domain_tags _dt JOIN tags _t ON _t.id=_dt.tag_id WHERE _dt.domain_id=d.id AND _t.name=?)';
    $_extraParams[] = $_fTag;
}

if ($_dlList) {
    // 用临时表避免 SQLite 999 个绑定参数的限制
    $master->exec("CREATE TEMP TABLE IF NOT EXISTS _tmp_dllist (domain TEXT PRIMARY KEY)");
    $master->exec("DELETE FROM _tmp_dllist");
    $ins = $master->prepare("INSERT OR IGNORE INTO _tmp_dllist VALUES (?)");
    foreach ($_dlList as $_d) { $ins->execute([$_d]); }
    $_extraConds[] = "d.domain IN (SELECT domain FROM _tmp_dllist)";
}

// 排除已过期（today）
if ($_cardExclExp) {
    $_extraConds[]  = "(d.expire_date='' OR d.expire_date IS NULL OR d.expire_date>?)";
    $_extraParams[] = date('Y-m-d');
}
// 过滤 N 天内到期
if ($_cardExclDays > 0) {
    $cutoff = date('Y-m-d', strtotime('+' . $_cardExclDays . ' days'));
    $_extraConds[]  = "(d.expire_date='' OR d.expire_date IS NULL OR d.expire_date>?)";
    $_extraParams[] = $cutoff;
}

$_extraWhere = '';
if ($_extraConds) {
    $_extraWhere = ($whereClause ? ' AND' : ' WHERE') . ' ' . implode(' AND ', $_extraConds);
}
$_hasFilter = !empty($_extraConds);

// ── 分页查询域名列表 ─────────────────────────────────────────
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(10, min(500, (int)($_GET['per_page'] ?? 50)));

$_filteredTotal = $_hasFilter
    ? (int)db_val($master,
        "SELECT COUNT(*) FROM domains d $joinClause $whereClause $_extraWhere",
        array_merge($baseParams, $_extraParams))
    : $total;

$totalPages = max(1, (int)ceil($_filteredTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$domains = db_all($master, "
    SELECT d.*, r.name AS registrar_name, a.username AS account_name
    FROM domains d $joinClause
    LEFT JOIN registrars r ON r.id = d.registrar_id
    LEFT JOIN accounts   a ON a.id = d.account_id
    $whereClause $_extraWhere
    ORDER BY d.expire_date ASC, d.domain ASC
    LIMIT ? OFFSET ?
", array_merge($baseParams, $_extraParams, [$perPage, $offset]));

if ($domains) {
    $ids = array_column($domains, 'id'); $tagMap = [];
    foreach (array_chunk($ids, 999) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        foreach (db_all($master,
            "SELECT dt.domain_id,t.id,t.name,t.color FROM tags t
             JOIN domain_tags dt ON dt.tag_id=t.id WHERE dt.domain_id IN ($ph)
             ORDER BY t.sort_order,t.name", $chunk) as $tr)
            $tagMap[$tr['domain_id']][] = $tr;
    }
    foreach ($domains as &$row) $row['tags'] = $tagMap[$row['id']] ?? [];
    unset($row);
}

$pageTitle = $cardName;
require_once dirname(__DIR__) . '/components/header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-success alert-dismissible fade show py-2">
  <i class="bi bi-check-circle me-2"></i><?= h($msg) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- 面包屑 -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <a href="/cards/" class="text-muted small text-decoration-none">
      <i class="bi bi-collection me-1"></i>域名卡片
    </a>
    <span class="text-muted small mx-1">/</span>
    <span class="fw-semibold"><?= h($cardName) ?></span>
    <?php if ($_showNf): ?>
    <span class="badge bg-secondary ms-2"><i class="bi bi-exclamation-triangle me-1"></i>不存在域名</span>
    <?php endif; ?>
    <?php if ($cardNote): ?>
    <div class="text-muted small mt-1"><?= h($cardNote) ?></div>
    <?php endif; ?>
  </div>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <?php if ($_showNf || $_showDup): ?>
    <a href="<?= h($_baseUrl) ?>" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i>返回域名列表
    </a>
    <?php else: ?>
      <?php if (!empty($notFoundDomains)): ?>
      <a href="<?= h($_baseUrl) ?>&show_nf=1" class="btn btn-sm btn-outline-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>不存在域名 <?= count($notFoundDomains) ?> 个
      </a>
      <?php endif; ?>
      <?php if ($dupCount > 0 && $cardId): ?>
      <a href="<?= h($_baseUrl) ?>&show_dup=1" class="btn btn-sm btn-outline-warning" style="color:#fd7e14;border-color:#fd7e14">
        <i class="bi bi-copy me-1"></i>跨卡重复 <?= $dupCount ?> 个
      </a>
      <?php endif; ?>
      <?php if ($cardId): ?>
      <button class="btn btn-sm btn-outline-success" onclick="toggleAddPanel()">
        <i class="bi bi-plus-circle me-1"></i>追加域名列表
      </button>
      <?php endif; ?>
    <?php endif; ?>

    <?php
      // ── 排除已过期按钮 ──
      $_cardBaseNoExcl = '/cards/detail.php?' . ($type==='unassigned' ? 'type=unassigned' : 'id='.$cardId);
      if ($_cardExclExp) {
          // 已激活 → 点击取消（保留 excl_days）
          $_toggleExclUrl = $_cardBaseNoExcl . ($_cardExclDays > 0 ? '&excl_days='.$_cardExclDays : '');
      } else {
          $_toggleExclUrl = $_baseUrl . '&excl_exp=1';
      }
    ?>
    <a href="<?= h($_toggleExclUrl) ?>"
       class="btn btn-sm <?= $_cardExclExp ? 'btn-danger' : 'btn-outline-danger' ?>"
       title="<?= $_cardExclExp ? '取消排除已过期' : '排除已过期域名' ?>">
      <i class="bi bi-clock-history me-1"></i><?= $_cardExclExp ? '✓ 已排除过期' : '排除已过期' ?>
    </a>

    <?php if ($_cardExclDays > 0): ?>
    <!-- 天数过滤激活态 -->
    <span class="badge bg-danger align-middle py-1 px-2" style="font-size:13px">
      <i class="bi bi-funnel me-1"></i>已过滤<?= $_cardExclDays ?>天内
    </span>
    <?php
      $_clearDaysUrl = $_cardBaseNoExcl . ($_cardExclExp ? '&excl_exp=1' : '');
    ?>
    <a href="<?= h($_clearDaysUrl) ?>" class="btn btn-sm btn-outline-secondary" title="取消天数过滤">
      <i class="bi bi-x-lg"></i>
    </a>
    <?php else: ?>
    <!-- 天数过滤输入 -->
    <form method="GET" action="/cards/detail.php" class="d-flex align-items-center gap-1" style="margin:0">
      <?php if ($cardId): ?><input type="hidden" name="id" value="<?= $cardId ?>"><?php endif; ?>
      <?php if ($type): ?><input type="hidden" name="type" value="<?= h($type) ?>"><?php endif; ?>
      <?php if ($_cardExclExp): ?><input type="hidden" name="excl_exp" value="1"><?php endif; ?>
      <input type="number" name="excl_days" min="1" max="3650"
             class="form-control form-control-sm" style="width:70px"
             placeholder="天数" title="过滤该天数内到期的域名">
      <button type="submit" class="btn btn-sm btn-outline-danger">
        <i class="bi bi-funnel me-1"></i>过滤到期
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- 数字统计卡片 -->
<div class="row g-2 mb-3">
  <?php foreach ([
    ['num'=>(int)($stats['total']??0),      'label'=>'域名总数',  'color'=>'primary','icon'=>'bi-globe2'],
    ['num'=>(int)($stats['cnt_normal']??0), 'label'=>'正常',      'color'=>'success','icon'=>'bi-check-circle'],
    ['num'=>(int)($stats['cnt_paused']??0), 'label'=>'暂停解析',  'color'=>'warning','icon'=>'bi-pause-circle'],
    ['num'=>(int)($stats['cnt_expired']??0),'label'=>'已过期',    'color'=>'danger', 'icon'=>'bi-x-circle'],
    ['num'=>(int)($stats['cnt_soon7']??0),  'label'=>'7天内到期', 'color'=>'danger', 'icon'=>'bi-alarm'],
  ] as $sc): ?>
  <div class="col-6 col-sm-4 col-md-2">
    <div class="stat-card">
      <div class="d-flex align-items-center gap-2 mb-1">
        <i class="bi <?= $sc['icon'] ?> text-<?= $sc['color'] ?>"></i>
        <span class="stat-num text-<?= $sc['color'] ?>"><?= $sc['num'] ?></span>
      </div>
      <div class="stat-label"><?= $sc['label'] ?></div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!empty($notFoundDomains) && $cardId): ?>
  <div class="col-6 col-sm-4 col-md-2">
    <a href="<?= h($_baseUrl) ?>&show_nf=1" class="text-decoration-none">
      <div class="stat-card" style="border-color:#fd7e14;cursor:pointer">
        <div class="d-flex align-items-center gap-2 mb-1">
          <i class="bi bi-exclamation-triangle text-warning"></i>
          <span class="stat-num text-warning"><?= count($notFoundDomains) ?></span>
        </div>
        <div class="stat-label">不存在域名</div>
      </div>
    </a>
  </div>
  <?php endif; ?>
  <?php if ($dupCount > 0 && $cardId): ?>
  <div class="col-6 col-sm-4 col-md-2">
    <a href="<?= h($_baseUrl) ?>&show_dup=1" class="text-decoration-none">
      <div class="stat-card" style="border-color:#fd7e14;cursor:pointer">
        <div class="d-flex align-items-center gap-2 mb-1">
          <i class="bi bi-copy" style="color:#fd7e14"></i>
          <span class="stat-num" style="color:#fd7e14"><?= $dupCount ?></span>
        </div>
        <div class="stat-label">跨卡重复</div>
      </div>
    </a>
  </div>
  <?php endif; ?>
</div>

<!-- ── 聚合汇总面板（带缓存 + 自动刷新 + 手动刷新）─────────── -->
<div class="form-card mb-3">
  <div class="d-flex align-items-center justify-content-between mb-2">
    <div class="section-title mb-0">数据汇总</div>
    <div class="d-flex align-items-center gap-2">
      <span class="text-muted small" id="agg-time-text">加载中…</span>
      <button class="btn btn-sm btn-outline-secondary" id="agg-refresh-btn"
              onclick="loadCardAgg(true)" title="重新计算">
        <span id="agg-spin" class="spinner-border spinner-border-sm d-none me-1" role="status"></span>
        <i class="bi bi-arrow-clockwise"></i> 更新统计
      </button>
    </div>
  </div>
  <div id="agg-body">
    <span class="text-muted small"><i class="bi bi-hourglass-split me-1"></i>统计数据加载中…</span>
  </div>
</div>

<!-- 追加域名面板 -->
<?php if ($cardId): ?>
<div id="addPanel" class="form-card mb-3" style="display:none">
  <div class="d-flex align-items-center justify-content-between mb-2">
    <div class="section-title mb-0">追加域名列表到此卡片</div>
    <small class="text-muted">已存在的域名会自动跳过（不重复添加）</small>
  </div>
  <form method="POST">
    <input type="hidden" name="action" value="add_domains">
    <textarea name="domains" class="form-control font-monospace mb-1" rows="6"
              placeholder="每行输入一个域名，例如：&#10;example.com&#10;test.cn" id="addPanelTA"></textarea>
    <div class="text-muted small mb-2" id="addPanelCount">0 行</div>
    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-success btn-sm">
        <i class="bi bi-plus-circle me-1"></i>确认追加
      </button>
      <button type="button" class="btn btn-outline-secondary btn-sm"
              onclick="document.getElementById('addPanel').style.display='none'">取消</button>
    </div>
  </form>
</div>
<script>
(function(){
  var ta = document.getElementById('addPanelTA');
  var lc = document.getElementById('addPanelCount');
  if (ta && lc) {
    ta.addEventListener('input', function() {
      var lines = ta.value.split('\n').filter(function(l){ return l.trim() !== ''; });
      lc.textContent = lines.length + ' 行';
    });
  }
})();
</script>
<?php endif; ?>

<!-- 卡片内域名查找面板 -->
<div class="mb-3">
  <div class="d-flex align-items-center gap-2 mb-2">
    <button class="btn btn-sm <?= $_dlActive ? 'btn-warning' : 'btn-outline-secondary' ?>"
            onclick="toggleCardDlPanel()">
      <i class="bi bi-search me-1"></i>
      <?= $_dlActive
          ? '已查找 ' . count($_dlList) . ' 个域名 · 点击修改'
          : '输入域名列表查找' ?>
    </button>
    <?php if ($_dlActive): ?>
    <a href="<?= h($_baseUrl . '&dl_clear=1') ?>" class="btn btn-sm btn-outline-danger">
      <i class="bi bi-x-lg me-1"></i>清除查找
    </a>
    <span class="text-muted small">
      找到 <strong><?= $_filteredTotal ?></strong> / <?= $total ?> 个
    </span>
    <?php endif; ?>
  </div>
  <div id="cardDlPanel" style="display:<?= $_dlActive ? 'block' : 'none' ?>">
    <form method="POST" action="<?= h($_baseUrl) ?>">
      <input type="hidden" name="_action" value="card_dl_search">
      <?php if ($_dlActive): ?>
      <input type="hidden" name="dl" value="1">
      <?php endif; ?>
      <div class="card border-0 shadow-sm">
        <div class="card-body py-2 px-3">
          <div class="row g-2 align-items-start">
            <div class="col">
              <textarea name="domain_input" class="form-control form-control-sm font-monospace"
                        rows="5" id="cardDlInput"
                        placeholder="每行输入一个域名，例如：&#10;example.com&#10;test.cn"
                        ><?= h(implode("\n", $_dlList)) ?></textarea>
              <div class="text-muted small mt-1" id="cardDlCount"><?= count($_dlList) ?> 行</div>
            </div>
            <div class="col-auto d-flex flex-column gap-2">
              <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-search me-1"></i>查找
              </button>
              <button type="button" class="btn btn-outline-secondary btn-sm"
                      onclick="document.getElementById('cardDlInput').value='';document.getElementById('cardDlCount').textContent='0 行'">
                清空
              </button>
            </div>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- 活跃过滤标记 -->
<?php if ($_activeFilters): ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <span class="text-muted small">当前过滤：</span>
  <?php foreach ($_activeFilters as $param => $val): ?>
  <a href="?<?= h(http_build_query(array_merge(
      $type === 'unassigned' ? ['type' => 'unassigned'] : ['id' => $cardId],
      array_diff_key($_activeFilters, [$param => 1]),
      $_dlActive ? ['dl' => '1'] : []
  ))) ?>"
     class="badge bg-primary text-decoration-none d-flex align-items-center gap-1"
     style="font-size:12px">
    <?= h(($_filterLabels[$param] ?? $param) . ': ' . ($val === '__EMPTY__' ? '【空】' : ($val ?: '(空)'))) ?>
    <i class="bi bi-x"></i>
  </a>
  <?php endforeach; ?>
  <a href="?<?= h(http_build_query(array_merge(
      $type === 'unassigned' ? ['type' => 'unassigned'] : ['id' => $cardId],
      $_dlActive ? ['dl' => '1'] : []
  ))) ?>" class="btn btn-sm btn-outline-danger py-0">
    <i class="bi bi-x-lg me-1"></i>清除全部过滤
  </a>
</div>
<?php endif; ?>

<!-- 域名表格 -->
<div class="table-card">
  <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
    <span class="text-muted small">
      共 <strong><?= $_filteredTotal ?></strong><?= $_hasFilter ? '（过滤后）/ '.$total.' ' : ' ' ?>个域名
      <?php if ($totalPages > 1): ?>· 第 <?= $page ?>/<?= $totalPages ?> 页<?php endif; ?>
    </span>
    <div class="d-flex align-items-center gap-2">
      <button class="btn btn-sm btn-outline-secondary" onclick="copyCardDomains(this)"
              title="复制当前筛选结果的域名列表">
        <i class="bi bi-clipboard"></i> 复制域名
      </button>
      <a href="<?= htmlspecialchars('/cards/api/export.php?' . http_build_query(array_merge(
          $type === 'unassigned' ? ['type'=>'unassigned'] : ['id'=>$cardId],
          ['action'=>'csv'],
          array_filter(['f_status'=>$_fStatus,'f_icp'=>$_fIcp,'f_dns'=>$_fDns,'f_reg'=>$_fReg,'f_acc'=>$_fAcc]),
          $_dlActive ? ['dl'=>'1'] : []
      ))) ?>" class="btn btn-sm btn-outline-success" title="导出 CSV">
        <i class="bi bi-download"></i> 导出
      </a>
      <span class="text-muted small">每页</span>
      <input type="number" id="perPageInput" class="form-control form-control-sm text-center"
             style="width:70px" min="10" max="500" value="<?= $perPage ?>"
             onkeydown="if(event.key==='Enter')setPerPage(this.value)">
      <span class="text-muted small">条</span>
      <button class="btn btn-sm btn-outline-secondary"
              onclick="setPerPage(document.getElementById('perPageInput').value)">✓</button>
    </div>
  </div>

  <?php if ($_showDup): ?>
  <!-- 跨卡重复域名列表 -->
  <?php if ($dupDomains): ?>
  <div class="alert alert-warning py-2 mb-3">
    <i class="bi bi-copy me-1"></i>
    以下 <?= $dupCount ?> 个域名同时存在于本卡片和其他卡片中，请确认是否需要整理。
    <button class="btn btn-sm btn-outline-secondary ms-3 py-0"
            onclick="copyDupDomains()" title="复制全部跨卡重复域名">
      <i class="bi bi-clipboard me-1"></i>复制域名
    </button>
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead class="table-light">
        <tr><th>#</th><th>域名</th><th>同时存在于</th></tr>
      </thead>
      <tbody>
      <?php $dupIdx = 0; foreach ($dupDomains as $dupDom => $otherCards): $dupIdx++; ?>
      <tr>
        <td class="text-muted"><?= $dupIdx ?></td>
        <td><a href="/domains/detail.php?id=<?= h(db_val($master,'SELECT id FROM domains WHERE domain=?',[$dupDom])??0) ?>"
               class="domain-name"><?= h($dupDom) ?></a></td>
        <td>
          <?php foreach (array_unique($otherCards) as $oc): ?>
          <span class="badge bg-light text-dark border me-1"><?= h($oc) ?></span>
          <?php endforeach; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <script>
  function copyDupDomains() {
    var domains = <?= json_encode(array_keys($dupDomains), JSON_UNESCAPED_UNICODE) ?>;
    var text = domains.join('\n');
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
    document.body.appendChild(ta);
    ta.focus(); ta.select();
    try {
      document.execCommand('copy');
      alert('已复制 ' + domains.length + ' 个跨卡重复域名');
    } catch(e) {
      alert('复制失败，请手动复制');
    }
    document.body.removeChild(ta);
  }
  </script>
  <?php else: ?>
  <div class="text-center text-muted py-5">
    <i class="bi bi-check-circle fs-1 d-block mb-3 text-success opacity-50"></i>
    <p>此卡片中没有跨卡重复域名</p>
  </div>
  <?php endif; ?>

  <?php elseif ($_showNf && !empty($notFoundDomains)): ?>
  <!-- 不存在域名列表 -->
  <div class="alert alert-warning py-2 mb-3">
    <i class="bi bi-info-circle me-1"></i>
    以下 <?= count($notFoundDomains) ?> 个域名在系统导入列表中不存在，仅供参考。
    如已导入，可重新添加到卡片使其从此列表移除。
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead class="table-light"><tr><th>#</th><th>域名</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($notFoundDomains as $nfi => $nfd): ?>
      <tr>
        <td class="text-muted"><?= $nfi + 1 ?></td>
        <td><?= h($nfd) ?></td>
        <td>
          <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="remove_nf">
            <input type="hidden" name="nf_domain" value="<?= h($nfd) ?>">
            <button class="btn btn-sm btn-outline-danger py-0 px-1"
                    onclick="return confirm('从不存在列表中移除 <?= addslashes(h($nfd)) ?>？')"
                    title="从不存在列表移除">✕</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>

  <?= _pager($page, $totalPages, $perPage, $cardId, $type) ?>

  <?php if ($cardId && $domains): ?>
  <!-- 批量操作工具栏 -->
  <form method="POST" id="batchRemoveForm">
    <input type="hidden" name="action" value="batch_remove">
    <div class="d-flex align-items-center gap-2 mb-2" id="batchBar" style="display:none!important">
      <span class="text-muted small" id="batchCount">已选 0 个</span>
      <button type="submit" class="btn btn-sm btn-danger" id="batchRemoveBtn" disabled
              onclick="return confirm('确定从卡片移除已选的域名吗？域名本身不受影响。')">
        <i class="bi bi-trash me-1"></i>批量移除
      </button>
      <button type="button" class="btn btn-sm btn-outline-secondary"
              onclick="clearBatchSelect()">取消选择</button>
    </div>
  <?php endif; ?>

  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0" id="cardDomainTable">
      <thead class="table-light">
        <tr>
          <?php if ($cardId): ?>
          <th width="32"><input type="checkbox" id="selectAll" title="全选/取消" onchange="toggleSelectAll(this)"></th>
          <?php endif; ?>
          <th>域名</th><th>注册商</th><th>账号</th><th>状态</th><th>备案</th>
          <th>过期时间</th><th>DNS</th><th>标签</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($domains): foreach ($domains as $d): ?>
        <tr>
          <?php if ($cardId): ?>
          <td>
            <input type="checkbox" name="domain_ids[]" value="<?= $d['id'] ?>"
                   class="domain-cb" form="batchRemoveForm" onchange="onCbChange()">
          </td>
          <?php endif; ?>
          <td><a href="/domains/detail.php?id=<?= $d['id'] ?>" class="domain-name"><?= h($d['domain']) ?></a></td>
          <td class="small text-muted"><?= h($d['registrar_name'] ?? '-') ?></td>
          <td class="small text-muted"><?= h($d['account_name'] ?? '-') ?></td>
          <td>
            <?php $st=$d['status']??''; ?>
            <?php if ($st==='paused'): ?><span class="badge bg-warning text-dark">暂停</span>
            <?php elseif ($st==='normal'): ?><span class="badge bg-success">正常</span>
            <?php elseif ($st): ?><span class="badge bg-secondary"><?= h($st) ?></span>
            <?php endif; ?>
          </td>
          <td class="small text-muted"><?= h($d['icp_type']??'') ?: '-' ?></td>
          <td class="small <?= getDomainExpireClass($d['expire_date']??'') ?>">
            <?= h($d['expire_date']??'') ?: '-' ?><?= getDomainExpireBadge($d['expire_date']??'') ?>
          </td>
          <td class="small text-muted"><?= h($d['dns_servers']??'') ?: '-' ?></td>
          <td><?php foreach ($d['tags'] as $tag): ?>
            <span class="tag-pill" style="background:<?= h($tag['color']) ?>"><?= h($tag['name']) ?></span>
          <?php endforeach; ?></td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="<?= $cardId?9:8 ?>" class="text-center text-muted py-4">暂无域名</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($cardId && $domains): ?>
  </form>
  <?php endif; ?>
  <?= _pager($page, $totalPages, $perPage, $cardId, $type) ?>
  <?php endif; // end else (!$_showNf && !$_showDup) ?>
</div>

<script>
// ── 初始聚合数据（服务器端从缓存读取，供立即渲染）──────────
var _initAgg = <?= $initAgg ? json_encode($initAgg, JSON_UNESCAPED_UNICODE) : 'null' ?>;
var _aggUrl  = '/cards/api/agg.php?<?= $aggQs ?>';
var _aggTimer = null;
var _activeFilters = <?= json_encode($_activeFilters, JSON_UNESCAPED_UNICODE) ?>;

// 字段 key → URL 参数名映射
var _paramMap = {
  'status':    'f_status',
  'icp_type':  'f_icp',
  'dns':       'f_dns',
  'registrar': 'f_reg',
  'account':   'f_acc',
  'tags':      'f_tag',
};

// 切换过滤参数（可叠加多个字段过滤）
function toggleFilter(param, val) {
  var url = new URLSearchParams(window.location.search);
  if (url.get(param) === val) {
    url.delete(param);
  } else {
    url.set(param, val);
  }
  url.delete('page');
  location.href = '?' + url.toString();
}

// 渲染聚合数据到 DOM（支持固定字段 + 自定义字段 cf_*）
function renderCardAgg(data) {
  if (!data || !data.groups) return;
  var groups = data.groups;
  var fixedDefs = [
    { key: 'status',    label: '状态',   isTag: false },
    { key: 'icp_type',  label: '备案',   isTag: false },
    { key: 'dns',       label: 'DNS',    isTag: false },
    { key: 'registrar', label: '注册商', isTag: false },
    { key: 'account',   label: '账号',   isTag: false },
    { key: 'tags',      label: '标签',   isTag: true  },
  ];

  function renderGroupBlock(label, rows, isTag, groupKey) {
    if (!rows || !rows.length) return '';
    var param = groupKey ? (_paramMap[groupKey] || null) : null;
    var curVal = param ? (_activeFilters[param] || null) : null;
    var b = '<div class="col-12 col-md-6 col-xl-4">';
    b += '<div class="fw-semibold small text-muted mb-1">' + _esc(label) + '</div>';
    b += '<div class="d-flex flex-wrap gap-1">';
    rows.forEach(function(row) {
      var val = row.val !== undefined ? String(row.val) : '';
      var cnt = row.cnt || 0;
      var isActive = param && curVal === val;
      var activeClass = isActive ? ' ci-pill-active' : '';
      var clickable = param && !row._has;  // _has 的自定义字段不支持点击过滤
      var clickAttr = clickable
        ? ' role="button" onclick="toggleFilter(\'' + param + '\',\'' + val.replace(/'/g, "\\'") + '\')" style="cursor:pointer"'
        : '';
      if (row._empty) {
        // 空值 pill 用 __EMPTY__ 哨兵，确保 PHP 能识别并过滤
        var emptyActive = param && (_activeFilters[param] || '') === '__EMPTY__';
        var emptyClick = param
          ? ' role="button" onclick="toggleFilter(\'' + param + '\',\'__EMPTY__\')" style="cursor:pointer"'
          : '';
        b += '<span class="ci-pill ci-pill-empty' + (emptyActive ? ' ci-pill-active' : '') + '"' + emptyClick + '>【空】 <span class="ci-cnt">数量: ' + cnt + '</span>'
           + (emptyActive ? ' ✕' : '') + '</span>';
      } else if (row._has) {
        b += '<span class="ci-pill ci-pill-muted">【有内容】 <span class="ci-cnt">数量: ' + cnt + '</span></span>';
      } else if (isTag) {
        var color = row.color || '#adb5bd';
        b += '<span class="ci-pill ci-pill-tag' + activeClass + '" style="background:' + color + '"' + clickAttr + '>【' + _esc(val) + '】 <span class="ci-cnt">数量: ' + cnt + '</span></span>';
      } else {
        b += '<span class="ci-pill' + activeClass + '"' + clickAttr + '>【' + _esc(val || '-') + '】 <span class="ci-cnt">数量: ' + cnt + '</span></span>';
      }
    });
    b += '</div></div>';
    return b;
  }

  var html = '<div class="row g-3">';
  fixedDefs.forEach(function(gd) {
    html += renderGroupBlock(gd.label, groups[gd.key], gd.isTag, gd.key);
  });
  // 自定义字段（cf_* 开头的 key，不支持点击过滤）
  Object.keys(groups).forEach(function(key) {
    if (key.indexOf('cf_') !== 0) return;
    var g = groups[key];
    html += renderGroupBlock(g.label || key, g.rows, false, null);
  });
  html += '</div>';

  document.getElementById('agg-body').innerHTML = html;
  document.getElementById('agg-time-text').textContent =
    '统计时间: ' + (data.generated_at || '未知')
    + (data.cached ? '' : ' (实时)');
}

function toggleCardDlPanel() {
  var p = document.getElementById('cardDlPanel');
  p.style.display = p.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('DOMContentLoaded', function() {
  var ta = document.getElementById('cardDlInput');
  var lc = document.getElementById('cardDlCount');
  if (ta && lc) {
    ta.addEventListener('input', function() {
      var n = this.value.split('\n').filter(function(l){ return l.trim(); }).length;
      lc.textContent = n + ' 行';
    });
  }
});
function _esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// 加载聚合（forceRefresh=true 时强制重算）
function loadCardAgg(forceRefresh) {
  var btn  = document.getElementById('agg-refresh-btn');
  var spin = document.getElementById('agg-spin');
  btn.disabled = true;
  spin.classList.remove('d-none');

  fetch(_aggUrl, { method: forceRefresh ? 'POST' : 'GET' })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data && data.groups) {
        renderCardAgg(data);
      } else if (!forceRefresh) {
        // GET 无缓存 → 自动触发计算
        loadCardAgg(true);
        return;
      }
    })
    .catch(function() {
      document.getElementById('agg-time-text').textContent = '加载失败，请重试';
    })
    .finally(function() {
      btn.disabled = false;
      spin.classList.add('d-none');
    });
}

// 初始化：有新鲜缓存直接渲染；无缓存或缓存已过期则立即自动计算
document.addEventListener('DOMContentLoaded', function() {
  if (_initAgg && _initAgg.groups) {
    renderCardAgg(_initAgg);  // 有新鲜缓存，直接渲染
  } else {
    loadCardAgg(true); // 无新鲜缓存 → 立即自动计算
  }

  // 每 5 分钟自动后台刷新
  _aggTimer = setInterval(function() { loadCardAgg(true); }, 5 * 60 * 1000);
});

function toggleAddPanel() {
  var p = document.getElementById('addPanel');
  if (p) p.style.display = p.style.display === 'none' ? '' : 'none';
}

// ── 批量多选控制 ─────────────────────────────────────────────
function onCbChange() {
  var cbs    = document.querySelectorAll('.domain-cb');
  var checked = document.querySelectorAll('.domain-cb:checked');
  var bar    = document.getElementById('batchBar');
  var btn    = document.getElementById('batchRemoveBtn');
  var cnt    = document.getElementById('batchCount');
  var all    = document.getElementById('selectAll');
  if (!bar) return;
  var n = checked.length;
  bar.style.display   = n > 0 ? 'flex' : 'none';
  btn.disabled        = n === 0;
  cnt.textContent     = '已选 ' + n + ' 个';
  if (all) {
    all.indeterminate = n > 0 && n < cbs.length;
    all.checked       = n === cbs.length && cbs.length > 0;
  }
}
function toggleSelectAll(el) {
  var cbs = document.querySelectorAll('.domain-cb');
  cbs.forEach(function(cb) { cb.checked = el.checked; });
  onCbChange();
}
function clearBatchSelect() {
  document.querySelectorAll('.domain-cb').forEach(function(cb){ cb.checked = false; });
  var all = document.getElementById('selectAll');
  if (all) { all.checked = false; all.indeterminate = false; }
  onCbChange();
}

function setPerPage(n) {
  n = parseInt(n, 10);
  if (!n || n < 10) n = 10;
  if (n > 500) n = 500;
  var url = new URLSearchParams(window.location.search);
  url.set('per_page', n); url.delete('page');
  location.href = '?' + url.toString();
}

// ── 复制域名 ───────────────────────────────────────────────────
function _clipboardCopy(text) {
  if (navigator.clipboard && navigator.clipboard.writeText) {
    return navigator.clipboard.writeText(text);
  }
  return new Promise(function(resolve, reject) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;width:1px;height:1px';
    document.body.appendChild(ta);
    ta.focus(); ta.select();
    try { document.execCommand('copy') ? resolve() : reject(); }
    catch(e) { reject(e); }
    document.body.removeChild(ta);
  });
}

function copyCardDomains(btn) {
  var qs  = window.location.search;
  var url = '/cards/api/export.php?action=copy' + (qs ? '&' + qs.slice(1) : '');
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> 获取中…';
  fetch(url).then(function(r) {
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.text();
  }).then(function(text) {
    var lines = text.split('\n').filter(function(l){ return l.trim(); });
    _clipboardCopy(text).then(function() {
      btn.innerHTML = '<i class="bi bi-check2"></i> 已复制 (' + lines.length + ' 个)';
      setTimeout(function(){ btn.innerHTML = '<i class="bi bi-clipboard"></i> 复制域名'; btn.disabled = false; }, 2500);
    }).catch(function() {
      prompt('请手动复制以下域名列表（Ctrl+A 全选后 Ctrl+C）：', text);
      btn.innerHTML = '<i class="bi bi-clipboard"></i> 复制域名'; btn.disabled = false;
    });
  }).catch(function(e) {
    alert('获取域名列表失败：' + e.message);
    btn.innerHTML = '<i class="bi bi-clipboard"></i> 复制域名'; btn.disabled = false;
  });
}
</script>

<?php
function _pager(int $page, int $totalPages, int $perPage, int $cardId, string $type): string {
    if ($totalPages <= 1) return '';
    $base = $cardId ? "?id={$cardId}&per_page={$perPage}" : "?type={$type}&per_page={$perPage}";
    $html = '<div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">';
    $html .= '<span class="text-muted small">第 '.$page.' / '.$totalPages.' 页</span><nav><ul class="pagination pagination-sm mb-0">';
    if ($page > 1)
        $html .= '<li class="page-item"><a class="page-link" href="'.$base.'&page=1">«</a></li>';
    for ($i=max(1,$page-3); $i<=min($totalPages,$page+3); $i++) {
        $active = $i===$page ? ' active' : '';
        $html  .= '<li class="page-item'.$active.'"><a class="page-link" href="'.$base.'&page='.$i.'">'.$i.'</a></li>';
    }
    if ($page < $totalPages)
        $html .= '<li class="page-item"><a class="page-link" href="'.$base.'&page='.$totalPages.'">»</a></li>';
    $html .= '</ul></nav></div>';
    return $html;
}
?>

<style>
.ci-pill {
  display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:4px;
  font-size:12px;border:1px solid #dee2e6;background:#f8f9fa;color:#343a40;line-height:1.5;
  transition:background .15s,border-color .15s;
}
.ci-pill[role=button]:hover { background:#e9ecef;border-color:#adb5bd; }
.ci-pill-active { background:#0d6efd!important;color:#fff!important;border-color:#0d6efd!important; }
.ci-pill-active .ci-cnt { opacity:.85; }
.ci-pill-empty  { color:#6c757d;border-color:#dee2e6;cursor:pointer; }
.ci-pill-empty:hover { background:#e9ecef;border-color:#adb5bd; }
.ci-pill-muted  { color:#198754;border-color:#d1e7dd; }
.ci-pill-tag    { border-radius:20px!important;color:#fff;border:none!important; }
.ci-cnt { font-size:11px;opacity:.75;white-space:nowrap; }
</style>
<?php require_once dirname(__DIR__) . '/components/footer.php'; ?>
