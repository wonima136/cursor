<?php
// 实时拉取账号域名列表（分页）
require_once dirname(dirname(dirname(__DIR__))) . '/core/functions.php';
require_once dirname(__DIR__) . '/core/api.php';

header('Content-Type: application/json');

$accountId = (int)($_GET['account_id'] ?? 0);
$page      = max(1, (int)($_GET['p'] ?? 1));
$pageSize  = 50;
$account   = $accountId ? dnsla_getAccount($accountId) : null;

if (!$account) { echo json_encode(['ok' => false, 'msg' => '账号不存在']); exit; }

$token = dnsla_buildToken($account['api_id'], $account['api_secret']);
$base  = $account['api_base'] ?? '';

$r = dnsla_request('GET', '/api/domainList', [
    'pageIndex' => $page,
    'pageSize'  => $pageSize,
], [], $token, $base);

if (!$r['ok']) {
    echo json_encode(['ok' => false, 'msg' => $r['msg'] ?: '请求失败（code:' . $r['code'] . '）']);
    exit;
}

$nsStateLabels = [0 => '未知', 1 => '已匹配', 2 => '未匹配', 3 => '未加入'];
$nsStateBadge  = [0 => 'secondary', 1 => 'success', 2 => 'warning', 3 => 'danger'];
$stateLabels   = [1 => '正常', 2 => '暂停'];
$stateBadge    = [1 => 'success', 2 => 'warning'];

$total   = (int)($r['data']['total'] ?? 0);
$results = $r['data']['results'] ?? [];
$rows    = [];

foreach ($results as $d) {
    $expAt  = (int)($d['expiredAt'] ?? 0);
    $expStr = ($expAt > 0 && $expAt < 4102416000) ? date('Y-m-d', $expAt) : '长期';
    $state  = (int)($d['state']   ?? 1);
    $nsState= (int)($d['nsState'] ?? 0);
    $rows[] = [
        'id'          => $d['id'] ?? '',
        'domain'      => rtrim($d['displayDomain'] ?? $d['domain'] ?? '', '.'),
        'state'       => $state,
        'stateLabel'  => $stateLabels[$state]   ?? $state,
        'stateBadge'  => $stateBadge[$state]    ?? 'secondary',
        'nsState'     => $nsState,
        'nsStateLabel'=> $nsStateLabels[$nsState] ?? $nsState,
        'nsStateBadge'=> $nsStateBadge[$nsState]  ?? 'secondary',
        'product'     => $d['productName'] ?: '免费版',
        'expiredAt'   => $expStr,
        'groupName'   => $d['groupName'] ?? '',
    ];
}

echo json_encode([
    'ok'        => true,
    'total'     => $total,
    'page'      => $page,
    'pageSize'  => $pageSize,
    'totalPages'=> $total > 0 ? (int)ceil($total / $pageSize) : 1,
    'rows'      => $rows,
]);
