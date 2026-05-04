<?php
/**
 * 占位符管理
 */
$pageTitle = '占位符管理 - 301重定向管理系统';

require_once __DIR__ . '/config.php';

// 检查登录
if (!checkLogin()) {
    header('Location: login.php');
    exit;
}

$message = null;

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_placeholders') {
        $data = [];
        for ($i = 1; $i <= 30; $i++) {
            $key = "自定义参数{$i}";
            $data[$key] = $_POST["param_{$i}"] ?? '';
        }
        
        if (savePlaceholders($data)) {
            $message = ['type' => 'success', 'text' => '占位符配置已保存'];
        } else {
            $message = ['type' => 'error', 'text' => '保存失败，请检查文件权限'];
        }
    }
}

require_once __DIR__ . '/header.php';
$placeholders = getPlaceholders();
?>

<style>
.placeholder-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    margin-top: 16px;
}
.placeholder-item {
    display: flex;
    align-items: center;
    gap: 8px;
}
.placeholder-label {
    min-width: 120px;
    font-size: 14px;
    color: var(--text);
    font-weight: 500;
}
.placeholder-textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--bg-dark);
    color: var(--text);
    font-size: 13px;
    font-family: 'Consolas', 'Monaco', monospace;
    resize: vertical;
    line-height: 1.5;
}
.placeholder-textarea:focus {
    outline: none;
    border-color: var(--primary);
}
.placeholder-textarea::placeholder {
    color: var(--text-muted);
    font-size: 12px;
}
.help-section {
    background: var(--bg-hover);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
}
.help-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 12px;
}
.help-category {
    margin-bottom: 12px;
}
.help-category-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--primary);
    margin-bottom: 6px;
}
.help-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    font-size: 13px;
    color: var(--text-muted);
    line-height: 1.8;
}
.help-item {
    background: var(--bg-dark);
    padding: 4px 10px;
    border-radius: 4px;
    font-family: 'Consolas', 'Monaco', monospace;
}
.test-section {
    background: var(--bg-hover);
    border-radius: 8px;
    padding: 16px;
    margin-top: 20px;
}
.test-input-group {
    display: flex;
    gap: 12px;
    margin-bottom: 12px;
}
.test-input {
    flex: 1;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--bg-dark);
    color: var(--text);
    font-size: 14px;
    font-family: 'Consolas', 'Monaco', monospace;
}
.test-output {
    padding: 12px;
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 6px;
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 14px;
    color: var(--success);
    min-height: 40px;
    word-break: break-all;
}
@media (max-width: 1200px) {
    .placeholder-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="page-header">
    <div style="display: flex; align-items: center; justify-content: space-between;">
        <div>
            <h1 class="page-title">🏷️ 占位符管理</h1>
            <p class="page-subtitle">配置自定义占位符，让跳转URL更灵活</p>
        </div>
        <div>
            <?php require_once __DIR__ . '/help_modal.php'; renderHelpModal('placeholders'); ?>
        </div>
    </div>
</div>

<?php if (isset($message)): ?>
<div class="alert alert-<?php echo $message['type']; ?>"><?php echo htmlspecialchars($message['text']); ?></div>
<?php endif; ?>

<!-- 占位符说明 -->
<div class="help-section">
    <div class="help-title">📖 占位符使用说明</div>
    
    <div class="help-category">
        <div class="help-category-title">⏰ 时间类占位符（自动获取当前时间）</div>
        <div class="help-list">
            <span class="help-item">{年}</span>
            <span class="help-item">{月}</span>
            <span class="help-item">{日}</span>
        </div>
    </div>
    
    <div class="help-category">
        <div class="help-category-title">🎲 随机类占位符（每次跳转重新生成）</div>
        <div class="help-list">
            <span class="help-item">{数字8}</span>
            <span class="help-item">{小写字母8}</span>
            <span class="help-item">{大写字母8}</span>
            <span class="help-item">{大小写字母8}</span>
            <span class="help-item">{小写随机字符8}</span>
            <span class="help-item">{大写随机字符8}</span>
            <span class="help-item">{大小写随机字符8}</span>
        </div>
        <p style="margin-top: 6px; font-size: 12px; color: var(--text-muted);">
            💡 数字代表长度，可自定义。例如：{数字6} 生成6位数字，{小写字母10} 生成10位小写字母
        </p>
    </div>
    
    <div class="help-category">
        <div class="help-category-title">⚙️ 自定义参数（在下方配置，支持多个值随机选择）</div>
        <div class="help-list">
            <span class="help-item">{自定义参数1}</span>
            <span class="help-item">{自定义参数2}</span>
            <span>...</span>
            <span class="help-item">{自定义参数30}</span>
        </div>
        <p style="margin-top: 6px; font-size: 12px; color: var(--text-muted);">
            💡 每个参数可配置多个值（换行或逗号分隔），程序会随机选择一个。例如：电影,小说,资源
        </p>
    </div>
    
    <div style="margin-top: 12px; padding: 10px; background: var(--bg-dark); border-radius: 6px; font-size: 13px; color: var(--text-muted);">
        <strong style="color: var(--text);">📌 使用示例：</strong><br>
        • 目标域名: <code style="color: var(--primary);">{小写字母6}.example.com</code> → abc123.example.com<br>
        • 目标URL: <code style="color: var(--primary);">http://site.com/{年}/{月}/{自定义参数1}.html</code> → http://site.com/2025/12/电影.html<br>
        • 二级域名: <code style="color: var(--primary);">{自定义参数2}-{数字4}</code> → 小说-1234<br>
        • 多值随机: 参数1配置为"电影,小说,资源"，每次跳转随机选择一个
    </div>
</div>

<!-- 自定义参数配置 -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">⚙️ 自定义参数配置</h3>
    </div>
    
    <form method="POST">
        <input type="hidden" name="action" value="save_placeholders">
        
        <div class="placeholder-grid">
            <?php for ($i = 1; $i <= 30; $i++): ?>
            <div class="placeholder-item" style="display: flex; flex-direction: column; gap: 4px;">
                <label class="placeholder-label">{自定义参数<?php echo $i; ?>}</label>
                <textarea name="param_<?php echo $i; ?>" class="placeholder-textarea" rows="3" 
                          placeholder="多个值用换行或逗号分隔，程序随机选择一个&#10;例如：&#10;电影&#10;小说&#10;资源"><?php echo htmlspecialchars($placeholders["自定义参数{$i}"] ?? ''); ?></textarea>
            </div>
            <?php endfor; ?>
        </div>
        
        <div style="margin-top: 20px;">
            <button type="submit" class="btn btn-primary">💾 保存配置</button>
        </div>
    </form>
</div>

<!-- 占位符测试工具 -->
<div class="test-section">
    <div class="help-title">🧪 占位符测试工具</div>
    <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 12px;">
        输入包含占位符的URL，实时查看替换效果
    </p>
    
    <div class="test-input-group">
        <input type="text" id="testInput" class="test-input" 
               placeholder="例如: {小写字母6}.{自定义参数1}.com/{年}/{月}/{数字8}.html"
               value="{小写字母6}.{自定义参数1}.com/{年}/{月}/{数字8}.html">
        <button class="btn btn-primary" onclick="testPlaceholder()">测试</button>
    </div>
    
    <div class="test-output" id="testOutput">点击"测试"按钮查看结果</div>
</div>

<script>
// 占位符替换函数（前端预览用）
function replacePlaceholders(text) {
    // 时间类
    const now = new Date();
    text = text.replace(/{年}/g, now.getFullYear());
    text = text.replace(/{月}/g, String(now.getMonth() + 1).padStart(2, '0'));
    text = text.replace(/{日}/g, String(now.getDate()).padStart(2, '0'));
    
    // 随机数字
    text = text.replace(/{数字(\d+)}/g, (match, len) => {
        let result = '';
        for (let i = 0; i < parseInt(len); i++) {
            result += Math.floor(Math.random() * 10);
        }
        return result;
    });
    
    // 小写字母
    text = text.replace(/{小写字母(\d+)}/g, (match, len) => {
        let result = '';
        const chars = 'abcdefghijklmnopqrstuvwxyz';
        for (let i = 0; i < parseInt(len); i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
    });
    
    // 大写字母
    text = text.replace(/{大写字母(\d+)}/g, (match, len) => {
        let result = '';
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        for (let i = 0; i < parseInt(len); i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
    });
    
    // 大小写字母
    text = text.replace(/{大小写字母(\d+)}/g, (match, len) => {
        let result = '';
        const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        for (let i = 0; i < parseInt(len); i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
    });
    
    // 小写随机字符
    text = text.replace(/{小写随机字符(\d+)}/g, (match, len) => {
        let result = '';
        const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        for (let i = 0; i < parseInt(len); i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
    });
    
    // 大写随机字符
    text = text.replace(/{大写随机字符(\d+)}/g, (match, len) => {
        let result = '';
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        for (let i = 0; i < parseInt(len); i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
    });
    
    // 大小写随机字符
    text = text.replace(/{大小写随机字符(\d+)}/g, (match, len) => {
        let result = '';
        const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        for (let i = 0; i < parseInt(len); i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
    });
    
    // 自定义参数（支持多个值随机选择，每次出现都重新随机）
    <?php for ($i = 1; $i <= 30; $i++): ?>
    <?php 
    $value = $placeholders["自定义参数{$i}"] ?? '';
    if (!empty($value)):
    ?>
    // 使用全局匹配和回调函数，确保每次匹配都重新随机
    text = text.replace(/{自定义参数<?php echo $i; ?>}/g, function() {
        const values = '<?php echo addslashes($value); ?>';
        // 先按换行分割，再按逗号分割
        let items = values.split(/[\n,]+/).map(v => v.trim()).filter(v => v);
        if (items.length === 0) return '';
        // 每次调用都重新随机选择
        return items[Math.floor(Math.random() * items.length)];
    });
    <?php endif; ?>
    <?php endfor; ?>
    
    return text;
}

function testPlaceholder() {
    const input = document.getElementById('testInput').value;
    const output = replacePlaceholders(input);
    document.getElementById('testOutput').textContent = output;
}

// 页面加载时自动测试一次
window.addEventListener('load', function() {
    testPlaceholder();
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

