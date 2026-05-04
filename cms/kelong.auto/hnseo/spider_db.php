<?php
/**
 * 蜘蛛统计数据库管理类
 * 使用 SQLite 按天分库存储蜘蛛访问记录
 * 
 * 文件结构：
 * spider_data/
 * ├── 2025-12-30.db
 * ├── 2025-12-29.db
 * └── ...
 */

class SpiderDB {
    private static $instance = null;
    private $connections = array(); // 缓存数据库连接
    private $db_dir;
    
    // 蜘蛛类型映射
    public static $spider_types = array(
        'Baiduspider' => '百度',
        'Googlebot' => '谷歌',
        'Sogou' => '搜狗',
        '360Spider' => '360',
        'Yisouspider' => '神马',
        'Bytespider' => '今日头条'
    );
    
    private function __construct() {
        $this->db_dir = __DIR__ . '/spider_data';
        if (!is_dir($this->db_dir)) {
            mkdir($this->db_dir, 0755, true);
        }
    }
    
    /**
     * 获取单例实例
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 获取指定日期的数据库连接
     * @param string $date Y-m-d 格式
     */
    public function getDBByDate($date) {
        if (isset($this->connections[$date])) {
            return $this->connections[$date];
        }
        
        $db_path = $this->db_dir . '/' . $date . '.db';
        $is_new = !file_exists($db_path);
        
        try {
            $db = new PDO('sqlite:' . $db_path);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // 性能优化
            $db->exec('PRAGMA journal_mode = WAL');
            $db->exec('PRAGMA synchronous = NORMAL');
            $db->exec('PRAGMA cache_size = 5000');
            $db->exec('PRAGMA temp_store = MEMORY');
            
            if ($is_new) {
                $this->initTable($db);
            }
            
            $this->connections[$date] = $db;
            return $db;
            
        } catch (PDOException $e) {
            // error_log('Spider DB Error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 获取今天的数据库
     */
    public function getDB() {
        return $this->getDBByDate(date('Y-m-d'));
    }
    
    /**
     * 初始化数据表
     */
    private function initTable($db) {
        $db->exec('
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
        ');
        
        $db->exec('CREATE INDEX IF NOT EXISTS idx_domain ON spider_visits(domain)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_spider_type ON spider_visits(spider_type)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_hour ON spider_visits(visit_hour)');
    }
    
    /**
     * 获取数据库目录
     */
    public function getDBDir() {
        return $this->db_dir;
    }
    
    /**
     * 获取所有可用的日期列表
     */
    public function getAvailableDates() {
        $dates = array();
        $files = glob($this->db_dir . '/*.db');
        foreach ($files as $file) {
            $date = basename($file, '.db');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $dates[] = $date;
            }
        }
        rsort($dates); // 最新的在前
        return $dates;
    }
    
    /**
     * 插入蜘蛛访问记录
     */
    public function insertVisit($visit_time, $ip, $spider_type, $url, $domain, $spider_name_override = null) {
        $date = date('Y-m-d', strtotime($visit_time));
        $db = $this->getDBByDate($date);
        if (!$db) return false;
        
        $spider_name = $spider_name_override !== null ? $spider_name_override : (isset(self::$spider_types[$spider_type]) ? self::$spider_types[$spider_type] : $spider_type);
        $visit_hour = (int)date('H', strtotime($visit_time));
        
        $stmt = $db->prepare('
            INSERT INTO spider_visits (visit_time, ip, spider_type, spider_name, url, domain, visit_hour)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        
        return $stmt->execute(array($visit_time, $ip, $spider_type, $spider_name, $url, $domain, $visit_hour));
    }
    
    /**
     * 批量插入记录（用于数据迁移）
     */
    public function batchInsert($records, $date = null) {
        if (empty($records)) return true;
        
        // 按日期分组
        $grouped = array();
        foreach ($records as $record) {
            $record_date = $date ? $date : date('Y-m-d', strtotime($record['visit_time']));
            if (!isset($grouped[$record_date])) {
                $grouped[$record_date] = array();
            }
            $grouped[$record_date][] = $record;
        }
        
        // 分别插入各日期的数据库
        foreach ($grouped as $record_date => $date_records) {
            $db = $this->getDBByDate($record_date);
            if (!$db) continue;
            
            $db->beginTransaction();
            try {
                $stmt = $db->prepare('
                    INSERT INTO spider_visits (visit_time, ip, spider_type, spider_name, url, domain, visit_hour)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                
                foreach ($date_records as $record) {
                    $spider_name = isset($record['spider_name']) ? $record['spider_name'] : (isset(self::$spider_types[$record['spider_type']]) ? self::$spider_types[$record['spider_type']] : $record['spider_type']);
                    $visit_hour = (int)date('H', strtotime($record['visit_time']));
                    
                    $stmt->execute(array(
                        $record['visit_time'],
                        $record['ip'],
                        $record['spider_type'],
                        $spider_name,
                        $record['url'],
                        $record['domain'],
                        $visit_hour
                    ));
                }
                
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                // error_log('Batch insert error: ' . $e->getMessage());
            }
        }
        
        return true;
    }
    
    /**
     * 获取指定日期的蜘蛛统计
     */
    public function getDayStats($date, $group_id = null) {
        $db = $this->getDBByDate($date);
        if (!$db) return array();
        
        $allowed_domains = $this->getGroupDomains($group_id);
        
        // 如果没有域名限制，直接查询
        if ($allowed_domains === null) {
            $sql = 'SELECT spider_type, spider_name, COUNT(*) as count FROM spider_visits GROUP BY spider_type ORDER BY count DESC';
            $stmt = $db->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // 如果域名数量超过900，分批查询并合并结果
        if (count($allowed_domains) > 900) {
            return $this->getDayStatsBatch($db, $allowed_domains);
        }
        
        // 正常查询
        $placeholders = implode(',', array_fill(0, count($allowed_domains), '?'));
        $sql = "SELECT spider_type, spider_name, COUNT(*) as count FROM spider_visits WHERE domain IN ($placeholders) GROUP BY spider_type ORDER BY count DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($allowed_domains);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 分批获取蜘蛛统计（处理大量域名）
     */
    private function getDayStatsBatch($db, $domains) {
        $batch_size = 900;
        $batches = array_chunk($domains, $batch_size);
        $totals = array();
        
        foreach ($batches as $batch) {
            $placeholders = implode(',', array_fill(0, count($batch), '?'));
            $sql = "SELECT spider_type, spider_name, COUNT(*) as count FROM spider_visits WHERE domain IN ($placeholders) GROUP BY spider_type";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($batch);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $key = $row['spider_type'];
                if (!isset($totals[$key])) {
                    $totals[$key] = array(
                        'spider_type' => $row['spider_type'],
                        'spider_name' => $row['spider_name'],
                        'count' => 0
                    );
                }
                $totals[$key]['count'] += (int)$row['count'];
            }
        }
        
        // 按数量排序
        usort($totals, function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        return $totals;
    }
    
    /**
     * 获取指定日期的小时分布
     */
    public function getHourlyStats($date, $spider_type = null, $group_id = null) {
        $db = $this->getDBByDate($date);
        if (!$db) return array_fill(0, 24, 0);
        
        $allowed_domains = $this->getGroupDomains($group_id);
        
        // 如果域名数量超过900，分批查询
        if ($allowed_domains !== null && count($allowed_domains) > 900) {
            return $this->getHourlyStatsBatch($db, $allowed_domains, $spider_type);
        }
        
        $sql = 'SELECT visit_hour as hour, COUNT(*) as count FROM spider_visits WHERE 1=1';
        $params = array();
        
        if ($spider_type) {
            $sql .= ' AND spider_type = ?';
            $params[] = $spider_type;
        }
        
        if ($allowed_domains !== null) {
            $placeholders = implode(',', array_fill(0, count($allowed_domains), '?'));
            $sql .= " AND domain IN ($placeholders)";
            $params = array_merge($params, $allowed_domains);
        }
        
        $sql .= ' GROUP BY visit_hour ORDER BY visit_hour';
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $result = array_fill(0, 24, 0);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['hour']] = (int)$row['count'];
        }
        return $result;
    }
    
    /**
     * 分批获取小时分布（处理大量域名）
     */
    private function getHourlyStatsBatch($db, $domains, $spider_type = null) {
        $batch_size = 900;
        $batches = array_chunk($domains, $batch_size);
        $result = array_fill(0, 24, 0);
        
        foreach ($batches as $batch) {
            $sql = 'SELECT visit_hour as hour, COUNT(*) as count FROM spider_visits WHERE 1=1';
            $params = array();
            
            if ($spider_type) {
                $sql .= ' AND spider_type = ?';
                $params[] = $spider_type;
            }
            
            $placeholders = implode(',', array_fill(0, count($batch), '?'));
            $sql .= " AND domain IN ($placeholders)";
            $params = array_merge($params, $batch);
            
            $sql .= ' GROUP BY visit_hour';
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $result[$row['hour']] += (int)$row['count'];
            }
        }
        
        return $result;
    }
    
    /**
     * 获取多日趋势数据
     */
    public function getTrendStats($days = 10, $spider_type = null, $group_id = null) {
        $result = array();
        $allowed_domains = $this->getGroupDomains($group_id);
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $result[$date] = 0;
            
            $db = $this->getDBByDate($date);
            if (!$db) continue;
            
            // 如果域名数量超过900，分批查询
            if ($allowed_domains !== null && count($allowed_domains) > 900) {
                $batch_size = 900;
                $batches = array_chunk($allowed_domains, $batch_size);
                
                foreach ($batches as $batch) {
                    $sql = 'SELECT COUNT(*) FROM spider_visits WHERE 1=1';
                    $params = array();
                    
                    if ($spider_type) {
                        $sql .= ' AND spider_type = ?';
                        $params[] = $spider_type;
                    }
                    
                    $placeholders = implode(',', array_fill(0, count($batch), '?'));
                    $sql .= " AND domain IN ($placeholders)";
                    $params = array_merge($params, $batch);
                    
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    $result[$date] += (int)$stmt->fetchColumn();
                }
            } else {
                $sql = 'SELECT COUNT(*) FROM spider_visits WHERE 1=1';
                $params = array();
                
                if ($spider_type) {
                    $sql .= ' AND spider_type = ?';
                    $params[] = $spider_type;
                }
                
                if ($allowed_domains !== null) {
                    $placeholders = implode(',', array_fill(0, count($allowed_domains), '?'));
                    $sql .= " AND domain IN ($placeholders)";
                    $params = array_merge($params, $allowed_domains);
                }
                
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $result[$date] = (int)$stmt->fetchColumn();
            }
        }
        
        return $result;
    }
    
    /**
     * 获取蜘蛛占比（饼图数据）
     */
    public function getSpiderRatio($days = 0, $group_id = null) {
        $totals = array();
        $allowed_domains = $this->getGroupDomains($group_id);
        
        // 确定查询的日期范围
        if ($days == 0) {
            $dates = array(date('Y-m-d'));
        } elseif ($days == 1) {
            $dates = array(date('Y-m-d', strtotime('-1 day')));
        } else {
            $dates = array();
            for ($i = $days - 1; $i >= 0; $i--) {
                $dates[] = date('Y-m-d', strtotime("-$i days"));
            }
        }
        
        foreach ($dates as $date) {
            $db = $this->getDBByDate($date);
            if (!$db) continue;
            
            // 如果域名数量超过900，分批查询
            if ($allowed_domains !== null && count($allowed_domains) > 900) {
                $batch_size = 900;
                $batches = array_chunk($allowed_domains, $batch_size);
                
                foreach ($batches as $batch) {
                    $placeholders = implode(',', array_fill(0, count($batch), '?'));
                    $sql = "SELECT spider_name as name, COUNT(*) as value FROM spider_visits WHERE domain IN ($placeholders) GROUP BY spider_name";
                    
                    $stmt = $db->prepare($sql);
                    $stmt->execute($batch);
                    
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $name = $row['name'];
                        if (!isset($totals[$name])) {
                            $totals[$name] = 0;
                        }
                        $totals[$name] += (int)$row['value'];
                    }
                }
            } else {
                $sql = 'SELECT spider_name as name, COUNT(*) as value FROM spider_visits';
                $params = array();
                
                if ($allowed_domains !== null) {
                    $placeholders = implode(',', array_fill(0, count($allowed_domains), '?'));
                    $sql .= " WHERE domain IN ($placeholders)";
                    $params = $allowed_domains;
                }
                
                $sql .= ' GROUP BY spider_name';
                
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $name = $row['name'];
                    if (!isset($totals[$name])) {
                        $totals[$name] = 0;
                    }
                    $totals[$name] += (int)$row['value'];
                }
            }
        }
        
        // 转换为数组格式并排序
        $result = array();
        foreach ($totals as $name => $value) {
            $result[] = array('name' => $name, 'value' => $value);
        }
        usort($result, function($a, $b) {
            return $b['value'] - $a['value'];
        });
        
        return $result;
    }
    
    /**
     * 获取指定日期的蜘蛛占比（单日数据）
     */
    public function getSpiderRatioByDate($date, $group_id = null) {
        $db = $this->getDBByDate($date);
        if (!$db) {
            return array();
        }
        
        $allowed_domains = $this->getGroupDomains($group_id);
        
        // 如果域名数量超过900，分批查询
        if ($allowed_domains !== null && count($allowed_domains) > 900) {
            $batch_size = 900;
            $batches = array_chunk($allowed_domains, $batch_size);
            $totals = array();
            
            foreach ($batches as $batch) {
                $placeholders = implode(',', array_fill(0, count($batch), '?'));
                $sql = "SELECT spider_name as name, COUNT(*) as value FROM spider_visits WHERE domain IN ($placeholders) GROUP BY spider_name";
                
                $stmt = $db->prepare($sql);
                $stmt->execute($batch);
                
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $name = $row['name'];
                    if (!isset($totals[$name])) {
                        $totals[$name] = 0;
                    }
                    $totals[$name] += (int)$row['value'];
                }
            }
            
            // 转换并排序
            $result = array();
            foreach ($totals as $name => $value) {
                $result[] = array('name' => $name, 'value' => $value);
            }
            usort($result, function($a, $b) {
                return $b['value'] - $a['value'];
            });
            
            return $result;
        }
        
        $sql = 'SELECT spider_name as name, COUNT(*) as value FROM spider_visits';
        $params = array();
        
        if ($allowed_domains !== null) {
            $placeholders = implode(',', array_fill(0, count($allowed_domains), '?'));
            $sql .= " WHERE domain IN ($placeholders)";
            $params = $allowed_domains;
        }
        
        $sql .= ' GROUP BY spider_name ORDER BY value DESC';
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $result = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[] = array('name' => $row['name'], 'value' => (int)$row['value']);
        }
        
        return $result;
    }
    
    /**
     * 获取访问明细列表（分页）
     */
    public function getVisitList($date, $spider_type = null, $page = 1, $page_size = 10, $group_id = null) {
        $db = $this->getDBByDate($date);
        if (!$db) {
            return array(
                'total' => 0,
                'list' => array(),
                'stats' => array(),
                'page' => $page,
                'page_size' => $page_size,
                'pages' => 0
            );
        }
        
        $allowed_domains = $this->getGroupDomains($group_id);
        $offset = ($page - 1) * $page_size;
        
        // 构建查询条件
        $where = '1=1';
        $params = array();
        
        if ($spider_type && $spider_type !== 'zong') {
            $where .= ' AND spider_type = ?';
            $params[] = $spider_type;
        }
        
        if ($allowed_domains !== null) {
            $placeholders = implode(',', array_fill(0, count($allowed_domains), '?'));
            $where .= " AND domain IN ($placeholders)";
            $params = array_merge($params, $allowed_domains);
        }
        
        // 查询总数
        $stmt = $db->prepare("SELECT COUNT(*) FROM spider_visits WHERE $where");
        $stmt->execute($params);
        $total = $stmt->fetchColumn();
        
        // 查询列表
        $list_params = $params;
        $list_params[] = $page_size;
        $list_params[] = $offset;
        
        $stmt = $db->prepare("
            SELECT id, visit_time, ip, spider_type, spider_name, url, domain
            FROM spider_visits 
            WHERE $where
            ORDER BY visit_time DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($list_params);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 获取各蜘蛛统计
        $stats = $this->getDayStats($date, $group_id);
        
        return array(
            'total' => $total,
            'list' => $list,
            'stats' => $stats,
            'page' => $page,
            'page_size' => $page_size,
            'pages' => ceil($total / $page_size)
        );
    }
    
    /**
     * 获取域名统计（支持日期范围）
     */
    public function getDomainStats($start_date, $end_date, $group_id = null) {
        $allowed_domains = $this->getGroupDomains($group_id);
        $domain_stats = array();
        
        // 遍历日期范围
        $current = strtotime($start_date);
        $end = strtotime($end_date);
        
        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            $db = $this->getDBByDate($date);
            
            if ($db) {
                // 如果域名数量超过900，分批查询
                if ($allowed_domains !== null && count($allowed_domains) > 900) {
                    $batch_size = 900;
                    $batches = array_chunk($allowed_domains, $batch_size);
                    
                    foreach ($batches as $batch) {
                        $placeholders = implode(',', array_fill(0, count($batch), '?'));
                        $sql = "
                            SELECT 
                                domain,
                                COUNT(*) as total,
                                SUM(CASE WHEN spider_name = '百度' OR spider_name = '百度PC' OR spider_name = '百度移动' THEN 1 ELSE 0 END) as baidu,
                                SUM(CASE WHEN spider_name = '谷歌' THEN 1 ELSE 0 END) as google,
                                SUM(CASE WHEN spider_name = '搜狗' THEN 1 ELSE 0 END) as sogou,
                                SUM(CASE WHEN spider_name = '360' THEN 1 ELSE 0 END) as s360,
                                SUM(CASE WHEN spider_name = '神马' THEN 1 ELSE 0 END) as yisou,
                                SUM(CASE WHEN spider_name = '今日头条' THEN 1 ELSE 0 END) as byte,
                                MAX(visit_time) as last_visit
                            FROM spider_visits
                            WHERE domain IN ($placeholders)
                            GROUP BY domain
                        ";
                        
                        $stmt = $db->prepare($sql);
                        $stmt->execute($batch);
                        
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $domain = $row['domain'];
                            if (!isset($domain_stats[$domain])) {
                                $domain_stats[$domain] = array(
                                    'domain' => $domain,
                                    'total' => 0,
                                    'baidu' => 0,
                                    'google' => 0,
                                    'sogou' => 0,
                                    's360' => 0,
                                    'yisou' => 0,
                                    'byte' => 0,
                                    'last_visit' => ''
                                );
                            }
                            $domain_stats[$domain]['total'] += (int)$row['total'];
                            $domain_stats[$domain]['baidu'] += (int)$row['baidu'];
                            $domain_stats[$domain]['google'] += (int)$row['google'];
                            $domain_stats[$domain]['sogou'] += (int)$row['sogou'];
                            $domain_stats[$domain]['s360'] += (int)$row['s360'];
                            $domain_stats[$domain]['yisou'] += (int)$row['yisou'];
                            $domain_stats[$domain]['byte'] += (int)$row['byte'];
                            if ($row['last_visit'] > $domain_stats[$domain]['last_visit']) {
                                $domain_stats[$domain]['last_visit'] = $row['last_visit'];
                            }
                        }
                    }
                } else {
                    $sql = "
                        SELECT 
                            domain,
                            COUNT(*) as total,
                            SUM(CASE WHEN spider_name = '百度' OR spider_name = '百度PC' OR spider_name = '百度移动' THEN 1 ELSE 0 END) as baidu,
                            SUM(CASE WHEN spider_name = '谷歌' THEN 1 ELSE 0 END) as google,
                            SUM(CASE WHEN spider_name = '搜狗' THEN 1 ELSE 0 END) as sogou,
                            SUM(CASE WHEN spider_name = '360' THEN 1 ELSE 0 END) as s360,
                            SUM(CASE WHEN spider_name = '神马' THEN 1 ELSE 0 END) as yisou,
                            SUM(CASE WHEN spider_name = '今日头条' THEN 1 ELSE 0 END) as byte,
                            MAX(visit_time) as last_visit
                        FROM spider_visits
                    ";
                    $params = array();
                    
                    if ($allowed_domains !== null) {
                        $placeholders = implode(',', array_fill(0, count($allowed_domains), '?'));
                        $sql .= " WHERE domain IN ($placeholders)";
                        $params = $allowed_domains;
                    }
                    
                    $sql .= ' GROUP BY domain';
                    
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $domain = $row['domain'];
                        if (!isset($domain_stats[$domain])) {
                            $domain_stats[$domain] = array(
                                'domain' => $domain,
                                'total' => 0,
                                'baidu' => 0,
                                'google' => 0,
                                'sogou' => 0,
                                's360' => 0,
                                'yisou' => 0,
                                'byte' => 0,
                                'last_visit' => ''
                            );
                        }
                        $domain_stats[$domain]['total'] += (int)$row['total'];
                        $domain_stats[$domain]['baidu'] += (int)$row['baidu'];
                        $domain_stats[$domain]['google'] += (int)$row['google'];
                        $domain_stats[$domain]['sogou'] += (int)$row['sogou'];
                        $domain_stats[$domain]['s360'] += (int)$row['s360'];
                        $domain_stats[$domain]['yisou'] += (int)$row['yisou'];
                        $domain_stats[$domain]['byte'] += (int)$row['byte'];
                        if ($row['last_visit'] > $domain_stats[$domain]['last_visit']) {
                            $domain_stats[$domain]['last_visit'] = $row['last_visit'];
                        }
                    }
                }
            }
            
            $current = strtotime('+1 day', $current);
        }
        
        // 按总数排序
        usort($domain_stats, function($a, $b) {
            return $b['total'] - $a['total'];
        });
        
        return array_values($domain_stats);
    }
    
    /**
     * 获取百度PC/移动统计
     */
    public function getBaiduPCMobileStats($days = 10, $group_id = null) {
        $pc_total = 0;
        $mobile_total = 0;
        $allowed_domains = $this->getGroupDomains($group_id);
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $db = $this->getDBByDate($date);
            if (!$db) continue;
            
            // 如果域名数量超过900，分批查询
            if ($allowed_domains !== null && count($allowed_domains) > 900) {
                $batch_size = 900;
                $batches = array_chunk($allowed_domains, $batch_size);
                
                foreach ($batches as $batch) {
                    $placeholders = implode(',', array_fill(0, count($batch), '?'));
                    $sql = "
                        SELECT 
                            SUM(CASE WHEN spider_name LIKE '%PC%' THEN 1 ELSE 0 END) as pc,
                            SUM(CASE WHEN spider_name LIKE '%移动%' THEN 1 ELSE 0 END) as mobile
                        FROM spider_visits 
                        WHERE spider_type = 'Baiduspider' AND domain IN ($placeholders)
                    ";
                    
                    $stmt = $db->prepare($sql);
                    $stmt->execute($batch);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($row) {
                        $pc_total += (int)$row['pc'];
                        $mobile_total += (int)$row['mobile'];
                    }
                }
            } else {
                $sql = "
                    SELECT 
                        SUM(CASE WHEN spider_name LIKE '%PC%' THEN 1 ELSE 0 END) as pc,
                        SUM(CASE WHEN spider_name LIKE '%移动%' THEN 1 ELSE 0 END) as mobile
                    FROM spider_visits 
                    WHERE spider_type = 'Baiduspider'
                ";
                $params = array();
                
                if ($allowed_domains !== null) {
                    $placeholders = implode(',', array_fill(0, count($allowed_domains), '?'));
                    $sql .= " AND domain IN ($placeholders)";
                    $params = $allowed_domains;
                }
                
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($row) {
                    $pc_total += (int)$row['pc'];
                    $mobile_total += (int)$row['mobile'];
                }
            }
        }
        
        return array('pc' => $pc_total, 'mobile' => $mobile_total);
    }
    
    /**
     * 获取总记录数（所有日期）
     */
    public function getTotalCount() {
        $total = 0;
        $dates = $this->getAvailableDates();
        
        foreach ($dates as $date) {
            $db = $this->getDBByDate($date);
            if ($db) {
                $stmt = $db->query('SELECT COUNT(*) FROM spider_visits');
                $total += (int)$stmt->fetchColumn();
            }
        }
        
        return $total;
    }
    
    /**
     * 获取分组的域名列表
     */
    private function getGroupDomains($group_id) {
        if (!$group_id) return null;
        
        $groups_file = __DIR__ . '/groups.json';
        if (!file_exists($groups_file)) return null;
        
        $data = json_decode(file_get_contents($groups_file), true);
        if (!$data || !isset($data['groups'])) return null;
        
        foreach ($data['groups'] as $group) {
            if ($group['id'] == $group_id) {
                return $group['domains'];
            }
        }
        
        return null;
    }
    
    /**
     * 删除指定日期的数据
     */
    public function deleteDate($date) {
        $db_path = $this->db_dir . '/' . $date . '.db';
        if (file_exists($db_path)) {
            // 关闭连接
            if (isset($this->connections[$date])) {
                unset($this->connections[$date]);
            }
            // 删除文件
            return unlink($db_path);
        }
        return false;
    }
    
    /**
     * 获取数据库文件大小信息
     */
    public function getStorageInfo() {
        $info = array();
        $dates = $this->getAvailableDates();
        $total_size = 0;
        
        foreach ($dates as $date) {
            $db_path = $this->db_dir . '/' . $date . '.db';
            if (file_exists($db_path)) {
                $size = filesize($db_path);
                $total_size += $size;
                $info[] = array(
                    'date' => $date,
                    'size' => $size,
                    'size_mb' => round($size / 1024 / 1024, 2)
                );
            }
        }
        
        return array(
            'files' => $info,
            'total_size' => $total_size,
            'total_size_mb' => round($total_size / 1024 / 1024, 2)
        );
    }
    
    /**
     * 优化指定日期的数据库
     */
    public function optimize($date = null) {
        if ($date) {
            $db = $this->getDBByDate($date);
            if ($db) {
                $db->exec('VACUUM');
                $db->exec('ANALYZE');
            }
        } else {
            // 优化所有数据库
            foreach ($this->getAvailableDates() as $d) {
                $db = $this->getDBByDate($d);
                if ($db) {
                    $db->exec('VACUUM');
                    $db->exec('ANALYZE');
                }
            }
        }
    }
}

/**
 * 全局函数：获取数据库实例
 */
function getSpiderDB() {
    return SpiderDB::getInstance();
}
