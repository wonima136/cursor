<?php
// 批量解析记录任务提交（添加 / 修改 / 删除）
require_once dirname(dirname(dirname(__DIR__))) . '/core/functions.php';
require_once dirname(__DIR__) . '/core/api.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'msg'=>'Method not allowed']); exit; }

$action    = trim($_POST['action'] ?? '');
$accountId = (int)($_POST['account_id'] ?? 0);
$account   = $accountId ? dnsla_getAccount($accountId) : null;

if (!$account) { echo json_encode(['ok'=>false,'msg'=>'账号不存在']); exit; }

$rawDomains = trim($_POST['domains'] ?? '');
$domains    = array_values(array_filter(array_map('trim', explode("\n", $rawDomains))));

if (empty($domains)) { echo json_encode(['ok'=>false,'msg'=>'域名列表为空']); exit; }

// ── 批量添加 A 记录 ───────────────────────────────────────────────
if ($action === 'add_records') {
    $rawIps  = trim($_POST['ips'] ?? '');
    $rawHosts= trim($_POST['hosts'] ?? '');
    $ips     = array_values(array_filter(array_map('trim', explode("\n", $rawIps))));
    $hosts   = array_values(array_filter(array_map('trim', explode("\n", $rawHosts))));
    $ttl     = max(1, (int)($_POST['ttl'] ?? 600));
    $clearA  = !empty($_POST['clear_a']) && $_POST['clear_a'] === '1';
    $recType = (int)($_POST['rec_type'] ?? 1);

    if (empty($ips))   { echo json_encode(['ok'=>false,'msg'=>'IP 列表为空']); exit; }
    if (empty($hosts)) { echo json_encode(['ok'=>false,'msg'=>'主机头列表为空']); exit; }

    $params = [
        'account_id' => $accountId,
        'ips'        => $ips,
        'hosts'      => $hosts,
        'ttl'        => $ttl,
        'clear_a'    => $clearA,
        'rec_type'   => $recType,
    ];
    $jobId = createJob('dns_la_add_records', $domains, $params);
    launchJob($jobId);
    echo json_encode(['ok' => true, 'job_id' => $jobId]);
    exit;
}

// ── 批量修改记录值 ────────────────────────────────────────────────
if ($action === 'update_records') {
    $rawIps   = trim($_POST['ips'] ?? '');
    $rawHosts = trim($_POST['hosts'] ?? '');
    $ips      = array_values(array_filter(array_map('trim', explode("\n", $rawIps))));
    $hosts    = array_values(array_filter(array_map('trim', explode("\n", $rawHosts))));
    $recType  = (int)($_POST['rec_type'] ?? 1);

    if (empty($ips)) { echo json_encode(['ok'=>false,'msg'=>'IP 列表为空']); exit; }

    $params = [
        'account_id' => $accountId,
        'ips'        => $ips,
        'hosts'      => $hosts,
        'rec_type'   => $recType,
    ];
    $jobId = createJob('dns_la_update_records', $domains, $params);
    launchJob($jobId);
    echo json_encode(['ok' => true, 'job_id' => $jobId]);
    exit;
}

// ── 批量删除记录 ──────────────────────────────────────────────────
if ($action === 'del_records') {
    $rawHosts = trim($_POST['hosts'] ?? '');
    $hosts    = array_values(array_filter(array_map('trim', explode("\n", $rawHosts))));
    $recType  = (int)($_POST['rec_type'] ?? 1);

    $params = [
        'account_id' => $accountId,
        'hosts'      => $hosts,
        'rec_type'   => $recType,
    ];
    $jobId = createJob('dns_la_del_records', $domains, $params);
    launchJob($jobId);
    echo json_encode(['ok' => true, 'job_id' => $jobId]);
    exit;
}

echo json_encode(['ok'=>false,'msg'=>'未知操作']);
