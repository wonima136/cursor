<?php
/**
 * 缓存管理器
 * 负责缓存的过期检测和清理
 */

class CacheManager {
    private $base_dir;
    private $cache_dir;
    private $cache_expire_time = 86400;  // 24小时（秒）
    private $domainExtractor;
    
    public function __construct() {
        $this->base_dir = dirname(__DIR__);
        $this->cache_dir = $this->base_dir . '/cachefile_yuan/';
        
        // 确保缓存目录存在
        if (!is_dir($this->cache_dir)) {
            mkdir($this->cache_dir, 0755, true);
        }
        
        // 引入域名提取器
        if (!class_exists('DomainExtractor')) {
            require_once __DIR__ . '/DomainExtractor.php';
        }
        $this->domainExtractor = DomainExtractor::getInstance();
    }
    
    /**
     * 检查缓存是否过期
     * @param string $domain 域名
     * @return bool true=过期，false=有效
     */
    public function isCacheExpired($domain) {
        $domainCacheDir = $this->cache_dir . $domain . '/';
        
        if (!is_dir($domainCacheDir)) {
            return true;  // 缓存目录不存在，视为过期
        }
        
        // 检查访问时间文件
        $accessTimeFile = $domainCacheDir . '.last_access';
        
        if (!file_exists($accessTimeFile)) {
            // 如果没有访问时间文件，检查缓存文件的修改时间
            $indexFile = $domainCacheDir . 'index.html';
            if (file_exists($indexFile)) {
                $lastModified = filemtime($indexFile);
                $age = time() - $lastModified;
                return $age > $this->cache_expire_time;
            }
            return true;
        }
        
        $lastAccess = intval(file_get_contents($accessTimeFile));
        $age = time() - $lastAccess;
        
        return $age > $this->cache_expire_time;
    }
    
    /**
     * 更新缓存访问时间
     * @param string $domain 域名
     */
    public function touchCache($domain) {
        $domainCacheDir = $this->cache_dir . $domain . '/';
        
        if (!is_dir($domainCacheDir)) {
            return false;
        }
        
        $accessTimeFile = $domainCacheDir . '.last_access';
        file_put_contents($accessTimeFile, time());
        
        return true;
    }
    
    /**
     * 删除过期缓存
     * @param string $domain 域名
     */
    public function deleteExpiredCache($domain) {
        $domainCacheDir = $this->cache_dir . $domain . '/';
        
        if (!is_dir($domainCacheDir)) {
            return false;
        }
        
        error_log("[缓存管理] 删除过期缓存: {$domain}");
        
        // 删除目录中的所有文件
        $files = glob($domainCacheDir . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        
        // 删除目录
        @rmdir($domainCacheDir);
        
        return true;
    }
    
    /**
     * 清理所有过期缓存（定时任务）
     */
    public function cleanupExpiredCaches() {
        if (!is_dir($this->cache_dir)) {
            return [];
        }
        
        $deleted = [];
        $dirs = scandir($this->cache_dir);
        
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            
            $domainCacheDir = $this->cache_dir . $dir . '/';
            
            if (!is_dir($domainCacheDir)) {
                continue;
            }
            
            // 检查访问时间
            $accessTimeFile = $domainCacheDir . '.last_access';
            
            if (file_exists($accessTimeFile)) {
                $lastAccess = intval(file_get_contents($accessTimeFile));
                $age = time() - $lastAccess;
                
                if ($age > $this->cache_expire_time) {
                    $this->deleteExpiredCache($dir);
                    $deleted[] = $dir;
                }
            } else {
                // 没有访问时间文件，检查缓存文件修改时间
                $indexFile = $domainCacheDir . 'index.html';
                if (file_exists($indexFile)) {
                    $lastModified = filemtime($indexFile);
                    $age = time() - $lastModified;
                    
                    if ($age > $this->cache_expire_time) {
                        $this->deleteExpiredCache($dir);
                        $deleted[] = $dir;
                    }
                }
            }
        }
        
        return $deleted;
    }
    
    /**
     * 设置缓存过期时间（秒）
     */
    public function setCacheExpireTime($seconds) {
        $this->cache_expire_time = $seconds;
    }
    
    /**
     * 为子域名创建缓存配置文件（动态模式专用）
     * @param string $subdomain 子域名（如：abc.example.com）
     * @param string $topDomain 主域名（如：example.com）
     * @param string $mirrorId 当前使用的克隆源
     * @return bool
     */
    public function createSubdomainCacheConfig($subdomain, $topDomain, $mirrorId) {
        $subdomainCacheDir = $this->cache_dir . $subdomain . '/';
        
        // 创建子域名缓存目录
        if (!is_dir($subdomainCacheDir)) {
            mkdir($subdomainCacheDir, 0755, true);
        }
        
        // 创建配置文件
        $configData = [
            'top_domain' => $topDomain,
            'mirror_id' => $mirrorId,
            'subdomain' => $subdomain,
            'created_at' => date('Y-m-d H:i:s'),
            'last_access' => date('Y-m-d H:i:s'),
            'cache_type' => 'dynamic_top'
        ];
        
        $configFile = $subdomainCacheDir . 'config.json';
        $json = json_encode($configData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        return file_put_contents($configFile, $json) !== false;
    }
    
    /**
     * 获取子域名缓存配置
     * @param string $subdomain 子域名
     * @return array|null
     */
    public function getSubdomainCacheConfig($subdomain) {
        $configFile = $this->cache_dir . $subdomain . '/config.json';
        
        if (!file_exists($configFile)) {
            return null;
        }
        
        $content = file_get_contents($configFile);
        return json_decode($content, true);
    }
    
    /**
     * 更新子域名访问时间
     * @param string $subdomain 子域名
     * @return bool
     */
    public function updateSubdomainAccessTime($subdomain) {
        $configFile = $this->cache_dir . $subdomain . '/config.json';
        
        if (!file_exists($configFile)) {
            return false;
        }
        
        $config = json_decode(file_get_contents($configFile), true);
        $config['last_access'] = date('Y-m-d H:i:s');
        
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return file_put_contents($configFile, $json) !== false;
    }
    
    /**
     * 检查子域名缓存是否存在
     * @param string $subdomain 子域名
     * @return bool
     */
    public function hasSubdomainCache($subdomain) {
        $indexFile = $this->cache_dir . $subdomain . '/index.html';
        return file_exists($indexFile);
    }
    
    /**
     * 保存子域名HTML缓存（不含TDK占位符）
     * @param string $subdomain 子域名
     * @param string $html HTML内容
     * @return bool
     */
    public function saveSubdomainCache($subdomain, $html) {
        $subdomainCacheDir = $this->cache_dir . $subdomain . '/';
        
        if (!is_dir($subdomainCacheDir)) {
            mkdir($subdomainCacheDir, 0755, true);
        }
        
        $indexFile = $subdomainCacheDir . 'index.html';
        return file_put_contents($indexFile, $html) !== false;
    }
    
    /**
     * 获取子域名HTML缓存
     * @param string $subdomain 子域名  
     * @return string|null
     */
    public function getSubdomainCache($subdomain) {
        $indexFile = $this->cache_dir . $subdomain . '/index.html';
        
        if (file_exists($indexFile)) {
            return file_get_contents($indexFile);
        }
        
        return null;
    }
    
    /**
     * 清理子域名缓存
     * @param string $subdomain 子域名
     * @return bool
     */
    public function clearSubdomainCache($subdomain) {
        $subdomainCacheDir = $this->cache_dir . $subdomain . '/';
        
        if (is_dir($subdomainCacheDir)) {
            return $this->deleteDirectory($subdomainCacheDir);
        }
        
        return true;
    }
    
    /**
     * 递归删除目录
     */
    private function deleteDirectory($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        
        return rmdir($dir);
    }
}
