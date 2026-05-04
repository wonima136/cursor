<?php
/**
 * URL处理工具类
 */

namespace Redirect301\Utils;

class UrlHelper
{
    /**
     * 获取当前完整URL
     * 
     * @return string 当前URL
     */
    public static function getCurrentUrl()
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return $protocol . '://' . $host . $uri;
    }
    
    /**
     * 获取当前协议
     * 
     * @return string 'http' 或 'https'
     */
    public static function getCurrentProtocol()
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    }
    
    /**
     * 获取当前主机名
     * 
     * @return string 主机名
     */
    public static function getCurrentHost()
    {
        return $_SERVER['HTTP_HOST'] ?? '';
    }
    
    /**
     * 获取当前URI
     * 
     * @return string URI
     */
    public static function getCurrentUri()
    {
        return $_SERVER['REQUEST_URI'] ?? '/';
    }
    
    /**
     * 获取客户端真实IP
     * 
     * @return string IP地址
     */
    public static function getClientIp()
    {
        $ip = '';
        
        // 按优先级获取 IP
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // 可能包含多个 IP，取第一个
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return $ip ?: 'unknown';
    }
    
    /**
     * 生成URL的多种变体用于匹配
     * 处理协议、www、参数等差异
     * 
     * @param string $url URL
     * @return array URL变体列表
     */
    public static function getUrlVariants($url)
    {
        $variants = [$url];
        
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host'])) {
            return $variants;
        }
        
        $host = $parsed['host'];
        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        
        // 生成协议变体
        $protocols = ['http', 'https'];
        
        // 生成域名变体（有无 www）
        $hosts = [$host];
        if (strpos($host, 'www.') === 0) {
            $hosts[] = substr($host, 4); // 去掉 www.
        } else {
            $hosts[] = 'www.' . $host; // 加上 www.
        }
        
        // 生成路径变体（有无参数）
        $paths = [$path . $query];
        if ($query) {
            $paths[] = $path; // 不带参数的版本
        }
        
        // 组合所有变体
        foreach ($protocols as $proto) {
            foreach ($hosts as $h) {
                foreach ($paths as $p) {
                    $variant = $proto . '://' . $h . $p;
                    if (!in_array($variant, $variants)) {
                        $variants[] = $variant;
                    }
                }
            }
        }
        
        return $variants;
    }
    
    /**
     * 判断是否为内页
     * 只有纯首页路径才算首页，带参数的算内页
     * 
     * @param string $uri URI路径
     * @return bool 是否为内页
     */
    public static function isInnerPage($uri)
    {
        // 首页路径（必须完全匹配，带参数的算内页）
        $homePages = [
            '/',
            '/index.php',
            '/index.html',
            '/index.htm',
        ];
        
        // 如果URI完全匹配首页路径，则不是内页
        return !in_array($uri, $homePages);
    }
    
    /**
     * 检查URL是否被排除
     * 
     * @param string $uri URI路径
     * @param array $excludeRules 排除规则
     * @return bool 是否被排除
     */
    public static function isExcluded($uri, $excludeRules)
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        
        // 精确匹配
        if (!empty($excludeRules['urls'])) {
            foreach ($excludeRules['urls'] as $url) {
                if ($path === $url || strpos($path, $url) === 0) {
                    return true;
                }
            }
        }
        
        // 正则匹配
        if (!empty($excludeRules['patterns'])) {
            foreach ($excludeRules['patterns'] as $pattern) {
                if (@preg_match($pattern, $path)) {
                    return true;
                }
            }
        }
        
        // 目录匹配
        if (!empty($excludeRules['directories'])) {
            foreach ($excludeRules['directories'] as $dir) {
                $dir = '/' . trim($dir, '/') . '/';
                if (strpos($path, $dir) !== false || strpos($path . '/', $dir) === 0) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * 构建URL
     * 
     * @param string $protocol 协议
     * @param string $host 主机名
     * @param string $path 路径
     * @return string 完整URL
     */
    public static function buildUrl($protocol, $host, $path = '/')
    {
        return $protocol . '://' . $host . $path;
    }
    
    /**
     * 解析URL并返回各部分
     * 
     * @param string $url URL
     * @return array|false 解析结果
     */
    public static function parseUrl($url)
    {
        return parse_url($url);
    }
    
    /**
     * 检查是否是静态资源
     * 
     * @param string $uri URI路径
     * @return bool 是否是静态资源
     */
    public static function isStaticResource($uri)
    {
        $ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
        $staticExts = [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'svg',
            'css', 'js', 'woff', 'woff2', 'ttf', 'eot', 'otf',
            'mp4', 'mp3', 'avi', 'mov', 'flv', 'wmv', 'zip', 'rar', 'pdf'
        ];
        
        return in_array($ext, $staticExts);
    }
    
    /**
     * 检查是否是后台路径
     * 
     * @param string $uri URI路径
     * @return bool 是否是后台路径
     */
    public static function isAdminPath($uri)
    {
        return strpos($uri, '/admin/') === 0 || strpos($uri, '/301/admin/') !== false;
    }
    
    /**
     * 标准化路径（移除多余的斜杠）
     * 
     * @param string $path 路径
     * @return string 标准化后的路径
     */
    public static function normalizePath($path)
    {
        // 移除多余的斜杠
        $path = preg_replace('#/+#', '/', $path);
        
        // 确保以斜杠开头
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        
        return $path;
    }
    
    /**
     * 合并URL和路径
     * 
     * @param string $baseUrl 基础URL
     * @param string $path 路径
     * @return string 合并后的URL
     */
    public static function joinUrl($baseUrl, $path)
    {
        $baseUrl = rtrim($baseUrl, '/');
        $path = ltrim($path, '/');
        
        return $baseUrl . '/' . $path;
    }
}

