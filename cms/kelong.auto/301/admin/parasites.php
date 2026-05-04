<?php
/**
 * 寄生重定向 - 任务列表
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/parasite_functions.php';
require_once __DIR__ . '/spider_selector.php';

// 检查登录
if (!checkLogin()) {
    header('Location: login.php');
    exit;
}

$pageTitle = '寄生重定向 - 301重定向管理系统';

// 处理AJAX请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
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
            
            $taskId = _r301parasite_create([
                'name' => $_POST['name'] ?? '未命名任务',
                'manage_type' => $_POST['manage_type'] ?? 'directory',
                'source_path' => $_POST['source_path'] ?? '',
                'source_domain' => $_POST['source_domain'] ?? '',
                'redirect_mode' => $_POST['redirect_mode'] ?? 'focus',
                'spider_filter' => $spiderFilter,
            ]);
            echo json_encode(['success' => true, 'task_id' => $taskId]);
            exit;
            
        case 'delete':
            $taskId = $_POST['task_id'] ?? '';
            _r301parasite_delete($taskId);
            
            // 重建 Redis 域名索引
            require_once __DIR__ . '/domain_index.php';
            rebuildDomainIndex();
            
            echo json_encode(['success' => true]);
            exit;
            
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
            
            if (_r301parasite_update($taskId, ['spider_filter' => $spiderFilter])) {
                echo json_encode(['success' => true, 'message' => '蜘蛛配置已更新']);
            } else {
                echo json_encode(['success' => false, 'message' => '更新失败']);
            }
            exit;
            
        // 渲染编辑模式的蜘蛛选择器
        case 'render_spider_selector_edit_mode':
            $taskId = $_POST['task_id'] ?? '';
            $task = _r301parasite_getById($taskId);
            if ($task) {
                $types = $task['spider_filter']['types'] ?? [];
                ob_start();
                renderSpiderSelectorEditMode('edit', $types);
                $html = ob_get_clean();
                echo json_encode(['success' => true, 'html' => $html]);
            } else {
                echo json_encode(['success' => false, 'message' => '任务不存在']);
            }
            exit;
            
        case 'toggle':
            $taskId = $_POST['task_id'] ?? '';
            $enabled = $_POST['enabled'] === '1';
            _r301parasite_toggle($taskId, $enabled);
            echo json_encode(['success' => true]);
            exit;
    }
    
    echo json_encode(['success' => false, 'message' => '未知操作']);
    exit;
}

$tasks = _r301parasite_getAll();

// 从 Redis 获取实时统计数据
require_once __DIR__ . '/redis_config.php';
try {
    $redis = getRedis();
    if ($redis) {
        foreach ($tasks as &$task) {
            $taskId = $task['id'];
            $prefix = REDIS_PARASITE_PREFIX;
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

include 'header.php';
?>

<style>
.task-grid {
    display: grid;
    gap: 16px;
}
.task-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
    display: grid;
    grid-template-columns: auto 1fr auto auto auto auto;
    align-items: center;
    gap: 20px;
}
.task-card:hover {
    border-color: var(--primary);
}
.task-type {
    font-size: 28px;
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg-dark);
    border-radius: 10px;
}
.task-info {
    min-width: 0;
}
.task-name {
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 4px;
}
.task-name a {
    color: inherit;
    text-decoration: none;
}
.task-name a:hover {
    color: var(--primary-light);
}
.task-meta {
    font-size: 13px;
    color: var(--text-muted);
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
}
.task-stat {
    text-align: center;
    padding: 0 16px;
}
.task-stat-value {
    font-size: 20px;
    font-weight: 600;
    color: var(--text);
}
.task-stat-label {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 2px;
}
.task-status {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
}
.task-status.running {
    background: rgba(34, 197, 94, 0.2);
    color: var(--success);
}
.task-status.stopped {
    background: rgba(239, 68, 68, 0.2);
    color: var(--error);
}
.task-actions {
    display: flex;
    gap: 8px;
}
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-muted);
}
.empty-state h3 {
    color: var(--text);
    margin-bottom: 8px;
}
.mode-badge {
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 12px;
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

/* 创建任务弹窗 */
.create-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}
.create-modal.show {
    display: flex;
}
.create-modal-content {
    background: var(--bg-card);
    border-radius: 16px;
    width: 90%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
}
.create-modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.create-modal-header h3 {
    font-size: 18px;
    color: var(--text);
}
.create-modal-close {
    background: none;
    border: none;
    font-size: 24px;
    color: var(--text-muted);
    cursor: pointer;
}
.create-modal-body {
    padding: 24px;
}
.step-indicator {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-bottom: 24px;
}
.step-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--border);
}
.step-dot.active {
    background: var(--primary);
}
.step-content {
    display: none;
}
.step-content.active {
    display: block;
}
.type-selector {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.type-option {
    padding: 16px;
    background: var(--bg-dark);
    border: 2px solid var(--border);
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.2s;
}
.type-option:hover {
    border-color: var(--primary);
}
.type-option.selected {
    border-color: var(--primary);
    background: rgba(99, 102, 241, 0.1);
}
.type-option h4 {
    font-size: 15px;
    color: var(--text);
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.type-option p {
    font-size: 13px;
    color: var(--text-muted);
}
.create-modal-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
}
</style>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
    <div>
        <h1 class="page-title">寄生重定向</h1>
        <p class="page-subtitle">管理寄生目录的权重转移和跳转规则</p>
    </div>
    <button class="btn btn-primary" onclick="showCreateModal()">+ 新建任务</button>
</div>

<?php if (empty($tasks)): ?>
<div class="card">
    <div class="empty-state">
        <h3>📁 暂无寄生重定向任务</h3>
        <p>创建任务来管理寄生目录的跳转规则</p>
        <button class="btn btn-primary" style="margin-top: 16px;" onclick="showCreateModal()">+ 新建任务</button>
    </div>
</div>
<?php else: ?>
<div class="task-grid">
    <?php foreach ($tasks as $task): 
        $summary = _r301parasite_getSummary($task);
    ?>
    <div class="task-card" data-id="<?php echo $task['id']; ?>">
        <div class="task-type">
            <?php echo $task['manage_type'] === 'directory' ? '📁' : '🌐'; ?>
        </div>
        <div class="task-info">
            <div class="task-name">
                <a href="parasite.php?id=<?php echo $task['id']; ?>"><?php echo htmlspecialchars($task['name']); ?></a>
            </div>
            <div class="task-meta">
                <span><?php echo htmlspecialchars($summary['source']); ?></span>
                <?php if ($task['manage_type'] === 'directory'): ?>
                <span class="mode-badge <?php echo $task['redirect_mode']; ?>">
                    <?php echo $summary['mode']; ?>
                </span>
                <?php endif; ?>
                <span>→ <?php echo htmlspecialchars($summary['target']); ?></span>
            </div>
        </div>
        <div class="task-stat">
            <div class="task-stat-value"><?php echo number_format($task['stats']['total_redirects'] ?? 0); ?></div>
            <div class="task-stat-label">已跳转</div>
        </div>
        <div class="task-status <?php echo $task['enabled'] ? 'running' : 'stopped'; ?>">
            <?php echo $task['enabled'] ? '✅ 运行中' : '⏸️ 已停止'; ?>
        </div>
        <div class="task-actions">
            <button class="btn btn-sm btn-secondary" onclick="editSpiderFilter('<?php echo $task['id']; ?>')">🕷️ 爬虫</button>
            <a href="parasite.php?id=<?php echo $task['id']; ?>" class="btn btn-sm btn-secondary">编辑</a>
            <?php if ($task['enabled']): ?>
            <button class="btn btn-sm btn-warning" onclick="toggleTask('<?php echo $task['id']; ?>', false)">停止</button>
            <?php else: ?>
            <button class="btn btn-sm btn-success" onclick="toggleTask('<?php echo $task['id']; ?>', true)">启动</button>
            <?php endif; ?>
            <button class="btn btn-sm btn-danger" onclick="deleteTask('<?php echo $task['id']; ?>')">删除</button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 创建任务弹窗 -->
<div class="create-modal" id="createModal">
    <div class="create-modal-content">
        <div class="create-modal-header">
            <h3>新建寄生重定向任务</h3>
            <button class="create-modal-close" onclick="hideCreateModal()">&times;</button>
        </div>
        <div class="create-modal-body">
            <div class="step-indicator">
                <div class="step-dot active" data-step="1"></div>
                <div class="step-dot" data-step="2"></div>
            </div>
            
            <!-- 步骤1：选择蜘蛛类型 -->
            <div class="step-content active" data-step="1">
                <?php renderSpiderSelector('create', false, []); ?>
            </div>
            
            <!-- 步骤2：填写任务名称 -->
            <div class="step-content" data-step="2">
                <form id="createForm">
                    <input type="hidden" name="manage_type" id="manageType" value="directory">
                    <input type="hidden" name="redirect_mode" id="redirectMode" value="focus">
                    
                    <div class="form-group">
                        <label class="form-label">任务名称</label>
                        <input type="text" name="name" class="form-input" placeholder="如：小说目录转移" required>
                        <p class="form-hint">创建后可在任务详情页配置源目录、跳转模式等详细设置</p>
                    </div>
                </form>
            </div>
        </div>
        <div class="create-modal-footer">
            <button class="btn btn-secondary" id="prevBtn" onclick="prevStep()" style="display: none;">上一步</button>
            <div></div>
            <button class="btn btn-primary" id="nextBtn" onclick="nextStep()">下一步</button>
        </div>
    </div>
</div>

<!-- 编辑蜘蛛配置弹窗 -->
<div class="modal-overlay" id="editSpiderModal">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">编辑蜘蛛配置</h3>
            <button class="modal-close" onclick="hideEditSpiderModal()">&times;</button>
        </div>
        <div class="modal-body" id="editSpiderContent">
            <!-- 动态加载 -->
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="hideEditSpiderModal()">取消</button>
            <button class="btn btn-primary" onclick="saveSpiderFilter()">保存配置</button>
        </div>
    </div>
</div>

<script>
// ⭐ 两步式创建任务逻辑
let currentStep = 1;

function showCreateModal() {
    document.getElementById('createModal').classList.add('show');
    currentStep = 1;
    updateStep();
}

function hideCreateModal() {
    document.getElementById('createModal').classList.remove('show');
}

function updateStep() {
    document.querySelectorAll('.step-dot').forEach(el => {
        el.classList.toggle('active', parseInt(el.dataset.step) <= currentStep);
    });
    document.querySelectorAll('.step-content').forEach(el => {
        el.classList.toggle('active', parseInt(el.dataset.step) === currentStep);
    });
    
    document.getElementById('prevBtn').style.display = currentStep > 1 ? '' : 'none';
    document.getElementById('nextBtn').textContent = currentStep === 2 ? '创建任务' : '下一步';
}

function prevStep() {
    if (currentStep > 1) {
        currentStep--;
        updateStep();
    }
}

function nextStep() {
    if (currentStep === 1) {
        currentStep = 2;
        updateStep();
    } else {
        createTask();
    }
}

function createTask() {
    const form = document.getElementById('createForm');
    const formData = new FormData(form);
    formData.append('action', 'create');
    
    // 获取蜘蛛筛选配置
    const spiderConfig = getSpiderFilterConfig('create');
    formData.append('spider_filter_enabled', spiderConfig.enabled ? '1' : '');
    formData.append('spider_type_baidu_pc', spiderConfig.types.baidu_pc ? '1' : '');
    formData.append('spider_type_baidu_mobile', spiderConfig.types.baidu_mobile ? '1' : '');
    formData.append('spider_type_google', spiderConfig.types.google ? '1' : '');
    formData.append('spider_type_sogou', spiderConfig.types.sogou ? '1' : '');
    
    fetch('parasites.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.href = 'parasite.php?id=' + data.task_id;
        } else {
            alert(data.message || '创建失败');
        }
    });
}

function toggleTask(taskId, enabled) {
    fetch('parasites.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=toggle&task_id=${taskId}&enabled=${enabled ? 1 : 0}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}

function deleteTask(taskId) {
    if (!confirm('确定要删除这个任务吗？此操作不可恢复！')) {
        return;
    }
    
    fetch('parasites.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=delete&task_id=${taskId}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}

// 全局变量存储当前编辑的任务ID
let currentEditTaskId = null;

// 隐藏编辑蜘蛛弹窗
function hideEditSpiderModal() {
    document.getElementById('editSpiderModal').classList.remove('active');
    document.getElementById('editSpiderModal').style.display = 'none';
}

// 编辑蜘蛛配置
function editSpiderFilter(taskId) {
    currentEditTaskId = taskId;
    
    const formData = new FormData();
    formData.append('action', 'render_spider_selector_edit_mode');
    formData.append('task_id', taskId);
    
    fetch('parasites.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('editSpiderContent').innerHTML = data.html;
            document.getElementById('editSpiderModal').classList.add('active');
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
    formData.append('action', 'update_spider_filter');
    formData.append('task_id', currentEditTaskId);
    formData.append('spider_filter_enabled', '1');
    formData.append('spider_type_baidu_pc', spiderConfig.types.baidu_pc ? '1' : '');
    formData.append('spider_type_baidu_mobile', spiderConfig.types.baidu_mobile ? '1' : '');
    formData.append('spider_type_google', spiderConfig.types.google ? '1' : '');
    formData.append('spider_type_sogou', spiderConfig.types.sogou ? '1' : '');
    
    fetch('parasites.php', {
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
</script>

<?php include 'footer.php'; ?>

