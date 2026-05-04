<?php
/**
 * 域名池管理
 */
$pageTitle = '域名池 - 301重定向管理系统';

require_once __DIR__ . '/config.php';

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $domains = getDomains();
    
    switch ($action) {
        case 'add':
            $domain = trim($_POST['domain'] ?? '');
            $weight = intval($_POST['weight'] ?? 1);
            $group = trim($_POST['group'] ?? 'default');
            
            if (!empty($domain)) {
                // 检查是否已存在
                $exists = false;
                foreach ($domains['list'] as $d) {
                    if (strtolower($d['domain']) === strtolower($domain)) {
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    $domains['list'][] = [
                        'domain' => $domain,
                        'weight' => max(1, $weight),
                        'enabled' => true,
                        'group' => $group,
                        'created_at' => date('Y-m-d H:i:s'),
                    ];
                    saveDomains($domains);
                    $message = ['type' => 'success', 'text' => '域名添加成功'];
                } else {
                    $message = ['type' => 'error', 'text' => '域名已存在'];
                }
            }
            break;
            
        case 'batch_add':
            $text = trim($_POST['domains_text'] ?? '');
            $defaultWeight = intval($_POST['default_weight'] ?? 1);
            $group = trim($_POST['group'] ?? 'default');
            
            if (!empty($text)) {
                $lines = explode("\n", $text);
                $added = 0;
                $skipped = 0;
                
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || strpos($line, '#') === 0) continue;
                    
                    // 支持格式：domain 或 domain,weight
                    $parts = explode(',', $line);
                    $domain = trim($parts[0]);
                    $weight = isset($parts[1]) ? intval(trim($parts[1])) : $defaultWeight;
                    
                    if (empty($domain)) continue;
                    
                    // 检查是否已存在
                    $exists = false;
                    foreach ($domains['list'] as $d) {
                        if (strtolower($d['domain']) === strtolower($domain)) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        $domains['list'][] = [
                            'domain' => $domain,
                            'weight' => max(1, $weight),
                            'enabled' => true,
                            'group' => $group,
                            'created_at' => date('Y-m-d H:i:s'),
                        ];
                        $added++;
                    } else {
                        $skipped++;
                    }
                }
                
                saveDomains($domains);
                $message = ['type' => 'success', 'text' => "成功添加 {$added} 个域名" . ($skipped > 0 ? "，跳过 {$skipped} 个重复" : '')];
            }
            break;
            
        case 'toggle':
            $index = intval($_POST['index'] ?? -1);
            if (isset($domains['list'][$index])) {
                $domains['list'][$index]['enabled'] = !$domains['list'][$index]['enabled'];
                saveDomains($domains);
            }
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
            
        case 'delete':
            $index = intval($_POST['index'] ?? -1);
            if (isset($domains['list'][$index])) {
                array_splice($domains['list'], $index, 1);
                saveDomains($domains);
                $message = ['type' => 'success', 'text' => '域名已删除'];
            }
            break;
            
        case 'clear':
            $domains['list'] = [];
            saveDomains($domains);
            $message = ['type' => 'success', 'text' => '域名池已清空'];
            break;
            
        case 'update':
            $index = intval($_POST['index'] ?? -1);
            if (isset($domains['list'][$index])) {
                $domains['list'][$index]['weight'] = max(1, intval($_POST['weight'] ?? 1));
                $domains['list'][$index]['group'] = trim($_POST['group'] ?? 'default');
                saveDomains($domains);
                $message = ['type' => 'success', 'text' => '域名已更新'];
            }
            break;
    }
}

require_once __DIR__ . '/header.php';
$domains = getDomains();

// 获取所有分组
$groups = ['default'];
foreach ($domains['list'] as $d) {
    if (!empty($d['group']) && !in_array($d['group'], $groups)) {
        $groups[] = $d['group'];
    }
}
?>

<div class="page-header">
    <h1 class="page-title">域名池管理</h1>
    <p class="page-subtitle">管理可跳转的目标域名列表</p>
</div>

<?php if (isset($message)): ?>
<div class="alert alert-<?php echo $message['type']; ?>"><?php echo htmlspecialchars($message['text']); ?></div>
<?php endif; ?>

<!-- 操作按钮 -->
<div style="display: flex; gap: 12px; margin-bottom: 20px;">
    <button class="btn btn-primary" onclick="showModal('addModal')">➕ 添加域名</button>
    <button class="btn btn-secondary" onclick="showModal('batchModal')">📋 批量导入</button>
    <?php if (!empty($domains['list'])): ?>
    <button class="btn btn-danger" onclick="confirmClear()">🗑️ 清空全部</button>
    <?php endif; ?>
</div>

<!-- 域名列表 -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">域名列表 (<?php echo count($domains['list']); ?>)</h3>
    </div>
    
    <?php if (!empty($domains['list'])): ?>
    <table class="table">
        <thead>
            <tr>
                <th>域名</th>
                <th style="width: 100px;">权重</th>
                <th style="width: 120px;">分组</th>
                <th style="width: 100px;">状态</th>
                <th style="width: 150px;">添加时间</th>
                <th style="width: 150px;">操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($domains['list'] as $index => $domain): ?>
            <tr>
                <td>
                    <a href="http://<?php echo htmlspecialchars($domain['domain']); ?>" target="_blank" style="color: var(--primary-light); text-decoration: none;">
                        <?php echo htmlspecialchars($domain['domain']); ?>
                    </a>
                </td>
                <td><?php echo $domain['weight']; ?></td>
                <td>
                    <span class="badge badge-info"><?php echo htmlspecialchars($domain['group'] ?? 'default'); ?></span>
                </td>
                <td>
                    <label class="switch">
                        <input type="checkbox" <?php echo $domain['enabled'] ? 'checked' : ''; ?> onchange="toggleDomain(<?php echo $index; ?>)">
                        <span class="switch-slider"></span>
                    </label>
                </td>
                <td style="color: var(--text-muted); font-size: 13px;">
                    <?php echo $domain['created_at'] ?? '-'; ?>
                </td>
                <td>
                    <button class="btn btn-sm btn-secondary" onclick="editDomain(<?php echo $index; ?>, '<?php echo htmlspecialchars($domain['domain']); ?>', <?php echo $domain['weight']; ?>, '<?php echo htmlspecialchars($domain['group'] ?? 'default'); ?>')">编辑</button>
                    <button class="btn btn-sm btn-danger" onclick="deleteDomain(<?php echo $index; ?>)">删除</button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state">
        <div class="empty-state-icon">🌐</div>
        <h3>暂无域名</h3>
        <p>添加一些域名到域名池开始使用</p>
        <button class="btn btn-primary" onclick="showModal('addModal')">➕ 添加域名</button>
    </div>
    <?php endif; ?>
</div>

<!-- 添加域名模态框 -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">添加域名</h3>
            <button class="modal-close" onclick="hideModal('addModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">域名</label>
                    <input type="text" name="domain" class="form-input" placeholder="例如：www.example.com" required>
                    <p class="form-hint">不需要带 http:// 或 https://</p>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">权重</label>
                        <input type="number" name="weight" class="form-input" value="1" min="1" max="100">
                        <p class="form-hint">权重越高被选中概率越大</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">分组</label>
                        <input type="text" name="group" class="form-input" value="default" list="groupList">
                        <datalist id="groupList">
                            <?php foreach ($groups as $g): ?>
                            <option value="<?php echo htmlspecialchars($g); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="hideModal('addModal')">取消</button>
                <button type="submit" class="btn btn-primary">添加</button>
            </div>
        </form>
    </div>
</div>

<!-- 批量导入模态框 -->
<div class="modal-overlay" id="batchModal">
    <div class="modal" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title">批量导入域名</h3>
            <button class="modal-close" onclick="hideModal('batchModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="batch_add">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">域名列表</label>
                    <textarea name="domains_text" class="form-textarea" rows="10" placeholder="每行一个域名，支持格式：
www.example1.com
www.example2.com,5
www.example3.com,10

# 开头的行为注释，会被忽略"></textarea>
                    <p class="form-hint">支持格式：域名 或 域名,权重（每行一个）</p>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">默认权重</label>
                        <input type="number" name="default_weight" class="form-input" value="1" min="1" max="100">
                    </div>
                    <div class="form-group">
                        <label class="form-label">分组</label>
                        <input type="text" name="group" class="form-input" value="default" list="groupList2">
                        <datalist id="groupList2">
                            <?php foreach ($groups as $g): ?>
                            <option value="<?php echo htmlspecialchars($g); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="hideModal('batchModal')">取消</button>
                <button type="submit" class="btn btn-primary">导入</button>
            </div>
        </form>
    </div>
</div>

<!-- 编辑域名模态框 -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">编辑域名</h3>
            <button class="modal-close" onclick="hideModal('editModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="index" id="editIndex">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">域名</label>
                    <input type="text" id="editDomain" class="form-input" disabled>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">权重</label>
                        <input type="number" name="weight" id="editWeight" class="form-input" value="1" min="1" max="100">
                    </div>
                    <div class="form-group">
                        <label class="form-label">分组</label>
                        <input type="text" name="group" id="editGroup" class="form-input" value="default" list="groupList3">
                        <datalist id="groupList3">
                            <?php foreach ($groups as $g): ?>
                            <option value="<?php echo htmlspecialchars($g); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="hideModal('editModal')">取消</button>
                <button type="submit" class="btn btn-primary">保存</button>
            </div>
        </form>
    </div>
</div>

<!-- 删除确认表单 -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="index" id="deleteIndex">
</form>

<!-- 清空确认表单 -->
<form id="clearForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="clear">
</form>

<script>
function toggleDomain(index) {
    fetch('domains.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=toggle&index=' + index
    });
}

function editDomain(index, domain, weight, group) {
    document.getElementById('editIndex').value = index;
    document.getElementById('editDomain').value = domain;
    document.getElementById('editWeight').value = weight;
    document.getElementById('editGroup').value = group;
    showModal('editModal');
}

function deleteDomain(index) {
    if (confirm('确定要删除这个域名吗？')) {
        document.getElementById('deleteIndex').value = index;
        document.getElementById('deleteForm').submit();
    }
}

function confirmClear() {
    if (confirm('确定要清空所有域名吗？此操作不可恢复！')) {
        document.getElementById('clearForm').submit();
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

