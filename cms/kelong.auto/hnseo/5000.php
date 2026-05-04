<?php
/**
 * 蜘蛛访问明细接口 - SQLite版本（按天分库）
 * 提供分页、筛选、分组功能
 */
 error_reporting(0);

require_once __DIR__ . '/spider_db.php';

// 解析请求参数
// 格式: 5000.php?spider_type--date--p--page--g--group_id
$req = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
$req = explode("--", $req);

$spider_type = isset($req[0]) ? $req[0] : 'zong';
$date = isset($req[1]) ? $req[1] : date('Ymd');
$page = isset($req[3]) ? intval($req[3]) : 1;
$group_id = isset($req[5]) ? intval($req[5]) : 0;

// 转换日期格式 YYYYMMDD -> YYYY-MM-DD
$date_formatted = date('Y-m-d', strtotime($date));

try {
    $db = getSpiderDB();
    $pdo = $db->getDBByDate($date_formatted);
    
    if (!$pdo) {
        // 如果指定日期没有数据库文件
        echo json_encode(array(
            'list' => array(),
            'pages' => '',
            'spider' => $spider_type,
            'total' => 0,
            'scount' => array(),
            'group_id' => $group_id
        ));
        exit;
    }
    
    // 蜘蛛类型映射
    $spider_map = array(
        'Baiduspider' => 'Baiduspider',
        'Googlebot' => 'Googlebot',
        'Sogou' => 'Sogou',
        '360Spider' => '360Spider',
        'Yisouspider' => 'Yisouspider',
        'Bytespider' => 'Bytespider',
        'zong' => null // 全部
    );
    
    $db_spider_type = isset($spider_map[$spider_type]) ? $spider_map[$spider_type] : null;
    $page_size = 50;
    $offset = ($page - 1) * $page_size;
    
    // 获取分组域名列表
    $allowed_domains = null;
    if ($group_id > 0) {
        $groups_file = __DIR__ . '/groups.json';
        if (file_exists($groups_file)) {
            $groups_data = json_decode(file_get_contents($groups_file), true);
            if ($groups_data && isset($groups_data['groups'])) {
                foreach ($groups_data['groups'] as $group) {
                    if ($group['id'] == $group_id) {
                        $allowed_domains = $group['domains'];
                        break;
                    }
                }
            }
        }
    }
    
    // 检查域名数量是否超过SQLite限制
    if ($allowed_domains !== null && empty($allowed_domains)) {
        // 分组没有域名，返回空结果
        echo json_encode(array(
            'list' => array(),
            'pages' => '',
            'spider' => $spider_type,
            'total' => 0,
            'scount' => array(),
            'group_id' => $group_id
        ));
        exit;
    }
    
    // 如果域名数量超过900，使用临时表方案
    if ($allowed_domains !== null && count($allowed_domains) > 900) {
        // 创建临时表
        $pdo->exec('CREATE TEMP TABLE IF NOT EXISTS temp_domains (domain TEXT)');
        $pdo->exec('DELETE FROM temp_domains');
        
        // 批量插入域名到临时表
        $insert_stmt = $pdo->prepare('INSERT INTO temp_domains (domain) VALUES (?)');
        foreach ($allowed_domains as $domain) {
            $insert_stmt->execute(array($domain));
        }
        
        // 使用临时表查询
        $where_conditions = array('domain IN (SELECT domain FROM temp_domains)');
        $params = array();
        
        if ($db_spider_type) {
            $where_conditions[] = 'spider_type = ?';
            $params[] = $db_spider_type;
        }
        
        $where_sql = implode(' AND ', $where_conditions);
    } else {
        // 正常查询
        $where_conditions = array('1=1');
        $params = array();
        
        // 蜘蛛类型筛选
        if ($db_spider_type) {
            $where_conditions[] = 'spider_type = ?';
            $params[] = $db_spider_type;
        }
        
        // 分组筛选
        if ($allowed_domains !== null && !empty($allowed_domains)) {
            $placeholders = implode(',', array_fill(0, count($allowed_domains), '?'));
            $where_conditions[] = "domain IN ($placeholders)";
            $params = array_merge($params, $allowed_domains);
        }
        
        $where_sql = implode(' AND ', $where_conditions);
    }
    
    // 查询总数
    $count_sql = "SELECT COUNT(*) FROM spider_visits WHERE $where_sql";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    
    // 查询列表
    $list_sql = "
        SELECT 
            id,
            visit_time as time,
            ip,
            spider_name as name,
            url,
            domain
        FROM spider_visits 
        WHERE $where_sql
        ORDER BY visit_time DESC
        LIMIT ? OFFSET ?
    ";
    
    $list_params = array_merge($params, array($page_size, $offset));
    $stmt = $pdo->prepare($list_sql);
    $stmt->execute($list_params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 格式化列表数据
    $list = array();
    foreach ($rows as $row) {
        $list[] = array(
            'id' => $row['id'],
            'name' => $row['name'],
            'ip' => $row['ip'],
            'city' => '<font color="green">中国</font>',
            'time' => '<font color="red">' . $row['time'] . '</font>',
            'typename' => '文章新闻',
            'url' => '<a target="_blank" title="打开此链接" href="' . htmlspecialchars($row['url']) . '">' . htmlspecialchars($row['url']) . '</a>'
        );
    }
    
    // 获取各蜘蛛统计
    if ($allowed_domains !== null && count($allowed_domains) > 900) {
        // 使用临时表
        $stats_sql = "SELECT spider_type, spider_name, COUNT(*) as count FROM spider_visits WHERE domain IN (SELECT domain FROM temp_domains) GROUP BY spider_type";
        $stmt = $pdo->query($stats_sql);
        $stats_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stats_sql = "SELECT spider_type, spider_name, COUNT(*) as count FROM spider_visits";
        $stats_params = array();
        
        if ($allowed_domains !== null && !empty($allowed_domains)) {
            $placeholders = implode(',', array_fill(0, count($allowed_domains), '?'));
            $stats_sql .= " WHERE domain IN ($placeholders)";
            $stats_params = $allowed_domains;
        }
        
        $stats_sql .= ' GROUP BY spider_type';
        
        $stmt = $pdo->prepare($stats_sql);
        $stmt->execute($stats_params);
        $stats_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 构建蜘蛛统计数据
    $spider_counts = array();
    $total_count = 0;
    foreach ($stats_rows as $row) {
        $spider_counts[$row['spider_type']] = array(
            'name' => $row['spider_name'],
            'count' => $row['count']
        );
        $total_count += $row['count'];
    }
    
    // 分组参数
    $group_param = $group_id > 0 ? "--g--$group_id" : "";
    
    // 构建 scount 数据
    $scount = array(
        array(
            'key' => 'null',
            'name' => '全部',
            'count' => $total_count,
            'url' => "5000.php?zong--$date--p--1$group_param"
        ),
        array(
            'key' => 'Baiduspider',
            'name' => '百度',
            'count' => isset($spider_counts['Baiduspider']) ? $spider_counts['Baiduspider']['count'] : 0,
            'url' => "5000.php?Baiduspider--$date--p--1$group_param"
        ),
        array(
            'key' => 'Googlebot',
            'name' => 'Google',
            'count' => isset($spider_counts['Googlebot']) ? $spider_counts['Googlebot']['count'] : 0,
            'url' => "5000.php?Googlebot--$date--p--1$group_param"
        ),
        array(
            'key' => 'Sogou',
            'name' => '搜狗',
            'count' => isset($spider_counts['Sogou']) ? $spider_counts['Sogou']['count'] : 0,
            'url' => "5000.php?Sogou--$date--p--1$group_param"
        ),
        array(
            'key' => '360Spider',
            'name' => '360蜘蛛',
            'count' => isset($spider_counts['360Spider']) ? $spider_counts['360Spider']['count'] : 0,
            'url' => "5000.php?360Spider--$date--p--1$group_param"
        ),
        array(
            'key' => 'Yisouspider',
            'name' => '神马',
            'count' => isset($spider_counts['Yisouspider']) ? $spider_counts['Yisouspider']['count'] : 0,
            'url' => "5000.php?Yisouspider--$date--p--1$group_param"
        ),
        array(
            'key' => 'Bytespider',
            'name' => '今日头条',
            'count' => isset($spider_counts['Bytespider']) ? $spider_counts['Bytespider']['count'] : 0,
            'url' => "5000.php?Bytespider--$date--p--1$group_param"
        )
    );
    
    // 生成分页代码
    $pages = ceil($total / $page_size);
    $pagecode = generatePagination($page, $pages, $total, $spider_type, $date, $group_id);
    
    // 输出JSON
    echo json_encode(array(
        'list' => $list,
        'pages' => $pagecode,
        'spider' => $spider_type,
        'total' => $total,
        'scount' => $scount,
        'group_id' => $group_id
    ));
    
} catch (Exception $e) {
    echo json_encode(array(
        'list' => array(),
        'pages' => '',
        'spider' => $spider_type,
        'total' => 0,
        'scount' => array(),
        'error' => $e->getMessage()
    ));
}

/**
 * 生成分页HTML
 */
function generatePagination($current_page, $total_pages, $total, $spider_type, $date, $group_id) {
    if ($total_pages <= 0) return '';
    
    $group_param = $group_id > 0 ? "--g--$group_id" : "";
    $base_url = "5000.php?{$spider_type}--{$date}--p--";
    
    $html = '';
    
    // 首页和上一页
    if ($current_page > 1) {
        $html .= "<a href=\"{$base_url}1{$group_param}\">首页</a>";
        $html .= "<a class=\"pre\" href=\"{$base_url}" . ($current_page - 1) . "{$group_param}\">上一页</a>";
    }
    
    // 页码
    $start = max(1, $current_page - 4);
    $end = min($total_pages, $current_page + 4);
    
    for ($i = $start; $i <= $end; $i++) {
        if ($i == $current_page) {
            $html .= "<a class=\"current\">{$i}</a>";
        } else {
            $html .= "<a href=\"{$base_url}{$i}{$group_param}\">{$i}</a>";
        }
    }
    
    // 下一页和末页
    if ($current_page < $total_pages) {
        $html .= "<a class=\"next\" href=\"{$base_url}" . ($current_page + 1) . "{$group_param}\">下一页</a>";
        $html .= "<a href=\"{$base_url}{$total_pages}{$group_param}\">末页</a>";
    }
    
    return $html;
}		
