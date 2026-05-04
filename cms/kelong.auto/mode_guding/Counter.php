<?php
/**
 * 固定顶级模式 - 访问计数
 */
class GudingCounter {
    private $base_dir;
    private $counterDir;
    private $domainDir;
    private $logger;
    
    public function __construct() {
        $this->base_dir = dirname(__DIR__);
        $this->counterDir = $this->base_dir . '/data/domain_groups/visit_counters';
        $this->domainDir = $this->base_dir . '/data/domain';
        
        // 确保计数器目录存在
        if (!is_dir($this->counterDir)) {
            mkdir($this->counterDir, 0755, true);
        }
    }
    
    public function setLogger($logger) {
        $this->logger = $logger;
    }
    
    /**
     * 增加访问计数（使用独立计数器文件）
     */
    public function increment($domain) {
        if ($this->logger) $this->logger->info("      计数模块: 开始增加访问计数");
        
        $counterFile = $this->counterDir . '/' . $domain . '.json';
        
        // 读取当前计数
        $data = $this->load($counterFile);
        
        if ($this->logger) $this->logger->debug("      当前计数: {$data['visit_count']}");
        
        // 增加计数
        $data['visit_count']++;
        $data['last_visit'] = date('Y-m-d H:i:s');
        
        // 保存
        $this->save($counterFile, $data);
        
        if ($this->logger) $this->logger->info("      访问计数: {$data['visit_count']}");
        
        error_log("[固定-计数] $domain 访问次数: {$data['visit_count']}");
        
        // 同步到配置文件
        $this->syncToConfig($domain, $data);
        
        return $data;
    }
    
    /**
     * 重置计数器
     */
    public function reset($domain) {
        $counterFile = $this->counterDir . '/' . $domain . '.json';
        
        $data = [
            'visit_count' => 0,
            'total_clone_source_switches' => $this->load($counterFile)['total_clone_source_switches'] ?? 0,
            'last_switch' => $this->load($counterFile)['last_switch'] ?? null,
            'last_visit' => date('Y-m-d H:i:s')
        ];
        
        // 增加切换次数
        $data['total_clone_source_switches']++;
        $data['last_switch'] = date('Y-m-d H:i:s');
        
        $this->save($counterFile, $data);
        
        error_log("[固定-计数] $domain 计数器已重置，切换次数: {$data['total_clone_source_switches']}");
        
        // 同步到配置文件
        $this->syncToConfig($domain, $data);
        
        return $data;
    }
    
    /**
     * 加载计数器数据
     */
    private function load($file) {
        if (!file_exists($file)) {
            return [
                'visit_count' => 0,
                'total_clone_source_switches' => 0,
                'last_switch' => null,
                'last_visit' => null
            ];
        }
        
        $content = file_get_contents($file);
        $data = json_decode($content, true);
        
        if (!$data) {
            return [
                'visit_count' => 0,
                'total_clone_source_switches' => 0,
                'last_switch' => null,
                'last_visit' => null
            ];
        }
        
        return $data;
    }
    
    /**
     * 保存计数器数据
     */
    private function save($file, $data) {
        file_put_contents(
            $file,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
    
    /**
     * 同步计数器数据到配置文件
     */
    private function syncToConfig($domain, $counterData) {
        $configFile = $this->domainDir . '/' . $domain . '.json';
        
        if (!file_exists($configFile)) {
            return;
        }
        
        $config = json_decode(file_get_contents($configFile), true);
        if (!$config) {
            return;
        }
        
        // 更新配置文件中的计数字段
        $config['visit_count'] = $counterData['visit_count'];
        $config['total_clone_source_switches'] = $counterData['total_clone_source_switches'];
        $config['last_switch'] = $counterData['last_switch'];
        $config['last_visit'] = $counterData['last_visit'];
        $config['updated_at'] = date('Y-m-d H:i:s');
        
        // 原子保存
        $tempFile = $configFile . '.tmp';
        file_put_contents($tempFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        rename($tempFile, $configFile);
    }
    
    /**
     * 获取计数器数据
     */
    public function get($domain) {
        $counterFile = $this->counterDir . '/' . $domain . '.json';
        return $this->load($counterFile);
    }
}
