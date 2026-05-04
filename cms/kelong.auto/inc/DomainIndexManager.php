<?php
/**
 * 域名配置索引管理器
 * 统一管理所有域名的配置信息
 */
class DomainIndexManager {
    private $base_dir;
    private $index_file;
    
    public function __construct() {
        $this->base_dir = dirname(__DIR__);
        $this->index_file = $this->base_dir . '/data/domains_index.json';
        
        // 确保索引文件存在
        if (!file_exists($this->index_file)) {
            $this->initializeIndex();
        }
    }
    
    /**
     * 初始化索引文件
     */
    private function initializeIndex() {
        file_put_contents($this->index_file, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 读取索引
     */
    private function readIndex() {
        if (!file_exists($this->index_file)) {
            return [];
        }
        
        $content = file_get_contents($this->index_file);
        return json_decode($content, true) ?: [];
    }
    
    /**
     * 保存索引
     */
    private function saveIndex($index) {
        file_put_contents(
            $this->index_file,
            json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
    
    /**
     * 添加或更新域名配置
     */
    public function addOrUpdateDomain($domain, $configData) {
        $index = $this->readIndex();
        
        $now = date('Y-m-d H:i:s');
        $isNew = !isset($index[$domain]);
        
        // 解析配置文件获取详细信息
        $configFile = $this->base_dir . '/data/domain/' . $domain . '.txt';
        $parsedConfig = $this->parseConfigFile($configFile);
        
        $domainInfo = [
            'domain' => $domain,
            'target' => $configData['target'] ?? $parsedConfig['target'] ?? '',
            'target_title' => $configData['target_title'] ?? $parsedConfig['target_title'] ?? '',
            'title' => $configData['title'] ?? $parsedConfig['title'] ?? '',
            'keywords' => $configData['keywords'] ?? $parsedConfig['keywords'] ?? '',
            'description' => $configData['description'] ?? $parsedConfig['description'] ?? '',
            'root' => $configData['root'] ?? '',
            'group_id' => $configData['group_id'] ?? '',
            'config_file' => 'data/domain/' . $domain . '.txt',
            'mode' => $configData['mode'] ?? 'independent',
            'created' => $isNew ? $now : ($index[$domain]['created'] ?? $now),
            'updated' => $now
        ];
        
        // 如果有分组信息，添加分组名称
        if (!empty($domainInfo['group_id'])) {
            require_once __DIR__ . '/DomainGroupManager.php';
            $groupManager = new DomainGroupManager();
            $group = $groupManager->getGroup($domainInfo['group_id']);
            if ($group) {
                $domainInfo['group_name'] = $group['name'];
            }
        }
        
        $index[$domain] = $domainInfo;
        $this->saveIndex($index);
        
        return $domainInfo;
    }
    
    /**
     * 解析配置文件
     */
    private function parseConfigFile($configFile) {
        if (!file_exists($configFile)) {
            return [];
        }
        
        $lines = file($configFile, FILE_IGNORE_NEW_LINES);
        
        return [
            'target' => $lines[0] ?? '',
            'target_title' => isset($lines[1]) ? explode(',', $lines[1])[0] : '',
            'title' => $lines[5] ?? '',
            'keywords' => $lines[6] ?? '',
            'description' => $lines[7] ?? ''
        ];
    }
    
    /**
     * 获取域名配置
     */
    public function getDomain($domain) {
        $index = $this->readIndex();
        return $index[$domain] ?? null;
    }
    
    /**
     * 获取所有域名配置
     */
    public function getAllDomains() {
        return $this->readIndex();
    }
    
    /**
     * 删除域名配置
     */
    public function deleteDomain($domain) {
        $index = $this->readIndex();
        
        if (isset($index[$domain])) {
            unset($index[$domain]);
            $this->saveIndex($index);
            return true;
        }
        
        return false;
    }
    
    /**
     * 按分组获取域名
     */
    public function getDomainsByGroup($groupId) {
        $index = $this->readIndex();
        $result = [];
        
        foreach ($index as $domain => $info) {
            if (isset($info['group_id']) && $info['group_id'] === $groupId) {
                $result[$domain] = $info;
            }
        }
        
        return $result;
    }
    
    /**
     * 按词根获取域名
     */
    public function getDomainsByRoot($root) {
        $index = $this->readIndex();
        $result = [];
        
        foreach ($index as $domain => $info) {
            if (isset($info['root']) && $info['root'] === $root) {
                $result[$domain] = $info;
            }
        }
        
        return $result;
    }
    
    /**
     * 搜索域名
     */
    public function searchDomains($keyword) {
        $index = $this->readIndex();
        $result = [];
        
        foreach ($index as $domain => $info) {
            if (stripos($domain, $keyword) !== false ||
                stripos($info['title'], $keyword) !== false ||
                stripos($info['keywords'], $keyword) !== false ||
                stripos($info['root'], $keyword) !== false) {
                $result[$domain] = $info;
            }
        }
        
        return $result;
    }
    
    /**
     * 获取统计信息
     */
    public function getStats() {
        $index = $this->readIndex();
        
        $stats = [
            'total_domains' => count($index),
            'by_mode' => [
                'independent' => 0,
                'top' => 0
            ],
            'by_root' => [],
            'by_group' => [],
            'recent_created' => [],
            'recent_updated' => []
        ];
        
        $created = [];
        $updated = [];
        
        foreach ($index as $domain => $info) {
            // 按模式统计
            $mode = $info['mode'] ?? 'independent';
            if (isset($stats['by_mode'][$mode])) {
                $stats['by_mode'][$mode]++;
            }
            
            // 按词根统计
            if (!empty($info['root'])) {
                if (!isset($stats['by_root'][$info['root']])) {
                    $stats['by_root'][$info['root']] = 0;
                }
                $stats['by_root'][$info['root']]++;
            }
            
            // 按分组统计
            if (!empty($info['group_id'])) {
                if (!isset($stats['by_group'][$info['group_id']])) {
                    $stats['by_group'][$info['group_id']] = [
                        'count' => 0,
                        'name' => $info['group_name'] ?? $info['group_id']
                    ];
                }
                $stats['by_group'][$info['group_id']]['count']++;
            }
            
            // 收集创建和更新时间
            $created[] = ['domain' => $domain, 'time' => $info['created']];
            $updated[] = ['domain' => $domain, 'time' => $info['updated']];
        }
        
        // 排序并取前10
        usort($created, function($a, $b) {
            return strtotime($b['time']) - strtotime($a['time']);
        });
        usort($updated, function($a, $b) {
            return strtotime($b['time']) - strtotime($a['time']);
        });
        
        $stats['recent_created'] = array_slice($created, 0, 10);
        $stats['recent_updated'] = array_slice($updated, 0, 10);
        
        // 词根排序（按数量降序）
        arsort($stats['by_root']);
        
        return $stats;
    }
    
    /**
     * 从现有配置文件重建索引
     */
    public function rebuildIndex() {
        $domainDir = $this->base_dir . '/data/domain/';
        
        if (!is_dir($domainDir)) {
            return ['success' => false, 'message' => '域名配置目录不存在'];
        }
        
        $files = glob($domainDir . '*.txt');
        $count = 0;
        $errors = [];
        
        // 读取分组信息
        require_once __DIR__ . '/DomainGroupManager.php';
        $groupManager = new DomainGroupManager();
        $allGroups = $groupManager->getAllGroups();
        
        // 创建域名到分组的映射
        $domainToGroup = [];
        foreach ($allGroups as $groupId => $group) {
            foreach ($group['domains'] as $domain) {
                $domainToGroup[$domain] = [
                    'group_id' => $groupId,
                    'group_name' => $group['name'],
                    'root' => $group['value']
                ];
            }
        }
        
        foreach ($files as $file) {
            $filename = basename($file);
            
            // 跳过失败日志文件
            if (strpos($filename, '_failure.log') !== false) {
                continue;
            }
            
            $domain = str_replace('.txt', '', $filename);
            
            try {
                $parsedConfig = $this->parseConfigFile($file);
                
                $configData = [
                    'target' => $parsedConfig['target'],
                    'target_title' => $parsedConfig['target_title'],
                    'title' => $parsedConfig['title'],
                    'keywords' => $parsedConfig['keywords'],
                    'description' => $parsedConfig['description'],
                    'mode' => 'independent'
                ];
                
                // 添加分组信息
                if (isset($domainToGroup[$domain])) {
                    $configData['group_id'] = $domainToGroup[$domain]['group_id'];
                    $configData['group_name'] = $domainToGroup[$domain]['group_name'];
                    $configData['root'] = $domainToGroup[$domain]['root'];
                }
                
                $this->addOrUpdateDomain($domain, $configData);
                $count++;
            } catch (Exception $e) {
                $errors[] = $domain . ': ' . $e->getMessage();
            }
        }
        
        return [
            'success' => true,
            'count' => $count,
            'errors' => $errors
        ];
    }
}

