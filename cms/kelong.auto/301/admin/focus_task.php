<?php
/**
 * 智能集权重定向 - 任务详情页
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/focus_functions.php';

// 处理 AJAX 请求（在任何输出之前）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    // 清除所有输出缓冲
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    
    // 检查登录
    if (!checkLogin()) {
        echo json_encode(['success' => false, 'message' => '登录已过期']);
        exit;
    }
    
    // 获取任务ID
    $taskId = $_GET['id'] ?? '';
    if (empty($taskId)) {
        echo json_encode(['success' => false, 'message' => '任务ID无效']);
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => '未知操作'];
    
    try {
        switch ($action) {
            case 'get_data_types':
                $result = _focus_getDataTypes();
                if ($result['success']) {
                    $response = ['success' => true, 'data_types' => $result['data_types']];
                } else {
                    $response = $result;
                }
                break;
                
            case 'get_groups':
                $result = _focus_getGroups();
                if ($result['success']) {
                    $response = ['success' => true, 'groups' => $result['groups']];
                } else {
                    $response = $result;
                }
                break;
                
            case 'get_domains_for_exclusion':
                $sourceType = $_POST['source_type'] ?? '';
                $sourceValue = $_POST['source_value'] ?? '';
                $result = _focus_getDomainsForExclusion($sourceType, $sourceValue);
                $response = $result;
                break;
                
            case 'save_exclusion':
                $excludeDomains = isset($_POST['exclude_domains']) ? json_decode($_POST['exclude_domains'], true) : [];
                $excludeGroups = isset($_POST['exclude_groups']) ? json_decode($_POST['exclude_groups'], true) : [];
                $excludeTypes = isset($_POST['exclude_types']) ? json_decode($_POST['exclude_types'], true) : [];
                
                $db = _focus_getDB();
                $stmt = $db->prepare("
                    UPDATE focus_tasks 
                    SET exclude_domains = ?, exclude_groups = ?, exclude_types = ?
                    WHERE id = ?
                ");
                $stmt->bindValue(1, json_encode($excludeDomains), SQLITE3_TEXT);
                $stmt->bindValue(2, json_encode($excludeGroups), SQLITE3_TEXT);
                $stmt->bindValue(3, json_encode($excludeTypes), SQLITE3_TEXT);
                $stmt->bindValue(4, $taskId, SQLITE3_TEXT);
                $stmt->execute();
                
                $response = ['success' => true, 'message' => '排除项设置已保存'];
                break;
                
            case 'extract_links':
                $sourceType = $_POST['source_type'] ?? '';
                $sourceValue = $_POST['source_value'] ?? '';
                $keywords = $_POST['keywords'] ?? '';
                $matchFromData = ($_POST['match_from_data'] ?? '0') === '1';
                $matchFromInput = ($_POST['match_from_input'] ?? '0') === '1';
                $includeTopLevel = ($_POST['include_top_level'] ?? '0') === '1';
                
                // 保存排除项到任务配置
                $db = _focus_getDB();
                
                // 解析排除项（前端发送的是 JSON 字符串）
                $excludeDomains = isset($_POST['exclude_domains']) ? json_decode($_POST['exclude_domains'], true) : [];
                $excludeGroups = isset($_POST['exclude_groups']) ? json_decode($_POST['exclude_groups'], true) : [];
                $excludeTypes = isset($_POST['exclude_types']) ? json_decode($_POST['exclude_types'], true) : [];
                
                // 确保是数组类型
                if (!is_array($excludeDomains)) $excludeDomains = [];
                if (!is_array($excludeGroups)) $excludeGroups = [];
                if (!is_array($excludeTypes)) $excludeTypes = [];
                
                // 过滤掉无效的域名
                $excludeDomains = array_values(array_filter($excludeDomains, function($domain) {
                    $trimmed = trim($domain);
                    return !empty($trimmed) && $trimmed !== '[]' && $trimmed !== 'null' && $trimmed !== 'undefined';
                }));
                
                $stmt = $db->prepare("
                    UPDATE focus_tasks 
                    SET exclude_domains = ?, exclude_groups = ?, exclude_types = ?
                    WHERE id = ?
                ");
                $stmt->bindValue(1, json_encode($excludeDomains), SQLITE3_TEXT);
                $stmt->bindValue(2, json_encode($excludeGroups), SQLITE3_TEXT);
                $stmt->bindValue(3, json_encode($excludeTypes), SQLITE3_TEXT);
                $stmt->bindValue(4, $taskId, SQLITE3_TEXT);
                $stmt->execute();
                
                $result = _focus_extractLinks($taskId, $sourceType, $sourceValue, $keywords, $matchFromData, $matchFromInput, $includeTopLevel);
                $response = $result;
                break;
                
            case 'get_extracted_links':
                $page = intval($_POST['page'] ?? 1);
                $pageSize = intval($_POST['page_size'] ?? 50);
                
                $result = _focus_getExtractedLinks($taskId, $page, $pageSize);
                $response = $result;
                break;
                
            case 'delete_extracted_link':
                $url = $_POST['url'] ?? '';
                $result = _focus_deleteExtractedLink($taskId, $url);
                $response = $result;
                break;
                
            case 'get_locked_links':
                $page = intval($_POST['page'] ?? 1);
                $pageSize = intval($_POST['page_size'] ?? 50);
                
                $result = _focus_getLockedLinks($taskId, $page, $pageSize);
                $response = $result;
                break;
                
            case 'export_extracted_links':
                $result = _focus_exportExtractedLinks($taskId);
                if ($result['success']) {
                    // 清除之前的所有输出
                    while (ob_get_level()) {
                        ob_end_clean();
                    }
                    
                    header('Content-Type: text/csv; charset=utf-8');
                    header('Content-Disposition: attachment; filename="focus_links_' . $taskId . '.csv"');
                    echo "\xEF\xBB\xBF"; // UTF-8 BOM
                    echo $result['csv'];
                    exit;
                } else {
                    $response = $result;
                }
                break;
                
            case 'save_config':
                $data = [
                    'target_urls' => $_POST['target_urls'] ?? '',
                    'redirect_type' => $_POST['redirect_type'] ?? '301',
                    'probability' => intval($_POST['probability'] ?? 100),
                    'schedule_days' => intval($_POST['schedule_days'] ?? 0),
                    'schedule_hours' => intval($_POST['schedule_hours'] ?? 0),
                    'schedule_minutes' => intval($_POST['schedule_minutes'] ?? 0),
                ];
                
                $result = _focus_updateTaskConfig($taskId, $data);
                $response = $result;
                break;
                
            case 'toggle_task':
                $enabled = intval($_POST['enabled'] ?? 0);
                $result = _focus_toggleTask($taskId, $enabled);
                $response = $result;
                break;
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => '操作失败: ' . $e->getMessage()];
    }
    
    echo json_encode($response);
    exit;
}

// 检查登录
if (!checkLogin()) {
    header('Location: login.php');
    exit;
}

// 获取任务ID
$taskId = $_GET['id'] ?? '';
if (empty($taskId)) {
    header('Location: focus.php');
    exit;
}

// 获取任务信息
$task = _focus_getTaskDetail($taskId);
if (!$task['success']) {
    header('Location: focus.php');
    exit;
}

$taskData = $task['task'];
$stats = $task['stats'] ?? [];
$task = $taskData; // 保持向后兼容

// 合并统计数据
$task['total_redirects'] = $stats['total_redirects'] ?? $task['total_redirects'] ?? 0;
$task['last_redirect_at'] = $stats['last_redirect_at'] ?? $task['last_redirect_at'] ?? null;

$pageTitle = htmlspecialchars($task['name']) . ' - 任务配置';

require_once __DIR__ . '/header.php';
?>

<div class="page-header-bar">
    <div>
        <a href="focus.php" class="back-link">← 返回任务列表</a>
    </div>
    <div class="header-actions">
        <?php if ($task['enabled']): ?>
            <span class="status-badge status-running">🟢 运行中</span>
            <button class="btn btn-warning btn-sm" onclick="toggleTask(0)">⏸️ 停止任务</button>
        <?php else: ?>
            <span class="status-badge status-stopped">🔴 已停止</span>
            <button class="btn btn-success btn-sm" onclick="toggleTask(1)">▶️ 启动任务</button>
        <?php endif; ?>
    </div>
</div>

<div class="page-header">
    <div>
        <h1 class="page-title"><?php echo htmlspecialchars($task['name']); ?></h1>
        <p class="page-subtitle">
            创建于 <?php echo $task['created_at']; ?> · 
            已跳转 <?php echo intval($task['total_redirects']); ?> 次 ·
            锁定 <?php echo intval($task['locked_urls_count']); ?> 个URL
        </p>
    </div>
</div>

<div class="section-grid">
    <!-- 左侧：数据源配置 -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📊 数据源配置</h3>
        </div>
        
        <form id="dataSourceForm">
            <div class="form-group">
                <label class="form-label">数据来源方式</label>
                <div class="source-type-buttons">
                    <button type="button" class="source-btn active" data-type="domain" onclick="selectSourceType('domain')">
                        🌐 输入域名列表
                    </button>
                    <button type="button" class="source-btn" data-type="group" onclick="selectSourceType('group')">
                        📁 从分组导入
                    </button>
                    <button type="button" class="source-btn" data-type="data_type" onclick="selectSourceType('data_type')">
                        📋 从分类导入
                    </button>
                </div>
            </div>
            
            <!-- 域名和关键词并列 -->
            <div class="form-row-2col">
                <!-- 域名输入 -->
                <div class="form-group source-input" id="domainInput">
                    <label class="form-label">域名列表（每行一个）</label>
                    <textarea class="form-input" name="domain_list" rows="5" placeholder="example1.com&#10;example2.com&#10;example3.com"></textarea>
                </div>
                
                <!-- 分组选择 -->
                <div class="form-group source-input" id="groupSelect" style="display: none;">
                    <label class="form-label">选择分组</label>
                    <select class="form-select" name="group_value">
                        <option value="">加载中...</option>
                    </select>
                </div>
                
                <!-- 分类选择 -->
                <div class="form-group source-input" id="dataTypeSelect" style="display: none;">
                    <label class="form-label">选择数据类型</label>
                    <select class="form-select" name="data_type_value">
                        <option value="">加载中...</option>
                    </select>
                </div>
                
                <!-- 关键词配置 -->
                <div class="form-group">
                    <label class="form-label">关键词（可选，每行一个）</label>
                    <textarea class="form-input" name="keywords" rows="5" placeholder="关键词1&#10;关键词2&#10;关键词3"></textarea>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">匹配选项</label>
                <div class="checkbox-group" style="display: flex; flex-direction: row; gap: 24px; flex-wrap: wrap;">
                    <label class="checkbox-label">
                        <input type="checkbox" name="match_from_data" checked>
                        <span>提取域名关键词</span>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="match_from_input" checked>
                        <span>词根提取关键词</span>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="include_top_level">
                        <span>三端是否参与跳转</span>
                    </label>
                </div>
            </div>
            
            <div style="display: flex; gap: 12px;">
                <button type="button" class="btn btn-primary" style="flex: 1;" onclick="extractLinks()">
                    🔍 提取对应链接
                </button>
                <button type="button" class="btn btn-secondary" onclick="openExclusionModal()">
                    🚫 设置排除项
                </button>
            </div>
        </form>
        
        <!-- 链接列表 -->
        <div class="links-section" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border);">
            <div class="links-header">
                <h4 class="section-subtitle">匹配的链接列表</h4>
                <div class="links-actions">
                    <button class="btn btn-secondary btn-sm" onclick="toggleLinksTable()">
                        <span id="toggleIcon">▼</span> <span id="toggleText">收起</span>
                    </button>
                    <button class="btn btn-primary btn-sm" onclick="exportLinks()">📤 导出CSV</button>
                </div>
            </div>
            
            <!-- 标签页切换 -->
            <div class="links-tabs" style="margin: 12px 0;">
                <button class="tab-btn active" id="availableTab" onclick="switchTab('available')">
                    可用链接 (<span id="availableCount">0</span>)
                </button>
                <button class="tab-btn" id="lockedTab" onclick="switchTab('locked')" style="display: none;">
                    已锁定 (<span id="lockedCount">0</span>)
                </button>
            </div>
            
            <div class="links-stats" style="margin: 12px 0;">
                <span id="currentTabInfo">当前显示：可用链接</span>
            </div>
            
            <div class="links-table-wrapper" id="linksTableWrapper">
                <table class="links-table">
                    <thead id="linksTableHead">
                        <tr>
                            <th>URL</th>
                            <th style="width: 120px;">关键词</th>
                            <th style="width: 100px;">跳转次数</th>
                            <th style="width: 80px;">操作</th>
                        </tr>
                    </thead>
                    <tbody id="linksTableBody">
                        <tr>
                            <td colspan="4" class="empty-state">点击"提取对应链接"开始</td>
                        </tr>
                    </tbody>
                </table>
                
                <!-- 已锁定链接提示 -->
                <div id="lockedTip" style="display: none; margin-top: 12px; padding: 12px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; color: #856404;">
                    💡 提示：这些链接已被其他任务锁定，无法在当前任务中使用
                </div>
            </div>
            
            <!-- 分页 -->
            <div class="pagination" id="linksPagination" style="display: none;"></div>
        </div>
    </div>
    
    <!-- 右侧：目标和规则配置 -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🎯 集权目标配置</h3>
        </div>
        
        <form id="configForm">
            <div class="form-group">
                <label class="form-label">目标URL列表（每行一个，程序随机选择）</label>
                <textarea class="form-input" name="target_urls" rows="16" 
                          placeholder="https://target1.com/page1&#10;https://target2.com/page2&#10;https://target3.com/page3"
                          oninput="updateTargetCount()"><?php echo htmlspecialchars($task['target_urls'] ?? ''); ?></textarea>
                <div class="form-hint">
                    已添加 <strong id="targetCount">0</strong> 个目标URL
                </div>
            </div>
            
            <!-- 跳转规则 -->
            <div class="form-group" style="margin-top: 24px; padding-top: 24px; border-top: 1px solid var(--border);">
                <h4 class="section-subtitle">⚙️ 跳转规则配置</h4>
                
                <div class="form-row-3col">
                    <div class="form-group">
                        <label class="form-label">跳转类型</label>
                        <select class="form-select" name="redirect_type">
                            <option value="301" <?php echo ($task['redirect_type'] ?? 301) == 301 ? 'selected' : ''; ?>>301 永久重定向</option>
                            <option value="302" <?php echo ($task['redirect_type'] ?? 301) == 302 ? 'selected' : ''; ?>>302 临时重定向</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">跳转概率 (%)</label>
                        <input type="number" class="form-input" name="probability" 
                               min="1" max="100" value="<?php echo $task['probability'] ?? 100; ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">定时跳转间隔</label>
                        <div class="schedule-input-group">
                            <input type="number" class="form-input" id="scheduleValue" 
                                   min="0" value="0" placeholder="0" onchange="updateScheduleFields()">
                            <select class="form-select" id="scheduleUnit" onchange="updateScheduleFields()">
                                <option value="days">天</option>
                                <option value="hours">小时</option>
                                <option value="minutes">分钟</option>
                            </select>
                        </div>
                        <input type="hidden" name="schedule_days" id="schedule_days" value="<?php echo $task['schedule_days'] ?? 0; ?>">
                        <input type="hidden" name="schedule_hours" id="schedule_hours" value="<?php echo $task['schedule_hours'] ?? 0; ?>">
                        <input type="hidden" name="schedule_minutes" id="schedule_minutes" value="<?php echo $task['schedule_minutes'] ?? 0; ?>">
                    </div>
                </div>
                <p class="form-hint">设为0表示不限制</p>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 20px;">
                💾 保存配置
            </button>
        </form>
    </div>
</div>

<!-- 排除项设置悬浮窗口 -->
<div class="modal-overlay" id="exclusionModal" style="display: none;" onclick="closeExclusionModal(event)">
    <div class="modal-container" onclick="event.stopPropagation()">
        <!-- 头部 -->
        <div class="modal-header">
            <h3>🚫 排除项设置</h3>
            <div class="modal-actions">
                <button class="btn btn-primary btn-sm" onclick="saveExclusion()">💾 保存</button>
                <button class="btn btn-secondary btn-sm" onclick="closeExclusionModal()">取消</button>
                <button class="btn-close" onclick="closeExclusionModal()">×</button>
            </div>
        </div>
        
        <!-- 内容区域：三列布局 -->
        <div class="modal-body">
            <div class="exclusion-grid">
                <!-- 第一列：排除域名 -->
                <div class="exclusion-column">
                    <div class="column-header">
                        <h4>排除域名</h4>
                    </div>
                    <div class="domain-input-area">
                        <textarea class="form-input" id="excludeDomainsInput" rows="20" placeholder="每行一个域名&#10;&#10;示例：&#10;example.com&#10;test.com&#10;&#10;说明：输入域名后会自动排除该域名及其所有子域名"></textarea>
                    </div>
                    <div class="column-footer">
                        <span class="hint-text" style="font-size: 12px; color: var(--text-secondary);">自动排除该域名及其所有子域名</span>
                    </div>
                </div>
                
                <!-- 第二列：排除小组 -->
                <div class="exclusion-column">
                    <div class="column-header">
                        <h4>排除小组</h4>
                    </div>
                    <div class="search-box">
                        <input type="text" class="form-input" id="searchGroups" placeholder="🔍 搜索小组..." onkeyup="filterExclusionList('groups')">
                    </div>
                    <div class="checkbox-list" id="groupsList">
                        <div class="loading-text">加载中...</div>
                    </div>
                    <div class="column-footer">
                        <span class="selected-count">已选: <span id="groupsCount">0</span> 项</span>
                    </div>
                </div>
                
                <!-- 第三列：排除分类 -->
                <div class="exclusion-column">
                    <div class="column-header">
                        <h4>排除分类</h4>
                    </div>
                    <div class="search-box">
                        <input type="text" class="form-input" id="searchTypes" placeholder="🔍 搜索分类..." onkeyup="filterExclusionList('types')">
                    </div>
                    <div class="checkbox-list" id="typesList">
                        <div class="loading-text">加载中...</div>
                    </div>
                    <div class="column-footer">
                        <span class="selected-count">已选: <span id="typesCount">0</span> 项</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 底部提示 -->
        <div class="modal-footer">
            <div class="hint-text">
                💡 提示：排除条件为"或"关系（满足任一条件即排除）| 域名自动排除所有子域名 | 搜索支持模糊匹配
            </div>
        </div>
    </div>
</div>

<script>
const taskId = '<?php echo $taskId; ?>';
let currentPage = 1;
let linksExpanded = true;

// 统一的fetch JSON处理函数
function fetchJSON(url, options) {
    return fetch(url, options)
        .then(res => res.text())
        .then(text => {
            // 尝试提取JSON部分（去除PHP警告）
            let jsonText = text;
            const jsonStart = text.indexOf('{');
            const jsonArrayStart = text.indexOf('[');
            
            // 找到第一个JSON起始位置
            let firstJsonPos = -1;
            if (jsonStart >= 0 && jsonArrayStart >= 0) {
                firstJsonPos = Math.min(jsonStart, jsonArrayStart);
            } else if (jsonStart >= 0) {
                firstJsonPos = jsonStart;
            } else if (jsonArrayStart >= 0) {
                firstJsonPos = jsonArrayStart;
            }
            
            if (firstJsonPos > 0) {
                console.warn('检测到PHP输出，已自动过滤');
                jsonText = text.substring(firstJsonPos);
            }
            
            try {
                return JSON.parse(jsonText);
            } catch (e) {
                console.error('JSON解析失败，原始响应:', text);
                throw new Error('服务器返回了非JSON格式的数据: ' + text.substring(0, 200));
            }
        });
}

// 切换数据来源
function selectSourceType(type) {
    // 更新按钮状态
    document.querySelectorAll('.source-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.type === type);
    });
    
    // 显示对应输入
    document.getElementById('domainInput').style.display = type === 'domain' ? 'block' : 'none';
    document.getElementById('groupSelect').style.display = type === 'group' ? 'block' : 'none';
    document.getElementById('dataTypeSelect').style.display = type === 'data_type' ? 'block' : 'none';
    
    // 加载选项
    if (type === 'group') {
        loadGroups();
    } else if (type === 'data_type') {
        loadDataTypes();
    }
}

// 加载分组
function loadGroups() {
    fetchJSON('focus_task.php?id=' + taskId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'ajax=1&action=get_groups'
    })
    .then(data => {
        if (data.success) {
            const select = document.querySelector('[name="group_value"]');
            select.innerHTML = '<option value="">请选择分组</option>' + 
                data.groups.map(g => `<option value="${g}">${g}</option>`).join('');
        }
    })
    .catch(err => console.error('加载分组错误:', err));
}

// 加载数据类型
function loadDataTypes() {
    fetchJSON('focus_task.php?id=' + taskId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'ajax=1&action=get_data_types'
    })
    .then(data => {
        if (data.success) {
            const select = document.querySelector('[name="data_type_value"]');
            select.innerHTML = '<option value="">请选择数据类型</option>' + 
                data.data_types.map(t => `<option value="${t}">${t}</option>`).join('');
        }
    })
    .catch(err => console.error('加载数据类型错误:', err));
}

// 切换排除项区域显示/隐藏
// ==================== 排除项悬浮窗口功能 ====================

// 全局变量：存储所有数据和选中状态
let exclusionData = {
    domains: [],      // 所有域名
    groups: [],       // 所有小组
    types: [],        // 所有分类
    selected: {
        domains: [],  // 选中的域名
        groups: [],   // 选中的小组
        types: []     // 选中的分类
    }
};

// 打开排除项窗口
function openExclusionModal() {
    document.getElementById('exclusionModal').style.display = 'flex';
    document.body.style.overflow = 'hidden'; // 禁止背景滚动
    
    // 加载数据
    loadExclusionData();
}

// 关闭排除项窗口
function closeExclusionModal(event) {
    // 如果是点击遮罩层，检查是否点击在窗口外部
    if (event && event.target.classList.contains('modal-container')) {
        return;
    }
    
    document.getElementById('exclusionModal').style.display = 'none';
    document.body.style.overflow = ''; // 恢复背景滚动
}

// 加载所有排除项数据
function loadExclusionData() {
    // 加载域名输入框（恢复已保存的域名）
    const textarea = document.getElementById('excludeDomainsInput');
    if (exclusionData.selected.domains && exclusionData.selected.domains.length > 0) {
        textarea.value = exclusionData.selected.domains.join('\n');
    }
    
    // 加载小组列表
    loadModalGroups();
    // 加载分类列表
    loadModalTypes();
}

// 加载小组列表
function loadModalGroups() {
    const container = document.getElementById('groupsList');
    container.innerHTML = '<div class="loading-text">加载中...</div>';
    
    fetchJSON('focus_task.php?id=' + taskId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'ajax=1&action=get_groups'
    })
    .then(data => {
        if (data.success && data.groups && data.groups.length > 0) {
            exclusionData.groups = data.groups;
            renderGroupsList();
        } else {
            container.innerHTML = '<div class="empty-text">暂无小组数据</div>';
        }
    })
    .catch(err => {
        console.error('加载小组错误:', err);
        container.innerHTML = '<div class="empty-text">加载失败</div>';
    });
}

// 加载分类列表
function loadModalTypes() {
    const container = document.getElementById('typesList');
    container.innerHTML = '<div class="loading-text">加载中...</div>';
    
    fetchJSON('focus_task.php?id=' + taskId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'ajax=1&action=get_data_types'
    })
    .then(data => {
        if (data.success && data.data_types && data.data_types.length > 0) {
            exclusionData.types = data.data_types;
            renderTypesList();
        } else {
            container.innerHTML = '<div class="empty-text">暂无分类数据</div>';
        }
    })
    .catch(err => {
        console.error('加载分类错误:', err);
        container.innerHTML = '<div class="empty-text">加载失败</div>';
    });
}

// 渲染小组列表
function renderGroupsList() {
    const container = document.getElementById('groupsList');
    const html = exclusionData.groups.map(group => {
        const checked = exclusionData.selected.groups.includes(group) ? 'checked' : '';
        const safeId = 'group_' + group.replace(/[^a-zA-Z0-9]/g, '_');
        return `
            <div class="checkbox-item" data-value="${group}">
                <input type="checkbox" id="${safeId}" ${checked} onchange="toggleSelection('groups', '${group}')">
                <label for="${safeId}">${group}</label>
            </div>
        `;
    }).join('');
    container.innerHTML = html;
    updateCount('groups');
}

// 渲染分类列表
function renderTypesList() {
    const container = document.getElementById('typesList');
    const html = exclusionData.types.map(type => {
        const checked = exclusionData.selected.types.includes(type) ? 'checked' : '';
        const safeId = 'type_' + type.replace(/[^a-zA-Z0-9]/g, '_');
        return `
            <div class="checkbox-item" data-value="${type}">
                <input type="checkbox" id="${safeId}" ${checked} onchange="toggleSelection('types', '${type}')">
                <label for="${safeId}">${type}</label>
            </div>
        `;
    }).join('');
    container.innerHTML = html;
    updateCount('types');
}

// 切换选中状态
function toggleSelection(type, value) {
    const index = exclusionData.selected[type].indexOf(value);
    if (index > -1) {
        exclusionData.selected[type].splice(index, 1);
    } else {
        exclusionData.selected[type].push(value);
    }
    updateCount(type);
}

// 更新选中计数
function updateCount(type) {
    const count = exclusionData.selected[type].length;
    document.getElementById(type + 'Count').textContent = count;
}

// 包含搜索（模糊匹配）
function filterExclusionList(type) {
    const searchInput = document.getElementById('search' + type.charAt(0).toUpperCase() + type.slice(1));
    const searchTerm = searchInput.value.toLowerCase();
    const container = document.getElementById(type + 'List');
    const items = container.querySelectorAll('.checkbox-item');
    
    items.forEach(item => {
        const value = item.getAttribute('data-value').toLowerCase();
        if (value.includes(searchTerm)) {
            item.classList.remove('hidden');
        } else {
            item.classList.add('hidden');
        }
    });
}

// 保存排除项设置
function saveExclusion() {
    // 从输入框获取域名列表
    const domainsText = document.getElementById('excludeDomainsInput').value;
    const domains = domainsText.split('\n')
        .map(d => d.trim())
        .filter(d => d.length > 0);
    
    // 更新全局数据
    exclusionData.selected.domains = domains;
    
    fetchJSON('focus_task.php?id=' + taskId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            ajax: '1',
            action: 'save_exclusion',
            exclude_domains: JSON.stringify(domains),
            exclude_groups: JSON.stringify(exclusionData.selected.groups),
            exclude_types: JSON.stringify(exclusionData.selected.types)
        })
    })
    .then(data => {
        if (data.success) {
            alert('排除项设置已保存');
            closeExclusionModal();
        } else {
            alert('保存失败: ' + data.message);
        }
    })
    .catch(err => {
        console.error('保存排除项错误:', err);
        alert('保存失败');
    });
}

// 初始化排除项设置（加载已保存的配置）
function initExclusionSettings() {
    <?php
    // 解析排除项数据，确保返回数组而不是 null
    $excludeDomains = [];
    $excludeGroups = [];
    $excludeTypes = [];
    
    if (!empty($task['exclude_domains'])) {
        $decoded = json_decode($task['exclude_domains'], true);
        if (is_array($decoded)) {
            $excludeDomains = $decoded;
        }
    }
    
    if (!empty($task['exclude_groups'])) {
        $decoded = json_decode($task['exclude_groups'], true);
        if (is_array($decoded)) {
            $excludeGroups = $decoded;
        }
    }
    
    if (!empty($task['exclude_types'])) {
        $decoded = json_decode($task['exclude_types'], true);
        if (is_array($decoded)) {
            $excludeTypes = $decoded;
        }
    }
    ?>
    
    exclusionData.selected.domains = <?php echo json_encode($excludeDomains); ?>;
    exclusionData.selected.groups = <?php echo json_encode($excludeGroups); ?>;
    exclusionData.selected.types = <?php echo json_encode($excludeTypes); ?>;
}

// 提取链接
function extractLinks() {
    const form = document.getElementById('dataSourceForm');
    const formData = new FormData(form);
    
    // 获取当前选中的数据来源类型
    const activeBtn = document.querySelector('.source-btn.active');
    const sourceType = activeBtn ? activeBtn.dataset.type : 'domain';
    
    let sourceValue = '';
    if (sourceType === 'domain') {
        sourceValue = formData.get('domain_list');
    } else if (sourceType === 'group') {
        sourceValue = formData.get('group_value');
    } else if (sourceType === 'data_type') {
        sourceValue = formData.get('data_type_value');
    }
    
    if (!sourceValue) {
        alert('请输入或选择数据来源');
        return;
    }
    
    // 获取排除项数据（从全局变量）
    const body = new URLSearchParams({
        ajax: '1',
        action: 'extract_links',
        source_type: sourceType,
        source_value: sourceValue,
        keywords: formData.get('keywords') || '',
        match_from_data: formData.get('match_from_data') ? '1' : '0',
        match_from_input: formData.get('match_from_input') ? '1' : '0',
        include_top_level: formData.get('include_top_level') ? '1' : '0',
        exclude_domains: JSON.stringify(exclusionData.selected.domains),
        exclude_groups: JSON.stringify(exclusionData.selected.groups),
        exclude_types: JSON.stringify(exclusionData.selected.types)
    });
    
    fetchJSON('focus_task.php?id=' + taskId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body
    })
    .then(data => {
        if (data.success) {
            // 保存数据到全局变量
            window.availableLinksData = data.available_links || [];
            window.lockedLinksData = data.locked_links || [];
            
            // 更新标签页计数
            document.getElementById('availableCount').textContent = data.available_count || 0;
            document.getElementById('lockedCount').textContent = data.locked_count || 0;
            
            // 显示/隐藏已锁定标签
            const lockedTab = document.getElementById('lockedTab');
            if (data.locked_count > 0) {
                lockedTab.style.display = 'inline-block';
            } else {
                lockedTab.style.display = 'none';
            }
            
            // 默认显示可用链接
            switchTab('available');
            
            // 提示信息
            let message = `提取成功！`;
            if (data.available_count > 0 && data.locked_count > 0) {
                message += `\n可用链接：${data.available_count} 条\n已锁定：${data.locked_count} 条`;
            } else if (data.available_count > 0) {
                message += `共提取 ${data.available_count} 条可用链接`;
            } else if (data.locked_count > 0) {
                message += `\n所有匹配的链接（${data.locked_count} 条）都已被其他任务锁定`;
            } else {
                message += `未找到匹配的链接`;
            }
            alert(message);
        } else {
            alert('提取失败: ' + data.message);
        }
    })
    .catch(err => {
        console.error('提取链接错误:', err);
        alert('提取失败: ' + err.message);
    });
}

// 加载提取的链接
function loadExtractedLinks(page = 1) {
    currentPage = page;
    
    const body = new URLSearchParams({
        ajax: '1',
        action: 'get_extracted_links',
        page: page,
        page_size: 50
    });
    
    fetchJSON('focus_task.php?id=' + taskId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body
    })
    .then(data => {
        if (data.success) {
            renderLinksTable(data.links);
            renderPagination(data.total, data.page, data.page_size);
            // 更新可用链接计数
            document.getElementById('availableCount').textContent = data.total;
        }
    })
    .catch(err => {
        console.error('加载链接列表错误:', err);
    });
}

// 全局变量：当前标签页
let currentTab = 'available';

// 切换标签页
function switchTab(tab) {
    currentTab = tab;
    
    // 更新标签样式
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    document.getElementById(tab + 'Tab').classList.add('active');
    
    // 更新表格头部
    const thead = document.getElementById('linksTableHead');
    if (tab === 'available') {
        thead.innerHTML = `
            <tr>
                <th>URL</th>
                <th style="width: 120px;">关键词</th>
                <th style="width: 100px;">跳转次数</th>
                <th style="width: 80px;">操作</th>
            </tr>
        `;
        document.getElementById('currentTabInfo').textContent = '当前显示：可用链接';
        document.getElementById('lockedTip').style.display = 'none';
    } else {
        thead.innerHTML = `
            <tr>
                <th>URL</th>
                <th style="width: 120px;">关键词</th>
                <th style="width: 150px;">锁定任务</th>
                <th style="width: 150px;">锁定时间</th>
            </tr>
        `;
        document.getElementById('currentTabInfo').textContent = '当前显示：已锁定链接';
        document.getElementById('lockedTip').style.display = 'block';
    }
    
    // 渲染对应的列表
    if (tab === 'available') {
        renderAvailableLinks();
        loadExtractedLinks(1);  // 加载更多数据
    } else {
        renderLockedLinks();
        loadLockedLinks(1);  // 加载更多数据
    }
}

// 渲染可用链接
function renderAvailableLinks() {
    const tbody = document.getElementById('linksTableBody');
    const links = window.availableLinksData || [];
    
    if (links.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="empty-state">暂无可用链接</td></tr>';
        return;
    }
    
    tbody.innerHTML = links.map(link => `
        <tr>
            <td>
                <div class="link-url" title="${link.url}">${link.url}</div>
            </td>
            <td>${link.keyword || '-'}</td>
            <td>${link.redirect_count || 0}</td>
            <td>
                <button class="btn btn-danger btn-sm" onclick="deleteExtractedLink('${link.url.replace(/'/g, "\\'")}')">🗑️</button>
            </td>
        </tr>
    `).join('');
}

// 渲染已锁定链接
function renderLockedLinks() {
    const tbody = document.getElementById('linksTableBody');
    const links = window.lockedLinksData || [];
    
    if (links.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="empty-state">没有已锁定的链接</td></tr>';
        return;
    }
    
    tbody.innerHTML = links.map(link => `
        <tr>
            <td>
                <div class="link-url" title="${link.url}">${link.url}</div>
            </td>
            <td>${link.keyword || '-'}</td>
            <td>
                <span class="badge badge-warning">${link.locked_by_task_name || '未知任务'}</span>
            </td>
            <td>${formatDate(link.locked_at)}</td>
        </tr>
    `).join('');
}

// 加载已锁定链接（分页）
function loadLockedLinks(page = 1) {
    const body = new URLSearchParams({
        ajax: '1',
        action: 'get_locked_links',
        page: page,
        page_size: 50
    });
    
    fetchJSON('focus_task.php?id=' + taskId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body
    })
    .then(data => {
        if (data.success) {
            window.lockedLinksData = data.links || [];
            renderLockedLinks();
            // 可以添加分页逻辑
        }
    })
    .catch(err => {
        console.error('加载已锁定链接错误:', err);
    });
}

// 格式化日期
function formatDate(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleString('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// 渲染链接表格（向后兼容）
function renderLinksTable(links) {
    const tbody = document.getElementById('linksTableBody');
    
    if (links.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="empty-state">暂无链接</td></tr>';
        return;
    }
    
    tbody.innerHTML = links.map(link => `
        <tr>
            <td>
                <div class="link-url" title="${link.url}">${link.url}</div>
            </td>
            <td>${link.keyword || '-'}</td>
            <td>${link.redirect_count || 0}</td>
            <td>
                <button class="btn btn-danger btn-sm" onclick="deleteExtractedLink('${link.url.replace(/'/g, "\\'")}')">🗑️</button>
            </td>
        </tr>
    `).join('');
}

// 渲染分页
function renderPagination(total, page, pageSize) {
    const totalPages = Math.ceil(total / pageSize);
    const pagination = document.getElementById('linksPagination');
    
    if (totalPages <= 1) {
        pagination.style.display = 'none';
        return;
    }
    
    pagination.style.display = 'flex';
    
    let html = '';
    if (page > 1) {
        html += `<button class="btn btn-secondary btn-sm" onclick="loadExtractedLinks(${page - 1})">上一页</button>`;
    }
    html += `<span style="margin: 0 12px;">第 ${page} / ${totalPages} 页</span>`;
    if (page < totalPages) {
        html += `<button class="btn btn-secondary btn-sm" onclick="loadExtractedLinks(${page + 1})">下一页</button>`;
    }
    
    pagination.innerHTML = html;
}

// 删除链接
function deleteExtractedLink(url) {
    if (!confirm('确定要删除这条链接吗？')) return;
    
    const body = new URLSearchParams({
        ajax: '1',
        action: 'delete_extracted_link',
        url: url
    });
    
    fetchJSON('focus_task.php?id=' + taskId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body
    })
    .then(data => {
        if (data.success) {
            loadExtractedLinks(currentPage);
        } else {
            alert('删除失败: ' + data.message);
        }
    })
    .catch(err => console.error('删除链接错误:', err));
}

// 导出链接
function exportLinks() {
    // 创建隐藏表单提交POST请求
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'focus_task.php?id=' + taskId;
    
    const ajaxInput = document.createElement('input');
    ajaxInput.type = 'hidden';
    ajaxInput.name = 'ajax';
    ajaxInput.value = '1';
    form.appendChild(ajaxInput);
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'export_extracted_links';
    form.appendChild(actionInput);
    
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

// 切换链接列表显示
function toggleLinksTable() {
    linksExpanded = !linksExpanded;
    const wrapper = document.getElementById('linksTableWrapper');
    const icon = document.getElementById('toggleIcon');
    const text = document.getElementById('toggleText');
    
    wrapper.style.display = linksExpanded ? 'block' : 'none';
    icon.textContent = linksExpanded ? '▼' : '▶';
    text.textContent = linksExpanded ? '收起' : '展开';
}

// 更新目标URL计数
function updateTargetCount() {
    const textarea = document.querySelector('[name="target_urls"]');
    const urls = textarea.value.split('\n').filter(line => line.trim());
    document.getElementById('targetCount').textContent = urls.length;
}

// 保存配置
document.getElementById('configForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('ajax', '1');
    formData.append('action', 'save_config');
    
    fetchJSON('focus_task.php?id=' + taskId, {
        method: 'POST',
        body: formData
    })
    .then(data => {
        console.log('保存配置响应:', data);
        if (data.success) {
            const lockedCount = data.locked_count || 0;
            alert('配置已保存！锁定了 ' + lockedCount + ' 个URL');
            // 刷新页面以显示最新数据
            setTimeout(() => location.reload(), 1000);
        } else {
            alert('保存失败: ' + data.message);
        }
    })
    .catch(err => {
        console.error('保存配置错误:', err);
        alert('保存失败，请查看控制台');
    });
});

// 切换任务状态
function toggleTask(enabled) {
    const body = new URLSearchParams({
        ajax: '1',
        action: 'toggle_task',
        enabled: enabled
    });
    
    fetchJSON('focus_task.php?id=' + taskId, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body
    })
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('操作失败: ' + data.message);
        }
    })
    .catch(err => console.error('切换任务状态错误:', err));
}

// 更新定时间隔字段
function updateScheduleFields() {
    const value = parseInt(document.getElementById('scheduleValue').value) || 0;
    const unit = document.getElementById('scheduleUnit').value;
    
    // 重置所有字段
    document.getElementById('schedule_days').value = 0;
    document.getElementById('schedule_hours').value = 0;
    document.getElementById('schedule_minutes').value = 0;
    
    // 根据单位设置对应字段
    if (unit === 'days') {
        document.getElementById('schedule_days').value = value;
    } else if (unit === 'hours') {
        document.getElementById('schedule_hours').value = value;
    } else if (unit === 'minutes') {
        document.getElementById('schedule_minutes').value = value;
    }
}

// 初始化定时间隔显示
function initScheduleDisplay() {
    const days = parseInt(document.getElementById('schedule_days').value) || 0;
    const hours = parseInt(document.getElementById('schedule_hours').value) || 0;
    const minutes = parseInt(document.getElementById('schedule_minutes').value) || 0;
    
    if (days > 0) {
        document.getElementById('scheduleValue').value = days;
        document.getElementById('scheduleUnit').value = 'days';
    } else if (hours > 0) {
        document.getElementById('scheduleValue').value = hours;
        document.getElementById('scheduleUnit').value = 'hours';
    } else if (minutes > 0) {
        document.getElementById('scheduleValue').value = minutes;
        document.getElementById('scheduleUnit').value = 'minutes';
    } else {
        document.getElementById('scheduleValue').value = 0;
        document.getElementById('scheduleUnit').value = 'days';
    }
}

// 初始化
// 初始化排除项配置

document.addEventListener('DOMContentLoaded', function() {
    updateTargetCount();
    initScheduleDisplay();
    initExclusionSettings();
    
    // 自动加载已提取的链接（如果有）
    loadExtractedLinks(1);
});
</script>

<style>
/* 强制两栏布局 */
.section-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 24px;
}

/* 只在小屏幕才单列 */
@media (max-width: 768px) {
    .section-grid {
        grid-template-columns: 1fr;
    }
}

/* 页面头部 */
.page-header-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border);
}

.back-link {
    color: var(--primary);
    text-decoration: none;
    font-size: 14px;
}

.back-link:hover {
    text-decoration: underline;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}

.status-badge {
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 13px;
    font-weight: 500;
}

.status-running {
    background: rgba(34, 197, 94, 0.1);
    color: var(--success);
}

.status-stopped {
    background: rgba(239, 68, 68, 0.1);
    color: var(--error);
}

/* 数据来源按钮 */
.source-type-buttons {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
}

.source-btn {
    padding: 10px 16px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text);
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s;
}

.source-btn:hover {
    background: var(--bg-hover);
    border-color: var(--primary);
}

.source-btn.active {
    background: var(--primary);
    border-color: var(--primary);
    color: white;
}

/* 链接部分 */
.links-section {
    margin-top: 20px;
}

.section-subtitle {
    font-size: 15px;
    font-weight: 600;
    color: var(--text);
    margin: 0;
}

.links-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.links-actions {
    display: flex;
    gap: 8px;
}

.links-stats {
    display: flex;
    gap: 16px;
    font-size: 14px;
    color: var(--text-muted);
}

.links-stats strong {
    color: var(--text);
    font-weight: 600;
}

/* 标签页样式 */
.links-tabs {
    display: flex;
    gap: 8px;
    border-bottom: 2px solid var(--border);
    margin-bottom: 12px;
}

.tab-btn {
    padding: 8px 16px;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    cursor: pointer;
    font-size: 14px;
    color: var(--text-muted);
    transition: all 0.2s;
}

.tab-btn:hover {
    color: var(--text);
    background: var(--bg-hover);
}

.tab-btn.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
    font-weight: 600;
}

/* Badge样式 */
.badge {
    display: inline-block;
    padding: 2px 8px;
    font-size: 12px;
    border-radius: 4px;
    font-weight: 500;
}

.badge-warning {
    background: #fff3cd;
    color: #856404;
    border: 1px solid #ffc107;
}

.links-table-wrapper {
    max-height: 400px;
    overflow-y: auto;
    border: 1px solid var(--border);
    border-radius: 6px;
}

.links-table {
    width: 100%;
    border-collapse: collapse;
}

.links-table thead {
    position: sticky;
    top: 0;
    background: var(--bg-hover);
    z-index: 1;
}

.links-table th,
.links-table td {
    padding: 10px 12px;
    text-align: left;
    border-bottom: 1px solid var(--border);
}

.links-table th {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-muted);
}

.links-table td {
    font-size: 14px;
    color: var(--text);
}

.link-url {
    max-width: 400px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-family: 'Monaco', 'Menlo', monospace;
    font-size: 13px;
}

.empty-state {
    text-align: center;
    padding: 40px 20px !important;
    color: var(--text-muted);
}

/* 分页 */
.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    margin-top: 16px;
    gap: 12px;
}

/* 复选框组 */
.checkbox-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    padding: 8px;
    border-radius: 4px;
    transition: background 0.2s;
}

.checkbox-label:hover {
    background: var(--bg-hover);
}

.checkbox-label input[type="checkbox"] {
    cursor: pointer;
}

/* 表单行 */
.form-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.form-row .form-group {
    margin-bottom: 0;
}

/* 两列表单行（域名和关键词并列） */
.form-row-2col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.form-row-2col .form-group {
    margin-bottom: 0;
}

/* 三列表单行（跳转类型、概率、定时间隔） */
.form-row-3col {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}

.form-row-3col .form-group {
    margin-bottom: 0;
}

/* 定时输入组（数字+单位） */
.schedule-input-group {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 8px;
}

.schedule-input-group .form-input {
    min-width: 0;
}

.schedule-input-group .form-select {
    min-width: 80px;
}

/* 小屏幕单列 */
@media (max-width: 768px) {
    .form-row-2col,
    .form-row-3col {
        grid-template-columns: 1fr;
    }
}

/* 提示文本 */
.form-hint {
    font-size: 13px;
    color: var(--text-muted);
    margin-top: 6px;
}

/* ==================== 排除项悬浮窗口样式 ==================== */

/* 遮罩层 */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(15, 23, 42, 0.95);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.2s ease-out;
    backdrop-filter: blur(4px);
}

@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

/* 窗口容器 */
.modal-container {
    width: 90%;
    max-width: 1000px;
    height: 85vh;
    max-height: 700px;
    background: var(--card-bg);
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
    border: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    animation: slideIn 0.3s ease-out;
    opacity: 1;
}

@keyframes slideIn {
    from {
        transform: scale(0.9);
        opacity: 0;
    }
    to {
        transform: scale(1);
        opacity: 1;
    }
}

/* 窗口头部 */
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid var(--border);
}

.modal-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: var(--text-primary);
}

.modal-actions {
    display: flex;
    gap: 8px;
    align-items: center;
}

.btn-close {
    background: none;
    border: none;
    font-size: 24px;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: all 0.2s;
}

.btn-close:hover {
    background: var(--hover-bg);
    color: var(--text-primary);
}

/* 窗口主体 */
.modal-body {
    flex: 1;
    padding: 24px;
    overflow: hidden;
}

/* 三列网格布局 */
.exclusion-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    height: 100%;
}

/* 单列样式 */
.exclusion-column {
    display: flex;
    flex-direction: column;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    overflow: hidden;
}

.column-header {
    padding: 12px 16px;
    background: var(--card-bg);
    border-bottom: 1px solid var(--border);
}

.column-header h4 {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
}

/* 搜索框 */
.search-box {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}

.search-box .form-input {
    width: 100%;
    padding: 8px 12px;
    font-size: 13px;
}

/* 域名输入区域 */
.domain-input-area {
    flex: 1;
    padding: 16px;
    overflow: hidden;
}

.domain-input-area textarea {
    width: 100%;
    height: 100%;
    resize: none;
    font-family: 'Monaco', 'Menlo', monospace;
    font-size: 13px;
    line-height: 1.6;
}

/* 复选框列表 */
.checkbox-list {
    flex: 1;
    overflow-y: auto;
    padding: 8px;
}

.checkbox-item {
    display: flex;
    align-items: center;
    padding: 8px 12px;
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.2s;
    user-select: none;
}

.checkbox-item:hover {
    background: var(--hover-bg);
}

.checkbox-item input[type="checkbox"] {
    margin-right: 8px;
    cursor: pointer;
}

.checkbox-item label {
    flex: 1;
    cursor: pointer;
    font-size: 13px;
    color: var(--text-primary);
    margin: 0;
}

.checkbox-item.hidden {
    display: none;
}

.loading-text {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-secondary);
    font-size: 14px;
}

.empty-text {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-muted);
    font-size: 13px;
}

/* 列底部 */
.column-footer {
    padding: 12px 16px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--card-bg);
}

.selected-count {
    font-size: 12px;
    color: var(--text-secondary);
}

.selected-count span {
    font-weight: 600;
    color: var(--primary);
}

/* 窗口底部 */
.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--border);
    background: var(--card-bg);
}

.hint-text {
    font-size: 13px;
    color: var(--text-secondary);
    text-align: center;
}

/* 响应式：小屏幕改为单列 */
@media (max-width: 900px) {
    .exclusion-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .modal-container {
        width: 95%;
        height: 90vh;
    }
}

/* 滚动条样式 */
.checkbox-list::-webkit-scrollbar {
    width: 6px;
}

.checkbox-list::-webkit-scrollbar-track {
    background: var(--bg);
}

.checkbox-list::-webkit-scrollbar-thumb {
    background: var(--border);
    border-radius: 3px;
}

.checkbox-list::-webkit-scrollbar-thumb:hover {
    background: var(--text-muted);
}
</style>

<?php require_once __DIR__ . '/footer.php'; ?>
