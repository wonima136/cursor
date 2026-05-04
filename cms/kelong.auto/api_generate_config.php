<?php
/**
 * 网站配置生成API接口
 * 用途：供前置重定向程序调用，生成域名配置信息
 * 
 * 请求方式：GET/POST
 * 参数：
 *   - domain: 域名（必填）
 *   - mode: 生成模式 top|independent（可选，默认top）
 *   - format: 返回格式 json|text（可选，默认json）
 * 
 * 返回格式（JSON）：
 * {
 *   "success": true,
 *   "domain": "example.com",
 *   "config": {
 *     "target_domain": "www.target.com",
 *     "target_title": "目标站点标题",
 *     "site_title": "网站标题",
 *     "site_keywords": "关键词1,关键词2",
 *     "site_description": "网站描述",
 *     "update_home": "1",
 *     "debug_mode": "0",
 *     "other_config": "hhnnseo",
 *     "jianti": "0"
 *   },
 *   "config_file": "/path/to/config.txt",
 *   "generated": true,
 *   "message": "配置已生成"
 * }
 */

// 禁止直接浏览器访问（可选安全措施）
// if (!isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
//     http_response_code(403);
//     die('Direct access forbidden');
// }

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

// 错误处理
error_reporting(E_ALL);
ini_set('display_errors', 0);

/**
 * 返回JSON响应
 */
function apiResponse($success, $data = [], $message = '', $httpCode = 200) {
    http_response_code($httpCode);
    
    $response = [
        'success' => $success,
        'message' => $message,
        'timestamp' => time(),
        'datetime' => date('Y-m-d H:i:s')
    ];
    
    if ($success) {
        $response = array_merge($response, $data);
    } else {
        $response['error'] = $data;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * 返回文本格式响应
 */
function apiResponseText($success, $configContent = '', $message = '') {
    header('Content-Type: text/plain; charset=utf-8');
    
    if ($success) {
        echo $configContent;
    } else {
        echo "ERROR: {$message}";
    }
    exit;
}

try {
    // 获取参数
    $domain = isset($_REQUEST['domain']) ? trim($_REQUEST['domain']) : '';
    $mode = isset($_REQUEST['mode']) ? trim($_REQUEST['mode']) : 'top';
    $format = isset($_REQUEST['format']) ? trim($_REQUEST['format']) : 'json';
    
    // 参数验证
    if (empty($domain)) {
        apiResponse(false, ['code' => 'MISSING_DOMAIN'], '缺少domain参数', 400);
    }
    
    // 域名格式验证
    if (!preg_match('/^[a-zA-Z0-9][-a-zA-Z0-9]{0,62}(\.[a-zA-Z0-9][-a-zA-Z0-9]{0,62})+$/', $domain)) {
        apiResponse(false, ['code' => 'INVALID_DOMAIN'], '域名格式不正确', 400);
    }
    
    // 模式验证
    if (!in_array($mode, ['top', 'independent'])) {
        $mode = 'top';
    }
    
    // 加载配置生成器
    require_once __DIR__ . '/inc/DomainConfigGenerator.php';
    $generator = new DomainConfigGenerator();
    
    // 检查配置是否已存在
    $configExists = $generator->configExists($domain, $mode);
    $isGenerated = false;
    
    if (!$configExists) {
        // 配置不存在，生成新配置
        $result = $generator->generateConfig($domain, $mode);
        
        if (!$result['success']) {
            apiResponse(false, [
                'code' => 'GENERATE_FAILED',
                'detail' => $result['error']
            ], '配置生成失败', 500);
        }
        
        $isGenerated = true;
        $configFile = $result['file'];
        $effectiveDomain = $result['domain'];
    } else {
        // 配置已存在，读取现有配置
        $isGenerated = false;
        
        if ($mode === 'independent') {
            $effectiveDomain = $domain;
        } else {
            $effectiveDomain = $generator->extractTopDomain($domain);
        }
        
        $configFile = __DIR__ . '/data/domain/' . $effectiveDomain . '.txt';
    }
    
    // 读取配置文件
    if (!file_exists($configFile)) {
        apiResponse(false, [
            'code' => 'CONFIG_NOT_FOUND',
            'file' => $configFile
        ], '配置文件不存在', 404);
    }
    
    $configLines = file($configFile, FILE_IGNORE_NEW_LINES);
    
    // 验证配置格式
    if (count($configLines) < 8) {
        apiResponse(false, [
            'code' => 'INVALID_CONFIG',
            'lines' => count($configLines)
        ], '配置文件格式错误', 500);
    }
    
    // 解析配置内容
    $configData = [
        'target_domain' => $configLines[0],           // 目标域名
        'target_keywords' => $configLines[1],         // 目标关键词
        'replace_keywords' => $configLines[2],        // 替换关键词
        'update_home' => $configLines[3],             // 是否更新首页
        'debug_mode' => $configLines[4],              // 调试模式
        'site_title' => $configLines[5],              // 网站标题
        'site_keywords' => $configLines[6],           // 网站关键词
        'site_description' => $configLines[7],        // 网站描述
        'other_config' => isset($configLines[8]) ? $configLines[8] : 'hhnnseo',  // 其他配置
        'jianti' => isset($configLines[9]) ? $configLines[9] : '0'  // 简繁体
    ];
    
    // 提取目标站点标题（从target_keywords中）
    $targetKeywordsParts = explode(',', $configLines[1]);
    $targetTitle = isset($targetKeywordsParts[0]) ? $targetKeywordsParts[0] : '';
    
    // 根据format返回不同格式
    if ($format === 'text') {
        // 返回原始文本格式
        apiResponseText(true, implode("\n", $configLines), '配置获取成功');
    } else {
        // 返回JSON格式
        apiResponse(true, [
            'domain' => $domain,
            'effective_domain' => $effectiveDomain,
            'mode' => $mode,
            'config' => $configData,
            'config_file' => $configFile,
            'file_size' => filesize($configFile),
            'file_mtime' => date('Y-m-d H:i:s', filemtime($configFile)),
            'generated' => $isGenerated,
            'exists' => $configExists
        ], $isGenerated ? '配置已生成' : '配置已存在');
    }
    
} catch (Exception $e) {
    // 异常处理
    error_log('API Error: ' . $e->getMessage());
    
    apiResponse(false, [
        'code' => 'INTERNAL_ERROR',
        'detail' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], '服务器内部错误', 500);
}

