<?php
$logFile = __DIR__ . '/data/prefetch_debug.log';
$timestamp = date('Y-m-d H:i:s');
$data = [
    'time' => $timestamp,
    'method' => $_SERVER['REQUEST_METHOD'],
    'post' => $_POST,
    'get' => $_GET,
    'headers' => getallheaders()
];
file_put_contents($logFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n", FILE_APPEND);
