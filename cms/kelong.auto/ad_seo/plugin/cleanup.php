<?php
/**
 * 安装后清理脚本
 * 将 mobiles.yml 移到 plugin/ 根目录，删除其余无用的规则文件和测试文件
 * 使用方式：php cleanup.php
 */

$vendor = __DIR__ . '/vendor';

if (!is_dir($vendor)) {
    echo "vendor 目录不存在，请先运行 composer install\n";
    exit(1);
}

$removed = 0;

// ── 1. 将 mobiles.yml 移到 plugin/ 根目录 ──
$mobiSrc  = $vendor . '/matomo/device-detector/regexes/device/mobiles.yml';
$mobiDest = __DIR__ . '/mobiles.yml';
if (file_exists($mobiSrc)) {
    rename($mobiSrc, $mobiDest);
    echo "移动: regexes/device/mobiles.yml → plugin/mobiles.yml\n";
    $removed++;
}

// ── 2. 删除整个 regexes 目录（mobiles.yml 已迁移）及无关测试目录 ──
$unusedDirs = [
    'matomo/device-detector/regexes',
    'mustangostang/spyc/tests',
    'mustangostang/spyc/examples',
    'mustangostang/spyc/php4',
];

function removeDir(string $dir): int {
    $count = 0;
    if (!is_dir($dir)) return 0;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        $count += is_dir($p) ? removeDir($p) : (unlink($p) ? 1 : 0);
    }
    rmdir($dir);
    return $count;
}

foreach ($unusedDirs as $rel) {
    $path = $vendor . '/' . $rel;
    if (is_dir($path)) {
        $n = removeDir($path);
        echo "删除目录: $rel ($n 个文件)\n";
        $removed += $n;
    }
}

$total = iterator_count(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vendor,
        FilesystemIterator::SKIP_DOTS))
);

echo "\n完成：删除 $removed 个文件，vendor 剩余 $total 个文件\n";
