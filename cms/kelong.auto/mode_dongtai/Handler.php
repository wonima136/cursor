<?php
/**
 * 动态顶级模式 - 主处理器
 * 使用顶级域名的TDK，但每个子域名分配不同的镜像
 */
class DongtaiHandler {
    private $logger;
    
    public function __construct() {
        require_once dirname(__DIR__) . '/inc/Logger.php';
        $this->logger = new Logger('mode_dongtai');
    }
    
    /**
     * 处理子域名请求
     */
    public function handle($currentHost, $topDomain, $topConfig, $groupConfig, $requestUri) {
        $this->logger->info("========== 动态顶级模式请求开始 ==========");
        $this->logger->info("子域名: {$currentHost}");
        $this->logger->info("顶级域名: {$topDomain}");
        $this->logger->info("请求URI: {$requestUri}");
        
        // 获取或创建动态配置（基于hash分配镜像）
        require_once __DIR__ . '/Mirror.php';
        $mirror = new DongtaiMirror();
        $mirror->setLogger($this->logger);
        
        $subConfig = $mirror->getOrAssign($currentHost, $topDomain, $topConfig, $groupConfig);
        
        if (!$subConfig) {
            $this->logger->error("动态配置获取失败");
            echo "配置获取失败";
            exit;
        }
        
        $this->logger->info("动态配置获取成功");
        $this->logger->debug("分配的 Mirror ID: " . ($subConfig['mirror_id'] ?? 'N/A'));
        
        // 如果是首页，处理访问计数和切换
        if ($requestUri === '/') {
            $this->logger->info("处理首页访问");
            $updatedConfig = $this->handleHomepage($currentHost, $subConfig, $groupConfig);
            
            // 如果配置被更新（发生了切换），使用新配置
            if ($updatedConfig) {
                $subConfig = $updatedConfig;
                $this->logger->info("使用更新后的配置渲染");
            }
        }
        
        // 渲染页面
        require_once __DIR__ . '/Render.php';
        $render = new DongtaiRender();
        $render->setLogger($this->logger);
        $render->render($subConfig, $requestUri);
        
        $this->logger->info("========== 动态顶级模式请求结束 ==========");
    }
    
    private function handleHomepage($currentHost, $subConfig, $groupConfig) {
        require_once __DIR__ . '/Counter.php';
        require_once __DIR__ . '/Switch.php';
        
        $counter = new DongtaiCounter();
        $counter->setLogger($this->logger);
        
        // 增加访问计数
        $visitData = $counter->increment($currentHost);
        $this->logger->info("当前访问次数: " . ($visitData['visit_count'] ?? 0));
        
        // 检查切换配置
        $switchConfig = $groupConfig['clone_source_switch'] ?? null;
        $enabled = $switchConfig['enabled'] ?? false;
        $triggerVisits = (int)($switchConfig['trigger_visits'] ?? 0);
        
        $this->logger->info("切换配置: enabled=" . ($enabled ? 'true' : 'false') . ", trigger_visits=" . $triggerVisits);
        
        // 如果没有启用切换，直接返回
        if (!$enabled) {
            $this->logger->warning("⚠️  访问切换功能未启用，跳过切换逻辑");
            return null;
        }
        
        // 检查是否达到切换条件
        $visitCount = $visitData['visit_count'] ?? 0;
        if ($visitCount < $triggerVisits) {
            $this->logger->info("未达到切换条件 ({$visitCount}/{$triggerVisits})");
            return null;
        }
        
        // 执行切换
        $this->logger->warning("✓ 达到切换条件 ({$visitCount}/{$triggerVisits})，开始执行切换");
        
        $switch = new DongtaiSwitch();
        $switch->setLogger($this->logger);
        
        $newConfig = $switch->execute($currentHost, $subConfig, $groupConfig);
        
        if ($newConfig) {
            $this->logger->info("✓ 切换成功，返回新配置");
            $this->logger->info("  新 Mirror ID: " . ($newConfig['mirror_id'] ?? 'N/A'));
            $this->logger->info("  新 Source Domain: " . ($newConfig['source_domain'] ?? 'N/A'));
            return $newConfig;
        } else {
            $this->logger->error("❌ 切换失败");
            return null;
        }
    }
}
