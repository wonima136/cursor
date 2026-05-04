<?php
/**
 * 大站池任务列表
 */
$pageTitle = '大站池任务 - 301重定向管理系统';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bigsite_task_functions.php';
require_once __DIR__ . '/redis_config.php';
require_once __DIR__ . '/spider_selector.php';

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    // 检查登录
    if (!checkLogin()) {
        echo json_encode(['success' => false, 'message' => '未登录']);
        exit;
    }
    
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => '未知操作'];
    
    switch ($action) {
        case 'create':
            $name = trim($_POST['name'] ?? '');
            if (empty($name)) {
                $response = ['success' => false, 'message' => '请输入任务名称'];
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
                
                $taskData = [
                    'name' => $name,
                    'spider_filter' => $spiderFilter
                ];
                
                $task = _bigsiteTask_create($taskData);
                $response = ['success' => true, 'message' => '任务创建成功', 'task_id' => $task['id']];
            }
            break;
            
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
            
            if (_bigsiteTask_update($taskId, ['spider_filter' => $spiderFilter])) {
                $response = ['success' => true, 'message' => '蜘蛛配置已更新'];
            } else {
                $response = ['success' => false, 'message' => '更新失败'];
            }
            break;
            
        case 'render_spider_selector_edit_mode':
            $taskId = $_POST['task_id'] ?? '';
            $task = _bigsiteTask_getById($taskId);
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
            
        case 'toggle':
            $taskId = $_POST['task_id'] ?? '';
            $enabled = _bigsiteTask_toggle($taskId);
            $response = ['success' => true, 'enabled' => $enabled];
            break;
            
        case 'delete':
            $taskId = $_POST['task_id'] ?? '';
            if (_bigsiteTask_delete($taskId)) {
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
$tasks = _bigsiteTask_getAll();

// 更新每个任务的统计信息
foreach ($tasks as &$task) {
    $task['stats'] = getBigsiteTaskStatsFromRedis($task['id']);
}
unset($task);

require_once __DIR__ . '/header.php';
?>

<div class="page-header">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1 class="page-title">大站池任务</h1>
            <p class="page-subtitle">管理高权重网站跳转任务，每个任务独立配置规则</p>
        </div>
        <button class="btn btn-primary" onclick="showCreateModal()">
            ➕ 新建任务
        </button>
    </div>
</div>

<?php if (empty($tasks)): ?>
<div class="card">
    <div class="empty-state">
        <div class="empty-state-icon">📋</div>
        <h3>还没有任务</h3>
        <p>创建第一个大站池任务，开始管理高权重网站跳转</p>
        <button class="btn btn-primary" onclick="showCreateModal()">➕ 新建任务</button>
    </div>
</div>
<?php else: ?>
<div style="display: grid; gap: 16px;">
    <?php foreach ($tasks as $task): ?>
    <div class="card" style="padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 20px;">
            <!-- 左侧：任务信息 -->
            <div style="flex: 1;">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
                    <h3 style="margin: 0; font-size: 18px; color: var(--text);">
                        <?php echo htmlspecialchars($task['name']); ?>
                    </h3>
                    <label class="switch" style="margin: 0;">
                        <input type="checkbox" 
                               <?php echo $task['enabled'] ? 'checked' : ''; ?>
                               onchange="toggleTask('<?php echo $task['id']; ?>', this.checked)">
                        <span class="switch-slider"></span>
                    </label>
                    <span class="badge <?php echo $task['enabled'] ? 'badge-success' : 'badge-warning'; ?>">
                        <?php echo $task['enabled'] ? '已启用' : '已禁用'; ?>
                    </span>
                </div>
                
                <div style="display: flex; gap: 24px; margin-top: 12px; font-size: 13px; color: var(--text-muted);">
                    <div>
                        <span style="color: var(--primary);">🔗 <?php echo $task['stats']['total_rules']; ?></span> 条规则
                    </div>
                    <div>
                        <span style="color: var(--success);">✅ <?php echo $task['stats']['completed_rules']; ?></span> 已完成
                    </div>
                    <div>
                        <span style="color: var(--info);">📊 <?php echo $task['stats']['total_redirects']; ?></span> 次跳转
                    </div>
                    <div>
                        创建于 <?php echo $task['created_at']; ?>
                    </div>
                </div>
            </div>
            
            <!-- 右侧：操作按钮 -->
            <div style="display: flex; gap: 8px;">
                <button class="btn btn-secondary btn-sm" onclick="editSpiderFilter('<?php echo $task['id']; ?>')">
                    🕷️ 爬虫
                </button>
                <a href="bigsite_task.php?id=<?php echo $task['id']; ?>" class="btn btn-primary btn-sm">
                    ⚙️ 管理
                </a>
                <button class="btn btn-danger btn-sm" onclick="deleteTask('<?php echo $task['id']; ?>', '<?php echo htmlspecialchars($task['name'], ENT_QUOTES); ?>')">
                    🗑️ 删除
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 第一步：选择蜘蛛类型 -->
<div class="modal-overlay" id="createSpiderModal">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">第1步：选择蜘蛛类型</h3>
            <button class="modal-close" onclick="closeModal('createSpiderModal')">&times;</button>
        </div>
        <div class="modal-body">
            <?php renderSpiderSelector('create', false, []); ?>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('createSpiderModal')">取消</button>
            <button class="btn btn-primary" onclick="goToNameStep()">下一步</button>
        </div>
    </div>
</div>

<!-- 第二步：输入任务名称 -->
<div class="modal-overlay" id="createNameModal">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">第2步：任务名称</h3>
            <button class="modal-close" onclick="closeModal('createNameModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">任务名称 <span style="color: #ef4444;">*</span></label>
                <input type="text" id="taskName" class="form-input" placeholder="例如：首页推广、产品页导流" autofocus>
                <p class="form-hint">用于标识这个任务的用途</p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="backToSpiderStep()">上一步</button>
            <button class="btn btn-primary" onclick="createTask()">创建</button>
        </div>
    </div>
</div>

<!-- 编辑蜘蛛配置弹窗 -->
<div class="modal-overlay" id="editSpiderModal">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">编辑蜘蛛配置</h3>
            <button class="modal-close" onclick="closeModal('editSpiderModal')">&times;</button>
        </div>
        <div class="modal-body" id="editSpiderContent">
            <!-- 动态加载 -->
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('editSpiderModal')">取消</button>
            <button class="btn btn-primary" onclick="saveSpiderFilter()">保存配置</button>
        </div>
    </div>
</div>

<script>
// 全局变量存储当前编辑的任务ID
let currentEditTaskId = null;

function showCreateModal() {
    document.getElementById('taskName').value = '';
    showModal('createSpiderModal');
}

function goToNameStep() {
    closeModal('createSpiderModal');
    showModal('createNameModal');
}

function backToSpiderStep() {
    closeModal('createNameModal');
    showModal('createSpiderModal');
}

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
    
    fetch('bigsite_tasks.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.href = 'bigsite_task.php?id=' + data.task_id;
        } else {
            alert(data.message);
        }
    });
}

function editSpiderFilter(taskId) {
    currentEditTaskId = taskId;
    
    // 通过 AJAX 加载编辑模式的蜘蛛选择器
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'render_spider_selector_edit_mode');
    formData.append('task_id', taskId);
    
    fetch('bigsite_tasks.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('editSpiderContent').innerHTML = data.html;
            showModal('editSpiderModal');
        } else {
            alert(data.message || '加载失败');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        alert('加载失败');
    });
}

function saveSpiderFilter() {
    if (!currentEditTaskId) {
        alert('任务ID丢失');
        return;
    }
    
    // 获取编辑模式的蜘蛛配置
    const spiderConfig = getSpiderFilterConfigEditMode('edit');
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'update_spider_filter');
    formData.append('task_id', currentEditTaskId);
    formData.append('spider_filter_enabled', '1'); // 编辑模式默认启用
    formData.append('spider_type_baidu_pc', spiderConfig.types.baidu_pc ? '1' : '');
    formData.append('spider_type_baidu_mobile', spiderConfig.types.baidu_mobile ? '1' : '');
    formData.append('spider_type_google', spiderConfig.types.google ? '1' : '');
    formData.append('spider_type_sogou', spiderConfig.types.sogou ? '1' : '');
    
    fetch('bigsite_tasks.php', {
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

// 从 spider_selector.php 引入的函数（确保全局可用）
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

function toggleTask(taskId, enabled) {
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'toggle');
    formData.append('task_id', taskId);
    
    fetch('bigsite_tasks.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}

function deleteTask(taskId, taskName) {
    if (!confirm(`确定删除任务"${taskName}"？\n\n这将删除该任务的所有规则，此操作不可恢复！`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'delete');
    formData.append('task_id', taskId);
    
    fetch('bigsite_tasks.php', {
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

function showModal(id) {
    document.getElementById(id).classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

