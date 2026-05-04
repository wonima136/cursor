<?php
/**
 * 域名配置管理器（JSON格式）
 * 统一管理两种模式的配置
 */

class DomainConfigManager {
    private $base_dir;
    private $config_dir;
    
    public function __construct() {
        $this->base_dir = dirname(__DIR__);
        $this->config_dir = $this->base_dir . '/data/domain/';
    }
    
    /**
     * 读取配置
     * @param string $domain 域名
     * @return array|null 配置数组
     */
    public function getConfig($domain) {
        $jsonFile = $this->config_dir . $domain . '.json';
        $txtFile = $this->config_dir . $domain . '.txt';
        
        // 优先读取JSON格式
        if (file_exists($jsonFile)) {
            $content = file_get_contents($jsonFile);
            $config = json_decode($content, true);
            
            if ($config) {
                return $config;
            }
        }
        
        // 兼容旧的TXT格式
        if (file_exists($txtFile)) {
            return $this->convertTxtToConfig($txtFile);
        }
        
        return null;
    }
    
    /**
     * 保存配置
     * @param string $domain 域名
     * @param array $config 配置数组
     */
    public function saveConfig($domain, $config) {
        $jsonFile = $this->config_dir . $domain . '.json';
        
        // 添加元数据
        $config['updated_at'] = date('Y-m-d H:i:s');
        
        if (!isset($config['created_at'])) {
            $config['created_at'] = date('Y-m-d H:i:s');
        }
        
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        file_put_contents($jsonFile, $json);
        
        return true;
    }
    
    /**
     * 创建镜像模式配置
     */
    public function createMirrorConfig($domain, $mirrorId, $tdk, $root = '', $mode = 'top', $subdomainConfig = []) {
        // 读取镜像的源站域名
        $sourceDomain = $this->getMirrorSourceDomain($mirrorId);
        
        $config = [
            'mode' => 'mirror',
            'mirror_id' => $mirrorId,
            'source_domain' => $sourceDomain,  // 镜像源的原始域名
            'tdk' => [
                'title' => $tdk['title'],
                'keywords' => $tdk['keywords'],
                'description' => $tdk['description']
            ],
            'root' => $root,
            'config_mode' => $mode,
            'created_at' => date('Y-m-d H:i:s'),
            
            // 🆕 泛二级域名配置
            'auto_generate_config' => $subdomainConfig['auto_generate_config'] ?? false,
            'use_same_clone_source' => $subdomainConfig['use_same_clone_source'] ?? true,
            'keyword_source' => $subdomainConfig['keyword_source'] ?? [
                'type' => 'random_root',
                'value' => '',
                'custom_list_id' => ''
            ],
            'cache_mode' => $subdomainConfig['cache_mode'] ?? 'shared'
        ];
        
        return $this->saveConfig($domain, $config);
    }
    
    /**
     * 创建克隆模式配置
     */
    public function createCloneConfig($domain, $targetDomain, $tdk, $options = [], $subdomainConfig = []) {
        $config = [
            'mode' => 'clone',
            'target_domain' => $targetDomain,
            'target_keywords' => $options['target_keywords'] ?? '',
            'replace_keywords' => $options['replace_keywords'] ?? '',
            'tdk' => [
                'title' => $tdk['title'],
                'keywords' => $tdk['keywords'],
                'description' => $tdk['description']
            ],
            'options' => [
                'update_home' => $options['update_home'] ?? '1',
                'debug_mode' => $options['debug_mode'] ?? '0',
                'other' => $options['other'] ?? 'hhnnseo',
                'jianti' => $options['jianti'] ?? '0'
            ],
            'created_at' => date('Y-m-d H:i:s'),
            
            // 🆕 泛二级域名配置
            'auto_generate_config' => $subdomainConfig['auto_generate_config'] ?? false,
            'use_same_clone_source' => $subdomainConfig['use_same_clone_source'] ?? true,
            'keyword_source' => $subdomainConfig['keyword_source'] ?? [
                'type' => 'random_root',
                'value' => '',
                'custom_list_id' => ''
            ],
            'cache_mode' => $subdomainConfig['cache_mode'] ?? 'shared'
        ];
        
        return $this->saveConfig($domain, $config);
    }
    
    /**
     * 转换旧TXT格式为配置数组
     */
    private function convertTxtToConfig($txtFile) {
        $lines = file($txtFile, FILE_IGNORE_NEW_LINES);
        $lineCount = count($lines);
        
        if ($lineCount >= 10) {
            // 旧格式（克隆模式）
            return [
                'mode' => 'clone',
                'target_domain' => trim($lines[0]),
                'target_keywords' => trim($lines[1]),
                'replace_keywords' => trim($lines[2]),
                'tdk' => [
                    'title' => trim($lines[5]),
                    'keywords' => trim($lines[6]),
                    'description' => trim($lines[7])
                ],
                'options' => [
                    'update_home' => trim($lines[3]),
                    'debug_mode' => trim($lines[4]),
                    'other' => trim($lines[8] ?? 'hhnnseo'),
                    'jianti' => trim($lines[9] ?? '0')
                ],
                'format' => 'txt'  // 标记为旧格式
            ];
        } elseif ($lineCount >= 4 && strpos($lines[0], 'mirror_') === 0) {
            // 新格式（镜像模式）- TXT版本
            return [
                'mode' => 'mirror',
                'mirror_id' => trim($lines[0]),
                'tdk' => [
                    'title' => trim($lines[1]),
                    'keywords' => trim($lines[2]),
                    'description' => trim($lines[3])
                ],
                'root' => trim($lines[4] ?? ''),
                'config_mode' => trim($lines[5] ?? 'top'),
                'format' => 'txt'  // 标记为旧格式
            ];
        }
        
        return null;
    }
    
    /**
     * 更新镜像ID（用于切换）
     */
    public function updateMirrorId($domain, $newMirrorId) {
        $config = $this->getConfig($domain);
        
        if (!$config || $config['mode'] !== 'mirror') {
            return false;
        }
        
        $config['mirror_id'] = $newMirrorId;
        $config['source_domain'] = $this->getMirrorSourceDomain($newMirrorId);  // 更新源站域名
        
        return $this->saveConfig($domain, $config);
    }
    
    /**
     * 更新克隆源（用于切换）
     */
    public function updateCloneSource($domain, $newTargetDomain) {
        $config = $this->getConfig($domain);
        
        if (!$config || $config['mode'] !== 'clone') {
            return false;
        }
        
        $oldDomain = $config['target_domain'];
        $config['target_domain'] = $newTargetDomain;
        
        // 同时更新 target_keywords 和 replace_keywords 中的域名
        $oldDomainClean = str_replace('www.', '', $oldDomain);
        $newDomainClean = str_replace('www.', '', $newTargetDomain);
        
        $config['target_keywords'] = str_replace($oldDomainClean, $newDomainClean, $config['target_keywords']);
        $config['replace_keywords'] = str_replace($oldDomainClean, $newDomainClean, $config['replace_keywords']);
        
        return $this->saveConfig($domain, $config);
    }
    
    /**
     * 获取镜像的源站域名
     */
    private function getMirrorSourceDomain($mirrorId) {
        $mirrorsDir = $this->base_dir . '/data/mirrors/';
        $configFile = $mirrorsDir . $mirrorId . '/config.json';
        
        if (!file_exists($configFile)) {
            return '';
        }
        
        $config = json_decode(file_get_contents($configFile), true);
        return $config['target_domain'] ?? '';
    }
    
    /**
     * 检查配置是否存在
     */
    public function exists($domain) {
        $jsonFile = $this->config_dir . $domain . '.json';
        $txtFile = $this->config_dir . $domain . '.txt';
        
        return file_exists($jsonFile) || file_exists($txtFile);
    }
    
    /**
     * 列出所有配置
     * @return array 配置列表
     */
    public function listConfigs() {
        $configs = [];
        
        if (!is_dir($this->config_dir)) {
            return $configs;
        }
        
        // 读取所有JSON配置文件
        $files = glob($this->config_dir . '*.json');
        
        foreach ($files as $file) {
            $domain = basename($file, '.json');
            $config = $this->getConfig($domain);
            
            if ($config) {
                $config['domain'] = $domain;
                $configs[] = $config;
            }
        }
        
        // 也读取TXT文件（如果有的话）
        $txtFiles = glob($this->config_dir . '*.txt');
        foreach ($txtFiles as $file) {
            $domain = basename($file, '.txt');
            // 只有当JSON文件不存在时才读取TXT
            $jsonFile = $this->config_dir . $domain . '.json';
            if (!file_exists($jsonFile)) {
                $config = $this->getConfig($domain);
                if ($config) {
                    $config['domain'] = $domain;
                    $configs[] = $config;
                }
            }
        }
        
        // 按更新时间排序（最新的在前）
        usort($configs, function($a, $b) {
            $timeA = strtotime($a['updated_at'] ?? $a['created_at'] ?? '1970-01-01');
            $timeB = strtotime($b['updated_at'] ?? $b['created_at'] ?? '1970-01-01');
            return $timeB - $timeA;
        });
        
        return $configs;
    }
    
    /**
     * 删除配置
     * @param string $domain 域名
     * @return bool
     */
    public function deleteConfig($domain, $deleteSubdomains = false) {
        $jsonFile = $this->config_dir . $domain . '.json';
        $txtFile = $this->config_dir . $domain . '.txt';
        $deleted = false;
        
        if (file_exists($jsonFile)) {
            unlink($jsonFile);
            $deleted = true;
        }
        
        if (file_exists($txtFile)) {
            unlink($txtFile);
            $deleted = true;
        }
        
        // 🆕 如果需要删除子域名配置
        if ($deleteSubdomains) {
            $pattern = $this->config_dir . '*.' . $domain . '.{json,txt}';
            $subdomainFiles = glob($pattern, GLOB_BRACE);
            foreach ($subdomainFiles as $file) {
                unlink($file);
                $deleted = true;
            }
        }
        
        return $deleted;
    }
}
