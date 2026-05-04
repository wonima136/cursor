<?php
/**
 * 一键迁移脚本 (完整版)
 * 功能：
 * 1. 将 tongji/ 目录下的原始 log 日志迁移到 SQLite
 * 2. 自动按日期分库存储到 spider_data/YYYY-MM-DD.db
 * 3. 自动修复旧数据库缺少的 visit_hour 字段
 * 
 * 使用方法: php migrate_all.php
 */

// 设置执行环境
set_time_limit(0);
ini_set('memory_limit', '1024M');
error_reporting(E_ALL);

$is_cli = php_sapi_name() === 'cli';

// 非CLI模式需要登录验证
if (!$is_cli) {
    ini_set('session.save_handler', 'files');
    ini_set('session.save_path', '/tmp');
    session_name('hnseo_tongji');
    session_start();
    if (!isset($_SESSION['tongji_logged_in']) || $_SESSION['tongji_logged_in'] !== true) {
        die('请先登录后台再执行迁移操作');
    }
}

// 输出函数
function output($message, $type = 'info') {
    global $is_cli;
    $prefix = '';
    switch ($type) {
        case 'success': $prefix = $is_cli ? '✅ ' : '<span style="color:#4CAF50">✅ '; break;
        case 'error': $prefix = $is_cli ? '❌ ' : '<span style="color:#f44336">❌ '; break;
        case 'warning': $prefix = $is_cli ? '⚠️  ' : '<span style="color:#ff9800">⚠️ '; break;
        case 'title': $prefix = $is_cli ? "\n🔷 " : '<br><span style="color:#2196F3;font-weight:bold">🔷 '; break;
        default: $prefix = $is_cli ? '   ' : '&nbsp;&nbsp;&nbsp;';
    }
    $suffix = (!$is_cli && $type != 'info') ? '</span>' : '';
    
    if ($is_cli) {
        echo $prefix . $message . "\n";
    } else {
        echo $prefix . $message . $suffix . "<br>\n";
        ob_flush();
        flush();
    }
}

// HTML头部
if (!$is_cli) {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>数据迁移</title></head>';
    echo '<body style="font-family:\'Consolas\',monospace;padding:20px;background:#1a1a2e;color:#eee;">';
    echo '<h2 style="color:#4CAF50;">🚀 蜘蛛统计数据迁移工具</h2>';
    echo '<div style="background:#16213e;padding:20px;border-radius:8px;line-height:1.8;">';
}

output('蜘蛛统计数据迁移工具 v2.0', 'title');
output('开始时间: ' . date('Y-m-d H:i:s'));

// 配置
$base_dir = __DIR__;
$tongji_dir = $base_dir . '/tongji';
$db_dir = $base_dir . '/spider_data';

// 创建数据库目录
if (!is_dir($db_dir)) {
    mkdir($db_dir, 0755, true);
    output('创建数据库目录: ' . $db_dir, 'success');
}

// 加载域名后缀配置
$suffixes_config = include($base_dir . '/domain_suffixes.php');
$double_suffixes = $suffixes_config['double_suffixes'];
$single_suffixes = $suffixes_config['single_suffixes'];

// 提取顶级域名函数
function extractTopDomain($domain) {
    global $double_suffixes, $single_suffixes;
    $domain = strtolower(trim($domain));
    $domain = rtrim($domain, '.');
    if (empty($domain)) return $domain;
    
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
    $parts = explode('.', $domain);
    if (count($parts) >= 2) {
        return $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
    }
    return $domain;
}

// 蜘蛛目录映射
$spider_dirs = array(
    'Baiduspider' => array('type' => 'Baiduspider', 'name' => '百度'),
    'Googlebot' => array('type' => 'Googlebot', 'name' => '谷歌'),
    'Sogou' => array('type' => 'Sogou', 'name' => '搜狗'),
    '360Spider' => array('type' => '360Spider', 'name' => '360'),
    'Yisouspider' => array('type' => 'Yisouspider', 'name' => '神马'),
    'Bytespider' => array('type' => 'Bytespider', 'name' => '今日头条')
);

// 数据库连接缓存
$db_connections = array();

/**
 * 获取指定日期的数据库连接（包含完整表结构）
 */
function getDBByDate($date) {
    global $db_connections, $db_dir;
    
    if (isset($db_connections[$date])) {
        return $db_connections[$date];
    }
    
    $db_file = $db_dir . '/' . $date . '.db';
    
    try {
        $pdo = new PDO('sqlite:' . $db_file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // 优化设置
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA cache_size = 10000');
        
        // 创建表（包含 visit_hour 字段）
        $pdo->exec('CREATE TABLE IF NOT EXISTS spider_visits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_time DATETIME NOT NULL,
            ip VARCHAR(50) NOT NULL,
            spider_type VARCHAR(50) NOT NULL,
            spider_name VARCHAR(50) NOT NULL,
            url TEXT NOT NULL,
            domain VARCHAR(255) NOT NULL,
            visit_hour INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        
        // 创建索引
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_domain ON spider_visits(domain)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_spider_name ON spider_visits(spider_name)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_spider_type ON spider_visits(spider_type)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_visit_time ON spider_visits(visit_time)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hour ON spider_visits(visit_hour)');
        
        $db_connections[$date] = $pdo;
        return $pdo;
    } catch (Exception $e) {
        output("数据库连接失败 ($date): " . $e->getMessage(), 'error');
        return null;
    }
}

/**
 * 检查并修复旧数据库的 visit_hour 字段
 */
function fixVisitHour($pdo, $date) {
    // 检查是否已有 visit_hour 列
    $columns = $pdo->query("PRAGMA table_info(spider_visits)")->fetchAll(PDO::FETCH_ASSOC);
    $has_visit_hour = false;
    foreach ($columns as $col) {
        if ($col['name'] === 'visit_hour') {
            $has_visit_hour = true;
            break;
        }
    }
    
    if (!$has_visit_hour) {
        // 添加 visit_hour 列
        $pdo->exec("ALTER TABLE spider_visits ADD COLUMN visit_hour INTEGER");
        // 更新数据
        $pdo->exec("UPDATE spider_visits SET visit_hour = CAST(strftime('%H', visit_time) AS INTEGER)");
        // 创建索引
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hour ON spider_visits(visit_hour)");
        return true;
    } else {
        // 检查是否有 NULL 值
        $stmt = $pdo->query("SELECT COUNT(*) FROM spider_visits WHERE visit_hour IS NULL");
        $null_count = $stmt->fetchColumn();
        if ($null_count > 0) {
            $pdo->exec("UPDATE spider_visits SET visit_hour = CAST(strftime('%H', visit_time) AS INTEGER) WHERE visit_hour IS NULL");
            return true;
        }
    }
    return false;
}

// ========================
// 步骤1: 修复现有数据库
// ========================
output('步骤1: 检查并修复现有数据库', 'title');

$existing_dbs = glob($db_dir . '/*.db');
$fixed_count = 0;

if (!empty($existing_dbs)) {
    output("发现 " . count($existing_dbs) . " 个现有数据库文件");
    
    foreach ($existing_dbs as $db_file) {
        $filename = basename($db_file);
        $date = str_replace('.db', '', $filename);
        
        try {
            $pdo = new PDO('sqlite:' . $db_file);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            if (fixVisitHour($pdo, $date)) {
                output("  修复: $filename", 'success');
                $fixed_count++;
            } else {
                output("  正常: $filename");
            }
        } catch (Exception $e) {
            output("  错误: $filename - " . $e->getMessage(), 'error');
        }
    }
    
    if ($fixed_count > 0) {
        output("修复了 $fixed_count 个数据库", 'success');
    } else {
        output("所有数据库结构正常", 'success');
    }
} else {
    output("没有现有数据库，将创建新的", 'warning');
}

// ========================
// 步骤2: 迁移日志文件
// ========================
output('步骤2: 迁移日志文件', 'title');

// 检查 tongji 目录
if (!is_dir($tongji_dir)) {
    output('tongji 目录不存在，跳过日志迁移', 'warning');
} else {
    $total_files = 0;
    $total_records = 0;
    $total_inserted = 0;
    $dates_processed = array();
    
    // 遍历蜘蛛目录
    foreach ($spider_dirs as $dir_name => $spider_info) {
        $spider_path = $tongji_dir . '/' . $dir_name;
        
        if (!is_dir($spider_path)) {
            continue;
        }
        
        $files = glob($spider_path . '/*.log');
        if (empty($files)) continue;
        
        output("处理目录: $dir_name (" . count($files) . " 个文件)");
        $dir_records = 0;
        
        foreach ($files as $file) {
            $total_files++;
            $filename = basename($file);
            
            // 从文件名提取日期 (格式: 20251230.log)
            if (preg_match('/^(\d{8})\.log$/', $filename, $m)) {
                $file_date = substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2);
            } else {
                continue;
            }
            
            $dates_processed[$file_date] = true;
            
            // 读取文件
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!$lines) continue;
            
            // 获取该日期的数据库
            $pdo = getDBByDate($file_date);
            if (!$pdo) continue;
            
            // 批量插入（包含 visit_hour）
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO spider_visits (visit_time, ip, spider_type, spider_name, url, domain, visit_hour) VALUES (?, ?, ?, ?, ?, ?, ?)');
            
            $batch_count = 0;
            foreach ($lines as $line) {
                $total_records++;
                
                // 解析日志行: 2025-12-30 10:25:00--192.168.1.1--百度PC--http://example.com/page
                $parts = explode('--', $line, 4);
                if (count($parts) < 4) continue;
                
                $visit_time = trim($parts[0]);
                $ip = trim($parts[1]);
                $spider_name = trim($parts[2]);
                $url = trim($parts[3]);
                
                // 计算 visit_hour
                $visit_hour = (int)date('H', strtotime($visit_time));
                
                // 提取域名
                $parsed = parse_url($url);
                $full_domain = isset($parsed['host']) ? $parsed['host'] : '';
                $top_domain = extractTopDomain($full_domain);
                
                if (empty($top_domain)) continue;
                
                try {
                    $stmt->execute(array(
                        $visit_time,
                        $ip,
                        $spider_info['type'],
                        $spider_name,
                        $url,
                        $top_domain,
                        $visit_hour
                    ));
                    $total_inserted++;
                    $batch_count++;
                    $dir_records++;
                    
                    // 每1000条提交一次
                    if ($batch_count >= 1000) {
                        $pdo->commit();
                        $pdo->beginTransaction();
                        $batch_count = 0;
                    }
                } catch (Exception $e) {
                    // 忽略插入错误（可能是重复数据）
                }
            }
            
            $pdo->commit();
        }
        
        if ($dir_records > 0) {
            output("  → 导入 " . number_format($dir_records) . " 条记录", 'success');
        }
    }
    
    if ($total_files > 0) {
        output("日志迁移完成", 'success');
        output("  处理文件: " . number_format($total_files) . " 个");
        output("  读取记录: " . number_format($total_records) . " 条");
        output("  成功导入: " . number_format($total_inserted) . " 条");
    } else {
        output("没有找到日志文件", 'warning');
    }
}

// ========================
// 步骤3: 优化数据库
// ========================
output('步骤3: 优化数据库', 'title');

foreach ($db_connections as $date => $pdo) {
    try {
        $pdo->exec('VACUUM');
        $pdo->exec('ANALYZE');
    } catch (Exception $e) {
        // 忽略
    }
}

// 关闭连接
$db_connections = array();

// 最终统计
output('迁移完成', 'title');

$final_dbs = glob($db_dir . '/*.db');
$total_size = 0;
foreach ($final_dbs as $f) {
    $total_size += filesize($f);
}

output("数据库文件: " . count($final_dbs) . " 个", 'success');
output("总大小: " . round($total_size / 1024 / 1024, 2) . " MB", 'success');
output("结束时间: " . date('Y-m-d H:i:s'));

// 列出数据库文件
if (!empty($final_dbs)) {
    output('数据库文件列表:', 'title');
    foreach ($final_dbs as $f) {
        $size = round(filesize($f) / 1024 / 1024, 2);
        output("  " . basename($f) . " ({$size} MB)");
    }
}

if (!$is_cli) {
    echo '</div>';
    echo '<p style="margin-top:20px;"><a href="tongji.php" style="color:#4CAF50;font-size:16px;">✅ 迁移完成，点击返回统计后台</a></p>';
    echo '</body></html>';
}
