<?php
/**
 * 域名统计数据处理文件 - SQLite版本（按天分库）
 * 用于分析各个域名的蜘蛛访问情况
 */

error_reporting(0);

require_once __DIR__ . '/spider_db.php';

/**
 * 解析日期范围
 * @param string $date_range 日期范围字符串
 * @return array [start_date, end_date] Y-m-d格式
 */
function parseDateRange($date_range) {
    switch ($date_range) {
        case 'today':
            $date = date('Y-m-d');
            return array($date, $date);
        case 'yesterday':
            $date = date('Y-m-d', strtotime('-1 day'));
            return array($date, $date);
        case 'week':
            return array(date('Y-m-d', strtotime('-6 days')), date('Y-m-d'));
        case 'month':
            return array(date('Y-m-d', strtotime('-29 days')), date('Y-m-d'));
        default:
            // 检查是否是日期范围格式 YYYYMMDD-YYYYMMDD (总长度17: 8+1+8)
            if (strlen($date_range) == 17 && $date_range[8] == '-') {
                $start_str = substr($date_range, 0, 8);
                $end_str = substr($date_range, 9, 8);
                $start = date('Y-m-d', strtotime($start_str));
                $end = date('Y-m-d', strtotime($end_str));
                return array($start, $end);
            }
            // 单个日期 YYYYMMDD 格式
            $date = date('Y-m-d', strtotime($date_range));
            return array($date, $date);
    }
}

/**
 * 获取日期范围显示标签
 * @param string $date_range 日期范围
 * @return string 显示标签
 */
function getDateRangeLabel($date_range) {
    switch ($date_range) {
        case 'today':
            return '今天 (' . date('Y-m-d') . ')';
        case 'yesterday':
            return '昨天 (' . date('Y-m-d', strtotime('-1 day')) . ')';
        case 'week':
            return '最近7天 (' . date('Y-m-d', strtotime('-6 day')) . ' 至 ' . date('Y-m-d') . ')';
        case 'month':
            return '最近30天 (' . date('Y-m-d', strtotime('-29 day')) . ' 至 ' . date('Y-m-d') . ')';
        default:
            // 检查是否是日期范围格式 YYYYMMDD-YYYYMMDD (总长度17: 8+1+8)
            if (strlen($date_range) == 17 && $date_range[8] == '-') {
                $start_str = substr($date_range, 0, 8);
                $end_str = substr($date_range, 9, 8);
                $start = date('Y-m-d', strtotime($start_str));
                $end = date('Y-m-d', strtotime($end_str));
                return "自定义范围 ($start 至 $end)";
            }
            return '单日 (' . date('Y-m-d', strtotime($date_range)) . ')';
    }
}

/**
 * 获取域名统计数据（跨日期范围）
 */
function getDomainStats($date_range, $group_id = 0) {
    try {
        $db = getSpiderDB();
        list($start_date, $end_date) = parseDateRange($date_range);
        
        $domain_stats = $db->getDomainStats($start_date, $end_date, $group_id > 0 ? $group_id : null);

        // 格式化数据
        $result = array();
        foreach ($domain_stats as $row) {
            $result[] = array(
                'domain' => $row['domain'],
                'total' => (int)$row['total'],
                'spiders' => array(
                    '百度' => (int)$row['baidu'],
                    '谷歌' => (int)$row['google'],
                    '搜狗' => (int)$row['sogou'],
                    '360' => (int)$row['s360'],
                    '神马' => (int)$row['yisou'],
                    '今日头条' => (int)$row['byte']
                ),
                'last_visit' => $row['last_visit']
            );
        }
        
        return $result;
        
    } catch (Exception $e) {
        return array();
    }
}

/**
 * 获取单个域名的详细统计（支持日期范围）
 */
function getDomainDetailByRange($domain, $date_range) {
    try {
        $db = getSpiderDB();
        list($start_date, $end_date) = parseDateRange($date_range);
    
        // 初始化统计
        $total = 0;
        $baidu = 0;
        $google = 0;
        $sogou = 0;
        $s360 = 0;
        $yisou = 0;
        $byte = 0;
        $hourly = array_fill(0, 24, 0);
        $visits = array();
    
    // 遍历日期范围
        $current = strtotime($start_date);
        $end = strtotime($end_date);
        
        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            $pdo = $db->getDBByDate($date);
            
            if ($pdo) {
                // 基础统计
                $sql = "
                    SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN spider_name = '百度' OR spider_name = '百度PC' OR spider_name = '百度移动' THEN 1 ELSE 0 END) as baidu,
                        SUM(CASE WHEN spider_name = '谷歌' THEN 1 ELSE 0 END) as google,
                        SUM(CASE WHEN spider_name = '搜狗' THEN 1 ELSE 0 END) as sogou,
                        SUM(CASE WHEN spider_name = '360' THEN 1 ELSE 0 END) as s360,
                        SUM(CASE WHEN spider_name = '神马' THEN 1 ELSE 0 END) as yisou,
                        SUM(CASE WHEN spider_name = '今日头条' THEN 1 ELSE 0 END) as byte
                    FROM spider_visits
                    WHERE domain = ?
                ";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array($domain));
                $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($stats) {
                    $total += (int)$stats['total'];
                    $baidu += (int)$stats['baidu'];
                    $google += (int)$stats['google'];
                    $sogou += (int)$stats['sogou'];
                    $s360 += (int)$stats['s360'];
                    $yisou += (int)$stats['yisou'];
                    $byte += (int)$stats['byte'];
                        }
                        
                // 小时分布
                $hourly_sql = "
                    SELECT visit_hour as hour, COUNT(*) as count
                    FROM spider_visits
                    WHERE domain = ?
                    GROUP BY visit_hour
                ";
                
                $stmt = $pdo->prepare($hourly_sql);
                $stmt->execute(array($domain));
                
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $hourly[$row['hour']] += (int)$row['count'];
                }
            }
            
            $current = strtotime('+1 day', $current);
}

        // 获取最近的访问记录（从最新日期开始）
        $visits_needed = 100;
        $current = strtotime($end_date);
        $start = strtotime($start_date);
        
        while ($current >= $start && count($visits) < $visits_needed) {
            $date = date('Y-m-d', $current);
            $pdo = $db->getDBByDate($date);
            
            if ($pdo) {
                $limit = $visits_needed - count($visits);
                $visits_sql = "
                    SELECT visit_time as time, ip, spider_name as spider, url
                    FROM spider_visits
                    WHERE domain = ?
                    ORDER BY visit_time DESC
                    LIMIT ?
                ";
                
                $stmt = $pdo->prepare($visits_sql);
                $stmt->execute(array($domain, $limit));
                $day_visits = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $visits = array_merge($visits, $day_visits);
            }
            
            $current = strtotime('-1 day', $current);
        }
        
        return array(
            'domain' => $domain,
            'date_range' => $date_range,
            'total' => $total,
            'spiders' => array(
                '百度' => $baidu,
                '谷歌' => $google,
                '搜狗' => $sogou,
                '360' => $s360,
                '神马' => $yisou,
                '今日头条' => $byte
            ),
            'hourly' => $hourly,
            'visits' => $visits
        );
        
    } catch (Exception $e) {
        return array(
        'domain' => $domain,
        'date_range' => $date_range,
        'total' => 0,
            'spiders' => array(),
        'hourly' => array_fill(0, 24, 0),
            'visits' => array(),
            'error' => $e->getMessage()
        );
    }
}

// 处理AJAX请求
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    switch ($_GET['action']) {
        case 'domain_list':
            $date_range = isset($_GET['date_range']) ? $_GET['date_range'] : 'today';
            $group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;
            
            $domain_stats = getDomainStats($date_range, $group_id);
            
            // 生成显示标签
            $display_label = getDateRangeLabel($date_range);
            
            // 如果有分组，添加分组名称到标签
            if ($group_id > 0) {
                $groups_file = __DIR__ . '/groups.json';
                if (file_exists($groups_file)) {
                    $groups_data = json_decode(file_get_contents($groups_file), true);
                    if ($groups_data && isset($groups_data['groups'])) {
                        foreach ($groups_data['groups'] as $group) {
                            if ($group['id'] == $group_id) {
                                $display_label .= ' - 分组：' . $group['name'];
                                break;
                            }
                        }
                    }
                }
            }
            
            echo json_encode(array(
                'success' => true,
                'data' => $domain_stats,
                'date_range' => $date_range,
                'display_label' => $display_label,
                'group_id' => $group_id
            ));
            break;
            
        case 'domain_detail':
            $domain = isset($_GET['domain']) ? $_GET['domain'] : '';
            $date_range = isset($_GET['date_range']) ? $_GET['date_range'] : 'today';
            
            if ($domain) {
                $domain_detail = getDomainDetailByRange($domain, $date_range);
                echo json_encode(array(
                    'success' => true,
                    'data' => $domain_detail
                ));
            } else {
                echo json_encode(array('success' => false, 'message' => '域名参数缺失'));
            }
            break;
            
        default:
            echo json_encode(array('success' => false, 'message' => '未知操作'));
    }
    exit;
}
