<?php
/**
 * HTTP 触发式 worker（兜底方案）
 * 当 exec() 无法启动 CLI PHP 时，由 launchJob 通过 HTTP 内请求调用此文件
 * 本文件以 ignore_user_abort 模式运行，立即返回响应头后继续在后台处理
 */
ignore_user_abort(true);
set_time_limit(0);
ini_set('memory_limit', '512M');

// 立即发送响应头，让请求方不用等待
header('Content-Type: text/plain');
header('Connection: close');
ob_start();
echo 'OK';
$size = ob_get_length();
header("Content-Length: $size");
ob_end_flush();
flush();

// 现在客户端已断开，继续处理任务
$jobId = trim($_GET['id'] ?? '');
if (!$jobId) exit;

require_once dirname(__DIR__) . '/core/functions.php';

$master = getMasterDB();
$job    = db_one($master, "SELECT * FROM jobs WHERE id=?", [$jobId]);
if (!$job || $job['status'] !== 'pending') exit;

// 复用 worker 逻辑（include worker run.php 等价代码）
// 直接 require worker（worker 会自行检查 CLI 限制，这里绕过）
// 注意：worker 首行检查了 PHP_SAPI !== 'cli'，需要在这里用另一种方式跑
// 所以直接内联跑任务逻辑：
$worker = dirname(__DIR__) . '/worker/run.php';
$php    = _findPhpCli();
// 再尝试一次 exec，此时 HTTP 连接已关闭，不受超时限制
$cmd = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($jobId) . ' > /dev/null 2>&1';
exec($cmd);
