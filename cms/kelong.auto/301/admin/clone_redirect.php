<?php
/**
 * 克隆站群重定向管理
 */
$pageTitle = '克隆站群重定向 - 301重定向管理系统';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/clone_functions.php';
require_once __DIR__ . '/spider_selector.php';

// 检查登录.
if (!checkLogin()) {
    header('Location: login.php');
    exit;
}

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    $response = ['success' => false];
    
    switch ($action) {
        case 'create_group':
            $name = trim($_POST['name'] ?? '');
            if (empty($name)) {
                $response = ['success' => false, 'message' => '请输入站群组名称'];
            } else {
                // 解析蜘蛛筛选配置
                $spiderFilter = [
                    'enabled' => !empty($_POST['spider_filter_enabled']),
                    'types' => [
                        'baidu_pc' => !empty($_POST['spider_type_baidu_pc']),
                        'baidu_mobile' => !empty($_POST['spider_type_baidu_mobile']),
                        'google' => !empty($_POST['spider_type_google']),
                        'sogou' => !empty($_POST['spider_type_sogou'])
                    ]
                ];
                
                $groupId = _r301clone_createGroup($name);
                
                // 更新蜘蛛筛选配置
                if ($groupId) {
                    _r301clone_updateGroup($groupId, ['spider_filter' => $spiderFilter]);
                }
                
                $response = ['success' => true, 'group_id' => $groupId, 'message' => '站群组创建成功'];
            }
            break;
            
        // 更新蜘蛛配置
        case 'update_spider_filter':
            $groupId = $_POST['group_id'] ?? '';
            $spiderFilter = [
                'enabled' => !empty($_POST['spider_filter_enabled']),
                'types' => [
                    'baidu_pc' => !empty($_POST['spider_type_baidu_pc']),
                    'baidu_mobile' => !empty($_POST['spider_type_baidu_mobile']),
                    'google' => !empty($_POST['spider_type_google']),
                    'sogou' => !empty($_POST['spider_type_sogou'])
                ]
            ];
            
            $result = _r301clone_updateGroup($groupId, ['spider_filter' => $spiderFilter]);
            echo json_encode($result);
            exit;
            
        // 渲染编辑模式的蜘蛛选择器
        case 'render_spider_selector_edit_mode':
            $groupId = $_POST['group_id'] ?? '';
            $groups = _r301clone_getAllGroups();
            if (isset($groups[$groupId])) {
                $types = $groups[$groupId]['spider_filter']['types'] ?? [];
                ob_start();
                renderSpiderSelectorEditMode('edit', $types);
                $html = ob_get_clean();
                echo json_encode(['success' => true, 'html' => $html]);
            } else {
                echo json_encode(['success' => false, 'message' => '站群组不存在']);
            }
            exit;
            
        case 'update_group':
            $groupId = $_POST['group_id'] ?? '';
            $data = [
                'name' => $_POST['name'] ?? '',
                'target_type' => $_POST['target_type'] ?? 'three_terminals',
                'target_url' => $_POST['target_url'] ?? '',
                'redirect_type' => (int)($_POST['redirect_type'] ?? 301),
                'max_redirects' => (int)($_POST['max_redirects'] ?? 0),
                'probability' => (int)($_POST['probability'] ?? 100)
            ];
            $response = _r301clone_updateGroup($groupId, $data);
            break;
            
        case 'delete_group':
            $groupId = $_POST['group_id'] ?? '';
            $response = _r301clone_deleteGroup($groupId);
            break;
            
        case 'toggle_group':
            $groupId = $_POST['group_id'] ?? '';
            $response = _r301clone_toggleGroup($groupId);
            break;
            
        case 'add_domains':
            $groupId = $_POST['group_id'] ?? '';
            $domains = array_filter(array_map('trim', explode("\n", $_POST['domains'] ?? '')));
            $response = _r301clone_addDomains($groupId, $domains);
            break;
            
        case 'remove_domain':
            $groupId = $_POST['group_id'] ?? '';
            $domain = $_POST['domain'] ?? '';
            $response = _r301clone_removeDomain($groupId, $domain);
            break;
            
        case 'get_stats':
            $groupId = $_POST['group_id'] ?? '';
            $stats = _r301clone_getGroupStats($groupId);
            $response = [
                'success' => true,
                'total_count' => $stats['total_count'] ?? 0,
                'domain_count' => $stats['domain_count'] ?? 0,
                'latest_redirect' => $stats['latest_redirect'] ?? null
            ];
            break;
            
        case 'reset_stats':
            $groupId = $_POST['group_id'] ?? '';
            $subdomain = $_POST['subdomain'] ?? null;
            $response = _r301clone_resetGroupStats($groupId, $subdomain);
            break;
    }
    
    echo json_encode($response);
    exit;
}

// 获取所有站群组
$groups = _r301clone_getAllGroups();

require_once 'header.php';
?>

<style>
/* 覆盖模态框样式以适配暗色主题 */
.modal-overlay.active {
    display: flex !important;
}

.clone-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.stat-item {
    padding: 15px;
    background: var(--bg-hover);
    border: 1px solid var(--border);
    border-radius: 8px;
}

.stat-item h4 {
    margin: 0 0 10px 0;
    font-size: 14px;
    color: var(--text-muted);
}

.stat-item .value {
    font-size: 24px;
    font-weight: bold;
    color: var(--text);
}

.stat-item small {
    font-size: 12px;
    color: var(--text-muted);
}

/* 表格操作按钮组 */
.table .clone-actions .btn {
    padding: 6px 12px;
    font-size: 13px;
}

/* 域名列表 */
.domain-list {
    max-height: 400px;
    overflow-y: auto;
}

.domain-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px;
    background: var(--bg-hover);
    border: 1px solid var(--border);
    border-radius: 6px;
    margin-bottom: 8px;
}

.domain-item:last-child {
    margin-bottom: 0;
}

.domain-name {
    font-family: 'Consolas', 'Monaco', monospace;
    color: var(--text);
}

/* 紧凑的域名列表布局 */
.domain-grid-compact {
    display: flex;
    flex-direction: column;
    gap: 0;
}

/* 紧凑的域名项样式 */
.domain-item-compact {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 10px;
    background: transparent;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    font-size: 13px;
    transition: background 0.2s;
}

.domain-item-compact:hover {
    background: rgba(255, 255, 255, 0.05);
}

.domain-item-compact:last-child {
    border-bottom: none;
}

.domain-item-compact .domain-name {
    font-size: 13px;
    flex: 1;
    font-family: 'Consolas', 'Monaco', monospace;
    color: var(--text);
}

.btn-icon-sm {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 14px;
    padding: 0 6px;
    opacity: 0.4;
    transition: opacity 0.2s;
    flex-shrink: 0;
    margin-left: 8px;
}

.btn-icon-sm:hover {
    opacity: 1;
    color: #ff4444;
}

/* 模态框内的表单样式调整 */
.modal .form-group small {
    display: block;
    margin-top: 4px;
    font-size: 12px;
    color: var(--text-muted);
}

.modal .checkbox-group {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
}

.modal .checkbox-group label {
    display: flex;
    align-items: center;
    gap: 5px;
    font-weight: normal;
    color: var(--text);
}

.modal .checkbox-group input[type="checkbox"] {
    width: auto;
    margin: 0;
}
</style>

<div class="page-header">
    <div style="display: flex; align-items: center; justify-content: space-between;">
        <div>
            <h1 class="page-title">🧬 克隆站群重定向</h1>
            <p class="page-subtitle">管理克隆站群的三端跳转和外部重定向配置</p>
        </div>
        <div>
            <button class="btn btn-primary" onclick="showCreateGroupModal()">➕ 创建站群组</button>
            <?php require_once __DIR__ . '/help_modal.php'; renderHelpModal('clone_redirect'); ?>
        </div>
    </div>
</div>

<div class="card">
    <?php if (empty($groups)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">🧬</div>
            <h3>暂无站群组</h3>
            <p>创建站群组，批量管理多个克隆站域名的跳转规则</p>
            <button class="btn btn-primary" onclick="showCreateGroupModal()">创建第一个站群组</button>
        </div>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>站群组名称</th>
                    <th>域名数量</th>
                    <th>跳转类型</th>
                    <th>跳转目标</th>
                    <th>状态码</th>
                    <th>状态</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $groupId => $group): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($group['name']); ?></strong>
                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">
                                ID: <?php echo htmlspecialchars($groupId); ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-info"><?php echo count($group['domains'] ?? []); ?> 个域名</span>
                        </td>
                        <td>
                            <?php if (($group['target_type'] ?? 'three_terminals') === 'three_terminals'): ?>
                                <span class="badge badge-info">三端跳转</span>
                            <?php else: ?>
                                <span class="badge badge-info">外部跳转</span>
                            <?php endif; ?>
                        </td>
                        <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <?php 
                            if (($group['target_type'] ?? 'three_terminals') === 'three_terminals') {
                                echo '@/www/m (随机)';
                            } else {
                                echo htmlspecialchars($group['target_url'] ?? '');
                            }
                            ?>
                        </td>
                        <td><span class="badge badge-info"><?php echo $group['redirect_type'] ?? 301; ?></span></td>
                        <td>
                            <?php if ($group['enabled'] ?? true): ?>
                                <span class="badge badge-success">✓ 启用</span>
                            <?php else: ?>
                                <span class="badge badge-danger">✕ 禁用</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="clone-actions">
                                <button class="btn btn-sm btn-secondary" onclick="editSpiderFilterClone('<?php echo htmlspecialchars($groupId); ?>')">🕷️ 爬虫</button>
                                <button class="btn btn-sm btn-secondary" onclick="showDomainsModal('<?php echo htmlspecialchars($groupId); ?>')">📋 域名</button>
                                <button class="btn btn-sm btn-secondary" onclick="showStatsModal('<?php echo htmlspecialchars($groupId); ?>')">📊 统计</button>
                                <button class="btn btn-sm btn-primary" onclick="showEditGroupModal('<?php echo htmlspecialchars($groupId); ?>')">✏️ 编辑</button>
                                <button class="btn btn-sm <?php echo ($group['enabled'] ?? true) ? 'btn-secondary' : 'btn-success'; ?>" 
                                        onclick="toggleGroup('<?php echo htmlspecialchars($groupId); ?>')">
                                    <?php echo ($group['enabled'] ?? true) ? '⏸ 禁用' : '▶️ 启用'; ?>
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="deleteGroup('<?php echo htmlspecialchars($groupId); ?>')">🗑️ 删除</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- 第一步：选择蜘蛛类型 -->
<div id="createSpiderModal" class="modal-overlay">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">第1步：选择蜘蛛类型</h3>
            <button type="button" class="modal-close" onclick="closeAllCreateModals()">&times;</button>
        </div>
        <div class="modal-body">
            <?php renderSpiderSelector('create', false, []); ?>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeAllCreateModals()">取消</button>
            <button type="button" class="btn btn-primary" onclick="goToNameStepClone()">下一步</button>
        </div>
    </div>
</div>

<!-- 第二步：输入站群组名称 -->
<div id="createNameModal" class="modal-overlay">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">第2步：站群组名称</h3>
            <button type="button" class="modal-close" onclick="closeAllCreateModals()">&times;</button>
        </div>
        <div class="modal-body">
        <form id="createGroupForm">
            <div class="form-group">
                <label class="form-label">站群组名称 *</label>
                <input type="text" class="form-input" id="group_name" name="name" required placeholder="例如：主站群、备用站群">
                <small>为这组域名起一个容易识别的名称</small>
            </div>
        </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="backToSpiderStepClone()">上一步</button>
            <button type="button" class="btn btn-primary" onclick="submitCreateGroup()">创建</button>
        </div>
    </div>
</div>

<!-- 编辑蜘蛛配置弹窗 -->
<div id="editSpiderModal" class="modal-overlay">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">编辑蜘蛛配置</h3>
            <button type="button" class="modal-close" onclick="closeModal('editSpiderModal')">&times;</button>
        </div>
        <div class="modal-body" id="editSpiderContent">
            <!-- 动态加载 -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('editSpiderModal')">取消</button>
            <button type="button" class="btn btn-primary" onclick="saveSpiderFilterClone()">保存配置</button>
        </div>
    </div>
</div>

<!-- 编辑站群组模态框 -->
<div id="editGroupModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">编辑站群组配置</h3>
            <button type="button" class="modal-close" onclick="closeModal('editGroupModal')">&times;</button>
        </div>
        <div class="modal-body">
        <form id="editGroupForm">
            <input type="hidden" id="edit_group_id" name="group_id">
            
            <div class="form-group">
                <label class="form-label">站群组名称 *</label>
                <input type="text" class="form-input" id="edit_group_name" name="name" required>
            </div>

            <div class="form-group">
                <label class="form-label">跳转类型 *</label>
                <select class="form-select" id="edit_target_type" name="target_type" onchange="toggleEditTargetUrl()">
                    <option value="three_terminals">跳转到三端（@/www/m 随机）</option>
                    <option value="external" selected>跳转到外部目标</option>
                </select>
            </div>
            
            <div class="form-group" id="editTargetUrlGroup" style="display: block;">
                <label class="form-label">跳转目标 URL *</label>
                <input type="text" class="form-input" id="edit_target_url" name="target_url" placeholder="https://example.com/search?q={标题}">
                <small>支持占位符：{标题} {年} {月} {日} {数字N}</small>
            </div>
            
            <div class="form-group">
                <label class="form-label">状态码 *</label>
                <select class="form-select" id="edit_redirect_type" name="redirect_type">
                    <option value="301">301 (永久重定向)</option>
                    <option value="302">302 (临时重定向)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">跳转次数限制</label>
                <input type="number" class="form-input" id="edit_max_redirects" name="max_redirects" value="0" min="0">
                <small>0 表示无限制，每个二级域名单独计算</small>
            </div>
            
            <div class="form-group">
                <label class="form-label">触发概率 (%)</label>
                <input type="number" class="form-input" id="edit_probability" name="probability" value="100" min="0" max="100">
            </div>
        </div>
        <div class="modal-footer">
            <button type="submit" class="btn btn-primary">💾 保存配置</button>
            <button type="button" class="btn btn-secondary" onclick="closeModal('editGroupModal')">取消</button>
        </div>
        </form>
    </div>
</div>

<!-- 域名管理模态框 -->
<div id="domainsModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="domainsTitle">域名管理</h3>
            <button type="button" class="modal-close" onclick="closeModal('domainsModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">添加域名（每行一个）</label>
                <textarea class="form-textarea" id="new_domains" placeholder="example1.com&#10;example2.com&#10;example3.com" rows="5"></textarea>
                <button type="button" class="btn btn-primary" style="margin-top: 10px;" onclick="addDomains()">➕ 添加域名</button>
            </div>
            
            <div class="form-group">
                <label class="form-label">当前域名列表</label>
                <button type="button" class="btn btn-secondary" onclick="showDomainListModal()" style="width: 100%;">
                    📋 查看域名列表 (<span id="domainCount">0</span> 个域名)
                </button>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('domainsModal')">关闭</button>
        </div>
    </div>
</div>

<!-- 域名列表查看模态框 -->
<div id="domainListModal" class="modal-overlay">
    <div class="modal" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title">域名列表</h3>
            <button type="button" class="modal-close" onclick="closeModal('domainListModal')">&times;</button>
        </div>
        <div class="modal-body" style="max-height: 500px; overflow-y: auto; padding: 0;">
            <div id="domainListContent" class="domain-grid-compact">
                <p style="text-align: center; color: var(--text-muted); padding: 20px;">加载中...</p>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('domainListModal')">关闭</button>
        </div>
    </div>
</div>

<!-- 统计模态框 -->
<div id="statsModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="statsTitle">跳转统计</h3>
            <button type="button" class="modal-close" onclick="closeModal('statsModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div id="statsContent">
                <p style="text-align: center; color: var(--text-muted);">加载中...</p>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('statsModal')">关闭</button>
        </div>
    </div>
</div>

<script>
const groups = <?php echo json_encode($groups); ?>;
let currentGroupId = '';

// 全局变量存储当前编辑的站群组ID
let currentEditGroupId = null;

function showCreateGroupModal() {
    document.getElementById('createGroupForm').reset();
    document.getElementById('createSpiderModal').classList.add('active');
}

function closeAllCreateModals() {
    document.getElementById('createSpiderModal').classList.remove('active');
    document.getElementById('createNameModal').classList.remove('active');
}

function goToNameStepClone() {
    document.getElementById('createSpiderModal').classList.remove('active');
    document.getElementById('createNameModal').classList.add('active');
    document.getElementById('group_name').focus();
}

function backToSpiderStepClone() {
    document.getElementById('createNameModal').classList.remove('active');
    document.getElementById('createSpiderModal').classList.add('active');
}

function showEditGroupModal(groupId) {
    const group = groups[groupId];
    if (!group) return;
    
    currentGroupId = groupId;
    document.getElementById('edit_group_id').value = groupId;
    document.getElementById('edit_group_name').value = group.name;
    document.getElementById('edit_target_type').value = group.target_type ?? 'external';
    document.getElementById('edit_target_url').value = group.target_url || '';
    document.getElementById('edit_redirect_type').value = group.redirect_type ?? 301;
    document.getElementById('edit_max_redirects').value = group.max_redirects ?? 0;
    document.getElementById('edit_probability').value = group.probability ?? 100;
    
    document.getElementById('editGroupModal').classList.add('active');
    toggleEditTargetUrl();
}

function showDomainsModal(groupId) {
    currentGroupId = groupId;
    const group = groups[groupId];
    document.getElementById('domainsTitle').textContent = group.name + ' - 域名管理';
    document.getElementById('new_domains').value = '';
    
    // 更新域名数量显示
    const domains = group.domains || [];
    document.getElementById('domainCount').textContent = domains.length;
    
    document.getElementById('domainsModal').classList.add('active');
}

// 显示域名列表弹窗
function showDomainListModal() {
    if (!currentGroupId) return;
    
    const group = groups[currentGroupId];
    const domains = group.domains || [];
    const domainListContent = document.getElementById('domainListContent');
    
    if (domains.length === 0) {
        domainListContent.innerHTML = '<p style="text-align: center; color: var(--text-muted); grid-column: 1 / -1;">暂无域名</p>';
    } else {
        let html = '';
        domains.forEach(domain => {
            html += `
                <div class="domain-item-compact">
                    <span class="domain-name">${domain}</span>
                    <button class="btn-icon-sm" onclick="removeDomain('${currentGroupId}', '${domain}')" title="删除">🗑️</button>
                </div>
            `;
        });
        domainListContent.innerHTML = html;
    }
    
    document.getElementById('domainListModal').classList.add('active');
}

function showStatsModal(groupId) {
    currentGroupId = groupId;
    const group = groups[groupId];
    document.getElementById('statsTitle').textContent = group.name + ' - 跳转统计';
    document.getElementById('statsContent').innerHTML = '<p style="text-align: center; color: var(--text-muted);">加载中...</p>';
    document.getElementById('statsModal').classList.add('active');
    
    fetch('clone_redirect.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=get_stats&group_id=' + encodeURIComponent(groupId)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            let html = '';
            
            // 计算汇总数据
            const totalCount = data.total_count || 0;
            const domainCount = data.domain_count || 0;
            const latestRedirect = data.latest_redirect || '无';
            
            if (totalCount === 0) {
                html += '<div class="empty-state"><div class="empty-state-icon">📊</div><p>暂无跳转记录</p></div>';
            } else {
                // 汇总统计卡片
                html += '<div class="stats-grid" style="grid-template-columns: repeat(3, 1fr); margin-bottom: 20px;">';
                html += `
                    <div class="stat-item" style="text-align: center;">
                        <h4>总跳转次数</h4>
                        <div class="value" style="color: #3b82f6;">${totalCount.toLocaleString()} 次</div>
                    </div>
                    <div class="stat-item" style="text-align: center;">
                        <h4>活跃域名数</h4>
                        <div class="value" style="color: #10b981;">${domainCount} 个</div>
                    </div>
                    <div class="stat-item" style="text-align: center;">
                        <h4>最后跳转</h4>
                        <div class="value" style="font-size: 14px; color: var(--text-muted);">${latestRedirect}</div>
                    </div>
                `;
                html += '</div>';
                
                // 重置按钮
                html += `<div style="text-align: center;">
                    <button class="btn btn-danger" onclick="resetAllStats('${groupId}')">🗑️ 重置所有统计</button>
                </div>`;
            }
            
            document.getElementById('statsContent').innerHTML = html;
        }
    });
}

function toggleEditTargetUrl() {
    const targetType = document.getElementById('edit_target_type').value;
    const targetUrlGroup = document.getElementById('editTargetUrlGroup');
    targetUrlGroup.style.display = targetType === 'external' ? 'block' : 'none';
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

// 创建站群组
function submitCreateGroup() {
    const form = document.getElementById('createGroupForm');
    const formData = new FormData(form);
    formData.set('action', 'create_group');
    
    // 获取蜘蛛筛选配置
    const spiderConfig = getSpiderFilterConfig('create');
    formData.append('spider_filter_enabled', spiderConfig.enabled ? '1' : '');
    formData.append('spider_type_baidu_pc', spiderConfig.types.baidu_pc ? '1' : '');
    formData.append('spider_type_baidu_mobile', spiderConfig.types.baidu_mobile ? '1' : '');
    formData.append('spider_type_google', spiderConfig.types.google ? '1' : '');
    formData.append('spider_type_sogou', spiderConfig.types.sogou ? '1' : '');
    
    fetch('clone_redirect.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            location.reload();
        }
    });
}

// 编辑站群组
document.getElementById('editGroupForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.set('action', 'update_group');
    
    fetch('clone_redirect.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            location.reload();
        }
    });
});

function toggleGroup(groupId) {
    if (!confirm('确定要切换状态吗？')) return;
    
    fetch('clone_redirect.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=toggle_group&group_id=' + encodeURIComponent(groupId)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || '操作失败');
        }
    });
}

function deleteGroup(groupId) {
    if (!confirm('确定要删除该站群组吗？这将同时删除所有相关的统计数据！')) return;
    
    fetch('clone_redirect.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=delete_group&group_id=' + encodeURIComponent(groupId)
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            location.reload();
        }
    });
}

function addDomains() {
    const domains = document.getElementById('new_domains').value;
    if (!domains.trim()) {
        alert('请输入域名');
        return;
    }
    
    const formData = new FormData();
    formData.set('action', 'add_domains');
    formData.set('group_id', currentGroupId);
    formData.set('domains', domains);
    
    fetch('clone_redirect.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            location.reload();
        }
    });
}

function removeDomain(groupId, domain) {
    if (!confirm('确定要删除域名 ' + domain + ' 吗？')) return;
    
    fetch('clone_redirect.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=remove_domain&group_id=' + encodeURIComponent(groupId) + '&domain=' + encodeURIComponent(domain)
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            location.reload();
        }
    });
}

function resetAllStats(groupId) {
    if (!confirm('确定要重置该站群组的所有统计数据吗？\n\n这将清空该站群组下所有域名的跳转记录！')) return;
    
    fetch('clone_redirect.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=reset_stats&group_id=' + encodeURIComponent(groupId)
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            showStatsModal(groupId);
        }
    });
}

// 点击模态框外部关闭
window.onclick = function(event) {
    if (event.target.classList.contains('modal-overlay')) {
        event.target.classList.remove('active');
    }
}

// 编辑蜘蛛配置
function editSpiderFilterClone(groupId) {
    currentEditGroupId = groupId;
    
    const formData = new FormData();
    formData.append('action', 'render_spider_selector_edit_mode');
    formData.append('group_id', groupId);
    
    fetch('clone_redirect.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('editSpiderContent').innerHTML = data.html;
            document.getElementById('editSpiderModal').classList.add('active');
        } else {
            alert(data.message || '加载失败');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        alert('加载失败');
    });
}

// 保存蜘蛛配置
function saveSpiderFilterClone() {
    if (!currentEditGroupId) {
        alert('站群组ID丢失');
        return;
    }
    
    const spiderConfig = getSpiderFilterConfigEditMode('edit');
    
    const formData = new FormData();
    formData.append('action', 'update_spider_filter');
    formData.append('group_id', currentEditGroupId);
    formData.append('spider_filter_enabled', '1');
    formData.append('spider_type_baidu_pc', spiderConfig.types.baidu_pc ? '1' : '');
    formData.append('spider_type_baidu_mobile', spiderConfig.types.baidu_mobile ? '1' : '');
    formData.append('spider_type_google', spiderConfig.types.google ? '1' : '');
    formData.append('spider_type_sogou', spiderConfig.types.sogou ? '1' : '');
    
    fetch('clone_redirect.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeModal('editSpiderModal');
            alert('蜘蛛配置已更新');
            location.reload();
        } else {
            alert(data.message || '更新失败');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        alert('更新失败');
    });
}

// 从 spider_selector.php 引入的函数
function getSpiderFilterConfigEditMode(id) {
    const types = {
        baidu_pc: false,
        baidu_mobile: false,
        google: false,
        sogou: false
    };
    
    const container = document.getElementById('spiderTypes_' + id);
    if (!container) {
        console.error('Spider types container not found:', 'spiderTypes_' + id);
        return { enabled: true, types: types };
    }
    
    const checkboxes = container.querySelectorAll('input[type="checkbox"]');
    checkboxes.forEach(cb => {
        const name = cb.name.replace('spider_type_' + id + '_', '');
        types[name] = cb.checked;
    });
    
    return { enabled: true, types: types };
}
</script>

<?php require_once 'footer.php'; ?>
