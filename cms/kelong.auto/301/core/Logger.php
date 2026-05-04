<?php
namespace Redirect301\Core;

/**
 * 日志记录器
 * 负责记录重定向日志到 SQLite 数据库
 */
class Logger {
    private $db = null;
    private $dbPath;
    
    public function __construct($dbPath = null) {
        $this->dbPath = $dbPath ?: __DIR__ . '/../log/redirects.db';
    }
    
    /**
     * 获取数据库连接
     */
    private function getDb() {
        if ($this->db === null) {
            $dir = dirname($this->dbPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            
            try {
                $this->db = new \SQLite3($this->dbPath);
                $this->db->busyTimeout(10000);
                $this->db->exec("PRAGMA journal_mode=WAL;");
                $this->db->exec("PRAGMA synchronous=NORMAL;");
                $this->initTable();
            } catch (\Exception $e) {
                error_log("SQLite connection failed: " . $e->getMessage());
                $this->db = false;
            }
        }
        
        return $this->db ?: null;
    }
    
    /**
     * 初始化日志表
     */
    private function initTable() {
        $sql = "CREATE TABLE IF NOT EXISTS redirect_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp INTEGER,
            datetime TEXT,
            ip TEXT,
            spider_type TEXT,
            spider_subtype TEXT,
            redirect_type TEXT,
            feature TEXT,
            source_url TEXT,
            target_url TEXT,
            domain TEXT,
            task_name TEXT
        )";
        
        $this->db->exec($sql);
        
        // ★ 创建优化索引
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_timestamp ON redirect_logs(timestamp DESC)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_feature ON redirect_logs(feature)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_task_name ON redirect_logs(task_name)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_spider_type ON redirect_logs(spider_type)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_feature_task_time ON redirect_logs(feature, task_name, timestamp DESC)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_feature_time ON redirect_logs(feature, timestamp DESC)");
    }
    
    /**
     * 调试日志（写入 error_log）
     */
    public function debug($message) {
        error_log("[Redirect301] " . $message);
    }
    
    /**
     * 记录重定向日志
     */
    public function log($data) {
        $db = $this->getDb();
        if (!$db) {
            return false;
        }
        
        $maxRetries = 3;
        $retryDelay = 50000; // 50ms.
        
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                // 兼容实际表结构（不含 user_agent 字段）
                $stmt = $db->prepare("
                    INSERT INTO redirect_logs 
                    (timestamp, datetime, ip, spider_type, spider_subtype, redirect_type, feature, source_url, target_url, domain, task_name)
                    VALUES (:timestamp, :datetime, :ip, :spider, :spider_sub, :rtype, :feature, :source, :target, :domain, :task)
                ");
                
                $timestamp = time();
                $datetime = date('Y-m-d H:i:s', $timestamp);
                $domain = parse_url($data['source_url'] ?? '', PHP_URL_HOST) ?: '';
                
                // 修复字段绑定逻辑
                // redirect_type字段：存储301/302状态码
                // feature字段：存储功能模块名称（如focus、task、group等）
                $stmt->bindValue(':timestamp', $timestamp, SQLITE3_INTEGER);
                $stmt->bindValue(':datetime', $datetime, SQLITE3_TEXT);
                $stmt->bindValue(':ip', $data['client_ip'] ?? '', SQLITE3_TEXT);
                $stmt->bindValue(':spider', $data['spider_type'] ?? '', SQLITE3_TEXT);
                $stmt->bindValue(':spider_sub', $data['spider_subtype'] ?? '', SQLITE3_TEXT);
                $stmt->bindValue(':rtype', $data['status_code'] ?? 301, SQLITE3_TEXT);
                $stmt->bindValue(':feature', $data['feature'] ?? '', SQLITE3_TEXT);
                $stmt->bindValue(':source', $data['source_url'] ?? '', SQLITE3_TEXT);
                $stmt->bindValue(':target', $data['target_url'] ?? '', SQLITE3_TEXT);
                $stmt->bindValue(':domain', $domain, SQLITE3_TEXT);
                $stmt->bindValue(':task', $data['task_name'] ?? '', SQLITE3_TEXT);
                
                $result = $stmt->execute();
                
                if ($result) {
                    return true;
                }
                
                $error = $db->lastErrorMsg();
                if (strpos($error, 'locked') !== false || strpos($error, 'busy') !== false) {
                    if ($attempt < $maxRetries - 1) {
                        usleep($retryDelay * ($attempt + 1));
                        continue;
                    }
                }
                
                error_log("SQLite insert failed (attempt " . ($attempt + 1) . "): " . $error);
            } catch (\Exception $e) {
                $errorMsg = $e->getMessage();
                if ((strpos($errorMsg, 'locked') !== false || strpos($errorMsg, 'busy') !== false) 
                    && $attempt < $maxRetries - 1) {
                    usleep($retryDelay * ($attempt + 1));
                    continue;
                }
                error_log("SQLite insert exception (attempt " . ($attempt + 1) . "): " . $errorMsg);
            }
        }
        
        return false;
    }
    
    /**
     * 查询日志
     */
    public function query($conditions = [], $limit = 100, $offset = 0) {
        $db = $this->getDb();
        if (!$db) {
            return [];
        }
        
        $where = [];
        $params = [];
        
        if (!empty($conditions['spider_type'])) {
            $where[] = "spider_type = :spider";
            $params[':spider'] = $conditions['spider_type'];
        }
        
        if (!empty($conditions['redirect_type'])) {
            $where[] = "redirect_type = :type";
            $params[':type'] = $conditions['redirect_type'];
        }
        
        if (!empty($conditions['task_name'])) {
            $where[] = "task_name = :task";
            $params[':task'] = $conditions['task_name'];
        }
        
        if (!empty($conditions['date_from'])) {
            $where[] = "timestamp >= :date_from";
            $params[':date_from'] = $conditions['date_from'];
        }
        
        if (!empty($conditions['date_to'])) {
            $where[] = "timestamp <= :date_to";
            $params[':date_to'] = $conditions['date_to'];
        }
        
        $sql = "SELECT * FROM redirect_logs";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " ORDER BY timestamp DESC LIMIT :limit OFFSET :offset";
        
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
        
        $result = $stmt->execute();
        $logs = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $logs[] = $row;
        }
        
        return $logs;
    }
    
    /**
     * 统计日志数量
     */
    public function count($conditions = []) {
        $db = $this->getDb();
        if (!$db) {
            return 0;
        }
        
        $where = [];
        $params = [];
        
        if (!empty($conditions['spider_type'])) {
            $where[] = "spider_type = :spider";
            $params[':spider'] = $conditions['spider_type'];
        }
        
        if (!empty($conditions['redirect_type'])) {
            $where[] = "redirect_type = :type";
            $params[':type'] = $conditions['redirect_type'];
        }
        
        if (!empty($conditions['task_name'])) {
            $where[] = "task_name = :task";
            $params[':task'] = $conditions['task_name'];
        }
        
        if (!empty($conditions['date_from'])) {
            $where[] = "timestamp >= :date_from";
            $params[':date_from'] = $conditions['date_from'];
        }
        
        if (!empty($conditions['date_to'])) {
            $where[] = "timestamp <= :date_to";
            $params[':date_to'] = $conditions['date_to'];
        }
        
        $sql = "SELECT COUNT(*) as total FROM redirect_logs";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        return $row['total'] ?? 0;
    }
    
    /**
     * 清理旧日志
     */
    public function cleanup($days = 30) {
        $db = $this->getDb();
        if (!$db) {
            return false;
        }
        
        $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $stmt = $db->prepare("DELETE FROM redirect_logs WHERE timestamp < :date");
        $stmt->bindValue(':date', $date);
        
        return $stmt->execute() !== false;
    }
}

