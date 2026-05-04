<?php
/**
 * 动态顶级模式 - 镜像分配
 */
class DongtaiMirror {
    private $base_dir;
    private $logger;
    
    public function __construct() {
        $this->base_dir = dirname(__DIR__);
    }
    
    public function setLogger($logger) {
        $this->logger = $logger;
    }
    
    /**
     * 获取或分配子域名配置
     */
    public function getOrAssign($subdomain, $topDomain, $topConfig, $groupConfig) {
        require_once __DIR__ . '/Config.php';
        
        $config = new DongtaiConfig();
        $config->setLogger($this->logger);
        
        return $config->getOrCreate($subdomain, $topDomain, $topConfig, $groupConfig);
    }
    
    /**
     * 根据子域名哈希分配镜像
     */
    public function assignByHash($subdomain, $config) {
        error_log("[动态-镜像] 为 $subdomain 分配镜像");
        
        // 1. 获取可用镜像列表
        $mirrors = $this->getAvailableMirrors($config);
        
        if (empty($mirrors)) {
            error_log("[动态-镜像] 无可用镜像");
            return null;
        }
        
        // 2. 根据子域名哈希选择镜像
        $hash = crc32($subdomain);
        $index = abs($hash) % count($mirrors);
        
        $selectedMirror = $mirrors[$index];
        
        error_log("[动态-镜像] 分配镜像: {$selectedMirror['id']}");
        
        return $selectedMirror['id'];
    }
    
    /**
     * 获取镜像源域名
     */
    public function getSourceDomain($mirrorId, $config) {
        $mirrors = $this->getAvailableMirrors($config);
        
        foreach ($mirrors as $mirror) {
            if ($mirror['id'] === $mirrorId) {
                return $mirror['source_domain'] ?? '';
            }
        }
        
        return '';
    }
    
    /**
     * 获取可用镜像列表
     */
    private function getAvailableMirrors($config) {
        $materialsFile = $config['material_file'] ?? '';
        
        if (!$materialsFile) {
            error_log("[动态-镜像] 无material_file");
            return [];
        }
        
        $materialsPath = $this->base_dir . '/data/mirrors/' . $materialsFile;
        
        if (!file_exists($materialsPath)) {
            error_log("[动态-镜像] material文件不存在: $materialsPath");
            return [];
        }
        
        $materials = json_decode(file_get_contents($materialsPath), true);
        
        if (empty($materials)) {
            error_log("[动态-镜像] material文件为空");
            return [];
        }
        
        return $materials;
    }
}
