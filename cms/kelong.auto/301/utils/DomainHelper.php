<?php
/**
 * 域名处理工具类
 */

namespace Redirect301\Utils;

class DomainHelper
{
    private static $doubleSuffixes = null;
    private static $singleSuffixes = null;
    
    /**
     * 初始化域名后缀列表
     */
    private static function initSuffixes()
    {
        if (self::$doubleSuffixes === null) {
            $suffixes = require REDIRECT301_ROOT . '/domain_suffixes.php';
            self::$doubleSuffixes = $suffixes['double_suffixes'];
            self::$singleSuffixes = $suffixes['single_suffixes'];
        }
    }
    
    /**
     * 从主机名中提取顶级域名
     * 例如：www.example.com.cn → example.com.cn
     *       abc.example.com → example.com
     * 
     * @param string $host 主机名
     * @return string 顶级域名
     */
    public static function extractTopDomain($host)
    {
        self::initSuffixes();
        
        $host = strtolower($host);
        
        // 移除端口号
        if (strpos($host, ':') !== false) {
            $host = explode(':', $host)[0];
        }
        
        // 如果是IP地址，直接返回
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }
        
        // 检查双后缀（如 .com.cn）
        foreach (self::$doubleSuffixes as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                $beforeSuffix = substr($host, 0, -strlen($suffix));
                $parts = explode('.', $beforeSuffix);
                return end($parts) . $suffix;
            }
        }
        
        // 检查单后缀（如 .com）
        foreach (self::$singleSuffixes as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                $beforeSuffix = substr($host, 0, -strlen($suffix));
                $parts = explode('.', $beforeSuffix);
                return end($parts) . $suffix;
            }
        }
        
        // 默认处理：取最后两段
        $parts = explode('.', $host);
        if (count($parts) >= 2) {
            return $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
        }
        
        return $host;
    }
    
    /**
     * 提取二级域名前缀
     * 例如：abc.example.com → abc
     *       www.example.com → www
     *       example.com → null（没有前缀）
     * 
     * @param string $host 主机名
     * @return string|null 二级域名前缀，如果没有则返回null
     */
    public static function extractSubdomainPrefix($host)
    {
        $host = strtolower($host);
        
        // 移除端口号
        if (strpos($host, ':') !== false) {
            $host = explode(':', $host)[0];
        }
        
        // 获取顶级域名
        $topDomain = self::extractTopDomain($host);
        
        // 如果主机名和顶级域名相同，说明没有前缀
        if ($host === $topDomain) {
            return null;
        }
        
        // 提取前缀部分
        $prefixPart = substr($host, 0, -(strlen($topDomain) + 1)); // +1 是为了去掉点号
        
        return $prefixPart ?: null;
    }
    
    /**
     * 匹配域名（支持通配符和占位符）
     * 
     * @param string $pattern 域名模式（可能包含占位符 {xxx}）
     * @param string $host 要匹配的主机名
     * @return bool 是否匹配
     */
    public static function matchDomain($pattern, $host)
    {
        $originalPattern = $pattern;
        $pattern = strtolower(trim($pattern));
        $host = strtolower(trim($host));
        
        // 检查是否包含占位符
        $hasPlaceholder = strpos($originalPattern, '{') !== false;
        
        if ($hasPlaceholder) {
            // 如果包含占位符，需要精确匹配结构
            // 例如：{param}.example.com 应该匹配 abc.example.com，但不匹配 example.com
            
            // 将占位符替换为正则表达式模式
            $regex = preg_quote($pattern, '/');
            // 将 \{xxx\} 替换为匹配任意非点字符的模式
            $regex = preg_replace('/\\\{[^}]+\\\}/', '[^.]+', $regex);
            $regex = '/^' . $regex . '$/';
            
            return preg_match($regex, $host) === 1;
        } else {
            // 没有占位符，使用精确匹配或子域名匹配
            // 移除占位符（如果有）
            $pattern = preg_replace('/{[^}]+}\.?/', '', $pattern);
            $pattern = strtolower(trim($pattern));
            
            if (empty($pattern)) {
                return false;
            }
            
            // 1. 精确匹配
            if ($host === $pattern) {
                return true;
            }
            
            // 2. 子域名匹配：host 是 pattern 的子域名
            // 例如：pattern = example.com，可以匹配 www.example.com, abc.example.com
            // 但不能匹配 other.com 或 example.org
            if (substr($host, -(strlen($pattern) + 1)) === '.' . $pattern) {
                return true;
            }
            
            // 3. www 兼容性：www.example.com 和 example.com 互相匹配
            if (strpos($host, 'www.') === 0 && substr($host, 4) === $pattern) {
                return true;
            }
            if (strpos($pattern, 'www.') === 0 && substr($pattern, 4) === $host) {
                return true;
            }
            
            return false;
        }
    }
    
    /**
     * 从域名列表中查找匹配的域名
     * 
     * @param array $domains 域名列表
     * @param string $host 要匹配的主机名
     * @return array|null 匹配的域名配置，如果没有匹配则返回null
     */
    public static function findMatchingDomain(array $domains, $host)
    {
        $host = strtolower($host);
        
        foreach ($domains as $domain) {
            $domainStr = is_array($domain) ? ($domain['domain'] ?? '') : $domain;
            
            if (self::matchDomain($domainStr, $host)) {
                return $domain;
            }
        }
        
        return null;
    }
    
    /**
     * 清理域名（移除占位符）
     * 
     * @param string $domain 域名
     * @return string 清理后的域名
     */
    public static function cleanDomain($domain)
    {
        $domain = preg_replace('/{[^}]+}\.?/', '', $domain);
        return strtolower(trim($domain));
    }
    
    /**
     * 判断是否是完整URL（包含协议）
     * 
     * @param string $str 字符串
     * @return bool 是否是完整URL
     */
    public static function isFullUrl($str)
    {
        return (strpos($str, 'http://') === 0 || 
                strpos($str, 'https://') === 0 || 
                strpos($str, '{') !== false);
    }
    
    /**
     * 从URL中提取主机名
     * 
     * @param string $url URL
     * @return string|null 主机名，如果解析失败则返回null
     */
    public static function extractHostFromUrl($url)
    {
        $parsed = parse_url($url);
        return $parsed['host'] ?? null;
    }
    
    /**
     * 从URL中提取路径
     * 
     * @param string $url URL
     * @return string 路径，默认为 '/'
     */
    public static function extractPathFromUrl($url)
    {
        $parsed = parse_url($url);
        return $parsed['path'] ?? '/';
    }
}

