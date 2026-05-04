<?php
/**
 * 数据迁移脚本：将单文件数据库迁移到按天分库
 * 
 * 将 spider_data.db (1.3G) 按日期拆分为：
 * spider_data/
 * ├── 2025-12-30.db
 * ├── 2025-12-29.db
 * └── ...
 * 
 * 使用方法（推荐命令行执行）：
 * cd /www/wwwroot/site/xxf.qiye/hnseo
 * php migrate_to_daily.php
 * 
 * 或使用 nohup 后台运行：
 * nohup php migrate_to_daily.php > migrate.log 2>&1 &
 */

// 检查是否命令行执行
$is_cli = php_sapi_name() === 'cli';

if (!$is_cli) {
    die('此脚本需要通过命令行运行，请使用: php migrate_to_daily.php');
}

// 增加资源限制
set_time_limit(0);
ini_set('memory_limit', '1024M');

// 输出函数（命令行专用）
function output($message) {
    echo $message . "\n";
    // 实时刷新输出
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

// 命令行输出样式
echo "\n";
echo "========================================\n";
echo "  📦 单文件数据库迁移到按天分库\n";
echo "========================================\n";
echo "\n";

output("=== 开始数据迁移 ===");
output("时间: " . date('Y-m-d H:i:s'));
output("");

$old_db_path = __DIR__ . '/spider_data.db';
$new_db_dir = __DIR__ . '/spider_data';

// 检查旧数据库是否存在
if (!file_exists($old_db_path)) {
    output("❌ 旧数据库文件不存在: $old_db_path");
    output("如果您已经完成迁移，可以忽略此消息。");
    exit;
}

// 创建新目录
if (!is_dir($new_db_dir)) {
    mkdir($new_db_dir, 0755, true);
    output("✓ 创建目录: $new_db_dir");
}

try {
    // 连接旧数据库
    $old_db = new PDO('sqlite:' . $old_db_path);
    $old_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    output("✓ 连接旧数据库成功");
    
    // 获取总记录数
    $stmt = $old_db->query("SELECT COUNT(*) FROM spider_visits");
    $total_records = $stmt->fetchColumn();
    output("总记录数: " . number_format($total_records));
    output("");
    
    // 获取所有不同的日期
    output("正在分析日期分布...");
    $stmt = $old_db->query("SELECT DISTINCT visit_date FROM spider_visits ORDER BY visit_date");
    $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
    output("共 " . count($dates) . " 个日期");
    output("");
    
    // 新数据库连接缓存
    $new_dbs = array();
    
    // 创建表结构的函数
    $create_table_sql = '
        CREATE TABLE IF NOT EXISTS spider_visits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_time DATETIME NOT NULL,
            ip VARCHAR(50) NOT NULL,
            spider_type VARCHAR(50) NOT NULL,
            spider_name VARCHAR(50) NOT NULL,
            url TEXT NOT NULL,
            domain VARCHAR(255) NOT NULL,
            visit_hour INTEGER NOT NULL
        )
    ';
    
    $create_indexes = array(
        'CREATE INDEX IF NOT EXISTS idx_domain ON spider_visits(domain)',
        'CREATE INDEX IF NOT EXISTS idx_spider_type ON spider_visits(spider_type)',
        'CREATE INDEX IF NOT EXISTS idx_hour ON spider_visits(visit_hour)'
    );
    
    // 逐日期迁移
    $migrated_total = 0;
    
    foreach ($dates as $date) {
        output("处理日期: $date");
        
        // 获取该日期的记录
        $stmt = $old_db->prepare("
            SELECT visit_time, ip, spider_type, spider_name, url, domain, visit_hour
            FROM spider_visits 
            WHERE visit_date = ?
        ");
        $stmt->execute(array($date));
        
        // 创建该日期的数据库
        $new_db_path = $new_db_dir . '/' . $date . '.db';
        
        if (!isset($new_dbs[$date])) {
            $new_db = new PDO('sqlite:' . $new_db_path);
            $new_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $new_db->exec('PRAGMA journal_mode = WAL');
            $new_db->exec('PRAGMA synchronous = NORMAL');
            $new_db->exec($create_table_sql);
            foreach ($create_indexes as $idx_sql) {
                $new_db->exec($idx_sql);
            }
            $new_dbs[$date] = $new_db;
        } else {
            $new_db = $new_dbs[$date];
        }
        
        // 批量插入
        $new_db->beginTransaction();
        $insert_stmt = $new_db->prepare('
            INSERT INTO spider_visits (visit_time, ip, spider_type, spider_name, url, domain, visit_hour)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        
        $day_count = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $insert_stmt->execute(array(
                $row['visit_time'],
                $row['ip'],
                $row['spider_type'],
                $row['spider_name'],
                $row['url'],
                $row['domain'],
                $row['visit_hour']
            ));
            $day_count++;
            
            // 每10000条提交一次
            if ($day_count % 10000 == 0) {
                $new_db->commit();
                $new_db->beginTransaction();
            }
        }
        
        $new_db->commit();
        $migrated_total += $day_count;
        
        // 优化该日期的数据库
        $new_db->exec('VACUUM');
        
        // 获取文件大小
        $file_size = round(filesize($new_db_path) / 1024 / 1024, 2);
        
        output("  - 记录数: " . number_format($day_count) . " | 文件大小: {$file_size} MB");
    }
    
    output("");
    output("=== 迁移完成 ===");
    output("迁移记录数: " . number_format($migrated_total));
    output("日期数量: " . count($dates));
    
    // 计算新文件总大小
    $total_size = 0;
    $files = glob($new_db_dir . '/*.db');
    foreach ($files as $file) {
        $total_size += filesize($file);
    }
    output("新数据库总大小: " . round($total_size / 1024 / 1024, 2) . " MB");
    output("旧数据库大小: " . round(filesize($old_db_path) / 1024 / 1024, 2) . " MB");
    output("");
    
    // 重命名旧数据库
    $backup_path = $old_db_path . '.backup';
    rename($old_db_path, $backup_path);
    output("✓ 旧数据库已重命名为: " . basename($backup_path));
    output("");
    output("如果确认迁移成功，可以手动删除备份文件：");
    output("  rm " . $backup_path);
    output("");
    output("完成时间: " . date('Y-m-d H:i:s'));
    output("");
    output("========================================");
    output("  迁移完成！");
    output("========================================");
    
} catch (Exception $e) {
    output("❌ 迁移过程中发生错误: " . $e->getMessage());
    // error_log("Migration Error: " . $e->getMessage());
}

