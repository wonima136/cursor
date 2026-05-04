<?php
/**
 * 全局蜘蛛数据导出功能
 * 导出所有域名的URL访问统计数据
 */

// 错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 调试模式
$debug = isset($_GET['debug']) ? true : false;

if ($debug) {
    echo "调试模式开启\n";
    echo "当前时间: " . date('Y-m-d H:i:s') . "\n";
    echo "PHP版本: " . PHP_VERSION . "\n";
}

// 引入域名统计功能
if (!file_exists('domain_stats.php')) {
    if ($debug) {
        echo "错误: domain_stats.php 文件不存在\n";
    }
    die('Required file domain_stats.php not found');
}

include_once 'domain_stats.php';

if ($debug) {
    echo "domain_stats.php 加载成功\n";
}

// 获取日期范围参数
$date_range = isset($_GET['date_range']) ? $_GET['date_range'] : 'today';

if ($debug) {
    echo "日期范围: " . $date_range . "\n";
}

// 设置响应头
if (!$debug) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="全局蜘蛛数据_' . date('Y-m-d') . '.csv"');
}

// 获取全局数据统计
function getGlobalUrlStats($date_range = null) {
    global $stats_dir, $spider_dirs;
    
    if (!$date_range) {
        $date_range = date('Ymd');
    }
    
    // 解析日期范围
    $dates = parseDateRange($date_range);
    
    $url_stats = [];
    
    // 遍历日期范围
    foreach ($dates as $date) {
        // 遍历所有蜘蛛目录
        foreach ($spider_dirs as $spider_dir => $spider_name) {
            $log_file = $stats_dir . $spider_dir . '/' . $date . '.log';
            
            if (file_exists($log_file)) {
                $content = file_get_contents($log_file);
                $lines = explode("\n", $content);
                
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    
                    $parts = explode('--', $line);
                    if (count($parts) >= 4) {
                        $time = $parts[0];
                        $ip = $parts[1];
                        $spider = $parts[2];
                        $url = $parts[3];
                        
                        // 使用蜘蛛名称+URL作为统计键
                        $stats_key = $spider . '|||' . $url;
                        
                        if (!isset($url_stats[$stats_key])) {
                            $url_stats[$stats_key] = [
                                'spider' => $spider,
                                'url' => $url,
                                'count' => 0
                            ];
                        }
                        $url_stats[$stats_key]['count']++;
                    }
                }
            }
        }
    }
    
    // 转换为数组并按抓取次数排序
    $export_data = array_values($url_stats);
    usort($export_data, function($a, $b) {
        return $b['count'] - $a['count'];
    });
    
    return $export_data;
}

// 获取数据
if ($debug) {
    echo "开始获取全局数据...\n";
}

$global_data = getGlobalUrlStats($date_range);

if ($debug) {
    echo "获取到数据条数: " . count($global_data) . "\n";
    if (count($global_data) > 0) {
        echo "前3条数据示例:\n";
        for ($i = 0; $i < min(3, count($global_data)); $i++) {
            echo "  " . ($i+1) . ". " . $global_data[$i]['spider'] . " - " . $global_data[$i]['url'] . " (" . $global_data[$i]['count'] . "次)\n";
        }
    }
    echo "开始输出CSV...\n";
}

// 输出CSV内容
if (!$debug) {
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
}
echo "蜘蛛名称,URL,抓取次数\n";

foreach ($global_data as $item) {
    // 处理CSV中的特殊字符
    $spider = '"' . str_replace('"', '""', $item['spider']) . '"';
    $url = '"' . str_replace('"', '""', $item['url']) . '"';
    $count = $item['count'];
    
    echo $spider . ',' . $url . ',' . $count . "\n";
}

if ($debug) {
    echo "\nCSV输出完成\n";
    echo "总导出记录数: " . count($global_data) . "\n";
}

// 记录导出日志（已禁用）
// try {
//     $log_message = date('Y-m-d H:i:s') . " - 全局数据导出 - 日期范围: {$date_range} - 记录数: " . count($global_data) . "\n";
//     if (isset($stats_dir) && is_dir($stats_dir)) {
//         file_put_contents($stats_dir . 'export.log', $log_message, FILE_APPEND | LOCK_EX);
//     }
// } catch (Exception $e) {
//     if ($debug) {
//         echo "日志记录失败: " . $e->getMessage() . "\n";
//     }
// }

if ($debug) {
    echo "导出完成\n";
}
?>
