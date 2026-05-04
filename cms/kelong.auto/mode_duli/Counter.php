<?php
/**
 * 独立配置模式 - 访问计数
 */
class DuliCounter {
    private $base_dir;
    private $counterDir;
    private $logger;
    
    public function __construct() {
        $this->base_dir = dirname(__DIR__);
        $this->counterDir = $this->base_dir . '/data/domain_groups/visit_counters';
        
        // 确保计数器目录存在
        if (!is_dir($this->counterDir)) {
            mkdir($this->counterDir, 0755, true);
        }
    }
    
    public function setLogger($logger) {
        $this->logger = $logger;
    }
    
    /**
     * 增加访问计数
     */
    public function increment($subdomain) {
        if ($this->logger) $this->logger->info("      计数模块: 开始增加访问计数");
        
        $counterFile = $this->counterDir . '/' . $subdomain . '.json';
        
        // 读取当前计数
        $data = $this->load($counterFile);
        
        $oldCount = $data['visit_count'] ?? 0;
        
        // 增加计数
        $data['visit_count']++;
        $data['last_visit'] = date('Y-m-d H:i:s');
        
        // 保存
        $this->save($counterFile, $data);
        
        if ($this->logger) {
            $this->logger->info("      访问计数: {$oldCount} -> {$data['visit_count']}");
            $this->logger->debug("      计数器文件: {$counterFile}");
        }
        
        return $data;
    }
    
    /**
     * 读取计数数据
     */
    private function load($file) {
        if (!file_exists($file)) {
            return [
                'visit_count' => 0,
                'total_clone_source_switches' => 0,
                'last_visit' => '',
                'last_switch' => ''
            ];
        }
        
        $content = file_get_contents($file);
        $data = json_decode($content, true);
        
        return $data ?: [
            'visit_count' => 0,
            'total_clone_source_switches' => 0,
            'last_visit' => '',
            'last_switch' => ''
        ];
    }
    
    /**
     * 保存计数数据
     */
    private function save($file, $data) {
        file_put_contents(
            $file,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }
    
    /**
     * 重置计数
     */
    public function reset($subdomain) {
        if ($this->logger) $this->logger->info("      计数模块: 重置访问计数");
        
        $counterFile = $this->counterDir . '/' . $subdomain . '.json';
        $data = $this->load($counterFile);
        
        $data['visit_count'] = 0;
        
        $this->save($counterFile, $data);
        
        return $data;
    }
    
    /**
     * 记录切换
     */
    public function recordSwitch($subdomain) {
        if ($this->logger) $this->logger->info("      计数模块: 记录克隆源切换");
        
        $counterFile = $this->counterDir . '/' . $subdomain . '.json';
        $data = $this->load($counterFile);
        
        $data['total_clone_source_switches']++;
        $data['last_switch'] = date('Y-m-d H:i:s');
        
        $this->save($counterFile, $data);
        
        if ($this->logger) {
            $this->logger->info("      总切换次数: {$data['total_clone_source_switches']}");
        }
        
        return $data;
    }
}
