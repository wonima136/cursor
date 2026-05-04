<?php
/**
 * 导出/复制当前筛选结果的域名
 * action=copy  → text/plain，每行一个域名
 * action=csv   → 下载 CSV（含所有字段）
 */
ob_start();
require_once dirname(__DIR__) . '/core/db.php';
require_once dirname(__DIR__) . '/core/functions.php';
ob_end_clean();

// ── 解析过滤参数（与 admin/index.php 相同）─────────────────────
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
];
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

$action = $_GET['action'] ?? 'copy';

// ── 无分页拉取全部匹配域名 ──────────────────────────────────────
$master       = getMasterDB();
$customFields = getCustomFields();

if ($action === 'copy') {
    // 只返回域名文本
    $domains = getMasterDomains($filters, $tagFilter, 0, 999999);
    header('Content-Type: text/plain; charset=utf-8');
    foreach ($domains as $d) {
        echo $d['domain'] . "\n";
    }
    exit;
}

// action=csv ─────────────────────────────────────────────────────
$domains = getMasterDomains($filters, $tagFilter, 0, 999999);

$filename = 'domains_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache');

// 输出 UTF-8 BOM（使 Excel 正确识别中文）
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// 表头
$headers = ['域名','注册商','状态','备案类型','分组','DNS','账号','过期时间','注册时间','标签'];
foreach ($customFields as $cf) {
    $headers[] = $cf['label'];
}
fputcsv($out, $headers);

foreach ($domains as $d) {
    $row = [
        $d['domain']          ?? '',
        $d['registrar_name']  ?? '',
        $d['status']          ?? '',
        $d['icp_type']        ?? '',
        $d['group_name']      ?? '',
        $d['dns_servers']     ?? '',   // 实际列名
        $d['account_name']    ?? '',
        $d['expire_date']     ?? '',
        $d['register_date']   ?? '',
        implode(',', array_column($d['tags'] ?? [], 'name')),
    ];
    $customData = json_decode($d['custom_data'] ?? '{}', true) ?: [];
    foreach ($customFields as $cf) {
        $row[] = $customData[$cf['name']] ?? '';
    }
    fputcsv($out, $row);
}
fclose($out);
exit;
