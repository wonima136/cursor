<?php
/**
 * 固定顶级模式 - 克隆源切换
 */
class GudingSwitch {
    private $base_dir;
    private $counter;
    private $mirrorSelector;
    
    public function __construct() {
        $this->base_dir = dirname(__DIR__);
        require_once __DIR__ . '/Counter.php';
        require_once $this->base_dir . '/inc/MirrorSelector.php';
        
        $this->counter = new GudingCounter();
        $this->mirrorSelector = new MirrorSelector();
    }
    
    private $logger;
    
    public function setLogger($logger) {
        $this->logger = $logger;
        $this->mirrorSelector->setLogger($logger);
    }
    
    /**
     * 判断是否应该切换
     */
    public function shouldSwitch($visitData, $groupConfig) {
        $visitCount = $visitData['visit_count'] ?? 0;
        $threshold = $groupConfig['clone_source_switch']['trigger_visits'] ?? 5;
        
        // 如果未达到阈值，直接返回
        if ($visitCount < $threshold) {
            if ($this->logger) {
                $this->logger->debug("      切换判断: 访问={$visitCount}, 阈值={$threshold}, 结果=否（未达阈值）");
            }
            return false;
        }
        
        // 达到阈值，检查是否有多个克隆源
        $cloneSources = $groupConfig['clone_sources'] ?? [];
        
        // 如果分组配置有克隆源，检查数量
        if (!empty($cloneSources)) {
            $hasMultipleSources = count($cloneSources) > 1;
            
            if ($this->logger) {
                $this->logger->debug("      切换判断: 访问={$visitCount}, 阈值={$threshold}, 分组克隆源数=" . count($cloneSources) . ", 结果=" . ($hasMultipleSources ? '是' : '否'));
            }
            
            if (!$hasMultipleSources) {
                if ($this->logger) {
                    $this->logger->warning("      ⚠ 已达到切换阈值，但分组中只有 " . count($cloneSources) . " 个克隆源，无法切换");
                }
            }
            
            return $hasMultipleSources;
        }
        
        // 分组配置为空，检查 mirrors 目录是否有多个镜像
        $mirrorsDir = $this->base_dir . '/data/mirrors';
        
        if (!is_dir($mirrorsDir)) {
            if ($this->logger) {
                $this->logger->warning("      ⚠ 已达到切换阈值，但 mirrors 目录不存在");
            }
            return false;
        }
        
        // 快速扫描 mirrors 目录
        $mirrorCount = 0;
        $dirs = scandir($mirrorsDir);
        foreach ($dirs as $dir) {
            if ($dir !== '.' && $dir !== '..' && strpos($dir, 'mirror_') === 0) {
                $mirrorCount++;
                if ($mirrorCount > 1) {
                    break; // 找到2个就够了
                }
            }
        }
        
        if ($this->logger) {
            $this->logger->debug("      切换判断: 访问={$visitCount}, 阈值={$threshold}, mirrors目录镜像数>=" . $mirrorCount . ", 结果=" . ($mirrorCount > 1 ? '是' : '否'));
        }
        
        if ($mirrorCount <= 1) {
            if ($this->logger) {
                $this->logger->warning("      ⚠ 已达到切换阈值，但只有 {$mirrorCount} 个镜像，无法切换");
            }
        }
        
        return $mirrorCount > 1;
    }
    
    /**
     * 执行切换
     */
    public function execute($subdomain, $currentConfig, $groupConfig) {
        if ($this->logger) $this->logger->info("      切换模块: 开始执行克隆源切换");
        
        try {
            require_once __DIR__ . '/Config.php';
            
            $config = new GudingConfig();
            $config->setLogger($this->logger);
            $this->counter->setLogger($this->logger);
            
            // 获取当前 mirror_id
            $currentMirrorId = $currentConfig['mirror_id'] ?? '';
            
            if ($this->logger) $this->logger->debug("      当前 Mirror ID: {$currentMirrorId}");
            
            // 使用通用镜像选择器随机选择新镜像
            $preferredSources = $groupConfig['clone_sources'] ?? [];
            $result = $this->mirrorSelector->selectRandom($currentMirrorId, $preferredSources);
            
            if (!$result) {
                if ($this->logger) $this->logger->error("      ❌ 无法找到有效的新镜像");
                return null;
            }
            
            $newMirrorId = $result['mirror_id'];
            $newSourceDomain = $result['source_domain'];
        
        // 更新配置（保留原有的 _config_file 字段）
        $newConfig = $currentConfig;
        $newConfig['mirror_id'] = $newMirrorId;
        if ($newSourceDomain) {
            $newConfig['source_domain'] = $newSourceDomain;
            if ($this->logger) $this->logger->debug("      新源站域名: {$newSourceDomain}");
        }
        $newConfig['updated_at'] = date('Y-m-d H:i:s');
        
        // 保存新配置（确保使用原配置文件名）
        if (!$config->save($subdomain, $newConfig)) {
            if ($this->logger) $this->logger->error("      ❌ 配置保存失败");
            return null;
        }
        
            // 重置计数（reset 方法中已经记录了切换）
            $this->counter->reset($subdomain);
            
            if ($this->logger) $this->logger->info("      ✓ 切换完成");
            
            return $newConfig;
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("      ❌ 切换过程出错: " . $e->getMessage());
                $this->logger->error("      错误位置: " . $e->getFile() . ":" . $e->getLine());
            }
            return null;
        }
    }
    
    
    /**
     * 提取顶级域名
     */
    private function extractTopDomain($subdomain) {
        // 简单提取顶级域名（不依赖外部类）
        $parts = explode('.', $subdomain);
        $count = count($parts);
        
        if ($count <= 2) {
            return $subdomain; // 已经是顶级域名
        }
        
        // 返回最后两段（如 hhjj.mydbys.cn → mydbys.cn）
        return implode('.', array_slice($parts, -2));
    }
    
    /**
     * 获取镜像的源站域名
     */
    private function getSourceDomainFromMirror($mirrorId) {
        $configPath = $this->base_dir . '/data/mirrors/' . $mirrorId . '/config.json';
        
        if (!file_exists($configPath)) {
            return '';
        }
        
        $config = json_decode(file_get_contents($configPath), true);
        return $config['source_domain'] ?? '';
    }
    
    /**
     * 获取可用镜像列表（三层回退机制）
     */
    private function getAvailableMirrors($topDomain, $groupConfig) {
        // 1. 尝试从分组配置的 clone_sources 获取
        if (!empty($groupConfig['clone_sources'])) {
            if ($this->logger) $this->logger->info("        → 从分组配置获取镜像列表");
            return $groupConfig['clone_sources'];
        }
        
        // 2. 如果分组配置为空，直接扫描 mirrors 目录获取所有可用镜像
        if ($this->logger) $this->logger->info("        → 分组配置为空，扫描 mirrors 目录");
        $mirrorsDir = $this->base_dir . '/data/mirrors';
        
        if (!is_dir($mirrorsDir)) {
            if ($this->logger) $this->logger->error("      ❌ mirrors 目录不存在");
            return [];
        }
        
        $mirrors = [];
        $dirs = @scandir($mirrorsDir);
        
        if ($dirs === false) {
            if ($this->logger) $this->logger->error("      ❌ 无法读取 mirrors 目录");
            return [];
        }
        
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            
            $mirrorPath = $mirrorsDir . '/' . $dir;
            if (is_dir($mirrorPath) && strpos($dir, 'mirror_') === 0) {
                // 检查是否有 config.json
                if (file_exists($mirrorPath . '/config.json')) {
                    $mirrors[] = $dir;
                    if ($this->logger) $this->logger->debug("          找到镜像: {$dir}");
                }
            }
        }
        
        if (!empty($mirrors)) {
            if ($this->logger) $this->logger->info("        → 扫描到 " . count($mirrors) . " 个可用镜像");
            return $mirrors;
        }
        
        if ($this->logger) $this->logger->error("      ❌ 未找到可用镜像");
        return [];
    }
}
