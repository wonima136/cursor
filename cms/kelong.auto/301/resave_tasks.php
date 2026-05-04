<?php
require_once __DIR__ . '/admin/config.php';
require_once __DIR__ . '/admin/parasite_functions.php';
require_once __DIR__ . '/admin/redis_config.php';

header('Content-Type: text/plain; charset=utf-8');

$tasks = _r301parasite_getAll();
foreach ($tasks as $task) {
    saveParasiteTaskToRedis($task['id'], $task);
    echo 'Saved task: ' . $task['id'] . PHP_EOL;
}
echo 'Done!' . PHP_EOL;
