<?php
/**
 * 卡片域名导出/复制 API
 * action=copy → text/plain，每行一个域名
 * action=csv  → 下载 CSV
 */
ob_start();
require_once dirname(dirname(__DIR__)) . '/core/functions.php';
ob_end_clean();

if (!isset($_SESSION)) @session_start();

$master  = getMasterDB();
$cardId  = (int)($_GET['id'] ?? 0);
$type    = trim($_GET['type'] ?? '');
$action  = $_GET['action'] ?? 'copy';
$showNf  = !empty($_GET['show_nf']);

// ── show_nf=1：直接返回不存在域名列表 ────────────────────────
if ($showNf && $cardId) {
    $row = db_one($master, "SELECT not_found_domains FROM domain_cards WHERE id=?", [$cardId]);
    $nfList = json_decode($row['not_found_domains'] ?? '[]', true) ?: [];
    if ($action === 'copy') {
        header('Content-Type: text/plain; charset=utf-8');
        echo implode("\n", $nfList);
        exit;
    }
    // CSV
    $filename = 'card_' . $cardId . '_notfound_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['不存在域名']);
    foreach ($nfList as $d) { fputcsv($out, [$d]); }
    fclose($out);
    exit;
}

// ── 构建基础 JOIN/WHERE ────────────────────────────────────────
if ($type === 'unassigned') {
    $joinClause  = '';
    $whereClause = "WHERE d.id NOT IN (SELECT domain_id FROM domain_card_items)";
    $baseParams  = [];
} elseif ($cardId) {
    $joinClause  = "JOIN domain_card_items dci ON dci.domain_id = d.id AND dci.card_id = ?";
    $whereClause = '';
    $baseParams  = [$cardId];
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo '';
    exit;
}

// ── 解析过滤参数 ────────────────────────────────────────────────
$_fStatus = trim($_GET['f_status'] ?? '');
$_fIcp    = trim($_GET['f_icp']    ?? '');
$_fDns    = trim($_GET['f_dns']    ?? '');
$_fReg    = trim($_GET['f_reg']    ?? '');
$_fAcc    = trim($_GET['f_acc']    ?? '');

$_extraConds  = [];
$_extraParams = [];

if ($_fStatus === '__EMPTY__') { $_extraConds[] = "(d.status='' OR d.status IS NULL)"; }
elseif ($_fStatus) { $_extraConds[] = 'd.status = ?';   $_extraParams[] = $_fStatus; }

if ($_fIcp === '__EMPTY__') { $_extraConds[] = "(d.icp_type='' OR d.icp_type IS NULL)"; }
elseif ($_fIcp) { $_extraConds[] = 'd.icp_type = ?';    $_extraParams[] = $_fIcp; }

if ($_fDns === '__EMPTY__') { $_extraConds[] = "(d.dns_servers='' OR d.dns_servers IS NULL)"; }
elseif ($_fDns) { $_extraConds[] = 'd.dns_servers = ?'; $_extraParams[] = $_fDns; }

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

// 域名列表搜索（session）
$_cardKey = $type === 'unassigned' ? 'unassigned' : (string)$cardId;
$_sessKey = 'card_dl_' . $_cardKey;
$_dlActive = !empty($_GET['dl']) && !empty($_SESSION[$_sessKey]);
$_dlList   = $_dlActive ? $_SESSION[$_sessKey] : [];
if ($_dlList) {
    $chunks = array_chunk($_dlList, 990);
    $orParts = [];
    foreach ($chunks as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $orParts[] = "d.domain IN ($ph)";
        $_extraParams = array_merge($_extraParams, $chunk);
    }
    $_extraConds[] = '(' . implode(' OR ', $orParts) . ')';
}

// 排除已过期（excl_exp=1）
if (!empty($_GET['excl_exp'])) {
    $_extraConds[]  = "(d.expire_date='' OR d.expire_date IS NULL OR d.expire_date>?)";
    $_extraParams[] = date('Y-m-d');
}
// 过滤 N 天内到期（excl_days=N）
$_exclDays = max(0, (int)($_GET['excl_days'] ?? 0));
if ($_exclDays > 0) {
    $cutoff = date('Y-m-d', strtotime('+' . $_exclDays . ' days'));
    $_extraConds[]  = "(d.expire_date='' OR d.expire_date IS NULL OR d.expire_date>?)";
    $_extraParams[] = $cutoff;
}

$_extraWhere = '';
if ($_extraConds) {
    $_extraWhere = ($whereClause ? ' AND' : ' WHERE') . ' ' . implode(' AND ', $_extraConds);
}

// ── 拉取域名 ─────────────────────────────────────────────────
$domains = db_all($master,
    "SELECT d.domain, d.expire_date, r.name AS registrar_name, a.username AS account_name,
            d.status, d.icp_type, d.dns_servers
     FROM domains d $joinClause
     LEFT JOIN registrars r ON r.id = d.registrar_id
     LEFT JOIN accounts   a ON a.id = d.account_id
     $whereClause $_extraWhere
     ORDER BY d.expire_date ASC, d.domain ASC",
    array_merge($baseParams, $_extraParams)
);

// ── 输出 ─────────────────────────────────────────────────────
if ($action === 'copy') {
    header('Content-Type: text/plain; charset=utf-8');
    foreach ($domains as $d) {
        echo $d['domain'] . "\n";
    }
    exit;
}

// action=csv
$filename = ($type === 'unassigned' ? 'unassigned' : 'card_' . $cardId) . '_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache');
echo "\xEF\xBB\xBF"; // UTF-8 BOM

$out = fopen('php://output', 'w');
fputcsv($out, ['域名', '注册商', '账号', '状态', '备案', 'DNS', '过期时间']);
foreach ($domains as $d) {
    fputcsv($out, [
        $d['domain']          ?? '',
        $d['registrar_name']  ?? '',
        $d['account_name']    ?? '',
        $d['status']          ?? '',
        $d['icp_type']        ?? '',
        $d['dns_servers']     ?? '',
        $d['expire_date']     ?? '',
    ]);
}
fclose($out);
exit;
