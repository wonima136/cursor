<?php
/**
 * 智能集权重定向模块 - 后台函数
 */

// 引入Redis配置
require_once __DIR__ . '/redis_config.php';

// ==================== 数据库连接 ====================

/**
 * 获取Focus数据库连接
 */
function _focus_getDB() {
    static $db = null;
    
    if ($db === null) {
        $dbFile = __DIR__ . '/data/focus.db';
        $needsInit = !file_exists($dbFile);
        
        error_log("Focus数据库路径: " . $dbFile);
        error_log("数据库文件存在: " . ($needsInit ? '否' : '是'));
        
        // 确保data目录存在
        $dataDir = dirname($dbFile);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0775, true);
            @chmod($dataDir, 0775);
            error_log("创建data目录: " . $dataDir);
        }
        
        // 创建或打开数据库
        $db = new SQLite3($dbFile);
        $db->busyTimeout(5000);
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('PRAGMA journal_mode = WAL');
        
        // 如果是新建数据库，初始化表结构并设置权限
        if ($needsInit) {
            error_log("初始化数据库表结构...");
            _focus_initDatabase($db);
            
            // 设置数据库文件权限（使用@抑制错误，因为某些服务器可能禁用chmod）
            @chmod($dbFile, 0775);
            
            // WAL模式会创建额外的文件，也需要设置权限
            $walFile = $dbFile . '-wal';
            $shmFile = $dbFile . '-shm';
            if (file_exists($walFile)) {
                @chmod($walFile, 0775);
            }
            if (file_exists($shmFile)) {
                @chmod($shmFile, 0775);
            }
            
            error_log("数据库文件权限已设置为775");
        } else {
            // 数据库已存在，检查并自动迁移表结构
            _focus_autoMigrate($db);
        }
        
        error_log("数据库连接成功，WAL模式已启用");
    }
    
    return $db;
}

/**
 * 自动迁移数据库表结构（添加缺失的字段）
 */
function _focus_autoMigrate($db) {
    try {
        // 检查domains表是否缺少字段
        $result = $db->query("PRAGMA table_info(domains)");
        $columns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[] = $row['name'];
        }
        
        // 添加缺失的字段到domains表
        if (!in_array('group_name', $columns)) {
            $db->exec("ALTER TABLE domains ADD COLUMN group_name VARCHAR(100)");
            error_log("已添加字段: domains.group_name");
        }
        if (!in_array('status', $columns)) {
            $db->exec("ALTER TABLE domains ADD COLUMN status VARCHAR(50)");
            error_log("已添加字段: domains.status");
        }
        if (!in_array('site_title', $columns)) {
            $db->exec("ALTER TABLE domains ADD COLUMN site_title VARCHAR(500)");
            error_log("已添加字段: domains.site_title");
        }
        if (!in_array('site_keywords', $columns)) {
            $db->exec("ALTER TABLE domains ADD COLUMN site_keywords TEXT");
            error_log("已添加字段: domains.site_keywords");
        }
        if (!in_array('site_description', $columns)) {
            $db->exec("ALTER TABLE domains ADD COLUMN site_description TEXT");
            error_log("已添加字段: domains.site_description");
        }
        if (!in_array('updated_at', $columns)) {
            $db->exec("ALTER TABLE domains ADD COLUMN updated_at DATETIME");
            error_log("已添加字段: domains.updated_at");
        }
        
        // 检查url_keywords表是否缺少字段
        $result = $db->query("PRAGMA table_info(url_keywords)");
        $columns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[] = $row['name'];
        }
        
        if (!in_array('redirect_count', $columns)) {
            $db->exec("ALTER TABLE url_keywords ADD COLUMN redirect_count INTEGER DEFAULT 0");
            error_log("已添加字段: url_keywords.redirect_count");
        }
        if (!in_array('last_redirect_at', $columns)) {
            $db->exec("ALTER TABLE url_keywords ADD COLUMN last_redirect_at DATETIME");
            error_log("已添加字段: url_keywords.last_redirect_at");
        }
        
        // 检查focus_tasks表是否缺少字段
        $result = $db->query("PRAGMA table_info(focus_tasks)");
        $columns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[] = $row['name'];
        }
        
        // 添加缺失的字段到focus_tasks表
        if (!in_array('spider_filter', $columns)) {
            $db->exec("ALTER TABLE focus_tasks ADD COLUMN spider_filter TEXT");
            error_log("已添加字段: focus_tasks.spider_filter");
        }
        if (!in_array('locked_urls_count', $columns)) {
            $db->exec("ALTER TABLE focus_tasks ADD COLUMN locked_urls_count INTEGER DEFAULT 0");
            error_log("已添加字段: focus_tasks.locked_urls_count");
        }
        if (!in_array('exclude_domains', $columns)) {
            $db->exec("ALTER TABLE focus_tasks ADD COLUMN exclude_domains TEXT");
            error_log("已添加字段: focus_tasks.exclude_domains");
        }
        if (!in_array('exclude_groups', $columns)) {
            $db->exec("ALTER TABLE focus_tasks ADD COLUMN exclude_groups TEXT");
            error_log("已添加字段: focus_tasks.exclude_groups");
        }
        if (!in_array('exclude_types', $columns)) {
            $db->exec("ALTER TABLE focus_tasks ADD COLUMN exclude_types TEXT");
            error_log("已添加字段: focus_tasks.exclude_types");
        }
        
        // 检查是否存在focus_redirect_logs表
        $tableExists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='focus_redirect_logs'");
        if (!$tableExists) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS focus_redirect_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    task_id VARCHAR(50) NOT NULL,
                    url_keyword_id INTEGER,
                    source_url VARCHAR(500) NOT NULL,
                    target_url VARCHAR(500) NOT NULL,
                    client_ip VARCHAR(50),
                    user_agent TEXT,
                    spider_type VARCHAR(50),
                    redirect_type INTEGER,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (task_id) REFERENCES focus_tasks(id) ON DELETE CASCADE,
                    FOREIGN KEY (url_keyword_id) REFERENCES url_keywords(id) ON DELETE SET NULL
                )
            ");
            error_log("已创建表: focus_redirect_logs");
        }
        
        // 检查是否存在extracted_links_locked表
        $tableExists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='extracted_links_locked'");
        if (!$tableExists) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS extracted_links_locked (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    task_id VARCHAR(50) NOT NULL,
                    url VARCHAR(500) NOT NULL,
                    keyword VARCHAR(255),
                    redirect_count INTEGER DEFAULT 0,
                    locked_by_task_id VARCHAR(50),
                    locked_by_task_name VARCHAR(500),
                    locked_at DATETIME,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (task_id) REFERENCES focus_tasks(id) ON DELETE CASCADE
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_extracted_locked_task ON extracted_links_locked(task_id)");
            error_log("已创建表: extracted_links_locked");
        }
        
        error_log("数据库表结构检查完成");
    } catch (Exception $e) {
        error_log("自动迁移失败: " . $e->getMessage());
    }
}

/**
 * 初始化数据库表结构
 */
function _focus_initDatabase($db) {
    // 1. 创建域名表
    $db->exec("
        CREATE TABLE IF NOT EXISTS domains (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain VARCHAR(255) NOT NULL UNIQUE,
            brand_name VARCHAR(255),
            data_type VARCHAR(100),
            group_name VARCHAR(100),
            status VARCHAR(50),
            site_title VARCHAR(500),
            site_keywords TEXT,
            site_description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // 2. 创建URL关键词表
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
            redirect_count INTEGER DEFAULT 0,
            last_redirect_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
        )
    ");
    
    // 3. 创建索引
    $db->exec("CREATE INDEX IF NOT EXISTS idx_url_keywords_domain ON url_keywords(domain_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_url_keywords_keyword ON url_keywords(keyword)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_url_keywords_locked ON url_keywords(is_locked, locked_by_task_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_url_keywords_full_url ON url_keywords(full_url)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_domains_domain ON domains(domain)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_domains_type_group ON domains(data_type, group_name)");
    
    // 4. 创建任务表
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
            spider_filter TEXT,
            enabled INTEGER DEFAULT 1,
            redirect_count INTEGER DEFAULT 0,
            locked_urls_count INTEGER DEFAULT 0,
            exclude_domains TEXT,
            exclude_groups TEXT,
            exclude_types TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // 5. 创建提取链接表（可用链接）
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
    
    // 5.1 创建已锁定链接表（用于显示已被其他任务锁定的链接）
    $db->exec("
        CREATE TABLE IF NOT EXISTS extracted_links_locked (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id VARCHAR(50) NOT NULL,
            url VARCHAR(500) NOT NULL,
            keyword VARCHAR(255),
            redirect_count INTEGER DEFAULT 0,
            locked_by_task_id VARCHAR(50),
            locked_by_task_name VARCHAR(500),
            locked_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES focus_tasks(id) ON DELETE CASCADE
        )
    ");
    
    $db->exec("CREATE INDEX IF NOT EXISTS idx_extracted_locked_task ON extracted_links_locked(task_id)");
    
    // 6. 创建关键词统计表
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
    
    // 7. 创建跳转日志表
    $db->exec("
        CREATE TABLE IF NOT EXISTS focus_redirect_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id VARCHAR(50) NOT NULL,
            url_keyword_id INTEGER,
            source_url VARCHAR(500) NOT NULL,
            target_url VARCHAR(500) NOT NULL,
            client_ip VARCHAR(50),
            user_agent TEXT,
            spider_type VARCHAR(50),
            redirect_type INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES focus_tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (url_keyword_id) REFERENCES url_keywords(id) ON DELETE SET NULL
        )
    ");
    
    // 8. 创建所有索引
    $db->exec("CREATE INDEX IF NOT EXISTS idx_focus_tasks_enabled ON focus_tasks(enabled)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_extracted_links_task ON extracted_links(task_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_extracted_links_url ON extracted_links(url)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_keyword_stats ON keyword_stats(keyword)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_total_count ON keyword_stats(total_count DESC)");
    
    error_log("数据库表结构初始化完成");
}

// ==================== 数据清洗功能 ====================

/**
 * 清洗网站数据（纯增量，不更新历史数据）
 */
function _focus_cleanSitesData() {
    $sitesFile = dirname(dirname(__DIR__)) . '/data/sites.json';
    
    if (!file_exists($sitesFile)) {
        return ['success' => false, 'message' => '源数据文件不存在: ' . $sitesFile];
    }
    
    $sitesData = json_decode(file_get_contents($sitesFile), true);
    
    if (!$sitesData || !is_array($sitesData)) {
        return ['success' => false, 'message' => '源数据格式错误'];
    }
    
    $db = _focus_getDB();
    $db->exec('BEGIN TRANSACTION');
    
    try {
        $totalSites = count($sitesData);
        $stats = [
            'total_sites' => $totalSites,
            'new_domains' => 0,
            'updated_domains' => 0,
            'new_urls' => 0,
            'skipped_urls' => 0,
            'new_keywords' => 0
        ];
        
        // 初始化进度
        _focus_updateProgress(0, $totalSites, '开始清洗数据...');
        
        $processedSites = 0;
        foreach ($sitesData as $site) {
            $processedSites++;
            
            // 更新进度
            $progressPercent = round(($processedSites / $totalSites) * 100);
            _focus_updateProgress(
                $processedSites, 
                $totalSites, 
                "正在处理: {$site['domain']} ({$processedSites}/{$totalSites})"
            );
            
            // 1. 插入/更新域名
            $domainResult = _focus_insertOrUpdateDomain($db, $site);
            if ($domainResult['is_new']) {
                $stats['new_domains']++;
            } else {
                $stats['updated_domains']++;
            }
            $domainId = $domainResult['id'];
            
            // 2. 处理顶级域名（三端：@/www/m）
            $terminalResult = _focus_processTopDomainThreeTerminals($db, $domainId, $site);
            $stats['new_urls'] += $terminalResult['new'];
            $stats['skipped_urls'] += $terminalResult['skipped'];
            
            // 3. 处理二级域名
            $prefixes = $site['subdomain_prefix_shared_tdk'] ?? [];
            
            foreach ($prefixes as $prefix => $keyword) {
                $fullUrl = $prefix . '.' . $site['domain'];
                
                // 检查是否已存在（不管是否锁定，都跳过）
                $stmt = $db->prepare("SELECT id FROM url_keywords WHERE full_url = ?");
                $stmt->bindValue(1, $fullUrl, SQLITE3_TEXT);
                $result = $stmt->execute();
                $exists = $result->fetchArray(SQLITE3_ASSOC);
                
                if ($exists) {
                    $stats['skipped_urls']++;
                    continue; // 跳过，不更新
                }
                
                // 新增
                _focus_insertUrlKeyword($db, $domainId, $prefix, $keyword, $fullUrl, 'subdomain', null);
                $stats['new_urls']++;
            }
            
            // 4. 更新关键词统计
            _focus_updateKeywordStats($db, $site['brand_name']);
            foreach ($prefixes as $keyword) {
                _focus_updateKeywordStats($db, $keyword);
            }
        }
        
        $db->exec('COMMIT');
        
        // 完成进度
        _focus_updateProgress($totalSites, $totalSites, '清洗完成！', true);
        
        $stats['success'] = true;
        $stats['message'] = "数据清洗完成！新增 {$stats['new_urls']} 个URL，跳过 {$stats['skipped_urls']} 个已存在的URL";
        
        return $stats;
        
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        _focus_updateProgress(0, 0, '清洗失败: ' . $e->getMessage(), true);
        return ['success' => false, 'message' => '清洗失败: ' . $e->getMessage()];
    }
}

/**
 * 更新清洗进度（使用文件缓存，避免session问题）
 */
function _focus_updateProgress($current, $total, $message, $completed = false) {
    $progressFile = __DIR__ . '/data/clean_progress.json';
    
    $progress = [
        'current' => $current,
        'total' => $total,
        'percent' => $total > 0 ? round(($current / $total) * 100) : 0,
        'message' => $message,
        'completed' => $completed,
        'timestamp' => time()
    ];
    
    @file_put_contents($progressFile, json_encode($progress));
}

/**
 * 获取清洗进度
 */
function _focus_getProgress() {
    $progressFile = __DIR__ . '/data/clean_progress.json';
    
    if (file_exists($progressFile)) {
        $data = @file_get_contents($progressFile);
        if ($data) {
            $progress = json_decode($data, true);
            if ($progress) {
                return $progress;
            }
        }
    }
    
    return [
        'current' => 0,
        'total' => 0,
        'percent' => 0,
        'message' => '未开始',
        'completed' => false,
        'timestamp' => time()
    ];
}

/**
 * 插入或更新域名
 */
function _focus_insertOrUpdateDomain($db, $site) {
    $domain = $site['domain'];
    
    // 检查是否已存在
    $stmt = $db->prepare("SELECT id FROM domains WHERE domain = ?");
    $stmt->bindValue(1, $domain, SQLITE3_TEXT);
    $result = $stmt->execute();
    $existing = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($existing) {
        // 更新域名信息（域名信息可以更新）
        $stmt = $db->prepare("
            UPDATE domains 
            SET brand_name = ?,
                data_type = ?,
                group_name = ?,
                status = ?,
                site_title = ?,
                site_keywords = ?,
                site_description = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->bindValue(1, $site['brand_name'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(2, $site['data_type'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(3, $site['group'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(4, $site['status'] ?? 1, SQLITE3_INTEGER);
        $stmt->bindValue(5, $site['site_title'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(6, $site['site_keywords'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(7, $site['site_description'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(8, $existing['id'], SQLITE3_INTEGER);
        $stmt->execute();
        
        return ['id' => $existing['id'], 'is_new' => false];
    } else {
        // 插入新域名
        $stmt = $db->prepare("
            INSERT INTO domains (domain, brand_name, data_type, group_name, status, site_title, site_keywords, site_description)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bindValue(1, $domain, SQLITE3_TEXT);
        $stmt->bindValue(2, $site['brand_name'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(3, $site['data_type'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(4, $site['group'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(5, $site['status'] ?? 1, SQLITE3_INTEGER);
        $stmt->bindValue(6, $site['site_title'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(7, $site['site_keywords'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(8, $site['site_description'] ?? '', SQLITE3_TEXT);
        $stmt->execute();
        
        return ['id' => $db->lastInsertRowID(), 'is_new' => true];
    }
}

/**
 * 处理顶级域名的三端（@/www/m）
 */
function _focus_processTopDomainThreeTerminals($db, $domainId, $site) {
    $domain = $site['domain'];
    $keyword = $site['brand_name'] ?? $domain;
    
    // 三端分组标识
    $terminalGroup = $domain . ':@';
    
    // 定义三端URL
    $terminals = [
        ['url' => $domain, 'type' => 'top_domain', 'prefix' => '@'],
        ['url' => 'www.' . $domain, 'type' => 'www', 'prefix' => 'www'],
        ['url' => 'm.' . $domain, 'type' => 'm', 'prefix' => 'm']
    ];
    
    $newCount = 0;
    $skippedCount = 0;
    
    foreach ($terminals as $terminal) {
        // 检查是否已存在
        $stmt = $db->prepare("SELECT id FROM url_keywords WHERE full_url = ?");
        $stmt->bindValue(1, $terminal['url'], SQLITE3_TEXT);
        $result = $stmt->execute();
        $exists = $result->fetchArray(SQLITE3_ASSOC);
        
        if ($exists) {
            $skippedCount++;
            continue; // 跳过，不更新
        }
        
        // 新增
        _focus_insertUrlKeyword(
            $db, 
            $domainId, 
            $terminal['prefix'], 
            $keyword, 
            $terminal['url'], 
            $terminal['type'],
            $terminalGroup
        );
        $newCount++;
    }
    
    return ['new' => $newCount, 'skipped' => $skippedCount];
}

/**
 * 插入URL关键词（纯新增，不更新）
 */
function _focus_insertUrlKeyword($db, $domainId, $prefix, $keyword, $fullUrl, $urlType, $terminalGroup) {
    try {
        $stmt = $db->prepare("
            INSERT INTO url_keywords 
            (domain_id, subdomain_prefix, keyword, full_url, url_type, terminal_group)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bindValue(1, $domainId, SQLITE3_INTEGER);
        $stmt->bindValue(2, $prefix, SQLITE3_TEXT);
        $stmt->bindValue(3, $keyword, SQLITE3_TEXT);
        $stmt->bindValue(4, $fullUrl, SQLITE3_TEXT);
        $stmt->bindValue(5, $urlType, SQLITE3_TEXT);
        $stmt->bindValue(6, $terminalGroup, SQLITE3_TEXT);
        $stmt->execute();
        
        return true;
    } catch (Exception $e) {
        // 如果违反UNIQUE约束，说明已存在，静默跳过
        if (strpos($e->getMessage(), 'UNIQUE constraint') !== false) {
            return false;
        }
        throw $e;
    }
}

/**
 * 更新关键词统计
 */
function _focus_updateKeywordStats($db, $keyword) {
    // 统计该关键词的总数和可用数
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN is_locked = 0 THEN 1 ELSE 0 END) as available,
            SUM(CASE WHEN is_locked = 1 THEN 1 ELSE 0 END) as locked
        FROM url_keywords
        WHERE keyword = ?
    ");
    $stmt->bindValue(1, $keyword, SQLITE3_TEXT);
    $result = $stmt->execute();
    $stats = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$stats) {
        return;
    }
    
    // 检查是否已存在
    $stmt = $db->prepare("SELECT id FROM keyword_stats WHERE keyword = ?");
    $stmt->bindValue(1, $keyword, SQLITE3_TEXT);
    $result = $stmt->execute();
    $exists = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($exists) {
        // 更新
        $stmt = $db->prepare("
            UPDATE keyword_stats 
            SET total_count = ?,
                available_count = ?,
                locked_count = ?,
                last_cleaned_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE keyword = ?
        ");
        $stmt->bindValue(1, $stats['total'], SQLITE3_INTEGER);
        $stmt->bindValue(2, $stats['available'], SQLITE3_INTEGER);
        $stmt->bindValue(3, $stats['locked'], SQLITE3_INTEGER);
        $stmt->bindValue(4, $keyword, SQLITE3_TEXT);
        $stmt->execute();
    } else {
        // 插入
        $stmt = $db->prepare("
            INSERT INTO keyword_stats (keyword, total_count, available_count, locked_count, last_cleaned_at)
            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $stmt->bindValue(1, $keyword, SQLITE3_TEXT);
        $stmt->bindValue(2, $stats['total'], SQLITE3_INTEGER);
        $stmt->bindValue(3, $stats['available'], SQLITE3_INTEGER);
        $stmt->bindValue(4, $stats['locked'], SQLITE3_INTEGER);
        $stmt->execute();
    }
}

// ==================== 数据查询功能 ====================

/**
 * 获取所有数据类型
 */
function _focus_getDataTypes() {
    $db = _focus_getDB();
    
    try {
        $result = $db->query("
            SELECT DISTINCT data_type
            FROM domains
            WHERE data_type IS NOT NULL AND data_type != ''
            ORDER BY data_type
        ");
        
        $types = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $types[] = $row['data_type'];
        }
        
        return ['success' => true, 'data_types' => $types];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 获取所有分组
 */
function _focus_getGroups() {
    $db = _focus_getDB();
    
    try {
        $result = $db->query("
            SELECT DISTINCT group_name
            FROM domains
            WHERE group_name IS NOT NULL AND group_name != ''
            ORDER BY group_name
        ");
        
        $groups = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $groups[] = $row['group_name'];
        }
        
        return ['success' => true, 'groups' => $groups];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 获取用于排除项的域名列表
 */
function _focus_getDomainsForExclusion($sourceType, $sourceValue) {
    $db = _focus_getDB();
    
    try {
        $conditions = [];
        $params = [];
        
        // 根据数据源类型构建查询
        if ($sourceType === 'domain') {
            $domains = array_filter(array_map('trim', explode("\n", $sourceValue)));
            if (empty($domains)) {
                return ['success' => false, 'message' => '请输入域名'];
            }
            $placeholders = implode(',', array_fill(0, count($domains), '?'));
            $conditions[] = "domain IN ($placeholders)";
            $params = array_merge($params, $domains);
        } elseif ($sourceType === 'group') {
            if (empty($sourceValue)) {
                return ['success' => false, 'message' => '请选择分组'];
            }
            $conditions[] = "group_name = ?";
            $params[] = $sourceValue;
        } elseif ($sourceType === 'data_type') {
            if (empty($sourceValue)) {
                return ['success' => false, 'message' => '请选择数据类型'];
            }
            $conditions[] = "data_type = ?";
            $params[] = $sourceValue;
        }
        
        $sql = "
            SELECT DISTINCT domain
            FROM domains
            WHERE " . implode(' AND ', $conditions) . "
            ORDER BY domain
        ";
        
        $stmt = $db->prepare($sql);
        foreach ($params as $i => $param) {
            $stmt->bindValue($i + 1, $param, SQLITE3_TEXT);
        }
        
        $result = $stmt->execute();
        
        $domains = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $domains[] = $row['domain'];
        }
        
        return ['success' => true, 'domains' => $domains];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 根据条件筛选关键词
 */
function _focus_filterKeywords($domains, $keywords, $includeTopDomain = true, $includeSubdomain = true) {
    $db = _focus_getDB();
    
    // 构建SQL
    $conditions = [];
    $params = [];
    
    // 域名条件
    if (!empty($domains)) {
        $placeholders = implode(',', array_fill(0, count($domains), '?'));
        $conditions[] = "d.domain IN ($placeholders)";
        $params = array_merge($params, $domains);
    }
    
    // 关键词条件（包含匹配）
    if (!empty($keywords)) {
        $keywordConditions = [];
        foreach ($keywords as $keyword) {
            $keywordConditions[] = "k.keyword LIKE ?";
            $params[] = "%{$keyword}%";
        }
        $conditions[] = "(" . implode(' OR ', $keywordConditions) . ")";
    }
    
    // URL类型条件
    $typeConditions = [];
    if ($includeTopDomain) {
        $typeConditions[] = "'top_domain'";
        $typeConditions[] = "'www'";
        $typeConditions[] = "'m'";
    }
    if ($includeSubdomain) {
        $typeConditions[] = "'subdomain'";
    }
    if (!empty($typeConditions)) {
        $conditions[] = "k.url_type IN (" . implode(',', $typeConditions) . ")";
    }
    
    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    
    $sql = "
        SELECT 
            k.keyword,
            k.url_type,
            COUNT(*) as total_count,
            SUM(CASE WHEN k.is_locked = 0 THEN 1 ELSE 0 END) as available_count,
            SUM(CASE WHEN k.is_locked = 1 THEN 1 ELSE 0 END) as locked_count,
            GROUP_CONCAT(DISTINCT k.terminal_group) as terminal_groups
        FROM url_keywords k
        INNER JOIN domains d ON k.domain_id = d.id
        {$whereClause}
        GROUP BY k.keyword, k.url_type
        ORDER BY total_count DESC
    ";
    
    $stmt = $db->prepare($sql);
    foreach ($params as $i => $param) {
        $stmt->bindValue($i + 1, $param, SQLITE3_TEXT);
    }
    
    $result = $stmt->execute();
    $keywords = [];
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $keywords[] = $row;
    }
    
    return $keywords;
}

// ==================== 任务管理功能 ====================

/**
 * 获取所有任务
 */
function _focus_getAllTasks() {
    $db = _focus_getDB();
    $result = $db->query("
        SELECT * FROM focus_tasks
        ORDER BY created_at DESC
    ");
    
    $tasks = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        // 解析JSON字段
        $row['filter_keywords'] = json_decode($row['filter_keywords'] ?? '[]', true);
        $row['filter_data_types'] = json_decode($row['filter_data_types'] ?? '[]', true);
        $row['filter_groups'] = json_decode($row['filter_groups'] ?? '[]', true);
        $row['spider_filter'] = json_decode($row['spider_filter'] ?? '{}', true);
        
        // 统计该任务锁定URL的关键词（按数量排序，取前3个）
        $row['top_keywords'] = _focus_getTopKeywordsForTask($db, $row['id']);
        
        $tasks[] = $row;
    }
    
    return $tasks;
}

/**
 * 获取任务的Top关键词（按锁定URL数量排序）
 */
function _focus_getTopKeywordsForTask($db, $taskId) {
    try {
        $stmt = $db->prepare("
            SELECT keyword, COUNT(*) as count
            FROM url_keywords
            WHERE locked_by_task_id = ?
            GROUP BY keyword
            ORDER BY count DESC
            LIMIT 3
        ");
        $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        $keywords = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (!empty($row['keyword'])) {
                $keywords[] = $row['keyword'] . '(' . $row['count'] . ')';
            }
        }
        
        error_log("_focus_getTopKeywordsForTask: 任务 {$taskId} 的关键词: " . json_encode($keywords));
        
        return $keywords;
    } catch (Exception $e) {
        error_log("_focus_getTopKeywordsForTask error: " . $e->getMessage());
        return [];
    }
}

/**
 * 创建任务
 */
function _focus_createTask($data) {
    error_log("=== 开始创建任务 ===");
    error_log("任务数据: " . json_encode($data));
    
    $db = _focus_getDB();
    error_log("数据库连接: " . ($db ? "成功" : "失败"));
    
    // 生成任务ID
    $taskId = 'focus_' . time() . '_' . substr(md5(uniqid()), 0, 8);
    error_log("生成任务ID: " . $taskId);
    
    $db->exec('BEGIN TRANSACTION');
    error_log("开始事务");
    
    try {
        // 插入任务
        $stmt = $db->prepare("
            INSERT INTO focus_tasks 
            (id, name, mode, target_url, target_keyword, filter_keywords, filter_data_types, filter_groups,
             schedule_days, schedule_hours, schedule_minutes, redirect_type, probability, enabled, spider_filter)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $stmt->bindValue(2, $data['name'], SQLITE3_TEXT);
        $stmt->bindValue(3, $data['mode'] ?? 'focus', SQLITE3_TEXT);
        $stmt->bindValue(4, $data['target_url'], SQLITE3_TEXT);
        $stmt->bindValue(5, $data['target_keyword'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(6, json_encode($data['filter_keywords'] ?? []), SQLITE3_TEXT);
        $stmt->bindValue(7, json_encode($data['filter_data_types'] ?? []), SQLITE3_TEXT);
        $stmt->bindValue(8, json_encode($data['filter_groups'] ?? []), SQLITE3_TEXT);
        $stmt->bindValue(9, $data['schedule_days'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(10, $data['schedule_hours'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(11, $data['schedule_minutes'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(12, $data['redirect_type'] ?? 301, SQLITE3_INTEGER);
        $stmt->bindValue(13, $data['probability'] ?? 100, SQLITE3_INTEGER);
        $stmt->bindValue(14, $data['enabled'] ?? 1, SQLITE3_INTEGER);
        $stmt->bindValue(15, json_encode($data['spider_filter'] ?? []), SQLITE3_TEXT);
        $stmt->execute();
        
        // 暂时不锁定URL，等待用户在任务详情页配置
        // $lockedCount = _focus_lockUrlsForTask($db, $taskId, $data);
        $lockedCount = 0;
        
        // 更新锁定数量
        $stmt = $db->prepare("UPDATE focus_tasks SET locked_urls_count = ? WHERE id = ?");
        $stmt->bindValue(1, $lockedCount, SQLITE3_INTEGER);
        $stmt->bindValue(2, $taskId, SQLITE3_TEXT);
        $stmt->execute();
        error_log("任务插入成功");
        
        $db->exec('COMMIT');
        error_log("事务提交成功");
        
        // 验证插入
        $verify = $db->querySingle("SELECT COUNT(*) FROM focus_tasks WHERE id = '$taskId'");
        error_log("验证插入结果: " . $verify);
        
        // 同步到Redis
        _focus_syncTaskToRedis($taskId);
        error_log("任务已同步到Redis");
        
        return ['success' => true, 'message' => '任务创建成功', 'task_id' => $taskId, 'locked_count' => $lockedCount];
        
    } catch (Exception $e) {
        error_log("创建任务失败: " . $e->getMessage());
        error_log("错误堆栈: " . $e->getTraceAsString());
        $db->exec('ROLLBACK');
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 锁定URL（支持三端统一锁定）
 */
function _focus_lockUrlsForTask($db, $taskId, $data) {
    $keywords = $data['filter_keywords'] ?? [];
    $dataTypes = $data['filter_data_types'] ?? [];
    $groups = $data['filter_groups'] ?? [];
    $targetUrl = $data['target_url'] ?? '';
    
    // 提取目标URL的域名，用于排除
    $targetHost = parse_url($targetUrl, PHP_URL_HOST);
    
    // 查询所有匹配的URL（未锁定）
    $conditions = [];
    $params = [];
    
    if (!empty($keywords)) {
        $placeholders = implode(',', array_fill(0, count($keywords), '?'));
        $conditions[] = "k.keyword IN ($placeholders)";
        $params = array_merge($params, $keywords);
    }
    
    if (!empty($dataTypes)) {
        $placeholders = implode(',', array_fill(0, count($dataTypes), '?'));
        $conditions[] = "d.data_type IN ($placeholders)";
        $params = array_merge($params, $dataTypes);
    }
    
    if (!empty($groups)) {
        $placeholders = implode(',', array_fill(0, count($groups), '?'));
        $conditions[] = "d.group_name IN ($placeholders)";
        $params = array_merge($params, $groups);
    }
    
    $conditions[] = "k.is_locked = 0";
    
    // 【关键】排除集权目标域名本身
    if ($targetHost) {
        $conditions[] = "d.domain != ?";
        $params[] = $targetHost;
    }
    
    $whereClause = 'WHERE ' . implode(' AND ', $conditions);
    
    $sql = "
        SELECT k.id, k.full_url, k.url_type, k.terminal_group
        FROM url_keywords k
        INNER JOIN domains d ON k.domain_id = d.id
        {$whereClause}
    ";
    
    $stmt = $db->prepare($sql);
    foreach ($params as $i => $param) {
        $stmt->bindValue($i + 1, $param, SQLITE3_TEXT);
    }
    
    $result = $stmt->execute();
    $urls = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $urls[] = $row;
    }
    
    $lockedCount = 0;
    $processedGroups = [];
    
    // 准备Redis缓存数据
    $lockData = [
        'task_id' => $taskId,
        'target_url' => $targetUrl,
        'schedule_days' => $data['schedule_days'] ?? 0,
        'schedule_hours' => $data['schedule_hours'] ?? 0,
        'schedule_minutes' => $data['schedule_minutes'] ?? 0
    ];
    
    require_once __DIR__ . '/../utils/FocusRedirectCache.php';
    $redis = getRedis();
    $cache = new \Redirect301\Utils\FocusRedirectCache($redis);
    
    foreach ($urls as $url) {
        // 如果是顶级域名类型，需要锁定整个三端组
        if (in_array($url['url_type'], ['top_domain', 'www', 'm']) && $url['terminal_group']) {
            if (in_array($url['terminal_group'], $processedGroups)) {
                continue; // 已处理
            }
            
            // 锁定整个三端组
            $groupCount = _focus_lockTerminalGroup($db, $taskId, $url['terminal_group'], $cache, $lockData);
            $lockedCount += $groupCount;
            $processedGroups[] = $url['terminal_group'];
            
        } else {
            // 普通二级域名，单独锁定
            _focus_lockSingleUrl($db, $taskId, $url['id'], $cache, $lockData);
            $lockedCount++;
        }
    }
    
    return $lockedCount;
}

/**
 * 锁定三端组
 */
function _focus_lockTerminalGroup($db, $taskId, $terminalGroup, $cache, $lockData) {
    $stmt = $db->prepare("
        SELECT id, full_url FROM url_keywords
        WHERE terminal_group = ? AND is_locked = 0
    ");
    $stmt->bindValue(1, $terminalGroup, SQLITE3_TEXT);
    $result = $stmt->execute();
    
    $count = 0;
    $lockData['terminal_group'] = $terminalGroup;
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        _focus_lockSingleUrl($db, $taskId, $row['id'], $cache, $lockData, $row['full_url']);
        $count++;
    }
    
    return $count;
}

/**
 * 锁定单个URL
 */
function _focus_lockSingleUrl($db, $taskId, $urlKeywordId, $cache, $lockData, $fullUrl = null) {
    // 更新锁定状态
    $stmt = $db->prepare("
        UPDATE url_keywords 
        SET is_locked = 1, locked_by_task_id = ?, locked_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
    $stmt->bindValue(2, $urlKeywordId, SQLITE3_INTEGER);
    $stmt->execute();
    
    // 记录锁定关系
    $stmt = $db->prepare("
        INSERT INTO task_url_locks (task_id, url_keyword_id)
        VALUES (?, ?)
    ");
    $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
    $stmt->bindValue(2, $urlKeywordId, SQLITE3_INTEGER);
    $stmt->execute();
    
    // 写入Redis缓存
    if ($fullUrl && $cache) {
        $cache->setUrlLock($fullUrl, $lockData);
    } else if (!$fullUrl) {
        // 如果没有传入fullUrl，从数据库查询
        $stmt = $db->prepare("SELECT full_url FROM url_keywords WHERE id = ?");
        $stmt->bindValue(1, $urlKeywordId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        if ($row && $cache) {
            $cache->setUrlLock($row['full_url'], $lockData);
        }
    }
}

/**
 * 删除任务（自动解锁所有URL）
 */
function _focus_deleteTask($taskId) {
    $db = _focus_getDB();
    
    $db->exec('BEGIN TRANSACTION');
    
    try {
        // 获取所有锁定的URL用于清除Redis缓存
        $stmt = $db->prepare("
            SELECT full_url FROM url_keywords 
            WHERE locked_by_task_id = ?
        ");
        $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $urls = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $urls[] = $row['full_url'];
        }
        $unlockedCount = count($urls);
        
        // 解锁所有URL
        $stmt = $db->prepare("
            UPDATE url_keywords 
            SET is_locked = 0, locked_by_task_id = NULL, locked_at = NULL
            WHERE locked_by_task_id = ?
        ");
        $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $stmt->execute();
        
        // 删除任务（会级联删除锁定关系和日志）
        $stmt = $db->prepare("DELETE FROM focus_tasks WHERE id = ?");
        $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $stmt->execute();
        
        // 清理提取的链接表（包括已锁定链接表）
        $stmt = $db->prepare("DELETE FROM extracted_links WHERE task_id = ?");
        $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $stmt->execute();
        
        $stmt = $db->prepare("DELETE FROM extracted_links_locked WHERE task_id = ?");
        $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $stmt->execute();
        
        // 清理其他任务中引用此任务的锁定记录
        $stmt = $db->prepare("DELETE FROM extracted_links_locked WHERE locked_by_task_id = ?");
        $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $stmt->execute();
        
        $db->exec('COMMIT');
        
        // 清除Redis缓存
        try {
            $redis = getRedis();
            if ($redis) {
                // 删除所有URL的锁定缓存（使用正确的key格式）
                foreach ($urls as $url) {
                    $redis->del("focus:lock:{$url}");
                }
                error_log("_focus_deleteTask: 已清除 " . count($urls) . " 个URL的锁定缓存");
                
                // 清除任务配置和统计缓存
                $redis->del("focus:task:{$taskId}");
                $redis->del("focus:stats:{$taskId}");
                error_log("_focus_deleteTask: 已清除任务 {$taskId} 的配置和统计缓存");
            }
        } catch (Exception $e) {
            // Redis不可用时忽略
            error_log("清除Redis缓存失败: " . $e->getMessage());
        }
        
        return ['success' => true, 'message' => '任务删除成功', 'unlocked_urls_count' => $unlockedCount];
        
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 更新任务状态
 */
function _focus_toggleTask($taskId) {
    $db = _focus_getDB();
    
    $stmt = $db->prepare("
        UPDATE focus_tasks 
        SET enabled = 1 - enabled,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
    $stmt->execute();
    
    return ['success' => true];
}

/**
 * 搜索关键词
 */
function _focus_searchKeywords($keyword, $dataType = '', $group = '') {
    $db = _focus_getDB();
    
    try {
        $params = ['%' . $keyword . '%'];
        
        // 如果有数据类型或分组过滤，需要join domains表
        if (!empty($dataType) || !empty($group)) {
            $sql = "
                SELECT DISTINCT ks.keyword, ks.total_count, ks.available_count, ks.locked_count
                FROM keyword_stats ks
                INNER JOIN url_keywords uk ON uk.keyword = ks.keyword
                INNER JOIN domains d ON d.id = uk.domain_id
                WHERE ks.keyword LIKE ?
            ";
            
            if (!empty($dataType)) {
                $sql .= " AND d.data_type = ?";
                $params[] = $dataType;
            }
            
            if (!empty($group)) {
                $sql .= " AND d.group_name = ?";
                $params[] = $group;
            }
            
            $sql .= " ORDER BY ks.total_count DESC LIMIT 50";
        } else {
            $sql = "
                SELECT keyword, total_count, available_count, locked_count
                FROM keyword_stats
                WHERE keyword LIKE ?
                ORDER BY total_count DESC
                LIMIT 50
            ";
        }
        
        $stmt = $db->prepare($sql);
        foreach ($params as $i => $param) {
            $stmt->bindValue($i + 1, $param, SQLITE3_TEXT);
        }
        
        $result = $stmt->execute();
        $keywords = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $keywords[] = $row;
        }
        
        return ['success' => true, 'keywords' => $keywords];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 获取任务列表
 */
function _focus_getTasks() {
    $db = _focus_getDB();
    
    try {
        $result = $db->query("
            SELECT * FROM focus_tasks
            ORDER BY created_at DESC
        ");
        
        $tasks = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // 解析JSON字段
            $row['spider_filter'] = json_decode($row['spider_filter'] ?? '{}', true);
            $row['filter_keywords'] = json_decode($row['filter_keywords'] ?? '[]', true);
            
            // 获取Redis统计
            try {
                $redis = getRedis();
                $statsKey = 'focus:stats:' . $row['id'];
                $stats = $redis->hgetall($statsKey);
                $row['stats'] = [
                    'total_redirects' => intval($stats['total_redirects'] ?? 0),
                    'last_redirect_at' => $stats['last_redirect_at'] ?? null
                ];
            } catch (Exception $e) {
                // Redis不可用时使用默认值
                $row['stats'] = [
                    'total_redirects' => 0,
                    'last_redirect_at' => null
                ];
            }
            
            // 获取Top关键词
            $row['top_keywords'] = _focus_getTopKeywordsForTask($db, $row['id']);
            
            $tasks[] = $row;
        }
        
        return ['success' => true, 'tasks' => $tasks];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 获取任务详情
 */
function _focus_getTaskDetail($taskId) {
    $db = _focus_getDB();
    
    try {
        $stmt = $db->prepare("SELECT * FROM focus_tasks WHERE id = ?");
        $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $task = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$task) {
            return ['success' => false, 'message' => '任务不存在'];
        }
        
        // 解析JSON字段
        $task['spider_filter'] = json_decode($task['spider_filter'] ?? '{}', true);
        $task['filter_keywords'] = json_decode($task['filter_keywords'] ?? '[]', true);
        
        // 解析目标URL列表（从JSON格式转换为换行分隔的字符串）
        $targetUrlsArray = json_decode($task['target_url'] ?? '[]', true);
        if (is_array($targetUrlsArray)) {
            $task['target_urls'] = implode("\n", $targetUrlsArray);
        } else {
            // 兼容旧格式（单个URL）
            $task['target_urls'] = $task['target_url'] ?? '';
        }
        
        // 获取Redis统计
        try {
            $redis = getRedis();
            $statsKey = 'focus:stats:' . $taskId;
            $stats = $redis->hgetall($statsKey);
            $statsData = [
                'total_redirects' => intval($stats['total_redirects'] ?? 0),
                'last_redirect_at' => $stats['last_redirect_at'] ?? null
            ];
        } catch (Exception $e) {
            $statsData = [
                'total_redirects' => 0,
                'last_redirect_at' => null
            ];
        }
        
        return [
            'success' => true,
            'task' => $task,
            'stats' => $statsData
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 更新任务
 */
function _focus_updateTask($taskId, $updates) {
    $db = _focus_getDB();
    
    try {
        error_log("_focus_updateTask: 开始更新任务 $taskId");
        error_log("_focus_updateTask: 接收到的updates=" . json_encode($updates));
        
        $fields = [];
        $params = [];
        
        foreach ($updates as $key => $value) {
            if ($key === 'spider_filter') {
                $fields[] = "spider_filter = ?";
                $jsonValue = json_encode($value);
                $params[] = $jsonValue;
                error_log("_focus_updateTask: spider_filter JSON=$jsonValue");
            } else {
                $fields[] = "$key = ?";
                $params[] = $value;
            }
        }
        
        if (empty($fields)) {
            return ['success' => false, 'message' => '没有要更新的字段'];
        }
        
        $fields[] = "updated_at = CURRENT_TIMESTAMP";
        $params[] = $taskId;
        
        $sql = "UPDATE focus_tasks SET " . implode(', ', $fields) . " WHERE id = ?";
        error_log("_focus_updateTask: SQL=$sql");
        
        $stmt = $db->prepare($sql);
        
        foreach ($params as $i => $param) {
            $stmt->bindValue($i + 1, $param);
        }
        
        $result = $stmt->execute();
        error_log("_focus_updateTask: SQL执行结果=" . ($result ? 'success' : 'failed'));
        
        // 验证更新
        $verifyStmt = $db->prepare("SELECT spider_filter FROM focus_tasks WHERE id = ?");
        $verifyStmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $verifyResult = $verifyStmt->execute();
        $verifyRow = $verifyResult->fetchArray(SQLITE3_ASSOC);
        error_log("_focus_updateTask: 数据库中的spider_filter=" . ($verifyRow['spider_filter'] ?? 'NULL'));
        
        // 清除Redis缓存，强制重新加载
        try {
            $redis = getRedis();
            $redis->del('focus:task:' . $taskId);
            error_log("_focus_updateTask: 已删除Redis缓存");
            
            // 立即重新加载任务到Redis
            $syncResult = _focus_syncTaskToRedis($taskId);
            error_log("_focus_updateTask: Redis同步结果=" . ($syncResult ? 'success' : 'failed'));
        } catch (Exception $e) {
            // Redis不可用时忽略
            error_log("_focus_updateTask: Redis同步失败 - " . $e->getMessage());
        }
        
        return ['success' => true, 'message' => '更新成功'];
        
    } catch (Exception $e) {
        error_log("_focus_updateTask: 异常 - " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 获取统计数据
 */
function _focus_getStats() {
    $db = _focus_getDB();
    
    try {
        $stats = [];
        
        $stats['total_domains'] = $db->querySingle("SELECT COUNT(*) FROM domains");
        $stats['total_urls'] = $db->querySingle("SELECT COUNT(*) FROM url_keywords");
        $stats['total_keywords'] = $db->querySingle("SELECT COUNT(*) FROM keyword_stats");
        $stats['active_tasks'] = $db->querySingle("SELECT COUNT(*) FROM focus_tasks WHERE enabled = 1");
        
        return $stats;
        
    } catch (Exception $e) {
        return [
            'total_domains' => 0,
            'total_urls' => 0,
            'total_keywords' => 0,
            'active_tasks' => 0
        ];
    }
}

/**
 * 获取任务锁定的URL列表
 */
function _focus_getTaskLockedUrls($taskId, $page = 1, $perPage = 50) {
    $db = _focus_getDB();
    
    try {
        $offset = ($page - 1) * $perPage;
        
        // 获取总数
        $stmt = $db->prepare("
            SELECT COUNT(*) as total
            FROM url_keywords
            WHERE locked_by_task_id = ?
        ");
        $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $totalRow = $result->fetchArray(SQLITE3_ASSOC);
        $total = $totalRow['total'];
        
        // 获取列表
        $stmt = $db->prepare("
            SELECT k.id, k.full_url, k.keyword, k.url_type, k.terminal_group,
                   k.redirect_count, k.last_redirect_at, k.locked_at,
                   d.domain, d.brand_name, d.data_type, d.group_name
            FROM url_keywords k
            INNER JOIN domains d ON k.domain_id = d.id
            WHERE k.locked_by_task_id = ?
            ORDER BY k.locked_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $stmt->bindValue(2, $perPage, SQLITE3_INTEGER);
        $stmt->bindValue(3, $offset, SQLITE3_INTEGER);
        
        $result = $stmt->execute();
        $urls = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $urls[] = $row;
        }
        
        return [
            'success' => true,
            'urls' => $urls,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 解锁指定URL
 */
function _focus_unlockUrl($taskId, $urlId) {
    $db = _focus_getDB();
    
    try {
        // 获取URL信息
        $stmt = $db->prepare("SELECT full_url FROM url_keywords WHERE id = ? AND locked_by_task_id = ?");
        $stmt->bindValue(1, $urlId, SQLITE3_INTEGER);
        $stmt->bindValue(2, $taskId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $url = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$url) {
            return ['success' => false, 'message' => 'URL不存在或不属于此任务'];
        }
        
        // 解锁
        $stmt = $db->prepare("
            UPDATE url_keywords
            SET is_locked = 0, locked_by_task_id = NULL, locked_at = NULL
            WHERE id = ?
        ");
        $stmt->bindValue(1, $urlId, SQLITE3_INTEGER);
        $stmt->execute();
        
        // 删除锁定关系
        $stmt = $db->prepare("DELETE FROM task_url_locks WHERE task_id = ? AND url_keyword_id = ?");
        $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $stmt->bindValue(2, $urlId, SQLITE3_INTEGER);
        $stmt->execute();
        
        // 删除Redis缓存
        require_once __DIR__ . '/../utils/FocusRedirectCache.php';
        try {
            $redis = getRedis();
            $cache = new \Redirect301\Utils\FocusRedirectCache($redis);
            $cache->deleteUrlLock($url['full_url']);
        } catch (Exception $e) {
            // Redis不可用时忽略
        }
        
        // 更新任务的锁定数量
        $stmt = $db->prepare("
            UPDATE focus_tasks
            SET locked_urls_count = locked_urls_count - 1
            WHERE id = ?
        ");
        $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $stmt->execute();
        
        return ['success' => true, 'message' => 'URL已解锁'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 导出任务锁定的URL列表
 */
function _focus_exportTaskUrls($taskId) {
    $db = _focus_getDB();
    
    try {
        $stmt = $db->prepare("
            SELECT k.full_url, k.keyword, k.url_type, k.terminal_group,
                   k.redirect_count, k.last_redirect_at,
                   d.domain, d.brand_name, d.data_type, d.group_name
            FROM url_keywords k
            INNER JOIN domains d ON k.domain_id = d.id
            WHERE k.locked_by_task_id = ?
            ORDER BY k.locked_at DESC
        ");
        $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        $urls = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $urls[] = $row;
        }
        
        return ['success' => true, 'urls' => $urls];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}


// ==================== 任务配置页面专用函数 ====================

/**
 * 提取链接
 */
function _focus_extractLinks($taskId, $sourceType, $sourceValue, $keywords = '', $matchFromData = true, $matchFromInput = true, $includeTopLevel = false) {
    try {
        $db = _focus_getDB();
        
        // 处理关键词（如果是字符串，转换为数组）
        if (is_string($keywords)) {
            $keywords = array_filter(array_map('trim', explode("\n", $keywords)));
        }
        
        $conditions = [];
        $params = [];
        
        // 根据数据源类型构建查询
        if ($sourceType === 'domain') {
            // 域名列表
            $domains = array_filter(array_map('trim', explode("\n", $sourceValue)));
            if (empty($domains)) {
                return ['success' => false, 'message' => '请输入域名'];
            }
            
            $placeholders = implode(',', array_fill(0, count($domains), '?'));
            $conditions[] = "d.domain IN ($placeholders)";
            $params = array_merge($params, $domains);
            
        } elseif ($sourceType === 'group') {
            // 分组
            if (empty($sourceValue)) {
                return ['success' => false, 'message' => '请选择分组'];
            }
            $conditions[] = "d.group_name = ?";
            $params[] = $sourceValue;
            
        } elseif ($sourceType === 'data_type') {
            // 数据类型
            if (empty($sourceValue)) {
                return ['success' => false, 'message' => '请选择数据类型'];
            }
            $conditions[] = "d.data_type = ?";
            $params[] = $sourceValue;
        }
        
        // 是否包含三端（@/www/m）参与跳转（默认不包含）
        // 三端指的是：顶级域名(top_domain)、www子域名(www)、m子域名(m)
        if (!$includeTopLevel) {
            $conditions[] = "k.url_type NOT IN ('top_domain', 'www', 'm')";
        }
        
        // 获取任务的排除项配置
        $task = $db->querySingle("SELECT exclude_domains, exclude_groups, exclude_types FROM focus_tasks WHERE id = '{$taskId}'", true);
        if ($task) {
            $excludeDomains = !empty($task['exclude_domains']) ? json_decode($task['exclude_domains'], true) : [];
            $excludeGroups = !empty($task['exclude_groups']) ? json_decode($task['exclude_groups'], true) : [];
            $excludeTypes = !empty($task['exclude_types']) ? json_decode($task['exclude_types'], true) : [];
            
            // 确保是数组类型
            if (!is_array($excludeDomains)) $excludeDomains = [];
            if (!is_array($excludeGroups)) $excludeGroups = [];
            if (!is_array($excludeTypes)) $excludeTypes = [];
            
            // 排除域名
            if (!empty($excludeDomains)) {
                $domainExcludeConditions = [];
                foreach ($excludeDomains as $domain) {
                    $domain = trim($domain);
                    if (empty($domain)) continue;
                    
                    // 排除该域名及其所有子域名
                    // 例如：输入 example.com，排除 example.com、abc.example.com、def.example.com 等
                    // 逻辑：如果 domain = 'example.com' OR domain LIKE '%.example.com'，则排除
                    // SQL：NOT (domain = ? OR domain LIKE ?) 
                    // 简化为：domain != ? AND domain NOT LIKE ?
                    $domainExcludeConditions[] = "(d.domain = ? OR d.domain LIKE ?)";
                    $params[] = $domain;
                    $params[] = '%.' . $domain;
                }
                if (!empty($domainExcludeConditions)) {
                    // 使用 NOT 包裹所有域名排除条件（OR 关系）
                    $conditions[] = "NOT (" . implode(' OR ', $domainExcludeConditions) . ")";
                }
            }
            
            // 排除小组
            if (!empty($excludeGroups)) {
                $excludeGroups = array_filter(array_map('trim', $excludeGroups));
                if (!empty($excludeGroups)) {
                    $placeholders = implode(',', array_fill(0, count($excludeGroups), '?'));
                    $conditions[] = "(d.group_name IS NULL OR d.group_name NOT IN ($placeholders))";
                    $params = array_merge($params, $excludeGroups);
                }
            }
            
            // 排除分类
            if (!empty($excludeTypes)) {
                $excludeTypes = array_filter(array_map('trim', $excludeTypes));
                if (!empty($excludeTypes)) {
                    $placeholders = implode(',', array_fill(0, count($excludeTypes), '?'));
                    $conditions[] = "(d.data_type IS NULL OR d.data_type NOT IN ($placeholders))";
                    $params = array_merge($params, $excludeTypes);
                }
            }
        }
        
        // 关键词匹配
        if (!empty($keywords) && ($matchFromData || $matchFromInput)) {
            $keywordConditions = [];
            
            if ($matchFromInput) {
                // 从用户输入的关键词匹配
                foreach ($keywords as $keyword) {
                    $keywordConditions[] = "k.keyword LIKE ?";
                    $params[] = "%$keyword%";
                }
            }
            
            if ($matchFromData) {
                // 从数据中选取关键词（这里假设关键词已经在数据库中）
                foreach ($keywords as $keyword) {
                    $keywordConditions[] = "k.keyword = ?";
                    $params[] = $keyword;
                }
            }
            
            if (!empty($keywordConditions)) {
                $conditions[] = '(' . implode(' OR ', $keywordConditions) . ')';
            }
        }
        
        // 查询所有匹配的URL（包括已锁定的，用于分类显示）
        // 构建完整SQL（取消1000条限制）
        $sql = "
            SELECT 
                k.id,
                k.full_url,
                k.keyword,
                k.redirect_count,
                k.is_locked,
                k.locked_by_task_id,
                k.locked_at,
                t.name as locked_by_task_name,
                d.domain,
                d.brand_name
            FROM url_keywords k
            INNER JOIN domains d ON k.domain_id = d.id
            LEFT JOIN focus_tasks t ON k.locked_by_task_id = t.id
            WHERE " . implode(' AND ', $conditions) . "
            ORDER BY k.is_locked ASC, k.created_at DESC
        ";
        
        $stmt = $db->prepare($sql);
        foreach ($params as $i => $param) {
            $stmt->bindValue($i + 1, $param, SQLITE3_TEXT);
        }
        
        $result = $stmt->execute();
        $availableLinks = [];
        $lockedLinks = [];
        
        // 分类：可用链接和已锁定链接
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['is_locked'] == 1) {
                // 已锁定链接
                $lockedLinks[] = [
                    'url' => $row['full_url'],
                    'keyword' => $row['keyword'],
                    'redirect_count' => $row['redirect_count'],
                    'locked_by_task_id' => $row['locked_by_task_id'],
                    'locked_by_task_name' => $row['locked_by_task_name'] ?? '未知任务',
                    'locked_at' => $row['locked_at']
                ];
            } else {
                // 可用链接
                $availableLinks[] = [
                    'url' => $row['full_url'],
                    'keyword' => $row['keyword'],
                    'redirect_count' => $row['redirect_count']
                ];
            }
        }
        
        // 保存提取的链接到任务的extracted_links表
        // 1. 先清空该任务的旧数据
        $db->exec("DELETE FROM extracted_links WHERE task_id = '" . $db->escapeString($taskId) . "'");
        $db->exec("DELETE FROM extracted_links_locked WHERE task_id = '" . $db->escapeString($taskId) . "'");
        
        // 2. 保存可用链接
        if (!empty($availableLinks)) {
            $insertStmt = $db->prepare("
                INSERT INTO extracted_links (task_id, url, keyword, redirect_count, created_at)
                VALUES (?, ?, ?, ?, datetime('now'))
            ");
            
            foreach ($availableLinks as $link) {
                $insertStmt->bindValue(1, $taskId, SQLITE3_TEXT);
                $insertStmt->bindValue(2, $link['url'], SQLITE3_TEXT);
                $insertStmt->bindValue(3, $link['keyword'], SQLITE3_TEXT);
                $insertStmt->bindValue(4, $link['redirect_count'], SQLITE3_INTEGER);
                $insertStmt->execute();
                $insertStmt->reset();
            }
        }
        
        // 3. 保存已锁定链接
        if (!empty($lockedLinks)) {
            $insertStmt = $db->prepare("
                INSERT INTO extracted_links_locked 
                (task_id, url, keyword, redirect_count, locked_by_task_id, locked_by_task_name, locked_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))
            ");
            
            foreach ($lockedLinks as $link) {
                $insertStmt->bindValue(1, $taskId, SQLITE3_TEXT);
                $insertStmt->bindValue(2, $link['url'], SQLITE3_TEXT);
                $insertStmt->bindValue(3, $link['keyword'], SQLITE3_TEXT);
                $insertStmt->bindValue(4, $link['redirect_count'], SQLITE3_INTEGER);
                $insertStmt->bindValue(5, $link['locked_by_task_id'], SQLITE3_TEXT);
                $insertStmt->bindValue(6, $link['locked_by_task_name'], SQLITE3_TEXT);
                $insertStmt->bindValue(7, $link['locked_at'], SQLITE3_TEXT);
                $insertStmt->execute();
                $insertStmt->reset();
            }
        }
        
        return [
            'success' => true,
            'available_links' => array_slice($availableLinks, 0, 50),  // 前50条
            'locked_links' => array_slice($lockedLinks, 0, 50),        // 前50条
            'available_count' => count($availableLinks),
            'locked_count' => count($lockedLinks),
            'total_count' => count($availableLinks) + count($lockedLinks)
        ];
        
    } catch (Exception $e) {
        error_log("_focus_extractLinks error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 获取提取的链接列表（分页）
 */
function _focus_getExtractedLinks($taskId, $page = 1, $pageSize = 50) {
    try {
        $db = _focus_getDB();
        
        $offset = ($page - 1) * $pageSize;
        
        // 获取总数
        $countStmt = $db->prepare("SELECT COUNT(*) as total FROM extracted_links WHERE task_id = ?");
        $countStmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $countResult = $countStmt->execute();
        $total = $countResult->fetchArray(SQLITE3_ASSOC)['total'];
        
        // 获取数据
        $stmt = $db->prepare("
            SELECT id, url, keyword, redirect_count, created_at
            FROM extracted_links
            WHERE task_id = ?
            ORDER BY id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $stmt->bindValue(2, $pageSize, SQLITE3_INTEGER);
        $stmt->bindValue(3, $offset, SQLITE3_INTEGER);
        
        $result = $stmt->execute();
        $links = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $links[] = $row;
        }
        
        return [
            'success' => true,
            'links' => $links,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize
        ];
        
    } catch (Exception $e) {
        error_log("_focus_getExtractedLinks error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 获取已锁定链接列表（分页）
 */
function _focus_getLockedLinks($taskId, $page = 1, $pageSize = 50) {
    try {
        $db = _focus_getDB();
        
        $offset = ($page - 1) * $pageSize;
        
        // 获取总数
        $countStmt = $db->prepare("SELECT COUNT(*) as total FROM extracted_links_locked WHERE task_id = ?");
        $countStmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $countResult = $countStmt->execute();
        $total = $countResult->fetchArray(SQLITE3_ASSOC)['total'];
        
        // 获取数据
        $stmt = $db->prepare("
            SELECT id, url, keyword, redirect_count, 
                   locked_by_task_id, locked_by_task_name, locked_at, created_at
            FROM extracted_links_locked
            WHERE task_id = ?
            ORDER BY locked_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $stmt->bindValue(2, $pageSize, SQLITE3_INTEGER);
        $stmt->bindValue(3, $offset, SQLITE3_INTEGER);
        
        $result = $stmt->execute();
        $links = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $links[] = $row;
        }
        
        return [
            'success' => true,
            'links' => $links,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize
        ];
        
    } catch (Exception $e) {
        error_log("_focus_getLockedLinks error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 删除提取的链接
 */
function _focus_deleteExtractedLink($taskId, $url) {
    try {
        $db = _focus_getDB();
        
        $stmt = $db->prepare("DELETE FROM extracted_links WHERE task_id = ? AND url = ?");
        $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $stmt->bindValue(2, $url, SQLITE3_TEXT);
        $stmt->execute();
        
        return ['success' => true, 'message' => '删除成功'];
        
    } catch (Exception $e) {
        error_log("_focus_deleteExtractedLink error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 导出提取的链接为CSV
 */
function _focus_exportExtractedLinks($taskId) {
    try {
        $db = _focus_getDB();
        
        $stmt = $db->prepare("
            SELECT url, keyword, redirect_count
            FROM extracted_links
            WHERE task_id = ?
            ORDER BY id
        ");
        $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
        
        $result = $stmt->execute();
        
        // 生成CSV
        $csv = "URL,关键词,跳转次数\n";
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $csv .= '"' . str_replace('"', '""', $row['url']) . '",';
            $csv .= '"' . str_replace('"', '""', $row['keyword']) . '",';
            $csv .= $row['redirect_count'] . "\n";
        }
        
        return ['success' => true, 'csv' => $csv];
        
    } catch (Exception $e) {
        error_log("_focus_exportExtractedLinks error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 保存任务配置
 */
function _focus_saveTaskConfig($taskId, $config) {
    try {
        $db = _focus_getDB();
        
        // 解析目标URL列表
        $targetUrls = array_filter(array_map('trim', explode("\n", $config['target_urls'])));
        if (empty($targetUrls)) {
            return ['success' => false, 'message' => '请至少输入一个目标URL'];
        }
        
        // 自动添加协议头（如果没有）
        $targetUrls = array_map(function($url) {
            if (!preg_match('/^https?:\/\//i', $url)) {
                return 'http://' . $url;
            }
            return $url;
        }, $targetUrls);
        
        // 开始事务
        $db->exec('BEGIN');
        
        // 计算定时间隔（转换为分钟）
        $scheduleInterval = ($config['schedule_days'] * 24 * 60) + 
                           ($config['schedule_hours'] * 60) + 
                           $config['schedule_minutes'];
        
        // 处理排除项配置
        $excludeDomains = isset($config['exclude_domains']) ? $config['exclude_domains'] : [];
        $excludeGroups = isset($config['exclude_groups']) ? $config['exclude_groups'] : [];
        $excludeTypes = isset($config['exclude_types']) ? $config['exclude_types'] : [];
        
        // 如果是字符串，转换为数组
        if (is_string($excludeDomains)) {
            $excludeDomains = array_filter(array_map('trim', explode("\n", $excludeDomains)));
        }
        if (is_string($excludeGroups)) {
            $excludeGroups = array_filter(array_map('trim', explode("\n", $excludeGroups)));
        }
        if (is_string($excludeTypes)) {
            $excludeTypes = array_filter(array_map('trim', explode("\n", $excludeTypes)));
        }
        
        // 更新任务基本信息（使用target_url字段存储JSON格式的多个URL）
        $stmt = $db->prepare("
            UPDATE focus_tasks SET
                mode = 'focus',
                target_url = ?,
                redirect_type = ?,
                probability = ?,
                schedule_days = ?,
                schedule_hours = ?,
                schedule_minutes = ?,
                exclude_domains = ?,
                exclude_groups = ?,
                exclude_types = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        $stmt->bindValue(1, json_encode($targetUrls), SQLITE3_TEXT);
        $stmt->bindValue(2, $config['redirect_type'], SQLITE3_INTEGER);
        $stmt->bindValue(3, $config['probability'], SQLITE3_INTEGER);
        $stmt->bindValue(4, $config['schedule_days'], SQLITE3_INTEGER);
        $stmt->bindValue(5, $config['schedule_hours'], SQLITE3_INTEGER);
        $stmt->bindValue(6, $config['schedule_minutes'], SQLITE3_INTEGER);
        $stmt->bindValue(7, json_encode($excludeDomains), SQLITE3_TEXT);
        $stmt->bindValue(8, json_encode($excludeGroups), SQLITE3_TEXT);
        $stmt->bindValue(9, json_encode($excludeTypes), SQLITE3_TEXT);
        $stmt->bindValue(10, $taskId, SQLITE3_TEXT);
        $stmt->execute();
        
        // 检查是否有提取的链接需要锁定
        $checkStmt = $db->prepare("SELECT COUNT(*) as count FROM extracted_links WHERE task_id = ?");
        $checkStmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $checkResult = $checkStmt->execute();
        $checkRow = $checkResult->fetchArray(SQLITE3_ASSOC);
        $hasExtractedLinks = ($checkRow['count'] > 0);
        
        // 只有当有提取的链接时才重新锁定
        // 注意：不在这里指定target_url，而是在第一次跳转时随机选择
        if ($hasExtractedLinks) {
            $lockResult = _focus_lockExtractedLinks($db, $taskId, null, $scheduleInterval);
            error_log("_focus_saveTaskConfig: 锁定结果: " . json_encode($lockResult));
        } else {
            error_log("_focus_saveTaskConfig: 没有提取的链接，跳过锁定步骤");
            $lockResult = ['success' => true, 'locked_count' => 0];
        }
        
        $db->exec('COMMIT');
        
        error_log("_focus_saveTaskConfig: 事务已提交");
        
        // 验证更新结果
        $verifyStmt = $db->prepare("SELECT locked_urls_count FROM focus_tasks WHERE id = ?");
        $verifyStmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $verifyResult = $verifyStmt->execute();
        $verifyRow = $verifyResult->fetchArray(SQLITE3_ASSOC);
        error_log("_focus_saveTaskConfig: 验证数据库中的locked_urls_count = " . ($verifyRow['locked_urls_count'] ?? 'NULL'));
        
        // 同步到Redis
        _focus_syncTaskToRedis($taskId);
        
        // 构建友好的提示消息
        $message = '保存成功';
        if (isset($lockResult['skipped_count']) && $lockResult['skipped_count'] > 0) {
            $message .= sprintf(
                '，成功锁定 %d 个URL，跳过 %d 个（已被其他任务锁定）',
                $lockResult['locked_count'],
                $lockResult['skipped_count']
            );
        } else {
            $message .= sprintf('，成功锁定 %d 个URL', $lockResult['locked_count']);
        }
        
        return [
            'success' => true, 
            'message' => $message,
            'locked_count' => $lockResult['locked_count'] ?? 0,
            'skipped_count' => $lockResult['skipped_count'] ?? 0
        ];
        
    } catch (Exception $e) {
        if ($db) {
            $db->exec('ROLLBACK');
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 锁定提取的链接
 */
function _focus_lockExtractedLinks($db, $taskId, $targetUrl, $scheduleInterval) {
    try {
        $redis = getRedis();
        if (!$redis) {
            throw new Exception('Redis连接失败');
        }
        
        // 获取所有提取的链接
        $stmt = $db->prepare("SELECT url FROM extracted_links WHERE task_id = ?");
        $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        $successCount = 0;  // 成功锁定的URL数量（包括新锁定和更新）
        $totalCount = 0;    // 总处理的URL数量
        $skippedCount = 0;  // 被其他任务锁定而跳过的数量
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $url = $row['url'];
            $totalCount++;
            
            error_log("_focus_lockExtractedLinks: 处理URL #{$totalCount}: {$url}");
            
            // 检查URL是否已被其他任务锁定
            $existingLock = $redis->get("focus:lock:{$url}");
            
            if ($existingLock !== false && $existingLock !== null) {
                // 解析锁定数据
                $lockData = json_decode($existingLock, true);
                $lockedByTaskId = $lockData['task_id'] ?? null;
                
                // 检查锁定任务是否还存在
                if ($lockedByTaskId && $lockedByTaskId !== $taskId) {
                    $checkStmt = $db->prepare("SELECT id FROM focus_tasks WHERE id = ?");
                    $checkStmt->bindValue(1, $lockedByTaskId, SQLITE3_TEXT);
                    $checkResult = $checkStmt->execute();
                    $taskExists = $checkResult->fetchArray(SQLITE3_ASSOC);
                    
                    if (!$taskExists) {
                        // 锁定任务已被删除，释放旧锁定
                        error_log("_focus_lockExtractedLinks: URL被已删除的任务({$lockedByTaskId})锁定，释放旧锁: {$url}");
                        $redis->del("focus:lock:{$url}");
                        
                        // 清除url_keywords表中的锁定标记
                        $clearStmt = $db->prepare("UPDATE url_keywords SET is_locked = 0, locked_by_task_id = NULL WHERE full_url = ?");
                        $clearStmt->bindValue(1, $url, SQLITE3_TEXT);
                        $clearStmt->execute();
                    } else {
                        // 任务仍存在，跳过此URL
                        $skippedCount++;
                        error_log("_focus_lockExtractedLinks: URL已被其他任务({$lockedByTaskId})锁定，跳过: {$url}");
                        continue;
                    }
                } elseif ($lockedByTaskId === $taskId) {
                    // 已被当前任务锁定，仅更新Redis数据
                    error_log("_focus_lockExtractedLinks: URL已被当前任务锁定，更新Redis: {$url}");
                }
            }
            
            // 锁定URL到Redis（无论是新锁定还是更新现有锁定）
            // 注意：target_url可能为null，表示在第一次跳转时才随机选择
            $lockData = [
                'task_id' => $taskId,
                'schedule_interval' => $scheduleInterval,
                'locked_at' => time()
            ];
            
            // 只有当target_url不为null时才添加
            if ($targetUrl !== null) {
                $lockData['target_url'] = $targetUrl;
            }
            
            $redis->set("focus:lock:{$url}", json_encode($lockData));
            
            // 同时更新url_keywords表的锁定状态
            $updateStmt = $db->prepare("
                UPDATE url_keywords 
                SET is_locked = 1, 
                    locked_by_task_id = ?,
                    locked_at = CURRENT_TIMESTAMP
                WHERE full_url = ?
            ");
            $updateStmt->bindValue(1, $taskId, SQLITE3_TEXT);
            $updateStmt->bindValue(2, $url, SQLITE3_TEXT);
            $updateStmt->execute();
            
            // 成功处理一个URL（新锁定或更新）
            $successCount++;
        }
        
        // 更新任务的锁定URL计数（使用成功处理的数量）
        $stmt = $db->prepare("UPDATE focus_tasks SET locked_urls_count = ? WHERE id = ?");
        $stmt->bindValue(1, $successCount, SQLITE3_INTEGER);
        $stmt->bindValue(2, $taskId, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        error_log("_focus_lockExtractedLinks: 总计 {$totalCount} 个URL，成功锁定 {$successCount} 个，跳过 {$skippedCount} 个，任务ID: {$taskId}");
        error_log("_focus_lockExtractedLinks: UPDATE执行结果: " . ($result ? 'success' : 'failed'));
        
        return [
            'success' => true, 
            'locked_count' => $successCount,
            'skipped_count' => $skippedCount,
            'total_count' => $totalCount
        ];
        
    } catch (Exception $e) {
        error_log("_focus_lockExtractedLinks error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 更新任务配置（别名函数）
 */
function _focus_updateTaskConfig($taskId, $config) {
    return _focus_saveTaskConfig($taskId, $config);
}

/**
 * 同步任务到Redis
 */
function _focus_syncTaskToRedis($taskId) {
    try {
        error_log("_focus_syncTaskToRedis: 开始同步任务 $taskId");
        
        $redis = getRedis();
        if (!$redis) {
            error_log("_focus_syncTaskToRedis: Redis连接失败");
            return false;
        }
        
        // 获取任务详情
        $result = _focus_getTaskDetail($taskId);
        if (!$result['success']) {
            error_log("_focus_syncTaskToRedis: 获取任务详情失败");
            return false;
        }
        
        $task = $result['task'];
        error_log("_focus_syncTaskToRedis: 任务spider_filter=" . json_encode($task['spider_filter'] ?? null));
        
        // 保存完整的任务配置到Redis（使用JSON格式，与FocusRedirect模块兼容）
        $key = "focus:task:$taskId";
        $taskData = [
            'id' => $task['id'],
            'name' => $task['name'],
            'mode' => $task['mode'] ?? 'focus',
            'enabled' => $task['enabled'] ? 1 : 0,
            'redirect_type' => $task['redirect_type'],
            'probability' => $task['probability'],
            'schedule_days' => $task['schedule_days'] ?? 0,
            'schedule_hours' => $task['schedule_hours'] ?? 0,
            'schedule_minutes' => $task['schedule_minutes'] ?? 0,
            'target_url' => $task['target_url'] ?? '',
            'spider_filter' => $task['spider_filter'] ?? ['enabled' => false],
            'filter_keywords' => $task['filter_keywords'] ?? [],
            'locked_urls_count' => $task['locked_urls_count'] ?? 0
        ];
        
        // 解析target_url为数组
        $targetUrl = $taskData['target_url'];
        if (!empty($targetUrl) && $targetUrl[0] === '[') {
            $taskData['target_urls'] = json_decode($targetUrl, true) ?: [$targetUrl];
        } else {
            $taskData['target_urls'] = [$targetUrl];
        }
        
        $redis->set($key, json_encode($taskData));
        error_log("_focus_syncTaskToRedis: 已保存到Redis, key=$key");
        error_log("_focus_syncTaskToRedis: Redis中的spider_filter=" . json_encode($taskData['spider_filter']));
        
        // 验证Redis中的数据
        $verifyData = $redis->get($key);
        if ($verifyData) {
            $verifyTask = json_decode($verifyData, true);
            error_log("_focus_syncTaskToRedis: 验证Redis数据, spider_filter=" . json_encode($verifyTask['spider_filter'] ?? null));
        }
        
        // 保存统计信息
        $statsKey = "focus:stats:$taskId";
        $redis->hMSet($statsKey, [
            'total_redirects' => $task['total_redirects'] ?? 0,
            'locked_urls_count' => $task['locked_urls_count'] ?? 0,
        ]);
        
        return true;
        
    } catch (Exception $e) {
        error_log("同步任务到Redis失败: " . $e->getMessage());
        return false;
    }
}
