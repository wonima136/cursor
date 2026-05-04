<?php
/**
 * 独立配置模式 - 克隆源切换
 */
class DuliSwitch {
    private $base_dir;
    private $logger;
    
    public function __construct() {
        $this->base_dir = dirname(__DIR__);
    }
    
    public function setLogger($logger) {
        $this->logger = $logger;
    }
    
    /**
     * 判断是否应该切换
     */
    public function shouldSwitch($visitData, $groupConfig) {
        $visitCount = $visitData['visit_count'] ?? 0;
        $threshold = $groupConfig['clone_source_switch']['trigger_visits'] ?? 5;
        
        // 只在刚好等于阈值时切换（避免每次刷新都切换）
        $shouldSwitch = ($visitCount == $threshold);
        
        if ($this->logger) {
            $this->logger->debug("      切换判断: 访问={$visitCount}, 阈值={$threshold}, 结果=" . ($shouldSwitch ? '是' : '否'));
        }
        
        return $shouldSwitch;
    }
    
    /**
     * 执行切换
     */
    public function execute($subdomain, $currentConfig, $groupConfig) {
        if ($this->logger) $this->logger->info("      切换模块: 开始执行克隆源切换");
        
        require_once __DIR__ . '/Config.php';
        require_once __DIR__ . '/Counter.php';
        
        $config = new DuliConfig();
        $config->setLogger($this->logger);
        $counter = new DuliCounter();
        $counter->setLogger($this->logger);
        
        // 获取当前 mirror_id
        $currentMirrorId = $currentConfig['mirror_id'] ?? '';
        
        if ($this->logger) $this->logger->debug("      当前 Mirror ID: {$currentMirrorId}");
        
        // 使用通用镜像选择器随机选择新镜像
        require_once $this->base_dir . '/inc/MirrorSelector.php';
        $selector = new MirrorSelector();
        $selector->setLogger($this->logger);
        
        $result = $selector->selectRandom($currentMirrorId);
        
        if (!$result) {
            if ($this->logger) $this->logger->error("      ❌ 无可用镜像");
            return null;
        }
        
        $newMirrorId = $result['mirror_id'];
        $newSourceDomain = $result['source_domain'];
        
        // 更新配置
        $newConfig = $currentConfig;
        $newConfig['mirror_id'] = $newMirrorId;
        if ($newSourceDomain) {
            $newConfig['source_domain'] = $newSourceDomain;
            if ($this->logger) $this->logger->debug("      新源站域名: {$newSourceDomain}");
        }
        $newConfig['updated_at'] = date('Y-m-d H:i:s');
        
        // 保存新配置（使用 DuliConfig 的保存方法）
        $config->save($subdomain, $newConfig);
        
        // 重置计数并记录切换
        $counter->reset($subdomain);
        $counter->recordSwitch($subdomain);
        
        if ($this->logger) $this->logger->info("      切换完成");
        
        return $newConfig;
    }
    
    
    /**
     * 从镜像ID获取源站域名
     */
    private function getSourceDomainFromMirror($mirrorId) {
        $mirrorConfigFile = $this->base_dir . '/data/mirrors/' . $mirrorId . '/config.json';
        
        if (!file_exists($mirrorConfigFile)) {
            if ($this->logger) $this->logger->warning("      镜像配置文件不存在: {$mirrorConfigFile}");
            return null;
        }
        
        $mirrorConfig = json_decode(file_get_contents($mirrorConfigFile), true);
        
        if (empty($mirrorConfig)) {
            if ($this->logger) $this->logger->warning("      镜像配置文件为空或格式错误");
            return null;
        }
        
        return $mirrorConfig['source_domain'] ?? null;
    }
    
    /**
     * 提取顶级域名
     */
    private function extractTopDomain($subdomain) {
        require_once $this->base_dir . '/inc/DomainExtractor.php';
        
        $extractor = new DomainExtractor();
        return $extractor->extractTopDomain($subdomain);
    }
}
