<?php
/**
 * WAF 拦截系统 — 拦截模板编辑
 */
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

define('CONFIG_DIR',   dirname(__DIR__));
define('CONFIG_FILE',  CONFIG_DIR . '/config.php');
define('TMPL_DIR',     CONFIG_DIR . '/templates');

function checkLogin(): bool {
    return isset($_SESSION['waf_admin_logged_in']) && $_SESSION['waf_admin_logged_in'] === true;
}
if (!checkLogin()) { header('Location: index.php'); exit; }

// 读取所有模板目录
function getTmplFolders(): array {
    $folders = [];
    if (!is_dir(TMPL_DIR)) return $folders;
    foreach (scandir(TMPL_DIR) as $d) {
        if ($d === '.' || $d === '..') continue;
        if (is_dir(TMPL_DIR . '/' . $d)) $folders[] = $d;
    }
    return $folders;
}

// 当前选中模板（默认读配置中的 TEMPLATE_FOLDER）
$allFolders    = getTmplFolders();
$defaultFolder = '404';
if (file_exists(CONFIG_FILE)) {
    $cfgRaw = file_get_contents(CONFIG_FILE);
    if (preg_match("/define\('TEMPLATE_FOLDER',\s*'([^']*)'\);/", $cfgRaw, $m)) {
        $defaultFolder = $m[1];
    }
}
$activeFolder = isset($_GET['folder']) && in_array($_GET['folder'], $allFolders, true)
    ? $_GET['folder'] : $defaultFolder;

$pcFile = TMPL_DIR . '/' . $activeFolder . '/pc.html';
$mFile  = TMPL_DIR . '/' . $activeFolder . '/m.html';

// ── 保存 PC 模板 ────────────────────────────────────────────────
if (isset($_POST['save_pc'])) {
    $content = $_POST['pc_content'] ?? '';
    if (file_put_contents($pcFile, $content) !== false) {
        header('Location: templates.php?folder=' . urlencode($activeFolder) . '&msg=pc_saved'); exit;
    }
}

// ── 保存移动端模板 ───────────────────────────────────────────────
if (isset($_POST['save_mobile'])) {
    $content = $_POST['mobile_content'] ?? '';
    if (file_put_contents($mFile, $content) !== false) {
        header('Location: templates.php?folder=' . urlencode($activeFolder) . '&msg=m_saved'); exit;
    }
}

// ── 同时保存两个 ─────────────────────────────────────────────────
if (isset($_POST['save_both'])) {
    $pcOk = file_put_contents($pcFile,  $_POST['pc_content']     ?? '') !== false;
    $mOk  = file_put_contents($mFile,   $_POST['mobile_content'] ?? '') !== false;
    $msg  = ($pcOk && $mOk) ? 'both_saved' : 'save_error';
    header('Location: templates.php?folder=' . urlencode($activeFolder) . '&msg=' . $msg); exit;
}

// 读取文件内容
$pcContent = file_exists($pcFile) ? file_get_contents($pcFile) : '';
$mContent  = file_exists($mFile)  ? file_get_contents($mFile)  : '';

define('CURRENT_PAGE', 'templates');

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
    <title>模板编辑 — <?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="admin.css">
    <style>
        .tmpl-tab-bar {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .tmpl-tab-btn {
            padding: 7px 18px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: var(--bg3);
            color: var(--text2);
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            transition: all .15s;
        }
        .tmpl-tab-btn:hover { border-color: var(--blue); color: var(--blue); }
        .tmpl-tab-btn.active {
            background: var(--blue-dim);
            border-color: var(--blue);
            color: var(--blue);
            font-weight: 600;
        }
        .editor-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 900px) {
            .editor-grid { grid-template-columns: 1fr; }
        }
        .code-editor {
            width: 100%;
            min-height: 460px;
            font-family: 'Cascadia Code', 'Fira Code', Consolas, monospace;
            font-size: 13px;
            line-height: 1.6;
            background: #0d1117;
            color: #c9d1d9;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px;
            box-sizing: border-box;
            resize: vertical;
            tab-size: 4;
        }
        .code-editor:focus {
            outline: none;
            border-color: var(--blue);
            box-shadow: 0 0 0 2px var(--blue-dim);
        }
        .tag-hint {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin: 10px 0 4px;
        }
        .tag-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            background: var(--bg3);
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 12px;
            color: var(--text2);
            cursor: pointer;
            user-select: none;
            transition: all .15s;
        }
        .tag-chip:hover {
            border-color: var(--blue);
            color: var(--blue);
            background: var(--blue-dim);
        }
        .tag-chip code {
            font-family: monospace;
            color: inherit;
        }
    </style>
</head>
<body>
    <button class="mobile-menu-btn" onclick="toggleSidebar()">☰</button>

    <div class="admin-layout">
        <?php include 'header.php'; ?>

        <?php if (isset($_GET['msg'])): ?>
        <?php
        $msgMap = [
            'pc_saved'   => ['success', '✅ PC 端模板已保存'],
            'm_saved'    => ['success', '✅ 移动端模板已保存'],
            'both_saved' => ['success', '✅ PC 端和移动端模板均已保存'],
            'save_error' => ['error',   '❌ 保存失败，请检查文件权限'],
        ];
        $info = $msgMap[$_GET['msg']] ?? null;
        if ($info) {
            $cls = $info[0] === 'error' ? 'alert alert-error' : 'alert alert-success';
            echo '<div class="' . $cls . '">' . $info[1] . '</div>';
        }
        ?>
        <?php endif; ?>

        <div class="page-header">
            <h1>📄 拦截模板编辑</h1>
            <p>编辑各设备的拦截页 HTML，系统变量会在展示时自动替换</p>
        </div>

        <!-- 模板目录切换 -->
        <?php if (count($allFolders) > 1): ?>
        <div class="tmpl-tab-bar">
            <?php foreach ($allFolders as $f): ?>
            <a href="?folder=<?php echo urlencode($f); ?>"
               class="tmpl-tab-btn <?php echo $f === $activeFolder ? 'active' : ''; ?>">
                📁 <?php echo htmlspecialchars($f); ?>
                <?php if ($f === $defaultFolder): ?>
                <span style="font-size:11px;color:var(--green);">（当前使用）</span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="margin-bottom:16px;">
            <span style="font-size:13px;color:var(--text2);">模板目录：</span>
            <strong><?php echo htmlspecialchars($activeFolder); ?></strong>
            <?php if ($activeFolder === $defaultFolder): ?>
            <span class="badge badge-green" style="margin-left:8px;">当前使用</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- 可用变量说明 -->
        <div class="info-box info-yellow" style="margin-bottom:20px;">
            <strong>📌 可用系统变量（点击复制）：</strong>
            <div class="tag-hint" style="margin-top:8px;">
                <span class="tag-chip" onclick="copyTag('{统计脚本}')"><code>{统计脚本}</code> 百度统计脚本（支持多ID）</span>
                <span class="tag-chip" onclick="copyTag('</body>')"><code>&lt;/body&gt;</code> WAF广告自动注入此标签前</span>
            </div>
            <p style="margin:8px 0 0;font-size:12px;color:var(--text2);">
                ⚠️ <code>{统计脚本}</code> 会根据全局配置中填写的统计 ID 数量，自动生成对应数量的统计脚本块（含恶意 URL 过滤）。<br>
                模板必须包含 <code>&lt;/body&gt;</code>，WAF 广告脚本（iframe / 跳转）会自动注入到该标签之前。
            </p>
        </div>

        <!-- 编辑器表单 -->
        <form method="post" id="tmplForm">
        <div class="editor-grid">

            <!-- PC 模板 -->
            <div class="card" style="padding-bottom:16px;">
                <div class="card-title">💻 PC 端模板 <code style="font-size:12px;font-weight:400;color:var(--text2);">pc.html</code></div>
                <textarea name="pc_content" class="code-editor"
                          id="pcEditor"
                          spellcheck="false"
                          onkeydown="handleTab(event)"><?php echo htmlspecialchars($pcContent); ?></textarea>
                <div style="margin-top:12px;display:flex;gap:8px;">
                    <button type="submit" name="save_pc" class="btn btn-primary" style="flex:1;">💾 保存 PC 模板</button>
                    <button type="button" class="btn btn-ghost btn-sm" onclick="resetEditor('pc')">↩ 还原</button>
                </div>
            </div>

            <!-- 移动端模板 -->
            <div class="card" style="padding-bottom:16px;">
                <div class="card-title">📱 移动端模板 <code style="font-size:12px;font-weight:400;color:var(--text2);">m.html</code></div>
                <textarea name="mobile_content" class="code-editor"
                          id="mEditor"
                          spellcheck="false"
                          onkeydown="handleTab(event)"><?php echo htmlspecialchars($mContent); ?></textarea>
                <div style="margin-top:12px;display:flex;gap:8px;">
                    <button type="submit" name="save_mobile" class="btn btn-primary" style="flex:1;">💾 保存移动端模板</button>
                    <button type="button" class="btn btn-ghost btn-sm" onclick="resetEditor('mobile')">↩ 还原</button>
                </div>
            </div>

        </div>

        <!-- 一键保存两个 -->
        <div class="btn-group" style="margin-top:8px;">
            <button type="submit" name="save_both" class="btn btn-success btn-lg">💾 同时保存 PC + 移动端</button>
        </div>
        </form>

    </div><!-- main-content -->
    </div><!-- admin-layout -->

    <script src="admin.js"></script>
    <script>
    // 保存原始内容用于还原
    const _origPc     = document.getElementById('pcEditor').value;
    const _origMobile = document.getElementById('mEditor').value;

    function resetEditor(type) {
        if (!confirm('确定放弃修改，还原到上次保存的内容？')) return;
        if (type === 'pc')     document.getElementById('pcEditor').value = _origPc;
        if (type === 'mobile') document.getElementById('mEditor').value  = _origMobile;
    }

    // Tab 键插入4空格而非切换焦点
    function handleTab(e) {
        if (e.key !== 'Tab') return;
        e.preventDefault();
        const ta  = e.target;
        const s   = ta.selectionStart;
        const end = ta.selectionEnd;
        ta.value  = ta.value.substring(0, s) + '    ' + ta.value.substring(end);
        ta.selectionStart = ta.selectionEnd = s + 4;
    }

    // 点击变量标签复制到剪贴板
    function copyTag(tag) {
        navigator.clipboard.writeText(tag).then(function () {
            var chips = document.querySelectorAll('.tag-chip');
            chips.forEach(function (c) {
                if (c.getAttribute('onclick') && c.getAttribute('onclick').includes(tag.replace(/'/g, "\\'"))) {
                    var orig = c.style.color;
                    c.style.color = 'var(--green)';
                    setTimeout(function () { c.style.color = orig; }, 800);
                }
            });
        }).catch(function () {
            prompt('复制以下内容：', tag);
        });
    }
    </script>
</body>
</html>
