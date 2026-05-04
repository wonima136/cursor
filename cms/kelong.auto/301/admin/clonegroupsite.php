<?php
/**
 * 克隆站分组管理 - 主页面
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/clonegroupsite_functions.php';

// 处理CSV导出
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    if (!checkLogin()) {
        header('Location: login.php');
        exit;
    }
    _clonegroupsite_downloadCSV();
    exit;
}

// 处理CSV导入
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    if (!checkLogin()) {
        echo json_encode(['success' => false, 'message' => '登录已过期']);
        exit;
    }
    
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => '文件上传失败']);
        exit;
    }
    
    $result = _clonegroupsite_importCSV($_FILES['csv_file']['tmp_name']);
    echo json_encode($result);
    exit;
}

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    if (!checkLogin()) {
        echo json_encode(['success' => false, 'message' => '登录已过期']);
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => '未知操作'];
    
    switch ($action) {
        case 'clean_data':
            $result = _clonegroupsite_cleanData();
            $response = $result;
            break;
            
        case 'update_config':
            $groupName = $_POST['group_name'] ?? '';
            $redirectMode = $_POST['redirect_mode'] ?? 'random_three';
            $redirectTarget = $_POST['redirect_target'] ?? '';
            $includeThreeTerminal = isset($_POST['include_three_terminal']) ? 1 : 0;
            
            if (empty($groupName)) {
                $response = ['success' => false, 'message' => '分组名称不能为空'];
                break;
            }
            
            // 如果是集权模式，处理集权对象
            if ($redirectMode === 'centralize') {
                $centralizeTargetsRaw = $_POST['centralize_targets'] ?? '';
                
                // 分割域名（支持换行和逗号）
                $targets = preg_split('/[\r\n,]+/', $centralizeTargetsRaw);
                $targets = array_filter(array_map('trim', $targets));
                
                if (empty($targets)) {
                    $response = ['success' => false, 'message' => '请输入至少一个集权对象域名'];
                    break;
                }
                
                // 验证域名是否重复
                $validation = _clonegroupsite_validateCentralizeTargets($targets);
                if (!$validation['valid']) {
                    $response = ['success' => false, 'message' => implode("\n", $validation['errors'])];
                    break;
                }
                
                // 保存集权配置
                $result = _clonegroupsite_updateCentralizeConfig($groupName, true, $targets);
                if ($result) {
                    $response = ['success' => true, 'message' => '集权配置已保存'];
                } else {
                    $response = ['success' => false, 'message' => '保存失败'];
                }
            } else {
                // 普通模式，清除集权配置
                _clonegroupsite_updateCentralizeConfig($groupName, false, []);
                
                $result = _clonegroupsite_updateGroupConfig($groupName, $redirectMode, $redirectTarget, $includeThreeTerminal);
                if ($result) {
                    $response = ['success' => true, 'message' => '配置已保存'];
                } else {
                    $response = ['success' => false, 'message' => '保存失败'];
                }
            }
            break;
    }
    
    echo json_encode($response);
    exit;
}

// 检查登录
if (!checkLogin()) {
    header('Location: login.php');
    exit;
}

// 获取所有分组
$groups = _clonegroupsite_getAllGroups();
$stats = _clonegroupsite_getStats();

// 获取全局统计和批量统计
$globalStats = _clonegroupsite_getGlobalStats();
$allGroupsStats = _clonegroupsite_getAllGroupsStats();

// 页面标题
$pageTitle = '克隆站分组管理';
require_once __DIR__ . '/header.php';
?>

<style>
.group-table-wrapper {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 8px;
    overflow: hidden;
}

.group-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: auto;
}

.group-table thead {
    background: var(--primary);
}

.group-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 500;
    font-size: 14px;
    color: white;
    white-space: nowrap;
}

.group-table th:nth-child(1) { width: 200px; }
.group-table th:nth-child(2) { width: auto; }
.group-table th:nth-child(3) { width: 80px; text-align: center; }
.group-table th:nth-child(4) { width: 100px; text-align: center; }
.group-table th:nth-child(5) { width: 100px; text-align: center; }

.group-table td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
    color: var(--text);
    word-wrap: break-word;
    word-break: break-all;
}

.group-table td:first-child {
    max-width: 200px;
}

.group-table tbody tr {
    transition: background 0.2s;
}

.group-table tbody tr:hover {
    background: var(--bg-hover);
}

.group-table tbody tr:last-of-type td {
    border-bottom: none;
}

.config-row {
    display: none;
    background: var(--bg-dark);
}

.config-row td {
    padding: 20px !important;
    border-bottom: 1px solid var(--border) !important;
}

.config-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.config-section {
    background: var(--bg-card);
    padding: 16px;
    border-radius: 8px;
    border: 1px solid var(--border);
}

.config-section h4 {
    margin: 0 0 12px 0;
    font-size: 14px;
    color: var(--text);
}

.form-select {
    width: 100%;
    padding: 8px 12px;
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text);
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s;
}

.form-select:hover {
    border-color: var(--primary);
}

.form-select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.checkbox-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    background: rgba(245, 158, 11, 0.1);
    border: 1px solid var(--warning);
    border-radius: 6px;
    margin-top: 8px;
    font-size: 13px;
    color: var(--text);
    cursor: pointer;
}

.action-btns {
    display: flex;
    gap: 10px;
    margin-top: 16px;
}

.domain-box {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 6px;
    overflow: hidden;
}

.domain-box-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    background: var(--bg-dark);
    border-bottom: 1px solid var(--border);
}

.domain-box-header h4 {
    margin: 0;
    font-size: 14px;
    color: var(--text);
}

.domain-list {
    padding: 12px 16px;
    max-height: 400px;
    overflow-y: auto;
}

.domain-item {
    padding: 6px 0;
    font-size: 13px;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border);
}

.domain-item:last-child {
    border-bottom: none;
}

.badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
    background: rgba(99, 102, 241, 0.2);
    color: var(--primary-light);
}

.badge-warning {
    background: rgba(245, 158, 11, 0.2);
    color: var(--warning);
}

.badge-info {
    background: rgba(59, 130, 246, 0.2);
    color: #60a5fa;
}

.badge-success {
    background: rgba(34, 197, 94, 0.2);
    color: #4ade80;
}
</style>

<div class="page-header">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1 class="page-title">🌐 克隆站分组管理</h1>
            <p class="page-subtitle">
                总分组: <?php echo $stats['total_groups']; ?> | 
                总域名: <?php echo $stats['total_domains']; ?> | 
                总跳转: <span class="badge badge-info"><?php echo number_format($globalStats['total_redirects']); ?></span> | 
                今日: <span class="badge badge-success"><?php echo number_format($globalStats['today_redirects']); ?></span>
            </p>
        </div>
        <div style="display: flex; gap: 10px;">
            <button class="btn btn-success" onclick="exportCSV()">📥 导出配置</button>
            <button class="btn btn-warning" onclick="document.getElementById('csvFileInput').click()">📤 导入配置</button>
            <button class="btn btn-primary" onclick="cleanData()">🔄 清洗数据</button>
            <input type="file" id="csvFileInput" accept=".csv" style="display: none;" onchange="importCSV(this)">
        </div>
    </div>
</div>

<?php if (empty($groups)): ?>
<div class="card">
    <div class="empty-state">
        <div class="empty-state-icon">📭</div>
        <h3>暂无分组数据</h3>
        <p>请先执行"清洗数据"导入分组信息</p>
        <button class="btn btn-primary" onclick="cleanData()">🔄 开始清洗</button>
    </div>
</div>
<?php else: ?>
<div class="group-table-wrapper">
    <table class="group-table">
        <thead>
            <tr>
                <th>分组名称</th>
                <th>分组标题</th>
                <th>域名数</th>
                <th>重定向</th>
                <th>跳转次数</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($groups as $group): 
                $groupStats = $allGroupsStats[$group['group_name']] ?? ['total' => 0];
            ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($group['group_name']); ?></strong></td>
                <td style="color: var(--text-muted);"><?php echo htmlspecialchars($group['group_title']); ?></td>
                <td style="text-align: center;"><?php echo $group['domain_count']; ?></td>
                <td style="text-align: center;">
                    <span class="badge <?php echo $group['redirect_mode'] === 'centralize' ? 'badge-warning' : ''; ?>">
                        <?php echo _clonegroupsite_getRedirectModeLabel($group['redirect_mode']); ?>
                    </span>
                </td>
                <td style="text-align: center;">
                    <span class="badge badge-info"><?php echo number_format($groupStats['total']); ?></span>
                </td>
                <td style="text-align: center;">
                    <button class="btn btn-sm" onclick="toggleConfig('<?php echo htmlspecialchars($group['group_name']); ?>')">
                        <span id="icon-<?php echo htmlspecialchars($group['group_name']); ?>">▼</span> 详情
                    </button>
                </td>
            </tr>
            <tr class="config-row" id="config-<?php echo htmlspecialchars($group['group_name']); ?>">
                <td colspan="6">
                    <?php 
                    // 获取详细统计（按需加载）
                    $detailStats = _clonegroupsite_getGroupStats($group['group_name']);
                    ?>
                    
                    <!-- 统计卡片 -->
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px;">
                        <div class="config-section" style="text-align: center;">
                            <div style="font-size: 24px; font-weight: bold; color: var(--primary);"><?php echo number_format($detailStats['total']); ?></div>
                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">总跳转</div>
                        </div>
                        <div class="config-section" style="text-align: center;">
                            <div style="font-size: 24px; font-weight: bold; color: var(--success);"><?php echo number_format($detailStats['today']); ?></div>
                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">今日跳转</div>
                        </div>
                        <div class="config-section" style="text-align: center;">
                            <div style="font-size: 24px; font-weight: bold; color: var(--info);"><?php echo number_format($detailStats['week']); ?></div>
                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">本周跳转</div>
                        </div>
                        <div class="config-section" style="text-align: center;">
                            <div style="font-size: 24px; font-weight: bold; color: var(--warning);"><?php echo number_format($detailStats['month']); ?></div>
                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">本月跳转</div>
                        </div>
                    </div>
                    
                    <div class="config-grid">
                        <div>
                            <div class="config-section">
                                <h4>⚙️ 重定向配置</h4>
                                <div style="margin-bottom: 12px;">
                                    <label style="display: block; margin-bottom: 6px; font-size: 13px; color: var(--text-muted);">重定向模式</label>
                                    <select id="mode_<?php echo htmlspecialchars($group['group_name']); ?>" 
                                            class="form-select"
                                            onchange="toggleModeOptions('<?php echo htmlspecialchars($group['group_name']); ?>')">
                                        <option value="random_three" <?php echo $group['redirect_mode'] === 'random_three' ? 'selected' : ''; ?>>随机三端 (@ / www / m)</option>
                                        <option value="fixed_www" <?php echo $group['redirect_mode'] === 'fixed_www' ? 'selected' : ''; ?>>固定www端</option>
                                        <option value="fixed_m" <?php echo $group['redirect_mode'] === 'fixed_m' ? 'selected' : ''; ?>>固定m端</option>
                                        <option value="fixed_top" <?php echo $group['redirect_mode'] === 'fixed_top' ? 'selected' : ''; ?>>固定顶级域名</option>
                                        <option value="custom_subdomain" <?php echo $group['redirect_mode'] === 'custom_subdomain' ? 'selected' : ''; ?>>自定义二级域名</option>
                                        <option value="centralize" <?php echo $group['redirect_mode'] === 'centralize' ? 'selected' : ''; ?>>⭐ 集权模式</option>
                                    </select>
                                </div>
                                
                                <!-- 自定义二级域名选项 -->
                                <div id="custom_options_<?php echo htmlspecialchars($group['group_name']); ?>" 
                                     style="<?php echo $group['redirect_mode'] === 'custom_subdomain' ? '' : 'display: none;'; ?>">
                                    <div style="margin-bottom: 12px;">
                                        <label style="display: block; margin-bottom: 6px; font-size: 13px; color: var(--text-muted);">自定义二级域名</label>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <input type="text" 
                                                   id="target_<?php echo htmlspecialchars($group['group_name']); ?>"
                                                   placeholder="例如: wap"
                                                   value="<?php echo htmlspecialchars($group['redirect_target'] ?? ''); ?>"
                                                   style="flex: 0 0 150px; padding: 8px 12px; background: var(--bg-dark); border: 1px solid var(--border); border-radius: 4px; color: var(--text); font-size: 13px;">
                                            <span style="color: var(--text-muted); font-size: 12px;">.example.com</span>
                                        </div>
                                    </div>
                                    
                                    <label class="checkbox-item">
                                        <input type="checkbox" 
                                               id="include_<?php echo htmlspecialchars($group['group_name']); ?>"
                                               <?php echo $group['include_three_terminal'] ? 'checked' : ''; ?>>
                                        <span>三端也重定向到目标</span>
                                    </label>
                                </div>
                                
                                <!-- 集权模式选项 -->
                                <?php 
                                $centralizeConfig = [];
                                if (!empty($group['centralize_targets'])) {
                                    $centralizeConfig = json_decode($group['centralize_targets'], true);
                                }
                                $centralizeEnabled = !empty($centralizeConfig['enabled']);
                                $centralizeDomains = $centralizeConfig['domains'] ?? [];
                                ?>
                                <div id="centralize_options_<?php echo htmlspecialchars($group['group_name']); ?>" 
                                     style="<?php echo $group['redirect_mode'] === 'centralize' ? '' : 'display: none;'; ?>">
                                    <div style="margin-bottom: 8px;">
                                        <label style="display: block; margin-bottom: 6px; font-size: 13px; color: var(--text-muted);">集权对象域名（多个用换行或逗号分隔）</label>
                                        <textarea 
                                            id="centralize_targets_<?php echo htmlspecialchars($group['group_name']); ?>"
                                            placeholder="例如：&#10;target.com&#10;www.site.com&#10;m.domain.com&#10;@.example.com"
                                            style="width: 100%; min-height: 120px; padding: 8px 12px; background: var(--bg-dark); border: 1px solid var(--border); border-radius: 4px; color: var(--text); font-size: 13px; font-family: monospace; resize: vertical;"><?php echo htmlspecialchars(implode("\n", $centralizeDomains)); ?></textarea>
                                    </div>
                                    <div style="font-size: 12px; color: var(--text-muted); line-height: 1.6;">
                                        💡 <strong>提示：</strong><br>
                                        • 输入顶级域名（如 target.com）将随机跳转到其三端<br>
                                        • 输入指定二级（如 www.target.com）将固定跳转到该二级<br>
                                        • 输入 @.domain.com 将固定跳转到顶级域名<br>
                                        • 多个域名将随机选择一个进行跳转<br>
                                        • 同一顶级域名只能出现一次<br>
                                        • 组内域名会自动排除避免死循环
                                    </div>
                                </div>
                            </div>
                            <div class="action-btns">
                                <button class="btn btn-primary" onclick="saveConfig('<?php echo htmlspecialchars($group['group_name']); ?>')">
                                    💾 保存配置
                                </button>
                                <button class="btn" onclick="toggleConfig('<?php echo htmlspecialchars($group['group_name']); ?>')">
                                    取消
                                </button>
                            </div>
                        </div>
                        <div class="domain-box">
                            <div class="domain-box-header">
                                <h4>📋 域名列表</h4>
                                <span style="font-size: 12px; color: var(--text-muted);"><?php echo $group['domain_count']; ?>个</span>
                            </div>
                            <div class="domain-list">
                                <?php 
                                $domains = _clonegroupsite_getGroupDomains($group['group_name']);
                                foreach ($domains as $domain): 
                                ?>
                                <div class="domain-item"><?php echo htmlspecialchars($domain['domain']); ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
function toggleConfig(groupName) {
    const row = document.getElementById('config-' + groupName);
    const icon = document.getElementById('icon-' + groupName);
    
    if (row.style.display === 'table-row') {
        row.style.display = 'none';
        icon.textContent = '▼';
    } else {
        // 关闭其他展开的配置
        document.querySelectorAll('.config-row').forEach(r => {
            r.style.display = 'none';
        });
        document.querySelectorAll('[id^="icon-"]').forEach(i => {
            i.textContent = '▼';
        });
        
        row.style.display = 'table-row';
        icon.textContent = '▲';
    }
}

function toggleModeOptions(groupName) {
    const select = document.getElementById('mode_' + groupName);
    const customOptions = document.getElementById('custom_options_' + groupName);
    const centralizeOptions = document.getElementById('centralize_options_' + groupName);
    
    // 隐藏所有选项
    customOptions.style.display = 'none';
    centralizeOptions.style.display = 'none';
    
    // 根据选择显示对应选项
    if (select.value === 'custom_subdomain') {
        customOptions.style.display = 'block';
    } else if (select.value === 'centralize') {
        centralizeOptions.style.display = 'block';
    }
}

function cleanData() {
    if (!confirm('确定要清洗数据吗？\n\n这将从JSON文件导入分组和域名信息。')) {
        return;
    }
    
    const btn = event.target;
    btn.disabled = true;
    btn.textContent = '🔄 清洗中...';
    
    fetch('clonegroupsite.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'ajax=1&action=clean_data'
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            location.reload();
        } else {
            btn.disabled = false;
            btn.textContent = '🔄 清洗数据';
        }
    })
    .catch(err => {
        alert('操作失败: ' + err.message);
        btn.disabled = false;
        btn.textContent = '🔄 清洗数据';
    });
}

function saveConfig(groupName) {
    const mode = document.getElementById('mode_' + groupName).value;
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'update_config');
    formData.append('group_name', groupName);
    formData.append('redirect_mode', mode);
    
    // 根据模式添加不同的参数
    if (mode === 'custom_subdomain') {
        const target = document.getElementById('target_' + groupName).value;
        const include = document.getElementById('include_' + groupName).checked;
        
        if (!target.trim()) {
            alert('请输入自定义二级域名');
            return;
        }
        
        formData.append('redirect_target', target);
        if (include) {
            formData.append('include_three_terminal', '1');
        }
    } else if (mode === 'centralize') {
        const centralizeTargets = document.getElementById('centralize_targets_' + groupName).value;
        
        if (!centralizeTargets.trim()) {
            alert('请输入至少一个集权对象域名');
            return;
        }
        
        formData.append('centralize_targets', centralizeTargets);
    } else {
        // 其他模式，清空自定义字段
        formData.append('redirect_target', '');
        formData.append('include_three_terminal', '0');
    }
    
    fetch('clonegroupsite.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            location.reload();
        }
    })
    .catch(err => {
        alert('保存失败: ' + err.message);
    });
}

// 导出CSV配置
function exportCSV() {
    window.location.href = 'clonegroupsite.php?action=export_csv';
}

// 导入CSV配置
function importCSV(input) {
    const file = input.files[0];
    if (!file) {
        return;
    }
    
    // 验证文件类型
    if (!file.name.endsWith('.csv')) {
        alert('请选择CSV文件');
        input.value = '';
        return;
    }
    
    if (!confirm('确定要导入CSV配置吗？\n\n这将更新所有匹配分组的配置信息。')) {
        input.value = '';
        return;
    }
    
    const formData = new FormData();
    formData.append('csv_file', file);
    
    // 显示加载提示
    const loadingMsg = document.createElement('div');
    loadingMsg.style.cssText = 'position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: rgba(0,0,0,0.8); color: white; padding: 20px 40px; border-radius: 8px; z-index: 9999; font-size: 16px;';
    loadingMsg.textContent = '正在导入配置...';
    document.body.appendChild(loadingMsg);
    
    fetch('clonegroupsite.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        document.body.removeChild(loadingMsg);
        
        let message = data.message;
        if (data.errors && data.errors.length > 0) {
            message += '\n\n错误详情：\n' + data.errors.join('\n');
        }
        
        alert(message);
        
        if (data.success) {
            location.reload();
        }
    })
    .catch(err => {
        document.body.removeChild(loadingMsg);
        alert('导入失败: ' + err.message);
    })
    .finally(() => {
        input.value = '';
    });
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
