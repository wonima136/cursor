<?php
/**
 * Cloudflare真实IP获取助手类
 * 支持普通域名和CF加速域名的真实IP检测
 */
class CloudflareIPHelper {
    
    /**
     * Cloudflare IP段列表 (IPv4)
     * 这些是CF的边缘服务器IP段，需要定期更新
     */
    private static $cf_ip_ranges = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22'
    ];
    
    /**
     * 获取真实访问者IP地址
     * 优先级：CF-Connecting-IP > X-Forwarded-For > X-Real-IP > REMOTE_ADDR
     * 
     * @return string 真实IP地址
     */
    public static function getRealIP() {
        // 1. 检查CF-Connecting-IP (Cloudflare专用头)
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
            if (self::isValidIP($ip)) {
                return $ip;
            }
        }
        
        // 2. 检查X-Forwarded-For (通用代理头)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            foreach ($ips as $ip) {
                $ip = trim($ip);
                if (self::isValidIP($ip) && !self::isPrivateIP($ip)) {
                    return $ip;
                }
            }
        }
        
        // 3. 检查X-Real-IP
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
            if (self::isValidIP($ip)) {
                return $ip;
            }
        }
        
        // 4. 检查CF-RAY头确认是否通过CF
        $is_cf = !empty($_SERVER['HTTP_CF_RAY']);
        
        // 5. 最后使用REMOTE_ADDR
        $remote_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        
        // 如果检测到CF但没有CF-Connecting-IP，记录警告（已禁用）
        // if ($is_cf && empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        //     error_log("CF检测到但缺少CF-Connecting-IP头，使用REMOTE_ADDR: " . $remote_ip);
        // }
        
        return $remote_ip;
    }
    
    /**
     * 检查IP是否有效
     * 
     * @param string $ip IP地址
     * @return bool
     */
    private static function isValidIP($ip) {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
    
    /**
     * 检查是否为私有IP
     * 
     * @param string $ip IP地址
     * @return bool
     */
    private static function isPrivateIP($ip) {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    
    /**
     * 检查当前请求是否通过Cloudflare
     * 
     * @return bool
     */
    public static function isCloudflareRequest() {
        // 检查CF特有的头信息
        $cf_headers = [
            'HTTP_CF_RAY',
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CF_IPCOUNTRY',
            'HTTP_CF_VISITOR'
        ];
        
        foreach ($cf_headers as $header) {
            if (!empty($_SERVER[$header])) {
                return true;
            }
        }
        
        // 检查REMOTE_ADDR是否为CF IP段
        $remote_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        return self::isCloudflareIP($remote_ip);
    }
    
    /**
     * 检查IP是否属于Cloudflare
     * 
     * @param string $ip IP地址
     * @return bool
     */
    public static function isCloudflareIP($ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        
        $ip_long = ip2long($ip);
        if ($ip_long === false) {
            return false;
        }
        
        foreach (self::$cf_ip_ranges as $range) {
            if (self::ipInRange($ip_long, $range)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查IP是否在指定范围内
     * 
     * @param int $ip_long IP的长整型表示
     * @param string $range CIDR格式的IP范围
     * @return bool
     */
    private static function ipInRange($ip_long, $range) {
        list($subnet, $mask) = explode('/', $range);
        $subnet_long = ip2long($subnet);
        $mask_long = -1 << (32 - (int)$mask);
        
        return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
    }
    
    /**
     * 获取详细的IP信息（用于调试）
     * 
     * @return array
     */
    public static function getIPDebugInfo() {
        return [
            'real_ip' => self::getRealIP(),
            'remote_addr' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
            'cf_connecting_ip' => isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : '',
            'x_forwarded_for' => isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '',
            'x_real_ip' => isset($_SERVER['HTTP_X_REAL_IP']) ? $_SERVER['HTTP_X_REAL_IP'] : '',
            'cf_ray' => isset($_SERVER['HTTP_CF_RAY']) ? $_SERVER['HTTP_CF_RAY'] : '',
            'cf_ipcountry' => isset($_SERVER['HTTP_CF_IPCOUNTRY']) ? $_SERVER['HTTP_CF_IPCOUNTRY'] : '',
            'is_cloudflare' => self::isCloudflareRequest(),
            'is_cf_ip' => self::isCloudflareIP(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''
        ];
    }
    
    /**
     * 更新Cloudflare IP段列表
     * 可以从CF官方API获取最新的IP段
     * 
     * @return bool 更新是否成功
     */
    public static function updateCloudflareIPRanges() {
        try {
            // CF官方IPv4 IP段API
            $url = 'https://www.cloudflare.com/ips-v4';
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'Mozilla/5.0 (compatible; Spider IP Checker)'
                ]
            ]);
            
            $content = @file_get_contents($url, false, $context);
            if ($content === false) {
                // error_log("无法获取CF IP段列表");
                return false;
            }
            
            $ranges = array_filter(array_map('trim', explode("\n", $content)));
            if (count($ranges) > 0) {
                // 保存到文件
                $cache_file = __DIR__ . '/cf_ip_ranges.json';
                file_put_contents($cache_file, json_encode([
                    'updated_at' => date('Y-m-d H:i:s'),
                    'ranges' => $ranges
                ]));
                
                // error_log("CF IP段列表已更新，共 " . count($ranges) . " 个段");
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            // error_log("更新CF IP段失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 从缓存文件加载CF IP段
     * 
     * @return bool 加载是否成功
     */
    public static function loadCachedIPRanges() {
        $cache_file = __DIR__ . '/cf_ip_ranges.json';
        if (file_exists($cache_file)) {
            $data = json_decode(file_get_contents($cache_file), true);
            if ($data && isset($data['ranges'])) {
                self::$cf_ip_ranges = $data['ranges'];
                return true;
            }
        }
        return false;
    }
}

// 自动加载缓存的IP段
CloudflareIPHelper::loadCachedIPRanges();
