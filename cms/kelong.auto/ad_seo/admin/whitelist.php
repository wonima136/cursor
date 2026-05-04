<?php
/**
 * WAF 拦截系统 — IP 白名单管理
 * 格式：JSON 数组，存储 AB 段（IP 前两位），如 ["220.181","116.179"]
 */
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

define('CONFIG_DIR',    dirname(__DIR__));
define('CONFIG_FILE',   CONFIG_DIR . '/config.php');
define('WHITELIST_DIR', CONFIG_DIR . '/whitelist');
define('WHITELIST_JSON', WHITELIST_DIR . '/ip_whitelist.json');

if (!is_dir(WHITELIST_DIR)) mkdir(WHITELIST_DIR, 0755, true);

function checkLogin(): bool {
    return isset($_SESSION['waf_admin_logged_in']) && $_SESSION['waf_admin_logged_in'] === true;
}
if (!checkLogin()) { header('Location: index.php'); exit; }

/**
 * 从任意格式字符串提取 AB 段
 */
function adminExtractAB(string $line): ?string {
    $line = trim($line);
    if (empty($line) || $line[0] === '#') return null;
    $line = preg_replace('/\/\d+$/', '', $line);      // CIDR
    $line = preg_replace('/\.\*.*/', '', $line);       // 通配
    $line = preg_replace('/\.\d+-\d+$/', '', $line);   // 范围
    $parts = explode('.', $line);
    if (count($parts) < 2) return null;
    $a = trim($parts[0]); $b = trim($parts[1]);
    if (!ctype_digit($a) || !ctype_digit($b)) return null;
    if ((int)$a > 255 || (int)$b > 255) return null;
    return $a . '.' . $b;
}

// ── 保存手动录入 ────────────────────────────────────────────────
if (isset($_POST['save_whitelist'])) {
    $input = $_POST['whitelist_content'] ?? '';
    $lines = array_filter(array_map('trim', explode("\n", $input)));

    // 读取现有（保留远程同步的 AB 段，只追加手动添加的）
    $existing = [];
    if (file_exists(WHITELIST_JSON)) {
        $existing = json_decode(file_get_contents(WHITELIST_JSON), true) ?: [];
    }

    $added = 0;
    foreach ($lines as $line) {
        if ($line[0] === '#') continue;
        $ab = adminExtractAB($line);
        if ($ab !== null && !in_array($ab, $existing, true)) {
            $existing[] = $ab;
            $added++;
        }
    }
    sort($existing);

    if (file_put_contents(WHITELIST_JSON, json_encode(array_values($existing), JSON_PRETTY_PRINT), LOCK_EX) !== false) {
        header('Location: whitelist.php?msg=saved&added=' . $added . '&t=' . time()); exit;
    }
}

// ── 覆盖写入（全量替换，用于清空或完整编辑）──────────────────────
if (isset($_POST['overwrite_whitelist'])) {
    $input  = $_POST['full_content'] ?? '';
    $lines  = array_filter(array_map('trim', explode("\n", $input)));
    $result = [];
    foreach ($lines as $line) {
        if ($line[0] === '#') continue;
        $ab = adminExtractAB($line);
        if ($ab !== null && !in_array($ab, $result, true)) $result[] = $ab;
    }
    sort($result);
    file_put_contents(WHITELIST_JSON, json_encode(array_values($result), JSON_PRETTY_PRINT), LOCK_EX);
    header('Location: whitelist.php?msg=overwritten&t=' . time()); exit;
}

// ── 删除单个 AB 段 ─────────────────────────────────────────────
if (isset($_POST['delete_ab'])) {
    $delAb = trim($_POST['delete_ab']);
    if (file_exists(WHITELIST_JSON)) {
        $data = json_decode(file_get_contents(WHITELIST_JSON), true) ?: [];
        $data = array_values(array_filter($data, function($v) use ($delAb) { return $v !== $delAb; }));
        file_put_contents(WHITELIST_JSON, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }
    header('Location: whitelist.php?msg=deleted&t=' . time()); exit;
}

// ── 人工立即拉取远程IP列表 ─────────────────────────────────────
if (isset($_POST['force_sync'])) {
    // 读取配置文件中的 URL 列表
    $syncUrls = [];
    if (file_exists(CONFIG_FILE)) {
        $cfgRaw = file_get_contents(CONFIG_FILE);
        if (preg_match("/define\('IP_WHITELIST_URLS',\s*\[(.*?)\]\s*\);/s", $cfgRaw, $um)) {
            preg_match_all("/'([^']+)'/", $um[1], $urlMatches);
            $syncUrls = $urlMatches[1];
        }
    }

    if (empty($syncUrls)) {
        header('Location: whitelist.php?msg=sync_no_url&t=' . time()); exit;
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout'    => 15,
            'user_agent' => 'Mozilla/5.0 (compatible; WAF-Sync/1.0)',
            'method'     => 'GET',
        ],
    ]);

    $remoteContent = false;
    foreach ($syncUrls as $syncUrl) {
        $syncUrl = trim($syncUrl);
        if (empty($syncUrl)) continue;
        $remoteContent = @file_get_contents($syncUrl, false, $ctx);
        if ($remoteContent !== false && !empty(trim($remoteContent))) break;
    }

    // 请求失败 → 不更新 JSON，直接返回错误
    if ($remoteContent === false || empty(trim($remoteContent))) {
        header('Location: whitelist.php?msg=sync_fail&t=' . time()); exit;
    }

    // 请求成功 → 追加新 AB 段
    $existing = [];
    if (file_exists(WHITELIST_JSON)) {
        $existing = json_decode(file_get_contents(WHITELIST_JSON), true) ?: [];
    }
    $before = count($existing);

    foreach (explode("\n", $remoteContent) as $rawLine) {
        $ab = adminExtractAB($rawLine);
        if ($ab !== null && !in_array($ab, $existing, true)) {
            $existing[] = $ab;
        }
    }
    sort($existing);
    $added = count($existing) - $before;

    file_put_contents(
        WHITELIST_JSON,
        json_encode(array_values($existing), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );

    // 更新锁文件时间戳，重置自动同步计时
    $lock = WHITELIST_DIR . '/.ip_sync.lock';
    @touch($lock);

    header('Location: whitelist.php?msg=sync_ok&added=' . $added . '&t=' . time()); exit;
}

define('CURRENT_PAGE', 'whitelist');

// 读取现有白名单
$wlData   = [];
$syncTime = '尚未同步';
if (file_exists(WHITELIST_JSON)) {
    $wlData   = json_decode(file_get_contents(WHITELIST_JSON), true) ?: [];
    $syncTime = date('Y-m-d H:i:s', filemtime(WHITELIST_JSON));
}
$wlCount = count($wlData);

// 读取标题
$pageTitle = 'WAF拦截系统';
if (file_exists(CONFIG_FILE)) {
    $cfg = file_get_contents(CONFIG_FILE);
    if (preg_match("/define\('ADMIN_TITLE',\s*'([^']*)'\);/", $cfg, $m)) $pageTitle = $m[1];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IP 白名单 — <?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="admin.css">
</head>
<body>
    <button class="mobile-menu-btn" onclick="toggleSidebar()">☰</button>

    <div class="admin-layout">
        <?php include 'header.php'; ?>

        <?php if (isset($_GET['msg'])): ?>
            <?php
            $msgMap = [
                'saved'        => ['success', '✅ 已追加新 AB 段，新增 ' . (int)($_GET['added'] ?? 0) . ' 条'],
                'overwritten'  => ['success', '✅ 白名单已完整替换保存'],
                'deleted'      => ['success', '✅ 已删除该 AB 段'],
                'sync_ok'      => ['success', '✅ 远程拉取成功，新增 ' . (int)($_GET['added'] ?? 0) . ' 条 AB 段'],
                'sync_fail'    => ['error',   '❌ 远程请求失败，JSON 未更新。请检查服务器 allow_url_fopen 是否开启，或远程地址是否可访问'],
                'sync_no_url'  => ['error',   '❌ 未配置远程 IP 同步地址，请先在全局设置中填写'],
            ];
            $info = $msgMap[$_GET['msg']] ?? null;
            if ($info) {
                $cls = $info[0] === 'error' ? 'alert alert-error' : 'alert alert-success';
                echo '<div class="' . $cls . '">' . $info[1] . '</div>';
            }
            ?>
        <?php endif; ?>

        <div class="page-header">
            <h1>🔒 IP 白名单管理</h1>
            <p>存储爬虫 IP AB 段（IP 前两位），严格模式下用于验证</p>
        </div>

        <!-- 统计 -->
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-card-label">AB 段总数</div>
                <div class="stat-card-value" style="color:var(--blue);"><?php echo $wlCount; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-label">涵盖 IP 数（估算）</div>
                <div class="stat-card-value" style="color:var(--green);"><?php echo number_format($wlCount * 65536); ?>+</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-label">最后更新</div>
                <div style="font-size:12px;margin-top:8px;color:var(--text2);"><?php echo $syncTime; ?></div>
            </div>
        </div>

        <!-- 格式说明 -->
        <div class="info-box info-yellow" style="margin-bottom:20px;">
            <strong>📌 AB 段白名单说明</strong><br>
            每个条目代表一个 B 类网段，如 <code>"220.181"</code> 匹配所有 <code>220.181.0.0 ~ 220.181.255.255</code>（共 65536 个 IP）。<br>
            <strong>输入支持：</strong>完整 IP（<code>220.181.108.147</code>）、C段（<code>220.181.108</code>）、AB段（<code>220.181</code>）、CIDR、通配符，自动转换。<br>
            <strong style="color:var(--green);">远程同步只追加新 AB 段，不删除已有条目。</strong>
            手动添加同理，如需完整替换请使用下方"完整编辑"功能。
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

            <!-- 左侧：追加手动录入 -->
            <div class="card">
                <div class="card-title">➕ 追加 IP（手动录入）</div>
                <form method="post">
                    <div class="form-group">
                        <label class="form-label">输入 IP 地址（每行一条，支持多种格式）</label>
                        <textarea name="whitelist_content" class="form-textarea" rows="12"
                                  placeholder="220.181.108.147&#10;66.249.64&#10;74.125&#10;# 注释行会被忽略&#10;114.114.114.0/24"></textarea>
                        <p class="form-hint">已存在的 AB 段自动去重，不会重复添加</p>
                    </div>
                    <button type="submit" name="save_whitelist" class="btn btn-success" style="width:100%;">
                        ➕ 追加到白名单
                    </button>
                </form>
            </div>

            <!-- 右侧：完整编辑 + 强制同步 -->
            <div class="card">
                <div class="card-title">✏️ 完整编辑 / 远程同步</div>

                <!-- 强制同步 -->
                <form method="post" style="margin-bottom:20px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;
                                padding:14px;background:var(--bg3);border-radius:var(--radius-sm);border:1px solid var(--border);">
                        <div>
                            <strong style="font-size:14px;">🔄 人工立即拉取远程 IP 列表</strong><br>
                            <span style="font-size:12px;color:var(--text2);">拉取成功才更新 JSON，失败则保留原数据；自动同步每 5 分钟一次</span>
                        </div>
                        <button type="submit" name="force_sync" class="btn btn-primary btn-sm">立即拉取</button>
                    </div>
                </form>

                <!-- 完整替换编辑 -->
                <form method="post" onsubmit="return confirm('此操作将完整替换当前白名单！确定继续？')">
                    <div class="form-group">
                        <label class="form-label">当前完整白名单（可直接编辑后保存替换）</label>
                        <textarea name="full_content" class="form-textarea" rows="12"><?php
                            echo htmlspecialchars(implode("\n", $wlData));
                        ?></textarea>
                        <p class="form-hint" style="color:var(--red);">⚠️ 此操作会完整替换白名单，慎用</p>
                    </div>
                    <button type="submit" name="overwrite_whitelist" class="btn btn-warning" style="width:100%;">
                        💾 完整替换保存
                    </button>
                </form>
            </div>
        </div>

        <!-- AB 段列表 -->
        <?php if (!empty($wlData)): ?>
        <div class="card" style="margin-top:0;">
            <div class="card-title">📋 当前 AB 段列表（<?php echo $wlCount; ?> 条）</div>
            <div style="display:flex;flex-wrap:wrap;gap:8px;">
                <?php foreach ($wlData as $ab): ?>
                <div style="display:inline-flex;align-items:center;gap:6px;
                             padding:5px 10px 5px 12px;background:var(--bg3);
                             border:1px solid var(--border);border-radius:20px;font-size:13px;">
                    <code style="color:var(--blue);"><?php echo htmlspecialchars($ab); ?></code>
                    <form method="post" style="display:inline;"
                          onsubmit="return confirm('删除 <?php echo htmlspecialchars($ab); ?>？')">
                        <input type="hidden" name="delete_ab" value="<?php echo htmlspecialchars($ab); ?>">
                        <button type="submit" style="background:none;border:none;color:var(--red);
                                cursor:pointer;font-size:14px;padding:0;line-height:1;"
                                title="删除">×</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- main-content -->
    </div><!-- admin-layout -->

    <script src="admin.js"></script>
</body>
</html>
