<?php
/**
 * 动态顶级模式 - 访问计数
 */
class DongtaiCounter {
    private $base_dir;
    private $counterDir;
    private $logger;
    
    public function setLogger($logger) {
        $this->logger = $logger;
    }
    
    public function __construct() {
        $this->base_dir = dirname(__DIR__);
        $this->counterDir = $this->base_dir . '/data/domain_groups/visit_counters';
        
        // 确保计数器目录存在
        if (!is_dir($this->counterDir)) {
            mkdir($this->counterDir, 0755, true);
        }
    }
    
    /**
     * 增加访问计数
     */
    public function increment($subdomain) {
        if ($this->logger) $this->logger->info("    计数模块: 开始增加访问计数");
        
        $counterFile = $this->counterDir . '/' . $subdomain . '.json';
        
        // 读取当前计数
        $data = $this->load($counterFile);
        
        if ($this->logger) $this->logger->debug("      当前计数: {$data['visit_count']}");
        
        // 增加计数
        $data['visit_count']++;
        $data['last_visit'] = date('Y-m-d H:i:s');
        
        // 保存
        $this->save($counterFile, $data);
        
        if ($this->logger) $this->logger->info("      ✓ 计数已更新: {$data['visit_count']}");
        
        error_log("[动态-计数] $subdomain 访问次数: {$data['visit_count']}");
        
        return $data;
    }
    
    /**
     * 重置计数器
     */
    public function reset($subdomain) {
        if ($this->logger) $this->logger->info("      计数模块: 重置计数器");
        
        $counterFile = $this->counterDir . '/' . $subdomain . '.json';
        
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
        
        if ($this->logger) {
            $this->logger->info("      ✓ 计数器已重置为 0");
            $this->logger->info("      总切换次数: {$data['total_clone_source_switches']}");
        }
        
        error_log("[动态-计数] $subdomain 计数器已重置，切换次数: {$data['total_clone_source_switches']}");
        
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
     * 获取计数器数据
     */
    public function get($subdomain) {
        $counterFile = $this->counterDir . '/' . $subdomain . '.json';
        return $this->load($counterFile);
    }
}
