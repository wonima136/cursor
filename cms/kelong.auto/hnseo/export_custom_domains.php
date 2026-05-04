<?php
/**
 * 指定域名导出URL数据
 * 支持用户输入多个域名，导出这些域名被蜘蛛抓取的URL记录
 * 支持导出：全部链接、移动端链接、PC端链接
 * 支持格式：TXT（仅URL）、CSV（含抓取次数统计）
 * 支持日期范围：当天、多天、自定义范围
 */
ini_set('session.save_handler', 'files');
ini_set('session.save_path', '/tmp');
session_name('hnseo_tongji');
session_start();

// 验证登录.
if (!isset($_SESSION['tongji_logged_in']) || $_SESSION['tongji_logged_in'] !== true) {
    header('HTTP/1.0 403 Forbidden');
    exit('请先登录');
}

require_once __DIR__ . '/spider_db.php';

// 获取参数（支持GET和POST）
$domains_input = isset($_POST['domains']) ? $_POST['domains'] : (isset($_GET['domains']) ? $_GET['domains'] : '');
$type = isset($_POST['type']) ? $_POST['type'] : (isset($_GET['type']) ? $_GET['type'] : 'all');
$format = isset($_POST['format']) ? $_POST['format'] : (isset($_GET['format']) ? $_GET['format'] : 'txt');
$range = isset($_POST['range']) ? $_POST['range'] : (isset($_GET['range']) ? $_GET['range'] : 'days_1');

// 链接过滤条件
$filter_inner = isset($_POST['filter_inner']) ? $_POST['filter_inner'] : (isset($_GET['filter_inner']) ? $_GET['filter_inner'] : '0');
$filter_subdomain = isset($_POST['filter_subdomain']) ? $_POST['filter_subdomain'] : (isset($_GET['filter_subdomain']) ? $_GET['filter_subdomain'] : '0');
$filter_inner = ($filter_inner === '1');
$filter_subdomain = ($filter_subdomain === '1');

// 解析域名列表
$domains_raw = preg_split('/[\r\n]+/', trim($domains_input));
$domains = array();
foreach ($domains_raw as $d) {
    $d = strtolower(trim($d));
    if (!empty($d)) {
        $domains[] = $d;
    }
}

if (empty($domains)) {
    die('请输入要导出的域名');
}

// 解析日期范围
$dates = array();
$date_display = '';

if (strpos($range, 'days_') === 0) {
    $days = intval(substr($range, 5));
    if ($days < 1) $days = 1;
    
    for ($i = $days - 1; $i >= 0; $i--) {
        $dates[] = date('Y-m-d', strtotime("-$i days"));
    }
    
    if ($days == 1) {
        $date_display = date('Y-m-d');
    } else {
        $date_display = $dates[0] . ' 至 ' . $dates[count($dates) - 1] . '（共' . $days . '天）';
    }
} else {
    $parts = explode('-', $range);
    if (count($parts) == 2 && strlen($parts[0]) == 8 && strlen($parts[1]) == 8) {
        $start = strtotime($parts[0]);
        $end = strtotime($parts[1]);
        
        if ($start > $end) {
            $tmp = $start;
            $start = $end;
            $end = $tmp;
        }
        
        $current = $start;
        while ($current <= $end) {
            $dates[] = date('Y-m-d', $current);
            $current = strtotime('+1 day', $current);
        }
        
        $date_display = date('Y-m-d', $start) . ' 至 ' . date('Y-m-d', $end) . '（共' . count($dates) . '天）';
    } else {
        $dates[] = date('Y-m-d');
        $date_display = date('Y-m-d');
    }
}

// 类型名称
$type_names = array(
    'all' => '全部',
    'mobile' => '移动端',
    'pc' => 'PC端'
);
$type_name = isset($type_names[$type]) ? $type_names[$type] : '全部';

// 过滤条件描述
$filter_desc = array();
if ($filter_inner) $filter_desc[] = '仅内页';
if ($filter_subdomain) $filter_desc[] = '仅二级域名';
$filter_name = empty($filter_desc) ? '无' : implode('+', $filter_desc);

/**
 * 检查URL是否为首页
 * @param string $url 完整URL
 * @return bool 是否为首页
 */
function isHomePage($url) {
    $parsed = parse_url($url);
    $path = isset($parsed['path']) ? $parsed['path'] : '/';
    // 首页：路径为空、/、/index.html、/index.php 等
    return ($path === '' || $path === '/' || preg_match('/^\/index\.(html?|php|asp|aspx|jsp)?$/i', $path));
}

/**
 * 检查URL是否为非主域的二级域名（排除www/m/wap）
 * @param string $url 完整URL
 * @param array $main_domains 用户输入的顶级域名列表
 * @return bool 是否为非主域的二级域名
 */
function isOtherSubdomain($url, $main_domains) {
    $parsed = parse_url($url);
    $host = isset($parsed['host']) ? strtolower($parsed['host']) : '';
    
    if (empty($host)) return false;
    
    // 主域前缀列表（这些被视为主域）
    $main_prefixes = array('www.', 'm.', 'wap.');
    
    foreach ($main_domains as $main_domain) {
        $main_domain = strtolower($main_domain);
        
        // 如果host就是顶级域名本身，不是二级域名
        if ($host === $main_domain) {
            return false;
        }
        
        // 检查是否是该顶级域名的子域
        if (substr($host, -strlen('.' . $main_domain)) === '.' . $main_domain) {
            // 提取子域前缀
            $subdomain = substr($host, 0, strlen($host) - strlen($main_domain) - 1);
            
            // 检查是否是主域前缀
            $is_main_prefix = false;
            foreach ($main_prefixes as $prefix) {
                if ($host === rtrim($prefix, '.') . '.' . $main_domain) {
                    $is_main_prefix = true;
                    break;
                }
            }
            
            // 如果不是主域前缀，则是其他二级域名
            if (!$is_main_prefix) {
                return true;
            }
        }
    }
    
    return false;
}

/**
 * 检查URL是否为二级域名的首页（不含内页）
 * @param string $url 完整URL
 * @return bool 是否为二级域名首页
 */
function isSubdomainHomePage($url) {
    return isHomePage($url);
}

// 生成文件名
$ext = ($format === 'csv') ? 'csv' : 'txt';
$domain_count = count($domains);
$date_for_filename = count($dates) == 1 ? str_replace('-', '', $dates[0]) : str_replace('-', '', $dates[0]) . '_' . str_replace('-', '', $dates[count($dates) - 1]);
$filename = '指定导出_' . $domain_count . '个域名_' . $type_name . '_' . $date_for_filename . '.' . $ext;
$filename = preg_replace('/[\/\\\\:*?"<>|]/', '_', $filename);

// 设置下载头
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    echo "\xEF\xBB\xBF"; // BOM
} else {
    header('Content-Type: text/plain; charset=utf-8');
}
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

try {
    $db = getSpiderDB();
    
    // 收集所有日期的数据
    $url_counts = array(); // URL => count
    $url_domains = array(); // URL => domain
    $url_spiders = array(); // URL => spider_name
    
    // 统计每个域名的数据
    $domain_stats = array();
    foreach ($domains as $d) {
        $domain_stats[$d] = 0;
    }
    
    foreach ($dates as $date) {
        $pdo = $db->getDBByDate($date);
        if (!$pdo) continue;
        
        // 如果域名数量超过900，分批查询
        if (count($domains) > 900) {
            $batch_size = 900;
            $batches = array_chunk($domains, $batch_size);
            
            foreach ($batches as $batch) {
                $placeholders = implode(',', array_fill(0, count($batch), '?'));
                $sql = "SELECT url, domain, spider_name, COUNT(*) as count FROM spider_visits WHERE domain IN ($placeholders)";
                $params = $batch;
                
                // 蜘蛛类型筛选
                if ($type === 'mobile') {
                    $sql .= " AND spider_name = '百度移动'";
                } elseif ($type === 'pc') {
                    $sql .= " AND spider_name = '百度PC'";
                }
                
                $sql .= ' GROUP BY url, domain, spider_name';
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $url = $row['url'];
                    $count = (int)$row['count'];
                    $domain = $row['domain'];
                    $spider = $row['spider_name'];
                    
                    // 应用链接过滤条件（并列模式：满足任一条件即保留）
                    if ($filter_inner || $filter_subdomain) {
                        $keep = false;
                        
                        // 仅内页：保留非首页的链接
                        if ($filter_inner && !isHomePage($url)) {
                            $keep = true;
                        }
                        
                        // 仅二级域名：保留非主域的二级域名首页
                        if ($filter_subdomain && isOtherSubdomain($url, $domains) && isSubdomainHomePage($url)) {
                            $keep = true;
                        }
                        
                        // 如果不满足任何条件，跳过
                        if (!$keep) {
                            continue;
                        }
                    }
                    
                    if (!isset($url_counts[$url])) {
                        $url_counts[$url] = 0;
                        $url_domains[$url] = $domain;
                        $url_spiders[$url] = $spider;
                    }
                    $url_counts[$url] += $count;
                    
                    // 更新域名统计
                    if (isset($domain_stats[$domain])) {
                        $domain_stats[$domain] += $count;
                    }
                }
            }
        } else {
            // 正常查询
            $placeholders = implode(',', array_fill(0, count($domains), '?'));
            $sql = "SELECT url, domain, spider_name, COUNT(*) as count FROM spider_visits WHERE domain IN ($placeholders)";
            $params = $domains;
            
            // 蜘蛛类型筛选
            if ($type === 'mobile') {
                $sql .= " AND spider_name = '百度移动'";
            } elseif ($type === 'pc') {
                $sql .= " AND spider_name = '百度PC'";
            }
            
            $sql .= ' GROUP BY url, domain, spider_name';
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $url = $row['url'];
            $count = (int)$row['count'];
            $domain = $row['domain'];
            $spider = $row['spider_name'];
            
            // 应用链接过滤条件（并列模式：满足任一条件即保留）
            if ($filter_inner || $filter_subdomain) {
                $keep = false;
                
                // 仅内页：保留非首页的链接
                if ($filter_inner && !isHomePage($url)) {
                    $keep = true;
                }
                
                // 仅二级域名：保留非主域的二级域名首页
                if ($filter_subdomain && isOtherSubdomain($url, $domains) && isSubdomainHomePage($url)) {
                    $keep = true;
                }
                
                // 如果不满足任何条件，跳过
                if (!$keep) {
                    continue;
                }
            }
            
            if (!isset($url_counts[$url])) {
                $url_counts[$url] = 0;
                $url_domains[$url] = $domain;
                $url_spiders[$url] = $spider;
            }
                $url_counts[$url] += $count;
                
                // 更新域名统计
                if (isset($domain_stats[$domain])) {
                    $domain_stats[$domain] += $count;
                }
            }
        }
    }
    
    // 按抓取次数降序排序
    arsort($url_counts);
    
    // 统计汇总
    $url_count = count($url_counts);
    $total_count = array_sum($url_counts);
    $matched_domains = 0;
    foreach ($domain_stats as $d => $c) {
        if ($c > 0) $matched_domains++;
    }
    
    if ($format === 'csv') {
        // CSV格式输出 - 头部信息
        echo "=== 导出信息 ===\n";
        echo "说明,内容\n";
        echo "导出方式,指定域名导出\n";
        echo "指定域名数," . $domain_count . "\n";
        echo "匹配域名数," . $matched_domains . "\n";
        echo "导出类型," . $type_name . "\n";
        echo "链接过滤," . $filter_name . "\n";
        echo "日期范围," . $date_display . "\n";
        echo "导出时间," . date('Y-m-d H:i:s') . "\n";
        echo "总URL数," . $url_count . "\n";
        echo "总抓取次数," . $total_count . "\n";
        echo "\n";
        
        // 输出域名匹配情况
        echo "=== 域名匹配详情 ===\n";
        echo "域名,抓取次数\n";
        foreach ($domain_stats as $d => $c) {
            echo '"' . $d . '",' . $c . "\n";
        }
        echo "\n";
        
        // 输出数据
        echo "=== URL列表（按抓取次数降序）===\n";
        echo "URL,抓取次数,域名,蜘蛛类型\n";
        
        foreach ($url_counts as $url => $count) {
            $domain = isset($url_domains[$url]) ? $url_domains[$url] : '';
            $spider = isset($url_spiders[$url]) ? $url_spiders[$url] : '';
            $url_escaped = '"' . str_replace('"', '""', $url) . '"';
            $domain_escaped = '"' . str_replace('"', '""', $domain) . '"';
            $spider_escaped = '"' . str_replace('"', '""', $spider) . '"';
            echo $url_escaped . ',' . $count . ',' . $domain_escaped . ',' . $spider_escaped . "\n";
        }
        
    } else {
        // TXT格式输出
        echo "# ========================================\n";
        echo "# 蜘蛛抓取URL导出 - 指定域名\n";
        echo "# ========================================\n";
        echo "# 指定域名数: " . $domain_count . "\n";
        echo "# 匹配域名数: " . $matched_domains . "\n";
        echo "# 导出类型: " . $type_name . "\n";
        echo "# 链接过滤: " . $filter_name . "\n";
        echo "# 日期范围: " . $date_display . "\n";
        echo "# 导出时间: " . date('Y-m-d H:i:s') . "\n";
        echo "# 总URL数: " . $url_count . "\n";
        echo "# ========================================\n";
        echo "# 域名匹配详情:\n";
        foreach ($domain_stats as $d => $c) {
            $status = $c > 0 ? '✓' : '✗';
            echo "#   $status $d: $c 次\n";
        }
        echo "# ========================================\n\n";
        
        foreach ($url_counts as $url => $count) {
            echo $url . "\n";
        }
    }
    
} catch (Exception $e) {
    if ($format === 'csv') {
        echo "说明,内容\n";
        echo "状态,导出失败\n";
        echo "错误信息," . $e->getMessage() . "\n";
    } else {
        echo "# 导出失败: " . $e->getMessage() . "\n";
    }
}

