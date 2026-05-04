<?php
/**
 * 站群链轮管理 - 分组列表
 */
$pageTitle = '站群链轮 - 301重定向管理系统';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/group_functions.php';
require_once __DIR__ . '/spider_selector.php';

// 检查登录状态
if (!checkLogin()) {
    header('Location: login.php');
    exit;
}

// 处理AJAX请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
            $name = trim($_POST['name'] ?? '');
            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => '请输入分组名称']);
                exit;
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
            
            $groupId = _r301group_create([
                'name' => $name,
                'spider_filter' => $spiderFilter
            ]);
            
            // 重建 Redis 域名索引
            require_once __DIR__ . '/domain_index.php';
            rebuildDomainIndex();
            
            echo json_encode(['success' => true, 'group_id' => $groupId, 'message' => '分组创建成功']);
            exit;
            
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
            
            if (_r301group_update($groupId, ['spider_filter' => $spiderFilter])) {
                echo json_encode(['success' => true, 'message' => '蜘蛛配置已更新']);
            } else {
                echo json_encode(['success' => false, 'message' => '更新失败']);
            }
            exit;
            
        // 渲染编辑模式的蜘蛛选择器
        case 'render_spider_selector_edit_mode':
            $groupId = $_POST['group_id'] ?? '';
            $group = _r301group_getById($groupId);
            if ($group) {
                $types = $group['spider_filter']['types'] ?? [];
                ob_start();
                renderSpiderSelectorEditMode('edit', $types);
                $html = ob_get_clean();
                echo json_encode(['success' => true, 'html' => $html]);
            } else {
                echo json_encode(['success' => false, 'message' => '分组不存在']);
            }
            exit;
            
        case 'toggle':
            $groupId = $_POST['group_id'] ?? '';
            $enabled = $_POST['enabled'] === '1';
            _r301group_toggle($groupId, $enabled);
            echo json_encode(['success' => true]);
            exit;
            
        case 'delete':
            $groupId = $_POST['group_id'] ?? '';
            _r301group_delete($groupId);
            
            // 重建 Redis 域名索引
            require_once __DIR__ . '/domain_index.php';
            rebuildDomainIndex();
            
            echo json_encode(['success' => true, 'message' => '分组已删除']);
            exit;
    }
    
    echo json_encode(['success' => false, 'message' => '未知操作']);
    exit;
}

// 获取所有分组
$groups = _r301group_getAll();

// 从 Redis 获取实时统计数据
require_once __DIR__ . '/redis_config.php';
try {
    $redis = getRedis();
    if ($redis) {
        foreach ($groups as &$group) {
            $groupId = $group['id'];
            $prefix = REDIS_GROUP_PREFIX;
            $statsKey = "{$prefix}{$groupId}:stats";  // ★ 修复：去掉多余的 "group:"
            
            // 从 Redis 读取统计数据
            if ($redis->exists($statsKey)) {
                $redisStats = $redis->hGetAll($statsKey);
                if (!empty($redisStats)) {
                    $group['stats']['total_redirects'] = (int)($redisStats['total_redirects'] ?? 0);
                }
            }
        }
        unset($group); // 解除引用
    }
} catch (Exception $e) {
    // Redis 连接失败，使用 JSON 中的数据
}

include 'header.php';
?>

<style>
.groups-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
}
.groups-table {
    width: 100%;
    border-collapse: collapse;
}
.groups-table th {
    text-align: left;
    padding: 14px 16px;
    background: var(--bg-dark);
    font-weight: 600;
    color: var(--text-muted);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.groups-table td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
.groups-table tr:last-child td {
    border-bottom: none;
}
.groups-table tr:hover td {
    background: var(--bg-hover);
}
.group-name-cell {
    font-weight: 600;
    color: var(--text);
}
.group-name-cell a {
    color: var(--text);
    text-decoration: none;
}
.group-name-cell a:hover {
    color: var(--primary-light);
}
.badge-enabled {
    background: rgba(34, 197, 94, 0.2);
    color: var(--success);
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
}
.badge-disabled {
    background: rgba(239, 68, 68, 0.2);
    color: var(--error);
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
}
.stat-cell {
    text-align: center;
    font-weight: 600;
    color: var(--text);
}
.summary-cell {
    font-size: 12px;
    color: var(--text-muted);
    max-width: 300px;
}
.actions-cell {
    display: flex;
    gap: 8px;
    align-items: center;
    justify-content: flex-end;
}
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 2px dashed var(--border);
}
.empty-state h3 {
    color: var(--text);
    margin-bottom: 8px;
}
.empty-state p {
    color: var(--text-muted);
    margin-bottom: 20px;
}
.page-header-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}
.info-box {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 24px;
}
.info-box h4 {
    margin: 0 0 6px 0;
    font-weight: 600;
    font-size: 15px;
}
.info-box p {
    margin: 0;
    opacity: 0.9;
    font-size: 13px;
    line-height: 1.5;
}
.info-box ul {
    margin: 6px 0 0 0;
    padding-left: 18px;
    opacity: 0.9;
    font-size: 13px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px;
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
</style>

<div class="page-header-row">
    <div>
        <h1 class="page-title">站群链轮</h1>
        <p class="page-subtitle">管理域名分组和链轮策略</p>
    </div>
    <button class="btn btn-primary" onclick="showCreateModal()">
        + 新建分组
    </button>
</div>

<div class="info-box">
    <h4>📡 站群链轮说明</h4>
    <p>站群链轮用于同一分组内的域名之间相互跳转，实现权重传递和快照更新。</p>
    <ul>
        <li><strong>独立概率控制</strong>：每个分组有独立的跳转概率（上限30%），避免过度跳转</li>
        <li><strong>Referer检测</strong>：自动检测来源，如果是组内域名跳转过来则不再跳转，防止死循环</li>
        <li><strong>域名开关</strong>：可单独关闭某个域名的跳转（仍可被其他域名跳转到）</li>
        <li><strong>固定目标</strong>：可为单个域名或整组设置固定跳转目标</li>
    </ul>
</div>

<?php if (empty($groups)): ?>
<div class="empty-state">
    <h3>还没有创建任何分组</h3>
    <p>创建一个分组来管理你的域名轮链策略</p>
    <button class="btn btn-primary" onclick="showCreateModal()">创建第一个分组</button>
</div>
<?php else: ?>

<div class="groups-card">
    <table class="groups-table">
        <thead>
            <tr>
                <th>分组名称</th>
                <th>状态</th>
                <th style="text-align: center;">域名数</th>
                <th style="text-align: center;">跳转次数</th>
                <th>配置摘要</th>
                <th style="text-align: right;">操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($groups as $group): ?>
            <tr id="group-<?php echo htmlspecialchars($group['id']); ?>">
                <td class="group-name-cell">
                    <a href="group.php?id=<?php echo urlencode($group['id']); ?>">
                        <?php echo htmlspecialchars($group['name']); ?>
                    </a>
                </td>
                <td>
                    <?php if ($group['enabled']): ?>
                    <span class="badge-enabled">运行中</span>
                    <?php else: ?>
                    <span class="badge-disabled">已停止</span>
                    <?php endif; ?>
                </td>
                <td class="stat-cell"><?php echo $group['stats']['total_domains'] ?? 0; ?></td>
                <td class="stat-cell"><?php echo $group['stats']['total_redirects'] ?? 0; ?></td>
                <td class="summary-cell"><?php echo htmlspecialchars(_r301group_getSummary($group)); ?></td>
                <td>
                    <div class="actions-cell">
                        <label class="switch">
                            <input type="checkbox" <?php echo $group['enabled'] ? 'checked' : ''; ?>
                                   onchange="toggleGroup('<?php echo htmlspecialchars($group['id']); ?>', this.checked)">
                            <span class="switch-slider"></span>
                        </label>
                        <button class="btn btn-sm btn-secondary" onclick="editSpiderFilter('<?php echo htmlspecialchars($group['id']); ?>')">
                            🕷️ 爬虫
                        </button>
                        <a href="group.php?id=<?php echo urlencode($group['id']); ?>" class="btn btn-sm btn-secondary">
                            管理
                        </a>
                        <button class="btn btn-sm btn-danger" onclick="deleteGroup('<?php echo htmlspecialchars($group['id']); ?>', '<?php echo htmlspecialchars($group['name']); ?>')">
                            删除
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php endif; ?>

<!-- 第一步：选择蜘蛛类型 -->
<div class="modal-overlay" id="createSpiderModal">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">第1步：选择蜘蛛类型</h3>
            <button class="modal-close" onclick="hideCreateModal()">&times;</button>
        </div>
        <div class="modal-body">
            <?php renderSpiderSelector('create', false, []); ?>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="hideCreateModal()">取消</button>
            <button class="btn btn-primary" onclick="goToNameStep()">下一步</button>
        </div>
    </div>
</div>

<!-- 第二步：输入分组名称 -->
<div class="modal-overlay" id="createNameModal">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">第2步：分组名称</h3>
            <button class="modal-close" onclick="hideCreateModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">分组名称</label>
                <input type="text" class="form-input" id="groupName" placeholder="例如：主站群、测试站群">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="backToSpiderStep()">上一步</button>
            <button class="btn btn-primary" onclick="createGroup()">创建</button>
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
// 全局变量存储当前编辑的分组ID
let currentEditGroupId = null;

function showCreateModal() {
    document.getElementById('groupName').value = '';
    document.getElementById('createSpiderModal').classList.add('active');
}

function hideCreateModal() {
    document.getElementById('createSpiderModal').classList.remove('active');
    document.getElementById('createNameModal').classList.remove('active');
}

function hideEditSpiderModal() {
    document.getElementById('editSpiderModal').classList.remove('active');
}

function goToNameStep() {
    document.getElementById('createSpiderModal').classList.remove('active');
    document.getElementById('createNameModal').classList.add('active');
    document.getElementById('groupName').focus();
}

function backToSpiderStep() {
    document.getElementById('createNameModal').classList.remove('active');
    document.getElementById('createSpiderModal').classList.add('active');
}

// 点击遮罩层关闭
document.getElementById('createSpiderModal').addEventListener('click', function(e) {
    if (e.target === this) hideCreateModal();
});
document.getElementById('createNameModal').addEventListener('click', function(e) {
    if (e.target === this) hideCreateModal();
});
document.getElementById('editSpiderModal').addEventListener('click', function(e) {
    if (e.target === this) hideEditSpiderModal();
});

// ESC 键关闭
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        hideCreateModal();
        hideEditSpiderModal();
    }
});

function createGroup() {
    const name = document.getElementById('groupName').value.trim();
    if (!name) {
        alert('请输入分组名称');
        return;
    }
    
    // 获取蜘蛛筛选配置
    const spiderConfig = getSpiderFilterConfig('create');
    
    const formData = new FormData();
    formData.append('action', 'create');
    formData.append('name', name);
    formData.append('spider_filter_enabled', spiderConfig.enabled ? '1' : '');
    formData.append('spider_type_baidu_pc', spiderConfig.types.baidu_pc ? '1' : '');
    formData.append('spider_type_baidu_mobile', spiderConfig.types.baidu_mobile ? '1' : '');
    formData.append('spider_type_google', spiderConfig.types.google ? '1' : '');
    formData.append('spider_type_sogou', spiderConfig.types.sogou ? '1' : '');
    
    fetch('groups.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.href = 'group.php?id=' + data.group_id;
        } else {
            alert(data.message || '创建失败');
        }
    });
}

// 编辑蜘蛛配置
function editSpiderFilter(groupId) {
    currentEditGroupId = groupId;
    
    const formData = new FormData();
    formData.append('action', 'render_spider_selector_edit_mode');
    formData.append('group_id', groupId);
    
    fetch('groups.php', {
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
function saveSpiderFilter() {
    if (!currentEditGroupId) {
        alert('分组ID丢失');
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
    
    fetch('groups.php', {
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

function toggleGroup(groupId, enabled) {
    fetch('groups.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=toggle&group_id=${encodeURIComponent(groupId)}&enabled=${enabled ? 1 : 0}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // 更新状态徽章
            const card = document.getElementById('group-' + groupId);
            const badge = card.querySelector('.badge-enabled, .badge-disabled');
            if (enabled) {
                badge.className = 'badge-enabled';
                badge.textContent = '运行中';
            } else {
                badge.className = 'badge-disabled';
                badge.textContent = '已停止';
            }
        }
    });
}

function deleteGroup(groupId, groupName) {
    if (!confirm(`确定要删除分组「${groupName}」吗？\n\n删除后该分组的所有域名配置将丢失！`)) {
        return;
    }
    
    fetch('groups.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=delete&group_id=${encodeURIComponent(groupId)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('group-' + groupId).remove();
            // 如果没有分组了，刷新页面显示空状态
            if (!document.querySelector('.group-card')) {
                location.reload();
            }
        }
    });
}
</script>

<?php include 'footer.php'; ?>
