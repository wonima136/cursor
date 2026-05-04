<?php
/**
 * 智能集权重定向管理
 */

// 处理 AJAX 请求 - 必须在任何输出之前
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    // 清除所有输出缓冲
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
}

$pageTitle = '智能集权 - 301重定向管理系统';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/focus_functions.php';

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => '未知操作'];
    
    switch ($action) {
        // 渲染蜘蛛筛选编辑模式
        case 'render_spider_edit':
            require_once __DIR__ . '/spider_selector.php';
            
            $config = json_decode($_POST['config'] ?? '{}', true);
            $types = $config['types'] ?? [];
            
            ob_start();
            renderSpiderSelectorEditMode('edit', $types);
            $html = ob_get_clean();
            
            echo $html;
            exit;
        
        // 清洗数据
        case 'clean_data':
            $response = _focus_cleanSitesData();
            break;
        
        // 获取清洗进度
        case 'get_clean_progress':
            $response = _focus_getProgress();
            break;
        
        // 搜索关键词
        case 'search_keywords':
            $keyword = trim($_POST['keyword'] ?? '');
            $dataType = trim($_POST['data_type'] ?? '');
            $group = trim($_POST['group'] ?? '');
            
            $response = _focus_searchKeywords($keyword, $dataType, $group);
            break;
        
        // 创建任务（简化版：只需要名称和蜘蛛筛选）
        case 'create_task':
            $name = trim($_POST['name'] ?? '');
            if (empty($name)) {
                $response = ['success' => false, 'message' => '请输入任务名称'];
                break;
            }
            
            $data = [
                'name' => $name,
                'mode' => 'focus',
                'target_url' => '',  // 稍后在任务详情页配置
                'target_keyword' => '',
                'redirect_type' => 301,
                'probability' => 100,
                'schedule_days' => 0,
                'schedule_hours' => 0,
                'schedule_minutes' => 0,
                'spider_filter' => json_decode($_POST['spider_filter'] ?? '{}', true)
            ];
            
            $response = _focus_createTask($data);
            break;
        
        // 更新任务
        case 'update_task':
            $taskId = $_POST['task_id'] ?? '';
            $updates = [
                'enabled' => isset($_POST['enabled']) ? intval($_POST['enabled']) : null,
                'probability' => isset($_POST['probability']) ? intval($_POST['probability']) : null,
                'redirect_type' => isset($_POST['redirect_type']) ? intval($_POST['redirect_type']) : null,
                'spider_filter' => isset($_POST['spider_filter']) ? json_decode($_POST['spider_filter'], true) : null
            ];
            
            // 调试日志
            error_log("focus.php update_task: taskId=$taskId");
            error_log("focus.php update_task: spider_filter=" . json_encode($updates['spider_filter']));
            
            // 移除null值
            $updates = array_filter($updates, function($v) { return $v !== null; });
            
            error_log("focus.php update_task: 过滤后的updates=" . json_encode($updates));
            
            $response = _focus_updateTask($taskId, $updates);
            
            error_log("focus.php update_task: 响应=" . json_encode($response));
            break;
        
        // 删除任务
        case 'delete_task':
            $taskId = $_POST['task_id'] ?? '';
            $response = _focus_deleteTask($taskId);
            break;
        
        // 获取任务列表
        case 'get_tasks':
            $response = _focus_getTasks();
            break;
        
        // 获取任务详情
        case 'get_task_detail':
            $taskId = $_POST['task_id'] ?? '';
            $response = _focus_getTaskDetail($taskId);
            break;
        
        // 获取数据类型列表
        case 'get_data_types':
            $response = _focus_getDataTypes();
            break;
        
        // 获取分组列表
        case 'get_groups':
            $response = _focus_getGroups();
            break;
        
        // 获取任务锁定的URL列表
        case 'get_task_urls':
            $taskId = $_POST['task_id'] ?? '';
            $page = intval($_POST['page'] ?? 1);
            $perPage = intval($_POST['per_page'] ?? 50);
            $response = _focus_getTaskLockedUrls($taskId, $page, $perPage);
            break;
        
        // 解锁URL
        case 'unlock_url':
            $taskId = $_POST['task_id'] ?? '';
            $urlId = intval($_POST['url_id'] ?? 0);
            $response = _focus_unlockUrl($taskId, $urlId);
            break;
        
        // 导出任务URL
        case 'export_task_urls':
            $taskId = $_POST['task_id'] ?? '';
            $result = _focus_exportTaskUrls($taskId);
            
            if ($result['success']) {
                // 生成CSV
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="focus_task_' . $taskId . '_urls.csv"');
                
                $output = fopen('php://output', 'w');
                fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
                
                // 表头
                fputcsv($output, ['完整URL', '关键词', 'URL类型', '三端组', '跳转次数', '最后跳转时间', '域名', '品牌名', '数据类型', '分组']);
                
                // 数据
                foreach ($result['urls'] as $url) {
                    fputcsv($output, [
                        $url['full_url'],
                        $url['keyword'],
                        $url['url_type'],
                        $url['terminal_group'] ?? '',
                        $url['redirect_count'],
                        $url['last_redirect_at'] ?? '',
                        $url['domain'],
                        $url['brand_name'],
                        $url['data_type'],
                        $url['group_name']
                    ]);
                }
                
                fclose($output);
                exit;
            } else {
                $response = $result;
            }
            break;
    }
    
    // 清除输出缓冲区中的任何错误信息
    $output = ob_get_clean();
    
    // 只输出JSON
    echo json_encode($response);
    exit;
}

// 加载任务列表
$tasks = _focus_getTasks();
$tasksData = $tasks['success'] ? $tasks['tasks'] : [];

// 获取数据统计
$stats = _focus_getStats();

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/spider_selector.php';
?>

<style>
/* 页面头部 */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

.page-title {
    font-size: 24px;
    font-weight: 600;
    color: var(--text);
    margin: 0;
}

.page-subtitle {
    color: var(--text-muted);
    font-size: 14px;
    margin-top: 4px;
}

.header-actions {
    display: flex;
    gap: 12px;
}

/* 统计卡片 */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
    transition: all 0.2s;
}

.stat-card:hover {
    border-color: var(--primary);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1);
}

.stat-card h3 {
    margin: 0 0 12px 0;
    font-size: 13px;
    color: var(--text-muted);
    font-weight: 500;
}

.stat-card .stat-value {
    font-size: 28px;
    font-weight: 600;
    color: var(--text);
    margin: 0;
}

/* 任务列表 */
.tasks-table-container {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
}

.tasks-table {
    width: 100%;
    border-collapse: collapse;
}

.tasks-table thead {
    background: var(--bg-secondary);
}

.tasks-table th {
    padding: 16px;
    text-align: left;
    font-weight: 600;
    color: var(--text);
    border-bottom: 2px solid var(--border);
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.tasks-table td {
    padding: 16px;
    border-bottom: 1px solid var(--border);
    color: var(--text);
}

.tasks-table tbody tr {
    transition: background 0.2s;
}

.tasks-table tbody tr:hover {
    background: var(--bg-hover);
}

.status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    display: inline-block;
}

.status-badge.running {
    background: rgba(34, 197, 94, 0.15);
    color: #22c55e;
}

.status-badge.stopped {
    background: rgba(239, 68, 68, 0.15);
    color: #ef4444;
}

/* 按钮样式 */
.btn-sm {
    padding: 6px 12px;
    font-size: 12px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    font-weight: 500;
}

.btn-primary {
    background: var(--primary);
    color: white;
}

.btn-primary:hover {
    background: var(--primary-hover);
}

.btn-success {
    background: var(--success);
    color: white;
}

.btn-success:hover {
    opacity: 0.9;
}

.btn-danger {
    background: var(--error);
    color: white;
}

.btn-danger:hover {
    opacity: 0.9;
}

.btn-warning {
    background: var(--warning);
    color: white;
}

.btn-warning:hover {
    opacity: 0.9;
}

/* 模态框 */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    z-index: 1000;
    justify-content: center;
    align-items: center;
}

.modal-overlay.active {
    display: flex;
}

.modal-content {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 24px;
    width: 100%;
    max-width: 800px;
    max-height: 80vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.modal-header h3 {
    margin: 0;
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
    line-height: 1;
}

.modal-close:hover {
    color: var(--text);
}

/* 表单样式 */
.form-group {
    margin-bottom: 20px;
}

.form-group label {
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
    font-size: 14px;
    background: var(--bg-dark);
    color: var(--text);
    transition: all 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: var(--primary);
    background: var(--bg-card);
}

/* 关键词搜索 */
.keyword-search {
    margin-bottom: 20px;
}

.keyword-results {
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px;
    background: var(--bg-dark);
}

.keyword-item {
    padding: 10px 12px;
    margin: 6px 0;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 6px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: all 0.2s;
}

.keyword-item:hover {
    border-color: var(--primary);
    background: var(--bg-card);
}

.keyword-item.selected {
    background: rgba(34, 197, 94, 0.1);
    border-color: var(--success);
}

.keyword-count {
    background: var(--primary);
    color: white;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
}

.schedule-inputs {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}

/* 提示信息 */
.alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    font-size: 14px;
}

.alert-success {
    background: rgba(34, 197, 94, 0.1);
    color: var(--success);
    border: 1px solid rgba(34, 197, 94, 0.3);
}

.alert-error {
    background: rgba(239, 68, 68, 0.1);
    color: var(--error);
    border: 1px solid rgba(239, 68, 68, 0.3);
}

/* 空状态 */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: var(--bg-card);
    border: 2px dashed var(--border);
    border-radius: 12px;
    margin-top: 20px;
}

.empty-state h3 {
    font-size: 20px;
    margin-bottom: 10px;
    color: var(--text);
}

.empty-state p {
    color: var(--text-muted);
    margin-bottom: 20px;
}

.empty-state-icon {
    font-size: 64px;
    margin-bottom: 20px;
    opacity: 0.3;
}
</style>

<div class="content-wrapper">
    <!-- 页面头部 -->
    <div class="page-header">
        <div>
            <h1 class="page-title">智能集权重定向</h1>
            <p class="page-subtitle">基于网站数据的智能SEO集权管理</p>
        </div>
        <div class="header-actions">
            <?php require_once __DIR__ . '/help_modal.php'; renderHelpModal('focus_redirect'); ?>
            <button class="btn btn-success" id="btnCleanData">
                🔄 清洗数据
            </button>
            <button class="btn btn-primary" id="btnCreateTask">
                ➕ 创建任务
            </button>
        </div>
    </div>

    <!-- 数据统计 -->
    <div class="stats-grid">
        <div class="stat-card">
            <h3>域名总数</h3>
            <p class="stat-value"><?php echo number_format($stats['total_domains'] ?? 0); ?></p>
        </div>
        <div class="stat-card">
            <h3>URL总数</h3>
            <p class="stat-value"><?php echo number_format($stats['total_urls'] ?? 0); ?></p>
        </div>
        <div class="stat-card">
            <h3>关键词总数</h3>
            <p class="stat-value"><?php echo number_format($stats['total_keywords'] ?? 0); ?></p>
        </div>
        <div class="stat-card">
            <h3>活跃任务</h3>
            <p class="stat-value"><?php echo number_format($stats['active_tasks'] ?? 0); ?></p>
        </div>
    </div>

    <!-- 任务列表 -->
    <?php if (empty($tasksData)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">📭</div>
        <h3>暂无任务</h3>
        <p>点击"创建任务"开始使用智能集权功能</p>
    </div>
    <?php else: ?>
    <div class="tasks-table-container">
        <table class="tasks-table">
            <thead>
                <tr>
                    <th>任务名称</th>
                    <th>状态</th>
                    <th>目标关键词</th>
                    <th style="text-align: center;">锁定URL</th>
                    <th style="text-align: center;">总跳转</th>
                    <th style="text-align: center;">剩余时间</th>
                    <th>配置摘要</th>
                    <th style="text-align: right;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tasksData as $task): 
                    $stats = $task['stats'] ?? [];
                    $topKeywords = $task['top_keywords'] ?? [];
                    $keywordsText = !empty($topKeywords) ? implode(', ', $topKeywords) : '-';
                    
                    // 计算定时间隔（分钟）
                    $scheduleTotal = ($task['schedule_days'] ?? 0) * 24 * 60 + 
                                    ($task['schedule_hours'] ?? 0) * 60 + 
                                    ($task['schedule_minutes'] ?? 0);
                    
                    // 获取URL锁定时间（从Redis）
                    $lockedAt = 0;
                    if ($task['locked_urls_count'] > 0) {
                        // 从Redis获取第一个锁定URL的时间（Redis存储的是Unix时间戳）
                        try {
                            require_once __DIR__ . '/redis_config.php';
                            $redis = getRedis();
                            if ($redis) {
                                // 获取任务的一个锁定URL
                                $db = new SQLite3(__DIR__ . '/data/focus.db');
                                $stmt = $db->prepare("SELECT full_url FROM url_keywords WHERE locked_by_task_id = ? AND is_locked = 1 LIMIT 1");
                                $stmt->bindValue(1, $task['id'], SQLITE3_TEXT);
                                $result = $stmt->execute();
                                $row = $result->fetchArray(SQLITE3_ASSOC);
                                if ($row && $row['full_url']) {
                                    $lockData = $redis->get('focus:lock:' . $row['full_url']);
                                    if ($lockData) {
                                        $lock = json_decode($lockData, true);
                                        $lockedAt = $lock['locked_at'] ?? 0;
                                    }
                                }
                                $db->close();
                            }
                        } catch (Exception $e) {
                            // 忽略错误
                        }
                    }
                    
                    // 如果没有锁定时间，使用当前时间（表示刚刚锁定）
                    if ($lockedAt == 0) {
                        $lockedAt = time();
                    }
                    
                    $expireAt = $lockedAt + ($scheduleTotal * 60);
                ?>
                <tr id="task-<?php echo htmlspecialchars($task['id']); ?>" 
                    data-task-id="<?php echo htmlspecialchars($task['id']); ?>"
                    data-expire-at="<?php echo $expireAt; ?>"
                    data-schedule-total="<?php echo $scheduleTotal; ?>">
                    <td>
                        <strong><?php echo htmlspecialchars($task['name']); ?></strong>
                    </td>
                    <td>
                        <span class="status-badge <?php echo $task['enabled'] ? 'running' : 'stopped'; ?>">
                            <?php echo $task['enabled'] ? '● 启用' : '● 禁用'; ?>
                        </span>
                    </td>
                    <td>
                        <span style="color: var(--primary); font-size: 13px; font-weight: 500;">
                            <?php echo htmlspecialchars($keywordsText); ?>
                        </span>
                    </td>
                    <td style="text-align: center; font-weight: 600;">
                        <?php echo number_format($task['locked_urls_count'] ?? 0); ?>
                    </td>
                    <td style="text-align: center; font-weight: 600; color: var(--success);">
                        <?php echo number_format($stats['total_redirects'] ?? 0); ?>
                    </td>
                    <td style="text-align: center;">
                        <span class="countdown" id="countdown-<?php echo htmlspecialchars($task['id']); ?>" 
                              style="font-weight: 600; font-size: 13px;">
                            计算中...
                        </span>
                    </td>
                    <td style="font-size: 12px; color: var(--text-muted);">
                        <?php echo $task['redirect_type'] == 301 ? '301永久' : '302临时'; ?> | 
                        概率 <?php echo $task['probability']; ?>%
                    </td>
                    <td style="text-align: right;">
                        <div style="display: flex; gap: 6px; justify-content: flex-end;">
                            <button class="btn-sm btn-primary" onclick="viewTaskDetail('<?php echo $task['id']; ?>')">
                                📊 详情
                            </button>
                            <button class="btn-sm btn-warning" onclick="toggleTaskStatus('<?php echo $task['id']; ?>', <?php echo $task['enabled'] ? 0 : 1; ?>)">
                                <?php echo $task['enabled'] ? '⏸ 禁用' : '▶ 启用'; ?>
                            </button>
                            <button class="btn-sm btn-success" onclick="editSpiderFilter('<?php echo $task['id']; ?>')">
                                🕷 爬虫
                            </button>
                            <button class="btn-sm btn-danger" onclick="deleteTask('<?php echo $task['id']; ?>')">
                                🗑 删除
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- 清洗数据模态框 -->
<div class="modal-overlay" id="cleanDataModal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>🔄 清洗网站数据</h3>
            <button class="modal-close" onclick="closeModal('cleanDataModal')">&times;</button>
        </div>
        <div id="cleanDataResult"></div>
        <p style="color: #666; margin-bottom: 20px;">
            从 <code>sites.json</code> 中提取域名和关键词数据，存入SQLite数据库。<br>
            <strong>纯增量更新</strong>：只添加新数据，不修改已存在的URL。
        </p>
        <button class="btn btn-primary" onclick="executeCleanData()" style="width: 100%;">
            开始清洗
        </button>
    </div>
</div>

<!-- 创建任务模态框 - 步骤1: 选择蜘蛛 -->
<div class="modal-overlay" id="createSpiderModal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3>创建任务 - 步骤1: 蜘蛛筛选</h3>
            <button class="modal-close" onclick="closeModal('createSpiderModal')">&times;</button>
        </div>
        
        <div style="margin-bottom: 20px;">
            <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 16px;">
                配置此任务对哪些蜘蛛类型生效
            </p>
            <?php renderSpiderSelector('create'); ?>
        </div>
        
        <button class="btn btn-primary" onclick="goToTaskConfigStep()" style="width: 100%;">
            下一步：任务配置
        </button>
    </div>
</div>

<!-- 创建任务模态框 - 步骤2: 任务命名 -->
<div class="modal-overlay" id="createTaskModal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3>创建任务 - 步骤2: 任务命名</h3>
            <button class="modal-close" onclick="closeModal('createTaskModal')">&times;</button>
        </div>
        
        <div id="createTaskAlert"></div>
        
        <form id="createTaskForm" onsubmit="return handleCreateTaskSubmit(event);">
            <div class="form-group">
                <label class="form-label">任务名称 *</label>
                <input type="text" class="form-control" name="name" required 
                       placeholder="例如：七猫小说集权" autofocus>
                <p class="form-hint">创建后将跳转到任务详情页进行配置</p>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 16px;">
                创建任务
            </button>
        </form>
    </div>
</div>

<!-- 任务详情模态框 -->
<div class="modal-overlay" id="taskDetailModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>任务详情</h3>
            <button class="modal-close" onclick="closeModal('taskDetailModal')">&times;</button>
        </div>
        <div id="taskDetailContent">
            <!-- 动态加载 -->
        </div>
    </div>
</div>

<!-- 编辑蜘蛛筛选模态框 -->
<div class="modal-overlay" id="editSpiderModal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>编辑蜘蛛筛选</h3>
            <button class="modal-close" onclick="closeModal('editSpiderModal')">&times;</button>
        </div>
        <div id="editSpiderContent">
            <!-- 动态加载 -->
        </div>
        <button class="btn btn-primary" onclick="saveSpiderFilter()" style="width: 100%; margin-top: 20px;">
            保存配置
        </button>
    </div>
</div>

<!-- 任务URL管理模态框 -->
<div class="modal-overlay" id="taskUrlsModal">
    <div class="modal-content" style="max-width: 1200px;">
        <div class="modal-header">
            <h3>管理锁定URL</h3>
            <button class="modal-close" onclick="closeModal('taskUrlsModal')">&times;</button>
        </div>
        <div id="taskUrlsList">
            <!-- 动态加载 -->
        </div>
    </div>
</div>

<script>
// 版本: 2025-01-06-v2 - 修复正则表达式错误
let selectedKeywords = [];
let currentEditTaskId = null;

// 显示清洗数据模态框
function showCleanDataModal() {
    document.getElementById('cleanDataModal').classList.add('active');
    document.getElementById('cleanDataResult').innerHTML = '';
}

// 执行数据清洗
let cleanProgressInterval = null;

function executeCleanData() {
    const resultDiv = document.getElementById('cleanDataResult');
    
    // 显示进度条
    resultDiv.innerHTML = `
        <div class="alert alert-success">
            <div style="margin-bottom: 12px;">
                <strong id="progressMessage">正在初始化...</strong>
            </div>
            <div style="background: rgba(255,255,255,0.2); border-radius: 8px; height: 24px; overflow: hidden; margin-bottom: 8px;">
                <div id="progressBar" style="background: linear-gradient(90deg, #4CAF50, #8BC34A); height: 100%; width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 12px;">
                    0%
                </div>
            </div>
            <div style="font-size: 12px; color: rgba(255,255,255,0.8);">
                <span id="progressDetail">准备中...</span>
            </div>
        </div>
    `;
    
    // 开始轮询进度
    startProgressPolling();
    
    // 启动清洗任务
    fetch('focus.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'ajax=1&action=clean_data'
    })
    .then(res => res.json())
    .then(data => {
        stopProgressPolling();
        
        if (data.success) {
            resultDiv.innerHTML = `
                <div class="alert alert-success">
                    <h3>✓ 清洗成功！</h3>
                    <p>处理站点: ${data.total_sites} 个</p>
                    <p>新增域名: ${data.new_domains} 个</p>
                    <p>更新域名: ${data.updated_domains} 个</p>
                    <p>新增URL: ${data.new_urls} 个</p>
                    <p>跳过URL: ${data.skipped_urls} 个</p>
                </div>
            `;
            setTimeout(() => location.reload(), 2000);
        } else {
            resultDiv.innerHTML = `<div class="alert alert-error">✗ ${data.message}</div>`;
        }
    })
    .catch(err => {
        stopProgressPolling();
        resultDiv.innerHTML = `<div class="alert alert-error">✗ 请求失败: ${err.message}</div>`;
    });
}

function startProgressPolling() {
    cleanProgressInterval = setInterval(updateCleanProgress, 500);
}

function stopProgressPolling() {
    if (cleanProgressInterval) {
        clearInterval(cleanProgressInterval);
        cleanProgressInterval = null;
    }
}

function updateCleanProgress() {
    fetch('focus.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'ajax=1&action=get_clean_progress'
    })
    .then(res => res.json())
    .then(data => {
        const progressBar = document.getElementById('progressBar');
        const progressMessage = document.getElementById('progressMessage');
        const progressDetail = document.getElementById('progressDetail');
        
        if (progressBar && progressMessage && progressDetail) {
            const percent = data.percent || 0;
            progressBar.style.width = percent + '%';
            progressBar.textContent = percent + '%';
            progressMessage.textContent = data.message || '处理中...';
            progressDetail.textContent = `已处理 ${data.current || 0} / ${data.total || 0} 个站点`;
        }
    })
    .catch(err => {
        // 获取进度失败
    });
}

// 显示创建任务模态框
function showCreateTaskModal() {
    const modal = document.getElementById('createSpiderModal');
    if (modal) {
        modal.classList.add('active');
    }
}

// 进入任务配置步骤
function goToTaskConfigStep() {
    closeModal('createSpiderModal');
    const modal = document.getElementById('createTaskModal');
    if (modal) {
        modal.classList.add('active');
    }
}

// 加载数据类型
function loadDataTypes() {
    fetch('focus.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'ajax=1&action=get_data_types'
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const select = document.getElementById('dataTypeFilter');
            data.data_types.forEach(type => {
                const option = document.createElement('option');
                option.value = type;
                option.textContent = type;
                select.appendChild(option);
            });
        }
    });
}

// 加载分组
function loadGroups() {
    fetch('focus.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'ajax=1&action=get_groups'
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const select = document.getElementById('groupFilter');
            data.groups.forEach(group => {
                const option = document.createElement('option');
                option.value = group;
                option.textContent = group;
                select.appendChild(option);
            });
        }
    });
}

// 搜索关键词
function searchKeywords() {
    const keyword = document.getElementById('keywordSearchInput').value.trim();
    const dataType = document.getElementById('dataTypeFilter').value;
    const group = document.getElementById('groupFilter').value;
    
    if (keyword.length < 1) {
        document.getElementById('keywordResults').style.display = 'none';
        return;
    }
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'search_keywords');
    formData.append('keyword', keyword);
    formData.append('data_type', dataType);
    formData.append('group', group);
    
    fetch('focus.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const resultsDiv = document.getElementById('keywordResults');
            resultsDiv.style.display = 'block';
            resultsDiv.innerHTML = '';
            
            data.keywords.forEach(item => {
                const div = document.createElement('div');
                div.className = 'keyword-item';
                if (selectedKeywords.includes(item.keyword)) {
                    div.classList.add('selected');
                }
                div.innerHTML = `
                    <span>${item.keyword}</span>
                    <span class="keyword-count">${item.available_count}个可用</span>
                `;
                div.onclick = () => toggleKeyword(item.keyword, div);
                resultsDiv.appendChild(div);
            });
        }
    });
}

// 切换关键词选择
function toggleKeyword(keyword, element) {
    const index = selectedKeywords.indexOf(keyword);
    if (index > -1) {
        selectedKeywords.splice(index, 1);
        element.classList.remove('selected');
    } else {
        selectedKeywords.push(keyword);
        element.classList.add('selected');
    }
    updateSelectedKeywordsDisplay();
}

// 更新已选择关键词显示
function updateSelectedKeywordsDisplay() {
    const div = document.getElementById('selectedKeywords');
    if (selectedKeywords.length === 0) {
        div.innerHTML = '';
        return;
    }
    
    div.innerHTML = `
        <strong>已选择 ${selectedKeywords.length} 个关键词:</strong>
        <div style="margin-top: 5px;">
            ${selectedKeywords.map(kw => `<span style="display: inline-block; background: #007bff; color: white; padding: 4px 8px; border-radius: 5px; margin: 2px;">${kw}</span>`).join('')}
        </div>
    `;
}

// 处理创建任务表单提交
function handleCreateTaskSubmit(event) {
    event.preventDefault();
    event.stopPropagation();
    
    const form = event.target;
    const formData = new FormData(form);
    const taskName = formData.get('name');
    
    formData.append('ajax', '1');
    formData.append('action', 'create_task');
    
    // 获取蜘蛛筛选配置
    const spiderConfig = getSpiderFilterConfig('create');
    formData.append('spider_filter', JSON.stringify(spiderConfig));
    
    fetch('focus.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        const alertDiv = document.getElementById('createTaskAlert');
        if (data.success) {
            if (!data.task_id) {
                alertDiv.innerHTML = '<div class="alert alert-error">✗ 创建成功但未返回任务ID</div>';
                return;
            }
            
            // 立即跳转到任务详情页
            window.location.href = 'focus_task.php?id=' + data.task_id;
        } else {
            alertDiv.innerHTML = '<div class="alert alert-error">✗ ' + (data.message || '创建失败') + '</div>';
        }
    })
    .catch(err => {
        const alertDiv = document.getElementById('createTaskAlert');
        alertDiv.innerHTML = '<div class="alert alert-error">✗ 请求失败，请重试</div>';
    });
    
    return false; // 确保阻止默认行为
}

// 查看任务详情
function viewTaskDetail(taskId) {
    // 跳转到任务详情页面
    window.location.href = `focus_task.php?id=${taskId}`;
}

// 旧版详情模态框（保留兼容）
function viewTaskDetailModal(taskId) {
    currentEditTaskId = taskId;
    
    fetch('focus.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `ajax=1&action=get_task_detail&task_id=${taskId}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const task = data.task;
            document.getElementById('taskDetailContent').innerHTML = `
                <div class="task-info">
                    <div class="task-info-row"><span>任务名称:</span><strong>${task.name}</strong></div>
                    <div class="task-info-row"><span>目标URL:</span><strong><a href="${task.target_url}" target="_blank">${task.target_url}</a></strong></div>
                    <div class="task-info-row"><span>目标关键词:</span><strong>${task.target_keyword}</strong></div>
                    <div class="task-info-row"><span>锁定URL数:</span><strong>${task.locked_urls_count}</strong></div>
                    <div class="task-info-row"><span>总跳转次数:</span><strong>${data.stats.total_redirects}</strong></div>
                    <div class="task-info-row"><span>跳转类型:</span><strong>${task.redirect_type == 301 ? '301永久' : '302临时'}</strong></div>
                    <div class="task-info-row"><span>概率:</span><strong>${task.probability}%</strong></div>
                    <div class="task-info-row"><span>状态:</span><strong>${task.enabled ? '✓ 启用' : '✗ 禁用'}</strong></div>
                </div>
                
                <div style="margin-top: 20px; display: flex; gap: 10px;">
                    <button class="btn btn-primary" onclick="showTaskUrls('${taskId}')">
                        📋 管理锁定URL
                    </button>
                    <button class="btn btn-success" onclick="exportTaskUrls('${taskId}')">
                        📥 导出URL列表
                    </button>
                </div>
            `;
            document.getElementById('taskDetailModal').classList.add('active');
        }
    });
}

// 显示任务URL列表
function showTaskUrls(taskId) {
    closeModal('taskDetailModal');
    loadTaskUrls(taskId, 1);
    document.getElementById('taskUrlsModal').classList.add('active');
}

// 加载任务URL列表
function loadTaskUrls(taskId, page = 1) {
    fetch('focus.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `ajax=1&action=get_task_urls&task_id=${taskId}&page=${page}&per_page=50`
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const urlsDiv = document.getElementById('taskUrlsList');
            
            if (data.urls.length === 0) {
                urlsDiv.innerHTML = '<div class="empty-state"><p>暂无锁定的URL</p></div>';
                return;
            }
            
            let html = `
                <div style="margin-bottom: 15px; color: #666;">
                    共 ${data.total} 个URL，第 ${page}/${data.total_pages} 页
                </div>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;">
                            <th style="padding: 10px; text-align: left;">URL</th>
                            <th style="padding: 10px; text-align: left;">关键词</th>
                            <th style="padding: 10px; text-align: center;">类型</th>
                            <th style="padding: 10px; text-align: center;">跳转次数</th>
                            <th style="padding: 10px; text-align: center;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            data.urls.forEach(url => {
                html += `
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 10px; font-size: 13px;">
                            <a href="http://${url.full_url}" target="_blank">${url.full_url}</a>
                        </td>
                        <td style="padding: 10px;">${url.keyword}</td>
                        <td style="padding: 10px; text-align: center;">
                            <span style="background: #e9ecef; padding: 2px 8px; border-radius: 3px; font-size: 12px;">
                                ${url.url_type}
                            </span>
                        </td>
                        <td style="padding: 10px; text-align: center;">${url.redirect_count}</td>
                        <td style="padding: 10px; text-align: center;">
                            <button class="btn-sm btn-danger" onclick="unlockUrl('${taskId}', ${url.id})">
                                解锁
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            html += '</tbody></table>';
            
            // 分页
            if (data.total_pages > 1) {
                html += '<div style="margin-top: 20px; text-align: center;">';
                for (let i = 1; i <= data.total_pages; i++) {
                    if (i === page) {
                        html += `<button class="btn-sm btn-primary" disabled>${i}</button> `;
                    } else {
                        html += `<button class="btn-sm" onclick="loadTaskUrls('${taskId}', ${i})">${i}</button> `;
                    }
                }
                html += '</div>';
            }
            
            urlsDiv.innerHTML = html;
        }
    });
}

// 解锁URL
function unlockUrl(taskId, urlId) {
    if (confirm('确定要解锁此URL吗？解锁后将不再跳转。')) {
        fetch('focus.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `ajax=1&action=unlock_url&task_id=${taskId}&url_id=${urlId}`
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('解锁成功');
                loadTaskUrls(taskId, 1);
            } else {
                alert('解锁失败: ' + data.message);
            }
        });
    }
}

// 导出任务URL
function exportTaskUrls(taskId) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'focus.php';
    
    const ajaxInput = document.createElement('input');
    ajaxInput.type = 'hidden';
    ajaxInput.name = 'ajax';
    ajaxInput.value = '1';
    form.appendChild(ajaxInput);
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'export_task_urls';
    form.appendChild(actionInput);
    
    const taskIdInput = document.createElement('input');
    taskIdInput.type = 'hidden';
    taskIdInput.name = 'task_id';
    taskIdInput.value = taskId;
    form.appendChild(taskIdInput);
    
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

// 切换任务状态
function toggleTaskStatus(taskId, enabled) {
    if (confirm(`确定要${enabled ? '启用' : '禁用'}此任务吗？`)) {
        fetch('focus.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `ajax=1&action=update_task&task_id=${taskId}&enabled=${enabled}`
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('操作失败: ' + data.message);
            }
        });
    }
}

// 编辑蜘蛛筛选
function editSpiderFilter(taskId) {
    currentEditTaskId = taskId;
    
    // 加载当前配置
    fetch('focus.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `ajax=1&action=get_task_detail&task_id=${taskId}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const spiderFilter = data.task.spider_filter || {};
            const types = spiderFilter.types || [];
            
            // 渲染编辑模式的蜘蛛筛选器
            const contentDiv = document.getElementById('editSpiderContent');
            contentDiv.innerHTML = '';
            
            // 使用PHP渲染
            fetch('focus.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `ajax=1&action=render_spider_edit&config=${encodeURIComponent(JSON.stringify({types: types}))}`
            })
            .then(res => res.text())
            .then(html => {
                contentDiv.innerHTML = html;
                document.getElementById('editSpiderModal').classList.add('active');
            });
        }
    });
}

// 获取编辑模式的蜘蛛筛选配置
function getSpiderFilterConfigEditMode(id) {
    const baiduPc = document.querySelector('input[name="spider_type_baidu_pc_' + id + '"]');
    const baiduMobile = document.querySelector('input[name="spider_type_baidu_mobile_' + id + '"]');
    const google = document.querySelector('input[name="spider_type_google_' + id + '"]');
    const sogou = document.querySelector('input[name="spider_type_sogou_' + id + '"]');
    
    if (!baiduPc || !baiduMobile || !google || !sogou) {
        return {
            enabled: false,
            types: {
                baidu_pc: false,
                baidu_mobile: false,
                google: false,
                sogou: false
            }
        };
    }
    
    const baiduPcChecked = baiduPc.checked;
    const baiduMobileChecked = baiduMobile.checked;
    const googleChecked = google.checked;
    const sogouChecked = sogou.checked;
    
    // 如果任何一个被选中，则enabled为true
    const enabled = baiduPcChecked || baiduMobileChecked || googleChecked || sogouChecked;
    
    return {
        enabled: enabled,
        types: {
            baidu_pc: baiduPcChecked,
            baidu_mobile: baiduMobileChecked,
            google: googleChecked,
            sogou: sogouChecked
        }
    };
}

// 保存蜘蛛筛选配置
function saveSpiderFilter() {
    const spiderConfig = getSpiderFilterConfigEditMode('edit');
    
    fetch('focus.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `ajax=1&action=update_task&task_id=${currentEditTaskId}&spider_filter=${encodeURIComponent(JSON.stringify(spiderConfig))}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('保存成功');
            closeModal('editSpiderModal');
            location.reload();
        } else {
            alert('保存失败: ' + data.message);
        }
    })
    .catch(err => {
        alert('保存失败: ' + err.message);
    });
}

// 删除任务
function deleteTask(taskId) {
    if (confirm('确定要删除此任务吗？所有锁定的URL将被释放。')) {
        // 找到任务行元素
        const taskRow = document.getElementById('task-' + taskId);
        
        fetch('focus.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `ajax=1&action=delete_task&task_id=${taskId}`
        })
        .then(res => {
            const contentType = res.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                return res.text().then(text => {
                    throw new Error('服务器返回了错误的响应格式');
                });
            }
            return res.json();
        })
        .then(data => {
            if (data.success) {
                // 添加淡出动画
                if (taskRow) {
                    taskRow.style.transition = 'opacity 0.3s, transform 0.3s';
                    taskRow.style.opacity = '0';
                    taskRow.style.transform = 'scale(0.95)';
                    
                    // 动画结束后刷新页面
                    setTimeout(() => {
                        location.reload();
                    }, 300);
                } else {
                    // 如果找不到卡片，直接刷新
                    location.reload();
                }
            } else {
                alert('删除失败: ' + data.message);
            }
        })
        .catch(error => {
            alert('删除失败: ' + error.message);
        });
    }
}

// 关闭模态框
function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

// 点击模态框外部关闭
window.onclick = function(event) {
    if (event.target.classList.contains('modal-overlay')) {
        event.target.classList.remove('active');
    }
}

// 倒计时功能
function updateCountdowns() {
    const now = Math.floor(Date.now() / 1000);
    const rows = document.querySelectorAll('tr[data-expire-at]');
    
    rows.forEach(row => {
        const taskId = row.getAttribute('data-task-id');
        const expireAt = parseInt(row.getAttribute('data-expire-at'));
        const scheduleTotal = parseInt(row.getAttribute('data-schedule-total'));
        const countdownEl = document.getElementById('countdown-' + taskId);
        
        if (!countdownEl) return;
        
        // 如果没有设置定时（0分钟），显示永不过期
        if (scheduleTotal === 0) {
            countdownEl.textContent = '永不过期';
            countdownEl.style.color = 'var(--success)';
            return;
        }
        
        const remaining = expireAt - now;
        
        if (remaining <= 0) {
            // 已过期
            countdownEl.textContent = '已停止跳转';
            countdownEl.style.color = 'var(--error)';
            
            // 更新状态徽章
            const statusBadge = row.querySelector('.status-badge');
            if (statusBadge && statusBadge.classList.contains('running')) {
                statusBadge.classList.remove('running');
                statusBadge.classList.add('stopped');
                statusBadge.textContent = '● 已过期';
            }
        } else {
            // 计算剩余时间
            const days = Math.floor(remaining / 86400);
            const hours = Math.floor((remaining % 86400) / 3600);
            const minutes = Math.floor((remaining % 3600) / 60);
            const seconds = remaining % 60;
            
            let timeStr = '';
            if (days > 0) {
                timeStr = `${days}天 ${hours}时 ${minutes}分`;
            } else if (hours > 0) {
                timeStr = `${hours}时 ${minutes}分 ${seconds}秒`;
            } else if (minutes > 0) {
                timeStr = `${minutes}分 ${seconds}秒`;
            } else {
                timeStr = `${seconds}秒`;
            }
            
            countdownEl.textContent = timeStr;
            
            // 根据剩余时间改变颜色
            if (remaining < 300) { // 小于5分钟
                countdownEl.style.color = 'var(--error)';
            } else if (remaining < 1800) { // 小于30分钟
                countdownEl.style.color = 'var(--warning)';
            } else {
                countdownEl.style.color = 'var(--success)';
            }
        }
    });
}

// 页面加载后绑定按钮事件
document.addEventListener('DOMContentLoaded', function() {
    // 清洗数据按钮
    const btnCleanData = document.getElementById('btnCleanData');
    if (btnCleanData) {
        btnCleanData.addEventListener('click', showCleanDataModal);
    }
    
    // 创建任务按钮
    const btnCreateTask = document.getElementById('btnCreateTask');
    if (btnCreateTask) {
        btnCreateTask.addEventListener('click', showCreateTaskModal);
    }
    
    // 启动倒计时
    updateCountdowns();
    setInterval(updateCountdowns, 1000); // 每秒更新一次
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

