<?php
/**
 * 卡片聚合统计 API
 * GET  ?id=X 或 ?type=unassigned              → 返回缓存（若有且<5分钟）
 * GET  ?id=X&dl=1&sess_key=card_dl_X          → 实时按域名列表过滤，不走缓存
 * POST ?id=X 或 ?type=unassigned              → 强制重新计算并更新缓存
 */
if (!isset($_SESSION)) @session_start();
require_once dirname(dirname(__DIR__)) . '/core/functions.php';
header('Content-Type: application/json; charset=utf-8');

$master   = getMasterDB();
$cardId   = (int)($_REQUEST['id'] ?? 0);
$type     = trim($_REQUEST['type'] ?? '');
$cacheDir = DATA_DIR . '/agg';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

// ── 域名列表过滤（dl=1 时从 session 读取，绕过缓存）──────────
$dlActive = !empty($_REQUEST['dl']);
$dlList   = [];
if ($dlActive) {
    $sessKey = trim($_REQUEST['sess_key'] ?? '');
    if ($sessKey && !empty($_SESSION[$sessKey])) {
        $dlList = $_SESSION[$sessKey];
    }
}
$bypassCache = $dlActive && !empty($dlList);

// ── 确定缓存文件 ──────────────────────────────────────────────
if ($type === 'unassigned') {
    $cacheFile = $cacheDir . '/card_unassigned.json';
    $joinClause  = '';
    $whereClause = "WHERE d.id NOT IN (SELECT domain_id FROM domain_card_items)";
    $baseParams  = [];
} elseif ($cardId) {
    $card = db_one($master, "SELECT id FROM domain_cards WHERE id=?", [$cardId]);
    if (!$card) { echo json_encode(['ok' => false, 'msg' => '卡片不存在']); exit; }
    $cacheFile   = $cacheDir . '/card_' . $cardId . '.json';
    $joinClause  = "JOIN domain_card_items dci ON dci.domain_id = d.id AND dci.card_id = ?";
    $whereClause = '';
    $baseParams  = [$cardId];
} else {
    echo json_encode(['ok' => false, 'msg' => '缺少参数']); exit;
}

// ── 域名列表临时表条件（注意：无额外参数）────────────────────
$dlCond = '';
if ($bypassCache) {
    $master->exec("CREATE TEMP TABLE IF NOT EXISTS _cdl_filter (domain TEXT PRIMARY KEY)");
    $master->exec("DELETE FROM _cdl_filter");
    foreach (array_chunk($dlList, 100) as $chunk) {
        $ph   = implode(',', array_fill(0, count($chunk), '(?)'));
        $stmt = $master->prepare("INSERT OR IGNORE INTO _cdl_filter(domain) VALUES $ph");
        $stmt->execute($chunk);
    }
    $dlCond = ' AND d.domain IN (SELECT domain FROM _cdl_filter)';
}

$CACHE_TTL = 300; // 5 分钟

// GET：有新鲜缓存就直接返回（dl 模式跳过缓存，实时计算）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!$bypassCache && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $CACHE_TTL) {
        readfile($cacheFile);
        exit;
    }
    if ($bypassCache) {
        // 实时计算（dl 模式），下方代码继续执行
    } else {
        // 缓存不存在或过期 → 告知前端需要计算
        echo json_encode(['cached' => false]);
        exit;
    }
}

// POST：强制重新计算
$today = date('Y-m-d');
$warn7 = date('Y-m-d', strtotime('+7 days'));

// 辅助：GROUP BY 某列（支持 $dlCond 追加条件）
function _aggCol(PDO $db, string $col, string $join, string $where, array $params, string $dlCond = ''): array {
    $cond = $where ? "AND $col != '' AND $col IS NOT NULL"
                   : "WHERE $col != '' AND $col IS NOT NULL";
    $rows = db_all($db,
        "SELECT $col AS val, COUNT(*) AS cnt FROM domains d $join $where $dlCond
         $cond GROUP BY $col ORDER BY cnt DESC", $params) ?: [];
    $emp = (int)db_val($db,
        "SELECT COUNT(*) FROM domains d $join $where $dlCond
         " . ($where ? "AND" : "WHERE") . " ($col IS NULL OR $col = '')", $params);
    if ($emp > 0) $rows[] = ['val' => '', 'cnt' => $emp, '_empty' => true];
    return $rows;
}

$groups = [
    'status'    => _aggCol($master, 'd.status',      $joinClause, $whereClause, $baseParams, $dlCond),
    'icp_type'  => _aggCol($master, 'd.icp_type',    $joinClause, $whereClause, $baseParams, $dlCond),
    'dns'       => _aggCol($master, 'd.dns_servers', $joinClause, $whereClause, $baseParams, $dlCond),
];

// WHERE/AND 拼接辅助（WHERE已有时用AND，否则用WHERE）
$_wAnd = $whereClause ? "$whereClause $dlCond" : ($dlCond ? "WHERE 1=1 $dlCond" : '');

// 注册商（独立）
$regRows = db_all($master,
    "SELECT COALESCE(r.name,'') AS val, COUNT(*) AS cnt
     FROM domains d $joinClause
     LEFT JOIN registrars r ON r.id = d.registrar_id
     $_wAnd
     GROUP BY d.registrar_id ORDER BY cnt DESC", $baseParams) ?: [];
foreach ($regRows as &$rr) { if ($rr['val'] === '') $rr['_empty'] = true; }
unset($rr);
$groups['registrar'] = $regRows;

// 账号（独立）
$accRows = db_all($master,
    "SELECT COALESCE(a.username,'') AS val, COUNT(*) AS cnt
     FROM domains d $joinClause
     LEFT JOIN accounts a ON a.id = d.account_id
     $_wAnd
     GROUP BY d.account_id ORDER BY cnt DESC", $baseParams) ?: [];
foreach ($accRows as &$ar) { if ($ar['val'] === '') $ar['_empty'] = true; }
unset($ar);
$groups['account'] = $accRows;


// 自定义字段（PHP 侧解析 JSON，不依赖 json_extract）
$cfFields = db_all($master, "SELECT * FROM custom_fields WHERE show_in_list=1 ORDER BY sort_order, id") ?: [];
foreach ($cfFields as $cf) {
    $cfRows = [];
    // 拉取该卡片内所有域名的 custom_data
    $allCd = db_all($master,
        "SELECT custom_data FROM domains d $joinClause $_wAnd",
        $baseParams) ?: [];

    $valueCounts = []; $emptyCnt = 0;
    foreach ($allCd as $row) {
        $cd  = json_decode($row['custom_data'] ?? '{}', true) ?: [];
        $val = isset($cd[$cf['name']]) ? trim((string)$cd[$cf['name']]) : '';
        if ($val === '') { $emptyCnt++; }
        else             { $valueCounts[$val] = ($valueCounts[$val] ?? 0) + 1; }
    }

    if ($cf['field_type'] === 'textarea') {
        $hasCnt = array_sum($valueCounts);
        if ($hasCnt  > 0) $cfRows[] = ['val' => '__has__', 'cnt' => $hasCnt,  '_has'   => true];
        if ($emptyCnt > 0) $cfRows[] = ['val' => '',        'cnt' => $emptyCnt, '_empty' => true];
    } else {
        arsort($valueCounts);
        foreach (array_slice($valueCounts, 0, 50, true) as $v => $c) {
            $cfRows[] = ['val' => $v, 'cnt' => $c];
        }
        if ($emptyCnt > 0) $cfRows[] = ['val' => '', 'cnt' => $emptyCnt, '_empty' => true];
    }
    $groups[$cf['name']] = [
        'label'   => $cf['label'],
        'cf_name' => $cf['name'],
        'rows'    => $cfRows,
    ];
}

$payload = [
    'ok'           => true,
    'cached'       => !$bypassCache,
    'generated_at' => date('Y-m-d H:i:s'),
    'groups'       => $groups,
];
$json = json_encode($payload, JSON_UNESCAPED_UNICODE);
// dl 过滤模式下不写缓存（结果依赖 session，组合无限）
if (!$bypassCache) {
    file_put_contents($cacheFile, $json);
}
echo $json;
