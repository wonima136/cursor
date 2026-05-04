<?php
/**
 * 链接消耗池管理
 */
$pageTitle = '链接消耗池 - 301重定向管理系统';

require_once __DIR__ . '/config.php';

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pool = getLinksPool();
    
    switch ($action) {
        case 'add':
            $url = trim($_POST['url'] ?? '');
            $count = intval($_POST['count'] ?? 10);
            $note = trim($_POST['note'] ?? '');
            
            if (!empty($url)) {
                // 检查是否已存在
                $exists = false;
                foreach ($pool['links'] as $link) {
                    if ($link['url'] === $url) {
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    $pool['links'][] = [
                        'url' => $url,
                        'total' => max(1, $count),
                        'consumed' => 0,
                        'remaining' => max(1, $count),
                        'note' => $note,
                        'created_at' => date('Y-m-d H:i:s'),
                        'last_used' => null,
                    ];
                    updateLinksPoolStats($pool);
                    saveLinksPool($pool);
                    $message = ['type' => 'success', 'text' => '链接添加成功'];
                } else {
                    $message = ['type' => 'error', 'text' => '链接已存在'];
                }
            }
            break;
            
        case 'update_default_count':
            $settings = getSettings();
            $settings['links_pool']['default_count'] = max(1, intval($_POST['default_count'] ?? 1));
            saveSettings($settings);
            $message = ['type' => 'success', 'text' => '默认跳转次数已更新为 ' . $settings['links_pool']['default_count'] . ' 次'];
            break;
            
        case 'batch_add':
            $text = trim($_POST['links_text'] ?? '');
            $settings = getSettings();
            $defaultCount = intval($_POST['default_count'] ?? $settings['links_pool']['default_count']);
            
            if (!empty($text)) {
                $lines = explode("\n", $text);
                $added = 0;
                $skipped = 0;
                
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || strpos($line, '#') === 0) continue;
                    
                    // 支持格式：url 或 url,count 或 url,count,note
                    $parts = explode(',', $line);
                    $url = trim($parts[0]);
                    $count = isset($parts[1]) ? intval(trim($parts[1])) : $defaultCount;
                    $note = isset($parts[2]) ? trim($parts[2]) : '';
                    
                    if (empty($url)) continue;
                    
                    // 检查是否已存在
                    $exists = false;
                    foreach ($pool['links'] as $link) {
                        if ($link['url'] === $url) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        $pool['links'][] = [
                            'url' => $url,
                            'total' => max(1, $count),
                            'consumed' => 0,
                            'remaining' => max(1, $count),
                            'note' => $note,
                            'created_at' => date('Y-m-d H:i:s'),
                            'last_used' => null,
                        ];
                        $added++;
                    } else {
                        $skipped++;
                    }
                }
                
                updateLinksPoolStats($pool);
                saveLinksPool($pool);
                $message = ['type' => 'success', 'text' => "成功添加 {$added} 个链接" . ($skipped > 0 ? "，跳过 {$skipped} 个重复" : '')];
            }
            break;
            
        case 'delete':
            $index = intval($_POST['index'] ?? -1);
            if (isset($pool['links'][$index])) {
                array_splice($pool['links'], $index, 1);
                updateLinksPoolStats($pool);
                saveLinksPool($pool);
                $message = ['type' => 'success', 'text' => '链接已删除'];
            }
            break;
            
        case 'clear':
            $pool['links'] = [];
            updateLinksPoolStats($pool);
            saveLinksPool($pool);
            $message = ['type' => 'success', 'text' => '链接池已清空'];
            break;
            
        case 'clear_completed':
            $pool['links'] = array_values(array_filter($pool['links'], function($link) { return $link['remaining'] > 0; }));
            updateLinksPoolStats($pool);
            saveLinksPool($pool);
            $message = ['type' => 'success', 'text' => '已完成的链接已清理'];
            break;
            
        case 'reset':
            $index = intval($_POST['index'] ?? -1);
            if (isset($pool['links'][$index])) {
                $pool['links'][$index]['consumed'] = 0;
                $pool['links'][$index]['remaining'] = $pool['links'][$index]['total'];
                updateLinksPoolStats($pool);
                saveLinksPool($pool);
                $message = ['type' => 'success', 'text' => '链接次数已重置'];
            }
            break;
    }
}

require_once __DIR__ . '/header.php';
$pool = getLinksPool();
updateLinksPoolStats($pool);
$settings = getSettings();

// 筛选
$filter = $_GET['filter'] ?? 'all';
$filteredLinks = $pool['links'];
if ($filter === 'active') {
    $filteredLinks = array_filter($pool['links'], function($link) { return $link['remaining'] > 0; });
} elseif ($filter === 'completed') {
    $filteredLinks = array_filter($pool['links'], function($link) { return $link['remaining'] <= 0; });
}
?>

<div class="page-header">
    <h1 class="page-title">链接消耗池</h1>
    <p class="page-subtitle">管理消耗型跳转链接，每个链接有固定跳转次数</p>
</div>

<?php if (isset($message)): ?>
<div class="alert alert-<?php echo $message['type']; ?>"><?php echo htmlspecialchars($message['text']); ?></div>
<?php endif; ?>

<!-- 默认跳转次数设置 -->
<div class="card" style="margin-bottom: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div>
            <h3 style="font-size: 16px; margin-bottom: 4px;">⚙️ 默认跳转次数设置</h3>
            <p style="color: var(--text-muted); font-size: 13px;">未指定次数的链接将使用此默认值</p>
        </div>
        <form method="POST" style="display: flex; align-items: center; gap: 12px;">
            <input type="hidden" name="action" value="update_default_count">
            <div style="display: flex; align-items: center; gap: 8px;">
                <label style="color: var(--text-muted); font-size: 14px;">默认次数：</label>
                <input type="number" name="default_count" class="form-input" 
                       value="<?php echo $settings['links_pool']['default_count']; ?>" 
                       min="1" max="9999" style="width: 100px;">
                <span style="color: var(--text-muted); font-size: 14px;">次</span>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">保存</button>
        </form>
    </div>
</div>

<!-- 统计卡片 -->
<div class="stats-grid" style="margin-bottom: 20px;">
    <div class="stat-card">
        <div class="stat-icon primary">📊</div>
        <div class="stat-content">
            <h3><?php echo $pool['stats']['total_links']; ?></h3>
            <p>总链接数</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success">✅</div>
        <div class="stat-content">
            <h3><?php echo $pool['stats']['completed_links']; ?></h3>
            <p>已完成</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning">🔄</div>
        <div class="stat-content">
            <h3><?php echo $pool['stats']['active_links']; ?></h3>
            <p>进行中</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon info">📈</div>
        <div class="stat-content">
            <h3><?php echo number_format($pool['stats']['total_remaining']); ?></h3>
            <p>剩余次数</p>
        </div>
    </div>
</div>

<!-- 操作按钮 -->
<div style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;">
    <button class="btn btn-primary" onclick="showModal('addModal')">➕ 添加链接</button>
    <button class="btn btn-secondary" onclick="showModal('batchModal')">📋 批量导入</button>
    <?php if ($pool['stats']['completed_links'] > 0): ?>
    <button class="btn btn-secondary" onclick="confirmClearCompleted()">🧹 清理已完成</button>
    <?php endif; ?>
    <?php if (!empty($pool['links'])): ?>
    <button class="btn btn-danger" onclick="confirmClear()">🗑️ 清空全部</button>
    <?php endif; ?>
</div>

<!-- 筛选标签 -->
<div style="display: flex; gap: 8px; margin-bottom: 20px;">
    <a href="?filter=all" class="btn btn-sm <?php echo $filter === 'all' ? 'btn-primary' : 'btn-secondary'; ?>">
        全部 (<?php echo $pool['stats']['total_links']; ?>)
    </a>
    <a href="?filter=active" class="btn btn-sm <?php echo $filter === 'active' ? 'btn-primary' : 'btn-secondary'; ?>">
        进行中 (<?php echo $pool['stats']['active_links']; ?>)
    </a>
    <a href="?filter=completed" class="btn btn-sm <?php echo $filter === 'completed' ? 'btn-primary' : 'btn-secondary'; ?>">
        已完成 (<?php echo $pool['stats']['completed_links']; ?>)
    </a>
</div>

<!-- 链接列表 -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">链接列表</h3>
    </div>
    
    <?php if (!empty($filteredLinks)): ?>
    <div style="overflow-x: auto;">
        <table class="table">
            <thead>
                <tr>
                    <th>链接</th>
                    <th style="width: 80px;">总次数</th>
                    <th style="width: 80px;">已消耗</th>
                    <th style="width: 80px;">剩余</th>
                    <th style="width: 100px;">状态</th>
                    <th style="width: 150px;">最后使用</th>
                    <th style="width: 120px;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // 保持原始索引
                foreach ($pool['links'] as $index => $link): 
                    // 根据筛选条件显示
                    if ($filter === 'active' && $link['remaining'] <= 0) continue;
                    if ($filter === 'completed' && $link['remaining'] > 0) continue;
                    
                    $progress = $link['total'] > 0 ? ($link['consumed'] / $link['total']) * 100 : 100;
                ?>
                <tr>
                    <td>
                        <div style="max-width: 400px;">
                            <a href="<?php echo htmlspecialchars($link['url']); ?>" target="_blank" style="color: var(--primary-light); text-decoration: none; word-break: break-all; font-size: 13px;">
                                <?php echo htmlspecialchars($link['url']); ?>
                            </a>
                            <?php if (!empty($link['note'])): ?>
                            <p style="color: var(--text-muted); font-size: 12px; margin-top: 4px;"><?php echo htmlspecialchars($link['note']); ?></p>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><?php echo $link['total']; ?></td>
                    <td><?php echo $link['consumed']; ?></td>
                    <td>
                        <strong style="color: <?php echo $link['remaining'] > 0 ? 'var(--success)' : 'var(--text-muted)'; ?>">
                            <?php echo $link['remaining']; ?>
                        </strong>
                    </td>
                    <td>
                        <?php if ($link['remaining'] <= 0): ?>
                        <span class="badge badge-success">✅ 已完成</span>
                        <?php elseif ($link['consumed'] > 0): ?>
                        <span class="badge badge-warning">🔄 进行中</span>
                        <?php else: ?>
                        <span class="badge badge-info">⏳ 等待中</span>
                        <?php endif; ?>
                    </td>
                    <td style="color: var(--text-muted); font-size: 12px;">
                        <?php echo $link['last_used'] ?? '-'; ?>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-secondary" onclick="resetLink(<?php echo $index; ?>)" title="重置次数">🔄</button>
                        <button class="btn btn-sm btn-danger" onclick="deleteLink(<?php echo $index; ?>)" title="删除">🗑️</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <div class="empty-state-icon">🔗</div>
        <h3>暂无链接</h3>
        <p>上传一些链接到消耗池开始使用</p>
        <button class="btn btn-primary" onclick="showModal('batchModal')">📋 批量导入</button>
    </div>
    <?php endif; ?>
</div>

<!-- 添加链接模态框 -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">添加链接</h3>
            <button class="modal-close" onclick="hideModal('addModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">链接URL</label>
                    <input type="url" name="url" class="form-input" placeholder="https://www.example.com/page.html" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">跳转次数</label>
                        <input type="number" name="count" class="form-input" value="<?php echo $settings['links_pool']['default_count']; ?>" min="1">
                        <p class="form-hint">消耗完后自动停止</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">备注</label>
                        <input type="text" name="note" class="form-input" placeholder="可选">
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
    <div class="modal" style="max-width: 700px;">
        <div class="modal-header">
            <h3 class="modal-title">批量导入链接</h3>
            <button class="modal-close" onclick="hideModal('batchModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="batch_add">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">链接列表</label>
                    <textarea name="links_text" class="form-textarea" rows="12" placeholder="每行一个链接，支持以下格式：

https://www.example1.com/page1.html
https://www.example2.com/page2.html,10
https://www.example3.com/page3.html,20,新文章页

格式说明：
- 纯链接：使用上方设置的默认次数（当前为 <?php echo $settings['links_pool']['default_count']; ?> 次）
- 链接,次数：指定跳转次数
- 链接,次数,备注：带备注说明

# 开头的行为注释，会被忽略"></textarea>
                    <p class="form-hint">未指定次数的链接将使用默认跳转次数：<strong><?php echo $settings['links_pool']['default_count']; ?> 次</strong>（可在上方修改）</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="hideModal('batchModal')">取消</button>
                <button type="submit" class="btn btn-primary">导入</button>
            </div>
        </form>
    </div>
</div>

<!-- 操作表单 -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="index" id="deleteIndex">
</form>

<form id="resetForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="reset">
    <input type="hidden" name="index" id="resetIndex">
</form>

<form id="clearForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="clear">
</form>

<form id="clearCompletedForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="clear_completed">
</form>

<script>
function deleteLink(index) {
    if (confirm('确定要删除这个链接吗？')) {
        document.getElementById('deleteIndex').value = index;
        document.getElementById('deleteForm').submit();
    }
}

function resetLink(index) {
    if (confirm('确定要重置这个链接的跳转次数吗？')) {
        document.getElementById('resetIndex').value = index;
        document.getElementById('resetForm').submit();
    }
}

function confirmClear() {
    if (confirm('确定要清空所有链接吗？此操作不可恢复！')) {
        document.getElementById('clearForm').submit();
    }
}

function confirmClearCompleted() {
    if (confirm('确定要清理所有已完成的链接吗？')) {
        document.getElementById('clearCompletedForm').submit();
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

