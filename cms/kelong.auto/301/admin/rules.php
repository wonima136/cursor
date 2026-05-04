<?php
/**
 * 全局设置
 */
$pageTitle = '全局设置 - 301重定向管理系统';

require_once __DIR__ . '/config.php';

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    $message = ['type' => 'success', 'text' => '设置已保存'];
}

require_once __DIR__ . '/header.php';
$settings = getSettings();
?>

<div class="page-header">
    <h1 class="page-title">全局设置</h1>
    <p class="page-subtitle">控制跳转系统的全局开关和排除规则</p>
</div>

<?php if (isset($message)): ?>
<div class="alert alert-<?php echo $message['type']; ?>"><?php echo htmlspecialchars($message['text']); ?></div>
<?php endif; ?>

<form method="POST">
    <!-- 全局开关 -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🎯 全局控制</h3>
        </div>
        
        <div class="form-group">
            <div style="display: flex; align-items: center; gap: 16px;">
                <label class="switch">
                    <input type="checkbox" name="global_enabled" <?php echo $settings['global_enabled'] ? 'checked' : ''; ?>>
                    <span class="switch-slider"></span>
                </label>
                <div>
                    <strong style="font-size: 16px;">全局开关</strong>
                    <p class="form-hint">关闭后所有跳转功能将停止（消耗池、大站池、站群链轮全部停止）</p>
                </div>
            </div>
        </div>
        
        <div class="form-group" style="margin-top: 20px;">
            <label class="form-label">⭐ 消耗池跳转概率</label>
            <div class="range-container">
                <input type="range" class="range-slider" name="redirect_probability" 
                       value="<?php echo $settings['redirect_probability']; ?>" 
                       min="0" max="100" step="1"
                       oninput="document.getElementById('probValue').textContent = this.value + '%'">
                <span class="range-value" id="probValue"><?php echo $settings['redirect_probability']; ?>%</span>
            </div>
            <p class="form-hint">
                此概率仅用于消耗池任务的跳转判断<br>
                <span style="color: var(--text-muted);">
                    · 大站池：固定100%跳转（按次数消耗）<br>
                    · 站群链轮：使用分组独立的概率设置（最高30%）
                </span>
            </p>
        </div>
    </div>
    
    <!-- 排除规则 -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🚫 排除规则</h3>
        </div>
        
        <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 16px;">
            配置不需要跳转的URL路径，这些规则会应用于所有跳转功能
        </p>
        
        <div class="form-group">
            <label class="form-label">排除的URL（精确匹配）</label>
            <textarea name="exclude_urls" class="form-textarea" rows="4" placeholder="/admin/
/login.php
/api/"><?php echo implode("\n", $settings['exclude_rules']['urls'] ?? []); ?></textarea>
            <p class="form-hint">每行一个URL路径，匹配到的页面不会跳转</p>
        </div>
        
        <div class="form-group">
            <label class="form-label">排除的URL模式（正则表达式）</label>
            <textarea name="exclude_patterns" class="form-textarea" rows="4" placeholder="/^\/admin\/.*/
/^\/api\/.*/"><?php echo implode("\n", $settings['exclude_rules']['patterns'] ?? []); ?></textarea>
            <p class="form-hint">每行一个正则表达式</p>
        </div>
        
        <div class="form-group">
            <label class="form-label">排除的目录</label>
            <textarea name="exclude_directories" class="form-textarea" rows="4" placeholder="admin
api
static"><?php echo implode("\n", $settings['exclude_rules']['directories'] ?? []); ?></textarea>
            <p class="form-hint">每行一个目录名，该目录下所有页面不跳转</p>
        </div>
    </div>
    
    <!-- 功能说明 -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📋 功能说明</h3>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;">
            <div style="padding: 16px; background: var(--bg-dark); border-radius: 8px;">
                <h4 style="color: var(--warning); margin-bottom: 8px;">⭐ 大站池</h4>
                <p style="font-size: 13px; color: var(--text-muted);">
                    优先级最高，按次数100%执行跳转，用于权重劫持和快照更新
                </p>
            </div>
            <div style="padding: 16px; background: var(--bg-dark); border-radius: 8px;">
                <h4 style="color: var(--primary-light); margin-bottom: 8px;">📋 消耗池任务</h4>
                <p style="font-size: 13px; color: var(--text-muted);">
                    按上方概率执行，支持多任务独立运行，链接消耗完后自动停止
                </p>
            </div>
            <div style="padding: 16px; background: var(--bg-dark); border-radius: 8px;">
                <h4 style="color: var(--success); margin-bottom: 8px;">🔗 站群链轮</h4>
                <p style="font-size: 13px; color: var(--text-muted);">
                    每个分组独立概率（最高30%），用于域名组内互相跳转
                </p>
            </div>
        </div>
    </div>
    
    <!-- 保存按钮 -->
    <div style="display: flex; gap: 12px; margin-top: 20px;">
        <button type="submit" class="btn btn-primary" style="padding: 14px 32px;">💾 保存设置</button>
    </div>
</form>

<?php require_once __DIR__ . '/footer.php'; ?>
