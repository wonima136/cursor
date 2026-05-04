<?php
/**
 * Web界面执行数据库迁移
 */

// 简单的安全检查
$secret = $_GET['secret'] ?? '';
if ($secret !== 'migrate2024') {
    die('Access denied');
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>数据库迁移</title>
    <style>
        body {
            font-family: monospace;
            padding: 20px;
            background: #1a1d2e;
            color: #e2e8f0;
        }
        .output {
            background: #0f1117;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #2d3548;
            white-space: pre-wrap;
            margin-top: 20px;
        }
        .success {
            color: #22c55e;
        }
        .error {
            color: #ef4444;
        }
        .btn {
            background: #3b82f6;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
        }
        .btn:hover {
            background: #2563eb;
        }
    </style>
</head>
<body>
    <h1>🔧 数据库迁移工具</h1>
    <p>添加 extracted_links 表</p>
    
    <?php
    if (isset($_POST['run'])) {
        echo '<div class="output">';
        
        require_once __DIR__ . '/config.php';
        
        $dbFile = __DIR__ . '/data/focus.db';
        
        if (!file_exists($dbFile)) {
            echo '<span class="error">❌ 数据库文件不存在: ' . $dbFile . '</span>';
        } else {
            try {
                $db = new SQLite3($dbFile);
                $db->busyTimeout(5000);
                
                echo "开始添加 extracted_links 表...\n\n";
                
                // 检查表是否已存在
                $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='extracted_links'");
                if ($result->fetchArray()) {
                    echo '<span class="success">✓ extracted_links 表已存在，无需创建</span>' . "\n";
                } else {
                    $db->exec("
                        CREATE TABLE IF NOT EXISTS extracted_links (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            task_id VARCHAR(50) NOT NULL,
                            url VARCHAR(500) NOT NULL,
                            keyword VARCHAR(255),
                            redirect_count INTEGER DEFAULT 0,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            FOREIGN KEY (task_id) REFERENCES focus_tasks(id) ON DELETE CASCADE
                        )
                    ");
                    
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_extracted_task_id ON extracted_links(task_id)");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_extracted_url ON extracted_links(url)");
                    
                    echo '<span class="success">✓ extracted_links 表创建成功</span>' . "\n";
                }
                
                // 验证表结构
                $result = $db->query("PRAGMA table_info(extracted_links)");
                echo "\n表结构:\n";
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    echo "  - {$row['name']} ({$row['type']})\n";
                }
                
                $db->close();
                
                echo "\n" . '<span class="success">✓ 迁移完成！</span>' . "\n";
                echo "\n现在可以返回任务详情页重新测试提取链接功能。";
                
            } catch (Exception $e) {
                echo '<span class="error">❌ 错误: ' . htmlspecialchars($e->getMessage()) . '</span>';
            }
        }
        
        echo '</div>';
    } else {
        ?>
        <form method="POST">
            <button type="submit" name="run" class="btn">▶️ 执行迁移</button>
        </form>
        <?php
    }
    ?>
</body>
</html>

