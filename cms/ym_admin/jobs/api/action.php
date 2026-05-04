<?php
require_once dirname(dirname(__DIR__)) . '/core/functions.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => '仅支持 POST']); exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';
$id     = $body['id'] ?? '';
$master = getMasterDB();

// ── 清空所有已完成/失败任务 ──────────────────────────────────────
if ($action === 'delete_finished') {
    $done = db_all($master,
        "SELECT id, params FROM jobs WHERE status IN ('done','failed')");
    $jobsDir = DATA_DIR . '/jobs';
    foreach ($done as $j) {
        $params = json_decode($j['params'], true) ?: [];
        if (!empty($params['data_file']) && file_exists($params['data_file'])) {
            unlink($params['data_file']);
        }
    }
    $cnt = count($done);
    db_exec($master, "DELETE FROM jobs WHERE status IN ('done','failed')");
    echo json_encode(['ok' => true, 'deleted' => $cnt], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 单任务操作：需要 id ──────────────────────────────────────────
if (!$id) { echo json_encode(['ok' => false, 'msg' => '缺少任务ID']); exit; }

$job = db_one($master, "SELECT * FROM jobs WHERE id=?", [$id]);
if (!$job) { echo json_encode(['ok' => false, 'msg' => '任务不存在']); exit; }

// ── 终止 ─────────────────────────────────────────────────────────
if ($action === 'terminate') {
    if (!in_array($job['status'], ['pending', 'running'])) {
        echo json_encode(['ok' => false, 'msg' => '任务已结束，无法终止']); exit;
    }

    $pid = (int)$job['pid'];
    $killed = false;

    if ($pid > 0) {
        // 优先用 posix_kill（更可靠）
        if (function_exists('posix_kill')) {
            $killed = @posix_kill($pid, 15); // SIGTERM
            if (!$killed) $killed = @posix_kill($pid, 9); // SIGKILL
        } else {
            // 回退到 exec kill
            exec('kill -15 ' . $pid . ' 2>/dev/null', $out, $ret);
            if ($ret !== 0) exec('kill -9 ' . $pid . ' 2>/dev/null');
            $killed = true;
        }
    }

    // 清理数据文件
    $params = json_decode($job['params'], true) ?: [];
    if (!empty($params['data_file']) && file_exists($params['data_file'])) {
        unlink($params['data_file']);
    }

    // 标记失败
    db_exec($master,
        "UPDATE jobs SET status='failed', message=?, updated_at=datetime('now','localtime') WHERE id=?",
        ['已手动终止' . ($pid > 0 ? "（PID {$pid}）" : ''), $id]
    );

    // 若是当前 session 中的任务，清除追踪
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (($_SESSION['last_job_id'] ?? '') === $id) unset($_SESSION['last_job_id']);

    echo json_encode(['ok' => true, 'killed' => $killed], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 删除 ─────────────────────────────────────────────────────────
if ($action === 'delete') {
    if (in_array($job['status'], ['pending', 'running'])) {
        echo json_encode(['ok' => false, 'msg' => '任务进行中，请先终止再删除']); exit;
    }

    $params = json_decode($job['params'], true) ?: [];
    if (!empty($params['data_file']) && file_exists($params['data_file'])) {
        unlink($params['data_file']);
    }

    db_exec($master, "DELETE FROM jobs WHERE id=?", [$id]);

    if (session_status() === PHP_SESSION_NONE) session_start();
    if (($_SESSION['last_job_id'] ?? '') === $id) unset($_SESSION['last_job_id']);

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'msg' => '未知操作: ' . $action]);
