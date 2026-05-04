<?php
/**
 * 接收批量续费表单，创建后台任务并重定向到进度页
 */
require_once dirname(__DIR__) . '/core/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/batch_renew.php');
}

$newExpire  = trim($_POST['expire_date'] ?? '');
$usePerLine = ($_POST['mode'] ?? 'single') === 'perline';
$lines      = array_filter(array_map('trim', explode("\n", $_POST['data'] ?? '')));

$rows = [];
foreach ($lines as $line) {
    $cols   = str_getcsv($line, ',');
    $domain = trim($cols[0] ?? '');
    $expire = $usePerLine ? trim($cols[1] ?? $newExpire) : $newExpire;
    if (!$domain || !$expire) continue;
    $rows[] = ['domain' => $domain, 'expire' => $expire];
}

if (!$rows) {
    flash('error', '没有有效的续费数据');
    redirect('/admin/batch_renew.php');
}

$jobId = createJob('renew', $rows, []);
launchJob($jobId);
redirect('/admin/job_progress.php?id=' . $jobId);
