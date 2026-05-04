<?php
/**
 * 固定顶级模式 - 配置管理
 * 为子域名创建独立配置，但继承顶级域名的TDK
 */
class GudingConfig {
    private $base_dir;
    private $domainDir;
    private $logger;
    
    public function __construct() {
        $this->base_dir = dirname(__DIR__);
        $this->domainDir = $this->base_dir . '/data/domain';
        
        if (!is_dir($this->domainDir)) {
            mkdir($this->domainDir, 0755, true);
        }
    }
    
    public function setLogger($logger) {
        $this->logger = $logger;
    }
    
    /**
     * 获取或创建子域名配置
     */
    public function getOrCreate($subdomain, $topDomain, $topConfig, $groupConfig) {
        if ($this->logger) $this->logger->info("      配置模块: 查找配置 {$subdomain}");
        
        // 1. 查找已有配置
        $existingConfig = $this->findExisting($subdomain);
        if ($existingConfig) {
            if ($this->logger) $this->logger->info("      配置已存在，直接使用");
            return $existingConfig;
        }
        
        // 2. 生成新配置
        if ($this->logger) $this->logger->info("      配置不存在，开始生成新配置");
        return $this->generate($subdomain, $topDomain, $topConfig, $groupConfig);
    }
    
    /**
     * 查找已有配置
     */
    private function findExisting($subdomain) {
        $directFile = "$subdomain.json";
        if (file_exists($this->domainDir . '/' . $directFile)) {
            return $this->load($directFile);
        }
        
        $pattern = "*.$subdomain.json";
        $files = glob($this->domainDir . '/' . $pattern);
        
        if (!empty($files)) {
            $filename = basename($files[0]);
            return $this->load($filename);
        }
        
        return null;
    }
    
    /**
     * 加载配置文件
     */
    private function load($filename) {
        $filepath = $this->domainDir . '/' . $filename;
        $content = file_get_contents($filepath);
        
        if (!$content) {
            return null;
        }
        
        $config = json_decode($content, true);
        
        if (!$config) {
            return null;
        }
        
        $config['_config_file'] = $filename;
        
        return $config;
    }
    
    /**
     * 生成新配置（引用顶级域名，独立克隆源）
     */
    private function generate($subdomain, $topDomain, $topConfig, $groupConfig) {
        if ($this->logger) {
            $this->logger->debug("        生成配置:");
            $this->logger->debug("          - 子域名: {$subdomain}");
            $this->logger->debug("          - 顶级域名: {$topDomain}");
        }
        
        // 获取随机镜像ID（独立选择）
        $mirrorResult = $this->getFirstMirror($topDomain, $groupConfig);
        
        if (!$mirrorResult) {
            if ($this->logger) $this->logger->error("        ❌ 无可用镜像");
            return null;
        }
        
        if ($this->logger) {
            $this->logger->info("        ✓ 找到镜像: {$mirrorResult['mirror_id']}");
            if (!empty($mirrorResult['source_domain'])) {
                $this->logger->debug("        源站域名: {$mirrorResult['source_domain']}");
            }
        }
        
        // 构建配置（保存顶级域名引用，而不是完整TDK）
        $config = [
            'tdk' => [
                'title' => $topDomain . '.json',  // 引用顶级域名配置文件
                'keywords' => $topDomain . '.json',
                'description' => $topDomain . '.json'
            ],
            'top_domain' => $topDomain,  // 保存顶级域名
            'mode' => 'mirror',
            'config_mode' => 'fixed_top',
            'mirror_id' => $mirrorResult['mirror_id'],
            'source_domain' => $mirrorResult['source_domain'] ?? '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        // 保存配置
        if ($this->logger) $this->logger->info("        保存配置到文件...");
        
        if (!$this->save($subdomain, $config)) {
            if ($this->logger) $this->logger->error("        ❌ 配置保存失败");
            return null;
        }
        
        if ($this->logger) $this->logger->info("        ✓ 配置生成完成");
        
        return $config;
    }
    
    /**
     * 获取第一个可用镜像（从分组或顶级配置）
     */
    private function getFirstMirror($topDomain, $groupConfig) {
        // 1. 尝试从分组的克隆源列表获取
        if (!empty($groupConfig['clone_sources'])) {
            $cloneSources = $groupConfig['clone_sources'];
            $randomMirrorId = $cloneSources[array_rand($cloneSources)];
            
            if ($this->logger) {
                $this->logger->info("        ✓ 从分组克隆源随机选择: {$randomMirrorId}");
            }
            
            $sourceDomain = $this->getMirrorSourceDomain($randomMirrorId);
            
            return [
                'mirror_id' => $randomMirrorId,
                'source_domain' => $sourceDomain
            ];
        }
        
        // 2. 从顶级域名配置获取
        require_once $this->base_dir . '/inc/DomainConfigManager.php';
        $configManager = new DomainConfigManager();
        $topConfig = $configManager->getConfig($topDomain);
        
        if (!empty($topConfig['mirror_id'])) {
            if ($this->logger) {
                $this->logger->info("        ✓ 从顶级配置获取 mirror_id: {$topConfig['mirror_id']}");
            }
            
            return [
                'mirror_id' => $topConfig['mirror_id'],
                'source_domain' => $topConfig['source_domain'] ?? ''
            ];
        }
        
        if ($this->logger) $this->logger->error("        ❌ 无可用镜像");
        return null;
    }
    
    /**
     * 获取镜像的源站域名
     */
    private function getMirrorSourceDomain($mirrorId) {
        $configPath = $this->base_dir . '/data/mirrors/' . $mirrorId . '/config.json';
        
        if (!file_exists($configPath)) {
            return '';
        }
        
        $config = json_decode(file_get_contents($configPath), true);
        return $config['source_domain'] ?? '';
    }
    
    /**
     * 保存配置（原子写入）
     */
    public function save($subdomain, $config) {
        $filename = $config['_config_file'] ?? "$subdomain.json";
        unset($config['_config_file']);
        
        $filepath = $this->domainDir . '/' . $filename;
        $tempFilepath = $filepath . '.tmp';
        
        $config['updated_at'] = date('Y-m-d H:i:s');
        
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        if (file_put_contents($tempFilepath, $json) === false) {
            if ($this->logger) $this->logger->error("        ❌ 写入临时配置文件失败: {$tempFilepath}");
            return false;
        }
        
        if (rename($tempFilepath, $filepath) === false) {
            if ($this->logger) $this->logger->error("        ❌ 重命名配置文件失败: {$tempFilepath} -> {$filepath}");
            unlink($tempFilepath);
            return false;
        }
        
        if ($this->logger) $this->logger->info("        ✓ 配置保存成功: {$filename}");
        return true;
    }
}
