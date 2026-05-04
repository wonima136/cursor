<?php
/**
 * 站群链轮管理 - 分组详情/编辑
 */
$pageTitle = '站群链轮管理 - 301重定向管理系统';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/group_functions.php';

// 判断是否为 AJAX 请求
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' ||
          $_SERVER['REQUEST_METHOD'] === 'POST';

// 检查登录状态
if (!checkLogin()) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '登录已过期，请刷新页面重新登录']);
        exit;
    }
    header('Location: login.php');
    exit;
}

$groupId = $_GET['id'] ?? '';
if (empty($groupId)) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '分组ID无效']);
        exit;
    }
    header('Location: groups.php');
    exit;
}

$group = _r301group_getById($groupId);
if (!$group) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '分组不存在']);
        exit;
    }
    header('Location: groups.php');
    exit;
}

// 处理AJAX请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'update_settings':
            // 解析权重关键词
            $weightKeywordsText = $_POST['weight_keywords'] ?? '';
            $weightKeywords = _r301group_parseWeightKeywords($weightKeywordsText);
            
            $redirectType = $_POST['redirect_type'] ?? '301';
            $probability = min(30, max(1, intval($_POST['probability'] ?? 30)));
            $followSubdomain = isset($_POST['follow_subdomain']) && $_POST['follow_subdomain'] === '1';
            $followUri = isset($_POST['follow_uri']) && $_POST['follow_uri'] === '1';
            $fixedTarget = trim($_POST['fixed_target'] ?? '');
            $chainMode = $_POST['chain_mode'] ?? 'sequential'; // 链轮模式
            
            $settings = [
                'redirect_type' => $redirectType,
                'probability' => $probability,
                'follow_subdomain' => $followSubdomain,
                'follow_uri' => $followUri,
                'fixed_target' => $fixedTarget,
                'chain_mode' => $chainMode,
                'weight_keywords' => $weightKeywords,
                'weight_mode' => trim($_POST['weight_mode'] ?? ''),
            ];
            $name = trim($_POST['name'] ?? $group['name']);
            
            // ★ 同时更新根级别的配置字段，确保程序能正确读取
            _r301group_update($groupId, [
                'name' => $name,
                'redirect_type' => $redirectType,
                'probability' => $probability,
                'follow_subdomain' => $followSubdomain,
                'follow_uri' => $followUri,
                'fixed_target' => $fixedTarget, // 整组固定目标
                'chain_mode' => $chainMode, // 链轮模式
                'settings' => $settings,
            ]);
            
            // 重建 Redis 域名索引
            require_once __DIR__ . '/domain_index.php';
            $indexCount = rebuildDomainIndex();
            
            echo json_encode([
                'success' => true, 
                'message' => '设置已保存' . ($indexCount !== false ? "，域名索引已更新（{$indexCount}个域名）" : '')
            ]);
            exit;
            
        case 'toggle':
            $enabled = $_POST['enabled'] === '1';
            _r301group_toggle($groupId, $enabled);
            
            // 重建 Redis 域名索引
            require_once __DIR__ . '/domain_index.php';
            rebuildDomainIndex();
            
            echo json_encode(['success' => true]);
            exit;
            
        case 'add_domains':
            $domainsText = $_POST['domains'] ?? '';
            $defaultWeight = max(1, intval($_POST['default_weight'] ?? 1));
            $result = _r301group_addDomains($groupId, $domainsText, $defaultWeight);
            
            // 重建 Redis 域名索引
            require_once __DIR__ . '/domain_index.php';
            rebuildDomainIndex();
            
            echo json_encode([
                'success' => true, 
                'message' => "成功添加 {$result['added']} 个域名，跳过 {$result['skipped']} 个"
            ]);
            exit;
            
        case 'update_domain':
            $domain = $_POST['domain'] ?? '';
            $data = [];
            if (isset($_POST['enabled'])) {
                $data['enabled'] = $_POST['enabled'] === '1';
            }
            if (isset($_POST['weight'])) {
                $data['weight'] = intval($_POST['weight']);
            }
            if (isset($_POST['fixed_target'])) {
                $data['fixed_target'] = trim($_POST['fixed_target']);
            }
            if (isset($_POST['follow_subdomain'])) {
                $data['follow_subdomain'] = $_POST['follow_subdomain'] === '1';
            }
            if (isset($_POST['follow_uri'])) {
                $data['follow_uri'] = $_POST['follow_uri'] === '1';
            }
            if (isset($_POST['redirect_type'])) {
                $data['redirect_type'] = $_POST['redirect_type'];
            }
            _r301group_updateDomain($groupId, $domain, $data);
            
            // 重建 Redis 域名索引
            require_once __DIR__ . '/domain_index.php';
            rebuildDomainIndex();
            
            echo json_encode(['success' => true, 'message' => '设置已保存']);
            exit;
            
        case 'delete_domain':
            $domain = $_POST['domain'] ?? '';
            _r301group_deleteDomain($groupId, $domain);
            
            // 重建 Redis 域名索引
            require_once __DIR__ . '/domain_index.php';
            rebuildDomainIndex();
            
            echo json_encode(['success' => true]);
            exit;
            
        case 'clear_domains':
            _r301group_clearDomains($groupId);
            
            // 重建 Redis 域名索引
            require_once __DIR__ . '/domain_index.php';
            rebuildDomainIndex();
            
            echo json_encode(['success' => true, 'message' => '已清空所有域名']);
            exit;
    }
    
    echo json_encode(['success' => false, 'message' => '未知操作']);
    exit;
}

// 重新获取最新数据
$group = _r301group_getById($groupId);
$settings = $group['settings'] ?? [];
$domains = $group['domains'] ?? [];

include 'header.php';
?>

<style>
.back-link {
    color: var(--text-muted);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 4px;
    margin-bottom: 16px;
}
.back-link:hover {
    color: var(--text);
}
.card-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border);
}
.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 16px;
}
.form-control {
    width: 100%;
    padding: 10px 14px;
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text);
    font-size: 14px;
}
.form-control:focus {
    outline: none;
    border-color: var(--primary);
}
.form-select {
    width: 100%;
    padding: 10px 14px;
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text);
    font-size: 14px;
}
.switch-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid var(--border);
}
.switch-row:last-child {
    border-bottom: none;
}
.switch-label {
    font-weight: 500;
    color: var(--text);
}
.switch-desc {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 2px;
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
    transform: translateX(24px);
}
.domain-table {
    width: 100%;
    border-collapse: collapse;
}
.domain-table th {
    text-align: left;
    padding: 12px;
    background: var(--bg-dark);
    font-weight: 500;
    color: var(--text-muted);
    font-size: 13px;
}
.domain-table td {
    padding: 12px;
    border-bottom: 1px solid var(--border);
    color: var(--text);
}
.domain-table tr:hover td {
    background: var(--bg-hover);
}
.domain-name {
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 14px;
    color: var(--text);
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
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    border: 1px solid var(--border);
}
.stat-value {
    font-size: 32px;
    font-weight: 700;
    color: var(--text);
}
.stat-label {
    font-size: 13px;
    color: var(--text-muted);
    margin-top: 4px;
}
.empty-domains {
    text-align: center;
    padding: 40px;
    color: var(--text-muted);
}
.fixed-target-input {
    width: 140px;
    padding: 6px 10px;
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 4px;
    font-size: 12px;
    color: var(--text);
}
.fixed-target-input:focus {
    outline: none;
    border-color: var(--primary);
}
.domain-select {
    padding: 4px 8px;
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 4px;
    font-size: 12px;
    color: var(--text);
    cursor: pointer;
}
.domain-select:focus {
    outline: none;
    border-color: var(--primary);
}
.btn-save-domain {
    padding: 4px 10px;
    font-size: 12px;
    background: var(--primary);
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    white-space: nowrap;
}
.btn-save-domain:hover {
    background: var(--primary-light);
}
.btn-save-domain:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.domain-actions {
    display: flex;
    gap: 6px;
    align-items: center;
}
.fixed-target-hint {
    font-size: 11px;
    color: var(--warning);
    margin-top: 2px;
}
.saved-indicator {
    color: var(--success);
    font-size: 12px;
    opacity: 0;
    transition: opacity 0.3s;
}
.saved-indicator.show {
    opacity: 1;
}
.form-control-sm {
    padding: 6px 10px;
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 4px;
    color: var(--text);
    font-size: 13px;
}
.btn-outline-primary {
    background: transparent;
    border: 1px solid var(--primary);
    color: var(--primary-light);
}
.btn-outline-primary:hover {
    background: var(--primary);
    color: white;
}
.btn-outline-danger {
    background: transparent;
    border: 1px solid var(--error);
    color: var(--error);
}
.btn-outline-danger:hover {
    background: var(--error);
    color: white;
}
.search-box {
    margin-bottom: 16px;
}
.search-box input {
    width: 100%;
    padding: 10px 16px;
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 14px;
    color: var(--text);
}
.search-box input:focus {
    outline: none;
    border-color: var(--primary);
}
/* 覆盖卡片样式 */
.card {
    background: var(--bg-card) !important;
    border: 1px solid var(--border) !important;
}
textarea.form-control {
    background: var(--bg-dark);
    border: 1px solid var(--border);
    color: var(--text);
    min-height: 150px;
    resize: vertical;
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 13px;
    line-height: 1.6;
}
textarea.form-control:focus {
    outline: none;
    border-color: var(--primary);
}
textarea[name="weight_keywords"] {
    min-height: 100px;
    font-size: 13px;
}
textarea.form-control::placeholder {
    color: var(--text-muted);
}
</style>

<div class="container">
    <a href="groups.php" class="back-link">
        <i class="bi bi-arrow-left"></i> 返回分组列表
    </a>
    
    <div class="page-header">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <h1 class="page-title"><?php echo htmlspecialchars($group['name']); ?></h1>
            <div>
                <?php require_once __DIR__ . '/help_modal.php'; renderHelpModal('group_task'); ?>
            </div>
        </div>
    </div>
    
    <!-- 状态栏 -->
    <div class="status-header <?php echo $group['enabled'] ? '' : 'disabled'; ?>" id="statusHeader">
        <div class="status-text">
            <h3 id="statusTitle"><?php echo $group['enabled'] ? '🟢 分组运行中' : '⚪ 分组已停止'; ?></h3>
            <p id="statusDesc"><?php echo $group['enabled'] ? '该分组的域名正在参与轮链跳转' : '启用分组后，域名将开始参与轮链跳转'; ?></p>
        </div>
        <label class="switch">
            <input type="checkbox" id="groupEnabled" <?php echo $group['enabled'] ? 'checked' : ''; ?> onchange="toggleGroup(this.checked)">
            <span class="slider"></span>
        </label>
    </div>
    
    <!-- 统计卡片 -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-value"><?php echo $group['stats']['total_domains'] ?? 0; ?></div>
            <div class="stat-label">总域名数</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $group['stats']['enabled_domains'] ?? 0; ?></div>
            <div class="stat-label">启用域名</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $group['stats']['total_redirects'] ?? 0; ?></div>
            <div class="stat-label">跳转次数</div>
        </div>
    </div>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <!-- 跳转设置 -->
        <div class="card">
            <div class="card-title">跳转设置</div>
            
            <form id="settingsForm">
                <div class="form-group">
                    <label class="form-label">分组名称</label>
                    <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($group['name']); ?>">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">跳转方式</label>
                        <select class="form-select" name="redirect_type">
                            <option value="301" <?php echo ($settings['redirect_type'] ?? '301') === '301' ? 'selected' : ''; ?>>301 永久重定向</option>
                            <option value="302" <?php echo ($settings['redirect_type'] ?? '301') === '302' ? 'selected' : ''; ?>>302 临时重定向</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">跳转概率 (1-30%)</label>
                        <input type="number" class="form-control" name="probability" min="1" max="30" value="<?php echo $settings['probability'] ?? 30; ?>">
                        <div class="form-hint">为防止死循环，最高限制30%</div>
                    </div>
                </div>
                
                <div class="switch-row">
                    <div>
                        <div class="switch-label">跟随二级域名</div>
                        <div class="switch-desc">如 abc.a.com 跳转到 abc.b.com</div>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="follow_subdomain" value="1" <?php echo !empty($settings['follow_subdomain']) ? 'checked' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                </div>
                
                <div class="switch-row">
                    <div>
                        <div class="switch-label">跟随URI路径</div>
                        <div class="switch-desc">如 a.com/page 跳转到 b.com/page</div>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="follow_uri" value="1" <?php echo !empty($settings['follow_uri']) ? 'checked' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                </div>
                
                <div class="form-group" style="margin-top: 16px;">
                    <label class="form-label">整组固定跳转目标（可选）</label>
                    <input type="text" class="form-control" name="fixed_target" value="<?php echo htmlspecialchars($settings['fixed_target'] ?? ''); ?>" placeholder="如：www.example.com">
                    <div class="form-hint">设置后，该分组所有域名都跳转到此目标（可被单个域名设置覆盖）</div>
                </div>
                
                <div class="form-group" style="margin-top: 16px;">
                    <label class="form-label">链轮模式</label>
                    <select class="form-control" name="chain_mode">
                        <option value="sequential" <?php echo ($settings['chain_mode'] ?? 'sequential') === 'sequential' ? 'selected' : ''; ?>>顺序链轮（跑火车）</option>
                        <option value="random" <?php echo ($settings['chain_mode'] ?? 'sequential') === 'random' ? 'selected' : ''; ?>>随机链轮</option>
                    </select>
                    <div class="form-hint">
                        <strong>顺序链轮：</strong>按域名顺序依次跳转（A→B→C→A），形成闭环<br>
                        <strong>随机链轮：</strong>每次随机选择其他域名跳转，不固定顺序
                    </div>
                </div>
                
                <div class="form-group" style="margin-top: 16px;">
                    <label class="form-label">权重二级/内页关键词（可选）</label>
                    <textarea class="form-control" name="weight_keywords" rows="4" placeholder="每行一个关键词，例如：&#10;news&#10;article&#10;blog"><?php 
                        $weightKeywords = $settings['weight_keywords'] ?? [];
                        echo htmlspecialchars(implode("\n", $weightKeywords)); 
                    ?></textarea>
                    <div class="form-hint">设置后将替代随机域名选择逻辑</div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">组合模式</label>
                    <select class="form-select" name="weight_mode">
                        <option value="" <?php echo empty($settings['weight_mode']) ? 'selected' : ''; ?>>不使用权重关键词</option>
                        <option value="subdomain" <?php echo ($settings['weight_mode'] ?? '') === 'subdomain' ? 'selected' : ''; ?>>组合二级域名 (news.example.com)</option>
                        <option value="path" <?php echo ($settings['weight_mode'] ?? '') === 'path' ? 'selected' : ''; ?>>组合内页路径 (example.com/news/)</option>
                    </select>
                    <div class="form-hint">选择如何将关键词与域名组合</div>
                </div>
                
                <button type="submit" class="btn btn-primary" style="margin-top: 16px;">保存设置</button>
            </form>
        </div>
        
        <!-- 添加域名 -->
        <div class="card">
            <div class="card-title">添加域名</div>
            
            <form id="addDomainsForm" style="display: flex; flex-direction: column; height: calc(100% - 50px);">
                <div class="form-group" style="flex: 1; display: flex; flex-direction: column;">
                    <label class="form-label">域名列表</label>
                    <textarea class="form-control" name="domains" style="flex: 1; min-height: 200px;" placeholder="每行一个域名，支持格式：
domain.com
domain.com,权重
www.domain.com 权重

# 以#开头的行为注释"></textarea>
                </div>
                
                <div style="display: flex; align-items: center; gap: 16px; margin-top: 12px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <label class="form-label" style="margin: 0; white-space: nowrap;">默认权重</label>
                        <input type="number" class="form-control" name="default_weight" min="1" value="1" style="width: 80px;">
                    </div>
                    <button type="submit" class="btn btn-primary">添加域名</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- 域名列表 -->
    <div class="card">
        <div class="card-title" style="display: flex; justify-content: space-between; align-items: center;">
            <span>域名列表 (<?php echo count($domains); ?>)</span>
            <?php if (!empty($domains)): ?>
            <button class="btn btn-sm btn-outline-danger" onclick="clearAllDomains()">清空全部</button>
            <?php endif; ?>
        </div>
        
        <?php if (empty($domains)): ?>
        <div class="empty-domains">
            <p>还没有添加任何域名</p>
            <p style="font-size: 13px;">在右侧添加域名后，它们将出现在这里</p>
        </div>
        <?php else: ?>
        
        <div class="search-box">
            <input type="text" id="searchDomain" placeholder="搜索域名..." onkeyup="filterDomains()">
        </div>
        
        <table class="domain-table" id="domainTable">
            <thead>
                <tr>
                    <th>域名</th>
                    <th style="width: 55px;">权重</th>
                    <th style="width: 60px;">状态</th>
                    <th style="width: 75px;">跳转</th>
                    <th style="width: 65px;">二级</th>
                    <th style="width: 65px;">URI</th>
                    <th style="width: 140px;">固定目标</th>
                    <th style="width: 150px;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($domains as $d): 
                    $domainEsc = htmlspecialchars($d['domain']);
                    // 如果单个域名没有设置，使用分组的全局设置
                    $followSub = isset($d['follow_subdomain']) ? $d['follow_subdomain'] : ($settings['follow_subdomain'] ?? false);
                    $followUri = isset($d['follow_uri']) ? $d['follow_uri'] : ($settings['follow_uri'] ?? false);
                    $redirectType = $d['redirect_type'] ?? $settings['redirect_type'] ?? '302';
                ?>
                <tr data-domain="<?php echo $domainEsc; ?>">
                    <td class="domain-name">
                        <?php echo $domainEsc; ?>
                        <?php if (!empty($d['fixed_target'])): ?>
                        <div class="fixed-target-hint">固定跳转 100%</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-sm domain-input" data-field="weight"
                               value="<?php echo $d['weight'] ?? 1; ?>" min="1" style="width: 45px;">
                    </td>
                    <td>
                        <label class="switch" style="transform: scale(0.7);">
                            <input type="checkbox" class="domain-input" data-field="enabled"
                                   <?php echo !empty($d['enabled']) ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </td>
                    <td>
                        <select class="domain-select domain-input" data-field="redirect_type" style="width: 65px;">
                            <option value="302" <?php echo $redirectType === '302' ? 'selected' : ''; ?>>302</option>
                            <option value="301" <?php echo $redirectType === '301' ? 'selected' : ''; ?>>301</option>
                        </select>
                    </td>
                    <td>
                        <label class="switch" style="transform: scale(0.7);" title="<?php echo !isset($d['follow_subdomain']) ? '继承全局设置' : ''; ?>">
                            <input type="checkbox" class="domain-input" data-field="follow_subdomain"
                                   <?php echo $followSub ? 'checked' : ''; ?>
                                   style="<?php echo !isset($d['follow_subdomain']) ? 'opacity: 0.6;' : ''; ?>">
                            <span class="slider"></span>
                        </label>
                    </td>
                    <td>
                        <label class="switch" style="transform: scale(0.7);" title="<?php echo !isset($d['follow_uri']) ? '继承全局设置' : ''; ?>">
                            <input type="checkbox" class="domain-input" data-field="follow_uri"
                                   <?php echo $followUri ? 'checked' : ''; ?>
                                   style="<?php echo !isset($d['follow_uri']) ? 'opacity: 0.6;' : ''; ?>">
                            <span class="slider"></span>
                        </label>
                    </td>
                    <td>
                        <input type="text" class="fixed-target-input domain-input" data-field="fixed_target"
                               value="<?php echo htmlspecialchars($d['fixed_target'] ?? ''); ?>" placeholder="留空则随机">
                    </td>
                    <td class="domain-actions">
                        <button class="btn-save-domain" onclick="saveDomain('<?php echo $domainEsc; ?>', this)">保存</button>
                        <span class="saved-indicator">✓</span>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteDomain('<?php echo $domainEsc; ?>')" style="white-space: nowrap;">删除</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<script>
const groupId = '<?php echo htmlspecialchars($groupId); ?>';

// 保存设置
document.getElementById('settingsForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'update_settings');
    
    // 处理checkbox
    if (!this.follow_subdomain.checked) {
        formData.set('follow_subdomain', '0');
    }
    if (!this.follow_uri.checked) {
        formData.set('follow_uri', '0');
    }
    
    fetch('group.php?id=' + groupId, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('设置已保存');
        } else {
            alert(data.message || '保存失败');
        }
    });
});

// 添加域名
document.getElementById('addDomainsForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'add_domains');
    
    fetch('group.php?id=' + groupId, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert(data.message || '添加失败');
        }
    });
});

// 切换分组开关
function toggleGroup(enabled) {
    fetch('group.php?id=' + groupId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=toggle&enabled=${enabled ? 1 : 0}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const header = document.getElementById('statusHeader');
            const title = document.getElementById('statusTitle');
            const desc = document.getElementById('statusDesc');
            
            if (enabled) {
                header.classList.remove('disabled');
                title.textContent = '🟢 分组运行中';
                desc.textContent = '该分组的域名正在参与轮链跳转';
            } else {
                header.classList.add('disabled');
                title.textContent = '⚪ 分组已停止';
                desc.textContent = '启用分组后，域名将开始参与轮链跳转';
            }
        }
    });
}

// 保存域名设置
function saveDomain(domain, btn) {
    const row = document.querySelector(`tr[data-domain="${domain}"]`);
    if (!row) return;
    
    const inputs = row.querySelectorAll('.domain-input');
    const params = new URLSearchParams();
    params.append('action', 'update_domain');
    params.append('domain', domain);
    
    inputs.forEach(input => {
        const field = input.dataset.field;
        let value;
        
        if (input.type === 'checkbox') {
            value = input.checked ? '1' : '0';
        } else {
            value = input.value;
        }
        
        params.append(field, value);
    });
    
    btn.disabled = true;
    btn.textContent = '保存中...';
    
    fetch('group.php?id=' + groupId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: params.toString()
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.textContent = '保存';
        
        if (data.success) {
            // 显示保存成功指示器
            const indicator = row.querySelector('.saved-indicator');
            indicator.classList.add('show');
            setTimeout(() => indicator.classList.remove('show'), 2000);
            
            // 更新固定目标提示
            const fixedTarget = row.querySelector('input[data-field="fixed_target"]').value;
            const hint = row.querySelector('.fixed-target-hint');
            const domainCell = row.querySelector('.domain-name');
            
            if (fixedTarget && !hint) {
                const newHint = document.createElement('div');
                newHint.className = 'fixed-target-hint';
                newHint.textContent = '固定跳转 100%';
                domainCell.appendChild(newHint);
            } else if (!fixedTarget && hint) {
                hint.remove();
            }
        } else {
            alert(data.message || '保存失败');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.textContent = '保存';
        alert('保存失败: ' + err.message);
    });
}

// 删除域名
function deleteDomain(domain) {
    if (!confirm(`确定要删除域名 ${domain} 吗？`)) {
        return;
    }
    
    fetch('group.php?id=' + groupId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=delete_domain&domain=${encodeURIComponent(domain)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.querySelector(`tr[data-domain="${domain}"]`).remove();
        }
    });
}

// 清空所有域名
function clearAllDomains() {
    if (!confirm('确定要清空所有域名吗？此操作不可恢复！')) {
        return;
    }
    
    fetch('group.php?id=' + groupId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=clear_domains'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}

// 搜索域名
function filterDomains() {
    const search = document.getElementById('searchDomain').value.toLowerCase();
    const rows = document.querySelectorAll('#domainTable tbody tr');
    
    rows.forEach(row => {
        const domain = row.dataset.domain.toLowerCase();
        row.style.display = domain.includes(search) ? '' : 'none';
    });
}
</script>

<?php include 'footer.php'; ?>

