<?php
/**
 * 寄生重定向 - 任务详情/编辑
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/parasite_functions.php';

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
    header('Location: parasites.php');
    exit;
}

$task = _r301parasite_getById($taskId);
if (!$task) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '任务不存在']);
        exit;
    }
    header('Location: parasites.php');
    exit;
}

$pageTitle = htmlspecialchars($task['name']) . ' - 寄生重定向';

// 处理AJAX请求
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'save_settings':
            $data = [
                'name' => $_POST['name'] ?? $task['name'],
                'source_path' => $_POST['source_path'] ?? $task['source_path'],
                'source_domain' => $_POST['source_domain'] ?? $task['source_domain'],
                'redirect_mode' => $_POST['redirect_mode'] ?? $task['redirect_mode'],
                'target_paths_enabled' => !empty($_POST['target_paths_enabled']),  // ⭐ 新增
                'target_paths' => $_POST['target_paths'] ?? '',  // ⭐ 新增
                'settings' => [
                    'path_mode' => $_POST['path_mode'] ?? 'strip_prefix',
                    'redirect_type' => $_POST['redirect_type'] ?? '301',
                    'probability' => max(0, min(100, intval($_POST['probability'] ?? 100))),
                    'exclude_paths' => array_filter(array_map('trim', explode("\n", $_POST['exclude_paths'] ?? ''))),
                    'exclude_extensions' => array_filter(array_map('trim', explode(',', $_POST['exclude_extensions'] ?? ''))),
                    'replacements' => _r301parasite_parseReplacements($_POST['replacements'] ?? ''),
                ],
            ];
            _r301parasite_update($taskId, $data);
            echo json_encode(['success' => true, 'message' => '设置已保存']);
            exit;
            
        case 'toggle':
            $enabled = $_POST['enabled'] === '1';
            _r301parasite_toggle($taskId, $enabled);
            
            // 重建 Redis 域名索引
            require_once __DIR__ . '/domain_index.php';
            rebuildDomainIndex();
            
            echo json_encode(['success' => true]);
            exit;
            
        case 'add_source_domains':
            $result = _r301parasite_addSourceDomains($taskId, $_POST['domains'] ?? '');
            
            // 重建 Redis 域名索引
            require_once __DIR__ . '/domain_index.php';
            rebuildDomainIndex();
            
            echo json_encode(['success' => true, 'message' => "添加 {$result['added']} 个，跳过 {$result['skipped']} 个"]);
            exit;
            
        case 'update_source_domain':
            $domain = $_POST['domain'] ?? '';
            $enabled = $_POST['enabled'] === '1';
            _r301parasite_updateSourceDomain($taskId, $domain, $enabled);
            echo json_encode(['success' => true]);
            exit;
            
        case 'delete_source_domain':
            $domain = $_POST['domain'] ?? '';
            _r301parasite_deleteSourceDomain($taskId, $domain);
            
            // 重建 Redis 域名索引
            require_once __DIR__ . '/domain_index.php';
            rebuildDomainIndex();
            
            echo json_encode(['success' => true]);
            exit;
            
        case 'add_target_domains':
            $result = _r301parasite_addTargetDomains($taskId, $_POST['domains'] ?? '');
            echo json_encode(['success' => true, 'message' => "添加 {$result['added']} 个，跳过 {$result['skipped']} 个"]);
            exit;
            
        case 'delete_target_domain':
            $domain = $_POST['domain'] ?? '';
            _r301parasite_deleteTargetDomain($taskId, $domain);
            echo json_encode(['success' => true]);
            exit;
            
        case 'add_directory':
            $data = [
                'path' => $_POST['path'] ?? '',
                'target_domain' => $_POST['target_domain'] ?? '',
                'path_mode' => $_POST['path_mode'] ?? 'strip_prefix',
                'redirect_type' => $_POST['redirect_type'] ?? '301',
                'probability' => intval($_POST['probability'] ?? 100),
            ];
            
            // 如果有 target_domains（多个目标域名），解析并添加
            if (isset($_POST['target_domains'])) {
                $targetDomains = json_decode($_POST['target_domains'], true);
                if (is_array($targetDomains) && !empty($targetDomains)) {
                    $data['target_domains'] = $targetDomains;
                }
            }
            
            $result = _r301parasite_addDirectory($taskId, $data);
            echo json_encode(['success' => $result]);
            exit;
            
        case 'update_directory':
            $dirId = $_POST['dir_id'] ?? '';
            $data = [];
            if (isset($_POST['enabled'])) {
                $data['enabled'] = $_POST['enabled'] === '1';
            }
            if (isset($_POST['target_domain'])) {
                $data['target_domain'] = $_POST['target_domain'];
            }
            if (isset($_POST['target_domains'])) {
                $targetDomains = json_decode($_POST['target_domains'], true);
                if (is_array($targetDomains)) {
                    $data['target_domains'] = $targetDomains;
                }
            }
            if (isset($_POST['path_mode'])) {
                $data['path_mode'] = $_POST['path_mode'];
            }
            if (isset($_POST['redirect_type'])) {
                $data['redirect_type'] = $_POST['redirect_type'];
            }
            if (isset($_POST['probability'])) {
                $data['probability'] = intval($_POST['probability']);
            }
            _r301parasite_updateDirectory($taskId, $dirId, $data);
            echo json_encode(['success' => true]);
            exit;
            
        case 'add_directory_targets':
            $dirId = $_POST['dir_id'] ?? '';
            $domains = $_POST['domains'] ?? '';
            $result = _r301parasite_addDirectoryTargets($taskId, $dirId, $domains);
            echo json_encode(['success' => true, 'message' => "添加 {$result['added']} 个，跳过 {$result['skipped']} 个"]);
            exit;
            
        case 'delete_directory_target':
            $dirId = $_POST['dir_id'] ?? '';
            $targetDomain = $_POST['target_domain'] ?? '';
            _r301parasite_deleteDirectoryTarget($taskId, $dirId, $targetDomain);
            echo json_encode(['success' => true]);
            exit;
            
        case 'delete_directory':
            $dirId = $_POST['dir_id'] ?? '';
            _r301parasite_deleteDirectory($taskId, $dirId);
            echo json_encode(['success' => true]);
            exit;
    }
    
    echo json_encode(['success' => false, 'message' => '未知操作']);
    exit;
}

// 重新获取任务数据
$task = _r301parasite_getById($taskId);
$settings = $task['settings'] ?? [];
$isDirectory = $task['manage_type'] === 'directory';

include 'header.php';
?>

<style>
.back-link {
    color: var(--text-muted);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 16px;
}
.back-link:hover {
    color: var(--text);
}
.status-bar {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
}
.status-bar.running {
    background: linear-gradient(135deg, #22c55e20 0%, #16a34a20 100%);
    border: 1px solid #22c55e40;
}
.status-bar.stopped {
    background: linear-gradient(135deg, #6b728020 0%, #52525b20 100%);
    border: 1px solid var(--border);
}
.status-bar h3 {
    flex: 1;
    font-size: 16px;
}
.stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}
.stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
    text-align: center;
}
.stat-value {
    font-size: 28px;
    font-weight: 700;
    color: var(--text);
}
.stat-label {
    font-size: 13px;
    color: var(--text-muted);
    margin-top: 4px;
}
.section-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}
.section-grid.full {
    grid-template-columns: 1fr;
}
.domain-list {
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid var(--border);
    border-radius: 8px;
}
.domain-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    border-bottom: 1px solid var(--border);
}
.domain-item:last-child {
    border-bottom: none;
}
.domain-item:hover {
    background: var(--bg-dark);
}
.domain-name {
    flex: 1;
    font-family: 'Consolas', monospace;
    font-size: 13px;
}
.dir-table {
    width: 100%;
    border-collapse: collapse;
}
.dir-table th,
.dir-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid var(--border);
}
.dir-table th {
    background: var(--bg-dark);
    font-weight: 500;
    font-size: 13px;
    color: var(--text-muted);
}
.dir-path {
    font-family: 'Consolas', monospace;
    color: var(--primary-light);
}
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.form-textarea {
    width: 100%;
    min-height: 120px;
    padding: 12px;
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text);
    font-size: 13px;
    font-family: 'Consolas', monospace;
    resize: vertical;
}
.form-textarea:focus {
    outline: none;
    border-color: var(--primary);
}
/* 弹窗样式 */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(15, 23, 42, 0.95);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.modal-content {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
    max-height: 90vh;
    overflow-y: auto;
    animation: modalSlideIn 0.2s ease-out;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px;
    border-bottom: 1px solid var(--border);
}

.modal-header h3 {
    margin: 0;
    font-size: 18px;
    color: var(--text-primary);
}

.modal-close {
    background: none;
    border: none;
    font-size: 28px;
    color: var(--text-muted);
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: all 0.2s;
}

.modal-close:hover {
    background: var(--bg-dark);
    color: var(--text-primary);
}

.modal-body {
    padding: 24px;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    margin-bottom: 8px;
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
}

.form-input,
.form-select {
    width: 100%;
    padding: 10px 14px;
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 14px;
    transition: all 0.2s;
}

.form-input:focus,
.form-select:focus {
    outline: none;
    border-color: var(--primary);
    background: var(--card-bg);
}

.form-input::placeholder {
    color: var(--text-muted);
}

.add-domain-form {
    display: flex;
    gap: 12px;
    margin-bottom: 16px;
}
.add-domain-form textarea {
    flex: 1;
    min-height: 80px;
}
.mode-badge {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
}
.mode-badge.focus {
    background: rgba(99, 102, 241, 0.2);
    color: var(--primary-light);
}
.mode-badge.interlink {
    background: rgba(34, 197, 94, 0.2);
    color: var(--success);
}
</style>

<a href="parasites.php" class="back-link">← 返回任务列表</a>

<!-- 状态栏 -->
<div class="status-bar <?php echo $task['enabled'] ? 'running' : 'stopped'; ?>">
    <h3>
        <?php echo $task['enabled'] ? '🟢 任务运行中' : '⏸️ 任务已停止'; ?>
        <span style="font-weight: normal; color: var(--text-muted); margin-left: 12px;">
            <?php echo $isDirectory ? '📁 按目录管理' : '🌐 按域名管理'; ?>
            <?php if ($isDirectory): ?>
            <span class="mode-badge <?php echo $task['redirect_mode']; ?>" style="margin-left: 8px;">
                <?php echo $task['redirect_mode'] === 'focus' ? '🎯 集权模式' : '🔗 互连模式'; ?>
            </span>
            <?php endif; ?>
        </span>
    </h3>
    <?php if ($task['enabled']): ?>
    <button class="btn btn-warning" onclick="toggleTask(false)">⏸️ 停止任务</button>
    <?php else: ?>
    <button class="btn btn-success" onclick="toggleTask(true)">▶️ 启动任务</button>
    <?php endif; ?>
</div>

<!-- 统计 -->
<div class="stats-row">
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($task['stats']['total_redirects'] ?? 0); ?></div>
        <div class="stat-label">总跳转次数</div>
    </div>
    <?php if ($isDirectory): ?>
    <div class="stat-card">
        <div class="stat-value"><?php echo count($task['source_domains']); ?></div>
        <div class="stat-label">源域名数</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo count(array_filter($task['source_domains'], function($d) { return !empty($d['enabled']); })); ?></div>
        <div class="stat-label">已启用</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo count($task['target_domains']); ?></div>
        <div class="stat-label">目标域名数</div>
    </div>
    <?php else: ?>
    <div class="stat-card">
        <div class="stat-value"><?php echo count($task['directories']); ?></div>
        <div class="stat-label">目录规则数</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo count(array_filter($task['directories'], function($d) { return !empty($d['enabled']); })); ?></div>
        <div class="stat-label">已启用</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $settings['probability'] ?? 100; ?>%</div>
        <div class="stat-label">跳转概率</div>
    </div>
    <?php endif; ?>
</div>

<div class="page-header">
    <div style="display: flex; align-items: center; justify-content: space-between;">
        <div>
            <h1 class="page-title"><?php echo htmlspecialchars($task['name']); ?></h1>
            <p class="page-subtitle">创建于 <?php echo $task['created_at']; ?></p>
        </div>
        <div>
            <?php require_once __DIR__ . '/help_modal.php'; renderHelpModal('parasite_task'); ?>
        </div>
    </div>
</div>

<?php if ($isDirectory): ?>
<!-- 按目录管理模式 -->
<div class="section-grid">
    <!-- 左侧：基础设置 -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">⚙️ 基础设置</h3>
        </div>
        
        <form id="settingsForm">
            <div class="form-group">
                <label class="form-label">任务名称</label>
                <input type="text" name="name" class="form-input" value="<?php echo htmlspecialchars($task['name']); ?>">
            </div>
            
            <div class="form-group">
                <label class="form-label">源目录（多个用逗号分隔）</label>
                <input type="text" name="source_path" class="form-input" value="<?php echo htmlspecialchars($task['source_path']); ?>" placeholder="/xiaoshuo/,/dianying/">
                <p class="form-hint">💡 多个目录用英文逗号分隔，例如：/a/, /b/, /c/</p>
            </div>
            
            <!-- ⭐ 新增：目标目录功能 -->
            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" name="target_paths_enabled" id="targetPathsEnabled" onchange="toggleTargetPaths()" <?php echo !empty($task['target_paths_enabled']) ? 'checked' : ''; ?>>
                    <span class="form-label" style="margin: 0;">启用目标目录跳转</span>
                </label>
                <p class="form-hint">勾选后，将随机跳转到指定的目标目录</p>
            </div>
            
            <div class="form-group" id="targetPathsGroup" style="<?php echo empty($task['target_paths_enabled']) ? 'display:none;' : ''; ?>">
                <label class="form-label">目标目录（多个用逗号或换行分隔，支持占位符）</label>
                <textarea name="target_paths" class="form-textarea" rows="5" placeholder="/x/&#10;/y/&#10;/detail/{大小写随机字符8}.html"><?php echo htmlspecialchars($task['target_paths'] ?? ''); ?></textarea>
                <p class="form-hint">
                    💡 支持占位符：{数字8}、{大小写随机字符8}、{年}{月}{日} 等<br>
                    💡 目录型（以/结尾）：拼接剩余路径，如 /x/ → /x/page.html<br>
                    💡 文件型（不以/结尾）：直接跳转，如 /detail/{随机字符8}.html
                </p>
            </div>
            
            <div class="form-group">
                <label class="form-label">跳转模式</label>
                <select name="redirect_mode" class="form-select" id="redirectMode" onchange="toggleModeOptions()">
                    <option value="focus" <?php echo $task['redirect_mode'] === 'focus' ? 'selected' : ''; ?>>🎯 集权模式 - 跳转到目标域名</option>
                    <option value="interlink" <?php echo $task['redirect_mode'] === 'interlink' ? 'selected' : ''; ?>>🔗 互连模式 - 源域名之间互跳</option>
                    <option value="one_to_one" <?php echo $task['redirect_mode'] === 'one_to_one' ? 'selected' : ''; ?>>🔄 一对一模式 - 同域名内部跳转</option>
                </select>
            </div>
            
            <div class="form-row">
                <div class="form-group" id="pathModeGroup" style="<?php echo ($task['redirect_mode'] === 'interlink' || !empty($task['target_paths_enabled'])) ? 'display:none;' : ''; ?>">
                    <label class="form-label">路径处理</label>
                    <select name="path_mode" class="form-select">
                        <option value="strip_prefix" <?php echo ($settings['path_mode'] ?? 'strip_prefix') === 'strip_prefix' ? 'selected' : ''; ?>>去除源目录前缀</option>
                        <option value="keep_full" <?php echo ($settings['path_mode'] ?? '') === 'keep_full' ? 'selected' : ''; ?>>保持完整路径</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">跳转方式</label>
                    <select name="redirect_type" class="form-select">
                        <option value="301" <?php echo ($settings['redirect_type'] ?? '301') === '301' ? 'selected' : ''; ?>>301 永久重定向</option>
                        <option value="302" <?php echo ($settings['redirect_type'] ?? '301') === '302' ? 'selected' : ''; ?>>302 临时重定向</option>
                    </select>
                </div>
            </div>
            
            <!-- 互连模式提示 -->
            <div id="interlinkNotice" style="<?php echo $task['redirect_mode'] !== 'interlink' ? 'display:none;' : ''; ?>; background: var(--bg-dark); border-radius: 8px; padding: 12px; margin-bottom: 16px;">
                <p style="color: var(--warning); font-size: 13px; margin: 0;">
                    ⚠️ <strong>互连模式说明：</strong><br>
                    · 路径处理自动设为"保持完整路径"<br>
                    · 跳转概率上限为 30%（防止死循环）
                </p>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">跳转概率</label>
                    <input type="number" name="probability" class="form-input" value="<?php echo $settings['probability'] ?? 100; ?>" min="0" max="100">
                    <p class="form-hint">0-100%，寄生转移建议100%</p>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">自定义替换规则（可选）</label>
                <textarea name="replacements" class="form-textarea" rows="3" placeholder="book -> books
.html -> .htm
每行一条，格式: 查找 -> 替换"><?php echo htmlspecialchars(_r301parasite_formatReplacements($settings['replacements'] ?? [])); ?></textarea>
            </div>
            
            <div class="form-group">
                <label class="form-label">排除子路径（可选）</label>
                <textarea name="exclude_paths" class="form-textarea" rows="3" placeholder="/xiaoshuo/api/
/xiaoshuo/admin/"><?php echo htmlspecialchars(implode("\n", $settings['exclude_paths'] ?? [])); ?></textarea>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%;">💾 保存设置</button>
        </form>
    </div>
    
    <!-- 右侧：域名管理 -->
    <div>
        <!-- 源域名 -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📋 源域名列表 (<?php echo count($task['source_domains']); ?>)</h3>
            </div>
            
            <div class="add-domain-form">
                <textarea id="sourceDomains" class="form-textarea" rows="3" placeholder="每行一个域名
movie1.com
movie2.com"></textarea>
                <button class="btn btn-primary" onclick="addSourceDomains()" style="white-space: nowrap;">添加</button>
            </div>
            
            <?php if (!empty($task['source_domains'])): ?>
            <div class="domain-list">
                <?php foreach ($task['source_domains'] as $d): ?>
                <div class="domain-item" data-domain="<?php echo htmlspecialchars($d['domain']); ?>">
                    <label class="switch" style="transform: scale(0.8);">
                        <input type="checkbox" <?php echo !empty($d['enabled']) ? 'checked' : ''; ?> onchange="updateSourceDomain('<?php echo htmlspecialchars($d['domain']); ?>', this.checked)">
                        <span class="slider"></span>
                    </label>
                    <span class="domain-name"><?php echo htmlspecialchars($d['domain']); ?></span>
                    <button class="btn btn-sm btn-danger" onclick="deleteSourceDomain('<?php echo htmlspecialchars($d['domain']); ?>')" style="white-space: nowrap;">删除</button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p style="color: var(--text-muted); text-align: center; padding: 20px;">暂无源域名，请添加包含 <?php echo htmlspecialchars($task['source_path']); ?> 目录的域名</p>
            <?php endif; ?>
        </div>
        
        <?php if ($task['redirect_mode'] === 'focus'): ?>
        <!-- 目标域名（集权模式） -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">🎯 目标域名 (<?php echo count($task['target_domains']); ?>)</h3>
            </div>
            
            <div class="add-domain-form">
                <textarea id="targetDomains" class="form-textarea" rows="2" placeholder="每行一个域名，多个则随机选择
xiaoshuo.com"></textarea>
                <button class="btn btn-primary" onclick="addTargetDomains()" style="white-space: nowrap;">添加</button>
            </div>
            
            <?php if (!empty($task['target_domains'])): ?>
            <div class="domain-list" style="max-height: 150px;">
                <?php foreach ($task['target_domains'] as $domain): ?>
                <div class="domain-item">
                    <span class="domain-name" style="color: var(--success);"><?php echo htmlspecialchars($domain); ?></span>
                    <button class="btn btn-sm btn-danger" onclick="deleteTargetDomain('<?php echo htmlspecialchars($domain); ?>')" style="white-space: nowrap;">删除</button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p style="color: var(--text-muted); text-align: center; padding: 20px;">请添加目标域名</p>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">🔗 互连模式说明</h3>
            </div>
            <p style="color: var(--text-muted); font-size: 13px;">
                互连模式下，源域名列表内的域名会互相跳转。<br><br>
                例如：访问 movie1.com<?php echo htmlspecialchars($task['source_path']); ?>page.html 会随机跳转到<br>
                movie2.com<?php echo htmlspecialchars($task['source_path']); ?>page.html 或<br>
                movie3.com<?php echo htmlspecialchars($task['source_path']); ?>page.html
            </p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- 按域名管理模式 -->
<div class="section-grid full">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">⚙️ 基础设置</h3>
        </div>
        
        <form id="settingsForm">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">任务名称</label>
                    <input type="text" name="name" class="form-input" value="<?php echo htmlspecialchars($task['name']); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">源域名</label>
                    <input type="text" name="source_domain" class="form-input" value="<?php echo htmlspecialchars($task['source_domain']); ?>" placeholder="movie.com">
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary">💾 保存设置</button>
        </form>
    </div>
    
    <!-- 目录规则列表 -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📁 目录规则 (<?php echo count($task['directories']); ?>)</h3>
            <button class="btn btn-primary btn-sm" onclick="showAddDirModal()">+ 添加目录</button>
        </div>
        
        <?php if (empty($task['directories'])): ?>
        <p style="color: var(--text-muted); text-align: center; padding: 40px;">暂无目录规则，点击上方按钮添加</p>
        <?php else: ?>
        <table class="dir-table">
            <thead>
                <tr>
                    <th>状态</th>
                    <th>源目录</th>
                    <th>目标域名</th>
                    <th>路径处理</th>
                    <th>跳转</th>
                    <th>已跳转</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($task['directories'] as $dir): ?>
                <tr data-id="<?php echo $dir['id']; ?>">
                    <td>
                        <label class="switch" style="transform: scale(0.8);">
                            <input type="checkbox" <?php echo !empty($dir['enabled']) ? 'checked' : ''; ?> onchange="updateDirectory('<?php echo $dir['id']; ?>', 'enabled', this.checked)">
                            <span class="slider"></span>
                        </label>
                    </td>
                    <td class="dir-path"><?php echo htmlspecialchars($dir['path']); ?></td>
                    <td>
                        <?php 
                        // 显示目标域名（支持单个或多个）
                        $targetDomains = $dir['target_domains'] ?? [];
                        if (!empty($targetDomains) && is_array($targetDomains)):
                            // 有多个目标域名
                            echo '<div style="display: flex; align-items: center; gap: 5px;">';
                            echo '<span style="color: var(--primary); font-weight: 500;">随机选择</span>';
                            echo '<button class="btn btn-sm" onclick="showDirectoryTargets(\'' . $dir['id'] . '\')" style="padding: 2px 8px; font-size: 12px;">查看(' . count($targetDomains) . '个)</button>';
                            echo '</div>';
                        else:
                            // 单个目标域名（向后兼容）
                            echo htmlspecialchars($dir['target_domain'] ?? '');
                        endif;
                        ?>
                    </td>
                    <td><?php echo ($dir['path_mode'] ?? 'strip_prefix') === 'strip_prefix' ? '去前缀' : '保持'; ?></td>
                    <td><?php echo $dir['redirect_type'] ?? '301'; ?></td>
                    <td><?php echo number_format($dir['stats']['total_redirects'] ?? 0); ?></td>
                    <td>
                        <button class="btn btn-sm" onclick="editDirectory('<?php echo $dir['id']; ?>')" style="white-space: nowrap; margin-right: 5px;">编辑</button>
                        <button class="btn btn-sm btn-danger" onclick="deleteDirectory('<?php echo $dir['id']; ?>')" style="white-space: nowrap;">删除</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- 添加目录弹窗 -->
<div class="modal-overlay" id="addDirModal" style="display: none;">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>添加目录规则</h3>
            <button class="modal-close" onclick="hideAddDirModal()">&times;</button>
        </div>
        <div class="modal-body" style="padding: 20px;">
            <form id="addDirForm">
                <div class="form-group">
                    <label class="form-label">源目录</label>
                    <input type="text" name="path" class="form-input" placeholder="/xiaoshuo/" required>
                </div>
                
                <!-- 目标域名模式选择 -->
                <div class="form-group">
                    <label class="form-label">目标域名模式</label>
                    <div style="display: flex; gap: 15px; margin-bottom: 10px;">
                        <label style="display: flex; align-items: center; cursor: pointer;">
                            <input type="radio" name="target_mode" value="single" checked onchange="toggleTargetMode()" style="margin-right: 8px;">
                            <span style="color: var(--text-primary);">单个域名</span>
                        </label>
                        <label style="display: flex; align-items: center; cursor: pointer;">
                            <input type="radio" name="target_mode" value="multiple" onchange="toggleTargetMode()" style="margin-right: 8px;">
                            <span style="color: var(--text-primary);">多个域名（随机选择）</span>
                        </label>
                    </div>
                </div>
                
                <!-- 单个目标域名 -->
                <div class="form-group" id="singleTargetGroup">
                    <label class="form-label">目标域名</label>
                    <input type="text" name="target_domain" class="form-input" placeholder="xiaoshuo.com">
                </div>
                
                <!-- 多个目标域名 -->
                <div class="form-group" id="multipleTargetGroup" style="display: none;">
                    <label class="form-label">目标域名列表（每行一个）</label>
                    <textarea name="target_domains" class="form-input" rows="5" placeholder="xiaoshuo1.com&#10;xiaoshuo2.com&#10;xiaoshuo3.com" style="resize: vertical; font-family: monospace;"></textarea>
                    <div style="margin-top: 8px; color: var(--text-muted); font-size: 12px;">
                        💡 提示：每行输入一个域名，访问时会随机选择其中一个进行跳转
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">路径处理</label>
                        <select name="path_mode" class="form-select">
                            <option value="strip_prefix">去除源目录前缀</option>
                            <option value="keep_full">保持完整路径</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">跳转方式</label>
                        <select name="redirect_type" class="form-select">
                            <option value="301">301</option>
                            <option value="302">302</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">跳转概率 (%)</label>
                    <input type="number" name="probability" class="form-input" value="100" min="1" max="100" required>
                    <div style="margin-top: 8px; color: var(--text-muted); font-size: 12px;">
                        💡 设置跳转触发的概率（1-100），100表示每次都跳转
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">添加</button>
            </form>
        </div>
    </div>
</div>

<!-- 编辑目录弹窗 -->
<div class="modal-overlay" id="editDirModal" style="display: none;">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>编辑目录规则</h3>
            <button class="modal-close" onclick="hideEditDirModal()">&times;</button>
        </div>
        <div class="modal-body" style="padding: 20px;">
            <form id="editDirForm">
                <input type="hidden" name="dir_id" id="editDirId">
                
                <div class="form-group">
                    <label class="form-label">源目录</label>
                    <input type="text" name="path" id="editDirPath" class="form-input" disabled style="background: var(--bg-dark); cursor: not-allowed;">
                    <div style="margin-top: 5px; color: var(--text-muted); font-size: 12px;">
                        💡 源目录不可修改，如需更改请删除后重新添加
                    </div>
                </div>
                
                <!-- 目标域名模式选择 -->
                <div class="form-group">
                    <label class="form-label">目标域名模式</label>
                    <div style="display: flex; gap: 15px; margin-bottom: 10px;">
                        <label style="display: flex; align-items: center; cursor: pointer;">
                            <input type="radio" name="edit_target_mode" value="single" checked onchange="toggleEditTargetMode()" style="margin-right: 8px;">
                            <span style="color: var(--text-primary);">单个域名</span>
                        </label>
                        <label style="display: flex; align-items: center; cursor: pointer;">
                            <input type="radio" name="edit_target_mode" value="multiple" onchange="toggleEditTargetMode()" style="margin-right: 8px;">
                            <span style="color: var(--text-primary);">多个域名（随机选择）</span>
                        </label>
                    </div>
                </div>
                
                <!-- 单个目标域名 -->
                <div class="form-group" id="editSingleTargetGroup">
                    <label class="form-label">目标域名</label>
                    <input type="text" name="target_domain" id="editTargetDomain" class="form-input" placeholder="xiaoshuo.com">
                </div>
                
                <!-- 多个目标域名 -->
                <div class="form-group" id="editMultipleTargetGroup" style="display: none;">
                    <label class="form-label">目标域名列表（每行一个）</label>
                    <textarea name="target_domains" id="editTargetDomains" class="form-input" rows="5" placeholder="xiaoshuo1.com&#10;xiaoshuo2.com&#10;xiaoshuo3.com" style="resize: vertical; font-family: monospace;"></textarea>
                    <div style="margin-top: 8px; color: var(--text-muted); font-size: 12px;">
                        💡 提示：每行输入一个域名，访问时会随机选择其中一个进行跳转
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">路径处理</label>
                        <select name="path_mode" id="editPathMode" class="form-select">
                            <option value="strip_prefix">去除源目录前缀</option>
                            <option value="keep_full">保持完整路径</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">跳转方式</label>
                        <select name="redirect_type" id="editRedirectType" class="form-select">
                            <option value="301">301</option>
                            <option value="302">302</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">跳转概率 (%)</label>
                    <input type="number" name="probability" id="editProbability" class="form-input" min="1" max="100" value="100" placeholder="100">
                    <div style="margin-top: 5px; color: var(--text-muted); font-size: 12px;">
                        💡 设置跳转概率，100表示100%跳转，50表示50%概率跳转
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">保存修改</button>
            </form>
        </div>
    </div>
</div>

<script>
const taskId = '<?php echo $taskId; ?>';

// 切换跳转模式相关选项
function toggleModeOptions() {
    const mode = document.getElementById('redirectMode')?.value;
    const pathModeGroup = document.getElementById('pathModeGroup');
    const interlinkNotice = document.getElementById('interlinkNotice');
    const targetPathsEnabled = document.getElementById('targetPathsEnabled')?.checked;
    
    if (pathModeGroup) {
        // 互连模式或启用目标目录时隐藏路径处理选项
        pathModeGroup.style.display = (mode === 'interlink' || targetPathsEnabled) ? 'none' : '';
    }
    if (interlinkNotice) {
        interlinkNotice.style.display = mode === 'interlink' ? '' : 'none';
    }
}

// 切换目标目录功能
function toggleTargetPaths() {
    const enabled = document.getElementById('targetPathsEnabled')?.checked;
    const targetPathsGroup = document.getElementById('targetPathsGroup');
    const pathModeGroup = document.getElementById('pathModeGroup');
    const mode = document.getElementById('redirectMode')?.value;
    
    if (targetPathsGroup) {
        targetPathsGroup.style.display = enabled ? 'block' : 'none';
    }
    if (pathModeGroup) {
        // 启用目标目录或互连模式时隐藏路径处理选项
        pathModeGroup.style.display = (enabled || mode === 'interlink') ? 'none' : '';
    }
}

// 切换任务状态
function toggleTask(enabled) {
    fetch('parasite.php?id=' + taskId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=toggle&enabled=${enabled ? 1 : 0}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) location.reload();
    });
}

// 保存设置
document.getElementById('settingsForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // ⭐ 验证：一对一模式下，检查源目录和目标目录是否重叠
    const targetPathsEnabled = document.getElementById('targetPathsEnabled')?.checked;
    const redirectMode = document.getElementById('redirectMode')?.value;
    
    if (targetPathsEnabled && redirectMode === 'one_to_one') {
        const sourcePathInput = document.querySelector('[name="source_path"]')?.value || '';
        const targetPathsInput = document.querySelector('[name="target_paths"]')?.value || '';
        
        // ⭐ 支持逗号和换行分隔
        const sourcePaths = sourcePathInput.split(/[,\n]/).map(s => s.trim()).filter(s => s);
        const targetPaths = targetPathsInput.split(/[,\n]/).map(s => s.trim()).filter(s => s);
        
        // 检查是否有重叠
        for (let source of sourcePaths) {
            for (let target of targetPaths) {
                // 标准化路径（去除结尾斜杠后比较）
                const normalizedSource = '/' + source.replace(/^\/+|\/+$/g, '') + '/';
                const normalizedTarget = '/' + target.replace(/^\/+|\/+$/g, '') + '/';
                
                if (normalizedSource === normalizedTarget) {
                    alert('⚠️ 警告：源目录和目标目录相同会导致无限循环！\n\n源目录：' + source + '\n目标目录：' + target + '\n\n请修改后再保存。');
                    return;
                }
            }
        }
    }
    
    const formData = new FormData(this);
    formData.append('action', 'save_settings');
    
    fetch('parasite.php?id=' + taskId, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('设置已保存');
            location.reload();
        } else {
            alert(data.message || '保存失败');
        }
    });
});

// 添加源域名
function addSourceDomains() {
    const domains = document.getElementById('sourceDomains').value;
    if (!domains.trim()) return;
    
    fetch('parasite.php?id=' + taskId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=add_source_domains&domains=${encodeURIComponent(domains)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        }
    });
}

// 更新源域名状态
function updateSourceDomain(domain, enabled) {
    fetch('parasite.php?id=' + taskId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=update_source_domain&domain=${encodeURIComponent(domain)}&enabled=${enabled ? 1 : 0}`
    });
}

// 删除源域名
function deleteSourceDomain(domain) {
    if (!confirm('确定要删除 ' + domain + ' 吗？')) return;
    
    fetch('parasite.php?id=' + taskId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=delete_source_domain&domain=${encodeURIComponent(domain)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.querySelector(`.domain-item[data-domain="${domain}"]`)?.remove();
        }
    });
}

// 添加目标域名
function addTargetDomains() {
    const domains = document.getElementById('targetDomains').value;
    if (!domains.trim()) return;
    
    fetch('parasite.php?id=' + taskId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=add_target_domains&domains=${encodeURIComponent(domains)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        }
    });
}

// 删除目标域名
function deleteTargetDomain(domain) {
    if (!confirm('确定要删除 ' + domain + ' 吗？')) return;
    
    fetch('parasite.php?id=' + taskId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=delete_target_domain&domain=${encodeURIComponent(domain)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) location.reload();
    });
}

// 显示添加目录弹窗
function showAddDirModal() {
    document.getElementById('addDirModal').style.display = 'flex';
    // 重置表单
    document.getElementById('addDirForm').reset();
    toggleTargetMode();
}

function hideAddDirModal() {
    document.getElementById('addDirModal').style.display = 'none';
}

// 切换目标域名模式
function toggleTargetMode() {
    const mode = document.querySelector('input[name="target_mode"]:checked').value;
    const singleGroup = document.getElementById('singleTargetGroup');
    const multipleGroup = document.getElementById('multipleTargetGroup');
    const singleInput = document.querySelector('input[name="target_domain"]');
    const multipleInput = document.querySelector('textarea[name="target_domains"]');
    
    if (mode === 'single') {
        singleGroup.style.display = 'block';
        multipleGroup.style.display = 'none';
        singleInput.required = true;
        multipleInput.required = false;
    } else {
        singleGroup.style.display = 'none';
        multipleGroup.style.display = 'block';
        singleInput.required = false;
        multipleInput.required = true;
    }
}

// 添加目录
document.getElementById('addDirForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const mode = document.querySelector('input[name="target_mode"]:checked').value;
    const formData = new FormData(this);
    
    // 根据模式处理数据
    if (mode === 'multiple') {
        // 多个目标域名模式
        const domainsText = formData.get('target_domains');
        const domains = domainsText.split('\n')
            .map(d => d.trim())
            .filter(d => d.length > 0);
        
        if (domains.length === 0) {
            alert('请至少输入一个目标域名');
            return;
        }
        
        // 移除单个域名字段，添加域名数组
        formData.delete('target_domain');
        formData.delete('target_domains');
        
        // 将域名数组作为JSON传递
        const params = new URLSearchParams();
        params.append('action', 'add_directory');
        params.append('path', formData.get('path'));
        params.append('path_mode', formData.get('path_mode'));
        params.append('redirect_type', formData.get('redirect_type'));
        params.append('probability', formData.get('probability'));
        params.append('target_domains', JSON.stringify(domains));
        
        fetch('parasite.php?id=' + taskId, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: params.toString()
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('添加失败，可能目录已存在');
            }
        });
    } else {
        // 单个目标域名模式（原有逻辑）
        formData.append('action', 'add_directory');
        formData.delete('target_domains');
        formData.delete('target_mode');
        
        fetch('parasite.php?id=' + taskId, {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('添加失败，可能目录已存在');
            }
        });
    }
});

// 更新目录
function updateDirectory(dirId, field, value) {
    const body = `action=update_directory&dir_id=${dirId}&${field}=${value ? 1 : 0}`;
    
    fetch('parasite.php?id=' + taskId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body
    });
}

// 编辑目录
function editDirectory(dirId) {
    // 从 PHP 数据中获取目录信息
    const directories = <?php echo json_encode($task['directories']); ?>;
    const dir = directories.find(d => d.id === dirId);
    
    if (!dir) {
        alert('没有找到目录信息');
        return;
    }
    
    // 填充表单
    document.getElementById('editDirId').value = dir.id;
    document.getElementById('editDirPath').value = dir.path;
    document.getElementById('editPathMode').value = dir.path_mode || 'strip_prefix';
    document.getElementById('editRedirectType').value = dir.redirect_type || '301';
    document.getElementById('editProbability').value = dir.probability || 100;
    
    // 判断是单个域名还是多个域名
    if (dir.target_domains && Array.isArray(dir.target_domains) && dir.target_domains.length > 0) {
        // 多个域名模式
        document.querySelector('input[name="edit_target_mode"][value="multiple"]').checked = true;
        document.getElementById('editTargetDomains').value = dir.target_domains.join('\n');
    } else {
        // 单个域名模式
        document.querySelector('input[name="edit_target_mode"][value="single"]').checked = true;
        document.getElementById('editTargetDomain').value = dir.target_domain || '';
    }
    
    toggleEditTargetMode();
    
    // 显示弹窗
    document.getElementById('editDirModal').style.display = 'flex';
}

function hideEditDirModal() {
    document.getElementById('editDirModal').style.display = 'none';
}

// 切换编辑目标域名模式
function toggleEditTargetMode() {
    const mode = document.querySelector('input[name="edit_target_mode"]:checked').value;
    const singleGroup = document.getElementById('editSingleTargetGroup');
    const multipleGroup = document.getElementById('editMultipleTargetGroup');
    const singleInput = document.getElementById('editTargetDomain');
    const multipleInput = document.getElementById('editTargetDomains');
    
    if (mode === 'single') {
        singleGroup.style.display = 'block';
        multipleGroup.style.display = 'none';
        singleInput.required = true;
        multipleInput.required = false;
    } else {
        singleGroup.style.display = 'none';
        multipleGroup.style.display = 'block';
        singleInput.required = false;
        multipleInput.required = true;
    }
}

// 编辑目录表单提交
document.getElementById('editDirForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const mode = document.querySelector('input[name="edit_target_mode"]:checked').value;
    const dirId = document.getElementById('editDirId').value;
    const formData = new FormData(this);
    
    const params = new URLSearchParams();
    params.append('action', 'update_directory');
    params.append('dir_id', dirId);
    params.append('path_mode', formData.get('path_mode'));
    params.append('redirect_type', formData.get('redirect_type'));
    params.append('probability', formData.get('probability'));
    
    // 根据模式处理域名数据
    if (mode === 'multiple') {
        // 多个目标域名模式
        const domainsText = formData.get('target_domains');
        const domains = domainsText.split('\n')
            .map(d => d.trim())
            .filter(d => d.length > 0);
        
        if (domains.length === 0) {
            alert('请至少输入一个目标域名');
            return;
        }
        
        params.append('target_domains', JSON.stringify(domains));
        params.append('target_domain', ''); // 清空单个域名
    } else {
        // 单个目标域名模式
        const targetDomain = formData.get('target_domain');
        if (!targetDomain || !targetDomain.trim()) {
            alert('请输入目标域名');
            return;
        }
        
        params.append('target_domain', targetDomain);
        params.append('target_domains', JSON.stringify([])); // 清空多个域名
    }
    
    fetch('parasite.php?id=' + taskId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: params.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('修改成功');
            location.reload();
        } else {
            alert('修改失败');
        }
    })
    .catch(err => {
        console.error(err);
        alert('修改失败');
    });
});

// 显示目录的目标域名列表
function showDirectoryTargets(dirId) {
    // 从 PHP 数据中获取目录信息
    const directories = <?php echo json_encode($task['directories']); ?>;
    const dir = directories.find(d => d.id === dirId);
    
    if (!dir || !dir.target_domains || !dir.target_domains.length) {
        alert('没有找到目标域名');
        return;
    }
    
    // 构建弹窗内容
    let html = `
        <div class="modal-overlay" id="targetDomainsModal" style="display: flex;">
            <div class="modal-content" style="max-width: 600px;">
                <div class="modal-header">
                    <h3>📁 ${dir.path} - 目标域名列表</h3>
                    <button class="modal-close" onclick="closeTargetDomainsModal()">&times;</button>
                </div>
                <div class="modal-body" style="padding: 20px;">
                    <div style="margin-bottom: 15px;">
                        <span style="color: var(--primary); font-weight: 500;">随机选择模式</span>
                        <span style="color: var(--text-muted); margin-left: 10px;">共 ${dir.target_domains.length} 个目标域名</span>
                    </div>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <table class="table" style="width: 100%;">
                            <thead>
                                <tr>
                                    <th>目标域名</th>
                                    <th style="width: 80px;">操作</th>
                                </tr>
                            </thead>
                            <tbody>`;
    
    dir.target_domains.forEach((domain, index) => {
        const domainStr = typeof domain === 'string' ? domain : (domain.domain || '');
        html += `
                                <tr>
                                    <td>${domainStr}</td>
                                    <td>
                                        <button class="btn btn-sm btn-danger" onclick="deleteDirectoryTarget('${dirId}', '${domainStr}')" style="padding: 2px 8px; font-size: 12px;">删除</button>
                                    </td>
                                </tr>`;
    });
    
    html += `
                            </tbody>
                        </table>
                    </div>
                    <div style="margin-top: 20px;">
                        <button class="btn btn-primary" onclick="showAddDirectoryTargetsForm('${dirId}')">+ 添加目标域名</button>
                    </div>
                </div>
            </div>
        </div>`;
    
    // 添加到页面
    const existing = document.getElementById('targetDomainsModal');
    if (existing) existing.remove();
    document.body.insertAdjacentHTML('beforeend', html);
}

// 关闭目标域名弹窗
function closeTargetDomainsModal() {
    const modal = document.getElementById('targetDomainsModal');
    if (modal) modal.remove();
}

// 显示添加目标域名表单
function showAddDirectoryTargetsForm(dirId) {
    const domains = prompt('请输入目标域名（每行一个）：\n\n支持批量添加，每行一个域名');
    if (!domains || !domains.trim()) return;
    
    fetch('parasite.php?id=' + taskId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=add_directory_targets&dir_id=${dirId}&domains=${encodeURIComponent(domains)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message || '添加成功');
            location.reload();
        } else {
            alert('添加失败: ' + (data.message || '未知错误'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('添加失败');
    });
}

// 删除目录的某个目标域名
function deleteDirectoryTarget(dirId, targetDomain) {
    if (!confirm(`确定要删除目标域名 ${targetDomain} 吗？`)) return;
    
    fetch('parasite.php?id=' + taskId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=delete_directory_target&dir_id=${dirId}&target_domain=${encodeURIComponent(targetDomain)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('删除成功');
            location.reload();
        } else {
            alert('删除失败');
        }
    })
    .catch(err => {
        console.error(err);
        alert('删除失败');
    });
}

// 删除目录
function deleteDirectory(dirId) {
    if (!confirm('确定要删除这个目录规则吗？')) return;
    
    fetch('parasite.php?id=' + taskId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=delete_directory&dir_id=${dirId}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.querySelector(`tr[data-id="${dirId}"]`)?.remove();
        }
    });
}
</script>

<?php include 'footer.php'; ?>

