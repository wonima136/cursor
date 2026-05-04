<?php
// ════════════════════════════════════════════════════════════════
// 后台任务管理
// ════════════════════════════════════════════════════════════════

function createJob(string $type, array $rows, array $settings): string {
    $master  = getMasterDB();
    $jobsDir = DATA_DIR . '/jobs';
    if (!is_dir($jobsDir)) mkdir($jobsDir, 0755, true);

    $old = db_all($master, "SELECT id FROM jobs WHERE created_at < datetime('now','-24 hours','localtime')");
    foreach ($old as $o) {
        $f = $jobsDir . '/' . $o['id'] . '.json';
        if (file_exists($f)) unlink($f);
    }
    db_exec($master, "DELETE FROM jobs WHERE created_at < datetime('now','-24 hours','localtime')");

    $jobId    = uniqid('job_', true);
    $dataFile = $jobsDir . '/' . $jobId . '.json';
    file_put_contents($dataFile, json_encode($rows, JSON_UNESCAPED_UNICODE));

    $settings['data_file'] = $dataFile;
    $total = isset($settings['_total']) ? (int)$settings['_total'] : count($rows);
    unset($settings['_total']);
    db_insert($master, 'jobs', [
        'id'     => $jobId,
        'type'   => $type,
        'params' => json_encode($settings, JSON_UNESCAPED_UNICODE),
        'total'  => $total,
        'status' => 'pending',
    ]);
    return $jobId;
}

function _findPhpCli(): string {
    $bin = PHP_BINARY;
    if (strpos($bin, 'php-fpm') !== false || strpos($bin, 'php-cgi') !== false) {
        $cli = preg_replace('#/s?bin/php-(fpm|cgi)$#', '/bin/php', $bin);
        if ($cli !== $bin) return $cli;
    }
    return $bin;
}

function launchJob(string $jobId): void {
    $worker = dirname(__DIR__) . '/worker/run.php';
    $php    = _findPhpCli();
    $cmd    = '/usr/bin/nohup ' . escapeshellarg($php) . ' '
            . escapeshellarg($worker) . ' '
            . escapeshellarg($jobId) . ' > /dev/null 2>&1 &';
    exec($cmd);

    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['last_job_id'] = $jobId;
}

function getActiveJob(): ?array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $jobId = $_SESSION['last_job_id'] ?? '';
    if (!$jobId) return null;

    $job = db_one(getMasterDB(),
        "SELECT id, type, status, progress, total FROM jobs WHERE id=?", [$jobId]);
    if (!$job || in_array($job['status'], ['done', 'failed'])) {
        unset($_SESSION['last_job_id']);
        return null;
    }
    return $job;
}
