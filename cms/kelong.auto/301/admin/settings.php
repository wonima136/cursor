<?php
/**
 * 系统设置（整合了全局设置和系统设置）
 */
$pageTitle = '系统设置 - 301重定向管理系统';

require_once __DIR__ . '/config.php';

$message = null;
$activeTab = $_GET['tab'] ?? 'system'; // system 或 global

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // 全局设置
    if ($action === 'save_global_settings') {
        $settings = getSettings();
        
        // 基础设置
        $settings['global_enabled'] = isset($_POST['global_enabled']);
        $settings['redirect_probability'] = max(0, min(100, intval($_POST['redirect_probability'] ?? 20)));
        
        // 排除规则
        $excludeUrls = trim($_POST['exclude_urls'] ?? '');
        $settings['exclude_rules']['urls'] = array_filter(array_map('trim', explode("\n", $excludeUrls)));
        
        $excludePatterns = trim($_POST['exclude_patterns'] ?? '');
        $settings['exclude_rules']['patterns'] = array_filter(array_map('trim', explode("\n", $excludePatterns)));
        
        $excludeDirs = trim($_POST['exclude_directories'] ?? '');
        $settings['exclude_rules']['directories'] = array_filter(array_map('trim', explode("\n", $excludeDirs)));
        
        saveSettings($settings);
        $message = ['type' => 'success', 'text' => '全局设置已保存'];
        $activeTab = 'global';
    }
    // 修改密码
    elseif ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if ($currentPassword !== ADMIN_PASSWORD) {
            $message = ['type' => 'error', 'text' => '当前密码错误'];
        } elseif (strlen($newPassword) < 6) {
            $message = ['type' => 'error', 'text' => '新密码长度至少6位'];
        } elseif ($newPassword !== $confirmPassword) {
            $message = ['type' => 'error', 'text' => '两次输入的密码不一致'];
        } else {
            // 更新配置文件中的密码
            $configFile = __DIR__ . '/config.php';
            $configContent = file_get_contents($configFile);
            $configContent = preg_replace(
                "/define\('ADMIN_PASSWORD',\s*'[^']*'\);/",
                "define('ADMIN_PASSWORD', '" . addslashes($newPassword) . "');",
                $configContent
            );
            file_put_contents($configFile, $configContent);
            $message = ['type' => 'success', 'text' => '密码修改成功，请重新登录'];
            
            // 清除session
            doLogout();
        }
    } elseif ($action === 'change_system_name') {
        $newName = trim($_POST['system_name'] ?? '');
        if (empty($newName)) {
            $message = ['type' => 'error', 'text' => '系统名称不能为空'];
        } elseif (mb_strlen($newName) > 30) {
            $message = ['type' => 'error', 'text' => '系统名称不能超过30个字符'];
        } else {
            if (saveSystemName($newName)) {
                $message = ['type' => 'success', 'text' => '系统名称修改成功'];
            } else {
                $message = ['type' => 'error', 'text' => '保存失败，请检查文件权限'];
            }
        }
    }
}

require_once __DIR__ . '/header.php';
$settings = getSettings();
$domains = getDomains();
$pool = getLinksPool();
updateLinksPoolStats($pool);

// 计算日志大小
$logSize = 0;
$logFiles = glob(REDIRECT301_LOG_DIR . '/*.log');
foreach ($logFiles as $file) {
    $logSize += filesize($file);
}

function formatBytes($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / (1024 * 1024), 1) . ' MB';
}
?>

<div class="page-header">
    <h1 class="page-title">系统设置</h1>
    <p class="page-subtitle">管理系统配置和安全设置</p>
</div>

<?php if (isset($message)): ?>
<div class="alert alert-<?php echo $message['type']; ?>"><?php echo htmlspecialchars($message['text']); ?></div>
<?php endif; ?>

<!-- 标签页导航 -->
<div class="card" style="margin-bottom: 20px;">
    <div style="display: flex; border-bottom: 1px solid var(--border);">
        <a href="?tab=system" class="tab-link <?php echo $activeTab === 'system' ? 'active' : ''; ?>" style="padding: 16px 24px; text-decoration: none; color: <?php echo $activeTab === 'system' ? 'var(--primary)' : 'var(--text-muted)'; ?>; border-bottom: 2px solid <?php echo $activeTab === 'system' ? 'var(--primary)' : 'transparent'; ?>; font-weight: 500;">
            ⚙️ 系统设置
        </a>
        <a href="?tab=global" class="tab-link <?php echo $activeTab === 'global' ? 'active' : ''; ?>" style="padding: 16px 24px; text-decoration: none; color: <?php echo $activeTab === 'global' ? 'var(--primary)' : 'var(--text-muted)'; ?>; border-bottom: 2px solid <?php echo $activeTab === 'global' ? 'var(--primary)' : 'transparent'; ?>; font-weight: 500;">
            🌐 全局设置
        </a>
    </div>
</div>

<?php if ($activeTab === 'system'): ?>
<!-- 系统设置标签页 -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
    <!-- 系统名称 -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🏷️ 系统名称</h3>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="change_system_name">
            <div class="form-group">
                <label class="form-label">自定义系统名称</label>
                <input type="text" name="system_name" class="form-input" value="<?php echo htmlspecialchars(getSystemName()); ?>" required maxlength="30" placeholder="例如: 电影站群A组">
                <p class="form-hint">用于区分不同站点，方便管理多个程序包（最多30字符）</p>
            </div>
            <button type="submit" class="btn btn-primary">保存名称</button>
        </form>
    </div>
    
    <!-- 修改密码 -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🔐 修改密码</h3>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="change_password">
            <div class="form-group">
                <label class="form-label">当前密码</label>
                <input type="password" name="current_password" class="form-input" required>
            </div>
            <div class="form-group">
                <label class="form-label">新密码</label>
                <input type="password" name="new_password" class="form-input" required minlength="6">
                <p class="form-hint">密码长度至少6位</p>
            </div>
            <div class="form-group">
                <label class="form-label">确认新密码</label>
                <input type="password" name="confirm_password" class="form-input" required>
            </div>
            <button type="submit" class="btn btn-primary">修改密码</button>
        </form>
    </div>
    
    <!-- 系统信息 -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📊 系统信息</h3>
        </div>
        <div style="display: grid; gap: 16px;">
            <div style="display: flex; justify-content: space-between; padding: 12px; background: var(--bg-dark); border-radius: 8px;">
                <span style="color: var(--text-muted);">PHP版本</span>
                <span><?php echo PHP_VERSION; ?></span>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 12px; background: var(--bg-dark); border-radius: 8px;">
                <span style="color: var(--text-muted);">域名数量</span>
                <span><?php echo count($domains['list']); ?> 个</span>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 12px; background: var(--bg-dark); border-radius: 8px;">
                <span style="color: var(--text-muted);">链接池数量</span>
                <span><?php echo $pool['stats']['total_links']; ?> 个</span>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 12px; background: var(--bg-dark); border-radius: 8px;">
                <span style="color: var(--text-muted);">日志文件</span>
                <span><?php echo count($logFiles); ?> 个 (<?php echo formatBytes($logSize); ?>)</span>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 12px; background: var(--bg-dark); border-radius: 8px;">
                <span style="color: var(--text-muted);">数据目录</span>
                <span style="font-size: 12px; word-break: break-all;"><?php echo REDIRECT301_DATA_DIR; ?></span>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- 全局设置标签页 -->
<form method="POST">
    <input type="hidden" name="action" value="save_global_settings">
    
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🌐 全局跳转设置</h3>
        </div>
        
        <div class="form-group">
            <label class="form-label">
                <input type="checkbox" name="global_enabled" <?php echo $settings['global_enabled'] ? 'checked' : ''; ?>>
                启用全局跳转功能
            </label>
            <p class="form-hint">关闭后所有跳转功能都将停止工作</p>
        </div>
        
        <div class="form-group">
            <label class="form-label">全局跳转概率</label>
            <div class="range-container">
                <input type="range" name="redirect_probability" class="range-slider" min="0" max="100" value="<?php echo $settings['redirect_probability']; ?>" oninput="this.nextElementSibling.textContent = this.value + '%'">
                <span class="range-value"><?php echo $settings['redirect_probability']; ?>%</span>
            </div>
            <p class="form-hint">⚠️ 核心安全阀：只有通过概率判断的访问才会执行跳转</p>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🚫 排除规则</h3>
        </div>
        
        <div class="form-group">
            <label class="form-label">排除URL（完全匹配）</label>
            <textarea name="exclude_urls" class="form-textarea" placeholder="每行一个URL，例如：&#10;/admin/&#10;/api/&#10;/login.php"><?php echo implode("\n", $settings['exclude_rules']['urls'] ?? []); ?></textarea>
            <p class="form-hint">这些URL将不会触发跳转</p>
        </div>
        
        <div class="form-group">
            <label class="form-label">排除模式（正则表达式）</label>
            <textarea name="exclude_patterns" class="form-textarea" placeholder="每行一个正则表达式，例如：&#10;/\.php$/&#10;/^\/user\//&#10;/\?id=\d+/"><?php echo implode("\n", $settings['exclude_rules']['patterns'] ?? []); ?></textarea>
            <p class="form-hint">支持正则表达式匹配</p>
        </div>
        
        <div class="form-group">
            <label class="form-label">排除目录</label>
            <textarea name="exclude_directories" class="form-textarea" placeholder="每行一个目录，例如：&#10;/images/&#10;/css/&#10;/js/"><?php echo implode("\n", $settings['exclude_rules']['directories'] ?? []); ?></textarea>
            <p class="form-hint">这些目录下的所有文件都不会触发跳转</p>
        </div>
        
        <button type="submit" class="btn btn-primary">💾 保存全局设置</button>
    </div>
</form>

<!-- 使用说明 -->
<div class="card" style="margin-top: 20px;">
    <div class="card-header">
        <h3 class="card-title">📖 使用说明</h3>
    </div>
    <div style="color: var(--text-muted); font-size: 14px; line-height: 1.8;">
        <h4 style="color: var(--text); margin-bottom: 12px;">如何在网站中使用跳转功能：</h4>
        <p>在需要启用跳转的PHP文件顶部添加以下代码：</p>
        <pre style="background: var(--bg-dark); padding: 16px; border-radius: 8px; margin: 12px 0; overflow-x: auto;"><code style="color: var(--primary-light);">&lt;?php
require_once '<?php echo dirname(__DIR__); ?>/redirect.php';
?&gt;</code></pre>
        
        <h4 style="color: var(--text); margin: 20px 0 12px;">跳转流程说明：</h4>
        <ol style="padding-left: 20px;">
            <li>检查全局开关是否开启</li>
            <li>检查页面类型（首页/内页）</li>
            <li>检查排除规则</li>
            <li><strong style="color: var(--warning);">概率判断（核心安全阀）</strong> - 只有通过概率判断才会继续</li>
            <li>来源判断（如果启用）</li>
            <li>选择跳转目标（域名池/链接池）</li>
            <li>执行跳转并记录日志</li>
        </ol>
        
        <h4 style="color: var(--text); margin: 20px 0 12px;">注意事项：</h4>
        <ul style="padding-left: 20px;">
            <li>概率设置是防止100%跳转的核心机制，请合理设置</li>
            <li>建议先在测试环境验证配置后再上线</li>
            <li>链接消耗池中的链接消耗完后会自动删除</li>
            <li>定期清理日志文件以节省磁盘空间</li>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>

