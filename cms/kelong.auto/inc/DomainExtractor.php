<?php
/**
 * 域名提取工具类
 * 用于从泛二级域名中提取主域名
 */
class DomainExtractor {
    private $suffixes = [];
    private static $instance = null;
    
    public function __construct() {
        $this->loadDomainSuffixes();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 加载域名后缀列表
     */
    private function loadDomainSuffixes() {
        $suffixFile = __DIR__ . '/domain_suffixes.json';
        if (file_exists($suffixFile)) {
            $data = json_decode(file_get_contents($suffixFile), true);
            $this->suffixes = $data['suffixes'] ?? [];
            
            // 按长度降序排序，确保匹配最长的后缀
            usort($this->suffixes, function($a, $b) {
                return strlen($b) - strlen($a);
            });
        }
    }
    
    /**
     * 从泛二级域名提取主域名
     * @param string $host 完整域名（如：abc.example.com）
     * @return string 主域名（如：example.com）
     */
    public function extractTopDomain($host) {
        // 移除端口号
        $host = strtolower(trim($host));
        if (strpos($host, ':') !== false) {
            $host = substr($host, 0, strpos($host, ':'));
        }
        
        // 使用后缀列表精确匹配
        foreach ($this->suffixes as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                // 找到匹配的后缀
                $withoutSuffix = substr($host, 0, -strlen($suffix));
                $parts = explode('.', $withoutSuffix);
                
                if (count($parts) >= 1) {
                    // 取最后一个部分作为主域名
                    $topDomain = array_pop($parts) . $suffix;
                    return $topDomain;
                }
            }
        }
        
        // 如果没有匹配的后缀，使用简单分割法
        $parts = explode('.', $host);
        if (count($parts) >= 2) {
            // 返回最后两个部分
            return implode('.', array_slice($parts, -2));
        }
        
        return $host; // 如果无法分割，返回原域名
    }
    
    /**
     * 检查是否为子域名
     * @param string $host 完整域名
     * @return array ['is_subdomain' => bool, 'top_domain' => string, 'subdomain' => string]
     */
    public function analyzeSubdomain($host) {
        $topDomain = $this->extractTopDomain($host);
        $isSubdomain = ($host !== $topDomain);
        
        return [
            'is_subdomain' => $isSubdomain,
            'top_domain' => $topDomain,
            'subdomain' => $host,
            'prefix' => $isSubdomain ? substr($host, 0, -strlen($topDomain) - 1) : '' // abc
        ];
    }
    
    /**
     * 获取支持的后缀数量（用于调试）
     */
    public function getSuffixCount() {
        return count($this->suffixes);
    }
    
    /**
     * 测试域名提取（用于调试）
     */
    public function testExtraction($testDomains) {
        $results = [];
        foreach ($testDomains as $domain) {
            $results[$domain] = $this->analyzeSubdomain($domain);
        }
        return $results;
    }
}