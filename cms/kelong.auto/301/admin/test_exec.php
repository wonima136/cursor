<?php
// 清空日志
file_put_contents(__DIR__ . '/data/test_exec.log', '');

$taskId = 'sitemap_1768068160_03d50865';
$phpBinary = '/www/server/php/72/bin/php';
$scriptPath = __DIR__ . '/sitemap_prefetch_worker.php';
$logFile = __DIR__ . '/data/sitemap_prefetch.log';

$command = sprintf(
    '%s %s %s >> %s 2>&1 &',
    escapeshellarg($phpBinary),
    escapeshellarg($scriptPath),
    escapeshellarg($taskId),
    escapeshellarg($logFile)
);

file_put_contents(__DIR__ . '/data/test_exec.log', "Command: $command\n", FILE_APPEND);

$output = [];
$returnVar = 0;
@exec($command, $output, $returnVar);

file_put_contents(__DIR__ . '/data/test_exec.log', "Return: $returnVar\n", FILE_APPEND);
file_put_contents(__DIR__ . '/data/test_exec.log', "Output: " . implode("\n", $output) . "\n", FILE_APPEND);

echo "Test completed. Check data/test_exec.log\n";
