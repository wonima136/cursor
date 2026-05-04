<?php
namespace Redirect301\Core;

/**
 * 缓存管理器
 * 支持 APCu 和文件缓存
 */
class Cache {
    private $useApcu = false;
    private $cacheDir;
    private $ttl = 3600; // 默认1小时
    
    public function __construct($cacheDir = null, $ttl = 3600) {
        $this->useApcu = function_exists('apcu_fetch') && function_exists('apcu_store');
        $this->cacheDir = $cacheDir ?: __DIR__ . '/../data/cache';
        $this->ttl = $ttl;
        
        if (!$this->useApcu && !is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * 获取缓存
     */
    public function get($key, $default = null) {
        if ($this->useApcu) {
            $value = apcu_fetch($key, $success);
            return $success ? $value : $default;
        }
        
        return $this->getFromFile($key, $default);
    }
    
    /**
     * 设置缓存
     */
    public function set($key, $value, $ttl = null) {
        $ttl = $ttl ?? $this->ttl;
        
        if ($this->useApcu) {
            return apcu_store($key, $value, $ttl);
        }
        
        return $this->setToFile($key, $value, $ttl);
    }
    
    /**
     * 删除缓存
     */
    public function delete($key) {
        if ($this->useApcu) {
            return apcu_delete($key);
        }
        
        return $this->deleteFile($key);
    }
    
    /**
     * 清空所有缓存
     */
    public function clear() {
        if ($this->useApcu) {
            return apcu_clear_cache();
        }
        
        return $this->clearFiles();
    }
    
    /**
     * 检查缓存是否存在
     */
    public function has($key) {
        if ($this->useApcu) {
            return apcu_exists($key);
        }
        
        return $this->fileExists($key);
    }
    
    /**
     * 从文件获取缓存
     */
    private function getFromFile($key, $default = null) {
        $file = $this->getCacheFile($key);
        
        if (!file_exists($file)) {
            return $default;
        }
        
        $data = @file_get_contents($file);
        if ($data === false) {
            return $default;
        }
        
        $cache = @unserialize($data);
        if ($cache === false) {
            return $default;
        }
        
        // 检查是否过期
        if (isset($cache['expire']) && $cache['expire'] > 0 && time() > $cache['expire']) {
            @unlink($file);
            return $default;
        }
        
        return $cache['value'] ?? $default;
    }
    
    /**
     * 设置文件缓存
     */
    private function setToFile($key, $value, $ttl) {
        $file = $this->getCacheFile($key);
        $dir = dirname($file);
        
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        
        $cache = [
            'value' => $value,
            'expire' => $ttl > 0 ? time() + $ttl : 0,
            'created' => time()
        ];
        
        $data = serialize($cache);
        return @file_put_contents($file, $data, LOCK_EX) !== false;
    }
    
    /**
     * 删除文件缓存
     */
    private function deleteFile($key) {
        $file = $this->getCacheFile($key);
        if (file_exists($file)) {
            return @unlink($file);
        }
        return true;
    }
    
    /**
     * 清空文件缓存
     */
    private function clearFiles() {
        if (!is_dir($this->cacheDir)) {
            return true;
        }
        
        $files = glob($this->cacheDir . '/*.cache');
        foreach ($files as $file) {
            @unlink($file);
        }
        
        return true;
    }
    
    /**
     * 检查文件缓存是否存在
     */
    private function fileExists($key) {
        $file = $this->getCacheFile($key);
        
        if (!file_exists($file)) {
            return false;
        }
        
        // 检查是否过期
        $data = @file_get_contents($file);
        if ($data === false) {
            return false;
        }
        
        $cache = @unserialize($data);
        if ($cache === false) {
            return false;
        }
        
        if (isset($cache['expire']) && $cache['expire'] > 0 && time() > $cache['expire']) {
            @unlink($file);
            return false;
        }
        
        return true;
    }
    
    /**
     * 获取缓存文件路径
     */
    private function getCacheFile($key) {
        $hash = md5($key);
        $subdir = substr($hash, 0, 2);
        return $this->cacheDir . '/' . $subdir . '/' . $hash . '.cache';
    }
    
    /**
     * 记忆化包装器
     * 如果缓存存在则返回缓存，否则执行回调并缓存结果
     */
    public function remember($key, $callback, $ttl = null) {
        if ($this->has($key)) {
            return $this->get($key);
        }
        
        $value = $callback();
        $this->set($key, $value, $ttl);
        
        return $value;
    }
    
    /**
     * 获取缓存统计信息
     */
    public function stats() {
        if ($this->useApcu && function_exists('apcu_cache_info')) {
            $info = apcu_cache_info();
            return [
                'type' => 'APCu',
                'num_entries' => $info['num_entries'] ?? 0,
                'mem_size' => $info['mem_size'] ?? 0,
                'num_hits' => $info['num_hits'] ?? 0,
                'num_misses' => $info['num_misses'] ?? 0,
            ];
        }
        
        $files = glob($this->cacheDir . '/*/*.cache');
        $totalSize = 0;
        foreach ($files as $file) {
            $totalSize += filesize($file);
        }
        
        return [
            'type' => 'File',
            'num_entries' => count($files),
            'disk_size' => $totalSize,
        ];
    }
}

