<?php
/**
 * API调用示例 - 供前置重定向程序参考
 * 
 * 使用场景：前置重定向程序需要获取域名配置信息
 */

// ========== 配置部分 ==========
$API_URL = 'http://your-main-server.com/api_generate_config.php'; // API地址
$CONFIG_MODE = 'top'; // 配置模式：top（统一）或 independent（独立）

// ========== 函数定义 ==========

/**
 * 获取域名配置
 * @param string $domain 域名
 * @return array|false 配置数组或false
 */
function getDomainConfig($domain) {
    global $API_URL, $CONFIG_MODE;
    
    // 构建请求URL
    $url = $API_URL . '?' . http_build_query([
        'domain' => $domain,
        'mode' => $CONFIG_MODE,
        'format' => 'json'
    ]);
    
    // 发起请求（使用file_get_contents，简单快速）
    $context = stream_context_create([
        'http' => [
            'timeout' => 5, // 5秒超时
            'ignore_errors' => true
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        error_log("API请求失败: {$domain}");
        return false;
    }
    
    // 解析JSON
    $data = json_decode($response, true);
    
    if (!$data || !$data['success']) {
        error_log("API返回失败: {$domain} - " . ($data['message'] ?? 'Unknown error'));
        return false;
    }
    
    return $data;
}

/**
 * 使用cURL获取配置（更稳定，推荐生产环境使用）
 * @param string $domain 域名
 * @return array|false 配置数组或false
 */
function getDomainConfigCurl($domain) {
    global $API_URL, $CONFIG_MODE;
    
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $API_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'domain' => $domain,
            'mode' => $CONFIG_MODE,
            'format' => 'json'
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false || $httpCode != 200) {
        error_log("API请求失败: {$domain} - HTTP {$httpCode} - {$error}");
        return false;
    }
    
    $data = json_decode($response, true);
    
    if (!$data || !$data['success']) {
        error_log("API返回失败: {$domain} - " . ($data['message'] ?? 'Unknown error'));
        return false;
    }
    
    return $data;
}

// ========== 使用示例 ==========

// 示例1：基本使用
function example1_basic() {
    echo "=== 示例1：基本使用 ===\n";
    
    $domain = 'example.com';
    $config = getDomainConfig($domain);
    
    if ($config) {
        echo "✓ 配置获取成功\n";
        echo "目标域名: {$config['config']['target_domain']}\n";
        echo "网站标题: {$config['config']['site_title']}\n";
        echo "网站关键词: {$config['config']['site_keywords']}\n";
        echo "网站描述: {$config['config']['site_description']}\n";
    } else {
        echo "✗ 配置获取失败\n";
    }
    
    echo "\n";
}

// 示例2：前置重定向程序集成
function example2_redirect() {
    echo "=== 示例2：前置重定向程序 ===\n";
    
    // 获取当前访问的域名
    $currentDomain = $_SERVER['HTTP_HOST'] ?? 'test.com';
    echo "当前域名: {$currentDomain}\n";
    
    // 获取配置
    $config = getDomainConfig($currentDomain);
    
    if ($config) {
        // 提取配置信息
        $targetDomain = $config['config']['target_domain'];
        $updateHome = $config['config']['update_home'];
        
        echo "目标域名: {$targetDomain}\n";
        echo "是否更新首页: {$updateHome}\n";
        
        // 根据配置决定是否重定向
        if ($updateHome == '1') {
            echo "执行重定向到: http://{$targetDomain}\n";
            // header("Location: http://{$targetDomain}");
            // exit;
        } else {
            echo "不执行重定向\n";
        }
    } else {
        echo "配置获取失败，执行默认处理\n";
        // header("HTTP/1.1 404 Not Found");
        // exit;
    }
    
    echo "\n";
}

// 示例3：带缓存的配置获取
function example3_with_cache() {
    echo "=== 示例3：带缓存的配置获取 ===\n";
    
    $domain = 'cached-example.com';
    $cacheDir = '/tmp/domain_config_cache';
    $cacheFile = $cacheDir . '/' . md5($domain) . '.json';
    $cacheTime = 3600; // 缓存1小时
    
    // 创建缓存目录
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    
    // 检查缓存
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
        echo "✓ 使用缓存\n";
        $config = json_decode(file_get_contents($cacheFile), true);
    } else {
        echo "✓ 请求API\n";
        $config = getDomainConfig($domain);
        
        if ($config) {
            // 保存缓存
            file_put_contents($cacheFile, json_encode($config));
            echo "✓ 缓存已保存\n";
        }
    }
    
    if ($config) {
        echo "目标域名: {$config['config']['target_domain']}\n";
    }
    
    echo "\n";
}

// 示例4：批量获取配置
function example4_batch() {
    echo "=== 示例4：批量获取配置 ===\n";
    
    $domains = [
        'site1.com',
        'site2.com',
        'www.site3.com'
    ];
    
    foreach ($domains as $domain) {
        $config = getDomainConfig($domain);
        
        if ($config) {
            $status = $config['generated'] ? '新生成' : '已存在';
            echo "{$domain}: {$status} → {$config['config']['target_domain']}\n";
        } else {
            echo "{$domain}: 失败\n";
        }
        
        usleep(100000); // 延迟100ms
    }
    
    echo "\n";
}

// 示例5：错误处理
function example5_error_handling() {
    echo "=== 示例5：错误处理 ===\n";
    
    // 测试无效域名
    $invalidDomain = 'invalid domain!';
    $config = getDomainConfig($invalidDomain);
    
    if (!$config) {
        echo "✓ 正确处理了无效域名\n";
    }
    
    echo "\n";
}

// 示例6：完整的前置重定向程序模板
function example6_full_redirect_template() {
    echo "=== 示例6：完整的前置重定向程序模板 ===\n";
    
    // 1. 获取当前域名
    $currentDomain = $_SERVER['HTTP_HOST'] ?? 'test.com';
    
    // 2. 获取配置（带缓存）
    $cacheFile = '/tmp/config_' . md5($currentDomain) . '.json';
    $cacheTime = 1800; // 缓存30分钟
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
        $config = json_decode(file_get_contents($cacheFile), true);
    } else {
        $config = getDomainConfigCurl($currentDomain);
        if ($config) {
            file_put_contents($cacheFile, json_encode($config));
        }
    }
    
    // 3. 根据配置执行逻辑
    if ($config && $config['success']) {
        $targetDomain = $config['config']['target_domain'];
        $siteTitle = $config['config']['site_title'];
        $siteKeywords = $config['config']['site_keywords'];
        $siteDescription = $config['config']['site_description'];
        
        echo "配置加载成功\n";
        echo "- 当前域名: {$currentDomain}\n";
        echo "- 目标域名: {$targetDomain}\n";
        echo "- 网站标题: {$siteTitle}\n";
        
        // 这里可以执行重定向或其他逻辑
        // 例如：
        // - 302重定向到目标站点
        // - 反向代理到目标站点
        // - 使用配置信息渲染页面
        
    } else {
        echo "配置加载失败，执行默认处理\n";
        // 执行默认逻辑，如显示404页面
    }
    
    echo "\n";
}

// ========== 执行示例 ==========

// 如果直接运行此文件，执行所有示例
if (php_sapi_name() === 'cli') {
    echo "网站配置API调用示例\n";
    echo str_repeat('=', 50) . "\n\n";
    
    // 注意：需要先修改 $API_URL 为实际的API地址
    echo "注意：请先修改 \$API_URL 为实际的API地址\n\n";
    
    // 取消下面的注释来运行示例
    // example1_basic();
    // example2_redirect();
    // example3_with_cache();
    // example4_batch();
    // example5_error_handling();
    // example6_full_redirect_template();
}

// ========== 实际使用时的简化版本 ==========

/**
 * 生产环境推荐使用的简化版本
 */
function getConfig($domain) {
    static $cache = [];
    
    // 内存缓存
    if (isset($cache[$domain])) {
        return $cache[$domain];
    }
    
    // API请求
    $url = 'http://your-api-server.com/api_generate_config.php?' . http_build_query([
        'domain' => $domain,
        'mode' => 'top',
        'format' => 'json'
    ]);
    
    $response = @file_get_contents($url, false, stream_context_create([
        'http' => ['timeout' => 5]
    ]));
    
    if ($response) {
        $data = json_decode($response, true);
        if ($data && $data['success']) {
            $cache[$domain] = $data['config'];
            return $data['config'];
        }
    }
    
    return false;
}

// 使用：
// $config = getConfig('example.com');
// if ($config) {
//     echo $config['site_title'];
// }

