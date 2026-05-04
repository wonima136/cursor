<?php
/**
 * 蜘蛛验证模块（通用）
 * 
 * 功能：
 * 1. 白名单 UA 检查（最高优先级）
 * 2. 蜘蛛类型识别（百度、谷歌、搜狗）
 * 3. IP 真实性验证（ABC 段匹配）
 * 4. 假冒爬虫处理（返回 404）
 * 
 * 使用方式：
 * $validator = new SpiderValidator($redis);
 * $result = $validator->validate($spiderConfig);
 * if (!$result['valid']) {
 *     SpiderValidator::return404();
 * }
 */

namespace Redirect301\Utils;

use Redirect301\Utils\SpiderDetector;

class SpiderValidator {
    
    // 白名单 UA 关键词
    const WHITELIST_UA = 'seo in my life';
    
    // IP 白名单文件路径（相对于项目根目录）
    const IP_WHITELIST_FILE = '/whitelist/ip_whitelist.txt';
    
    // Redis 缓存键
    const REDIS_CACHE_KEY = 'spider:ip_whitelist';
    
    // Redis 缓存过期时间（1小时 = 3600秒）
    const REDIS_CACHE_TTL = 3600;
    
    private $redis;
    private $userAgent;
    private $clientIp;
    
    /**
     * 构造函数
     * 
     * @param Redis|null $redis Redis 连接实例
     */
    public function __construct($redis = null) {
        $this->redis = $redis;
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $this->clientIp = $this->getClientIP();
    }
    
    /**
     * 【静态方法】验证IP是否在白名单（用于全局验证）
     * 
     * @param string $clientIP 客户端IP
     * @return bool IP是否在白名单
     */
    public static function checkIPWhitelist($clientIP) {
        // 提取 ABC 段
        $parts = explode('.', $clientIP);
        if (count($parts) < 3) {
            return false;
        }
        $ipABC = $parts[0] . '.' . $parts[1] . '.' . $parts[2];
        
        // 加载白名单文件
        $whitelistFile = __DIR__ . '/../whitelist/ip_whitelist.txt';
        if (!file_exists($whitelistFile)) {
            return true; // 白名单文件不存在，跳过验证
        }
        
        $whitelist = file($whitelistFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($whitelist)) {
            return true; // 白名单为空，跳过验证
        }
        
        // 匹配 ABC 段
        return in_array($ipABC, $whitelist, true);
    }
    
    /**
     * 【核心方法】验证蜘蛛是否真实
     * 
     * @param array $spiderConfig 后台配置（百度PC、百度移动、谷歌、搜狗）
     * @return array ['valid' => bool, 'type' => string, 'subtype' => string, 'reason' => string]
     */
    public function validate($spiderConfig = []) {
        // Step 1: 白名单 UA 检查（最高优先级）
        if ($this->isWhitelistUA()) {
            error_log("SpiderValidator: 白名单 UA 通过 - {$this->userAgent}");
            return [
                'valid' => true,
                'type' => 'whitelist',
                'subtype' => null,
                'reason' => '白名单 UA'
            ];
        }
        
        // Step 2: 识别蜘蛛类型
        error_log("SpiderValidator: 开始识别蜘蛛 - UA: {$this->userAgent}");
        $spiderInfo = SpiderDetector::detect($this->userAgent);
        $spiderType = $spiderInfo['type'] ?? '';
        $spiderSubtype = $spiderInfo['subtype'] ?? '';
        error_log("SpiderValidator: 识别结果 - Type: {$spiderType}, Subtype: {$spiderSubtype}");
        
        // Step 3: 检查是否是目标蜘蛛（百度、谷歌、搜狗）
        if (!$this->isTargetSpider($spiderType)) {
            error_log("SpiderValidator: 不是目标蜘蛛 - Type: {$spiderType}");
            return [
                'valid' => false,
                'type' => $spiderType,
                'subtype' => $spiderSubtype,
                'reason' => '不是目标蜘蛛'
            ];
        }
        
        // Step 4: 检查后台配置（用户是否启用了该蜘蛛）
        if (!$this->isSpiderEnabled($spiderType, $spiderSubtype, $spiderConfig)) {
            error_log("SpiderValidator: 后台未启用该蜘蛛 - Type: {$spiderType}, Subtype: {$spiderSubtype}");
            return [
                'valid' => false,
                'type' => $spiderType,
                'subtype' => $spiderSubtype,
                'reason' => '后台未启用该蜘蛛'
            ];
        }
        
        // Step 5: IP 真实性验证
        if (!$this->validateIP($spiderType)) {
            error_log("SpiderValidator: IP 不在白名单 - IP: {$this->clientIp}, ABC: " . $this->extractIPABC($this->clientIp));
            return [
                'valid' => false,
                'type' => $spiderType,
                'subtype' => $spiderSubtype,
                'reason' => 'IP 不在白名单'
            ];
        }
        
        // 验证通过
        error_log("SpiderValidator: 验证通过 - Type: {$spiderType}, Subtype: {$spiderSubtype}, IP: {$this->clientIp}");
        return [
            'valid' => true,
            'type' => $spiderType,
            'subtype' => $spiderSubtype,
            'reason' => '真实爬虫'
        ];
    }
    
    /**
     * 检查是否是白名单 UA（不区分大小写）
     * 
     * @return bool
     */
    private function isWhitelistUA() {
        return stripos($this->userAgent, self::WHITELIST_UA) !== false;
    }
    
    /**
     * 检查是否是目标蜘蛛（百度、谷歌、搜狗）
     * 
     * @param string $spiderType 蜘蛛类型
     * @return bool
     */
    private function isTargetSpider($spiderType) {
        $targetSpiders = [
            'baidu_spider',
            'google_spider',
            'sogou_spider'
        ];
        return in_array($spiderType, $targetSpiders);
    }
    
    /**
     * 检查后台配置是否启用了该蜘蛛
     * 
     * @param string $spiderType 蜘蛛类型
     * @param string|null $spiderSubtype 蜘蛛子类型
     * @param array $spiderConfig 后台配置
     * @return bool
     */
    private function isSpiderEnabled($spiderType, $spiderSubtype, $spiderConfig) {
        if (empty($spiderConfig)) {
            return false; // 后台未启用蜘蛛识别
        }
        
        // 百度蜘蛛（PC 或移动）
        if ($spiderType === 'baidu_spider') {
            if ($spiderSubtype === 'baidu_pc') {
                return !empty($spiderConfig['baidu_pc']);
            }
            if ($spiderSubtype === 'baidu_mobile') {
                return !empty($spiderConfig['baidu_mobile']);
            }
            return false;
        }
        
        // 谷歌蜘蛛
        if ($spiderType === 'google_spider') {
            return !empty($spiderConfig['google']);
        }
        
        // 搜狗蜘蛛
        if ($spiderType === 'sogou_spider') {
            return !empty($spiderConfig['sogou']);
        }
        
        return false;
    }
    
    /**
     * 验证 IP 是否在白名单
     * 
     * @param string $spiderType 蜘蛛类型
     * @return bool
     */
    private function validateIP($spiderType) {
        error_log("SpiderValidator: 开始 IP 验证 - 客户端 IP: {$this->clientIp}");
        
        // 提取 IP 的 ABC 段
        $ipABC = $this->extractIPABC($this->clientIp);
        error_log("SpiderValidator: 提取 ABC 段 - ABC: {$ipABC}");
        
        if (empty($ipABC)) {
            error_log("SpiderValidator: 无法提取 IP ABC 段 - IP: {$this->clientIp}");
            return false;
        }
        
        // 加载 IP 白名单（带缓存）
        $whitelist = $this->loadIPWhitelist();
        error_log("SpiderValidator: 白名单加载完成 - 共 " . count($whitelist) . " 条");
        error_log("SpiderValidator: 白名单前3条: " . implode(', ', array_slice($whitelist, 0, 3)));
        error_log("SpiderValidator: 白名单后3条: " . implode(', ', array_slice($whitelist, -3)));
        
        if (empty($whitelist)) {
            error_log("SpiderValidator: IP 白名单为空，跳过 IP 验证");
            return true; // 白名单为空时，跳过验证（向后兼容）
        }
        
        error_log("SpiderValidator: 开始匹配 ABC 段: {$ipABC}");
        
        // 匹配 ABC 段
        $matched = in_array($ipABC, $whitelist, true);
        
        error_log("SpiderValidator: 匹配结果: " . ($matched ? '成功' : '失败'));
        
        if ($matched) {
            error_log("SpiderValidator: IP 匹配成功 - ABC: {$ipABC}");
        } else {
            error_log("SpiderValidator: IP 匹配失败 - ABC: {$ipABC}");
            error_log("SpiderValidator: 白名单样本: " . implode(', ', array_slice($whitelist, 0, 5)));
        }
        
        return $matched;
    }
    
    /**
     * 提取 IP 的 ABC 段
     * 
     * @param string $ip 完整 IP（如 123.125.71.35）
     * @return string ABC 段（如 123.125.71）
     */
    private function extractIPABC($ip) {
        $parts = explode('.', $ip);
        
        if (count($parts) < 3) {
            return '';
        }
        
        return $parts[0] . '.' . $parts[1] . '.' . $parts[2];
    }
    
    /**
     * 加载 IP 白名单（带 Redis 缓存）
     * 
     * @return array IP ABC 段数组
     */
    private function loadIPWhitelist() {
        error_log("SpiderValidator: loadIPWhitelist() 开始");
        
        // 尝试从 Redis 缓存读取
        if ($this->redis) {
            error_log("SpiderValidator: Redis 实例存在，尝试读取缓存");
            try {
                $conn = $this->redis->getConnection();
                if ($conn) {
                    $cached = $conn->get(self::REDIS_CACHE_KEY);
                    error_log("SpiderValidator: Redis get 返回: " . ($cached === false ? 'false' : 'data'));
                    if ($cached !== false) {
                        $whitelist = json_decode($cached, true);
                        if (is_array($whitelist)) {
                            error_log("SpiderValidator: 从 Redis 缓存加载 IP 白名单 - 共 " . count($whitelist) . " 条");
                            return $whitelist;
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("SpiderValidator: Redis 读取失败 - " . $e->getMessage());
            }
        } else {
            error_log("SpiderValidator: Redis 实例不存在，跳过缓存");
        }
        
        // 从文件读取
        $filePath = dirname(__DIR__) . self::IP_WHITELIST_FILE;
        
        if (!file_exists($filePath)) {
            error_log("SpiderValidator: IP 白名单文件不存在 - {$filePath}");
            return [];
        }
        
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $whitelist = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // 跳过注释和空行
            if (empty($line) || $line[0] === '#') {
                continue;
            }
            
            $whitelist[] = $line;
        }
        
        error_log("SpiderValidator: 从文件加载 IP 白名单 - 共 " . count($whitelist) . " 条");
        
        // 缓存到 Redis（1小时）
        if ($this->redis && !empty($whitelist)) {
            error_log("SpiderValidator: 尝试缓存到 Redis");
            try {
                $conn = $this->redis->getConnection();
                if ($conn) {
                    $conn->setex(
                        self::REDIS_CACHE_KEY,
                        self::REDIS_CACHE_TTL,
                        json_encode($whitelist)
                    );
                    error_log("SpiderValidator: IP 白名单已缓存到 Redis - TTL: " . self::REDIS_CACHE_TTL . "秒");
                }
            } catch (Exception $e) {
                error_log("SpiderValidator: Redis 写入失败 - " . $e->getMessage());
            }
        }
        
        error_log("SpiderValidator: loadIPWhitelist() 返回 " . count($whitelist) . " 条");
        return $whitelist;
    }
    
    /**
     * 获取客户端 IP
     * 
     * @return string
     */
    private function getClientIP() {
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                
                // X-Forwarded-For 可能包含多个 IP，取第一个
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                
                // 验证 IP 格式
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }
    
    /**
     * 返回 404 页面（假冒爬虫）
     * 
     * @return void
     */
    public static function return404() {
        http_response_code(404);
        
        echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>404 Not Found</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            text-align: center; 
            padding: 50px; 
            background: #f5f5f5;
        }
        h1 { 
            font-size: 50px; 
            color: #333; 
            margin-bottom: 20px;
        }
        p { 
            font-size: 18px; 
            color: #666; 
        }
    </style>
</head>
<body>
    <h1>404 Not Found</h1>
    <p>The requested URL was not found on this server.</p>
</body>
</html>';
        
        exit;
    }
}

