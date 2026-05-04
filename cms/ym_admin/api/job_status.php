<?php
require_once dirname(__DIR__) . '/core/functions.php';
header('Content-Type: application/json; charset=utf-8');

// ?list=1 → 返回运行中任务列表（供任务管理页轮询）
if (!empty($_GET['list'])) {
    $master = getMasterDB();
    $rows   = db_all($master,
        "SELECT id,type,status,progress,total FROM jobs WHERE status IN ('pending','running')");
    foreach ($rows as &$r) {
        $r['pct'] = $r['total'] > 0 ? min(100, (int)round($r['progress'] / $r['total'] * 100)) : 0;
    }
    echo json_encode(['ok' => true, 'jobs' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

$id  = trim($_GET['id'] ?? '');
if (!$id) { echo json_encode(['ok' => false, 'msg' => '缺少任务ID']); exit; }

$master = getMasterDB();
$job    = db_one($master,
    "SELECT id,type,status,progress,total,message,result FROM jobs WHERE id=?",
    [$id]
);

if (!$job) { echo json_encode(['ok' => false, 'msg' => '任务不存在']); exit; }

$job['result'] = ($job['result'] !== '' && $job['result'] !== null)
    ? json_decode($job['result'], true)
    : null;
$job['pct'] = $job['total'] > 0
    ? min(100, (int)round($job['progress'] / $job['total'] * 100))
    : ($job['status'] === 'done' ? 100 : 0);

// 任务完成后清除 session 中的 job_id
if (isset($_GET['clear']) && in_array($job['status'], ['done', 'failed'])) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (($_SESSION['last_job_id'] ?? '') === $id) {
        unset($_SESSION['last_job_id']);
    }
}

echo json_encode(['ok' => true, 'job' => $job], JSON_UNESCAPED_UNICODE);
