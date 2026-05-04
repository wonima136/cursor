<?php
/**
 * 整站重定向任务管理
 */
$pageTitle = '整站重定向 - 301重定向管理系统';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sitewide_functions.php';
require_once __DIR__ . '/spider_selector.php';

// 检查登录
if (!checkLogin()) {
    header('Location: login.php');
    exit;
}

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    // 检查登录状态
    if (!checkLogin()) {
        echo json_encode(['success' => false, 'message' => '登录已过期，请刷新页面重新登录']);
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => '未知操作'];
    
    switch ($action) {
        // 创建新任务
        case 'create':
            $name = trim($_POST['name'] ?? '');
            if (empty($name)) {
                $response = ['success' => false, 'message' => '请输入任务名称'];
                break;
            }
            
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
            
            $taskData = [
                'name' => $name,
                'spider_filter' => $spiderFilter
            ];
            
            $taskId = _r301sitewide_create($taskData);
            if ($taskId) {
                $response = ['success' => true, 'message' => '任务创建成功', 'task_id' => $taskId];
            } else {
                $response = ['success' => false, 'message' => '创建失败'];
            }
            break;
            
        // 更新蜘蛛配置
        case 'update_spider_filter':
            $taskId = $_POST['task_id'] ?? '';
            $spiderFilter = [
                'enabled' => !empty($_POST['spider_filter_enabled']),
                'types' => [
                    'baidu_pc' => !empty($_POST['spider_type_baidu_pc']),
                    'baidu_mobile' => !empty($_POST['spider_type_baidu_mobile']),
                    'google' => !empty($_POST['spider_type_google']),
                    'sogou' => !empty($_POST['spider_type_sogou'])
                ]
            ];
            
            if (_r301sitewide_update($taskId, ['spider_filter' => $spiderFilter])) {
                $response = ['success' => true, 'message' => '蜘蛛配置已更新'];
            } else {
                $response = ['success' => false, 'message' => '更新失败'];
            }
            break;
            
        // 渲染编辑模式的蜘蛛选择器
        case 'render_spider_selector_edit_mode':
            $taskId = $_POST['task_id'] ?? '';
            $task = _r301sitewide_getById($taskId);
            if ($task) {
                $types = $task['spider_filter']['types'] ?? [];
                ob_start();
                renderSpiderSelectorEditMode('edit', $types);
                $html = ob_get_clean();
                $response = ['success' => true, 'html' => $html];
            } else {
                $response = ['success' => false, 'message' => '任务不存在'];
            }
            break;
            
        // 切换任务开关
        case 'toggle':
            $taskId = $_POST['task_id'] ?? '';
            $enabled = $_POST['enabled'] === '1';
            
            if (_r301sitewide_toggle($taskId, $enabled)) {
                $response = ['success' => true, 'message' => $enabled ? '任务已启用' : '任务已停止'];
            } else {
                $response = ['success' => false, 'message' => '操作失败'];
            }
            break;
            
        // 删除任务
        case 'delete':
            $taskId = $_POST['task_id'] ?? '';
            
            if (_r301sitewide_delete($taskId)) {
                $response = ['success' => true, 'message' => '任务已删除'];
            } else {
                $response = ['success' => false, 'message' => '删除失败，请查看服务器错误日志'];
            }
            break;
    }
    
    echo json_encode($response);
    exit;
}

// 获取配置
$config = _r301sitewide_getConfig();
$tasks = $config['tasks'] ?? [];
$globalEnabled = $config['enabled'] ?? false;

// 从 Redis 获取实时统计数据
require_once __DIR__ . '/redis_config.php';
try {
    $redis = getRedis();
    if ($redis) {
        foreach ($tasks as &$task) {
            $taskId = $task['id'];
            $prefix = REDIS_SITEWIDE_PREFIX;
            $statsKey = "{$prefix}task:{$taskId}:stats";
            
            // 从 Redis 读取统计数据
            if ($redis->exists($statsKey)) {
                $redisStats = $redis->hGetAll($statsKey);
                if (!empty($redisStats)) {
                    $task['stats']['total_redirects'] = (int)($redisStats['total_redirects'] ?? 0);
                }
            }
        }
        unset($task); // 解除引用
    }
} catch (Exception $e) {
    // Redis 连接失败，使用 JSON 中的数据
}

require_once __DIR__ . '/header.php';
?>

<style>
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
}

.page-title {
    font-size: 24px;
    font-weight: 600;
    color: var(--text);
}

.switch {
    position: relative;
    width: 50px;
    height: 26px;
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
    background-color: var(--bg-hover);
    transition: .3s;
    border-radius: 26px;
}

.slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}

input:checked + .slider {
    background-color: #10b981;
}

input:checked + .slider:before {
    transform: translateX(24px);
}

.tasks-container {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.task-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
    transition: all 0.2s;
}

.task-card:hover {
    border-color: var(--primary);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1);
}

.task-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}

.task-info {
    flex: 1;
}

.task-name {
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 8px;
}

.task-summary {
    font-size: 13px;
    color: var(--text-muted);
    line-height: 1.6;
}

.task-actions {
    display: flex;
    gap: 8px;
    align-items: center;
}

.task-stats {
    display: flex;
    gap: 20px;
    padding-top: 12px;
    border-top: 1px solid var(--border);
    font-size: 13px;
    color: var(--text-muted);
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 6px;
}

.stat-value {
    font-weight: 600;
    color: var(--text);
}

.badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
}

.badge-success {
    background: #d1fae5;
    color: #065f46;
}

.badge-danger {
    background: #fee2e2;
    color: #991b1b;
}

.badge-completed {
    background: #e0e7ff;
    color: #3730a3;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-muted);
}

.empty-state-icon {
    font-size: 48px;
    margin-bottom: 16px;
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
    max-width: 500px;
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

.form-group {
    margin-bottom: 16px;
}

.form-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: var(--text);
}

.form-input {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg-dark);
    color: var(--text);
    font-size: 14px;
}

.form-input:focus {
    outline: none;
    border-color: var(--primary);
}

.modal-footer {
    display: flex;
    gap: 12px;
    padding: 20px;
    border-top: 1px solid var(--border);
}
</style>

<div class="page-header">
    <h1 class="page-title">🌐 整站重定向</h1>
</div>

<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h3 class="card-title">任务列表</h3>
        <button class="btn btn-primary" onclick="showCreateModal()">
            <span style="margin-right: 6px;">➕</span>
            新建任务
        </button>
    </div>
    
    <?php if (empty($tasks)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">📭</div>
        <p style="font-size: 16px; margin-bottom: 8px;">暂无任务</p>
        <p style="font-size: 14px;">点击「新建任务」创建整站重定向规则</p>
    </div>
    <?php else: ?>
    <div class="tasks-container">
        <?php foreach ($tasks as $task): ?>
        <div class="task-card">
            <div class="task-header">
                <div class="task-info">
                    <div class="task-name">
                        <?php echo htmlspecialchars($task['name']); ?>
                        <?php if ($task['enabled']): ?>
                            <span class="badge badge-success">运行中</span>
                        <?php else: ?>
                            <span class="badge badge-danger">已停止</span>
                        <?php endif; ?>
                    </div>
                    <div class="task-summary">
                        <?php echo htmlspecialchars(_r301sitewide_getSummary($task)); ?>
                    </div>
                </div>
                <div class="task-actions">
                    <label class="switch">
                        <input type="checkbox" <?php echo $task['enabled'] ? 'checked' : ''; ?> 
                               onchange="toggleTask('<?php echo $task['id']; ?>', this.checked)">
                        <span class="slider"></span>
                    </label>
                    <button class="btn btn-secondary btn-sm" onclick="editSpiderFilter('<?php echo $task['id']; ?>')">
                        🕷️ 爬虫
                    </button>
                    <a href="sitewide_task.php?id=<?php echo $task['id']; ?>" class="btn btn-secondary btn-sm">
                        编辑
                    </a>
                    <button class="btn btn-danger btn-sm" onclick="deleteTask('<?php echo $task['id']; ?>')">
                        删除
                    </button>
                </div>
            </div>
            
            <div class="task-stats">
                <div class="stat-item">
                    <span>源域名:</span>
                    <span class="stat-value"><?php echo count($task['source_domains'] ?? []); ?></span>
                </div>
                <div class="stat-item">
                    <span>目标域名:</span>
                    <span class="stat-value"><?php echo count($task['target_domains'] ?? []); ?></span>
                </div>
                <div class="stat-item">
                    <span>已跳转:</span>
                    <span class="stat-value"><?php echo number_format($task['stats']['total_redirects'] ?? 0); ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- 第一步：选择蜘蛛类型 -->
<div class="modal-overlay" id="createSpiderModal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">第1步：选择蜘蛛类型</h3>
            <button class="modal-close" onclick="hideCreateModal()">&times;</button>
        </div>
        <div class="modal-body">
            <?php renderSpiderSelector('create', false, []); ?>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="hideCreateModal()" style="flex: 1;">取消</button>
            <button class="btn btn-primary" onclick="goToNameStep()" style="flex: 1;">下一步</button>
        </div>
    </div>
</div>

<!-- 第二步：输入任务名称 -->
<div class="modal-overlay" id="createNameModal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">第2步：任务名称</h3>
            <button class="modal-close" onclick="hideCreateModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">任务名称</label>
                <input type="text" id="taskName" class="form-input" placeholder="例如：老站迁移到新站">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="backToSpiderStep()" style="flex: 1;">上一步</button>
            <button class="btn btn-primary" onclick="createTask()" style="flex: 1;">创建</button>
        </div>
    </div>
</div>

<!-- 编辑蜘蛛配置弹窗 -->
<div class="modal-overlay" id="editSpiderModal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">编辑蜘蛛配置</h3>
            <button class="modal-close" onclick="hideEditSpiderModal()">&times;</button>
        </div>
        <div class="modal-body" id="editSpiderContent">
            <!-- 动态加载 -->
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="hideEditSpiderModal()" style="flex: 1;">取消</button>
            <button class="btn btn-primary" onclick="saveSpiderFilter()" style="flex: 1;">保存配置</button>
        </div>
    </div>
</div>

<script>
// 全局变量存储当前编辑的任务ID
let currentEditTaskId = null;

// 显示创建弹窗
function showCreateModal() {
    document.getElementById('createSpiderModal').style.display = 'flex';
    document.getElementById('taskName').value = '';
}

// 隐藏创建弹窗
function hideCreateModal() {
    document.getElementById('createSpiderModal').style.display = 'none';
    document.getElementById('createNameModal').style.display = 'none';
}

// 隐藏编辑蜘蛛弹窗
function hideEditSpiderModal() {
    document.getElementById('editSpiderModal').style.display = 'none';
}

// 进入第二步
function goToNameStep() {
    document.getElementById('createSpiderModal').style.display = 'none';
    document.getElementById('createNameModal').style.display = 'flex';
    document.getElementById('taskName').focus();
}

// 返回第一步
function backToSpiderStep() {
    document.getElementById('createNameModal').style.display = 'none';
    document.getElementById('createSpiderModal').style.display = 'flex';
}

// 创建任务
function createTask() {
    const name = document.getElementById('taskName').value.trim();
    
    if (!name) {
        alert('请输入任务名称');
        return;
    }
    
    // 获取蜘蛛筛选配置
    const spiderConfig = getSpiderFilterConfig('create');
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'create');
    formData.append('name', name);
    formData.append('spider_filter_enabled', spiderConfig.enabled ? '1' : '');
    formData.append('spider_type_baidu_pc', spiderConfig.types.baidu_pc ? '1' : '');
    formData.append('spider_type_baidu_mobile', spiderConfig.types.baidu_mobile ? '1' : '');
    formData.append('spider_type_google', spiderConfig.types.google ? '1' : '');
    formData.append('spider_type_sogou', spiderConfig.types.sogou ? '1' : '');
    
    fetch('sitewide.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.href = 'sitewide_task.php?id=' + data.task_id;
        } else {
            alert(data.message);
        }
    })
    .catch(err => alert('创建失败：' + err.message));
}

// 编辑蜘蛛配置
function editSpiderFilter(taskId) {
    currentEditTaskId = taskId;
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'render_spider_selector_edit_mode');
    formData.append('task_id', taskId);
    
    fetch('sitewide.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('editSpiderContent').innerHTML = data.html;
            document.getElementById('editSpiderModal').style.display = 'flex';
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
function saveSpiderFilter() {
    if (!currentEditTaskId) {
        alert('任务ID丢失');
        return;
    }
    
    const spiderConfig = getSpiderFilterConfigEditMode('edit');
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'update_spider_filter');
    formData.append('task_id', currentEditTaskId);
    formData.append('spider_filter_enabled', '1');
    formData.append('spider_type_baidu_pc', spiderConfig.types.baidu_pc ? '1' : '');
    formData.append('spider_type_baidu_mobile', spiderConfig.types.baidu_mobile ? '1' : '');
    formData.append('spider_type_google', spiderConfig.types.google ? '1' : '');
    formData.append('spider_type_sogou', spiderConfig.types.sogou ? '1' : '');
    
    fetch('sitewide.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            hideEditSpiderModal();
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

// 切换任务开关
function toggleTask(taskId, enabled) {
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'toggle');
    formData.append('task_id', taskId);
    formData.append('enabled', enabled ? '1' : '0');
    
    fetch('sitewide.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message);
        }
    })
    .catch(err => alert('操作失败：' + err.message));
}

// 删除任务
function deleteTask(taskId) {
    if (!confirm('确定要删除这个任务吗？此操作无法恢复！')) return;
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'delete');
    formData.append('task_id', taskId);
    
    fetch('sitewide.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message);
        }
    })
    .catch(err => alert('删除失败：' + err.message));
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

