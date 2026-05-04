<?php
/**
 * 数据库管理页面
 * 查看各日期数据库文件、删除历史数据
 */
ini_set('session.save_handler', 'files');
ini_set('session.save_path', '/tmp');
session_name('hnseo_tongji');
session_start();

// 验证登录
if (!isset($_SESSION['tongji_logged_in']) || $_SESSION['tongji_logged_in'] !== true) {
    header('Location: tongji.php');
    exit;
}

require_once __DIR__ . '/spider_db.php';

// 处理操作
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    
    if ($action === 'delete_date') {
        $date = isset($_POST['date']) ? $_POST['date'] : '';
        if ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $db = getSpiderDB();
            if ($db->deleteDate($date)) {
                $message = "已删除 $date 的数据";
            } else {
                $error = "删除 $date 失败";
            }
        }
    } elseif ($action === 'delete_before') {
        $before_date = isset($_POST['before_date']) ? $_POST['before_date'] : '';
        if ($before_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $before_date)) {
            $db = getSpiderDB();
            $dates = $db->getAvailableDates();
            $deleted_count = 0;
            foreach ($dates as $date) {
                if ($date < $before_date) {
                    if ($db->deleteDate($date)) {
                        $deleted_count++;
                    }
                }
            }
            $message = "已删除 $deleted_count 个日期的数据";
        }
    } elseif ($action === 'optimize') {
        $date = isset($_POST['date']) ? $_POST['date'] : '';
        if ($date) {
            $db = getSpiderDB();
            $db->optimize($date);
            $message = "已优化 $date 的数据库";
        }
    } elseif ($action === 'optimize_all') {
        $db = getSpiderDB();
        $db->optimize();
        $message = "已优化所有数据库";
    }
}

// 获取数据
$db = getSpiderDB();
$storage_info = $db->getStorageInfo();
$dates = $db->getAvailableDates();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>数据库管理 - 蜘蛛统计</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            color: #fff;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        h1 { font-size: 24px; font-weight: 600; }
        .back-link { 
            color: #4CAF50; 
            text-decoration: none;
            font-size: 14px;
        }
        .back-link:hover { text-decoration: underline; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: rgba(255,255,255,0.05);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }
        .stat-value { font-size: 32px; font-weight: 700; color: #4CAF50; }
        .stat-label { font-size: 14px; color: #aaa; margin-top: 5px; }
        
        .message { 
            padding: 15px; 
            border-radius: 8px; 
            margin-bottom: 20px;
        }
        .message.success { background: rgba(76, 175, 80, 0.2); border: 1px solid #4CAF50; }
        .message.error { background: rgba(244, 67, 54, 0.2); border: 1px solid #f44336; }
        
        .actions-panel {
            background: rgba(255,255,255,0.05);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .actions-panel h3 { margin-bottom: 15px; font-size: 16px; }
        .action-row {
            display: flex;
            gap: 15px;
            align-items: center;
            margin-bottom: 15px;
        }
        .action-row:last-child { margin-bottom: 0; }
        
        input[type="date"] {
            padding: 8px 12px;
            border: 1px solid #444;
            border-radius: 6px;
            background: #2a2a3e;
            color: #fff;
            font-size: 14px;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
        }
        .btn-primary { background: #4CAF50; color: #fff; }
        .btn-primary:hover { background: #45a049; }
        .btn-danger { background: #f44336; color: #fff; }
        .btn-danger:hover { background: #d32f2f; }
        .btn-warning { background: #ff9800; color: #fff; }
        .btn-warning:hover { background: #f57c00; }
        .btn-small { padding: 5px 10px; font-size: 12px; }
        
        .data-table {
            background: rgba(255,255,255,0.05);
            border-radius: 12px;
            overflow: hidden;
        }
        .data-table h3 { 
            padding: 15px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            font-size: 16px;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { 
            padding: 12px 20px; 
            text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        th { 
            background: rgba(255,255,255,0.03);
            font-weight: 600;
            color: #aaa;
            font-size: 13px;
        }
        tr:hover { background: rgba(255,255,255,0.03); }
        .size-bar {
            height: 8px;
            background: #333;
            border-radius: 4px;
            overflow: hidden;
            width: 100px;
            display: inline-block;
            margin-right: 10px;
        }
        .size-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #4CAF50, #8BC34A);
            border-radius: 4px;
        }
        .text-muted { color: #888; }
        
        .confirm-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .confirm-modal.active { display: flex; }
        .modal-content {
            background: #2a2a3e;
            border-radius: 12px;
            padding: 30px;
            max-width: 400px;
            text-align: center;
        }
        .modal-content h4 { margin-bottom: 15px; }
        .modal-content p { color: #aaa; margin-bottom: 20px; }
        .modal-buttons { display: flex; gap: 10px; justify-content: center; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 数据库管理</h1>
            <a href="tongji.php" class="back-link">← 返回统计面板</a>
        </div>
        
        <?php if ($message): ?>
        <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo count($storage_info['files']); ?></div>
                <div class="stat-label">数据库文件数</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $storage_info['total_size_mb']; ?> MB</div>
                <div class="stat-label">总占用空间</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($db->getTotalCount()); ?></div>
                <div class="stat-label">总记录数</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count($dates) > 0 ? $dates[count($dates)-1] : '-'; ?></div>
                <div class="stat-label">最早日期</div>
            </div>
        </div>
        
        <div class="actions-panel">
            <h3>🛠️ 批量操作</h3>
            <div class="action-row">
                <form method="post" style="display:flex;gap:15px;align-items:center;">
                    <input type="hidden" name="action" value="delete_before">
                    <label>删除早于此日期的所有数据：</label>
                    <input type="date" name="before_date" required>
                    <button type="submit" class="btn btn-danger" onclick="return confirm('确定要删除这些数据吗？此操作不可恢复！');">删除</button>
                </form>
            </div>
            <div class="action-row">
                <form method="post">
                    <input type="hidden" name="action" value="optimize_all">
                    <button type="submit" class="btn btn-warning">优化所有数据库</button>
                </form>
                <span class="text-muted">执行 VACUUM 和 ANALYZE 以优化性能</span>
            </div>
        </div>
        
        <div class="data-table">
            <h3>📁 数据库文件列表</h3>
            <table>
                <thead>
                    <tr>
                        <th>日期</th>
                        <th>文件大小</th>
                        <th>记录数</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $max_size = 1;
                    foreach ($storage_info['files'] as $file) {
                        if ($file['size_mb'] > $max_size) $max_size = $file['size_mb'];
                    }
                    
                    foreach ($storage_info['files'] as $file): 
                        $date = $file['date'];
                        $pdo = $db->getDBByDate($date);
                        $count = 0;
                        if ($pdo) {
                            $stmt = $pdo->query('SELECT COUNT(*) FROM spider_visits');
                            $count = $stmt->fetchColumn();
                        }
                        $bar_width = ($file['size_mb'] / $max_size) * 100;
                    ?>
                    <tr>
                        <td><?php echo $date; ?></td>
                        <td>
                            <div class="size-bar">
                                <div class="size-bar-fill" style="width:<?php echo $bar_width; ?>%"></div>
                            </div>
                            <?php echo $file['size_mb']; ?> MB
                        </td>
                        <td><?php echo number_format($count); ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="optimize">
                                <input type="hidden" name="date" value="<?php echo $date; ?>">
                                <button type="submit" class="btn btn-warning btn-small">优化</button>
                            </form>
                            <form method="post" style="display:inline;margin-left:5px;">
                                <input type="hidden" name="action" value="delete_date">
                                <input type="hidden" name="date" value="<?php echo $date; ?>">
                                <button type="submit" class="btn btn-danger btn-small" 
                                    onclick="return confirm('确定要删除 <?php echo $date; ?> 的数据吗？');">删除</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($storage_info['files'])): ?>
                    <tr>
                        <td colspan="4" style="text-align:center;color:#888;padding:30px;">
                            暂无数据
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>

