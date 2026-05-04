<?php
/**
 * 跳转日志清理脚本
 * 功能：清理指定天数之前的日志
 */

require_once __DIR__ . '/config.php';

// 检查登录状态
if (!checkLogin()) {
    die('请先登录');
}

$dbFile = REDIRECT301_DATA_DIR . '/logs.db';

if (!file_exists($dbFile)) {
    die("❌ 数据库文件不存在: {$dbFile}\n");
}

// ★ AJAX 预览请求
if (isset($_POST['ajax']) && $_POST['action'] === 'preview') {
    $days = max(1, min(365, intval($_POST['days'] ?? 30)));
    $feature = $_POST['feature'] ?? 'all';
    $cleanupAll = isset($_POST['cleanup_all']) && $_POST['cleanup_all'] === '1';
    
    try {
        $db = new SQLite3($dbFile);
        $db->busyTimeout(30000);
        
        // 构建查询条件
        $whereConditions = [];
        $params = [];
        
        if (!$cleanupAll) {
            $whereConditions[] = "timestamp < :cutoff";
            $params[':cutoff'] = strtotime("-{$days} days");
        }
        
        if ($feature !== 'all') {
            $whereConditions[] = "feature = :feature";
            $params[':feature'] = $feature;
        }
        
        if (empty($whereConditions)) {
            $whereConditions[] = "1=1";
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // 获取将要删除的记录数
        $countSql = "SELECT COUNT(*) as total FROM redirect_logs WHERE " . $whereClause;
        $stmt = $db->prepare($countSql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $count = $row['total'];
        
        $result->finalize();
        $stmt->close();
        $db->close();
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'count' => $count]);
        exit;
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// 处理清理请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanup'])) {
    $days = max(1, min(365, intval($_POST['days'] ?? 30)));
    $feature = $_POST['feature'] ?? 'all'; // ★ 新增：功能模块筛选
    $cleanupAll = isset($_POST['cleanup_all']) && $_POST['cleanup_all'] === '1'; // ★ 是否清空所有
    
    try {
        $db = new SQLite3($dbFile);
        $db->busyTimeout(30000);
        
        // 构建查询条件
        $whereConditions = [];
        $params = [];
        
        // ★ 如果不是清空所有，则添加时间限制
        if (!$cleanupAll) {
            $whereConditions[] = "timestamp < :cutoff";
            $params[':cutoff'] = strtotime("-{$days} days");
        }
        
        // ★ 如果指定了功能模块，添加筛选条件
        if ($feature !== 'all') {
            $whereConditions[] = "feature = :feature";
            $params[':feature'] = $feature;
        }
        
        // ★ 如果没有任何条件，添加 1=1 避免语法错误
        if (empty($whereConditions)) {
            $whereConditions[] = "1=1";
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // 获取清理前的记录数
        $countSql = "SELECT COUNT(*) as total FROM redirect_logs WHERE " . $whereClause;
        $stmt = $db->prepare($countSql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $beforeCount = $row['total'];
        
        // ★ 关闭 result 和 statement，释放资源
        $result->finalize();
        $stmt->close();
        unset($result, $stmt);
        
        // 删除旧记录
        $deleteSql = "DELETE FROM redirect_logs WHERE " . $whereClause;
        $stmt = $db->prepare($deleteSql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        
        // ★ 关闭 statement，释放资源
        $stmt->close();
        unset($stmt);
        
        $deletedCount = $beforeCount;
        
        // ★ 优化数据库（确保所有 statement 都已关闭）
        $db->exec("VACUUM");
        
        $db->close();
        
        // ★ 生成提示信息
        $featureText = $feature === 'all' ? '全部' : $feature;
        if ($cleanupAll) {
            $message = "✅ 清理完成！删除了「{$featureText}」模块的所有 " . number_format($deletedCount) . " 条记录";
        } else {
            $message = "✅ 清理完成！删除了「{$featureText}」模块 " . number_format($deletedCount) . " 条记录（保留最近 {$days} 天）";
        }
        $messageType = 'success';
        
    } catch (Exception $e) {
        $message = "❌ 清理失败: " . $e->getMessage();
        $messageType = 'error';
    }
}

// 获取统计信息
try {
    $db = new SQLite3($dbFile);
    $db->busyTimeout(5000);
    
    // 总记录数
    $result = $db->query("SELECT COUNT(*) as total FROM redirect_logs");
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $totalRecords = $row['total'];
    
    // 数据库大小
    $dbSize = filesize($dbFile);
    
    // 最早记录时间
    $result = $db->query("SELECT MIN(timestamp) as earliest FROM redirect_logs");
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $earliestTime = $row['earliest'];
    
    // 最新记录时间
    $result = $db->query("SELECT MAX(timestamp) as latest FROM redirect_logs");
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $latestTime = $row['latest'];
    
    // 各时间段的记录数
    $now = time();
    $stats = [
        '今天' => $db->querySingle("SELECT COUNT(*) FROM redirect_logs WHERE timestamp >= " . strtotime('today')),
        '最近7天' => $db->querySingle("SELECT COUNT(*) FROM redirect_logs WHERE timestamp >= " . strtotime('-7 days')),
        '最近30天' => $db->querySingle("SELECT COUNT(*) FROM redirect_logs WHERE timestamp >= " . strtotime('-30 days')),
        '最近90天' => $db->querySingle("SELECT COUNT(*) FROM redirect_logs WHERE timestamp >= " . strtotime('-90 days')),
        '超过90天' => $db->querySingle("SELECT COUNT(*) FROM redirect_logs WHERE timestamp < " . strtotime('-90 days'))
    ];
    
    // ★ 按功能模块统计
    $result = $db->query("
        SELECT feature, COUNT(*) as count 
        FROM redirect_logs 
        GROUP BY feature 
        ORDER BY count DESC
    ");
    
    $featureStats = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $featureStats[$row['feature']] = $row['count'];
    }
    
    $db->close();
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/header.php';
?>

<style>
.cleanup-container {
    max-width: 800px;
    margin: 0 auto;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--bg-card);
    padding: 16px;
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    text-align: center;
}

.stat-label {
    color: var(--text-muted);
    font-size: 12px;
    margin-bottom: 6px;
}

.stat-value {
    color: var(--text);
    font-size: 18px;
    font-weight: 600;
}

.stat-value.date {
    font-size: 14px;
}

.cleanup-form {
    background: var(--bg-card);
    padding: 24px;
    border-radius: 12px;
    margin-bottom: 24px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: var(--text);
    font-weight: 500;
}

.form-group input[type="number"] {
    width: 200px;
    padding: 10px;
    background: var(--bg-dark);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 6px;
    color: var(--text);
    font-size: 14px;
}

.warning-box {
    background: rgba(245, 158, 11, 0.1);
    border: 1px solid rgba(245, 158, 11, 0.3);
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 20px;
    color: #f59e0b;
}

.message {
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.message.success {
    background: rgba(16, 185, 129, 0.1);
    border: 1px solid rgba(16, 185, 129, 0.3);
    color: #10b981;
}

.message.error {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: #ef4444;
}

.time-stats {
    background: var(--bg-card);
    padding: 24px;
    border-radius: 12px;
    margin-bottom: 24px;
}

.time-stats table {
    width: 100%;
    border-collapse: collapse;
}

.time-stats th,
.time-stats td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.time-stats th {
    color: var(--text-muted);
    font-weight: 500;
    font-size: 13px;
}

.time-stats td {
    color: var(--text);
}
</style>

<div class="cleanup-container">
    <h1>🗑️ 跳转日志清理</h1>
    
    <?php if (isset($message)): ?>
        <div class="message <?php echo $messageType; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="message error">
            ❌ 错误: <?php echo htmlspecialchars($error); ?>
        </div>
    <?php else: ?>
        
        <!-- 统计信息 -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">总记录数</div>
                <div class="stat-value"><?php echo number_format($totalRecords); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">数据库大小</div>
                <div class="stat-value"><?php echo formatBytes($dbSize); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">最早记录</div>
                <div class="stat-value date">
                    <?php echo $earliestTime ? date('Y-m-d', $earliestTime) : '-'; ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">最新记录</div>
                <div class="stat-value date">
                    <?php echo $latestTime ? date('Y-m-d', $latestTime) : '-'; ?>
                </div>
            </div>
        </div>
        
        <!-- 功能模块统计 -->
        <div class="time-stats">
            <h3 style="margin-bottom: 16px;">📊 功能模块分布</h3>
            <table>
                <thead>
                    <tr>
                        <th>功能模块</th>
                        <th>记录数</th>
                        <th>占比</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($featureStats as $feature => $count): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($feature); ?></td>
                            <td><?php echo number_format($count); ?> 条</td>
                            <td><?php echo $totalRecords > 0 ? round($count / $totalRecords * 100, 1) : 0; ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 时间分布统计 -->
        <div class="time-stats">
            <h3 style="margin-bottom: 16px;">📅 时间分布</h3>
            <table>
                <thead>
                    <tr>
                        <th>时间段</th>
                        <th>记录数</th>
                        <th>占比</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats as $label => $count): ?>
                        <tr>
                            <td><?php echo $label; ?></td>
                            <td><?php echo number_format($count); ?> 条</td>
                            <td><?php echo $totalRecords > 0 ? round($count / $totalRecords * 100, 1) : 0; ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 清理表单 -->
        <div class="cleanup-form">
            <h3 style="margin-bottom: 16px;">🗑️ 清理旧日志</h3>
            
            <div class="warning-box">
                ⚠️ <strong>注意：</strong>删除操作不可恢复，请谨慎操作！建议先备份数据库。
            </div>
            
            <form method="POST" onsubmit="return confirmCleanup();">
                <div class="form-group">
                    <label for="feature">清理哪个功能模块：</label>
                    <select id="feature" name="feature" style="width: 300px; padding: 10px; background: var(--bg-dark); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 6px; color: var(--text); font-size: 14px;">
                        <option value="all">全部模块</option>
                        <?php foreach ($featureStats as $feature => $count): ?>
                            <option value="<?php echo htmlspecialchars($feature); ?>">
                                <?php echo htmlspecialchars($feature); ?> (<?php echo number_format($count); ?> 条)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span style="color: var(--text-muted); font-size: 13px; margin-left: 8px;">
                        （可指定清理某个模块的数据）
                    </span>
                </div>
                
                <div class="form-group">
                    <label for="days">保留最近多少天的数据：</label>
                    <input type="number" id="days" name="days" value="30" min="1" max="365" required>
                    <span style="color: var(--text-muted); font-size: 13px; margin-left: 8px;">
                        （推荐：30天）
                    </span>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="cleanup_all" name="cleanup_all" value="1" onchange="toggleCleanupMode()">
                        <span style="color: #ef4444; font-weight: bold;">清空所有数据（忽略天数限制）</span>
                    </label>
                </div>
                
                <div id="preview-info" style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); padding: 12px; border-radius: 6px; margin: 16px 0; display: none;">
                    <span id="preview-text"></span>
                </div>
                
                <div style="display: flex; gap: 12px;">
                    <button type="button" onclick="previewCleanup()" class="btn" style="background: #3b82f6;">
                        👁️ 预览将删除的数据
                    </button>
                    <button type="submit" name="cleanup" class="btn btn-danger">
                        🗑️ 开始清理
                    </button>
                    <a href="logs.php" class="btn btn-secondary">取消</a>
                </div>
            </form>
            
            <script>
            function toggleCleanupMode() {
                const cleanupAll = document.getElementById('cleanup_all').checked;
                const daysInput = document.getElementById('days');
                daysInput.disabled = cleanupAll;
                if (cleanupAll) {
                    daysInput.style.opacity = '0.5';
                } else {
                    daysInput.style.opacity = '1';
                }
                // 清空预览
                document.getElementById('preview-info').style.display = 'none';
            }
            
            function previewCleanup() {
                const feature = document.getElementById('feature').value;
                const days = document.getElementById('days').value;
                const cleanupAll = document.getElementById('cleanup_all').checked;
                
                // 发送预览请求
                fetch('cleanup_logs.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `ajax=1&action=preview&feature=${encodeURIComponent(feature)}&days=${days}&cleanup_all=${cleanupAll ? '1' : '0'}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const previewInfo = document.getElementById('preview-info');
                        const previewText = document.getElementById('preview-text');
                        const featureText = feature === 'all' ? '全部模块' : '「' + feature + '」模块';
                        
                        if (cleanupAll) {
                            previewText.innerHTML = `📊 将清空${featureText}的 <strong>${data.count.toLocaleString()}</strong> 条记录`;
                        } else {
                            previewText.innerHTML = `📊 将删除${featureText}中 ${days} 天前的 <strong>${data.count.toLocaleString()}</strong> 条记录`;
                        }
                        previewInfo.style.display = 'block';
                    }
                })
                .catch(error => {
                    alert('预览失败：' + error);
                });
            }
            
            function confirmCleanup() {
                const feature = document.getElementById('feature').value;
                const days = document.getElementById('days').value;
                const cleanupAll = document.getElementById('cleanup_all').checked;
                const featureText = feature === 'all' ? '全部模块' : '「' + feature + '」模块';
                
                let message;
                if (cleanupAll) {
                    message = `⚠️ 确定要清空${featureText}的所有日志吗？\n\n这将删除该模块的全部数据！\n此操作不可恢复！`;
                } else {
                    message = `确定要清理${featureText}中 ${days} 天前的日志吗？\n\n此操作不可恢复！`;
                }
                return confirm(message);
            }
            </script>
        </div>
        
        <!-- 快捷操作 -->
        <div style="display: flex; gap: 12px;">
            <a href="optimize_logs_db.php" class="btn btn-primary">
                🚀 优化数据库
            </a>
            <a href="logs.php" class="btn btn-secondary">
                📝 返回日志
            </a>
        </div>
        
    <?php endif; ?>
</div>

<?php
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

require_once __DIR__ . '/footer.php';
?>

