<?php
/**
 * 域名数据库管理器（存储所有域名详细信息）
 * 按分组和父子关系组织
 */

class DomainsDB {
    private static $instance = null;
    private $db;
    private $db_path;
    
    private function __construct() {
        // 使用绝对路径
        if (!defined('KELONG_ROOT_DIR')) {
            require_once __DIR__ . '/config.php';
        }
        
        $db_dir = KELONG_DOMAIN_GROUPS_DIR;
        
        if (!is_dir($db_dir)) {
            mkdir($db_dir, 0755, true);
        }
        
        $this->db_path = KELONG_DOMAINS_DB;
        $this->initDB();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function initDB() {
        try {
            $is_new = !file_exists($this->db_path);
            
            $this->db = new PDO('sqlite:' . $this->db_path);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // 性能优化
            $this->db->exec('PRAGMA journal_mode = WAL');
            $this->db->exec('PRAGMA synchronous = NORMAL');
            $this->db->exec('PRAGMA cache_size = 10000');
            
            if ($is_new) {
                $this->createTables();
            }
            
        } catch (PDOException $e) {
            error_log('DomainsDB Init Error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    private function createTables() {
        // 域名表（存储所有域名）
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS domains (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                domain VARCHAR(255) UNIQUE NOT NULL,
                parent_domain VARCHAR(255),
                group_id VARCHAR(100),
                level INTEGER DEFAULT 1,
                is_subdomain INTEGER DEFAULT 0,
                clone_url TEXT,
                title TEXT,
                keywords TEXT,
                description TEXT,
                created_at DATETIME NOT NULL,
                config_json TEXT
            )
        ');
        
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_domain ON domains(domain)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_parent ON domains(parent_domain)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_group ON domains(group_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_level ON domains(level, is_subdomain)');
    }
    
    /**
     * 添加域名
     */
    public function addDomain($domain, $group_id, $config = [], $parent_domain = null) {
        try {
            $is_subdomain = !empty($parent_domain) ? 1 : 0;
            $level = $is_subdomain ? 2 : 1;
            
            $stmt = $this->db->prepare('
                INSERT OR REPLACE INTO domains (
                    domain, parent_domain, group_id, level, is_subdomain,
                    clone_url, title, keywords, description,
                    created_at, config_json
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            
            return $stmt->execute([
                $domain,
                $parent_domain,
                $group_id,
                $level,
                $is_subdomain,
                $config['clone_url'] ?? '',
                $config['title'] ?? '',
                $config['keywords'] ?? '',
                $config['description'] ?? '',
                date('Y-m-d H:i:s'),
                json_encode($config, JSON_UNESCAPED_UNICODE)
            ]);
            
        } catch (PDOException $e) {
            error_log('DomainsDB addDomain Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取单个域名信息
     */
    public function getDomain($domain) {
        try {
            $stmt = $this->db->prepare('SELECT * FROM domains WHERE domain = ? LIMIT 1');
            $stmt->execute([$domain]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('DomainsDB getDomain Error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 获取分组的域名（按需加载）
     */
    public function getDomainsByGroup($group_id, $parent_only = false) {
        try {
            if ($parent_only) {
                $sql = 'SELECT * FROM domains WHERE group_id = ? AND is_subdomain = 0 ORDER BY domain';
            } else {
                $sql = 'SELECT * FROM domains WHERE group_id = ? ORDER BY level, domain';
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$group_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log('DomainsDB getDomainsByGroup Error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 🆕 一次性获取所有域名，按分组ID分组返回
     * 性能优化：避免N次查询（如果有100个分组就避免100次查询）
     * @param bool $parent_only 是否只获取顶级域名
     * @return array ['group_id' => [域名数组]]
     */
    public function getAllDomainsByGroups($parent_only = false) {
        try {
            $sql = 'SELECT * FROM domains';
            if ($parent_only) {
                $sql .= ' WHERE is_subdomain = 0';
            }
            $sql .= ' ORDER BY group_id, level, domain';
            
            $stmt = $this->db->query($sql);
            $allDomains = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 按分组ID分组
            $result = [];
            foreach ($allDomains as $domain) {
                $groupId = $domain['group_id'];
                if (!isset($result[$groupId])) {
                    $result[$groupId] = [];
                }
                $result[$groupId][] = $domain;
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log('DomainsDB::getAllDomainsByGroups Error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取父域名的子域名
     */
    public function getSubdomains($parent_domain) {
        try {
            $stmt = $this->db->prepare('
                SELECT * FROM domains 
                WHERE parent_domain = ? 
                ORDER BY domain
            ');
            $stmt->execute([$parent_domain]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log('DomainsDB getSubdomains Error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 统计分组的域名数量
     */
    public function countGroupDomains($group_id) {
        try {
            $stmt = $this->db->prepare('
                SELECT 
                    COUNT(CASE WHEN is_subdomain = 0 THEN 1 END) as domain_count,
                    COUNT(CASE WHEN is_subdomain = 1 THEN 1 END) as subdomain_count
                FROM domains 
                WHERE group_id = ?
            ');
            $stmt->execute([$group_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log('DomainsDB countGroupDomains Error: ' . $e->getMessage());
            return ['domain_count' => 0, 'subdomain_count' => 0];
        }
    }
    
    /**
     * 删除域名
     */
    public function deleteDomain($domain, $delete_children = false) {
        try {
            $this->db->beginTransaction();
            
            if ($delete_children) {
                $stmt = $this->db->prepare('DELETE FROM domains WHERE parent_domain = ?');
                $stmt->execute([$domain]);
            }
            
            $stmt = $this->db->prepare('DELETE FROM domains WHERE domain = ?');
            $stmt->execute([$domain]);
            
            $this->db->commit();
            return true;
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log('DomainsDB deleteDomain Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 🆕 清空所有域名数据（危险操作）
     */
    public function clearAll() {
        try {
            $this->db->exec('DELETE FROM domains');
            $this->db->exec('VACUUM'); // 优化数据库文件大小
            return true;
        } catch (PDOException $e) {
            error_log('DomainsDB clearAll Error: ' . $e->getMessage());
            return false;
        }
    }
    
    public function getDBPath() {
        return $this->db_path;
    }
    
    public function getDBSize() {
        return file_exists($this->db_path) ? filesize($this->db_path) : 0;
    }
}

function getDomainsDB() {
    return DomainsDB::getInstance();
}
