<?php
/**
 * 接收重定向统计 - 主页面
 * 统计养站域名被蜘蛛抓取的情况
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 网站根目录配置（相对于当前脚本的位置）
define('SITE_ROOT', dirname(__DIR__));
define('REDIRECT_STATS_DIR', __DIR__ . '/redirect_stats_data');

// 确保数据目录存在
if (!is_dir(REDIRECT_STATS_DIR)) {
    mkdir(REDIRECT_STATS_DIR, 0755, true);
}

require_once __DIR__ . '/spider_db.php';

/**
 * 网站类型配置
 */
$site_types = array(
    'static_養站' => array(
        'name' => '静态资源养站',
        'csv_path' => '/yzconfig/domain.csv',
        'url_pattern' => '/web_data/{template}/url.txt',
        'description' => '通过静态资源页面养站，统计蜘蛛抓取情况'
    )
    // 后续可以添加更多网站类型
);

/**
 * 解析CSV文件获取域名配置
 */
function parseDomainCSV($csv_path) {
    if (!file_exists($csv_path)) {
        return array('error' => "配置文件不存在: {$csv_path}");
    }
    
    $content = file_get_contents($csv_path);
    if (empty($content)) {
        return array('error' => "配置文件为空: {$csv_path}");
    }
    
    // 处理编码
    $encoding = mb_detect_encoding($content, array('UTF-8', 'GBK', 'GB2312'), true);
    if ($encoding && $encoding !== 'UTF-8') {
        $content = mb_convert_encoding($content, 'UTF-8', $encoding);
    }
    
    $lines = explode("\n", $content);
    $domains = array();
    
    foreach ($lines as $index => $line) {
        $line = trim($line);
        if (empty($line) || $index === 0) continue; // 跳过表头
        
        $parts = str_getcsv($line);
        if (count($parts) >= 3) {
            $domain = trim($parts[0]);
            $path_prefix = trim($parts[1]);
            $template = trim($parts[2]);
            
            if (!empty($domain) && !empty($template)) {
                if (!isset($domains[$domain])) {
                    $domains[$domain] = array();
                }
                $domains[$domain][] = array(
                    'path_prefix' => $path_prefix,
                    'template' => $template
                );
            }
        }
    }
    
    if (empty($domains)) {
        return array('error' => "CSV文件中没有找到有效的域名配置");
    }
    
    return array('domains' => $domains);
}

/**
 * 获取模板的URL列表
 */
function getTemplateUrls($template) {
    $url_file = SITE_ROOT . "/web_data/{$template}/url.txt";
    
    if (!file_exists($url_file)) {
        return array();
    }
    
    $content = file_get_contents($url_file);
    if (empty($content)) {
        return array();
    }
    
    $lines = explode("\n", $content);
    $urls = array();
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (!empty($line)) {
            $urls[] = $line;
        }
    }
    
    return $urls;
}

/**
 * 拼接完整URL
 * @param string $domain 域名
 * @param string $path_prefix 路径前缀，如 "/" 或 "/jiaoyu/"
 * @param string $uri URL路径，如 "/erke/fx/8271.html"
 */
function buildFullUrl($domain, $path_prefix, $uri) {
    // 处理路径前缀末尾的斜杠
    $path_prefix = rtrim($path_prefix, '/');
    
    // URI已经包含开头的斜杠，直接拼接
    // 如果路径前缀是根目录"/"，则变成空字符串后直接拼接URI
    return $domain . $path_prefix . $uri;
}

/**
 * 从URL中提取主域名（去除二级域名前缀如m.、wap.、www.）
 */
function extractMainDomain($url) {
    // 去除协议
    $url = preg_replace('#^https?://#i', '', $url);
    
    // 提取域名部分
    $parts = explode('/', $url, 2);
    $host = $parts[0];
    $path = isset($parts[1]) ? '/' . $parts[1] : '/';
    
    // 去除常见的二级域名前缀
    $prefixes = array('m.', 'wap.', 'www.', 'mobile.', 'touch.', 'h5.');
    foreach ($prefixes as $prefix) {
        if (stripos($host, $prefix) === 0) {
            $host = substr($host, strlen($prefix));
            break;
        }
    }
    
    return array('domain' => $host, 'path' => $path);
}

/**
 * 获取统计数据库连接
 */
function getStatsDB($site_type) {
    $db_path = REDIRECT_STATS_DIR . "/{$site_type}.db";
    
    $pdo = new PDO("sqlite:{$db_path}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 创建表结构
    $pdo->exec("CREATE TABLE IF NOT EXISTS domain_urls (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        domain TEXT NOT NULL,
        path_prefix TEXT NOT NULL,
        template TEXT NOT NULL,
        uri TEXT NOT NULL,
        full_url TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(domain, path_prefix, uri)
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS url_stats (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        domain TEXT NOT NULL,
        path_prefix TEXT NOT NULL,
        uri TEXT NOT NULL,
        visit_date TEXT NOT NULL,
        visit_count INTEGER DEFAULT 0,
        mobile_count INTEGER DEFAULT 0,
        pc_count INTEGER DEFAULT 0,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(domain, path_prefix, uri, visit_date)
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS stats_meta (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        key TEXT UNIQUE NOT NULL,
        value TEXT,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_domain ON domain_urls(domain)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stats_domain ON url_stats(domain)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stats_date ON url_stats(visit_date)");
    
    return $pdo;
}

/**
 * 获取最后更新时间
 */
function getLastUpdateTime($db) {
    $stmt = $db->prepare("SELECT value FROM stats_meta WHERE key = 'last_update'");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['value'] : null;
}

/**
 * 设置最后更新时间
 */
function setLastUpdateTime($db) {
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare("INSERT OR REPLACE INTO stats_meta (key, value, updated_at) VALUES ('last_update', ?, ?)");
    $stmt->execute(array($now, $now));
}

/**
 * 执行数据分析
 */
function analyzeData($site_type, $site_config, $days = 7) {
    $csv_path = SITE_ROOT . $site_config['csv_path'];
    
    // 解析CSV
    $result = parseDomainCSV($csv_path);
    if (isset($result['error'])) {
        return $result;
    }
    
    $domains = $result['domains'];
    $stats_db = getStatsDB($site_type);
    $spider_db = getSpiderDB();
    
    // 清空旧的URL数据
    $stats_db->exec("DELETE FROM domain_urls");
    
    // 收集所有URL
    $all_urls = array();
    $url_count = 0;
    
    foreach ($domains as $domain => $configs) {
        foreach ($configs as $config) {
            $template = $config['template'];
            $path_prefix = $config['path_prefix'];
            
            $template_urls = getTemplateUrls($template);
            
            if (empty($template_urls)) {
                continue;
            }
            
            $stmt = $stats_db->prepare("INSERT OR IGNORE INTO domain_urls (domain, path_prefix, template, uri, full_url) VALUES (?, ?, ?, ?, ?)");
            
            foreach ($template_urls as $uri) {
                $full_url = buildFullUrl($domain, $path_prefix, $uri);
                $stmt->execute(array($domain, $path_prefix, $template, $uri, $full_url));
                
                $all_urls[] = array(
                    'domain' => $domain,
                    'path_prefix' => $path_prefix,
                    'uri' => $uri,
                    'full_url' => $full_url
                );
                $url_count++;
            }
        }
    }
    
    if ($url_count === 0) {
        return array('error' => "没有获取到任何URL数据，请检查web_data目录下的url.txt文件是否存在");
    }
    
    // 清空旧的统计数据
    $stats_db->exec("DELETE FROM url_stats");
    
    // 遍历日期进行统计
    $stats_count = 0;
    
    for ($i = 0; $i < $days; $i++) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $db_file = __DIR__ . "/spider_data/{$date}.db";
        
        if (!file_exists($db_file)) {
            continue;
        }
        
        $day_db = new PDO("sqlite:{$db_file}");
        $day_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // 获取当天所有蜘蛛访问记录
        $stmt = $day_db->query("SELECT url, spider_name FROM spider_visits");
        $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 构建URL索引用于快速匹配
        $url_index = array();
        foreach ($all_urls as $url_info) {
            $key = $url_info['domain'] . $url_info['uri'];
            $url_index[$key] = $url_info;
        }
        
        // 统计匹配
        $day_stats = array();
        
        foreach ($visits as $visit) {
            $visit_url = $visit['url'];
            $spider_name = $visit['spider_name'];
            
            // 提取主域名和路径
            $parsed = extractMainDomain($visit_url);
            $visit_domain = $parsed['domain'];
            $visit_path = $parsed['path'];
            
            // 尝试匹配
            foreach ($all_urls as $url_info) {
                // 检查域名是否匹配（忽略二级域名前缀）
                if (stripos($visit_domain, $url_info['domain']) !== false || 
                    $visit_domain === $url_info['domain']) {
                    
                    // 检查路径是否匹配
                    $expected_path = rtrim($url_info['path_prefix'], '/') . $url_info['uri'];
                    
                    if ($visit_path === $expected_path) {
                        $key = $url_info['domain'] . '|' . $url_info['path_prefix'] . '|' . $url_info['uri'];
                        
                        if (!isset($day_stats[$key])) {
                            $day_stats[$key] = array(
                                'domain' => $url_info['domain'],
                                'path_prefix' => $url_info['path_prefix'],
                                'uri' => $url_info['uri'],
                                'visit_count' => 0,
                                'mobile_count' => 0,
                                'pc_count' => 0
                            );
                        }
                        
                        $day_stats[$key]['visit_count']++;
                        
                        // 区分移动和PC
                        if (stripos($spider_name, 'Mobile') !== false) {
                            $day_stats[$key]['mobile_count']++;
                        } else {
                            $day_stats[$key]['pc_count']++;
                        }
                        
                        break;
                    }
                }
            }
        }
        
        // 保存当天统计
        $insert_stmt = $stats_db->prepare("INSERT OR REPLACE INTO url_stats 
            (domain, path_prefix, uri, visit_date, visit_count, mobile_count, pc_count, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))");
        
        foreach ($day_stats as $stat) {
            $insert_stmt->execute(array(
                $stat['domain'],
                $stat['path_prefix'],
                $stat['uri'],
                $date,
                $stat['visit_count'],
                $stat['mobile_count'],
                $stat['pc_count']
            ));
            $stats_count++;
        }
    }
    
    // 更新最后分析时间
    setLastUpdateTime($stats_db);
    
    return array(
        'success' => true,
        'domain_count' => count($domains),
        'url_count' => $url_count,
        'stats_count' => $stats_count,
        'days' => $days
    );
}

/**
 * 获取域名统计汇总
 */
function getDomainSummary($site_type, $start_date = null, $end_date = null) {
    $stats_db = getStatsDB($site_type);
    
    // 从统计表获取所有域名和路径前缀（不再依赖domain_urls表）
    $domains_stmt = $stats_db->query("SELECT DISTINCT domain, path_prefix FROM url_stats ORDER BY domain, path_prefix");
    $domain_configs = $domains_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($domain_configs)) {
        return array();
    }
    
    // 构建日期条件
    $date_condition = "";
    $params = array();
    if ($start_date && $end_date) {
        $date_condition = " AND visit_date BETWEEN ? AND ?";
        $params = array($start_date, $end_date);
    }
    
    // 按域名分组统计
    $summary = array();
    
    foreach ($domain_configs as $config) {
        $domain = $config['domain'];
        $path_prefix = $config['path_prefix'];
        
        if (!isset($summary[$domain])) {
            $summary[$domain] = array(
                'domain' => $domain,
                'prefixes' => array(),
                'total' => 0,
                'mobile_total' => 0,
                'pc_total' => 0
            );
        }
        
        // 查询该路径前缀的统计
        $sql = "SELECT SUM(visit_count) as total, SUM(mobile_count) as mobile, SUM(pc_count) as pc 
                FROM url_stats WHERE domain = ? AND path_prefix = ?" . $date_condition;
        $stmt = $stats_db->prepare($sql);
        $stmt->execute(array_merge(array($domain, $path_prefix), $params));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $total = (int)($row['total'] ?? 0);
        $mobile = (int)($row['mobile'] ?? 0);
        $pc = (int)($row['pc'] ?? 0);
        
        $summary[$domain]['prefixes'][$path_prefix] = array(
            'total' => $total,
            'mobile' => $mobile,
            'pc' => $pc
        );
        
        $summary[$domain]['total'] += $total;
        $summary[$domain]['mobile_total'] += $mobile;
        $summary[$domain]['pc_total'] += $pc;
    }
    
    return $summary;
}

/**
 * 获取详细URL列表
 */
function getUrlDetails($site_type, $domain, $path_prefix, $start_date = null, $end_date = null) {
    $stats_db = getStatsDB($site_type);
    
    $where_parts = array("domain = ?", "path_prefix = ?");
    $params = array($domain, $path_prefix);
    
    if ($start_date && $end_date) {
        $where_parts[] = "visit_date BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
    }
    
    $where_sql = implode(' AND ', $where_parts);
    
    // 直接从url_stats表查询，不再依赖domain_urls表
    $sql = "SELECT uri, 
                   (domain || path_prefix || uri) as full_url,
                   SUM(visit_count) as total,
                   SUM(mobile_count) as mobile,
                   SUM(pc_count) as pc
            FROM url_stats
            WHERE {$where_sql}
            GROUP BY uri
            ORDER BY total DESC
            LIMIT 500";
    
    $stmt = $stats_db->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 获取趋势数据
 */
function getTrendData($site_type, $domain = null, $path_prefix = null, $days = 7) {
    $stats_db = getStatsDB($site_type);
    
    $params = array();
    $where = "";
    
    if ($domain) {
        $where .= " AND domain = ?";
        $params[] = $domain;
    }
    if ($path_prefix) {
        $where .= " AND path_prefix = ?";
        $params[] = $path_prefix;
    }
    
    $sql = "SELECT visit_date, SUM(visit_count) as total, SUM(mobile_count) as mobile, SUM(pc_count) as pc
            FROM url_stats
            WHERE visit_date >= date('now', '-{$days} days') {$where}
            GROUP BY visit_date
            ORDER BY visit_date";
    
    $stmt = $stats_db->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 流式输出进度信息
 */
function streamOutput($message, $type = 'info') {
    echo "data: " . json_encode(array('type' => $type, 'message' => $message), JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush();
    @flush();
    
    // 发送心跳保持连接
    static $last_heartbeat = 0;
    $now = time();
    if ($now - $last_heartbeat > 5) {
        echo ": heartbeat\n\n";
        @flush();
        $last_heartbeat = $now;
    }
}

/**
 * 执行数据分析（流式版本 - 优化性能）
 */
function analyzeDataStream($site_type, $site_config, $days = 7) {
    $csv_path = SITE_ROOT . $site_config['csv_path'];
    
    streamOutput("📂 正在读取配置文件...", 'progress');
    
    // 解析CSV
    $result = parseDomainCSV($csv_path);
    if (isset($result['error'])) {
        streamOutput("❌ " . $result['error'], 'error');
        return $result;
    }
    
    $domains = $result['domains'];
    $domain_count = count($domains);
    streamOutput("✅ 找到 {$domain_count} 个域名配置", 'success');
    
    $stats_db = getStatsDB($site_type);
    
    // 清空旧的URL数据
    $stats_db->exec("DELETE FROM domain_urls");
    
    // 收集所有URL - 批量处理
    $all_urls = array();
    $url_count = 0;
    $template_count = 0;
    $template_details = array();
    $missing_templates = array();
    
    // ========== 第一步：收集所有唯一模板 ==========
    $unique_templates = array();
    foreach ($domains as $domain => $configs) {
        foreach ($configs as $config) {
            $unique_templates[$config['template']] = true;
        }
    }
    $unique_template_list = array_keys($unique_templates);
    $total_templates = count($unique_template_list);
    
    streamOutput("📦 发现 {$total_templates} 个唯一模板，开始批量获取URL...", 'progress');
    
    // ========== 第二步：一次性读取所有模板URL ==========
    $template_urls_map = array();
    $loaded_count = 0;
    $total_template_urls = 0;
    
    foreach ($unique_template_list as $template) {
        $urls = getTemplateUrls($template);
        $loaded_count++;
        
        if (empty($urls)) {
            $missing_templates[] = $template;
        } else {
            $template_urls_map[$template] = $urls;
            $urls_count = count($urls);
            $total_template_urls += $urls_count;
            $template_details[] = "{$template} ({$urls_count} 条URL)";
        }
        
        // 每10个模板输出一次进度
        if ($loaded_count % 10 == 0 || $loaded_count == $total_templates) {
            streamOutput("   📄 已加载 {$loaded_count}/{$total_templates} 个模板", 'info');
        }
    }
    
    streamOutput("✅ 模板加载完成：{$loaded_count} 个模板，共 {$total_template_urls} 条唯一URL", 'success');
    
    if (!empty($missing_templates)) {
        $missing_count = count($missing_templates);
        $missing_preview = implode(', ', array_slice($missing_templates, 0, 3));
        streamOutput("⚠️ {$missing_count} 个模板无数据: {$missing_preview}" . ($missing_count > 3 ? '...' : ''), 'warning');
    }
    
    // ========== 第三步：构建关系映射表（内存高效）==========
    streamOutput("🔗 正在构建关系映射表...", 'progress');
    
    // 关系表1：域名 -> [(路径前缀, 模板)]
    $domain_mapping = array();
    $url_count = 0;
    
    foreach ($domains as $domain => $configs) {
        $domain_mapping[$domain] = array();
        foreach ($configs as $config) {
            $template = $config['template'];
            $path_prefix = $config['path_prefix'];
            
            if (!isset($template_urls_map[$template])) {
                continue;
            }
            
            $domain_mapping[$domain][] = array(
                'prefix' => $path_prefix,
                'template' => $template
            );
            $url_count += count($template_urls_map[$template]);
            $template_count++;
        }
    }
    
    // 关系表2：模板 -> URL集合（使用哈希set快速查找）
    $template_url_sets = array();
    foreach ($template_urls_map as $template => $urls) {
        $template_url_sets[$template] = array_flip($urls); // 用array_flip创建哈希set
    }
    
    // 释放原始数组
    unset($template_urls_map);
    
    streamOutput("✅ 关系映射完成：{$domain_count} 个域名，{$template_count} 个配置，约 {$url_count} 条URL", 'success');
    
    if ($url_count === 0) {
        streamOutput("❌ 没有获取到任何URL数据", 'error');
        return array('error' => "没有获取到任何URL数据，请检查web_data目录下的url.txt文件是否存在");
    }
    
    // 清空旧的统计数据
    $stats_db->exec("DELETE FROM url_stats");
    
    streamOutput("🔍 开始比对蜘蛛数据（最近 {$days} 天）...", 'progress');
    
    // 遍历日期进行统计
    $stats_count = 0;
    $total_visits = 0;
    $processed_days = 0;
    $skipped_days = 0;
    
    for ($i = 0; $i < $days; $i++) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $db_file = __DIR__ . "/spider_data/{$date}.db";
        
        if (!file_exists($db_file)) {
            $skipped_days++;
            continue;
        }
        
        $processed_days++;
        
        $day_db = new PDO("sqlite:{$db_file}");
        $day_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // 只获取百度蜘蛛的访问记录（百度PC 和 百度移动）
        $stmt = $day_db->query("SELECT url, spider_name FROM spider_visits WHERE spider_name IN ('百度PC', '百度移动')");
        $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $visit_count = count($visits);
        
        // 统计匹配 - 使用关系映射表
        $day_stats = array();
        $day_match = 0;
        
        foreach ($visits as $visit) {
            $visit_url = $visit['url'];
            $spider_name = $visit['spider_name'];
            
            // 提取主域名和路径
            $parsed = extractMainDomain($visit_url);
            $visit_domain = $parsed['domain'];
            $visit_path = $parsed['path'];
            
            // 检查域名是否在映射表中
            if (!isset($domain_mapping[$visit_domain])) {
                continue;
            }
            
            // 遍历该域名的所有配置
            foreach ($domain_mapping[$visit_domain] as $config) {
                $prefix = $config['prefix'];
                $template = $config['template'];
                
                // 检查路径是否以该前缀开头
                $prefix_clean = rtrim($prefix, '/');
                if ($prefix_clean !== '' && strpos($visit_path, $prefix_clean) !== 0) {
                    continue;
                }
                
                // 提取相对URI（去掉前缀部分）
                if ($prefix_clean === '') {
                    $relative_uri = $visit_path;
                } else {
                    $relative_uri = substr($visit_path, strlen($prefix_clean));
                }
                
                // 检查URI是否在模板URL集合中
                if (isset($template_url_sets[$template][$relative_uri])) {
                    $stat_key = $visit_domain . '|' . $prefix . '|' . $relative_uri;
                    
                    if (!isset($day_stats[$stat_key])) {
                        $day_stats[$stat_key] = array(
                            'domain' => $visit_domain,
                            'path_prefix' => $prefix,
                            'uri' => $relative_uri,
                            'visit_count' => 0,
                            'mobile_count' => 0,
                            'pc_count' => 0
                        );
                    }
                    
                    $day_stats[$stat_key]['visit_count']++;
                    $day_match++;
                    
                    // 区分移动和PC（使用中文字段名）
                    if ($spider_name === '百度移动') {
                        $day_stats[$stat_key]['mobile_count']++;
                    } else {
                        $day_stats[$stat_key]['pc_count']++;
                    }
                    
                    break; // 匹配到一个就跳出
                }
            }
        }
        
        // 批量保存当天统计
        $stats_db->beginTransaction();
        $insert_stmt = $stats_db->prepare("INSERT OR REPLACE INTO url_stats 
            (domain, path_prefix, uri, visit_date, visit_count, mobile_count, pc_count, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))");
        
        foreach ($day_stats as $stat) {
            $insert_stmt->execute(array(
                $stat['domain'],
                $stat['path_prefix'],
                $stat['uri'],
                $date,
                $stat['visit_count'],
                $stat['mobile_count'],
                $stat['pc_count']
            ));
            $stats_count++;
        }
        $stats_db->commit();
        
        $total_visits += $day_match;
        
        // 输出进度
        streamOutput("📅 {$date}: {$visit_count} 条记录 → 命中 {$day_match} 条", 'info');
    }
    
    if ($skipped_days > 0) {
        streamOutput("⏭️ 跳过 {$skipped_days} 天（无数据）", 'warning');
    }
    
    // 更新最后分析时间
    setLastUpdateTime($stats_db);
    
    streamOutput("🎉 分析完成！共 {$domain_count} 个域名，{$url_count} 条URL，命中 {$total_visits} 次", 'complete');
    
    return array(
        'success' => true,
        'domain_count' => $domain_count,
        'url_count' => $url_count,
        'stats_count' => $stats_count,
        'total_visits' => $total_visits,
        'days' => $days
    );
}

// ============== AJAX处理 ==============
$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action) {
    try {
        switch ($action) {
            case 'analyze':
                // 设置超时时间
                set_time_limit(600); // 10分钟
                ini_set('max_execution_time', 600);
                
                // 使用SSE流式输出
                header('Content-Type: text/event-stream');
                header('Cache-Control: no-cache');
                header('Connection: keep-alive');
                header('X-Accel-Buffering: no');
                
                // 关闭输出缓冲
                while (ob_get_level()) ob_end_clean();
                ob_implicit_flush(true);
                
                // 发送初始连接确认
                echo ": connected\n\n";
                flush();
                
                $type = isset($_GET['type']) ? $_GET['type'] : '';
                $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
                
                if (!isset($site_types[$type])) {
                    streamOutput('未知的网站类型', 'error');
                    exit;
                }
                
                $result = analyzeDataStream($type, $site_types[$type], $days);
                
                // 发送最终结果
                echo "data: " . json_encode(array('type' => 'done', 'result' => $result), JSON_UNESCAPED_UNICODE) . "\n\n";
                @ob_flush();
                @flush();
                exit;
                break;
                
            case 'summary':
                $type = isset($_GET['type']) ? $_GET['type'] : '';
                $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
                $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
                
                $summary = getDomainSummary($type, $start_date, $end_date);
                $last_update = null;
                
                if (!empty($type)) {
                    $db = getStatsDB($type);
                    $last_update = getLastUpdateTime($db);
                }
                
                echo json_encode(array(
                    'summary' => $summary,
                    'last_update' => $last_update
                ));
                break;
                
            case 'details':
                $type = isset($_GET['type']) ? $_GET['type'] : '';
                $domain = isset($_GET['domain']) ? $_GET['domain'] : '';
                $path_prefix = isset($_GET['path_prefix']) ? $_GET['path_prefix'] : '';
                $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
                $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
                
                $details = getUrlDetails($type, $domain, $path_prefix, $start_date, $end_date);
                echo json_encode(array('details' => $details));
                break;
                
            case 'export':
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="redirect_stats_' . date('Y-m-d') . '.csv"');
                
                $type = isset($_GET['type']) ? $_GET['type'] : '';
                $domain = isset($_GET['domain']) ? $_GET['domain'] : null;
                $path_prefix = isset($_GET['path_prefix']) ? $_GET['path_prefix'] : null;
                
                // 输出BOM头，确保Excel正确显示中文
                echo "\xEF\xBB\xBF";
                
                if ($domain && $path_prefix) {
                    // 导出单个目录的详细URL
                    echo "域名,目录,URL路径,完整URL,总计,移动,PC\n";
                    $details = getUrlDetails($type, $domain, $path_prefix);
                    foreach ($details as $row) {
                        $full_url = 'http://' . $domain . rtrim($path_prefix, '/') . $row['uri'];
                        echo '"' . $domain . '","' . $path_prefix . '","' . $row['uri'] . '","' . $full_url . '",' . $row['total'] . ',' . $row['mobile'] . ',' . $row['pc'] . "\n";
                    }
                } else {
                    // 导出全部汇总数据
                    echo "域名,目录,完整URL示例,命中次数,移动,PC\n";
                    $summary = getDomainSummary($type);
                    foreach ($summary as $domain => $data) {
                        foreach ($data['prefixes'] as $prefix => $stats) {
                            if ($stats['total'] > 0) {
                                $url_example = 'http://' . $domain . rtrim($prefix, '/') . '/';
                                echo '"' . $domain . '","' . $prefix . '","' . $url_example . '",' . $stats['total'] . ',' . $stats['mobile'] . ',' . $stats['pc'] . "\n";
                            }
                        }
                    }
                }
                exit;
                
            case 'trend':
                $type = isset($_GET['type']) ? $_GET['type'] : '';
                $domain = isset($_GET['domain']) ? $_GET['domain'] : null;
                $path_prefix = isset($_GET['path_prefix']) ? $_GET['path_prefix'] : null;
                $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
                
                $trend = getTrendData($type, $domain, $path_prefix, $days);
                echo json_encode(array('trend' => $trend));
                break;
                
            default:
                echo json_encode(array('error' => '未知操作'));
        }
    } catch (Exception $e) {
        echo json_encode(array('error' => $e->getMessage()));
    }
    exit;
}

// ============== HTML页面 ==============
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>接收重定向统计</title>
    <script src="./static/js/jquery.js"></script>
    <script src="./static/js/highcharts.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        .header {
            background: rgba(255,255,255,0.95);
            border-radius: 16px;
            padding: 24px 32px;
            margin-bottom: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        .header h1 {
            font-size: 24px;
            color: #1a1a2e;
            margin-bottom: 8px;
        }
        .header .subtitle {
            color: #666;
            font-size: 14px;
        }
        .nav-back {
            display: inline-block;
            margin-bottom: 16px;
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
        }
        .nav-back:hover { text-decoration: underline; }
        
        .card {
            background: rgba(255,255,255,0.95);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .type-selector {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }
        .type-card {
            flex: 1;
            min-width: 280px;
            padding: 20px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .type-card:hover {
            border-color: #667eea;
            background: #f8f9ff;
        }
        .type-card.active {
            border-color: #667eea;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .type-card.active .type-name { color: white; }
        .type-card.active .type-desc { color: rgba(255,255,255,0.8); }
        .type-name {
            font-size: 16px;
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 8px;
        }
        .type-desc {
            font-size: 13px;
            color: #666;
        }
        
        .controls {
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        .control-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .control-group label {
            font-size: 14px;
            color: #666;
        }
        .control-group select, .control-group input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-secondary {
            background: #f0f0f0;
            color: #333;
        }
        .btn-secondary:hover {
            background: #e0e0e0;
        }
        
        .stats-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }
        .stats-table th, .stats-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .stats-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
            font-size: 13px;
        }
        .stats-table tr:hover {
            background: #f8f9ff;
        }
        .stats-table .domain-cell {
            font-weight: 600;
            color: #1a1a2e;
        }
        .stats-table .prefix-cell {
            cursor: pointer;
            color: #667eea;
        }
        .stats-table .prefix-cell:hover {
            text-decoration: underline;
        }
        .stats-table .number {
            text-align: right;
            font-family: "SF Mono", Monaco, monospace;
        }
        .stats-table .total-row {
            background: #f0f8ff;
            font-weight: 600;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        .badge-mobile {
            background: #e3f2fd;
            color: #1976d2;
        }
        .badge-pc {
            background: #fce4ec;
            color: #c2185b;
        }
        
        .prefix-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .prefix-tag {
            display: inline-block;
            padding: 4px 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 500;
        }
        .prefix-tag:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.4);
        }
        
        .url-cell {
            word-break: break-all;
            max-width: 600px;
        }
        .url-cell a {
            color: #667eea;
            text-decoration: none;
            font-family: "SF Mono", Monaco, monospace;
            font-size: 12px;
        }
        .url-cell a:hover {
            text-decoration: underline;
            color: #764ba2;
        }
        .copy-toast {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: #333;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            z-index: 9999;
            animation: fadeInOut 1.5s ease;
        }
        @keyframes fadeInOut {
            0% { opacity: 0; transform: translateX(-50%) translateY(-10px); }
            20% { opacity: 1; transform: translateX(-50%) translateY(0); }
            80% { opacity: 1; }
            100% { opacity: 0; }
        }
        
        .info-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            background: #fff8e1;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 13px;
            color: #f57c00;
        }
        .info-bar.success {
            background: #e8f5e9;
            color: #2e7d32;
        }
        .info-bar.error {
            background: #ffebee;
            color: #c62828;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        .loading::after {
            content: '';
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #667eea;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 1s linear infinite;
            margin-left: 10px;
            vertical-align: middle;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .detail-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            overflow-y: auto;
            padding: 40px 20px;
        }
        .modal-content {
            background: white;
            border-radius: 16px;
            max-width: 1000px;
            margin: 0 auto;
            max-height: calc(100vh - 80px);
            overflow-y: auto;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid #eee;
            position: sticky;
            top: 0;
            background: white;
            z-index: 10;
        }
        .modal-header h3 {
            font-size: 18px;
            color: #1a1a2e;
        }
        .modal-close {
            width: 32px;
            height: 32px;
            border: none;
            background: #f0f0f0;
            border-radius: 50%;
            cursor: pointer;
            font-size: 18px;
            color: #666;
        }
        .modal-close:hover {
            background: #e0e0e0;
        }
        .modal-body {
            padding: 24px;
        }
        
        #chart-container {
            height: 300px;
            margin-top: 20px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        .empty-state .icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
        
        /* 分析日志样式 */
        .analysis-log {
            background: #1a1a2e;
            border-radius: 12px;
            overflow: hidden;
            font-family: "SF Mono", Monaco, "Cascadia Code", monospace;
        }
        .log-header {
            background: #16213e;
            color: #fff;
            padding: 12px 16px;
            font-size: 14px;
            font-weight: 600;
            border-bottom: 1px solid #0f3460;
        }
        .log-content {
            padding: 16px;
            min-height: 500px;
            max-height: 800px;
            overflow-y: auto;
            font-size: 13px;
            line-height: 1.8;
        }
        .log-item {
            color: #a0a0a0;
            padding: 2px 0;
        }
        .log-item.log-progress {
            color: #64b5f6;
        }
        .log-item.log-success {
            color: #81c784;
        }
        .log-item.log-error {
            color: #e57373;
        }
        .log-item.log-warning {
            color: #ffb74d;
        }
        .log-item.log-complete {
            color: #4fc3f7;
            font-weight: 600;
            font-size: 14px;
            padding: 8px 0;
            border-top: 1px solid #0f3460;
            margin-top: 8px;
        }
        .log-content::-webkit-scrollbar {
            width: 8px;
        }
        .log-content::-webkit-scrollbar-track {
            background: #16213e;
        }
        .log-content::-webkit-scrollbar-thumb {
            background: #0f3460;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="tongji.php" class="nav-back">← 返回蜘蛛统计</a>
            <h1>🔄 接收重定向统计</h1>
            <p class="subtitle">统计养站域名被蜘蛛抓取的情况，分析重定向效果</p>
        </div>
        
        <div class="card">
            <div class="card-title">选择网站类型</div>
            <div class="type-selector">
                <?php foreach ($site_types as $key => $config): ?>
                <div class="type-card" data-type="<?php echo $key; ?>">
                    <div class="type-name">📦 <?php echo htmlspecialchars($config['name']); ?></div>
                    <div class="type-desc"><?php echo htmlspecialchars($config['description']); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="controls" id="controls" style="display: none;">
                <div class="control-group">
                    <label>统计天数：</label>
                    <select id="days-select">
                        <option value="3">最近3天</option>
                        <option value="7" selected>最近7天</option>
                        <option value="14">最近14天</option>
                        <option value="30">最近30天</option>
                    </select>
                </div>
                <button class="btn btn-primary" id="analyze-btn">🔍 开始分析</button>
                <button class="btn btn-secondary" id="refresh-btn" style="display: none;">🔄 刷新数据</button>
            </div>
        </div>
        
        <div class="card" id="result-card" style="display: none;">
            <div class="card-title" style="display: flex; justify-content: space-between; align-items: center;">
                <span>📊 统计结果</span>
                <button class="btn btn-secondary" id="export-all-btn" onclick="exportAllData()" style="font-size: 12px; padding: 6px 12px;">
                    📥 导出全部数据
                </button>
            </div>
            <div id="info-bar"></div>
            <div id="chart-container"></div>
            <div id="result-content"></div>
        </div>
    </div>
    
    <div class="detail-modal" id="detail-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modal-title">URL详情</h3>
                <button class="modal-close" onclick="closeModal()">×</button>
            </div>
            <div class="modal-body" id="modal-body"></div>
        </div>
    </div>

<script>
var currentType = '';
var lastUpdate = null;

$(function() {
    // 选择网站类型
    $('.type-card').click(function() {
        $('.type-card').removeClass('active');
        $(this).addClass('active');
        currentType = $(this).data('type');
        $('#controls').show();
        
        // 检查是否有数据
        loadSummary();
    });
    
    // 开始分析
    $('#analyze-btn').click(function() {
        runAnalysis();
    });
    
    // 刷新数据
    $('#refresh-btn').click(function() {
        runAnalysis();
    });
});

function runAnalysis() {
    if (!currentType) {
        alert('请先选择网站类型');
        return;
    }
    
    var days = $('#days-select').val();
    
    $('#result-card').show();
    $('#info-bar').html('');
    $('#chart-container').html('');
    $('#analyze-btn').prop('disabled', true).text('⏳ 分析中...');
    
    // 创建进度日志容器
    var logHtml = '<div class="analysis-log" id="analysis-log">';
    logHtml += '<div class="log-header">📊 分析进度</div>';
    logHtml += '<div class="log-content" id="log-content"></div>';
    logHtml += '</div>';
    $('#result-content').html(logHtml);
    
    // 使用SSE接收流式数据
    var eventSource = new EventSource('redirect_stats.php?action=analyze&type=' + currentType + '&days=' + days);
    
    eventSource.onmessage = function(event) {
        var data = JSON.parse(event.data);
        var logContent = $('#log-content');
        
        if (data.type === 'done') {
            // 分析完成
            eventSource.close();
            $('#analyze-btn').prop('disabled', false).text('🔍 开始分析');
            
            if (data.result && data.result.error) {
                appendLog('❌ ' + data.result.error, 'error');
            } else {
                setTimeout(function() {
                    loadSummary();
                }, 500);
            }
        } else {
            // 追加日志
            appendLog(data.message, data.type);
        }
    };
    
    eventSource.onerror = function() {
        eventSource.close();
        $('#analyze-btn').prop('disabled', false).text('🔍 开始分析');
        appendLog('❌ 连接中断，请重试', 'error');
    };
}

function appendLog(message, type) {
    var logContent = $('#log-content');
    var className = 'log-item';
    
    if (type === 'error') className += ' log-error';
    else if (type === 'success') className += ' log-success';
    else if (type === 'warning') className += ' log-warning';
    else if (type === 'complete') className += ' log-complete';
    else if (type === 'progress') className += ' log-progress';
    
    logContent.append('<div class="' + className + '">' + message + '</div>');
    
    // 自动滚动到底部
    logContent.scrollTop(logContent[0].scrollHeight);
}

function loadSummary() {
    var days = $('#days-select').val();
    var endDate = new Date().toISOString().split('T')[0];
    var startDate = new Date(Date.now() - days * 86400000).toISOString().split('T')[0];
    
    $.ajax({
        url: 'redirect_stats.php?action=summary&type=' + currentType + '&start_date=' + startDate + '&end_date=' + endDate,
        dataType: 'json',
        success: function(res) {
            if (res.last_update) {
                lastUpdate = res.last_update;
                $('#refresh-btn').show();
                $('#info-bar').html('<div class="info-bar">📅 数据更新时间：' + res.last_update + '（如需最新数据请点击刷新）</div>');
            }
            
            if ($.isEmptyObject(res.summary)) {
                $('#result-card').show();
                $('#result-content').html('<div class="empty-state"><div class="icon">📭</div><div>暂无数据，请点击"开始分析"获取数据</div></div>');
                return;
            }
            
            $('#result-card').show();
            renderSummaryTable(res.summary);
            loadTrendChart();
        }
    });
}

function renderSummaryTable(summary) {
    var html = '<table class="stats-table">';
    html += '<thead><tr><th>域名</th><th>目录命中</th><th class="number">总计</th><th class="number">移动</th><th class="number">PC</th></tr></thead>';
    html += '<tbody>';
    
    var grandTotal = 0, grandMobile = 0, grandPC = 0;
    
    // 转换为数组并按总计降序排序
    var domainList = [];
    $.each(summary, function(domain, data) {
        if (data.total > 0) { // 只显示有数据的域名
            domainList.push(data);
        }
    });
    domainList.sort(function(a, b) {
        return b.total - a.total;
    });
    
    $.each(domainList, function(i, data) {
        var domain = data.domain;
        
        // 构建目录命中标签
        var prefixTags = [];
        $.each(data.prefixes, function(prefix, stats) {
            if (stats.total > 0) {
                var displayName = prefix === '/' ? '根' : prefix.replace(/\//g, '');
                prefixTags.push({
                    prefix: prefix,
                    name: displayName,
                    total: stats.total
                });
            }
        });
        
        // 按命中数降序排序
        prefixTags.sort(function(a, b) {
            return b.total - a.total;
        });
        
        // 生成标签HTML
        var tagsHtml = '';
        $.each(prefixTags, function(j, tag) {
            tagsHtml += '<span class="prefix-tag" onclick="showDetails(\'' + domain + '\', \'' + tag.prefix + '\')">' + tag.name + '/' + tag.total + '</span>';
        });
        
        html += '<tr>';
        html += '<td class="domain-cell">' + domain + '</td>';
        html += '<td class="prefix-tags">' + tagsHtml + '</td>';
        html += '<td class="number"><strong>' + data.total + '</strong></td>';
        html += '<td class="number"><span class="badge badge-mobile">' + data.mobile_total + '</span></td>';
        html += '<td class="number"><span class="badge badge-pc">' + data.pc_total + '</span></td>';
        html += '</tr>';
        
        grandTotal += data.total;
        grandMobile += data.mobile_total;
        grandPC += data.pc_total;
    });
    
    // 汇总行
    html += '<tr class="total-row">';
    html += '<td>合计 (' + domainList.length + ' 个域名)</td>';
    html += '<td></td>';
    html += '<td class="number"><strong>' + grandTotal + '</strong></td>';
    html += '<td class="number"><span class="badge badge-mobile">' + grandMobile + '</span></td>';
    html += '<td class="number"><span class="badge badge-pc">' + grandPC + '</span></td>';
    html += '</tr>';
    
    html += '</tbody></table>';
    
    $('#result-content').html(html);
}

function loadTrendChart() {
    var days = $('#days-select').val();
    
    $.ajax({
        url: 'redirect_stats.php?action=trend&type=' + currentType + '&days=' + days,
        dataType: 'json',
        success: function(res) {
            if (!res.trend || res.trend.length === 0) {
                $('#chart-container').html('');
                return;
            }
            
            var dates = [];
            var totals = [];
            var mobiles = [];
            var pcs = [];
            
            $.each(res.trend, function(i, item) {
                dates.push(item.visit_date);
                totals.push(parseInt(item.total) || 0);
                mobiles.push(parseInt(item.mobile) || 0);
                pcs.push(parseInt(item.pc) || 0);
            });
            
            Highcharts.chart('chart-container', {
                chart: { type: 'line' },
                title: { text: '蜘蛛抓取趋势' },
                credits: { enabled: false },
                xAxis: { categories: dates },
                yAxis: { title: { text: '抓取次数' } },
                series: [
                    { name: '总计', data: totals, color: '#667eea' },
                    { name: '移动', data: mobiles, color: '#1976d2' },
                    { name: 'PC', data: pcs, color: '#c2185b' }
                ]
            });
        }
    });
}

function showDetails(domain, pathPrefix) {
    var days = $('#days-select').val();
    var endDate = new Date().toISOString().split('T')[0];
    var startDate = new Date(Date.now() - days * 86400000).toISOString().split('T')[0];
    
    var displayPath = pathPrefix === '/' ? '根目录' : pathPrefix;
    $('#modal-title').html(domain + ' <span style="color:#667eea;">' + displayPath + '</span> URL详情 ' +
        '<button class="btn btn-secondary" onclick="copyAllUrls()" style="font-size: 11px; padding: 4px 10px; margin-left: 15px;">📋 复制全部</button>' +
        '<button class="btn btn-secondary" onclick="exportDetails(\'' + domain + '\', \'' + pathPrefix + '\')" style="font-size: 11px; padding: 4px 10px; margin-left: 8px;">📥 导出</button>');
    $('#modal-body').html('<div class="loading">加载中...</div>');
    $('#detail-modal').show();
    
    // 存储当前查看的域名和目录，供导出使用
    window.currentDetailDomain = domain;
    window.currentDetailPrefix = pathPrefix;
    
    $.ajax({
        url: 'redirect_stats.php?action=details&type=' + currentType + '&domain=' + encodeURIComponent(domain) + '&path_prefix=' + encodeURIComponent(pathPrefix) + '&start_date=' + startDate + '&end_date=' + endDate,
        dataType: 'json',
        success: function(res) {
            if (!res.details || res.details.length === 0) {
                $('#modal-body').html('<div class="empty-state">暂无数据</div>');
                return;
            }
            
            // 构建完整URL
            var prefixClean = pathPrefix === '/' ? '' : pathPrefix.replace(/\/$/, '');
            
            // 存储所有URL供复制使用
            window.currentDetailUrls = [];
            
            var html = '<table class="stats-table">';
            html += '<thead><tr><th>完整URL</th><th class="number">总计</th><th class="number">移动</th><th class="number">PC</th></tr></thead>';
            html += '<tbody>';
            
            $.each(res.details, function(i, item) {
                var fullUrl = 'http://' + domain + prefixClean + item.uri;
                window.currentDetailUrls.push(fullUrl);
                html += '<tr>';
                html += '<td class="url-cell">';
                html += '<a href="' + fullUrl + '" target="_blank" title="点击访问">' + fullUrl + '</a>';
                html += '</td>';
                html += '<td class="number">' + item.total + '</td>';
                html += '<td class="number"><span class="badge badge-mobile">' + item.mobile + '</span></td>';
                html += '<td class="number"><span class="badge badge-pc">' + item.pc + '</span></td>';
                html += '</tr>';
            });
            
            html += '</tbody></table>';
            $('#modal-body').html(html);
        },
        error: function() {
            $('#modal-body').html('<div class="empty-state">加载失败</div>');
        }
    });
}

function closeModal() {
    $('#detail-modal').hide();
}

function exportAllData() {
    if (!currentType) {
        alert('请先选择网站类型');
        return;
    }
    window.location.href = 'redirect_stats.php?action=export&type=' + encodeURIComponent(currentType);
}

function exportDetails(domain, pathPrefix) {
    if (!currentType) {
        alert('请先选择网站类型');
        return;
    }
    window.location.href = 'redirect_stats.php?action=export&type=' + encodeURIComponent(currentType) + 
        '&domain=' + encodeURIComponent(domain) + 
        '&path_prefix=' + encodeURIComponent(pathPrefix);
}

function copyAllUrls() {
    if (!window.currentDetailUrls || window.currentDetailUrls.length === 0) {
        showCopyToast('❌ 没有URL可复制');
        return;
    }
    
    var allUrls = window.currentDetailUrls.join('\n');
    
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(allUrls).then(function() {
            showCopyToast('✅ 已复制 ' + window.currentDetailUrls.length + ' 条URL');
        }).catch(function() {
            fallbackCopy(allUrls);
        });
    } else {
        fallbackCopy(allUrls);
    }
}

function fallbackCopy(text) {
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    try {
        document.execCommand('copy');
        showCopyToast('✅ 已复制');
    } catch (e) {
        showCopyToast('❌ 复制失败');
    }
    document.body.removeChild(textarea);
}

function showCopyToast(message) {
    var toast = $('<div class="copy-toast">' + message + '</div>');
    $('body').append(toast);
    setTimeout(function() {
        toast.remove();
    }, 1500);
}

$(document).keyup(function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>
</body>
</html>

