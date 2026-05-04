<?php
/**
 * WAF 拦截系统 — 系统配置管理
 * 布局顺序：总开关 → 拦截条件 → PC端 → 移动端 → IP白名单 → UA配置 → 其他
 */
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

define('CONFIG_DIR',  dirname(__DIR__));
define('CONFIG_FILE', CONFIG_DIR . '/config.php');
define('WHITELIST_DIR', CONFIG_DIR . '/whitelist');

if (!is_dir(WHITELIST_DIR)) mkdir(WHITELIST_DIR, 0755, true);

function checkLogin(): bool {
    return isset($_SESSION['waf_admin_logged_in']) && $_SESSION['waf_admin_logged_in'] === true;
}
if (!checkLogin()) { header('Location: index.php'); exit; }

/* ──────────────────────────────────────────────
   工具：读写 config.php 中的 define 常量
   ────────────────────────────────────────────── */
function cfgReadString(string $content, string $key, string $default = ''): string {
    preg_match("/define\('{$key}',\s*'([^']*)'\);/", $content, $m);
    return $m[1] ?? $default;
}
function cfgReadBool(string $content, string $key, bool $default = true): bool {
    preg_match("/define\('{$key}',\s*(true|false)\);/", $content, $m);
    if (!isset($m[1])) return $default;
    return $m[1] === 'true';
}
function cfgReadInt(string $content, string $key, int $default = 0): int {
    preg_match("/define\('{$key}',\s*(\d+)\);/", $content, $m);
    return isset($m[1]) ? (int)$m[1] : $default;
}
function cfgReadArray(string $content, string $key): array {
    preg_match("/define\('{$key}',\s*\[(.*?)\]\);/s", $content, $m);
    if (empty($m[1])) return [];
    preg_match_all("/'([^']+)'/", $m[1], $vals);
    return $vals[1] ?? [];
}

function cfgWriteString(string $content, string $key, string $value): string {
    $esc = addslashes($value);
    return preg_replace("/define\('{$key}',\s*'[^']*'\);/", "define('{$key}', '{$esc}');", $content) ?? $content;
}
function cfgWriteBool(string $content, string $key, bool $value): string {
    $v = $value ? 'true' : 'false';
    return preg_replace("/define\('{$key}',\s*(true|false)\);/", "define('{$key}', {$v});", $content) ?? $content;
}
function cfgWriteInt(string $content, string $key, int $value): string {
    return preg_replace("/define\('{$key}',\s*\d+\);/", "define('{$key}', {$value});", $content) ?? $content;
}
function cfgReadFloat(string $content, string $key, float $default = 0.5): float {
    preg_match("/define\('{$key}',\s*([0-9.]+)\);/", $content, $m);
    return isset($m[1]) ? (float)$m[1] : $default;
}
function cfgWriteFloat(string $content, string $key, float $value): string {
    return preg_replace("/define\('{$key}',\s*[0-9.]+\);/", "define('{$key}', {$value});", $content) ?? $content;
}
function cfgWriteArray(string $content, string $key, array $items): string {
    $lines = '';
    foreach ($items as $item) {
        $lines .= "    '" . addslashes($item) . "',\n";
    }
    $replacement = "define('{$key}', [\n{$lines}]);";
    return preg_replace("/define\('{$key}',\s*\[[^\]]*\]\);/s", $replacement, $content) ?? $content;
}

/* ──────────────────────────────────────────────
   设备库工具函数
   ────────────────────────────────────────────── */
define('PLUGIN_DIR',       CONFIG_DIR . '/plugin');
define('COMPOSER_BIN',     PLUGIN_DIR . '/composer');
define('COMPOSER_LOCK',    PLUGIN_DIR . '/composer.lock');
define('DEVICE_LIB_STAMP', PLUGIN_DIR . '/.device_lib_updated');
define('DEVICE_UPDATE_INTERVAL', 15 * 86400); // 15 天

function deviceLibVersion(): string {
    $lock = @file_get_contents(COMPOSER_LOCK);
    if (!$lock) return '未知';
    $data = json_decode($lock, true);
    foreach ($data['packages'] ?? [] as $pkg) {
        if ($pkg['name'] === 'matomo/device-detector') return $pkg['version'];
    }
    return '未知';
}

function deviceLibLastUpdate(): int {
    return (int)(@filemtime(DEVICE_LIB_STAMP) ?: 0);
}

function deviceLibNextUpdate(): int {
    return deviceLibLastUpdate() + DEVICE_UPDATE_INTERVAL;
}

function deviceLibRunUpdate(): array {
    // 优先找 php 可执行文件
    $php = PHP_BINARY ?: 'php';
    $composer = COMPOSER_BIN;

    if (!file_exists($composer)) {
        return ['ok' => false, 'msg' => '找不到 composer，路径：' . $composer];
    }

    $cmd    = escapeshellarg($php) . ' ' . escapeshellarg($composer)
            . ' update matomo/device-detector --no-interaction --no-ansi 2>&1';
    $output = shell_exec('cd ' . escapeshellarg(PLUGIN_DIR) . ' && ' . $cmd);

    if ($output === null) {
        return ['ok' => false, 'msg' => 'shell_exec 不可用或命令执行失败'];
    }

    // 更新后执行清理脚本，删除无关规则文件
    $cleanup = PLUGIN_DIR . '/cleanup.php';
    if (file_exists($cleanup)) {
        shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($cleanup) . ' 2>&1');
    }

    // 记录时间戳
    file_put_contents(DEVICE_LIB_STAMP, date('Y-m-d H:i:s'));

    return ['ok' => true, 'msg' => trim($output)];
}

/* ──────────────────────────────────────────────
   保存处理
   ────────────────────────────────────────────── */
$msg = '';

// 手动更新设备库
if (isset($_POST['update_device_lib'])) {
    $result = deviceLibRunUpdate();
    $status = $result['ok'] ? 'device_updated' : 'device_update_fail';
    $detail = urlencode(mb_substr($result['msg'], 0, 300));
    header("Location: config_manage.php?msg={$status}&detail={$detail}#tab-maintain");
    exit;
}

// 保存所有主配置
if (isset($_POST['save_config'])) {
    $cfg = file_get_contents(CONFIG_FILE);

    // 拦截模式
    $interceptMode = in_array($_POST['intercept_mode'] ?? '', ['strict','ua_only'])
        ? $_POST['intercept_mode'] : 'strict';
    $cfg = cfgWriteString($cfg, 'INTERCEPT_MODE', $interceptMode);

    // PC 设置
    $cfg = cfgWriteBool($cfg,   'PC_ENABLED', isset($_POST['pc_enabled']));
    $pcMode = in_array($_POST['pc_ad_mode'] ?? '', ['none','iframe','redirect'])
        ? $_POST['pc_ad_mode'] : 'none';
    $cfg = cfgWriteString($cfg, 'PC_AD_MODE', $pcMode);
    $cfg = cfgWriteString($cfg, 'PC_AD_URL',  trim($_POST['pc_ad_url'] ?? ''));
    $cfg = cfgWriteBool($cfg,   'PC_STAT_INJECT', isset($_POST['pc_stat_inject']));

    // 移动端设置
    $cfg = cfgWriteBool($cfg,   'MOBILE_ENABLED', isset($_POST['mobile_enabled']));
    $mobileMode = in_array($_POST['mobile_ad_mode'] ?? '', ['none','iframe','redirect'])
        ? $_POST['mobile_ad_mode'] : 'none';
    $cfg = cfgWriteString($cfg, 'MOBILE_AD_MODE', $mobileMode);
    $cfg = cfgWriteString($cfg, 'MOBILE_AD_URL',  trim($_POST['mobile_ad_url'] ?? ''));
    $cfg = cfgWriteBool($cfg,   'MOBILE_STAT_INJECT', isset($_POST['mobile_stat_inject']));

    // IP 白名单 URL 列表
    $ipUrls = array_values(array_filter(array_map('trim', explode("\n", $_POST['ip_whitelist_urls'] ?? ''))));
    $cfg = cfgWriteArray($cfg, 'IP_WHITELIST_URLS', $ipUrls);

    // 爬虫 UA 列表
    $botUaList = array_values(array_filter(array_map('trim', explode("\n", $_POST['bot_ua_list'] ?? ''))));
    $cfg = cfgWriteArray($cfg, 'BOT_UA_LIST', $botUaList);

    // 管理员绕过 UA
    $bypassList = array_values(array_filter(array_map('trim', explode("\n", $_POST['ua_bypass_list'] ?? ''))));
    $cfg = cfgWriteArray($cfg, 'UA_BYPASS_LIST', $bypassList);

    // 其他
    // 百度统计 ID（多行 → 逗号分隔）
    $statsRaw = $_POST['statistics_id'] ?? '';
    $statsIds = array_filter(array_map('trim', preg_split('/[\n,]+/', $statsRaw)));
    $cfg = cfgWriteString($cfg, 'STATISTICS_ID', implode(',', $statsIds));

    // 51la 统计 ID（多行 → 逗号分隔）
    $la51Raw = $_POST['la51_ids'] ?? '';
    $la51Ids = array_filter(array_map('trim', preg_split('/[\n,]+/', $la51Raw)));
    $cfg = cfgWriteString($cfg, 'LA51_IDS', implode(',', $la51Ids));
    $cfg = cfgWriteString($cfg, 'TEMPLATE_FOLDER', trim($_POST['template_folder'] ?? '404'));
    $cfg = cfgWriteString($cfg, 'ADMIN_TITLE',     trim($_POST['admin_title'] ?? 'WAF拦截系统'));
    $cfg = cfgWriteString($cfg, 'ADMIN_PASSWORD',  trim($_POST['admin_password'] ?? 'admin2025'));

    if (file_put_contents(CONFIG_FILE, $cfg) !== false) {
        header('Location: config_manage.php?msg=saved&t=' . time()); exit;
    }
}

define('CURRENT_PAGE', 'config');

/* ──────────────────────────────────────────────
   读取当前配置值
   ────────────────────────────────────────────── */
$cfg = file_get_contents(CONFIG_FILE);

$cv = [
    'intercept_mode'     => cfgReadString($cfg, 'INTERCEPT_MODE', 'strict'),
    'pc_enabled'         => cfgReadBool($cfg, 'PC_ENABLED', true),
    'pc_ad_mode'         => cfgReadString($cfg, 'PC_AD_MODE', 'none'),
    'pc_ad_url'          => cfgReadString($cfg, 'PC_AD_URL', ''),
    'pc_stat_inject'     => cfgReadBool($cfg, 'PC_STAT_INJECT', false),
    'mobile_enabled'     => cfgReadBool($cfg, 'MOBILE_ENABLED', true),
    'mobile_ad_mode'     => cfgReadString($cfg, 'MOBILE_AD_MODE', 'none'),
    'mobile_ad_url'      => cfgReadString($cfg, 'MOBILE_AD_URL', ''),
    'mobile_stat_inject' => cfgReadBool($cfg, 'MOBILE_STAT_INJECT', false),
    'ip_whitelist_urls'  => implode("\n", cfgReadArray($cfg, 'IP_WHITELIST_URLS')),
    'bot_ua_list'        => implode("\n", cfgReadArray($cfg, 'BOT_UA_LIST')),
    'ua_bypass_list'     => implode("\n", cfgReadArray($cfg, 'UA_BYPASS_LIST')),
    'statistics_id'      => cfgReadString($cfg, 'STATISTICS_ID', ''),
    'la51_ids'           => cfgReadString($cfg, 'LA51_IDS', ''),
    'template_folder'    => cfgReadString($cfg, 'TEMPLATE_FOLDER', '404'),
    'admin_title'        => cfgReadString($cfg, 'ADMIN_TITLE', 'WAF拦截系统'),
    'admin_password'     => cfgReadString($cfg, 'ADMIN_PASSWORD', 'admin2025'),
];

// 扫描模板目录
$templates     = [];
$templatesDir  = CONFIG_DIR . '/templates';
if (is_dir($templatesDir)) {
    foreach (scandir($templatesDir) as $f) {
        if ($f === '.' || $f === '..' || !is_dir($templatesDir . '/' . $f)) continue;
        $hasPhp  = file_exists($templatesDir . '/' . $f . '/redirect_m.php')
                || file_exists($templatesDir . '/' . $f . '/redirect_pc.php');
        $hasHtml = file_exists($templatesDir . '/' . $f . '/m.html')
                || file_exists($templatesDir . '/' . $f . '/pc.html');
        $templates[] = ['name' => $f, 'php' => $hasPhp, 'html' => $hasHtml];
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统配置 — <?php echo htmlspecialchars($cv['admin_title']); ?></title>
    <link rel="stylesheet" href="admin.css">
</head>
<body>
    <button class="mobile-menu-btn" onclick="toggleSidebar()">☰</button>

    <div class="admin-layout">
        <?php include 'header.php'; ?>

        <!-- 提示消息 -->
        <?php if (isset($_GET['msg'])): ?>
            <?php if ($_GET['msg'] === 'saved'): ?>
                <div class="alert alert-success">✅ 配置已保存成功！</div>
            <?php elseif ($_GET['msg'] === 'master_saved'): ?>
                <div class="alert alert-success">✅ 总开关已更新！</div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="page-header">
            <h1>⚙️ 系统配置</h1>
        </div>

        <!-- ══════════════ Tab 导航 ══════════════ -->
        <nav class="tab-nav">
            <button type="button" class="tab-btn active"
                    data-tab="global" onclick="switchTab('global')">
                <span class="tab-btn-icon">⚙️</span>
                <span class="tab-btn-label">全局设置</span>
            </button>
            <button type="button" class="tab-btn"
                    data-tab="pc" onclick="switchTab('pc')">
                <span class="tab-btn-icon">💻</span>
                <span class="tab-btn-label">PC 设置</span>
                <span class="tab-btn-badge <?php echo $cv['pc_enabled']?'badge-green':'badge-red'; ?>">
                    <?php echo $cv['pc_enabled']?'启用':'关闭'; ?>
                </span>
            </button>
            <button type="button" class="tab-btn"
                    data-tab="mobile" onclick="switchTab('mobile')">
                <span class="tab-btn-icon">📱</span>
                <span class="tab-btn-label">移动设置</span>
                <span class="tab-btn-badge <?php echo $cv['mobile_enabled']?'badge-green':'badge-red'; ?>">
                    <?php echo $cv['mobile_enabled']?'启用':'关闭'; ?>
                </span>
            </button>
            <button type="button" class="tab-btn <?php echo (deviceLibNextUpdate() < time()) ? 'tab-btn-warn' : ''; ?>"
                    data-tab="maintain" onclick="switchTab('maintain')">
                <span class="tab-btn-icon">🔧</span>
                <span class="tab-btn-label">系统维护</span>
                <?php if (deviceLibNextUpdate() < time()): ?>
                <span class="tab-btn-badge badge-yellow">需更新</span>
                <?php endif; ?>
            </button>
        </nav>

        <!-- 所有 Tab 面板共用一个表单 -->
        <form method="post" id="mainForm">

        <!-- ══════════════════════════════════════════
             Tab ① 全局设置
        ═════════════════════════════════════════════ -->
        <div class="tab-panel active" id="tab-global">

            <div class="btn-group" style="margin-bottom:8px;">
                <button type="submit" name="save_config" class="btn btn-success btn-lg">💾 保存全局配置</button>
                <button type="button" class="btn btn-ghost btn-lg" onclick="location.reload()">🔄 刷新</button>
            </div>

            <!-- 基础配置 + 拦截条件模式 并排 -->
            <div class="device-grid">

                <!-- 基础配置 -->
                <div class="card">
                    <div class="card-title">🔧 基础配置</div>
                    <div class="form-group">
                        <label class="form-label">后台名称</label>
                        <input type="text" name="admin_title" class="form-input"
                               value="<?php echo htmlspecialchars($cv['admin_title']); ?>" placeholder="WAF拦截系统">
                        <p class="form-hint">显示在侧边栏顶部，用于区分不同网站的后台</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">管理密码</label>
                        <input type="text" name="admin_password" class="form-input"
                               value="<?php echo htmlspecialchars($cv['admin_password']); ?>" placeholder="admin2025">
                        <p class="form-hint">保存后下次登录生效</p>
                    </div>
                </div>

                <!-- 拦截条件模式 -->
                <div class="card">
                    <div class="card-title">
                        🎯 拦截条件模式 <span class="badge badge-yellow">全局</span>
                    </div>
                    <div class="info-box info-yellow" style="margin-bottom:14px;">
                        对 PC 端和移动端同时生效，决定何时放行爬虫请求。
                    </div>
                    <div class="radio-group">
                        <label class="radio-option <?php echo $cv['intercept_mode']==='strict'?'selected':''; ?>">
                            <input type="radio" name="intercept_mode" value="strict"
                                   <?php echo $cv['intercept_mode']==='strict'?'checked':''; ?>>
                            <div class="radio-option-text">
                                <strong>🔒 严格模式</strong>
                                <span>UA 头匹配 → 再验证真实 IP 的 AB 段（只用 REMOTE_ADDR，防模拟IP绕过）→ 两项都通过才放行</span>
                            </div>
                            <span class="radio-option-tag badge-yellow">推荐</span>
                        </label>
                        <label class="radio-option <?php echo $cv['intercept_mode']==='ua_only'?'selected':''; ?>">
                            <input type="radio" name="intercept_mode" value="ua_only"
                                   <?php echo $cv['intercept_mode']==='ua_only'?'checked':''; ?>>
                            <div class="radio-option-text">
                                <strong>🔓 仅 UA 模式</strong>
                                <span>只验证 UA 头，匹配爬虫列表则直接放行，不做 IP 验证</span>
                            </div>
                            <span class="radio-option-tag badge-blue">宽松</span>
                        </label>
                    </div>
                </div>

            </div>

            <!-- 统计配置：百度 + 51la 各一张卡片并排 -->
            <div class="device-grid">

                <div class="card">
                    <div class="card-title">📊 百度统计
                        <span class="badge badge-blue" style="font-size:11px;">每行一个 ID</span>
                    </div>
                    <div class="form-group">
                        <textarea name="statistics_id" class="form-textarea" rows="5"
                                  placeholder="21998659&#10;abcdef1234567890"
                                  style="font-family:monospace;font-size:13px;"><?php
                            echo htmlspecialchars(str_replace(',', "\n", $cv['statistics_id']));
                        ?></textarea>
                        <p class="form-hint">使用 <code>baidu.txt</code> 模板，有几个 ID 生成几段脚本</p>
                    </div>
                </div>

                <div class="card">
                    <div class="card-title">📊 51la 统计
                        <span class="badge badge-purple" style="font-size:11px;">每行一个 ID</span>
                    </div>
                    <div class="form-group">
                        <textarea name="la51_ids" class="form-textarea" rows="5"
                                  placeholder="JnxxxxxxxxxxxxxxA&#10;另一个51la_ID"
                                  style="font-family:monospace;font-size:13px;"><?php
                            echo htmlspecialchars(str_replace(',', "\n", $cv['la51_ids']));
                        ?></textarea>
                        <p class="form-hint">使用 <code>51.txt</code> 模板，有几个 ID 生成几段脚本</p>
                    </div>
                </div>

            </div>

            <!-- IP 白名单配置 -->
            <div class="card">
                <div class="card-title">🔒 IP 白名单配置</div>
                <div class="info-box" style="margin-bottom:16px;">
                    存储为 JSON 数组，记录 IP 前两段（AB 段），如 <code>"220.181"</code> 代表 220.181.x.x 整段。
                    严格模式下，爬虫 UA 匹配后只使用 <code>$_SERVER['REMOTE_ADDR']</code>（TCP 真实 IP）验证，
                    <strong style="color:var(--red);">完全忽略 X-Forwarded-For 等可伪造 Header</strong>。
                </div>
                <div class="form-group">
                    <div class="form-label-badge" style="background:var(--green-dim);color:var(--green);border:1px solid var(--green);">
                        🌐 远程同步 URL（每5分钟自动拉取，支持多个备用地址）
                    </div>
                    <textarea name="ip_whitelist_urls" class="form-textarea" rows="4"
                              placeholder="http://ip.3306.site/data/bing-baidu-google.txt&#10;http://backup-url/data/ips.txt"><?php
                        echo htmlspecialchars($cv['ip_whitelist_urls']);
                    ?></textarea>
                    <p class="form-hint">每行一个 URL，第一个失败后自动尝试下一个。远程 IP 自动转换为 AB 段，<strong>只追加新 AB 段，不覆盖已有数据</strong></p>
                </div>
                <?php
                $wlFile   = WHITELIST_DIR . '/ip_whitelist.json';
                $wlData   = [];
                $syncTime = '尚未同步';
                if (file_exists($wlFile)) {
                    $wlData   = json_decode(file_get_contents($wlFile), true) ?: [];
                    $syncTime = date('Y-m-d H:i:s', filemtime($wlFile));
                }
                $wlCount = count($wlData);
                ?>
                <div class="stat-grid" style="margin-bottom:14px;">
                    <div class="stat-card">
                        <div class="stat-card-label">当前 AB 段数量</div>
                        <div class="stat-card-value" style="color:var(--blue);"><?php echo $wlCount; ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-label">白名单文件</div>
                        <div style="font-size:12px;font-family:monospace;margin-top:8px;color:var(--text2);">whitelist/ip_whitelist.json</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-label">最后同步时间</div>
                        <div style="font-size:13px;margin-top:8px;color:var(--text2);"><?php echo $syncTime; ?></div>
                    </div>
                </div>
                <div class="form-group">
                    <div class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
                        <span>当前白名单 AB 段预览</span>
                        <a href="whitelist.php" class="btn btn-ghost btn-sm">🔒 详细管理</a>
                    </div>
                    <div class="json-preview"><?php
                        echo empty($wlData) ? '（暂无数据）'
                            : htmlspecialchars(json_encode($wlData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    ?></div>
                </div>
            </div>

            <!-- UA 配置 -->
            <div class="card">
                <div class="card-title">🤖 UA 配置</div>
                <div class="grid-2">
                    <div class="form-group">
                        <div class="form-label-badge" style="background:var(--blue-dim);color:var(--blue);border:1px solid var(--blue);">
                            🔍 爬虫 UA 列表（拦截条件判断用）
                        </div>
                        <textarea name="bot_ua_list" class="form-textarea" rows="10"
                                  placeholder="Baiduspider&#10;googlebot&#10;bingbot"><?php
                            echo htmlspecialchars($cv['bot_ua_list']);
                        ?></textarea>
                        <p class="form-hint">每行一个关键词（不区分大小写），UA 中包含任意一个则视为爬虫</p>
                    </div>
                    <div class="form-group">
                        <div class="form-label-badge" style="background:var(--purple-dim);color:var(--purple);border:1px solid var(--purple);">
                            🛡️ 管理员绕过 UA
                        </div>
                        <textarea name="ua_bypass_list" class="form-textarea" rows="10"
                                  placeholder="seo in my life&#10;my-custom-ua"><?php
                            echo htmlspecialchars($cv['ua_bypass_list']);
                        ?></textarea>
                        <p class="form-hint">每行一个字符串（区分大小写），匹配则<strong>跳过所有拦截直接放行</strong></p>
                    </div>
                </div>
            </div>

            <div class="btn-group">
                <button type="submit" name="save_config" class="btn btn-success btn-lg">💾 保存全局配置</button>
                <button type="button" class="btn btn-ghost btn-lg" onclick="location.reload()">🔄 刷新</button>
            </div>

        </div><!-- end tab-global -->

        <!-- ══════════════════════════════════════════
             Tab ② PC 设置
        ═════════════════════════════════════════════ -->
        <div class="tab-panel" id="tab-pc">

            <div class="card">
                <div class="card-title">
                    💻 PC 端设置
                    <span class="badge <?php echo $cv['pc_enabled']?'badge-green':'badge-red'; ?>">
                        <?php echo $cv['pc_enabled']?'已启用':'已关闭'; ?>
                    </span>
                </div>

                <div class="switch-row">
                    <div class="switch-row-info">
                        <strong>PC 端拦截开关</strong>
                        <span>关闭后 PC 用户全部放行</span>
                    </div>
                    <label class="toggle-label">
                        <input type="checkbox" id="pcSwitch" name="pc_enabled" value="1"
                               <?php echo $cv['pc_enabled']?'checked':''; ?>>
                        <span class="toggle-text <?php echo $cv['pc_enabled']?'toggle-on':'toggle-off'; ?>"
                              id="pcSwitchLabel">
                            <?php echo $cv['pc_enabled']?'已启用':'已关闭'; ?>
                        </span>
                    </label>
                </div>


                <div class="form-group" style="margin-top:16px;">
                    <div class="form-label-badge" style="background:var(--blue-dim);color:var(--blue);border:1px solid var(--blue);">
                        📢 PC 端拦截后广告策略
                    </div>
                    <div class="radio-group">
                        <label class="radio-option <?php echo $cv['pc_ad_mode']==='none'?'selected':''; ?>">
                            <input type="radio" name="pc_ad_mode" value="none"
                                   <?php echo $cv['pc_ad_mode']==='none'?'checked':''; ?>>
                            <div class="radio-option-text">
                                <strong>🚫 不投放广告</strong>
                                <span>仅显示拦截模板页面（404 / 500 等），不加载任何广告</span>
                            </div>
                        </label>
                        <label class="radio-option <?php echo $cv['pc_ad_mode']==='iframe'?'selected':''; ?>">
                            <input type="radio" name="pc_ad_mode" value="iframe"
                                   <?php echo $cv['pc_ad_mode']==='iframe'?'checked':''; ?>>
                            <div class="radio-option-text">
                                <strong>🖼️ 全屏 iframe 覆盖</strong>
                                <span>在拦截页上方覆盖全屏 iframe，加载指定广告地址</span>
                            </div>
                            <span class="radio-option-tag badge-purple">全覆盖</span>
                        </label>
                        <label class="radio-option <?php echo $cv['pc_ad_mode']==='redirect'?'selected':''; ?>">
                            <input type="radio" name="pc_ad_mode" value="redirect"
                                   <?php echo $cv['pc_ad_mode']==='redirect'?'checked':''; ?>>
                            <div class="radio-option-text">
                                <strong>↪️ JS 跳转</strong>
                                <span>通过 <code>window.location.href</code> 跳转到指定地址</span>
                            </div>
                            <span class="radio-option-tag" style="background:var(--yellow-dim);color:var(--yellow);border:1px solid var(--yellow);">302跳转</span>
                        </label>
                    </div>
                    <div class="url-input-wrap <?php echo in_array($cv['pc_ad_mode'],['iframe','redirect'])?'visible':''; ?>"
                         id="pc_url_wrap">
                        <label class="form-label" id="pc_url_label">
                            <?php echo $cv['pc_ad_mode']==='redirect'?'JS 跳转目标地址':'iframe 覆盖地址'; ?>
                        </label>
                        <input type="text" name="pc_ad_url" class="form-input"
                               value="<?php echo htmlspecialchars($cv['pc_ad_url']); ?>"
                               placeholder="https://example.com/ad-page">
                        <p class="form-hint">填写广告落地页或推广地址，客户端 JS 根据屏幕宽度触发</p>
                    </div>
                </div>

                <div class="form-group" id="pcStatInjectRow" style="margin-top:16px;<?php echo $cv['pc_enabled']?'display:none;':''; ?>">
                    <div class="form-label-badge" style="background:var(--yellow-dim);color:var(--yellow);border:1px solid var(--yellow);">
                        📊 PC 端关闭时仍注入统计
                    </div>
                    <label class="toggle-label">
                        <input type="checkbox" name="pc_stat_inject" value="1"
                               <?php echo $cv['pc_stat_inject']?'checked':''; ?>>
                        <span class="toggle-text <?php echo $cv['pc_stat_inject']?'toggle-on':'toggle-off'; ?>">
                            <?php echo $cv['pc_stat_inject']?'已启用':'已关闭'; ?>
                        </span>
                    </label>
                    <p class="form-hint">PC 端拦截关闭时，是否仍在拦截页底部注入统计脚本以采集数据</p>
                </div>
            </div>

            <div class="btn-group">
                <button type="submit" name="save_config" class="btn btn-success btn-lg">💾 保存 PC 配置</button>
                <button type="button" class="btn btn-ghost btn-lg" onclick="location.reload()">🔄 刷新</button>
            </div>

        </div><!-- end tab-pc -->

        <!-- ══════════════════════════════════════════
             Tab ③ 移动设置
        ═════════════════════════════════════════════ -->
        <div class="tab-panel" id="tab-mobile">

            <div class="card">
                <div class="card-title">
                    📱 移动端设置
                    <span class="badge <?php echo $cv['mobile_enabled']?'badge-green':'badge-red'; ?>">
                        <?php echo $cv['mobile_enabled']?'已启用':'已关闭'; ?>
                    </span>
                </div>

                <div class="switch-row">
                    <div class="switch-row-info">
                        <strong>移动端拦截开关</strong>
                        <span>关闭后移动端 + 平板用户全部放行</span>
                    </div>
                    <label class="toggle-label">
                        <input type="checkbox" id="mobileSwitch" name="mobile_enabled" value="1"
                               <?php echo $cv['mobile_enabled']?'checked':''; ?>>
                        <span class="toggle-text <?php echo $cv['mobile_enabled']?'toggle-on':'toggle-off'; ?>"
                              id="mobileSwitchLabel">
                            <?php echo $cv['mobile_enabled']?'已启用':'已关闭'; ?>
                        </span>
                    </label>
                </div>


                <div class="form-group" style="margin-top:16px;">
                    <div class="form-label-badge" style="background:var(--green-dim);color:var(--green);border:1px solid var(--green);">
                        📢 移动端拦截后广告策略
                    </div>
                    <div class="radio-group">
                        <label class="radio-option <?php echo $cv['mobile_ad_mode']==='none'?'selected':''; ?>">
                            <input type="radio" name="mobile_ad_mode" value="none"
                                   <?php echo $cv['mobile_ad_mode']==='none'?'checked':''; ?>>
                            <div class="radio-option-text">
                                <strong>🚫 不投放广告</strong>
                                <span>仅显示拦截模板页面，不加载任何广告</span>
                            </div>
                        </label>
                        <label class="radio-option <?php echo $cv['mobile_ad_mode']==='iframe'?'selected':''; ?>">
                            <input type="radio" name="mobile_ad_mode" value="iframe"
                                   <?php echo $cv['mobile_ad_mode']==='iframe'?'checked':''; ?>>
                            <div class="radio-option-text">
                                <strong>🖼️ 全屏 iframe 覆盖</strong>
                                <span>在拦截页上方覆盖全屏 iframe，加载指定广告地址</span>
                            </div>
                            <span class="radio-option-tag badge-purple">全覆盖</span>
                        </label>
                        <label class="radio-option <?php echo $cv['mobile_ad_mode']==='redirect'?'selected':''; ?>">
                            <input type="radio" name="mobile_ad_mode" value="redirect"
                                   <?php echo $cv['mobile_ad_mode']==='redirect'?'checked':''; ?>>
                            <div class="radio-option-text">
                                <strong>↪️ JS 跳转</strong>
                                <span>通过 <code>window.location.href</code> 跳转到指定地址</span>
                            </div>
                            <span class="radio-option-tag" style="background:var(--yellow-dim);color:var(--yellow);border:1px solid var(--yellow);">302跳转</span>
                        </label>
                    </div>
                    <div class="url-input-wrap <?php echo in_array($cv['mobile_ad_mode'],['iframe','redirect'])?'visible':''; ?>"
                         id="mobile_url_wrap">
                        <label class="form-label" id="mobile_url_label">
                            <?php echo $cv['mobile_ad_mode']==='redirect'?'JS 跳转目标地址':'iframe 覆盖地址'; ?>
                        </label>
                        <input type="text" name="mobile_ad_url" class="form-input"
                               value="<?php echo htmlspecialchars($cv['mobile_ad_url']); ?>"
                               placeholder="https://example.com/mobile-ad">
                        <p class="form-hint">填写广告落地页或推广地址</p>
                    </div>
                </div>

                <div class="form-group" id="mobileStatInjectRow" style="margin-top:16px;<?php echo $cv['mobile_enabled']?'display:none;':''; ?>">
                    <div class="form-label-badge" style="background:var(--yellow-dim);color:var(--yellow);border:1px solid var(--yellow);">
                        📊 移动端关闭时仍注入统计
                    </div>
                    <label class="toggle-label">
                        <input type="checkbox" name="mobile_stat_inject" value="1"
                               <?php echo $cv['mobile_stat_inject']?'checked':''; ?>>
                        <span class="toggle-text <?php echo $cv['mobile_stat_inject']?'toggle-on':'toggle-off'; ?>">
                            <?php echo $cv['mobile_stat_inject']?'已启用':'已关闭'; ?>
                        </span>
                    </label>
                    <p class="form-hint">移动端拦截关闭时，是否仍在拦截页底部注入统计脚本以采集数据</p>
                </div>
            </div>

            <div class="btn-group">
                <button type="submit" name="save_config" class="btn btn-success btn-lg">💾 保存移动端配置</button>
                <button type="button" class="btn btn-ghost btn-lg" onclick="location.reload()">🔄 刷新</button>
            </div>

        </div><!-- end tab-mobile -->

        <!-- ══════════════════════════════════════════
             Tab ④ 系统维护
        ═════════════════════════════════════════════ -->
        <div class="tab-panel" id="tab-maintain">

            <?php
            $libVer      = deviceLibVersion();
            $lastUpdate  = deviceLibLastUpdate();
            $nextUpdate  = deviceLibNextUpdate();
            $needsUpdate = $nextUpdate < time();
            $daysLeft    = max(0, (int)ceil(($nextUpdate - time()) / 86400));
            ?>

            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'device_updated'): ?>
                <div class="alert alert-success">✅ 设备库更新成功！当前版本：<?php echo htmlspecialchars(deviceLibVersion()); ?></div>
            <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'device_update_fail'): ?>
                <div class="alert alert-danger">❌ 更新失败：<?php echo htmlspecialchars(urldecode($_GET['detail'] ?? '')); ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-title">📦 设备识别库</div>

                <div class="info-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px;">
                    <div class="info-box" style="background:var(--card-bg2);border:1px solid var(--border);border-radius:8px;padding:14px;text-align:center;">
                        <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">当前版本</div>
                        <div style="font-size:18px;font-weight:700;color:var(--blue);"><?php echo htmlspecialchars($libVer); ?></div>
                    </div>
                    <div class="info-box" style="background:var(--card-bg2);border:1px solid var(--border);border-radius:8px;padding:14px;text-align:center;">
                        <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">上次更新</div>
                        <div style="font-size:14px;font-weight:600;color:var(--text);">
                            <?php echo $lastUpdate ? date('Y-m-d', $lastUpdate) : '从未'; ?>
                        </div>
                    </div>
                    <div class="info-box" style="background:var(--card-bg2);border:1px solid:1px solid var(--border);border-radius:8px;padding:14px;text-align:center;
                         <?php echo $needsUpdate ? 'border-color:var(--yellow);' : ''; ?>">
                        <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">下次更新</div>
                        <div style="font-size:14px;font-weight:600;color:<?php echo $needsUpdate ? 'var(--yellow)' : 'var(--green)'; ?>;">
                            <?php echo $needsUpdate ? '⚠️ 已逾期' : '还剩 ' . $daysLeft . ' 天'; ?>
                        </div>
                    </div>
                    <div class="info-box" style="background:var(--card-bg2);border:1px solid var(--border);border-radius:8px;padding:14px;text-align:center;">
                        <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">更新周期</div>
                        <div style="font-size:14px;font-weight:600;color:var(--text);">每 15 天</div>
                    </div>
                </div>

                <p class="form-hint" style="margin-bottom:16px;">
                    设备识别库来自 <strong>matomo/device-detector</strong>，收录 45,000+ 设备型号、机器人及操作系统规则，定期更新可提升识别准确率。
                </p>

                <form method="post" id="updateDeviceForm"
                      onsubmit="return confirmUpdate()">
                    <button type="submit" name="update_device_lib" value="1"
                            class="btn <?php echo $needsUpdate ? 'btn-warning' : 'btn-primary'; ?> btn-lg">
                        🔄 <?php echo $needsUpdate ? '立即更新（已逾期）' : '手动更新设备库'; ?>
                    </button>
                    <span style="margin-left:12px;font-size:13px;color:var(--text-muted);">
                        更新约需 10-30 秒，期间页面无响应属正常现象
                    </span>
                </form>
            </div>

        </div><!-- end tab-maintain -->

        </form><!-- end mainForm -->

    </div><!-- end main-content (closed by header.php) -->
    </div><!-- admin-layout -->

    <script src="admin.js"></script>
</body>
</html>
