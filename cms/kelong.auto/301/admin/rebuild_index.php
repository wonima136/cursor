<?php
/**
 * 手动重建域名索引工具
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/domain_index.php';

// 检查登录
if (!checkLogin()) {
    header('Location: login.php');
    exit;
}

$message = '';
$success = false;
$details = [];

// 处理重建请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rebuild'])) {
    // 检查 Redis 连接
    $redis = _domainIndex_getRedis();
    
    if (!$redis) {
        $message = 'Redis 连接失败，无法重建索引';
        $success = false;
    } else {
        // 执行重建
        $count = rebuildDomainIndex();
        
        if ($count !== false) {
            $success = true;
            $message = "索引重建成功！共收集 {$count} 个域名";
            
            // 获取详细信息
            $prefix = _REDIRECT301_REDIS_PREFIX_;
            $domainsKey = "{$prefix}domains";
            $allDomains = $redis->sMembers($domainsKey);
            sort($allDomains);
            $details = $allDomains;
        } else {
            $message = '索引重建失败';
            $success = false;
        }
    }
}

// 获取当前索引信息
$redis = _domainIndex_getRedis();
$currentCount = 0;
$currentDomains = [];

if ($redis) {
    $prefix = _REDIRECT301_REDIS_PREFIX_;
    $domainsKey = "{$prefix}domains";
    $currentCount = $redis->sCard($domainsKey);
    if ($currentCount > 0) {
        $currentDomains = $redis->sMembers($domainsKey);
        sort($currentDomains);
    }
}

require_once __DIR__ . '/header.php';
?>

<style>
.tool-card {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
}

.tool-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border-color);
}

.tool-icon {
    font-size: 32px;
}

.tool-title {
    font-size: 20px;
    font-weight: 600;
    color: var(--text-primary);
}

.info-box {
    background: rgba(59, 130, 246, 0.1);
    border-left: 4px solid #3b82f6;
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.info-box p {
    margin: 8px 0;
    color: var(--text-secondary);
    line-height: 1.6;
}

.status-box {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.status-item {
    background: var(--bg-dark);
    padding: 16px;
    border-radius: 8px;
    text-align: center;
}

.status-label {
    font-size: 14px;
    color: var(--text-secondary);
    margin-bottom: 8px;
}

.status-value {
    font-size: 28px;
    font-weight: 600;
    color: var(--primary-color);
}

.domain-list {
    background: var(--bg-dark);
    border-radius: 8px;
    padding: 16px;
    max-height: 400px;
    overflow-y: auto;
}

.domain-item {
    padding: 8px 12px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 6px;
    margin-bottom: 8px;
    font-family: 'Monaco', 'Courier New', monospace;
    font-size: 14px;
    color: var(--text-primary);
}

.alert {
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.alert-success {
    background: rgba(16, 185, 129, 0.1);
    border-left: 4px solid #10b981;
    color: #10b981;
}

.alert-error {
    background: rgba(239, 68, 68, 0.1);
    border-left: 4px solid #ef4444;
    color: #ef4444;
}

.btn-rebuild {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 12px 32px;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}

.btn-rebuild:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(102, 126, 234, 0.3);
}

.btn-rebuild:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>

<div class="container">
    <h1>🔧 重建域名索引</h1>
    
    <div class="tool-card">
        <div class="tool-header">
            <span class="tool-icon">⚡</span>
            <span class="tool-title">域名索引管理</span>
        </div>
        
        <div class="info-box">
            <p><strong>什么是域名索引？</strong></p>
            <p>域名索引是一个存储在 Redis 中的快速查找表，用于加速重定向判断。</p>
            <p>当访问请求到来时，程序会先检查该域名是否在索引中，如果不在则直接跳过，避免不必要的配置文件读取。</p>
            <p><strong>什么时候需要重建？</strong></p>
            <p>• 添加、修改或删除站群链轮的域名后</p>
            <p>• 添加、修改或删除整站重定向的域名后</p>
            <p>• 添加、修改或删除寄生重定向的域名后</p>
            <p>• 发现域名无法正常跳转时</p>
        </div>
        
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $success ? 'success' : 'error'; ?>">
            <span style="font-size: 24px;"><?php echo $success ? '✅' : '❌'; ?></span>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
        <?php endif; ?>
        
        <div class="status-box">
            <div class="status-item">
                <div class="status-label">Redis 状态</div>
                <div class="status-value" style="color: <?php echo $redis ? '#10b981' : '#ef4444'; ?>">
                    <?php echo $redis ? '✓ 已连接' : '✗ 未连接'; ?>
                </div>
            </div>
            
            <div class="status-item">
                <div class="status-label">当前索引数量</div>
                <div class="status-value"><?php echo $currentCount; ?></div>
            </div>
            
            <div class="status-item">
                <div class="status-label">站点 ID</div>
                <div class="status-value" style="font-size: 16px; font-family: monospace;">
                    <?php echo _REDIRECT301_SITE_ID_; ?>
                </div>
            </div>
        </div>
        
        <form method="post" style="text-align: center; margin: 24px 0;">
            <button type="submit" name="rebuild" class="btn-rebuild" <?php echo !$redis ? 'disabled' : ''; ?>>
                🔄 立即重建索引
            </button>
        </form>
        
        <?php if (!empty($currentDomains) || !empty($details)): ?>
        <div style="margin-top: 32px;">
            <h3 style="color: var(--text-primary); margin-bottom: 16px;">
                📋 索引中的域名列表 (<?php echo count($details ?: $currentDomains); ?>)
            </h3>
            <div class="domain-list">
                <?php 
                $displayDomains = !empty($details) ? $details : $currentDomains;
                foreach ($displayDomains as $domain): 
                ?>
                <div class="domain-item"><?php echo htmlspecialchars($domain); ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>

