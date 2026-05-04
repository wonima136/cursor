<?php
/**
 * 蜘蛛IP验证类
 * 用于验证百度和谷歌蜘蛛的真实性
 * 支持Cloudflare CDN环境
 */

// 引入CF IP助手
require_once __DIR__ . '/cf_ip_helper.php';

class SpiderIPVerify {
    private static $ip_list = null;
    
    /**
     * 加载真实蜘蛛IP列表
     */
    private static function loadIPList() {
        if (self::$ip_list === null) {
            $ip_file = __DIR__ . '/ip.txt';
            if (file_exists($ip_file)) {
                $content = file_get_contents($ip_file);
                $ips = explode("\n", trim($content));
                self::$ip_list = array();
                
                foreach ($ips as $ip) {
                    $ip = trim($ip);
                    if (!empty($ip) && strpos($ip, '.') !== false) {
                        // 提取IP的前三段 (A.B.C)
                        $parts = explode('.', $ip);
                        if (count($parts) >= 3 && !empty($parts[0]) && !empty($parts[1]) && !empty($parts[2])) {
                            $prefix = $parts[0] . '.' . $parts[1] . '.' . $parts[2];
                            self::$ip_list[] = $prefix;
                        }
                    }
                }
                // 去重并重新索引
                self::$ip_list = array_unique(self::$ip_list);
            } else {
                self::$ip_list = array();
            }
        }
        return self::$ip_list;
    }
    
    /**
     * 验证IP是否为真实蜘蛛IP
     * @param string $ip 要验证的IP地址
     * @return bool 是否为真实蜘蛛IP
     */
    public static function isRealSpiderIP($ip) {
        $ip_list = self::loadIPList();
        
        // 提取访问IP的前三段
        $parts = explode('.', $ip);
        if (count($parts) < 3) {
            return false;
        }
        
        $visitor_prefix = $parts[0] . '.' . $parts[1] . '.' . $parts[2];
        
        // 检查是否在真实蜘蛛IP列表中
        return in_array($visitor_prefix, $ip_list);
    }
    
    /**
     * 验证是否为真实百度蜘蛛
     * @param string $user_agent User-Agent字符串
     * @param string $ip 访问者IP（可能是代理IP）
     * @return bool 是否为真实百度蜘蛛
     */
    public static function isRealBaiduSpider($user_agent, $ip = null) {
        // 首先检查UA是否包含百度蜘蛛标识
        if (!preg_match('/Baiduspider/i', $user_agent)) {
            return false;
        }
        
        // 获取真实IP（支持CF环境）
        $real_ip = $ip ? $ip : CloudflareIPHelper::getRealIP();
        
        // 然后验证IP是否为真实百度蜘蛛IP
        return self::isRealSpiderIP($real_ip);
    }
    
    /**
     * 验证是否为真实谷歌蜘蛛
     * @param string $user_agent User-Agent字符串
     * @param string $ip 访问者IP（可能是代理IP）
     * @return bool 是否为真实谷歌蜘蛛
     */
    public static function isRealGoogleBot($user_agent, $ip = null) {
        // 首先检查UA是否包含谷歌蜘蛛标识
        if (!preg_match('/Googlebot/i', $user_agent)) {
            return false;
        }
        
        // 获取真实IP（支持CF环境）
        $real_ip = $ip ? $ip : CloudflareIPHelper::getRealIP();
        
        // 然后验证IP是否为真实谷歌蜘蛛IP
        return self::isRealSpiderIP($real_ip);
    }
    
    /**
     * 获取加载的IP列表（用于调试）
     * @return array IP前缀列表
     */
    public static function getIPList() {
        return self::loadIPList();
    }
    
    /**
     * 获取IP列表统计信息
     * @return array 统计信息
     */
    public static function getIPStats() {
        $ip_list = self::loadIPList();
        $baidu_count = 0;
        $google_count = 0;
        $other_count = 0;
        
        foreach ($ip_list as $prefix) {
            if (strpos($prefix, '66.249.') === 0) {
                $google_count++;
            } elseif (in_array(substr($prefix, 0, 3), ['180', '220', '123', '116', '111', '61.', '124', '113', '119', '137', '115', '58.', '192'])) {
                $baidu_count++;
            } else {
                $other_count++;
            }
        }
        
        return array(
            'total' => count($ip_list),
            'baidu_estimated' => $baidu_count,
            'google_estimated' => $google_count,
            'other_estimated' => $other_count
        );
    }
}
?>
