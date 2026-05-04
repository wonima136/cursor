<?php
/**
 * 地图重定向任务列表
 */
$pageTitle = '地图重定向 - 301重定向管理系统';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sitemap_functions.php';
require_once __DIR__ . '/redis_config.php';
require_once __DIR__ . '/spider_selector.php';

// 处理 AJAX 请求.
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
        case 'save_module_settings':
            $probability = intval($_POST['probability'] ?? 100);
            $probability = max(1, min(100, $probability)); // 限制在1-100之间
            
            // 保存到配置文件
            $configFile = __DIR__ . '/config.php';
            $configContent = file_get_contents($configFile);
            
            // 检查是否已存在 sitemap_probability 配置
            if (strpos($configContent, "sitemap_probability") !== false) {
                // 更新现有配置（匹配 $config['sitemap_probability'] = 数字; 格式）
                $configContent = preg_replace(
                    "/\\\$config\['sitemap_probability'\]\s*=\s*\d+;/",
                    "\$config['sitemap_probability'] = {$probability};",
                    $configContent
                );
            } else {
                // 添加新配置（在 <?php 后添加）
                $configContent = preg_replace(
                    "/(<\?php\s*\n)/",
                    "$1\n// 地图重定向模块概率\n\$config['sitemap_probability'] = {$probability};\n",
                    $configContent,
                    1
                );
            }
            
            if (file_put_contents($configFile, $configContent)) {
                $response = ['success' => true, 'message' => '模块设置已保存'];
            } else {
                $response = ['success' => false, 'message' => '保存失败'];
            }
            break;
            
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
                
                $task = _sitemap_create($taskData);
                $response = ['success' => true, 'message' => '任务创建成功', 'task_id' => $task['id']];
            }
            break;
            
        case 'delete':
            $taskId = $_POST['task_id'] ?? '';
            if (_sitemap_delete($taskId)) {
                $response = ['success' => true, 'message' => '任务已删除'];
            } else {
                $response = ['success' => false, 'message' => '删除失败'];
            }
            break;
            
        case 'toggle':
            $taskId = $_POST['task_id'] ?? '';
            $enabled = !empty($_POST['enabled']);
            if (_sitemap_toggle($taskId, $enabled)) {
                $response = ['success' => true, 'message' => $enabled ? '任务已启用' : '任务已禁用'];
            } else {
                $response = ['success' => false, 'message' => '操作失败'];
            }
            break;
            
        case 'get_spider_filter':
            $taskId = $_POST['task_id'] ?? '';
            $task = _sitemap_getById($taskId);
            if ($task) {
                $response = [
                    'success' => true,
                    'spider_filter' => $task['spider_filter'] ?? ['enabled' => false, 'types' => []]
                ];
            } else {
                $response = ['success' => false, 'message' => '任务不存在'];
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
            
            if (_sitemap_update($taskId, ['spider_filter' => $spiderFilter])) {
                $response = ['success' => true, 'message' => '蜘蛛筛选配置已更新'];
            } else {
                $response = ['success' => false, 'message' => '更新失败'];
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

// 获取所有任务
$tasks = _sitemap_getAll();

// 为每个任务加载统计数据
foreach ($tasks as &$task) {
    $task['stats'] = getSitemapTaskStats($task['id']);
}
unset($task);

require_once __DIR__ . '/header.php';
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

.form-hint {
    margin-top: 6px;
    font-size: 12px;
    color: var(--text-muted);
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

/* 按钮样式 */
.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-sm {
    padding: 6px 12px;
    font-size: 12px;
    border-radius: 6px;
}

.btn-primary {
    background: var(--primary);
    color: white;
}

.btn-primary:hover {
    background: var(--primary-hover);
}

.btn-secondary {
    background: var(--bg-dark);
    color: var(--text);
    border: 1px solid var(--border);
}

.btn-secondary:hover {
    background: var(--bg-hover);
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
    background: #f59e0b;
    color: white;
}

.btn-warning:hover {
    background: #d97706;
}

.btn-info {
    background: #3b82f6;
    color: white;
}

.btn-info:hover {
    background: #2563eb;
}

</style>

<div class="content-wrapper">
    <!-- 页面头部 -->
    <div class="page-header">
        <div>
            <h1 class="page-title">地图重定向</h1>
            <p class="page-subtitle">根据比例将流量导向域名首页或地图页内页，智能缓存，自动过滤</p>
        </div>
        <div class="header-actions" style="display: flex; align-items: center; gap: 16px;">
            <form id="moduleSettingsForm" style="display: flex; align-items: center; gap: 12px;">
                <label style="margin: 0; color: var(--text); font-weight: 500; white-space: nowrap; font-size: 14px;">
                    ⚙️ 跳转概率 (%)
                </label>
                <input type="number" 
                       id="moduleProbability" 
                       class="form-control" 
                       value="<?php echo intval($config['sitemap_probability'] ?? 100); ?>" 
                       min="1" 
                       max="100" 
                       style="width: 80px; height: 40px;">
                <button type="submit" class="btn btn-primary" style="white-space: nowrap; height: 40px;">
                    💾 保存
                </button>
            </form>
            <button class="btn btn-primary" onclick="showCreateModal()">
                ➕ 创建任务
            </button>
        </div>
    </div>

    <!-- 任务列表 -->
    <?php if (empty($tasks)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">📭</div>
        <h3>暂无任务</h3>
        <p>点击"创建任务"开始使用地图重定向功能</p>
    </div>
    <?php else: ?>
    <div class="tasks-table-container">
        <table class="tasks-table">
            <thead>
                <tr>
                    <th>任务名称</th>
                    <th>状态</th>
                    <th style="text-align: center;">蜘蛛筛选</th>
                    <th style="text-align: center;">域名数</th>
                    <th style="text-align: center;">已抓取域名</th>
                    <th style="text-align: center;">总跳转</th>
                    <th style="text-align: center;">首页跳转</th>
                    <th style="text-align: center;">内页跳转</th>
                    <th style="text-align: right;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tasks as $task): 
                    $stats = $task['stats'];
                    $total = intval($stats['total'] ?? 0);
                    $domainJumps = intval($stats['domain_jumps'] ?? 0);
                    $innerJumps = intval($stats['inner_jumps'] ?? 0);
                    $domainCount = count($task['domains'] ?? []);
                ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($task['name']); ?></strong>
                    </td>
                    <td>
                        <?php if ($task['enabled']): ?>
                        <span class="status-badge running">● 运行中</span>
                        <?php else: ?>
                        <span class="status-badge stopped">● 已停止</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center;">
                        <?php 
                        $spiderFilter = $task['spider_filter'] ?? ['enabled' => false, 'types' => []];
                        if ($spiderFilter['enabled']) {
                            $types = $spiderFilter['types'] ?? [];
                            $enabledTypes = [];
                            if (!empty($types['baidu_pc'])) $enabledTypes[] = '百度PC';
                            if (!empty($types['baidu_mobile'])) $enabledTypes[] = '百度移动';
                            if (!empty($types['google'])) $enabledTypes[] = '谷歌';
                            if (!empty($types['sogou'])) $enabledTypes[] = '搜狗';
                            
                            if (!empty($enabledTypes)) {
                                echo '<span style="color: var(--primary); font-size: 12px;">🕷️ ' . implode('、', $enabledTypes) . '</span>';
                            } else {
                                echo '<span style="color: var(--text-muted); font-size: 12px;">已启用（未选择）</span>';
                            }
                        } else {
                            echo '<span style="color: var(--text-muted); font-size: 12px;">-</span>';
                        }
                        ?>
                    </td>
                    <td style="text-align: center;">
                        <?php echo $domainCount; ?>
                    </td>
                    <td style="text-align: center;">
                        <?php 
                        // 检查Redis中实际有链接数据的域名数量
                        $cachedCount = _sitemap_countCachedDomains($task['id'], $task['domains']);
                        echo '<span style="color: var(--text-muted); font-size: 12px;">' . $cachedCount . ' / ' . $domainCount . '</span>';
                        ?>
                    </td>
                    <td style="text-align: center;">
                        <?php echo number_format($total); ?>
                    </td>
                    <td style="text-align: center;">
                        <?php echo number_format($domainJumps); ?>
                    </td>
                    <td style="text-align: center;">
                        <?php echo number_format($innerJumps); ?>
                    </td>
                    <td style="text-align: right;">
                        <button class="btn btn-sm <?php echo $task['enabled'] ? 'btn-secondary' : 'btn-success'; ?>" 
                                onclick="toggleTask('<?php echo $task['id']; ?>', <?php echo $task['enabled'] ? 'false' : 'true'; ?>)">
                            <?php echo $task['enabled'] ? '⏸ 停止' : '▶ 启用'; ?>
                        </button>
                        <button class="btn btn-warning btn-sm" 
                                onclick="editSpider('<?php echo $task['id']; ?>')">
                            🕷️ 蜘蛛
                        </button>
                        <button class="btn btn-primary btn-sm" 
                                onclick="location.href='sitemap_task.php?id=<?php echo $task['id']; ?>'">
                            编辑
                        </button>
                        <button class="btn btn-danger btn-sm" 
                                onclick="deleteTask('<?php echo $task['id']; ?>', '<?php echo htmlspecialchars($task['name']); ?>')">
                            删除
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
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
            <?php renderSpiderSelector('create', false, []); ?>
        </div>
        
        <button class="btn btn-primary" onclick="goToTaskNameStep()" style="width: 100%;">
            下一步：任务命名
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
                <label>任务名称 *</label>
                <input type="text" class="form-control" name="name" required 
                       placeholder="例如：主站地图重定向" autofocus>
                <p class="form-hint">创建后将跳转到任务详情页进行配置</p>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 16px;">
                创建任务
            </button>
        </form>
    </div>
</div>

<!-- 编辑蜘蛛筛选模态框 -->
<div class="modal-overlay" id="editSpiderModal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>编辑蜘蛛筛选</h3>
            <button class="modal-close" onclick="closeModal('editSpiderModal')">&times;</button>
        </div>
        
        <div id="editSpiderAlert"></div>
        
        <form id="editSpiderForm" onsubmit="return handleEditSpiderSubmit(event);">
            <input type="hidden" id="editSpiderTaskId" name="task_id">
            
            <div id="editSpiderContainer">
                <!-- 蜘蛛选择器将动态加载到这里 -->
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 16px;">
                保存修改
            </button>
        </form>
    </div>
</div>

<script>
// 处理模块设置表单提交
document.getElementById('moduleSettingsForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const probability = document.getElementById('moduleProbability').value;
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'save_module_settings');
    formData.append('probability', probability);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✓ ' + data.message);
        } else {
            alert('✗ ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('保存失败');
    });
});

// 显示创建任务模态框（步骤1）
function showCreateModal() {
    document.getElementById('createSpiderModal').classList.add('active');
}

// 关闭模态框
function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

// 进入步骤2：任务命名
function goToTaskNameStep() {
    // 关闭步骤1
    closeModal('createSpiderModal');
    // 打开步骤2
    document.getElementById('createTaskModal').classList.add('active');
    document.getElementById('createTaskAlert').innerHTML = '';
}

// 处理创建任务表单提交
function handleCreateTaskSubmit(event) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    
    // 获取蜘蛛筛选配置（使用正确的ID：spiderFilterEnabled_create）
    const spiderFilterEnabled = document.querySelector('input[name="spider_filter_enabled_create"]');
    const spiderFilter = {
        enabled: spiderFilterEnabled ? spiderFilterEnabled.checked : false,
        types: {}
    };
    
    // 获取各个蜘蛛类型的选择（使用正确的name：spider_type_xxx_create）
    ['baidu_pc', 'baidu_mobile', 'google', 'sogou'].forEach(type => {
        const checkbox = document.querySelector(`input[name="spider_type_${type}_create"]`);
        spiderFilter.types[type] = checkbox ? checkbox.checked : false;
    });
    
    // 添加到表单数据
    formData.append('ajax', '1');
    formData.append('action', 'create');
    formData.append('spider_filter_enabled', spiderFilter.enabled ? '1' : '0');
    formData.append('spider_type_baidu_pc', spiderFilter.types.baidu_pc ? '1' : '0');
    formData.append('spider_type_baidu_mobile', spiderFilter.types.baidu_mobile ? '1' : '0');
    formData.append('spider_type_google', spiderFilter.types.google ? '1' : '0');
    formData.append('spider_type_sogou', spiderFilter.types.sogou ? '1' : '0');
    
    // 提交
    fetch('sitemap_tasks.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // 跳转到任务详情页
            location.href = 'sitemap_task.php?id=' + data.task_id;
        } else {
            document.getElementById('createTaskAlert').innerHTML = 
                `<div class="alert alert-error">✗ ${data.message || '创建失败'}</div>`;
        }
    })
    .catch(err => {
        console.error(err);
        document.getElementById('createTaskAlert').innerHTML = 
            '<div class="alert alert-error">✗ 创建失败，请重试</div>';
    });
    
    return false;
}

function toggleTask(taskId, enabled) {
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'toggle');
    formData.append('task_id', taskId);
    formData.append('enabled', enabled ? '1' : '0');
    
    fetch('sitemap_tasks.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // 刷新页面
            location.reload();
        } else {
            alert(data.message || '操作失败');
            location.reload();
        }
    });
}

function deleteTask(taskId, taskName) {
    if (!confirm(`确定要删除任务"${taskName}"吗？\n\n删除后将清除所有相关数据，此操作不可恢复！`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'delete');
    formData.append('task_id', taskId);
    
    fetch('sitemap_tasks.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || '删除失败');
        }
    });
}

// 编辑蜘蛛筛选
function editSpider(taskId) {
    // 获取任务的蜘蛛筛选配置
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'get_spider_filter');
    formData.append('task_id', taskId);
    
    fetch('sitemap_tasks.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // 设置任务ID
            document.getElementById('editSpiderTaskId').value = taskId;
            
            // 动态生成蜘蛛选择器
            const container = document.getElementById('editSpiderContainer');
            const spiderFilter = data.spider_filter || {enabled: false, types: {}};
            const types = spiderFilter.types || {};
            
            container.innerHTML = `
                <div class="spider-selector-card">
                    <div class="spider-selector-header">
                        <h3>🕷️ 蜘蛛筛选配置</h3>
                        <p class="spider-selector-desc">配置此任务针对哪些蜘蛛类型生效</p>
                    </div>
                    
                    <div class="spider-selector-body">
                        <!-- 启用开关 -->
                        <div class="spider-selector-switch">
                            <label class="switch-label">
                                <input type="checkbox" 
                                       id="spiderFilterEnabled_edit" 
                                       name="spider_filter_enabled" 
                                       value="1" 
                                       ${spiderFilter.enabled ? 'checked' : ''}
                                       onchange="toggleEditSpiderTypes()">
                                <span class="switch-slider"></span>
                                <span class="switch-text">启用蜘蛛筛选</span>
                            </label>
                            <p class="switch-hint">关闭则对所有访问者生效（包括蜘蛛和普通用户）</p>
                        </div>
                        
                        <!-- 蜘蛛类型选择 -->
                        <div id="spiderTypesContainer_edit" class="spider-types-container" style="${spiderFilter.enabled ? '' : 'display: none;'}">
                            <div class="spider-types-header">
                                <span>选择蜘蛛类型：</span>
                                <span class="spider-types-hint">至少选择一种蜘蛛类型</span>
                            </div>
                            
                            <div class="spider-types-grid">
                                <!-- 百度PC -->
                                <label class="spider-type-item">
                                    <input type="checkbox" 
                                           name="spider_type_baidu_pc" 
                                           value="1" 
                                           ${types.baidu_pc ? 'checked' : ''}>
                                    <div class="spider-type-content">
                                        <div class="spider-type-name">百度PC</div>
                                    </div>
                                </label>
                                
                                <!-- 百度移动 -->
                                <label class="spider-type-item">
                                    <input type="checkbox" 
                                           name="spider_type_baidu_mobile" 
                                           value="1" 
                                           ${types.baidu_mobile ? 'checked' : ''}>
                                    <div class="spider-type-content">
                                        <div class="spider-type-name">百度移动</div>
                                    </div>
                                </label>
                                
                                <!-- 谷歌蜘蛛 -->
                                <label class="spider-type-item">
                                    <input type="checkbox" 
                                           name="spider_type_google" 
                                           value="1" 
                                           ${types.google ? 'checked' : ''}>
                                    <div class="spider-type-content">
                                        <div class="spider-type-name">谷歌蜘蛛</div>
                                    </div>
                                </label>
                                
                                <!-- 搜狗蜘蛛 -->
                                <label class="spider-type-item">
                                    <input type="checkbox" 
                                           name="spider_type_sogou" 
                                           value="1" 
                                           ${types.sogou ? 'checked' : ''}>
                                    <div class="spider-type-content">
                                        <div class="spider-type-name">搜狗蜘蛛</div>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // 显示模态框
            document.getElementById('editSpiderModal').classList.add('active');
        } else {
            alert(data.message || '获取配置失败');
        }
    })
    .catch(err => {
        console.error(err);
        alert('获取配置失败');
    });
}

// 切换蜘蛛类型显示
function toggleEditSpiderTypes() {
    const enabled = document.getElementById('spiderFilterEnabled_edit').checked;
    const container = document.getElementById('spiderTypesContainer_edit');
    container.style.display = enabled ? '' : 'none';
}

// 提交编辑蜘蛛筛选
function handleEditSpiderSubmit(event) {
    event.preventDefault();
    
    const formData = new FormData(event.target);
    formData.append('ajax', '1');
    formData.append('action', 'update_spider_filter');
    
    fetch('sitemap_tasks.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✓ ' + data.message);
            location.reload();
        } else {
            alert('✗ ' + (data.message || '保存失败'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('✗ 保存失败，请重试');
    });
    
    return false;
}

// 点击遮罩关闭模态框
document.getElementById('createSpiderModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal('createSpiderModal');
    }
});

document.getElementById('createTaskModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal('createTaskModal');
    }
});

</script>

<?php require_once __DIR__ . '/footer.php'; ?>

