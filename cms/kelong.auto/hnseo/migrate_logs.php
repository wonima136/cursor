<?php
/**
 * 日志数据迁移脚本
 * 将历史日志文件导入到 SQLite 数据库
 * 
 * 使用方法：
 * 1. 通过浏览器访问此文件（需要登录验证）
 * 2. 或者通过命令行执行：php migrate_logs.php
 */

// 检查是否命令行执行
$is_cli = php_sapi_name() === 'cli';

if (!$is_cli) {
    ini_set('session.save_handler', 'files');
    ini_set('session.save_path', '/tmp');
    session_name('hnseo_tongji');
    session_start();
    // 验证登录状态
    if (!isset($_SESSION['tongji_logged_in']) || $_SESSION['tongji_logged_in'] !== true) {
        die('请先登录后台再执行迁移操作');
    }
}

// 设置执行时间限制
set_time_limit(0);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/spider_db.php';

// 输出函数
function output($message) {
    global $is_cli;
    if ($is_cli) {
        echo $message . "\n";
    } else {
        echo $message . "<br>\n";
        ob_flush();
        flush();
    }
}

// HTML头部
if (!$is_cli) {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>数据迁移</title>';
    echo '<style>body{font-family:monospace;padding:20px;background:#1e1e1e;color:#0f0;}';
    echo '.error{color:#f00;}.success{color:#0f0;}.info{color:#ff0;}</style></head><body>';
    echo '<h2>📦 日志数据迁移到 SQLite</h2><pre>';
}

output("=== 开始数据迁移 ===");
output("时间: " . date('Y-m-d H:i:s'));
output("");

// 加载域名后缀配置
$suffixes_config = include(__DIR__ . '/domain_suffixes.php');
$double_suffixes = $suffixes_config['double_suffixes'];
$single_suffixes = $suffixes_config['single_suffixes'];

// 提取顶级域名函数
function extractTopDomain($domain) {
    global $double_suffixes, $single_suffixes;
    $domain = strtolower(trim($domain));
    $domain = rtrim($domain, '.');
    if (empty($domain)) return $domain;
    
    // 优先匹配双后缀
    foreach ($double_suffixes as $suffix) {
        if (substr($domain, -strlen($suffix)) === $suffix) {
            $without = rtrim(substr($domain, 0, -strlen($suffix)), '.');
            if (!empty($without)) {
                $parts = explode('.', $without);
                $main = end($parts);
                if (!empty($main)) return $main . $suffix;
            }
        }
    }
    // 匹配单后缀
    foreach ($single_suffixes as $suffix) {
        if (substr($domain, -strlen($suffix)) === $suffix) {
            $without = rtrim(substr($domain, 0, -strlen($suffix)), '.');
            if (!empty($without)) {
                $parts = explode('.', $without);
                $main = end($parts);
                if (!empty($main)) return $main . $suffix;
            }
        }
    }
    // 默认取最后两部分
    $parts = explode('.', $domain);
    if (count($parts) >= 2) {
        return $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
    }
    return $domain;
}

// 蜘蛛目录配置
$spider_dirs = [
    'Sogou' => 'Sogou',
    'Baiduspider' => 'Baiduspider',
    'Googlebot' => 'Googlebot',
    '360Spider' => '360Spider',
    'Yisouspider' => 'Yisouspider',
    'Bytespider' => 'Bytespider'
];

$tongji_path = __DIR__ . '/tongji/';

try {
    $db = getSpiderDB();
    output("✓ 数据库连接成功");
    
    // 统计信息
    $total_files = 0;
    $total_records = 0;
    $total_errors = 0;
    $skipped_duplicates = 0;
    
    // 检查数据库现有记录数
    $existing_count = $db->getTotalCount();
    output("当前数据库记录数: " . number_format($existing_count));
    output("");
    
    // 遍历每个蜘蛛目录
    foreach ($spider_dirs as $spider_type => $dir_name) {
        $spider_path = $tongji_path . $dir_name . '/';
        
        if (!is_dir($spider_path)) {
            output("⚠ 目录不存在: $dir_name");
            continue;
        }
        
        output("处理目录: $dir_name");
        
        // 获取所有日志文件
        $log_files = glob($spider_path . '*.log');
        
        if (empty($log_files)) {
            output("  - 无日志文件");
            continue;
        }
        
        output("  - 找到 " . count($log_files) . " 个日志文件");
        
        foreach ($log_files as $log_file) {
            $filename = basename($log_file);
            $total_files++;
            
            $content = file_get_contents($log_file);
            if (empty($content)) {
                continue;
            }
            
            $lines = explode("\n", $content);
            $records = [];
            $file_records = 0;
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                $parts = explode('--', $line);
                if (count($parts) >= 4) {
                    $visit_time = $parts[0];
                    $ip = $parts[1];
                    $spider_name_from_log = $parts[2];
                    $url = $parts[3];
                    
                    // 解析URL获取域名
                    $parsed_url = parse_url($url);
                    $full_domain = isset($parsed_url['host']) ? $parsed_url['host'] : '';
                    $domain = extractTopDomain($full_domain);
                    
                    if (empty($domain)) {
                        $total_errors++;
                        continue;
                    }
                    
                    $records[] = [
                        'visit_time' => $visit_time,
                        'ip' => $ip,
                        'spider_type' => $spider_type,
                        'spider_name' => $spider_name_from_log, // 保留原始蜘蛛名称（如百度PC/百度移动）
                        'url' => $url,
                        'domain' => $domain
                    ];
                    
                    $file_records++;
                    
                    // 每1000条批量插入一次
                    if (count($records) >= 1000) {
                        $db->batchInsert($records);
                        $total_records += count($records);
                        $records = [];
                    }
                }
            }
            
            // 插入剩余记录
            if (!empty($records)) {
                $db->batchInsert($records);
                $total_records += count($records);
            }
            
            if ($file_records > 0) {
                output("  - $filename: $file_records 条记录");
            }
        }
        
        output("");
    }
    
    // 优化数据库
    output("优化数据库...");
    $db->optimize();
    output("✓ 数据库优化完成");
    
    // 最终统计
    $final_count = $db->getTotalCount();
    
    output("");
    output("=== 迁移完成 ===");
    output("处理文件数: " . number_format($total_files));
    output("导入记录数: " . number_format($total_records));
    output("错误记录数: " . number_format($total_errors));
    output("数据库总记录: " . number_format($final_count));
    output("完成时间: " . date('Y-m-d H:i:s'));
    
} catch (Exception $e) {
    output("✗ 错误: " . $e->getMessage());
}

if (!$is_cli) {
    echo '</pre>';
    echo '<p><a href="tongji.php" style="color:#0ff;">返回统计后台</a></p>';
    echo '</body></html>';
}

