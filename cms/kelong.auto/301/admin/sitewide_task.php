<?php
/**
 * 整站重定向 - 任务编辑
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sitewide_functions.php';

// 判断是否为 AJAX 请求
$isAjax = $_SERVER['REQUEST_METHOD'] === 'POST';

// 检查登录
if (!checkLogin()) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '登录已过期，请刷新页面重新登录']);
        exit;
    }
    header('Location: login.php');
    exit;
}

$taskId = $_GET['id'] ?? '';
if (empty($taskId)) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '任务ID无效']);
        exit;
    }
    header('Location: sitewide.php');
    exit;
}

$task = _r301sitewide_getById($taskId);
if (!$task) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '任务不存在']);
        exit;
    }
    header('Location: sitewide.php');
    exit;
}

$pageTitle = htmlspecialchars($task['name']) . ' - 整站重定向';

// 处理AJAX请求
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => '未知操作'];
    
    switch ($action) {
        // 保存所有设置
        case 'save_settings':
            $data = [
                'name' => trim($_POST['name'] ?? $task['name']),
                'redirect_type' => $_POST['redirect_type'] ?? '301',
                'follow_subdomain' => isset($_POST['follow_subdomain']),
                'follow_uri' => isset($_POST['follow_uri']),
                'uri_replacements' => _r301sitewide_parseReplacements($_POST['replacements_text'] ?? ''),
                'uri_filter' => [
                    'enabled' => isset($_POST['filter_enabled']),
                    'mode' => $_POST['filter_mode'] ?? 'blacklist',
                    'rules' => json_decode($_POST['filter_rules'] ?? '[]', true) ?: [],
                ],
            ];
            
            if (_r301sitewide_update($taskId, $data)) {
                $response = ['success' => true, 'message' => '设置已保存'];
            } else {
                $response = ['success' => false, 'message' => '保存失败'];
            }
            break;
        
        // 批量添加源域名
        case 'add_source_domains':
            $text = $_POST['domains_text'] ?? '';
            $domains = _r301sitewide_parseDomains($text);
            
            if (empty($domains)) {
                $response = ['success' => false, 'message' => '没有有效的域名'];
                break;
            }
            
            $currentDomains = $task['source_domains'] ?? [];
            $newDomains = array_unique(array_merge($currentDomains, $domains));
            
            if (_r301sitewide_update($taskId, ['source_domains' => $newDomains])) {
                $response = ['success' => true, 'message' => '已添加 ' . count($domains) . ' 个域名'];
            } else {
                $response = ['success' => false, 'message' => '添加失败'];
            }
            break;
        
        // 删除源域名
        case 'delete_source_domain':
            $domain = $_POST['domain'] ?? '';
            $currentDomains = $task['source_domains'] ?? [];
            $newDomains = array_values(array_filter($currentDomains, function($d) use ($domain) {
                return $d !== $domain;
            }));
            
            if (_r301sitewide_update($taskId, ['source_domains' => $newDomains])) {
                $response = ['success' => true, 'message' => '域名已删除'];
            } else {
                $response = ['success' => false, 'message' => '删除失败'];
            }
            break;
        
        // 批量添加目标域名
        case 'add_target_domains':
            $text = $_POST['domains_text'] ?? '';
            $domains = _r301sitewide_parseDomains($text);
            
            if (empty($domains)) {
                $response = ['success' => false, 'message' => '没有有效的域名'];
                break;
            }
            
            $currentDomains = $task['target_domains'] ?? [];
            $newDomains = array_unique(array_merge($currentDomains, $domains));
            
            if (_r301sitewide_update($taskId, ['target_domains' => $newDomains])) {
                $response = ['success' => true, 'message' => '已添加 ' . count($domains) . ' 个域名'];
            } else {
                $response = ['success' => false, 'message' => '添加失败'];
            }
            break;
        
        // 删除目标域名
        case 'delete_target_domain':
            $domain = $_POST['domain'] ?? '';
            $currentDomains = $task['target_domains'] ?? [];
            $newDomains = array_values(array_filter($currentDomains, function($d) use ($domain) {
                return $d !== $domain;
            }));
            
            if (_r301sitewide_update($taskId, ['target_domains' => $newDomains])) {
                $response = ['success' => true, 'message' => '域名已删除'];
            } else {
                $response = ['success' => false, 'message' => '删除失败'];
            }
            break;
            
        // 清空所有目标域名
        case 'clear_target_domains':
            if (_r301sitewide_update($taskId, ['target_domains' => []])) {
                $response = ['success' => true, 'message' => '已清空所有目标域名'];
            } else {
                $response = ['success' => false, 'message' => '清空失败'];
            }
            break;
            
        // 添加备用 URL
        case 'add_fallback_urls':
            $text = $_POST['urls_text'] ?? '';
            $urls = array_filter(array_map('trim', explode("\n", $text)));
            
            if (empty($urls)) {
                $response = ['success' => false, 'message' => '没有有效的 URL'];
                break;
            }
            
            // 验证 URL/URI 格式
            $invalidUrls = [];
            foreach ($urls as $url) {
                // 判断是完整 URL 还是 URI 路径
                if (preg_match('#^https?://#i', $url)) {
                    // 完整 URL：使用标准验证
                    if (!filter_var($url, FILTER_VALIDATE_URL)) {
                        $invalidUrls[] = $url;
                    }
                } else {
                    // URI 路径：验证格式（必须以 / 开头，或包含占位符）
                    if (!preg_match('#^/|{.+}#', $url)) {
                        $invalidUrls[] = $url;
                    }
                }
            }
            
            if (!empty($invalidUrls)) {
                $response = ['success' => false, 'message' => "无效的格式: " . implode(', ', array_slice($invalidUrls, 0, 3)) . (count($invalidUrls) > 3 ? '...' : '')];
                break;
            }
            
            $currentUrls = $task['fallback_urls'] ?? [];
            $newUrls = array_unique(array_merge($currentUrls, $urls));
            
            if (_r301sitewide_update($taskId, ['fallback_urls' => $newUrls])) {
                $response = ['success' => true, 'message' => '已添加 ' . count($urls) . ' 个 URL/URI，当前共 ' . count($newUrls) . ' 个'];
            } else {
                $response = ['success' => false, 'message' => '添加失败'];
            }
            break;
            
        // 删除单个备用 URL
        case 'delete_fallback_url':
            $url = $_POST['url'] ?? '';
            $currentUrls = $task['fallback_urls'] ?? [];
            $newUrls = array_values(array_filter($currentUrls, function($u) use ($url) {
                return $u !== $url;
            }));
            
            if (_r301sitewide_update($taskId, ['fallback_urls' => $newUrls])) {
                $response = ['success' => true, 'message' => 'URL 已删除'];
            } else {
                $response = ['success' => false, 'message' => '删除失败'];
            }
            break;
            
        // 清空所有备用 URL
        case 'clear_fallback_urls':
            if (_r301sitewide_update($taskId, ['fallback_urls' => []])) {
                $response = ['success' => true, 'message' => '已清空所有备用 URL'];
            } else {
                $response = ['success' => false, 'message' => '清空失败'];
            }
            break;
            
        // 清空 URL 映射关系
        case 'clear_url_mappings':
            require_once __DIR__ . '/redis_config.php';
            $redis = getRedis();
            
            if (!$redis) {
                $response = ['success' => false, 'message' => 'Redis 连接失败'];
                break;
            }
            
            $prefix = REDIS_SITEWIDE_PREFIX;
            $pattern = "{$prefix}task:{$taskId}:mapping:*";
            $keys = $redis->keys($pattern);
            
            $deletedCount = 0;
            if ($keys) {
                foreach ($keys as $key) {
                    if ($redis->del($key)) {
                        $deletedCount++;
                    }
                }
            }
            
            $response = ['success' => true, 'message' => "已清空 {$deletedCount} 条映射关系"];
            break;
            
        // 清空所有源域名
        case 'clear_source_domains':
            if (_r301sitewide_update($taskId, ['source_domains' => []])) {
                $response = ['success' => true, 'message' => '已清空所有源域名'];
            } else {
                $response = ['success' => false, 'message' => '清空失败'];
            }
            break;
    }
    
    echo json_encode($response);
    exit;
}

// 准备数据
$sourceDomains = $task['source_domains'] ?? [];
$targetDomains = $task['target_domains'] ?? [];
$uriReplacements = $task['uri_replacements'] ?? [];
$fallbackUrls = $task['fallback_urls'] ?? [];
$uriFilter = $task['uri_filter'] ?? ['enabled' => false, 'mode' => 'blacklist', 'rules' => []];

require_once __DIR__ . '/header.php';
?>

<style>
.container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}
.back-link {
    display: inline-block;
    color: var(--text-muted);
    text-decoration: none;
    margin-bottom: 16px;
    font-size: 14px;
}
.back-link:hover {
    color: var(--primary);
}
.page-header {
    margin-bottom: 20px;
}
.page-title {
    font-size: 24px;
    font-weight: 600;
    color: var(--text);
    margin: 0;
}
.status-header {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 12px;
    color: white;
    margin-bottom: 20px;
}
.status-header.disabled {
    background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
}
.status-text {
    flex: 1;
}
.status-text h3 {
    margin: 0 0 4px 0;
    font-size: 18px;
}
.status-text p {
    margin: 0;
    opacity: 0.9;
    font-size: 14px;
}
.stats-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}
.stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px;
    text-align: center;
}
.stat-value {
    font-size: 28px;
    font-weight: 600;
    color: var(--primary);
    margin-bottom: 4px;
}
.stat-label {
    font-size: 13px;
    color: var(--text-muted);
}
.grid-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}
@media (max-width: 1200px) {
    .grid-layout {
        grid-template-columns: 1fr;
    }
}
.card-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border);
}
.form-group {
    margin-bottom: 16px;
}
.form-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: var(--text);
    font-size: 14px;
}
.form-control {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg-dark);
    color: var(--text);
    font-size: 14px;
}
.form-control:focus {
    outline: none;
    border-color: var(--primary);
}
textarea.form-control {
    min-height: 100px;
    resize: vertical;
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 13px;
}
.form-hint {
    display: block;
    margin-top: 6px;
    font-size: 12px;
    color: var(--text-muted);
}
.checkbox-group {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    color: var(--text);
    font-size: 14px;
}
.checkbox-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}
.switch {
    position: relative;
    display: inline-block;
    width: 50px;
    height: 24px;
}
.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}
.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: var(--border);
    transition: .3s;
    border-radius: 24px;
}
.slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}
input:checked + .slider {
    background-color: var(--success);
}
input:checked + .slider:before {
    transform: translateX(26px);
}
.domain-section {
    margin-bottom: 20px;
}
.domain-section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}
.domain-section-title {
    font-size: 14px;
    font-weight: 500;
    color: var(--text);
}
.domain-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 12px;
    background: var(--bg-dark);
    border-radius: 8px;
    min-height: 60px;
}
.domain-table-wrapper {
    overflow-x: auto;
}
.domain-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.domain-table thead {
    background: var(--bg-hover);
}
.domain-table th {
    padding: 10px 12px;
    text-align: left;
    font-weight: 500;
    color: var(--text-muted);
    border-bottom: 2px solid var(--border);
}
.domain-table td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--border);
    color: var(--text);
}
.domain-table tbody tr {
    transition: background 0.2s;
}
.domain-table tbody tr:hover {
    background: var(--bg-hover);
}
.btn-table-delete {
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    padding: 4px 8px;
    font-size: 16px;
    transition: all 0.2s;
    border-radius: 4px;
}
.btn-table-delete:hover {
    background: rgba(239, 68, 68, 0.1);
    color: #ef4444;
}
.empty-list {
    width: 100%;
    padding: 20px;
    text-align: center;
    color: var(--text-muted);
    font-size: 13px;
}
.filter-rules-container {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 12px;
}
.filter-rule-item {
    display: flex;
    gap: 8px;
    align-items: center;
}
.filter-rule-item select {
    width: 120px;
}
.filter-rule-item input {
    flex: 1;
}
.regex-help {
    margin-top: 12px;
    padding: 12px;
    background: var(--bg-hover);
    border-radius: 8px;
    font-size: 12px;
    color: var(--text-muted);
    line-height: 1.6;
}
.regex-help-title {
    font-weight: 600;
    color: var(--text);
    margin-bottom: 8px;
}
.regex-example {
    font-family: 'Consolas', 'Monaco', monospace;
    background: var(--bg-dark);
    padding: 2px 6px;
    border-radius: 4px;
    display: inline-block;
}
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}
.modal-content {
    background: var(--bg-card);
    border-radius: 12px;
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    border-bottom: 1px solid var(--border);
}
.modal-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--text);
}
.modal-close {
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 24px;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.modal-close:hover {
    color: var(--text);
}
.modal-body {
    padding: 20px;
}
.modal-footer {
    display: flex;
    gap: 12px;
    padding: 20px;
    border-top: 1px solid var(--border);
}
</style>

<div class="container">
    <a href="sitewide.php" class="back-link">← 返回任务列表</a>
    
    <div class="page-header">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <h1 class="page-title"><?php echo htmlspecialchars($task['name']); ?></h1>
            <div>
                <?php require_once __DIR__ . '/help_modal.php'; renderHelpModal('sitewide_task'); ?>
            </div>
        </div>
    </div>
    
    <!-- 状态栏 -->
    <div class="status-header <?php echo $task['enabled'] ? '' : 'disabled'; ?>">
        <div class="status-text">
            <h3><?php echo $task['enabled'] ? '🟢 任务运行中' : '⚪ 任务已停止'; ?></h3>
            <p><?php echo $task['enabled'] ? '整站重定向正在生效' : '启用任务后开始重定向'; ?></p>
        </div>
    </div>
    
    <!-- 统计卡片 -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-value"><?php echo count($sourceDomains); ?></div>
            <div class="stat-label">源域名数</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo count($targetDomains); ?></div>
            <div class="stat-label">目标域名数</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($task['stats']['total_redirects'] ?? 0); ?></div>
            <div class="stat-label">跳转次数</div>
        </div>
    </div>
    
    <div class="grid-layout">
        <!-- 左侧列 -->
        <div>
            <!-- 基本设置 -->
            <div class="card">
                <div class="card-title">⚙️ 基本设置</div>
                
                <form id="settingsForm">
                    <div class="form-group">
                        <label class="form-label">任务名称</label>
                        <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($task['name']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">重定向类型</label>
                        <select name="redirect_type" class="form-control">
                            <option value="301" <?php echo ($task['redirect_type'] ?? '301') === '301' ? 'selected' : ''; ?>>301 (永久重定向)</option>
                            <option value="302" <?php echo ($task['redirect_type'] ?? '301') === '302' ? 'selected' : ''; ?>>302 (临时重定向)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="follow_subdomain" <?php echo ($task['follow_subdomain'] ?? false) ? 'checked' : ''; ?>>
                                <span>跟随二级域名 (如: abc.old.com → abc.new.com)</span>
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="follow_uri" <?php echo ($task['follow_uri'] ?? true) ? 'checked' : ''; ?>>
                                <span>跟随URI (保持原路径)</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">URI替换规则 (可选)</label>
                        <textarea name="replacements_text" class="form-control" placeholder="category -> products&#10;.html -> .php"><?php 
                            foreach ($uriReplacements as $rule) {
                                echo htmlspecialchars($rule['find']) . ' -> ' . htmlspecialchars($rule['replace']) . "\n";
                            }
                        ?></textarea>
                        <span class="form-hint">格式: find -> replace (每行一个)</span>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 20px;">保存所有设置</button>
                </form>
            </div>
        </div>
        
        <!-- 右侧列 -->
        <div>
            <!-- 域名配置 -->
            <div class="card">
                <div class="card-title">🔀 域名配置</div>
                
                <div class="domain-section">
                    <div class="domain-section-header">
                        <span class="domain-section-title">📥 源域名 (自动匹配所有二级域名)</span>
                        <div style="display: flex; gap: 6px;">
                            <?php if (!empty($sourceDomains)): ?>
                            <button class="btn btn-secondary btn-sm" onclick="clearSourceDomains()" title="清空所有">🗑️</button>
                            <?php endif; ?>
                            <button class="btn btn-secondary btn-sm" onclick="showAddSourceModal()">+ 添加</button>
                        </div>
                    </div>
                    <div class="domain-table-wrapper">
                        <?php if (empty($sourceDomains)): ?>
                        <div class="empty-list">暂无源域名</div>
                        <?php else: ?>
                        <table class="domain-table">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">#</th>
                                    <th>域名</th>
                                    <th style="width: 80px;">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sourceDomains as $index => $domain): ?>
                                <tr>
                                    <td style="text-align: center; color: var(--text-muted);"><?php echo $index + 1; ?></td>
                                    <td style="font-family: 'Consolas', 'Monaco', monospace; word-break: break-all;">
                                        <?php echo htmlspecialchars($domain); ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <button class="btn-table-delete" onclick="deleteSourceDomain('<?php echo htmlspecialchars(addslashes($domain)); ?>')" title="删除">🗑️</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="domain-section">
                    <div class="domain-section-header">
                        <span class="domain-section-title">📤 目标域名 (随机选择)</span>
                        <div style="display: flex; gap: 6px;">
                            <?php if (!empty($targetDomains)): ?>
                            <button class="btn btn-secondary btn-sm" onclick="clearTargetDomains()" title="清空所有">🗑️</button>
                            <?php endif; ?>
                            <button class="btn btn-secondary btn-sm" onclick="showAddTargetModal()">+ 添加</button>
                        </div>
                    </div>
                    <div class="domain-table-wrapper">
                        <?php if (empty($targetDomains)): ?>
                        <div class="empty-list">暂无目标域名</div>
                        <?php else: ?>
                        <table class="domain-table">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">#</th>
                                    <th>URL / 域名</th>
                                    <th style="width: 100px;">类型</th>
                                    <th style="width: 80px;">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($targetDomains as $index => $domain): ?>
                                <?php 
                                    // 判断是完整URL还是纯域名
                                    $isFullUrl = (strpos($domain, 'http://') === 0 || strpos($domain, 'https://') === 0 || strpos($domain, '{') !== false);
                                    $typeLabel = $isFullUrl ? '完整URL' : '纯域名';
                                    $typeColor = $isFullUrl ? '#8b5cf6' : '#3b82f6';
                                ?>
                                <tr>
                                    <td style="text-align: center; color: var(--text-muted);"><?php echo $index + 1; ?></td>
                                    <td style="font-family: 'Consolas', 'Monaco', monospace; word-break: break-all;">
                                        <?php echo htmlspecialchars($domain); ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="badge" style="background: <?php echo $typeColor; ?>; color: white; padding: 3px 8px; border-radius: 4px; font-size: 11px;">
                                            <?php echo $typeLabel; ?>
                                        </span>
                                    </td>
                                    <td style="text-align: center;">
                                        <button class="btn-table-delete" onclick="deleteTargetDomain('<?php echo htmlspecialchars(addslashes($domain)); ?>')" title="删除">🗑️</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- URI过滤 -->
            <div class="card" style="margin-top: 20px;">
                <div class="card-title">🎯 URI过滤 (高级)</div>
                
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="filter_enabled" id="filterEnabled" <?php echo $uriFilter['enabled'] ? 'checked' : ''; ?> onchange="toggleFilterOptions()">
                        <span>启用URI过滤</span>
                    </label>
                </div>
                
                <div id="filterOptions" style="<?php echo $uriFilter['enabled'] ? '' : 'display: none;'; ?>">
                    <div class="form-group">
                        <label class="form-label">过滤模式</label>
                        <select name="filter_mode" class="form-control">
                            <option value="whitelist" <?php echo ($uriFilter['mode'] ?? 'blacklist') === 'whitelist' ? 'selected' : ''; ?>>白名单 (仅跳转匹配的)</option>
                            <option value="blacklist" <?php echo ($uriFilter['mode'] ?? 'blacklist') === 'blacklist' ? 'selected' : ''; ?>>黑名单 (排除匹配的)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">过滤规则</label>
                        <div class="filter-rules-container" id="filterRules">
                            <?php if (empty($uriFilter['rules'])): ?>
                            <div class="empty-list">暂无规则</div>
                            <?php else: ?>
                            <?php foreach ($uriFilter['rules'] as $rule): ?>
                            <div class="filter-rule-item">
                                <select class="form-control">
                                    <option value="exact" <?php echo ($rule['type'] ?? 'exact') === 'exact' ? 'selected' : ''; ?>>精确</option>
                                    <option value="prefix" <?php echo ($rule['type'] ?? 'exact') === 'prefix' ? 'selected' : ''; ?>>前缀</option>
                                    <option value="regex" <?php echo ($rule['type'] ?? 'exact') === 'regex' ? 'selected' : ''; ?>>正则</option>
                                </select>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($rule['value'] ?? ''); ?>" placeholder="/path/ 或 #/product/\\d+#">
                                <button type="button" class="btn btn-danger btn-sm" onclick="removeFilterRule(this)">删除</button>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="addFilterRule()" style="margin-top: 8px;">+ 添加规则</button>
                    </div>
                    
                    <div class="regex-help">
                        <div class="regex-help-title">📖 正则表达式说明</div>
                        <strong>格式：</strong>需自己添加分隔符，如 <span class="regex-example">#/product/\d+\.html#</span><br>
                        <strong>常用：</strong>
                        <span class="regex-example">#^/blog/#</span> 以/blog/开头 •
                        <span class="regex-example">#\.(jpg|png)$#i</span> 图片文件<br>
                        <strong>元字符：</strong>
                        <code>\d</code> 数字 • <code>\w</code> 字母 • <code>.</code> 任意 • <code>+</code> 多个 • <code>^</code> 开头 • <code>$</code> 结尾
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 备用 URL 池 (通铺整个页面) -->
    <div class="card" style="margin-top: 20px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; padding: 20px; border-bottom: 1px solid var(--border);">
            <h3 class="card-title" style="margin: 0; font-size: 18px; font-weight: 600;">🔗 备用 URL 池（URI 不匹配时使用）</h3>
            <div style="display: flex; gap: 8px;">
                <button class="btn btn-primary btn-sm" onclick="showAddFallbackUrlsModal()">📥 导入 URL</button>
                <button class="btn btn-warning btn-sm" onclick="clearUrlMappings()">🔄 清空映射</button>
                <button class="btn btn-danger btn-sm" onclick="clearAllFallbackUrls()">🗑️ 清空</button>
            </div>
        </div>
        
        <div style="padding: 20px;">
            <?php if (empty($fallbackUrls)): ?>
            <!-- 功能说明 -->
            <div style="padding: 16px; background: var(--bg-light); border-radius: 8px; margin-bottom: 20px;">
                <div style="color: var(--text-muted); font-size: 13px; line-height: 1.8;">
                    <strong style="color: var(--warning); font-size: 14px;">💡 功能说明：</strong><br>
                    • 当开启"跟随 URI"功能时生效<br>
                    • 如果原 URI 匹配替换规则，使用替换后的路径跳转<br>
                    • 如果原 URI 不匹配替换规则，从备用池随机选择一个 URI<br>
                    • <strong style="color: var(--primary);">推荐导入 URI 路径</strong>（如 <code style="background: var(--bg-dark); padding: 2px 6px; border-radius: 4px;">/news/123.html</code>），会自动与目标域名组合<br>
                    • 也支持完整 URL（如 <code style="background: var(--bg-dark); padding: 2px 6px; border-radius: 4px;">https://example.com/page.html</code>），将直接使用<br>
                    • 相同的源 URL 后续访问将跳转到相同的目标（固定映射）<br>
                    • 映射关系永久保存在 Redis 中，直到手动清空
                </div>
            </div>
            
            <div class="empty-links" style="padding: 60px 20px; text-align: center; background: var(--bg-light); border-radius: 12px; border: 2px dashed var(--border);">
                <div style="font-size: 48px; margin-bottom: 16px;">📭</div>
                <p style="font-size: 16px; color: var(--text); margin-bottom: 8px; font-weight: 500;">暂无备用 URL</p>
                <p style="font-size: 13px; color: var(--text-muted);">点击「导入 URL」添加备用跳转目标</p>
            </div>
            <?php else: ?>
            <?php
            // 获取映射关系统计
            require_once __DIR__ . '/redis_config.php';
            $redis = getRedis();
            $mappingCount = 0;
            if ($redis) {
                $prefix = REDIS_SITEWIDE_PREFIX;
                $pattern = "{$prefix}task:{$taskId}:mapping:*";
                $keys = $redis->keys($pattern);
                $mappingCount = $keys ? count($keys) : 0;
            }
            $coverageRate = $mappingCount > 0 && count($fallbackUrls) > 0 ? number_format(($mappingCount / count($fallbackUrls)) * 100, 1) : 0;
            ?>
            
            <!-- 紧凑统计栏 -->
            <div style="display: flex; gap: 12px; margin-bottom: 20px; padding: 16px; background: var(--bg-light); border-radius: 10px; border: 1px solid var(--border);">
                <div style="flex: 1; display: flex; align-items: center; gap: 12px; padding: 12px; background: var(--bg-card); border-radius: 8px;">
                    <div style="width: 40px; height: 40px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 20px;">📦</div>
                    <div style="flex: 1;">
                        <div style="font-size: 24px; font-weight: bold; color: var(--text); line-height: 1;"><?php echo count($fallbackUrls); ?></div>
                        <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">备用 URL 总数</div>
                    </div>
                </div>
                
                <div style="flex: 1; display: flex; align-items: center; gap: 12px; padding: 12px; background: var(--bg-card); border-radius: 8px;">
                    <div style="width: 40px; height: 40px; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 20px;">🔗</div>
                    <div style="flex: 1;">
                        <div style="font-size: 24px; font-weight: bold; color: var(--text); line-height: 1;"><?php echo $mappingCount; ?></div>
                        <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">已固定映射数</div>
                    </div>
                </div>
                
                <div style="flex: 1; display: flex; align-items: center; gap: 12px; padding: 12px; background: var(--bg-card); border-radius: 8px;">
                    <div style="width: 40px; height: 40px; background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 20px;">📊</div>
                    <div style="flex: 1;">
                        <div style="font-size: 24px; font-weight: bold; color: var(--text); line-height: 1;"><?php echo $coverageRate; ?>%</div>
                        <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">映射覆盖率</div>
                    </div>
                </div>
            </div>
            
            <!-- URL 列表表格 -->
            <div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; overflow: hidden;">
                <div style="max-height: 500px; overflow-y: auto;">
                    <table class="domain-table" style="margin: 0;">
                        <thead style="position: sticky; top: 0; background: var(--bg-hover); z-index: 10;">
                            <tr>
                                <th style="width: 80px; text-align: center;">#</th>
                                <th>URI / URL</th>
                                <th style="width: 120px; text-align: center;">类型</th>
                                <th style="width: 80px; text-align: center;">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $index = 1;
                            foreach ($fallbackUrls as $url): 
                                // 判断类型
                                if (preg_match('#^https?://#i', $url)) {
                                    $typeLabel = '完整 URL';
                                    $typeColor = '#8b5cf6';
                                    $typeIcon = '🌐';
                                } elseif (strpos($url, '{') !== false) {
                                    $typeLabel = '动态 URI';
                                    $typeColor = '#f59e0b';
                                    $typeIcon = '⚡';
                                } else {
                                    $typeLabel = 'URI 路径';
                                    $typeColor = '#10b981';
                                    $typeIcon = '📄';
                                }
                            ?>
                            <tr>
                                <td style="text-align: center; color: var(--text-muted); font-weight: 600; font-size: 14px;"><?php echo $index++; ?></td>
                                <td>
                                    <div style="font-family: 'Consolas', 'Monaco', monospace; font-size: 13px; color: var(--text); word-break: break-all; line-height: 1.6;">
                                        <?php echo htmlspecialchars($url); ?>
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; background: <?php echo $typeColor; ?>; color: white; border-radius: 20px; font-size: 12px; font-weight: 500;">
                                        <span><?php echo $typeIcon; ?></span>
                                        <span><?php echo $typeLabel; ?></span>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <button class="btn-table-delete" onclick="deleteFallbackUrl('<?php echo htmlspecialchars(addslashes($url)); ?>')" title="删除">🗑️</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- 底部统计栏 -->
            <div style="margin-top: 20px; padding: 16px; background: var(--bg-light); border-radius: 8px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                <div style="color: var(--text-muted); font-size: 13px;">
                    <strong style="color: var(--text); font-size: 14px;">共 <?php echo count($fallbackUrls); ?> 个备用 URL</strong>
                    <?php if ($mappingCount > 0): ?>
                    <span style="margin-left: 16px;">• 已建立 <strong style="color: var(--success);"><?php echo $mappingCount; ?></strong> 条固定映射</span>
                    <?php endif; ?>
                </div>
                <div style="color: var(--text-muted); font-size: 12px; display: flex; align-items: center; gap: 6px;">
                    <span>💡</span>
                    <span>提示：相同源 URL 将固定跳转到相同目标</span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 添加源域名弹窗 -->
<div class="modal-overlay" id="addSourceModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">批量添加源域名</h3>
            <button class="modal-close" onclick="hideAddSourceModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">域名列表 (每行一个)</label>
                <textarea id="sourceDomainsText" class="form-control" placeholder="old-site1.com&#10;old-site2.com"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="hideAddSourceModal()" style="flex: 1;">取消</button>
            <button class="btn btn-primary" onclick="addSourceDomains()" style="flex: 1;">添加</button>
        </div>
    </div>
</div>

<!-- 添加目标域名弹窗 -->
<div class="modal-overlay" id="addTargetModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">批量添加目标域名</h3>
            <button class="modal-close" onclick="hideAddTargetModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">目标URL列表 (每行一个)</label>
                <textarea id="targetDomainsText" class="form-control" rows="10" placeholder="支持两种格式：

1. 纯域名（自动跟随原路径）：
baidu.com
google.com

2. 完整URL结构（支持占位符）：
http://{自定义参数1}.baidu.com/{自定义参数2}/{年}{月}{日}/{数字8}.html
https://www.google.com/search?q={自定义参数1}

占位符说明：
{年} {月} {日} - 当前日期
{数字X} - X位随机数字
{小写字母X} - X位随机小写字母
{自定义参数1-30} - 自定义参数"></textarea>
                <p class="form-hint" style="margin-top: 8px; color: var(--text-muted); font-size: 12px;">
                    💡 如果输入纯域名，将自动跟随原路径；如果输入完整URL，将使用指定的URL结构
                </p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="hideAddTargetModal()" style="flex: 1;">取消</button>
            <button class="btn btn-primary" onclick="addTargetDomains()" style="flex: 1;">添加</button>
        </div>
    </div>
</div>

<script>
const taskId = '<?php echo $taskId; ?>';

// 保存所有设置
document.getElementById('settingsForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'save_settings');
    
    // 添加URI过滤设置
    const filterEnabled = document.getElementById('filterEnabled');
    if (filterEnabled && filterEnabled.checked) {
        formData.append('filter_enabled', '1');
        
        const filterMode = document.querySelector('[name="filter_mode"]');
        if (filterMode) {
            formData.append('filter_mode', filterMode.value);
        }
        
        const rules = [];
        document.querySelectorAll('.filter-rule-item').forEach(item => {
            const type = item.querySelector('select').value;
            const value = item.querySelector('input').value.trim();
            if (value) {
                rules.push({type, value});
            }
        });
        formData.append('filter_rules', JSON.stringify(rules));
    }
    
    fetch('sitewide_task.php?id=' + taskId, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) location.reload();
    })
    .catch(err => alert('保存失败：' + err.message));
});

// URI过滤选项切换
function toggleFilterOptions() {
    const enabled = document.getElementById('filterEnabled').checked;
    document.getElementById('filterOptions').style.display = enabled ? 'block' : 'none';
}

// 添加过滤规则
function addFilterRule() {
    const container = document.getElementById('filterRules');
    const empty = container.querySelector('.empty-list');
    if (empty) empty.remove();
    
    const ruleHtml = `
        <div class="filter-rule-item">
            <select class="form-control">
                <option value="exact">精确</option>
                <option value="prefix">前缀</option>
                <option value="regex">正则</option>
            </select>
            <input type="text" class="form-control" placeholder="/path/ 或 #/product/\\d+#">
            <button type="button" class="btn btn-danger btn-sm" onclick="removeFilterRule(this)">删除</button>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', ruleHtml);
}

// 删除过滤规则
function removeFilterRule(btn) {
    btn.closest('.filter-rule-item').remove();
    
    const container = document.getElementById('filterRules');
    if (container.children.length === 0) {
        container.innerHTML = '<div class="empty-list">暂无规则</div>';
    }
}

// 源域名管理
function showAddSourceModal() {
    document.getElementById('addSourceModal').style.display = 'flex';
}

function hideAddSourceModal() {
    document.getElementById('addSourceModal').style.display = 'none';
}

function addSourceDomains() {
    const text = document.getElementById('sourceDomainsText').value;
    if (!text.trim()) {
        alert('请输入域名');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'add_source_domains');
    formData.append('domains_text', text);
    
    fetch('sitewide_task.php?id=' + taskId, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) location.reload();
    })
    .catch(err => alert('添加失败：' + err.message));
}

function deleteSourceDomain(domain) {
    if (!confirm('确定要删除这个域名吗？')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete_source_domain');
    formData.append('domain', domain);
    
    fetch('sitewide_task.php?id=' + taskId, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) location.reload();
        else alert(data.message);
    })
    .catch(err => alert('删除失败：' + err.message));
}

// 目标域名管理
function showAddTargetModal() {
    document.getElementById('addTargetModal').style.display = 'flex';
}

function hideAddTargetModal() {
    document.getElementById('addTargetModal').style.display = 'none';
}

function addTargetDomains() {
    const text = document.getElementById('targetDomainsText').value;
    if (!text.trim()) {
        alert('请输入域名');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'add_target_domains');
    formData.append('domains_text', text);
    
    fetch('sitewide_task.php?id=' + taskId, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) location.reload();
    })
    .catch(err => alert('添加失败：' + err.message));
}

function deleteTargetDomain(domain) {
    if (!confirm('确定要删除这个域名吗？')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete_target_domain');
    formData.append('domain', domain);
    
    fetch('sitewide_task.php?id=' + taskId, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) location.reload();
        else alert(data.message);
    })
    .catch(err => alert('删除失败：' + err.message));
}

function clearTargetDomains() {
    if (!confirm('确定要清空所有目标域名吗？\n\n此操作不可恢复！')) return;
    
    const formData = new FormData();
    formData.append('action', 'clear_target_domains');
    
    fetch('sitewide_task.php?id=' + taskId, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) location.reload();
    })
    .catch(err => alert('清空失败：' + err.message));
}

function clearSourceDomains() {
    if (!confirm('确定要清空所有源域名吗？\n\n此操作不可恢复！')) return;
    
    const formData = new FormData();
    formData.append('action', 'clear_source_domains');
    
    fetch('sitewide_task.php?id=' + taskId, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) location.reload();
    })
    .catch(err => alert('清空失败：' + err.message));
}

// 点击外部关闭弹窗
document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
});

// ESC 关闭弹窗
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.style.display = 'none';
        });
    }
});

// ==================== 备用 URL 池管理 ====================

// 显示导入 URL 弹窗
function showAddFallbackUrlsModal() {
    document.getElementById('addFallbackUrlsModal').style.display = 'flex';
    document.getElementById('fallbackUrlsText').value = '';
    document.getElementById('fallbackUrlsText').focus();
}

// 隐藏导入 URL 弹窗
function hideAddFallbackUrlsModal() {
    document.getElementById('addFallbackUrlsModal').style.display = 'none';
}

// 导入备用 URL
function addFallbackUrls() {
    const text = document.getElementById('fallbackUrlsText').value.trim();
    if (!text) {
        alert('请输入 URL');
        return;
    }
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'add_fallback_urls');
    formData.append('urls_text', text);
    
    fetch('sitewide_task.php?id=<?php echo urlencode($taskId); ?>', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('导入失败：' + data.message);
        }
    })
    .catch(err => alert('导入失败：' + err.message));
}

// 删除单个备用 URL
function deleteFallbackUrl(url) {
    if (!confirm('确定要删除这个 URL 吗？')) return;
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'delete_fallback_url');
    formData.append('url', url);
    
    fetch('sitewide_task.php?id=<?php echo urlencode($taskId); ?>', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('删除失败：' + data.message);
        }
    })
    .catch(err => alert('删除失败：' + err.message));
}

// 清空所有备用 URL
function clearAllFallbackUrls() {
    if (!confirm('确定要清空所有备用 URL 吗？此操作无法恢复！')) return;
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'clear_fallback_urls');
    
    fetch('sitewide_task.php?id=<?php echo urlencode($taskId); ?>', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('清空失败：' + data.message);
        }
    })
    .catch(err => alert('清空失败：' + err.message));
}

// 清空 URL 映射关系
function clearUrlMappings() {
    if (!confirm('确定要清空所有 URL 映射关系吗？\n\n清空后，相同的源 URL 将重新随机选择目标 URL。')) return;
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'clear_url_mappings');
    
    fetch('sitewide_task.php?id=<?php echo urlencode($taskId); ?>', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
        } else {
            alert('清空失败：' + data.message);
        }
    })
    .catch(err => alert('清空失败：' + err.message));
}
</script>

<!-- 导入备用 URL 弹窗 -->
<div class="modal-overlay" id="addFallbackUrlsModal" style="display: none;">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title">📥 导入备用 URL</h3>
            <button class="modal-close" onclick="hideAddFallbackUrlsModal()">&times;</button>
        </div>
        
        <div style="padding: 20px;">
            <div class="form-group">
                <label class="form-label">URI / URL 列表（每行一个）</label>
                <textarea id="fallbackUrlsText" class="form-control" rows="10" placeholder="推荐格式（URI 路径）：&#10;/news/article/123.html&#10;/blog/post/456.html&#10;/category/product/789.html&#10;&#10;也支持完整 URL：&#10;https://example.com/page1.html&#10;https://example.com/page2.html"></textarea>
                <span class="form-hint" style="color: var(--primary); font-weight: 500;">💡 推荐：导入 URI 路径（如 /news/123.html），会自动与目标域名组合</span>
            </div>
            
            <div style="display: flex; gap: 12px;">
                <button class="btn btn-secondary" onclick="hideAddFallbackUrlsModal()" style="flex: 1;">取消</button>
                <button class="btn btn-primary" onclick="addFallbackUrls()" style="flex: 1;">导入</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
