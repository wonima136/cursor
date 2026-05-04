<?php
/**
 * 消耗池任务管理
 */
$pageTitle = '消耗池任务 - 301重定向管理系统';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/task_functions.php';

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => '未知操作'];
    
    switch ($action) {
        // 渲染蜘蛛筛选编辑模式卡片
        case 'render_spider_edit':
            require_once __DIR__ . '/spider_selector.php';
            
            $config = json_decode($_POST['config'] ?? '{}', true);
            $types = $config['types'] ?? [];
            
            ob_start();
            renderSpiderSelectorEditMode('edit', $types);
            $html = ob_get_clean();
            
            echo $html;
            exit;
        
        // 创建新任务
        case 'create':
            $name = trim($_POST['name'] ?? '');
            if (empty($name)) {
                $response = ['success' => false, 'message' => '请输入任务名称'];
                break;
            }
            
            // 解析蜘蛛筛选配置
            $spiderFilter = null;
            if (isset($_POST['spider_filter'])) {
                $spiderFilter = json_decode($_POST['spider_filter'], true);
            }
            
            $data = ['name' => $name];
            if ($spiderFilter !== null) {
                $data['spider_filter'] = $spiderFilter;
            }
            
            $taskId = _r301task_create($data);
            if ($taskId) {
                $response = ['success' => true, 'message' => '任务创建成功', 'task_id' => $taskId];
            } else {
                $response = ['success' => false, 'message' => '创建失败'];
            }
            break;
        
        // 更新蜘蛛筛选配置
        case 'update_spider_filter':
            $taskId = $_POST['task_id'] ?? '';
            $spiderFilter = null;
            if (isset($_POST['spider_filter'])) {
                $spiderFilter = json_decode($_POST['spider_filter'], true);
            }
            
            // 调试日志
            error_log("更新蜘蛛配置 - 任务ID: {$taskId}");
            error_log("更新蜘蛛配置 - 配置数据: " . json_encode($spiderFilter));
            
            if (empty($taskId)) {
                $response = ['success' => false, 'message' => '任务ID无效'];
                break;
            }
            
            if ($spiderFilter === null) {
                $response = ['success' => false, 'message' => '配置数据无效'];
                break;
            }
            
            if (_r301task_update($taskId, ['spider_filter' => $spiderFilter])) {
                $response = ['success' => true, 'message' => '蜘蛛筛选配置已更新'];
                error_log("更新蜘蛛配置 - 成功");
            } else {
                $response = ['success' => false, 'message' => '更新失败'];
                error_log("更新蜘蛛配置 - 失败");
            }
            break;
        
        // 从远程链接导入任务
        case 'import_from_url':
            $url = trim($_POST['url'] ?? '');
            if (empty($url)) {
                $response = ['success' => false, 'message' => '请输入远程JSON链接'];
                break;
            }
            
            $result = _r301task_importFromUrl($url);
            $response = $result;
            break;
        
        // 从上传的文件导入任务
        case 'import_from_file':
            if (!isset($_FILES['config_file']) || $_FILES['config_file']['error'] !== UPLOAD_ERR_OK) {
                $response = ['success' => false, 'message' => '文件上传失败'];
                break;
            }
            
            $fileContent = file_get_contents($_FILES['config_file']['tmp_name']);
            $importData = json_decode($fileContent, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $response = ['success' => false, 'message' => 'JSON解析失败：' . json_last_error_msg()];
                break;
            }
            
            $result = _r301task_import($importData);
            $response = $result;
            break;
            
        // 切换任务开关
        case 'toggle':
            $taskId = $_POST['task_id'] ?? '';
            $enabled = $_POST['enabled'] === '1';
            
            if (_r301task_toggle($taskId, $enabled)) {
                $response = ['success' => true, 'message' => $enabled ? '任务已启用' : '任务已停止'];
            } else {
                $response = ['success' => false, 'message' => '操作失败'];
            }
            break;
            
        // 删除任务
        case 'delete':
            $taskId = $_POST['task_id'] ?? '';
            
            if (_r301task_delete($taskId)) {
                $response = ['success' => true, 'message' => '任务已删除'];
            } else {
                $response = ['success' => false, 'message' => '删除失败'];
            }
            break;
            
    }
    
    echo json_encode($response);
    exit;
}

// 获取所有任务
$tasks = _r301task_getAll();

// 为每个任务从 Redis 获取最新统计数据
require_once __DIR__ . '/redis_config.php';
foreach ($tasks as &$task) {
    $redisStats = getTaskStatsFromRedis($task['id']);
    if ($redisStats) {
        // 使用 Redis 中的统计数据覆盖 JSON 中的数据
        $task['stats']['total_links'] = $redisStats['total_links'];
        $task['stats']['available_links'] = $redisStats['available_links'];
        $task['stats']['total_redirects'] = $redisStats['total_redirects'];
        $task['stats']['remaining_links'] = $redisStats['available_links']; // 兼容旧字段名
    }
}
unset($task); // 解除引用

require_once __DIR__ . '/header.php';
?>

<style>
.task-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.task-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
    transition: all 0.3s ease;
}

.task-card:hover {
    border-color: var(--primary);
    box-shadow: 0 4px 20px rgba(99, 102, 241, 0.15);
}

.task-card.disabled {
    opacity: 0.6;
}

.task-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
}

.task-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.task-priority {
    background: var(--bg-dark);
    color: var(--text-muted);
    font-size: 12px;
    padding: 2px 8px;
    border-radius: 4px;
    font-weight: normal;
}

.task-status {
    display: flex;
    align-items: center;
    gap: 8px;
}

.status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}

.status-badge.running {
    background: rgba(34, 197, 94, 0.15);
    color: #22c55e;
}

.status-badge.stopped {
    background: rgba(239, 68, 68, 0.15);
    color: #ef4444;
}

.status-badge.completed {
    background: rgba(34, 197, 94, 0.15);
    color: #22c55e;
}

.task-conditions {
    background: var(--bg-dark);
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 16px;
    font-size: 13px;
    color: var(--text-muted);
}

.task-conditions strong {
    color: var(--text);
}

.task-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 16px;
}

.stat-item {
    text-align: center;
    padding: 12px;
    background: var(--bg-dark);
    border-radius: 8px;
}

.stat-value {
    font-size: 20px;
    font-weight: 600;
    color: var(--text);
}

.stat-label {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 4px;
}

.stat-item.warning .stat-value {
    color: var(--warning);
}

.stat-item.success .stat-value {
    color: var(--success);
}

.task-actions {
    display: flex;
    gap: 10px;
    padding-top: 16px;
    border-top: 1px solid var(--border);
}

.task-actions .btn {
    flex: 1;
    padding: 10px;
    font-size: 13px;
}

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

/* 新建任务模态框 */
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
    max-width: 450px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.modal-title {
    font-size: 18px;
    font-weight: 600;
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
</style>

<div class="page-header">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1 class="page-title">消耗池任务</h1>
            <p class="page-subtitle">管理链接消耗跳转任务，每个任务独立配置触发条件和链接池</p>
        </div>
        <div style="display: flex; gap: 10px;">
            <button class="btn btn-secondary" onclick="showImportModal()">
                📥 远程导入
            </button>
            <button class="btn btn-primary" onclick="showCreateModal()">
                ➕ 新建任务
            </button>
        </div>
    </div>
</div>

<?php if (empty($tasks)): ?>
<div class="empty-state">
    <h3>📋 暂无任务</h3>
    <p>创建一个新任务来开始配置链接跳转规则</p>
    <button class="btn btn-primary" onclick="showCreateModal()">➕ 新建任务</button>
</div>
<?php else: ?>
<div class="task-grid">
    <?php foreach ($tasks as $task): ?>
    <div class="task-card <?php echo $task['enabled'] ? '' : 'disabled'; ?>" id="task-<?php echo $task['id']; ?>">
        <div class="task-header">
            <h3 class="task-title">
                <?php echo htmlspecialchars($task['name']); ?>
            </h3>
            <div class="task-status">
                <?php if ($task['enabled']): ?>
                    <span class="status-badge running">🟢 运行中</span>
                <?php elseif (!empty($task['auto_stopped_at'])): ?>
                    <span class="status-badge completed">✅ 已完成</span>
                <?php else: ?>
                    <span class="status-badge stopped">🔴 已停止</span>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="task-conditions">
            <strong>触发条件：</strong><?php echo htmlspecialchars(_r301task_getConditionSummary($task)); ?>
        </div>
        
        <div class="task-stats">
            <div class="stat-item">
                <div class="stat-value"><?php echo $task['stats']['total_links'] ?? 0; ?></div>
                <div class="stat-label">总链接数</div>
            </div>
            <div class="stat-item warning">
                <div class="stat-value"><?php echo $task['stats']['remaining_links'] ?? 0; ?></div>
                <div class="stat-label">剩余可用</div>
            </div>
            <div class="stat-item success">
                <div class="stat-value"><?php echo $task['stats']['total_redirects'] ?? 0; ?></div>
                <div class="stat-label">已跳转</div>
            </div>
        </div>
        
        <div class="task-actions">
            <button class="btn btn-secondary" onclick="toggleTask('<?php echo $task['id']; ?>', <?php echo $task['enabled'] ? 'false' : 'true'; ?>)">
                <?php echo $task['enabled'] ? '⏸️ 停止' : '▶️ 启动'; ?>
            </button>
            <button class="btn btn-secondary" onclick='editSpiderFilter(<?php echo json_encode($task['id']); ?>, <?php echo json_encode($task['spider_filter'] ?? ['enabled' => false, 'types' => []]); ?>)' title="编辑蜘蛛筛选">
                🕷️ 爬虫
            </button>
            <a href="task.php?id=<?php echo $task['id']; ?>" class="btn btn-primary">
                ⚙️ 配置
            </a>
            <button class="btn btn-danger" onclick="deleteTask('<?php echo $task['id']; ?>', '<?php echo htmlspecialchars($task['name']); ?>')">
                🗑️ 删除
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 新建任务模态框 - 第一步：配置蜘蛛筛选 -->
<div class="modal-overlay" id="createModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">➕ 新建任务 - 第1步：配置蜘蛛筛选</h3>
            <button class="modal-close" onclick="hideCreateModal()">&times;</button>
        </div>
        
        <div id="createStep1">
            <div style="background: var(--warning-bg); border: 1px solid var(--warning); border-radius: 8px; padding: 12px; margin-bottom: 20px;">
                <p style="margin: 0; font-size: 13px; color: var(--warning);">
                    💡 <strong>提示：</strong>先配置蜘蛛筛选，避免遗漏重要设置
                </p>
            </div>
            
            <?php 
            // 包含蜘蛛筛选卡片（默认配置）
            require_once __DIR__ . '/spider_selector.php';
            renderSpiderSelector('create', false, []);
            ?>
            
            <div style="display: flex; gap: 12px; margin-top: 20px;">
                <button type="button" class="btn btn-secondary" onclick="hideCreateModal()" style="flex: 1;">取消</button>
                <button type="button" class="btn btn-primary" onclick="goToCreateStep2()" style="flex: 1;">下一步 →</button>
            </div>
        </div>
        
        <div id="createStep2" style="display: none;">
            <form onsubmit="createTask(event)">
                <div class="form-group">
                    <label class="form-label">任务名称</label>
                    <input type="text" id="taskName" class="form-input" placeholder="例如：百度PC蜘蛛任务" required>
                    <p class="form-hint">给任务起一个容易识别的名称</p>
                </div>
                <div style="display: flex; gap: 12px; margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="backToCreateStep1()" style="flex: 1;">← 上一步</button>
                    <button type="submit" class="btn btn-primary" style="flex: 1;">创建任务</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 编辑蜘蛛配置模态框 -->
<div class="modal-overlay" id="editSpiderModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">编辑蜘蛛筛选配置</h3>
            <button class="modal-close" onclick="hideEditSpiderModal()">&times;</button>
        </div>
        
        <div id="editSpiderContent">
            <!-- 动态加载蜘蛛筛选卡片 -->
        </div>
        
        <div style="display: flex; gap: 12px; margin-top: 20px;">
            <button type="button" class="btn btn-secondary" onclick="hideEditSpiderModal()" style="flex: 1;">取消</button>
            <button type="button" class="btn btn-primary" onclick="saveSpiderFilter()" style="flex: 1;">💾 保存配置</button>
        </div>
    </div>
</div>

<!-- 导入模态框 -->
<div class="modal-overlay" id="importModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">📥 导入任务配置</h3>
            <button class="modal-close" onclick="hideImportModal()">&times;</button>
        </div>
        
        <!-- 导入方式选择 -->
        <div style="display: flex; gap: 10px; margin-bottom: 20px;">
            <button type="button" class="btn btn-secondary import-tab active" onclick="switchImportTab('url')" id="tabUrl">🔗 远程链接</button>
            <button type="button" class="btn btn-secondary import-tab" onclick="switchImportTab('file')" id="tabFile">📁 上传文件</button>
        </div>
        
        <!-- 远程链接导入 -->
        <form onsubmit="importFromUrl(event)" id="formUrl">
            <div class="form-group">
                <label class="form-label">远程 JSON 链接</label>
                <input type="url" id="importUrl" class="form-input" placeholder="https://xxx.com/301/admin/task_export.php?id=xxx">
                <p class="form-hint">从其他服务器的消耗池任务中复制导出链接，粘贴到这里</p>
            </div>
            <div id="importStatusUrl" style="display: none; padding: 12px; border-radius: 8px; margin-top: 16px;"></div>
            <div style="display: flex; gap: 12px; margin-top: 20px;">
                <button type="button" class="btn btn-secondary" onclick="hideImportModal()" style="flex: 1;">取消</button>
                <button type="submit" class="btn btn-primary" id="importBtnUrl" style="flex: 1;">导入任务</button>
            </div>
        </form>
        
        <!-- 文件上传导入 -->
        <form onsubmit="importFromFile(event)" id="formFile" style="display: none;">
            <div class="form-group">
                <label class="form-label">选择配置文件</label>
                <input type="file" id="configFile" class="form-input" accept=".json" style="padding: 10px;">
                <p class="form-hint">选择从其他服务器下载的 .json 配置文件</p>
            </div>
            <div id="importStatusFile" style="display: none; padding: 12px; border-radius: 8px; margin-top: 16px;"></div>
            <div style="display: flex; gap: 12px; margin-top: 20px;">
                <button type="button" class="btn btn-secondary" onclick="hideImportModal()" style="flex: 1;">取消</button>
                <button type="submit" class="btn btn-primary" id="importBtnFile" style="flex: 1;">上传并导入</button>
            </div>
        </form>
    </div>
</div>

<style>
.import-tab {
    flex: 1;
    opacity: 0.6;
}
.import-tab.active {
    opacity: 1;
    background: var(--primary);
    color: #fff;
}
</style>

<script>
// 显示创建模态框
function showCreateModal() {
    document.getElementById('createModal').classList.add('active');
    document.getElementById('taskName').focus();
}

// 隐藏创建模态框
function hideCreateModal() {
    document.getElementById('createModal').classList.remove('active');
    document.getElementById('taskName').value = '';
}

// 创建任务
function createTask(e) {
    e.preventDefault();
    
    const name = document.getElementById('taskName').value.trim();
    if (!name) {
        alert('请输入任务名称');
        return;
    }
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'create');
    formData.append('name', name);
    
    // 获取蜘蛛筛选配置
    const spiderConfig = window.getSpiderFilterConfig ? window.getSpiderFilterConfig('create') : null;
    if (spiderConfig) {
        formData.append('spider_filter', JSON.stringify(spiderConfig));
    }
    
    fetch('tasks.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // 跳转到任务配置页面
            window.location.href = 'task.php?id=' + data.task_id;
        } else {
            alert(data.message);
        }
    })
    .catch(err => {
        alert('操作失败：' + err.message);
    });
}

// 创建任务 - 步骤切换
function goToCreateStep2() {
    document.getElementById('createStep1').style.display = 'none';
    document.getElementById('createStep2').style.display = 'block';
    document.querySelector('#createModal .modal-title').textContent = '➕ 新建任务 - 第2步：输入任务名称';
}

function backToCreateStep1() {
    document.getElementById('createStep2').style.display = 'none';
    document.getElementById('createStep1').style.display = 'block';
    document.querySelector('#createModal .modal-title').textContent = '➕ 新建任务 - 第1步：配置蜘蛛筛选';
}

// 编辑蜘蛛配置
let currentEditTaskId = null;
let currentEditConfig = null;

function editSpiderFilter(taskId, currentConfig) {
    currentEditTaskId = taskId;
    currentEditConfig = currentConfig;
    
    // 调试：查看传入的配置
    console.log('编辑蜘蛛配置 - 任务ID:', taskId);
    console.log('编辑蜘蛛配置 - 当前配置:', currentConfig);
    
    // 使用 AJAX 获取 PHP 渲染的编辑模式卡片
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'render_spider_edit');
    formData.append('config', JSON.stringify(currentConfig));
    
    fetch('tasks.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.text())
    .then(html => {
        document.getElementById('editSpiderContent').innerHTML = html;
        document.getElementById('editSpiderModal').classList.add('active');
    })
    .catch(err => {
        console.error('加载蜘蛛筛选卡片失败:', err);
        alert('加载失败，请重试');
    });
}

function hideEditSpiderModal() {
    document.getElementById('editSpiderModal').classList.remove('active');
    currentEditTaskId = null;
}

// 获取编辑模式的蜘蛛筛选配置
function getSpiderFilterConfigEditMode(id) {
    const baiduPc = document.querySelector('input[name="spider_type_baidu_pc_' + id + '"]');
    const baiduMobile = document.querySelector('input[name="spider_type_baidu_mobile_' + id + '"]');
    const google = document.querySelector('input[name="spider_type_google_' + id + '"]');
    const sogou = document.querySelector('input[name="spider_type_sogou_' + id + '"]');
    
    // 检查元素是否存在
    if (!baiduPc || !baiduMobile || !google || !sogou) {
        console.error('蜘蛛筛选元素未找到，ID前缀:', id);
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
    
    const types = {
        baidu_pc: baiduPc.checked,
        baidu_mobile: baiduMobile.checked,
        google: google.checked,
        sogou: sogou.checked
    };
    
    // 如果任何一个被选中，则enabled为true
    const enabled = types.baidu_pc || types.baidu_mobile || types.google || types.sogou;
    
    return {
        enabled: enabled,
        types: types
    };
}

function saveSpiderFilter() {
    if (!currentEditTaskId) {
        alert('任务ID无效');
        return;
    }
    
    // 使用编辑模式的 API 获取配置
    const spiderConfig = getSpiderFilterConfigEditMode('edit');
    
    // 调试：查看获取到的配置
    console.log('保存蜘蛛配置 - 任务ID:', currentEditTaskId);
    console.log('保存蜘蛛配置 - 配置内容:', spiderConfig);
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'update_spider_filter');
    formData.append('task_id', currentEditTaskId);
    formData.append('spider_filter', JSON.stringify(spiderConfig));
    
    fetch('tasks.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            hideEditSpiderModal();
            location.reload();
        }
    })
    .catch(err => {
        alert('保存失败：' + err.message);
    });
}

// 切换任务开关
function toggleTask(taskId, enabled) {
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'toggle');
    formData.append('task_id', taskId);
    formData.append('enabled', enabled ? '1' : '0');
    
    fetch('tasks.php', {
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
    .catch(err => {
        alert('操作失败：' + err.message);
    });
}

// 删除任务
function deleteTask(taskId, taskName) {
    if (!confirm('确定要删除任务「' + taskName + '」吗？\n\n该操作将同时删除任务的所有链接数据，无法恢复！')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'delete');
    formData.append('task_id', taskId);
    
    fetch('tasks.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('task-' + taskId).remove();
            // 如果没有任务了，刷新页面显示空状态
            if (document.querySelectorAll('.task-card').length === 0) {
                location.reload();
            }
        } else {
            alert(data.message);
        }
    })
    .catch(err => {
        alert('操作失败：' + err.message);
    });
}

// 显示导入模态框
function showImportModal() {
    document.getElementById('importModal').classList.add('active');
    switchImportTab('url');
}

// 隐藏导入模态框
function hideImportModal() {
    document.getElementById('importModal').classList.remove('active');
    document.getElementById('importUrl').value = '';
    document.getElementById('configFile').value = '';
    document.getElementById('importStatusUrl').style.display = 'none';
    document.getElementById('importStatusFile').style.display = 'none';
}

// 切换导入方式
function switchImportTab(tab) {
    document.getElementById('tabUrl').classList.toggle('active', tab === 'url');
    document.getElementById('tabFile').classList.toggle('active', tab === 'file');
    document.getElementById('formUrl').style.display = tab === 'url' ? 'block' : 'none';
    document.getElementById('formFile').style.display = tab === 'file' ? 'block' : 'none';
    
    if (tab === 'url') {
        document.getElementById('importUrl').focus();
    }
}

// 从远程URL导入任务
function importFromUrl(e) {
    e.preventDefault();
    
    const url = document.getElementById('importUrl').value.trim();
    if (!url) {
        alert('请输入远程JSON链接');
        return;
    }
    
    const btn = document.getElementById('importBtnUrl');
    const status = document.getElementById('importStatusUrl');
    
    btn.disabled = true;
    btn.textContent = '正在导入...';
    status.style.display = 'block';
    status.style.background = 'var(--bg-dark)';
    status.style.color = 'var(--text-muted)';
    status.textContent = '正在获取远程数据...';
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'import_from_url');
    formData.append('url', url);
    
    fetch('tasks.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        handleImportResult(data, btn, status, '导入任务');
    })
    .catch(err => {
        showImportError(status, btn, '请求失败：' + err.message, '导入任务');
    });
}

// 从文件导入任务
function importFromFile(e) {
    e.preventDefault();
    
    const fileInput = document.getElementById('configFile');
    if (!fileInput.files || !fileInput.files[0]) {
        alert('请选择配置文件');
        return;
    }
    
    const btn = document.getElementById('importBtnFile');
    const status = document.getElementById('importStatusFile');
    
    btn.disabled = true;
    btn.textContent = '正在上传...';
    status.style.display = 'block';
    status.style.background = 'var(--bg-dark)';
    status.style.color = 'var(--text-muted)';
    status.textContent = '正在解析配置文件...';
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'import_from_file');
    formData.append('config_file', fileInput.files[0]);
    
    fetch('tasks.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        handleImportResult(data, btn, status, '上传并导入');
    })
    .catch(err => {
        showImportError(status, btn, '上传失败：' + err.message, '上传并导入');
    });
}

// 处理导入结果
function handleImportResult(data, btn, status, btnText) {
    if (data.success) {
        status.style.background = 'rgba(34, 197, 94, 0.15)';
        status.style.color = '#22c55e';
        status.textContent = '✅ ' + data.message;
        
        setTimeout(function() {
            window.location.href = 'task.php?id=' + data.task_id;
        }, 1500);
    } else {
        showImportError(status, btn, data.message, btnText);
    }
}

// 显示导入错误
function showImportError(status, btn, message, btnText) {
    status.style.background = 'rgba(239, 68, 68, 0.15)';
    status.style.color = '#ef4444';
    status.textContent = '❌ ' + message;
    btn.disabled = false;
    btn.textContent = btnText;
}

// 点击模态框外部关闭
document.getElementById('createModal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideCreateModal();
    }
});

document.getElementById('importModal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideImportModal();
    }
});

// ESC 键关闭模态框
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        hideCreateModal();
        hideImportModal();
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

