<?php
/**
 * URL抓取查询接口
 * 查询指定URL在数据库中的抓取记录
 * 支持精确匹配，自动处理协议头
 */
ini_set('session.save_handler', 'files');
ini_set('session.save_path', '/tmp');
session_name('hnseo_tongji');
session_start();

// 验证登录
if (!isset($_SESSION['tongji_logged_in']) || $_SESSION['tongji_logged_in'] !== true) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/spider_db.php';

// 获取参数
$urls_input = isset($_POST['urls']) ? $_POST['urls'] : '';
$range = isset($_POST['range']) ? $_POST['range'] : 'days_1';

if (empty($urls_input)) {
    echo json_encode(['success' => false, 'message' => '请输入要查询的URL']);
    exit;
}

// 解析URL列表
$urls_raw = preg_split('/[\r\n]+/', trim($urls_input));
$urls = [];
foreach ($urls_raw as $url) {
    $url = trim($url);
    if (!empty($url)) {
        $urls[] = $url;
    }
}

if (empty($urls)) {
    echo json_encode(['success' => false, 'message' => '请输入有效的URL']);
    exit;
}

// 解析日期范围
$dates = [];
if (strpos($range, 'days_') === 0) {
    $days = intval(substr($range, 5));
    if ($days < 1) $days = 1;
    
    for ($i = $days - 1; $i >= 0; $i--) {
        $dates[] = date('Y-m-d', strtotime("-$i days"));
    }
} else {
    // 自定义范围 YYYYMMDD-YYYYMMDD
    if (strlen($range) == 17 && $range[8] == '-') {
        $start_str = substr($range, 0, 8);
        $end_str = substr($range, 9, 8);
        $start = strtotime($start_str);
        $end = strtotime($end_str);
        
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
    } else {
        $dates[] = date('Y-m-d');
    }
}

/**
 * 为URL生成可能的变体（处理协议头）
 * @param string $url 原始URL
 * @return array 可能的URL变体
 */
function getUrlVariants($url) {
    $variants = [];
    
    // 如果已经有协议头，直接返回
    if (preg_match('/^https?:\/\//i', $url)) {
        $variants[] = $url;
        return $variants;
    }
    
    // 没有协议头，生成http和https两个版本
    $variants[] = 'http://' . $url;
    $variants[] = 'https://' . $url;
    
    return $variants;
}

try {
    $db = getSpiderDB();
    
    // 初始化结果数组
    $results = [];
    foreach ($urls as $url) {
        $results[$url] = [
            'url' => $url,
            'pc' => 0,
            'mobile' => 0,
            'total' => 0,
            'matched_url' => null // 实际匹配到的URL
        ];
    }
    
    // 遍历日期查询
    foreach ($dates as $date) {
        $pdo = $db->getDBByDate($date);
        if (!$pdo) continue;
        
        // 对每个用户输入的URL进行查询
        foreach ($urls as $original_url) {
            $variants = getUrlVariants($original_url);
            
            foreach ($variants as $variant) {
                // 精确匹配查询
                $sql = "SELECT 
                    url,
                    SUM(CASE WHEN spider_name = '百度PC' THEN 1 ELSE 0 END) as pc,
                    SUM(CASE WHEN spider_name = '百度移动' THEN 1 ELSE 0 END) as mobile,
                    COUNT(*) as total
                FROM spider_visits 
                WHERE url = ?
                GROUP BY url";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$variant]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($row && $row['total'] > 0) {
                    $results[$original_url]['pc'] += (int)$row['pc'];
                    $results[$original_url]['mobile'] += (int)$row['mobile'];
                    $results[$original_url]['total'] += (int)$row['total'];
                    
                    // 记录实际匹配到的URL
                    if (!$results[$original_url]['matched_url']) {
                        $results[$original_url]['matched_url'] = $row['url'];
                    }
                }
            }
        }
    }
    
    // 转换为数组并按抓取总数降序排序
    $result_array = array_values($results);
    usort($result_array, function($a, $b) {
        return $b['total'] - $a['total'];
    });
    
    // 如果有匹配到的URL，使用匹配到的URL替换原始URL
    foreach ($result_array as &$item) {
        if ($item['matched_url']) {
            $item['url'] = $item['matched_url'];
        }
        unset($item['matched_url']);
    }
    unset($item);
    
    // 统计
    $total_urls = count($urls);
    $found_urls = 0;
    foreach ($result_array as $item) {
        if ($item['total'] > 0) {
            $found_urls++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => $result_array,
        'total_urls' => $total_urls,
        'found_urls' => $found_urls,
        'date_range' => count($dates) == 1 ? $dates[0] : $dates[0] . ' 至 ' . $dates[count($dates) - 1]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '查询出错: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

