<?php
/**
 * 数据库迁移：添加 extracted_links 表
 */

require_once __DIR__ . '/config.php';

$dbFile = __DIR__ . '/data/focus.db';

if (!file_exists($dbFile)) {
    die("数据库文件不存在: {$dbFile}\n");
}

try {
    $db = new SQLite3($dbFile);
    $db->busyTimeout(5000);
    
    echo "开始添加 extracted_links 表...\n";
    
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
    
    echo "✓ extracted_links 表创建成功\n";
    
    $db->close();
    
    echo "迁移完成！\n";
    
} catch (Exception $e) {
    echo "❌ 错误: " . $e->getMessage() . "\n";
    exit(1);
}

