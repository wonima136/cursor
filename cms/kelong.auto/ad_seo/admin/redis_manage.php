<?php
/**
 * WAF Redis 管理页面
 */
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

define('CONFIG_DIR',  dirname(__DIR__));
define('CONFIG_FILE', CONFIG_DIR . '/config.php');

function checkLogin(): bool {
    return isset($_SESSION['waf_admin_logged_in']) && $_SESSION['waf_admin_logged_in'] === true;
}
if (!checkLogin()) { header('Location: index.php'); exit; }

// 加载 WafRedis（只加载类文件，不触发 WAF 执行）
require_once CONFIG_DIR . '/WafRedis.php';

// 读取 config.php 文本，手动解析并定义 Redis 常量
// ⚠️ 不能 include/require config.php：那样会触发 monitor.php → WAF 执行入口 → exit()
//    导致 POST 保存请求被拦截，永远无法写入
(function () {
    $raw = @file_get_contents(CONFIG_FILE);
    if ($raw === false) return;

    $parseBool   = function (string $raw, string $key, bool $default) {
        preg_match("/define\('{$key}',\s*(true|false)\);/i", $raw, $m);
        return isset($m[1]) ? strtolower($m[1]) === 'true' : $default;
    };
    $parseStr    = function (string $raw, string $key, string $default) {
        preg_match("/define\('{$key}',\s*'([^']*)'\);/", $raw, $m);
        return $m[1] ?? $default;
    };
    $parseInt    = function (string $raw, string $key, int $default) {
        preg_match("/define\('{$key}',\s*(\d+)\);/", $raw, $m);
        return isset($m[1]) ? (int)$m[1] : $default;
    };
    $parseFloat  = function (string $raw, string $key, float $default) {
        preg_match("/define\('{$key}',\s*([0-9.]+)\);/", $raw, $m);
        return isset($m[1]) ? (float)$m[1] : $default;
    };

    // 只定义 WafRedis 连接所需的常量（避免与 config.php 常量冲突）
    if (!defined('WAF_REDIS_ENABLED'))  define('WAF_REDIS_ENABLED',  $parseBool($raw,  'WAF_REDIS_ENABLED',  false));
    if (!defined('WAF_REDIS_HOST'))     define('WAF_REDIS_HOST',     $parseStr($raw,   'WAF_REDIS_HOST',     '127.0.0.1'));
    if (!defined('WAF_REDIS_PORT'))     define('WAF_REDIS_PORT',     $parseInt($raw,   'WAF_REDIS_PORT',     6379));
    if (!defined('WAF_REDIS_AUTH'))     define('WAF_REDIS_AUTH',     $parseStr($raw,   'WAF_REDIS_AUTH',     ''));
    if (!defined('WAF_REDIS_TIMEOUT'))  define('WAF_REDIS_TIMEOUT',  $parseFloat($raw, 'WAF_REDIS_TIMEOUT',  0.5));
    if (!defined('WAF_REDIS_DB'))       define('WAF_REDIS_DB',       $parseInt($raw,   'WAF_REDIS_DB',       1));
    if (!defined('WAF_INSTANCE_ID'))    define('WAF_INSTANCE_ID',    $parseStr($raw,   'WAF_INSTANCE_ID',    ''));

    // 供 WafRedis::getInstanceId() 自动生成时使用正确路径
    if (!isset($_ENV['WAF_CONFIG_PATH'])) {
        $_ENV['WAF_CONFIG_PATH'] = CONFIG_DIR;
    }
})();

define('CURRENT_PAGE', 'redis');

/* ──────────────────────────────────────────────
   config.php 读写工具
   读：正则提取值
   写：逐行扫描找到 define('KEY', ...) 那一行，整行替换
       无论原来的格式、空格、注释如何，都能准确命中
   ────────────────────────────────────────────── */
function cfgReadString(string $content, string $key, string $default = ''): string {
    preg_match("/define\(['\"]" . preg_quote($key, '/') . "['\"],\s*'([^']*)'\)/", $content, $m);
    return $m[1] ?? $default;
}
function cfgReadBool(string $content, string $key, bool $default = false): bool {
    preg_match("/define\(['\"]" . preg_quote($key, '/') . "['\"],\s*(true|false)\)/i", $content, $m);
    if (!isset($m[1])) return $default;
    return strtolower($m[1]) === 'true';
}
function cfgReadInt(string $content, string $key, int $default = 0): int {
    preg_match("/define\(['\"]" . preg_quote($key, '/') . "['\"],\s*(\d+)\)/", $content, $m);
    return isset($m[1]) ? (int)$m[1] : $default;
}
function cfgReadFloat(string $content, string $key, float $default = 0.5): float {
    preg_match("/define\(['\"]" . preg_quote($key, '/') . "['\"],\s*([0-9.]+)\)/", $content, $m);
    return isset($m[1]) ? (float)$m[1] : $default;
}

/**
 * 逐行找到包含 define('KEY', 的那一行，整行替换为新值
 * 不依赖格式、空格数量、注释，只认 KEY 名称
 */
function cfgWriteLine(string $content, string $key, string $newRawValue): string {
    $needle  = "define('" . $key . "',";   // 单引号形式
    $needle2 = 'define("' . $key . '",';   // 双引号形式（容错）
    $eol     = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
    $lines   = explode("\n", str_replace("\r\n", "\n", $content));
    $found   = false;
    foreach ($lines as &$line) {
        $trimmed = ltrim($line);
        if (strpos($trimmed, $needle) === 0 || strpos($trimmed, $needle2) === 0) {
            $line  = "define('" . $key . "', " . $newRawValue . ");";
            $found = true;
            break;
        }
    }
    unset($line);
    return $found ? implode($eol, $lines) : $content;
}

function cfgWriteString(string $content, string $key, string $value): string {
    return cfgWriteLine($content, $key, "'" . addslashes($value) . "'");
}
function cfgWriteBool(string $content, string $key, bool $value): string {
    return cfgWriteLine($content, $key, $value ? 'true' : 'false');
}
function cfgWriteInt(string $content, string $key, int $value): string {
    return cfgWriteLine($content, $key, (string)$value);
}
function cfgWriteFloat(string $content, string $key, float $value): string {
    return cfgWriteLine($content, $key, rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.'));
}

/* ──────────────────────────────────────────────
   Redis 连接与工具函数
   ────────────────────────────────────────────── */

function getRedis(): ?\Redis {
    return WafRedis::get();
}

function redisEnabled(): bool {
    return defined('WAF_REDIS_ENABLED') && WAF_REDIS_ENABLED;
}

function getInstanceId(): string {
    return WafRedis::getInstanceId();
}

/** 获取IP白名单 Redis key */
function ipWhitelistKey(): string {
    return WafRedis::instanceKey('ip_whitelist');
}

/** 获取同步锁 key */
function ipSyncLockKey(): string {
    return WafRedis::instanceKey('ip_sync_lock');
}

/** 获取 Redis 信息（版本、内存等） */
function getRedisInfo(\Redis $redis): array {
    try {
        $info = $redis->info('server');
        $mem  = $redis->info('memory');
        return [
            'version'    => $info['redis_version']      ?? '未知',
            'uptime'     => $info['uptime_in_days']     ?? 0,
            'used_mem'   => $mem['used_memory_human']   ?? '未知',
            'peak_mem'   => $mem['used_memory_peak_human'] ?? '未知',
        ];
    } catch (\Exception $e) {
        return ['version' => '未知', 'uptime' => 0, 'used_mem' => '未知', 'peak_mem' => '未知'];
    }
}

/** 获取UA缓存数量（SCAN waf:shared:ua:*）
 *  phpredis: scan($cursor[引用], $pattern, $count) 返回 keys数组|false，cursor 由引用更新
 */
function countUACache(\Redis $redis): int {
    $count  = 0;
    $cursor = null;
    do {
        $keys = $redis->scan($cursor, 'waf:shared:ua:*', 200);
        if ($keys === false || !is_array($keys)) break;
        $count += count($keys);
    } while ($cursor != 0);
    return $count;
}

/** 删除所有UA缓存 key */
function clearUACache(\Redis $redis): int {
    $count  = 0;
    $cursor = null;
    do {
        $keys = $redis->scan($cursor, 'waf:shared:ua:*', 200);
        if ($keys === false || !is_array($keys)) break;
        if (!empty($keys)) {
            $redis->del($keys);
            $count += count($keys);
        }
    } while ($cursor != 0);
    return $count;
}

/** 获取全部 Bot UA（按最新访问时间降序） */
function getBotUAs(\Redis $redis): array {
    $list = $redis->zRevRange(WafRedis::sharedKey('bot_uas'), 0, -1, true);
    return is_array($list) ? $list : [];
}

/** 删除所有 waf:* key（当前实例+共享） */
function clearAllWafKeys(\Redis $redis): int {
    $count    = 0;
    $instId   = getInstanceId();
    $patterns = ['waf:' . $instId . ':*', 'waf:shared:*'];
    foreach ($patterns as $pat) {
        $cursor = null;
        do {
            $keys = $redis->scan($cursor, $pat, 200);
            if ($keys === false || !is_array($keys)) break;
            if (!empty($keys)) {
                $redis->del($keys);
                $count += count($keys);
            }
        } while ($cursor != 0);
    }
    return $count;
}

/** 从 JSON 文件重新写入 IP 白名单到 Redis */
function reloadWhitelistToRedis(\Redis $redis): array {
    $jsonFile = CONFIG_DIR . '/whitelist/ip_whitelist.json';
    if (!file_exists($jsonFile)) {
        return ['ok' => false, 'msg' => '白名单文件不存在：' . $jsonFile];
    }
    $list = @json_decode(@file_get_contents($jsonFile), true);
    if (!is_array($list) || empty($list)) {
        return ['ok' => false, 'msg' => '白名单文件为空或格式错误'];
    }

    $key  = ipWhitelistKey();
    $pipe = $redis->pipeline();
    $pipe->del($key);
    foreach ($list as $ab) {
        $pipe->sAdd($key, $ab);
    }
    $pipe->exec();

    return ['ok' => true, 'msg' => '已写入 ' . count($list) . ' 条AB段到 Redis（key: ' . $key . '）'];
}

/* ──────────────────────────────────────────────
   GET：导出 Bot UA 列表（输出文件，不走 HTML）
   ────────────────────────────────────────────── */
if (isset($_GET['action']) && $_GET['action'] === 'export_bot_uas') {
    $r = getRedis();
    if (!$r) {
        die('Redis 未连接，无法导出。');
    }
    $list = getBotUAs($r);
    $lines = [];
    foreach ($list as $ua => $score) {
        $time    = date('Y-m-d H:i:s', (int)$score);
        $lines[] = "[{$time}] {$ua}";
    }
    $content  = implode("\n", $lines);
    $filename = 'bot_ua_' . date('Ymd_His') . '.txt';

    // 删除 waf:shared:ua:* 中值为 "bot" 的条目，mobile/desktop 缓存不受影响
    $cursor = null;
    do {
        $keys = $r->scan($cursor, 'waf:shared:ua:*', 200);
        if ($keys === false || !is_array($keys)) break;
        if (!empty($keys)) {
            $values = $r->mGet($keys);
            foreach ($values as $i => $val) {
                if ($val === 'bot') {
                    $r->del($keys[$i]);
                }
            }
        }
    } while ($cursor != 0);
    // 同时清除 bot UA 日志
    $r->del(WafRedis::sharedKey('bot_uas'));

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

/* ──────────────────────────────────────────────
   POST：保存 Redis 配置
   ────────────────────────────────────────────── */
if (isset($_POST['save_redis_config'])) {
    // 读取文件
    $cfg = @file_get_contents(CONFIG_FILE);
    if ($cfg === false) {
        header('Location: redis_manage.php?msg=' . urlencode('读取失败：config.php 不可读，路径：' . CONFIG_FILE) . '&type=error');
        exit;
    }

    $cfgOld = $cfg;  // 留存原始内容，用于对比

    $cfg = cfgWriteBool($cfg,   'WAF_REDIS_ENABLED', isset($_POST['redis_enabled']));
    $cfg = cfgWriteString($cfg, 'WAF_REDIS_HOST',    trim($_POST['redis_host'] ?? '127.0.0.1'));
    $cfg = cfgWriteInt($cfg,    'WAF_REDIS_PORT',    max(1, min(65535, (int)($_POST['redis_port'] ?? 6379))));
    $cfg = cfgWriteString($cfg, 'WAF_REDIS_AUTH',    trim($_POST['redis_auth'] ?? ''));
    $redisDb = max(0, min(15, (int)($_POST['redis_db'] ?? 1)));
    $cfg = cfgWriteInt($cfg,    'WAF_REDIS_DB',      $redisDb);
    $cfg = cfgWriteFloat($cfg,  'WAF_REDIS_TIMEOUT', round(max(0.1, min(10.0, (float)($_POST['redis_timeout'] ?? 0.5))), 1));
    $cfg = cfgWriteString($cfg, 'WAF_INSTANCE_ID',   trim($_POST['redis_instance_id'] ?? ''));

    // 检查内容是否发生变化（正则是否匹配到了）
    if ($cfg === $cfgOld) {
        // 读出当前文件里的实际值，告诉用户文件里现在存的是什么
        preg_match("/define\('WAF_REDIS_ENABLED',\s*(true|false)\);/i", $cfgOld, $cEn);
        preg_match("/define\('WAF_REDIS_DB',\s*(\d+)\);/", $cfgOld, $cDb);
        preg_match("/define\('WAF_REDIS_HOST',\s*'([^']*)'\);/", $cfgOld, $cHost);
        $currentInfo = sprintf(
            'ENABLED=%s DB=%s HOST=%s',
            $cEn[1]  ?? '?',
            $cDb[1]  ?? '?',
            $cHost[1] ?? '?'
        );
        header('Location: redis_manage.php?msg=' . urlencode('内容未变化（提交值与文件相同），当前文件值：' . $currentInfo . '，无需重复保存') . '&type=success');
        exit;
    }

    // 写入文件
    $written = @file_put_contents(CONFIG_FILE, $cfg);
    if ($written === false) {
        header('Location: redis_manage.php?msg=' . urlencode('写入失败：config.php 没有写入权限，请 chmod 644 或联系主机商') . '&type=error');
        exit;
    }

    // 检查 DB 是否真的写进去了
    preg_match("/define\('WAF_REDIS_DB',\s*(\d+)\);/", $cfg, $dbCheck);
    $savedDb = isset($dbCheck[1]) ? (int)$dbCheck[1] : -1;
    $dbNote  = ($savedDb !== $redisDb) ? '（⚠️ DB写入校验失败，期望' . $redisDb . '实际' . $savedDb . '）' : '';

    $successMsg = 'Redis 配置已保存，DB=' . $redisDb . $dbNote;
    if ($redisDb !== (int)(defined('WAF_REDIS_DB') ? WAF_REDIS_DB : 1)) {
        $successMsg .= ' | ⚠️ 切换 DB 后旧 DB 中的缓存数据不会自动迁移，请在下方点击「重新写入 IP 白名单」';
    }
    header('Location: redis_manage.php?msg=' . urlencode($successMsg) . '&type=success');
    exit;
}

/* ──────────────────────────────────────────────
   POST：数据操作
   ────────────────────────────────────────────── */
$actionMsg  = '';
$actionType = '';  // success | error

$redis = getRedis();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if (!redisEnabled()) {
        $actionMsg  = 'Redis 未启用，请先在 config.php 中将 WAF_REDIS_ENABLED 设为 true';
        $actionType = 'error';
    } elseif (!$redis) {
        $actionMsg  = 'Redis 连接失败，请检查配置';
        $actionType = 'error';
    } else {
        switch ($action) {
            case 'reload_whitelist':
                $r = reloadWhitelistToRedis($redis);
                $actionMsg  = $r['msg'];
                $actionType = $r['ok'] ? 'success' : 'error';
                break;

            case 'clear_whitelist':
                $redis->del(ipWhitelistKey());
                $redis->del(ipSyncLockKey());
                $actionMsg  = 'IP 白名单已从 Redis 清除（保留本地 JSON 文件）';
                $actionType = 'success';
                break;

            case 'clear_ua_cache':
                $n = clearUACache($redis);
                $actionMsg  = "设备识别缓存已清除，共删除 {$n} 条记录";
                $actionType = 'success';
                break;

            case 'clear_all':
                $n = clearAllWafKeys($redis);
                $actionMsg  = "已清除全部 WAF Redis 数据，共删除 {$n} 个 key";
                $actionType = 'success';
                break;

            default:
                $actionMsg  = '未知操作';
                $actionType = 'error';
        }
    }
    // PRG 防重复提交
    $encMsg  = urlencode($actionMsg);
    $encType = urlencode($actionType);
    header("Location: redis_manage.php?msg={$encMsg}&type={$encType}");
    exit;
}

// GET 消息回显
if (isset($_GET['msg'])) {
    $actionMsg  = urldecode($_GET['msg']);
    $actionType = urldecode($_GET['type'] ?? 'success');
}

/* ──────────────────────────────────────────────
   采集页面数据
   ────────────────────────────────────────────── */
$connected   = $redis !== null;
$redisInfo   = $connected ? getRedisInfo($redis) : [];
$ipCount     = $connected ? (int)$redis->sCard(ipWhitelistKey()) : -1;
$uaCount     = $connected ? countUACache($redis) : -1;
$syncLockTTL = $connected ? (int)$redis->ttl(ipSyncLockKey()) : -1;

$cfgHost    = defined('WAF_REDIS_HOST')    ? WAF_REDIS_HOST    : '127.0.0.1';
$cfgPort    = defined('WAF_REDIS_PORT')    ? WAF_REDIS_PORT    : 6379;
$cfgDB      = defined('WAF_REDIS_DB')      ? WAF_REDIS_DB      : 0;
$cfgTimeout = defined('WAF_REDIS_TIMEOUT') ? WAF_REDIS_TIMEOUT : 0.5;
$cfgAuth    = (defined('WAF_REDIS_AUTH') && WAF_REDIS_AUTH !== '') ? '******' : '（无密码）';
$cfgEnabled = redisEnabled();
$instanceId = getInstanceId();

$jsonFile    = CONFIG_DIR . '/whitelist/ip_whitelist.json';
$jsonCount   = 0;
if (file_exists($jsonFile)) {
    $jl = @json_decode(@file_get_contents($jsonFile), true);
    $jsonCount = is_array($jl) ? count($jl) : 0;
}

// 读取配置用于填充表单
$_cfgRaw = file_get_contents(CONFIG_FILE);
$redisCv = [
    'redis_enabled'     => cfgReadBool($_cfgRaw,   'WAF_REDIS_ENABLED', false),
    'redis_host'        => cfgReadString($_cfgRaw,  'WAF_REDIS_HOST', '127.0.0.1'),
    'redis_port'        => cfgReadInt($_cfgRaw,     'WAF_REDIS_PORT', 6379),
    'redis_auth'        => cfgReadString($_cfgRaw,  'WAF_REDIS_AUTH', ''),
    'redis_db'          => cfgReadInt($_cfgRaw,     'WAF_REDIS_DB', 1),
    'redis_timeout'     => cfgReadFloat($_cfgRaw,   'WAF_REDIS_TIMEOUT', 0.5),
    'redis_instance_id' => cfgReadString($_cfgRaw,  'WAF_INSTANCE_ID', ''),
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redis 管理 — WAF拦截系统</title>
    <link rel="stylesheet" href="admin.css">
    <style>
        /* ── 状态栏 ── */
        .redis-status-bar{display:flex;align-items:center;gap:10px;padding:12px 18px;border-radius:var(--radius-sm);margin-bottom:20px;font-size:14px;border:1px solid var(--border);}
        .redis-status-bar.ok      {background:var(--green-dim); border-color:var(--green);}
        .redis-status-bar.fail    {background:var(--red-dim);   border-color:var(--red);}
        .redis-status-bar.disabled{background:var(--bg3);       border-color:var(--border);}
        .redis-status-bar strong  {color:var(--text);}
        .redis-status-bar .rs-sub {color:var(--text2);}

        /* ── 数据统计格 ── */
        .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:4px;}
        .stat-box{background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm);padding:18px;text-align:center;}
        .stat-box .stat-num{font-size:28px;font-weight:700;color:var(--blue);line-height:1.2;}
        .stat-box .stat-num.warn{color:var(--yellow);}
        .stat-box .stat-num.ok  {color:var(--green);}
        .stat-box .stat-num.err {color:var(--text3);}
        .stat-box .stat-label{font-size:12px;color:var(--text2);margin-top:6px;}
        .stat-box .stat-sub  {font-size:11px;color:var(--text3);margin-top:3px;word-break:break-all;}

        /* ── 操作卡片 ── */
        .op-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;}
        .op-card{background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm);padding:20px;}
        .op-card.danger{border-color:var(--red);}
        .op-card .op-title{font-weight:600;font-size:14px;margin-bottom:8px;color:var(--text);}
        .op-card.danger .op-title{color:var(--red);}
        .op-card .op-desc{font-size:12px;color:var(--text2);margin-bottom:16px;line-height:1.7;}

        /* ── 配置信息表格 ── */
        .info-table{width:100%;border-collapse:collapse;font-size:13px;}
        .info-table td{padding:9px 12px;border-bottom:1px solid var(--border);}
        .info-table tr:last-child td{border-bottom:none;}
        .info-table td:first-child{color:var(--text2);width:140px;}
        .info-table td:last-child{font-family:monospace;color:var(--text);}

        /* ── 状态小圆点 ── */
        .badge-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;flex-shrink:0;}
        .badge-dot.green{background:var(--green);}
        .badge-dot.red  {background:var(--red);}
        .badge-dot.gray {background:var(--text3);}
    </style>
</head>
<body>
<button class="mobile-menu-btn" onclick="toggleSidebar()">☰</button>
<div class="admin-layout">
    <?php include 'header.php'; ?>

    <?php if ($actionMsg): ?>
    <div class="alert alert-<?php echo $actionType === 'success' ? 'success' : 'danger'; ?>">
        <?php echo $actionType === 'success' ? '✅' : '❌'; ?>
        <?php echo htmlspecialchars($actionMsg); ?>
    </div>
    <?php endif; ?>

    <div class="page-header">
        <h1>🗄️ Redis 管理</h1>
    </div>

    <!-- ══ Redis 缓存配置表单 ══ -->
    <div class="card" style="margin-bottom:20px;">
        <div class="card-title">⚡ Redis 缓存配置</div>
        <p class="form-hint" style="margin-bottom:18px;">
            启用 Redis 后，IP 白名单和设备识别结果将写入 Redis，显著降低磁盘 I/O，提升高并发性能。
        </p>
        <form method="post">
            <div class="form-group">
                <label class="form-label">Redis 开关</label>
                <label class="toggle-label">
                    <input type="checkbox" name="redis_enabled" value="1"
                           id="redisEnabledToggle"
                           <?php echo $redisCv['redis_enabled'] ? 'checked' : ''; ?>>
                    <span class="toggle-text <?php echo $redisCv['redis_enabled'] ? 'toggle-on' : 'toggle-off'; ?>"
                          id="redisEnabledText">
                        <?php echo $redisCv['redis_enabled'] ? '已启用' : '已关闭'; ?>
                    </span>
                </label>
            </div>

            <div id="redisFields" style="<?php echo $redisCv['redis_enabled'] ? '' : 'display:none;'; ?>">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px;">
                    <div class="form-group">
                        <label class="form-label">Redis 主机</label>
                        <input type="text" name="redis_host" class="form-input"
                               value="<?php echo htmlspecialchars($redisCv['redis_host']); ?>"
                               placeholder="127.0.0.1">
                        <p class="form-hint">本机填 127.0.0.1，远程填服务器 IP</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Redis 端口</label>
                        <input type="number" name="redis_port" class="form-input"
                               value="<?php echo (int)$redisCv['redis_port']; ?>"
                               min="1" max="65535" placeholder="6379">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
                    <div class="form-group">
                        <label class="form-label">Redis 密码</label>
                        <input type="text" name="redis_auth" class="form-input"
                               value="<?php echo htmlspecialchars($redisCv['redis_auth']); ?>"
                               placeholder="无密码留空">
                    </div>
                    <div class="form-group">
                        <label class="form-label">数据库编号 (DB)</label>
                        <input type="number" name="redis_db" class="form-input"
                               value="<?php echo (int)$redisCv['redis_db']; ?>"
                               min="0" max="15" placeholder="1">
                        <p class="form-hint">建议使用非 0 号库与其他应用隔离</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">连接超时 (秒)</label>
                        <input type="number" name="redis_timeout" class="form-input"
                               value="<?php echo $redisCv['redis_timeout']; ?>"
                               min="0.1" max="10" step="0.1" placeholder="0.5">
                    </div>
                </div>
            </div>

            <!-- 实例ID 始终可见，与 Redis 开关无关 -->
            <div class="form-group" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);">
                <label class="form-label">实例 ID（Instance ID）</label>
                <input type="text" name="redis_instance_id" class="form-input"
                       value="<?php echo htmlspecialchars($redisCv['redis_instance_id']); ?>"
                       placeholder="留空则自动生成（推荐）">
                <p class="form-hint">
                    多网站共用同一 Redis 时填写，用于区分各站键名（格式：<code>waf:{instanceID}:*</code>）。
                    留空系统自动用安装路径生成，无需手动填写。
                    <strong style="color:var(--yellow);">修改后需重新写入 IP 白名单，旧键名数据不会自动迁移。</strong>
                </p>
                <div style="margin-top:8px;padding:10px 14px;background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm);font-size:12px;font-family:monospace;color:var(--text2);">
                    当前实例ID：<span style="color:var(--blue);"><?php echo htmlspecialchars($instanceId); ?></span>
                    &nbsp;|&nbsp; IP白名单键：<span style="color:var(--text3);"><?php echo htmlspecialchars(ipWhitelistKey()); ?></span>
                </div>
            </div>

            <div class="btn-group" style="margin-top:16px;">
                <button type="submit" name="save_redis_config" value="1" class="btn btn-success">💾 保存配置</button>
            </div>
        </form>
    </div>

    <!-- ══ 连接状态栏 ══ -->
    <?php if (!$cfgEnabled): ?>
    <div class="redis-status-bar disabled">
        <span class="badge-dot gray"></span>
        <strong>Redis 未启用</strong>
        <span class="rs-sub">— 当前使用文件模式，启用请在上方配置表单中打开开关并保存</span>
    </div>
    <?php elseif ($connected): ?>
    <div class="redis-status-bar ok">
        <span class="badge-dot green"></span>
        <strong>Redis 连接正常</strong>
        <span class="rs-sub">— <?php echo htmlspecialchars($cfgHost); ?>:<?php echo $cfgPort; ?> &nbsp;|&nbsp; DB <?php echo $cfgDB; ?> &nbsp;|&nbsp; 版本 <?php echo htmlspecialchars($redisInfo['version']); ?></span>
    </div>
    <?php else: ?>
    <div class="redis-status-bar fail">
        <span class="badge-dot red"></span>
        <strong>Redis 连接失败</strong>
        <span class="rs-sub">— 请检查服务器地址、端口、密码及 php-redis 扩展是否安装</span>
    </div>
    <?php endif; ?>

    <!-- ══ 数据统计 ══ -->
    <div class="card" style="margin-bottom:18px;">
        <div class="card-title">📊 数据统计</div>
        <div class="stat-grid">
            <div class="stat-box">
                <div class="stat-num <?php echo $ipCount >= 0 ? 'ok' : 'err'; ?>">
                    <?php echo $ipCount >= 0 ? $ipCount : '—'; ?>
                </div>
                <div class="stat-label">Redis IP白名单（AB段）</div>
                <div class="stat-sub">key: <?php echo htmlspecialchars(ipWhitelistKey()); ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-num" style="color:#555;">
                    <?php echo $jsonCount; ?>
                </div>
                <div class="stat-label">本地文件 IP白名单</div>
                <div class="stat-sub">ip_whitelist.json</div>
            </div>
            <div class="stat-box">
                <div class="stat-num <?php echo $uaCount >= 0 ? 'ok' : 'err'; ?>">
                    <?php echo $uaCount >= 0 ? $uaCount : '—'; ?>
                </div>
                <div class="stat-label">设备识别缓存（UA）</div>
                <div class="stat-sub">waf:shared:ua:*</div>
            </div>
            <div class="stat-box">
                <div class="stat-num <?php
                    if ($syncLockTTL > 0) echo 'warn';
                    elseif ($syncLockTTL === -2 || $syncLockTTL < 0) echo 'ok';
                    else echo 'err';
                ?>">
                    <?php
                    if ($syncLockTTL > 0)   echo $syncLockTTL . 's';
                    elseif (!$connected)     echo '—';
                    else                     echo '空闲';
                    ?>
                </div>
                <div class="stat-label">IP同步锁剩余时间</div>
                <div class="stat-sub">锁存在=同步保护中</div>
            </div>
        </div>
    </div>

    <!-- ══ 配置信息 ══ -->
    <div class="card" style="margin-bottom:18px;">
        <div class="card-title">⚙️ 当前 Redis 配置</div>
        <table class="info-table">
            <tr><td>启用状态</td><td><?php echo $cfgEnabled ? '✅ 已启用' : '❌ 未启用'; ?></td></tr>
            <tr><td>服务器地址</td><td><?php echo htmlspecialchars($cfgHost); ?>:<?php echo $cfgPort; ?></td></tr>
            <tr><td>存储 DB 库</td><td>DB <?php echo $cfgDB; ?></td></tr>
            <tr><td>连接超时</td><td><?php echo $cfgTimeout; ?>s</td></tr>
            <tr><td>认证密码</td><td><?php echo htmlspecialchars($cfgAuth); ?></td></tr>
            <tr><td>实例 ID</td><td><?php echo htmlspecialchars($instanceId); ?></td></tr>
            <?php if ($connected): ?>
            <tr><td>Redis 版本</td><td><?php echo htmlspecialchars($redisInfo['version']); ?></td></tr>
            <tr><td>已运行</td><td><?php echo $redisInfo['uptime']; ?> 天</td></tr>
            <tr><td>内存使用</td><td><?php echo htmlspecialchars($redisInfo['used_mem']); ?> / 峰值 <?php echo htmlspecialchars($redisInfo['peak_mem']); ?></td></tr>
            <?php endif; ?>
        </table>
        <p style="font-size:12px;color:var(--text3);margin-top:12px;">修改配置请前往 <a href="config_manage.php#tab-maintain" style="color:var(--blue);">系统配置 → 系统维护</a>，或直接编辑 <code>ad_seo/config.php</code></p>
    </div>

    <!-- ══ 操作区 ══ -->
    <div class="card">
        <div class="card-title">🛠️ 数据操作</div>
        <?php if (!$cfgEnabled || !$connected): ?>
        <div class="info-box info-yellow">
            <?php echo !$cfgEnabled ? 'Redis 未启用，操作不可用。' : 'Redis 连接失败，操作不可用。'; ?>
        </div>
        <?php else: ?>
        <div class="op-grid">

            <!-- 重新写入IP白名单 -->
            <div class="op-card">
                <div class="op-title">🔄 重新写入 IP 白名单</div>
                <div class="op-desc">
                    将本地 <code>ip_whitelist.json</code> 文件中的 IP AB段全量写入 Redis。<br>
                    适用于：修改了配置、换了实例ID、或Redis数据丢失后重建。<br>
                    <strong>本地文件共 <?php echo $jsonCount; ?> 条，当前Redis有 <?php echo $ipCount; ?> 条。</strong>
                </div>
                <form method="post" onsubmit="return confirm('确认从本地文件重新写入IP白名单到Redis？');">
                    <input type="hidden" name="action" value="reload_whitelist">
                    <button type="submit" class="btn btn-primary">🔄 重新写入</button>
                </form>
            </div>

            <!-- 清除Redis IP白名单 -->
            <div class="op-card">
                <div class="op-title">🗑️ 清除 Redis IP 白名单</div>
                <div class="op-desc">
                    删除 Redis 中的 IP 白名单数据（本地 JSON 文件保留不变）。<br>
                    清除后下次同步将自动重新写入，或手动点击"重新写入"。<br>
                    同步锁也会一并清除，允许立即触发新一次同步。
                </div>
                <form method="post" onsubmit="return confirm('确认清除 Redis 中的IP白名单？（本地文件不受影响）');">
                    <input type="hidden" name="action" value="clear_whitelist">
                    <button type="submit" class="btn btn-warning">🗑️ 清除白名单</button>
                </form>
            </div>

            <!-- 清除UA设备缓存 -->
            <div class="op-card">
                <div class="op-title">🧹 清除设备识别缓存</div>
                <div class="op-desc">
                    删除所有 <code>waf:shared:ua:*</code> 缓存记录（共 <?php echo $uaCount; ?> 条）。<br>
                    适用于：更新了 Matomo 设备库后，需要让新库的识别结果覆盖旧缓存。<br>
                    清除后各站点会自动重新识别并写入新缓存，不影响正常访问。
                </div>
                <form method="post" onsubmit="return confirm('确认清除全部设备识别缓存？清除后将重新识别各UA。');">
                    <input type="hidden" name="action" value="clear_ua_cache">
                    <button type="submit" class="btn btn-warning">🧹 清除UA缓存</button>
                </form>
            </div>

            <!-- 导出 Bot UA -->
            <div class="op-card">
                <div class="op-title">🤖 导出 Bot UA 列表</div>
                <div class="op-desc">
                    导出被识别为爬虫/机器人的 UA 字符串记录，按最新访问时间排序，含时间戳，下载为 .txt 文件。
                </div>
                <a href="redis_manage.php?action=export_bot_uas" class="btn btn-primary">📥 导出 Bot UA</a>
            </div>

            <!-- 清除全部WAF数据 -->
            <div class="op-card danger">
                <div class="op-title">⚠️ 清除全部 WAF 数据</div>
                <div class="op-desc">
                    删除当前实例（<code><?php echo htmlspecialchars($instanceId); ?></code>）的所有 key<br>
                    以及全局共享的 UA 缓存（<code>waf:shared:*</code>）。<br>
                    <strong style="color:var(--red);">此操作不可恢复，清除后从文件重建需时间。</strong>
                </div>
                <form method="post" onsubmit="return confirm('⚠️ 确认清除全部WAF Redis数据？此操作不可恢复！');">
                    <input type="hidden" name="action" value="clear_all">
                    <button type="submit" class="btn btn-danger">⚠️ 清除全部数据</button>
                </form>
            </div>

        </div>
        <?php endif; ?>
    </div>

</div><!-- /.admin-layout -->

<script src="admin.js"></script>
</body>
</html>
