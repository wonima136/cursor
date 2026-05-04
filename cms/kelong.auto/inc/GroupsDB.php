<?php
/**
 * 分组数据库管理器（只存储分组汇总信息）
 * 用于快速加载分组列表
 */

class GroupsDB {
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
        
        $this->db_path = KELONG_GROUPS_DB;
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
            $this->db->exec('PRAGMA cache_size = 5000');
            
            if ($is_new) {
                $this->createTables();
            } else {
                // 检查并添加新字段（兼容旧数据库）
                $this->upgradeSchema();
            }
            
        } catch (PDOException $e) {
            error_log('GroupsDB Init Error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 升级数据库结构（添加新字段）
     */
    private function upgradeSchema() {
        try {
            // 检查 domain_list_json 字段是否存在
            $result = $this->db->query("PRAGMA table_info(groups)");
            $columns = $result->fetchAll(PDO::FETCH_ASSOC);
            $hasField = false;
            
            foreach ($columns as $col) {
                if ($col['name'] === 'domain_list_json') {
                    $hasField = true;
                    break;
                }
            }
            
            if (!$hasField) {
                $this->db->exec('ALTER TABLE groups ADD COLUMN domain_list_json TEXT DEFAULT "[]"');
                error_log('GroupsDB: Added domain_list_json column');
            }
        } catch (PDOException $e) {
            error_log('GroupsDB upgradeSchema Error: ' . $e->getMessage());
        }
    }
    
    private function createTables() {
        // 分组表（存储汇总信息 + 域名列表）
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                group_id VARCHAR(100) UNIQUE NOT NULL,
                group_name VARCHAR(255) NOT NULL,
                group_type VARCHAR(50),
                root_value VARCHAR(255),
                clone_url TEXT,
                subdomain_mode VARCHAR(50),
                domain_count INTEGER DEFAULT 0,
                subdomain_count INTEGER DEFAULT 0,
                domain_list_json TEXT,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                config_json TEXT
            )
        ');
        
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_group_id ON groups(group_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_created_at ON groups(created_at)');
    }
    
    /**
     * 保存分组（汇总信息 + 域名列表）
     */
    public function saveGroup($group_id, $group_name, $group_type, $config = []) {
        try {
            $stmt = $this->db->prepare('
                INSERT OR REPLACE INTO groups (
                    group_id, group_name, group_type, root_value,
                    clone_url, subdomain_mode,
                    domain_count, subdomain_count, domain_list_json,
                    created_at, updated_at, config_json
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            
            // 🆕 如果传入了完整的分组数据，使用它；否则使用简化配置
            $configJson = isset($config['full_group_data']) 
                ? json_encode($config['full_group_data'], JSON_UNESCAPED_UNICODE)
                : json_encode($config, JSON_UNESCAPED_UNICODE);
            
            return $stmt->execute([
                $group_id,
                $group_name,
                $group_type,
                $config['root_value'] ?? '',
                $config['clone_url'] ?? '',
                $config['subdomain_mode'] ?? 'fixed_top',
                $config['domain_count'] ?? 0,
                $config['subdomain_count'] ?? 0,
                $config['domain_list_json'] ?? '[]',  // 🆕 域名列表JSON
                $config['created_at'] ?? date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s'),
                $configJson  // 🆕 使用完整配置或简化配置
            ]);
            
        } catch (PDOException $e) {
            error_log('GroupsDB saveGroup Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 更新分组的域名数量
     */
    public function updateDomainCount($group_id, $domain_count, $subdomain_count = 0) {
        try {
            $stmt = $this->db->prepare('
                UPDATE groups 
                SET domain_count = ?, subdomain_count = ?, updated_at = ?
                WHERE group_id = ?
            ');
            
            return $stmt->execute([$domain_count, $subdomain_count, date('Y-m-d H:i:s'), $group_id]);
            
        } catch (PDOException $e) {
            error_log('GroupsDB updateDomainCount Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 删除分组
     */
    public function deleteGroup($group_id) {
        try {
            $stmt = $this->db->prepare('DELETE FROM groups WHERE group_id = ?');
            return $stmt->execute([$group_id]);
        } catch (PDOException $e) {
            error_log('GroupsDB deleteGroup Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 🆕 更新分组的域名列表（预处理数据，提升查询性能）
     * @param string $group_id 分组ID
     * @param array $domains 域名数组 [['domain' => 'xx.com', ...], ...]
     */
    public function updateDomainList($group_id, $domains) {
        try {
            $domainCount = 0;
            $subdomainCount = 0;
            
            // 统计顶级域名和子域名数量
            foreach ($domains as $d) {
                if (empty($d['parent_domain'])) {
                    $domainCount++;
                } else {
                    $subdomainCount++;
                }
            }
            
            $stmt = $this->db->prepare('
                UPDATE groups 
                SET domain_list_json = ?,
                    domain_count = ?,
                    subdomain_count = ?,
                    updated_at = ?
                WHERE group_id = ?
            ');
            
            return $stmt->execute([
                json_encode($domains, JSON_UNESCAPED_UNICODE),
                $domainCount,
                $subdomainCount,
                date('Y-m-d H:i:s'),
                $group_id
            ]);
            
        } catch (PDOException $e) {
            error_log('GroupsDB updateDomainList Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 🆕 增量添加域名到分组（用于动态添加子域名）
     * @param string $group_id 分组ID
     * @param array $newDomain 新域名数据 ['domain' => 'xx.com', 'parent_domain' => '', ...]
     */
    public function addDomainToList($group_id, $newDomain) {
        try {
            // 获取当前分组信息
            $group = $this->getGroup($group_id);
            if (!$group) {
                return false;
            }
            
            // 解析现有域名列表
            $domains = json_decode($group['domain_list_json'] ?: '[]', true);
            
            // 检查域名是否已存在
            $exists = false;
            foreach ($domains as $d) {
                if ($d['domain'] === $newDomain['domain']) {
                    $exists = true;
                    break;
                }
            }
            
            // 如果不存在，则添加
            if (!$exists) {
                $domains[] = $newDomain;
                
                // 判断是顶级域名还是子域名
                $isSubdomain = !empty($newDomain['parent_domain']);
                
                // 增量更新计数
                if ($isSubdomain) {
                    $stmt = $this->db->prepare('
                        UPDATE groups 
                        SET domain_list_json = ?,
                            subdomain_count = subdomain_count + 1,
                            updated_at = ?
                        WHERE group_id = ?
                    ');
                } else {
                    $stmt = $this->db->prepare('
                        UPDATE groups 
                        SET domain_list_json = ?,
                            domain_count = domain_count + 1,
                            updated_at = ?
                        WHERE group_id = ?
                    ');
                }
                
                return $stmt->execute([
                    json_encode($domains, JSON_UNESCAPED_UNICODE),
                    date('Y-m-d H:i:s'),
                    $group_id
                ]);
            }
            
            return true; // 已存在，返回成功
            
        } catch (PDOException $e) {
            error_log('GroupsDB addDomainToList Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 🆕 从分组中删除域名（用于删除域名时同步更新）
     * @param string $group_id 分组ID
     * @param string $domain 要删除的域名
     */
    public function removeDomainFromList($group_id, $domain) {
        try {
            // 获取当前分组信息
            $group = $this->getGroup($group_id);
            if (!$group) {
                return false;
            }
            
            // 解析现有域名列表
            $domains = json_decode($group['domain_list_json'] ?: '[]', true);
            
            // 查找并删除域名
            $found = false;
            $isSubdomain = false;
            $newDomains = [];
            
            foreach ($domains as $d) {
                if ($d['domain'] === $domain) {
                    $found = true;
                    $isSubdomain = !empty($d['parent_domain']);
                } else {
                    $newDomains[] = $d;
                }
            }
            
            if ($found) {
                // 减少计数
                if ($isSubdomain) {
                    $stmt = $this->db->prepare('
                        UPDATE groups 
                        SET domain_list_json = ?,
                            subdomain_count = CASE WHEN subdomain_count > 0 THEN subdomain_count - 1 ELSE 0 END,
                            updated_at = ?
                        WHERE group_id = ?
                    ');
                } else {
                    $stmt = $this->db->prepare('
                        UPDATE groups 
                        SET domain_list_json = ?,
                            domain_count = CASE WHEN domain_count > 0 THEN domain_count - 1 ELSE 0 END,
                            updated_at = ?
                        WHERE group_id = ?
                    ');
                }
                
                return $stmt->execute([
                    json_encode($newDomains, JSON_UNESCAPED_UNICODE),
                    date('Y-m-d H:i:s'),
                    $group_id
                ]);
            }
            
            return true; // 未找到，返回成功
            
        } catch (PDOException $e) {
            error_log('GroupsDB removeDomainFromList Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取所有分组（快速，不含域名列表）
     */
    public function getAllGroups() {
        try {
            $stmt = $this->db->query('SELECT * FROM groups ORDER BY created_at DESC');
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log('GroupsDB getAllGroups Error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取单个分组
     */
    public function getGroup($group_id) {
        try {
            $stmt = $this->db->prepare('SELECT * FROM groups WHERE group_id = ?');
            $stmt->execute([$group_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log('GroupsDB getGroup Error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 搜索分组
     */
    public function searchGroups($keyword) {
        try {
            $stmt = $this->db->prepare('
                SELECT * FROM groups 
                WHERE group_name LIKE ? OR group_id LIKE ?
                ORDER BY created_at DESC
            ');
            $stmt->execute(["%{$keyword}%", "%{$keyword}%"]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log('GroupsDB searchGroups Error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取统计信息
     */
    public function getStats() {
        try {
            $stmt = $this->db->query('SELECT COUNT(*) as count FROM groups');
            $total_groups = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            $stmt = $this->db->query('SELECT SUM(domain_count) as count FROM groups');
            $total_domains = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            
            $stmt = $this->db->query('SELECT SUM(subdomain_count) as count FROM groups');
            $total_subdomains = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            
            return [
                'total_groups' => $total_groups,
                'total_domains' => $total_domains,
                'total_subdomains' => $total_subdomains
            ];
            
        } catch (PDOException $e) {
            error_log('GroupsDB getStats Error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 🆕 清空所有分组数据（危险操作）
     */
    public function clearAll() {
        try {
            $this->db->exec('DELETE FROM groups');
            $this->db->exec('VACUUM'); // 优化数据库文件大小
            return true;
        } catch (PDOException $e) {
            error_log('GroupsDB clearAll Error: ' . $e->getMessage());
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

function getGroupsDB() {
    return GroupsDB::getInstance();
}
