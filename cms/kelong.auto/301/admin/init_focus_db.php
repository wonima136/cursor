<?php
/**
 * 智能集权重定向模块 - 数据库初始化脚本
 * 
 * 功能：创建SQLite数据库和所有必要的表结构
 */

require_once __DIR__ . '/config.php';

// 数据库文件路径
$dbFile = __DIR__ . '/data/focus.db';

// 如果数据库已存在，询问是否重新创建
if (file_exists($dbFile)) {
    echo "数据库文件已存在: {$dbFile}\n";
    echo "是否删除并重新创建？(yes/no): ";
    $input = trim(fgets(STDIN));
    if ($input !== 'yes') {
        echo "操作已取消。\n";
        exit;
    }
    unlink($dbFile);
    echo "已删除旧数据库文件。\n";
}

try {
    // 创建数据库连接
    $db = new SQLite3($dbFile);
    $db->busyTimeout(5000);
    
    // 启用外键约束
    $db->exec('PRAGMA foreign_keys = ON');
    
    // 启用WAL模式（提高并发性能）
    $db->exec('PRAGMA journal_mode = WAL');
    
    echo "开始创建数据库表...\n\n";
    
    // ==================== 表1: domains - 域名表 ====================
    echo "创建 domains 表...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS domains (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain VARCHAR(255) UNIQUE NOT NULL,
            brand_name VARCHAR(255),
            data_type VARCHAR(50),
            group_name VARCHAR(255),
            status INTEGER DEFAULT 1,
            site_title TEXT,
            site_keywords TEXT,
            site_description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    $db->exec("CREATE INDEX IF NOT EXISTS idx_domain ON domains(domain)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_data_type ON domains(data_type)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_group_name ON domains(group_name)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_status ON domains(status)");
    echo "✓ domains 表创建成功\n\n";
    
    // ==================== 表2: url_keywords - URL关键词映射表 ====================
    echo "创建 url_keywords 表...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS url_keywords (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain_id INTEGER NOT NULL,
            subdomain_prefix VARCHAR(100),
            keyword VARCHAR(255) NOT NULL,
            full_url VARCHAR(500) NOT NULL UNIQUE,
            url_type VARCHAR(20) DEFAULT 'subdomain',
            terminal_group VARCHAR(50),
            is_locked INTEGER DEFAULT 0,
            locked_by_task_id VARCHAR(50),
            locked_at DATETIME,
            last_redirect_at DATETIME,
            redirect_count INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
        )
    ");
    
    $db->exec("CREATE INDEX IF NOT EXISTS idx_keyword ON url_keywords(keyword)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_domain_keyword ON url_keywords(domain_id, keyword)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_locked ON url_keywords(is_locked)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_full_url ON url_keywords(full_url)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_url_type ON url_keywords(url_type)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_terminal_group ON url_keywords(terminal_group)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_last_redirect ON url_keywords(last_redirect_at)");
    echo "✓ url_keywords 表创建成功\n\n";
    
    // ==================== 表3: keyword_stats - 关键词统计表 ====================
    echo "创建 keyword_stats 表...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS keyword_stats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            keyword VARCHAR(255) UNIQUE NOT NULL,
            total_count INTEGER DEFAULT 0,
            available_count INTEGER DEFAULT 0,
            locked_count INTEGER DEFAULT 0,
            last_cleaned_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    $db->exec("CREATE INDEX IF NOT EXISTS idx_keyword_stats ON keyword_stats(keyword)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_total_count ON keyword_stats(total_count DESC)");
    echo "✓ keyword_stats 表创建成功\n\n";
    
    // ==================== 表4: focus_tasks - 集权任务表 ====================
    echo "创建 focus_tasks 表...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS focus_tasks (
            id VARCHAR(50) PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            mode VARCHAR(50) DEFAULT 'focus',
            target_url VARCHAR(500) NOT NULL,
            target_keyword VARCHAR(255),
            filter_keywords TEXT,
            filter_data_types TEXT,
            filter_groups TEXT,
            schedule_days INTEGER DEFAULT 0,
            schedule_hours INTEGER DEFAULT 0,
            schedule_minutes INTEGER DEFAULT 0,
            redirect_type INTEGER DEFAULT 301,
            probability INTEGER DEFAULT 100,
            enabled INTEGER DEFAULT 1,
            spider_filter TEXT,
            locked_urls_count INTEGER DEFAULT 0,
            total_redirects INTEGER DEFAULT 0,
            last_redirect_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    $db->exec("CREATE INDEX IF NOT EXISTS idx_enabled ON focus_tasks(enabled)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_target_keyword ON focus_tasks(target_keyword)");
    echo "✓ focus_tasks 表创建成功\n\n";
    
    // ==================== 表5: task_url_locks - 任务URL锁定关系表 ====================
    echo "创建 task_url_locks 表...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS task_url_locks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id VARCHAR(50) NOT NULL,
            url_keyword_id INTEGER NOT NULL,
            locked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES focus_tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (url_keyword_id) REFERENCES url_keywords(id) ON DELETE CASCADE,
            UNIQUE (task_id, url_keyword_id)
        )
    ");
    
    $db->exec("CREATE INDEX IF NOT EXISTS idx_task_id ON task_url_locks(task_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_url_keyword_id ON task_url_locks(url_keyword_id)");
    echo "✓ task_url_locks 表创建成功\n\n";
    
    // ==================== 表6: extracted_links - 提取的链接临时表 ====================
    echo "创建 extracted_links 表...\n";
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
    echo "✓ extracted_links 表创建成功\n\n";
    
    // ==================== 表7: focus_redirect_logs - 集权跳转日志表 ====================
    echo "创建 focus_redirect_logs 表...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS focus_redirect_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id VARCHAR(50) NOT NULL,
            url_keyword_id INTEGER NOT NULL,
            source_url VARCHAR(500) NOT NULL,
            target_url VARCHAR(500) NOT NULL,
            client_ip VARCHAR(50),
            user_agent TEXT,
            spider_type VARCHAR(50),
            redirect_type INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES focus_tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (url_keyword_id) REFERENCES url_keywords(id) ON DELETE CASCADE
        )
    ");
    
    $db->exec("CREATE INDEX IF NOT EXISTS idx_log_task_id ON focus_redirect_logs(task_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_log_created_at ON focus_redirect_logs(created_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_log_url_keyword_id ON focus_redirect_logs(url_keyword_id)");
    echo "✓ focus_redirect_logs 表创建成功\n\n";
    
    // 关闭数据库连接
    $db->close();
    
    echo "========================================\n";
    echo "✓ 数据库初始化完成！\n";
    echo "数据库文件: {$dbFile}\n";
    echo "========================================\n";
    
} catch (Exception $e) {
    echo "❌ 错误: " . $e->getMessage() . "\n";
    exit(1);
}

