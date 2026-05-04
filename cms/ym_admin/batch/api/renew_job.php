<?php
/**
 * 接收批量续费表单，创建后台任务并重定向到进度页
 */
require_once dirname(dirname(__DIR__)) . '/core/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/batch/renew.php');
}

$mode       = $_POST['mode'] ?? 'add_years';
$newExpire  = trim($_POST['expire_date'] ?? '');
$renewYears = max(1, min(10, (int)($_POST['renew_years'] ?? 1)));
$lines      = array_filter(array_map('trim', explode("\n", $_POST['data'] ?? '')));

$rows = [];

if ($mode === 'add_years') {
    // 只需要域名列表，过期时间由 worker 计算（现有日期 + N 年）
    foreach ($lines as $line) {
        $domain = trim(explode(',', $line)[0]);
        if ($domain) $rows[] = ['domain' => $domain];
    }
    if (!$rows) {
        flash('error', '没有有效的域名');
        redirect('/batch/renew.php');
    }
    $jobId = createJob('renew_add_years', $rows, ['years' => $renewYears]);
} else {
    // single / perline 模式（保留原有逻辑）
    $usePerLine = $mode === 'perline';
    foreach ($lines as $line) {
        $cols   = str_getcsv($line, ',');
        $domain = trim($cols[0] ?? '');
        $expire = $usePerLine ? trim($cols[1] ?? $newExpire) : $newExpire;
        if (!$domain || !$expire) continue;
        $rows[] = ['domain' => $domain, 'expire' => $expire];
    }
    if (!$rows) {
        flash('error', '没有有效的续费数据');
        redirect('/batch/renew.php');
    }
    $jobId = createJob('renew', $rows, []);
}

launchJob($jobId);
redirect('/jobs/progress.php?id=' . $jobId);
