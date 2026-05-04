<?php
namespace Redirect301\Core;

/**
 * 配置管理器
 * 负责加载和管理所有配置
 */
class Config {
    private $cache;
    private $configDir;
    private $configs = [];
    
    public function __construct($configDir = null, ?Cache $cache = null) {
        $this->configDir = $configDir ?: __DIR__ . '/../admin/data';
        $this->cache = $cache ?: new Cache();
    }
    
    /**
     * 加载配置文件
     */
    public function load($name) {
        if (isset($this->configs[$name])) {
            return $this->configs[$name];
        }
        
        $cacheKey = 'config_' . $name;
        
        // 尝试从缓存获取
        $config = $this->cache->remember($cacheKey, function() use ($name) {
            return $this->loadFromFile($name);
        }, 300); // 缓存5分钟
        
        $this->configs[$name] = $config;
        return $config;
    }
    
    /**
     * 从文件加载配置
     */
    private function loadFromFile($name) {
        $file = $this->configDir . '/' . $name . '.json';
        
        if (!file_exists($file)) {
            return $this->getDefaultConfig($name);
        }
        
        $content = @file_get_contents($file);
        if ($content === false) {
            error_log("Failed to read config file: {$file}");
            return $this->getDefaultConfig($name);
        }
        
        $config = json_decode($content, true);
        if ($config === null) {
            error_log("Failed to parse config file: {$file}");
            return $this->getDefaultConfig($name);
        }
        
        return $config;
    }
    
    /**
     * 保存配置
     */
    public function save($name, $config) {
        $file = $this->configDir . '/' . $name . '.json';
        $dir = dirname($file);
        
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            error_log("Failed to encode config: {$name}");
            return false;
        }
        
        // 使用临时文件确保原子写入
        $tempFile = $file . '.tmp.' . getmypid();
        $result = @file_put_contents($tempFile, $json, LOCK_EX);
        
        if ($result === false) {
            error_log("Failed to write config file: {$file}");
            @unlink($tempFile);
            return false;
        }
        
        if (!@rename($tempFile, $file)) {
            error_log("Failed to rename temp file: {$tempFile}");
            @unlink($tempFile);
            return false;
        }
        
        @chmod($file, 0644);
        
        // 清除缓存
        $this->cache->delete('config_' . $name);
        unset($this->configs[$name]);
        
        return true;
    }
    
    /**
     * 获取默认配置
     */
    private function getDefaultConfig($name) {
        $defaults = [
            'sitewide' => [
                'enabled' => false,
                'tasks' => []
            ],
            'parasites' => [
                'enabled' => false,
                'tasks' => []
            ],
            'groups' => [
                'enabled' => false,
                'groups' => []
            ],
            'placeholders' => [],
            'settings' => [
                'redis' => [
                    'host' => '127.0.0.1',
                    'port' => 6379,
                    'password' => '',
                    'database' => 1
                ],
                'log' => [
                    'enabled' => true,
                    'retention_days' => 30
                ]
            ]
        ];
        
        return $defaults[$name] ?? [];
    }
    
    /**
     * 获取配置值
     */
    public function get($name, $key = null, $default = null) {
        $config = $this->load($name);
        
        if ($key === null) {
            return $config;
        }
        
        // 支持点号分隔的键名
        $keys = explode('.', $key);
        $value = $config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
    
    /**
     * 设置配置值
     */
    public function set($name, $key, $value) {
        $config = $this->load($name);
        
        // 支持点号分隔的键名
        $keys = explode('.', $key);
        $current = &$config;
        
        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $current[$k] = $value;
            } else {
                if (!isset($current[$k]) || !is_array($current[$k])) {
                    $current[$k] = [];
                }
                $current = &$current[$k];
            }
        }
        
        return $this->save($name, $config);
    }
    
    /**
     * 刷新配置缓存
     */
    public function refresh($name = null) {
        if ($name === null) {
            // 刷新所有配置
            $this->cache->clear();
            $this->configs = [];
        } else {
            // 刷新指定配置
            $this->cache->delete('config_' . $name);
            unset($this->configs[$name]);
        }
    }
    
    /**
     * 检查配置文件是否存在
     */
    public function exists($name) {
        $file = $this->configDir . '/' . $name . '.json';
        return file_exists($file);
    }
    
    /**
     * 删除配置文件
     */
    public function delete($name) {
        $file = $this->configDir . '/' . $name . '.json';
        
        if (file_exists($file)) {
            $result = @unlink($file);
            if ($result) {
                $this->cache->delete('config_' . $name);
                unset($this->configs[$name]);
            }
            return $result;
        }
        
        return true;
    }
    
    /**
     * 获取所有配置文件列表
     */
    public function list() {
        $files = glob($this->configDir . '/*.json');
        $configs = [];
        
        foreach ($files as $file) {
            $name = basename($file, '.json');
            $configs[] = $name;
        }
        
        return $configs;
    }
}

