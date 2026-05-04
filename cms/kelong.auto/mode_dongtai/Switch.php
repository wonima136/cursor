<?php
/**
 * 动态顶级模式 - 克隆源切换
 */
class DongtaiSwitch {
    private $base_dir;
    private $counter;
    private $logger;
    
    public function __construct() {
        $this->base_dir = dirname(__DIR__);
        require_once __DIR__ . '/Counter.php';
        require_once $this->base_dir . '/inc/MirrorSelector.php';
        
        $this->counter = new DongtaiCounter();
        $this->mirrorSelector = new MirrorSelector();
    }
    
    private $mirrorSelector;
    
    public function setLogger($logger) {
        $this->logger = $logger;
        if ($this->counter) {
            $this->counter->setLogger($logger);
        }
        if ($this->mirrorSelector) {
            $this->mirrorSelector->setLogger($logger);
        }
    }
    
    /**
     * 判断是否应该切换
     */
    public function shouldSwitch($visitData, $groupConfig) {
        if ($this->logger) $this->logger->info("    切换模块: 开始检查是否需要切换");
        
        // 检查是否启用访问切换（从 clone_source_switch 配置中读取）
        $switchConfig = $groupConfig['clone_source_switch'] ?? [];
        $visitSwitch = $switchConfig['enabled'] ?? false;
        
        if ($this->logger) {
            $this->logger->debug("      配置检查:");
            $this->logger->debug("        - enabled: " . ($visitSwitch ? 'true' : 'false'));
        }
        
        if (!$visitSwitch) {
            if ($this->logger) $this->logger->warning("      ❌ 访问切换未启用");
            return false;
        }
        
        // 获取切换频率（字段名为 trigger_visits）
        $switchFrequency = (int)($switchConfig['trigger_visits'] ?? 5);
        
        // 检查访问次数
        $visitCount = $visitData['visit_count'] ?? 0;
        
        if ($this->logger) {
            $this->logger->info("      切换检查:");
            $this->logger->info("        - 当前访问次数: {$visitCount}");
            $this->logger->info("        - 触发阈值: {$switchFrequency}");
        }
        
        if ($visitCount >= $switchFrequency) {
            if ($this->logger) $this->logger->warning("      ✓ 达到切换条件！准备切换");
            return true;
        }
        
        if ($this->logger) $this->logger->debug("      未达到切换条件");
        return false;
    }
    
    /**
     * 执行切换
     */
    public function execute($subdomain, $currentConfig, $groupConfig) {
        if ($this->logger) $this->logger->info("      切换模块: 开始执行克隆源切换");
        
        // 使用通用镜像选择器随机选择新镜像
        $currentMirrorId = $currentConfig['mirror_id'] ?? '';
        $preferredSources = $groupConfig['clone_sources'] ?? [];
        
        $result = $this->mirrorSelector->selectRandom($currentMirrorId, $preferredSources);
        
        if (!$result) {
            if ($this->logger) $this->logger->error("      ❌ 无法找到有效的新镜像");
            return null;
        }
        
        $newMirrorId = $result['mirror_id'];
        $newSourceDomain = $result['source_domain'];
        
        if ($this->logger) $this->logger->info("      ✓ 选中新镜像: {$newMirrorId}");
        
        // 更新配置
        require_once __DIR__ . '/Config.php';
        $config = new DongtaiConfig();
        $config->setLogger($this->logger);
        
        $newConfig = $currentConfig;
        $newConfig['mirror_id'] = $newMirrorId;
        $newConfig['source_domain'] = $newSourceDomain;
        $newConfig['updated_at'] = date('Y-m-d H:i:s');
        
        // 保存配置
        if (!$config->save($subdomain, $newConfig)) {
            if ($this->logger) $this->logger->error("      ❌ 配置保存失败");
            return null;
        }
        
        // 重置计数器
        $this->counter->reset($subdomain);
        
        // 清理缓存
        $this->clearCache($subdomain);
        
        if ($this->logger) $this->logger->info("      ✓ 切换完成");
        
        return $newConfig;
    }
    
    /**
     * 清理缓存
     */
    private function clearCache($subdomain) {
        $cacheDir = $this->base_dir . '/cachefile_yuan/' . $subdomain;
        
        if (!is_dir($cacheDir)) {
            return;
        }
        
        // 删除缓存目录
        $this->deleteDirectory($cacheDir);
        
        error_log("[动态-切换] 缓存已清理: $cacheDir");
    }
    
    /**
     * 递归删除目录
     */
    private function deleteDirectory($dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        
        rmdir($dir);
    }
}
