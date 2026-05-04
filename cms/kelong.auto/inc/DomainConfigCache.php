<?php
/**
 * 域名配置缓存类
 * 使用APCu内存缓存加速域名配置读取
 * 
 * 特性：
 * - APCu可用时：使用内存缓存（0.01ms响应）
 * - APCu不可用时：自动降级为直接读文件（2ms响应）
 * - 透明切换，无需修改调用代码
 */
class DomainConfigCache {
    
    /**
     * APCu是否可用（缓存检测结果）
     */
    private static $apcu_available = null;
    
    /**
     * 统计信息
     */
    private static $stats = [
        'hits' => 0,      // 缓存命中次数
        'misses' => 0,    // 缓存未命中次数
        'reads' => 0      // 文件读取次数
    ];
    
    /**
     * 检测APCu是否可用
     * @return bool
     */
    private static function isAPCuAvailable() {
        if (self::$apcu_available === null) {
            self::$apcu_available = 
                extension_loaded('apcu') && 
                function_exists('apcu_fetch') && 
                function_exists('apcu_store') &&
                ini_get('apc.enabled');
        }
        return self::$apcu_available;
    }
    
    /**
     * 获取域名配置（带缓存）
     * 
     * @param string $domain 域名（如：xiu1.com.cn）
     * @return array|null 配置数组，不存在返回null
     */
    public static function get($domain) {
        $cacheKey = 'domain_config_' . $domain;
        
        // 1️⃣ 尝试从APCu内存缓存读取（微秒级）
        if (self::isAPCuAvailable()) {
            $config = apcu_fetch($cacheKey, $success);
            if ($success) {
                self::$stats['hits']++;
                return $config;
            }
            self::$stats['misses']++;
        }
        
        // 2️⃣ 缓存未命中或APCu不可用，读取JSON文件
        $configFile = KELONG_DOMAIN_DIR . '/' . $domain . '.json';
        
        if (!file_exists($configFile)) {
            return null;
        }
        
        $jsonContent = @file_get_contents($configFile);
        if ($jsonContent === false) {
            return null;
        }
        
        $config = json_decode($jsonContent, true);
        if ($config === null || json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        
        self::$stats['reads']++;
        
        // 3️⃣ 存入APCu缓存（60秒过期），下次访问更快
        if (self::isAPCuAvailable()) {
            apcu_store($cacheKey, $config, 60);
        }
        
        return $config;
    }
    
    /**
     * 保存域名配置（同时更新缓存）
     * 
     * @param string $domain 域名
     * @param array $config 配置数组
     * @return bool 成功返回true
     */
    public static function set($domain, $config) {
        // 1. 保存到文件
        $configFile = KELONG_DOMAIN_DIR . '/' . $domain . '.json';
        $jsonContent = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        $result = @file_put_contents($configFile, $jsonContent);
        if ($result === false) {
            return false;
        }
        
        // 2. 更新缓存
        if (self::isAPCuAvailable()) {
            $cacheKey = 'domain_config_' . $domain;
            apcu_store($cacheKey, $config, 60);
        }
        
        return true;
    }
    
    /**
     * 清除指定域名的缓存
     * 
     * @param string $domain 域名
     * @return bool
     */
    public static function clear($domain) {
        if (self::isAPCuAvailable()) {
            $cacheKey = 'domain_config_' . $domain;
            return apcu_delete($cacheKey);
        }
        return true;
    }
    
    /**
     * 批量清除缓存
     * 
     * @param array $domains 域名数组
     * @return int 清除的数量
     */
    public static function clearBatch($domains) {
        if (!self::isAPCuAvailable()) {
            return 0;
        }
        
        $count = 0;
        foreach ($domains as $domain) {
            if (self::clear($domain)) {
                $count++;
            }
        }
        return $count;
    }
    
    /**
     * 清除所有域名配置缓存
     * 
     * @return bool
     */
    public static function clearAll() {
        if (self::isAPCuAvailable()) {
            // 使用APCUIterator清除所有以 domain_config_ 开头的缓存
            try {
                if (class_exists('APCUIterator')) {
                    $iterator = new APCUIterator('/^domain_config_/');
                    return apcu_delete($iterator);
                } else {
                    // 降级方案：清除整个APCu缓存
                    return apcu_clear_cache();
                }
            } catch (Exception $e) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * 预热缓存：批量加载配置到内存
     * 
     * @param array $domains 域名数组
     * @return int 成功加载的数量
     */
    public static function warmup($domains) {
        if (!self::isAPCuAvailable()) {
            return 0;
        }
        
        $count = 0;
        foreach ($domains as $domain) {
            $config = self::get($domain);
            if ($config !== null) {
                $count++;
            }
        }
        return $count;
    }
    
    /**
     * 获取统计信息
     * 
     * @return array
     */
    public static function getStats() {
        $stats = self::$stats;
        $stats['apcu_available'] = self::isAPCuAvailable();
        
        if ($stats['hits'] + $stats['misses'] > 0) {
            $stats['hit_rate'] = round(
                $stats['hits'] / ($stats['hits'] + $stats['misses']) * 100, 
                2
            );
        } else {
            $stats['hit_rate'] = 0;
        }
        
        return $stats;
    }
    
    /**
     * 重置统计信息
     */
    public static function resetStats() {
        self::$stats = [
            'hits' => 0,
            'misses' => 0,
            'reads' => 0
        ];
    }
    
    /**
     * 获取APCu缓存信息
     * 
     * @return array|null
     */
    public static function getCacheInfo() {
        if (!self::isAPCuAvailable()) {
            return null;
        }
        
        try {
            $info = apcu_cache_info();
            return [
                'memory_size' => round($info['mem_size'] / 1024 / 1024, 2) . ' MB',
                'used_memory' => round(($info['mem_size'] - $info['avail_mem']) / 1024 / 1024, 2) . ' MB',
                'num_entries' => $info['num_entries'],
                'num_hits' => $info['num_hits'] ?? 0,
                'num_misses' => $info['num_misses'] ?? 0,
                'start_time' => date('Y-m-d H:i:s', $info['start_time'] ?? 0)
            ];
        } catch (Exception $e) {
            return null;
        }
    }
}
