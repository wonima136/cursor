<?php
/**
 * 导出单个域名URL数据
 * 支持导出：全部链接、移动端链接、PC端链接
 * 支持格式：TXT（仅URL）、CSV（含抓取次数统计）
 * 支持日期范围：当天、多天、自定义范围
 */
ini_set('session.save_handler', 'files');
ini_set('session.save_path', '/tmp');
session_name('hnseo_tongji');
session_start();

// 验证登录
if (!isset($_SESSION['tongji_logged_in']) || $_SESSION['tongji_logged_in'] !== true) {
    header('HTTP/1.0 403 Forbidden');
    exit('请先登录');
}

require_once __DIR__ . '/spider_db.php';

// 获取参数
$domain = isset($_GET['domain']) ? trim($_GET['domain']) : '';
$type = isset($_GET['type']) ? $_GET['type'] : 'all';  // all, mobile, pc
$format = isset($_GET['format']) ? $_GET['format'] : 'txt';  // txt, csv
$range = isset($_GET['range']) ? $_GET['range'] : 'days_1';  // days_N 或 YYYYMMDD-YYYYMMDD

if (empty($domain)) {
    die('域名参数缺失');
}

// 解析日期范围
$dates = array();
$date_display = '';

if (strpos($range, 'days_') === 0) {
    // 按天数
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
    // 自定义范围 YYYYMMDD-YYYYMMDD
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
        // 默认今天
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

// 生成文件名
$ext = ($format === 'csv') ? 'csv' : 'txt';
$date_for_filename = count($dates) == 1 ? str_replace('-', '', $dates[0]) : str_replace('-', '', $dates[0]) . '_' . str_replace('-', '', $dates[count($dates) - 1]);
$filename = $domain . '_' . $type_name . '_' . $date_for_filename . '.' . $ext;
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
    $url_spiders = array(); // URL => spider_name
    
    foreach ($dates as $date) {
        $pdo = $db->getDBByDate($date);
        if (!$pdo) continue;
        
        // 构建查询
        $sql = 'SELECT url, spider_name, COUNT(*) as count FROM spider_visits WHERE domain = ?';
        $params = array($domain);
        
        // 蜘蛛类型筛选
        if ($type === 'mobile') {
            $sql .= " AND spider_name = '百度移动'";
        } elseif ($type === 'pc') {
            $sql .= " AND spider_name = '百度PC'";
        }
        
        $sql .= ' GROUP BY url, spider_name';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $url = $row['url'];
            $count = (int)$row['count'];
            $spider = $row['spider_name'];
            
            if (!isset($url_counts[$url])) {
                $url_counts[$url] = 0;
                $url_spiders[$url] = $spider;
            }
            $url_counts[$url] += $count;
        }
    }
    
    // 按抓取次数降序排序
    arsort($url_counts);
    
    // 统计汇总
    $url_count = count($url_counts);
    $total_count = array_sum($url_counts);
    
    if ($format === 'csv') {
        // CSV格式输出 - 头部信息
        echo "=== 导出信息 ===\n";
        echo "说明,内容\n";
        echo "域名," . $domain . "\n";
        echo "导出类型," . $type_name . "\n";
        echo "日期范围," . $date_display . "\n";
        echo "导出时间," . date('Y-m-d H:i:s') . "\n";
        echo "总URL数," . $url_count . "\n";
        echo "总抓取次数," . $total_count . "\n";
        echo "\n";
        
        // 输出数据
        echo "=== 数据列表（按抓取次数降序）===\n";
        echo "URL,抓取次数,蜘蛛类型\n";
        
        foreach ($url_counts as $url => $count) {
            $spider = isset($url_spiders[$url]) ? $url_spiders[$url] : '';
            $url_escaped = '"' . str_replace('"', '""', $url) . '"';
            $spider_escaped = '"' . str_replace('"', '""', $spider) . '"';
            echo $url_escaped . ',' . $count . ',' . $spider_escaped . "\n";
        }
        
    } else {
        // TXT格式输出
        echo "# ========================================\n";
        echo "# 蜘蛛抓取URL导出\n";
        echo "# ========================================\n";
        echo "# 域名: " . $domain . "\n";
        echo "# 导出类型: " . $type_name . "\n";
        echo "# 日期范围: " . $date_display . "\n";
        echo "# 导出时间: " . date('Y-m-d H:i:s') . "\n";
        echo "# 总URL数: " . $url_count . "\n";
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

