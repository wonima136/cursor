<?php
/**
 * 固定顶级模式 - 主处理器
 * 所有子域名共用顶级域名的TDK，但可以独立切换克隆源
 */
class GudingHandler {
    private $config;
    private $counter;
    private $switch;
    private $render;
    private $logger;
    
    public function __construct() {
        require_once __DIR__ . '/Config.php';
        require_once __DIR__ . '/Counter.php';
        require_once __DIR__ . '/Switch.php';
        require_once __DIR__ . '/Render.php';
        require_once dirname(__DIR__) . '/inc/Logger.php';
        
        $this->config = new GudingConfig();
        $this->counter = new GudingCounter();
        $this->switch = new GudingSwitch();
        $this->render = new GudingRender();
        $this->logger = new Logger('mode_guding');
    }
    
    /**
     * 处理子域名请求
     */
    public function handle($currentHost, $topDomain, $topConfig, $groupConfig, $requestUri) {
        $this->logger->info("========== 固定顶级模式请求开始 ==========");
        $this->logger->info("子域名: {$currentHost}");
        $this->logger->info("顶级域名: {$topDomain}");
        $this->logger->info("请求URI: {$requestUri}");
        
        // 检查是否启用访问次数切换
        $switchEnabled = $groupConfig['clone_source_switch']['enabled'] ?? false;
        
        if ($switchEnabled) {
            // 启用切换：需要为子域名创建独立配置
            $this->logger->info("访问次数切换已启用，使用子域名独立配置");
            
            // 1. 获取或创建子域名配置（继承顶级TDK）
            $this->config->setLogger($this->logger);
            $subConfig = $this->config->getOrCreate($currentHost, $topDomain, $topConfig, $groupConfig);
            
            if (!$subConfig) {
                $this->logger->error("配置获取失败");
                echo "域名配置生成失败";
                exit;
            }
            
            $this->logger->info("配置获取成功");
            $this->logger->debug("Mirror ID: " . ($subConfig['mirror_id'] ?? 'N/A'));
            $this->logger->debug("TDK Title: " . ($subConfig['tdk']['title'] ?? 'N/A'));
            
            // 2. 处理访问计数和切换逻辑（所有请求都计数）
            $this->handleHomepage($currentHost, $subConfig, $groupConfig);
            
            // 3. 渲染页面（使用子域名配置，但TDK来自顶级）
            $this->render->setLogger($this->logger);
            $this->render->render($subConfig, $requestUri);
            
        } else {
            // 未启用切换：直接使用顶级域名配置
            $this->logger->info("访问次数切换未启用，直接使用顶级域名配置");
            $this->logger->debug("Mirror ID: " . ($topConfig['mirror_id'] ?? 'N/A'));
            
            $this->render->setLogger($this->logger);
            $this->render->render($topConfig, $requestUri);
        }
        
        $this->logger->info("========== 固定顶级模式请求结束 ==========");
    }
    
    /**
     * 处理首页访问（访问计数和切换）
     */
    private function handleHomepage($currentHost, &$subConfig, $groupConfig) {
        $this->logger->info("  首页处理: 访问计数开始");
        
        // 访问计数
        $this->counter->setLogger($this->logger);
        $visitData = $this->counter->increment($currentHost);
        
        $this->logger->info("  当前访问次数: " . ($visitData['visit_count'] ?? 0));
        $this->logger->info("  切换阈值: " . ($groupConfig['clone_source_switch']['trigger_visits'] ?? 5));
        
        // 检查是否需要切换克隆源
        $this->switch->setLogger($this->logger);
        if ($this->switch->shouldSwitch($visitData, $groupConfig)) {
            $this->logger->warning("  触发切换条件，开始切换克隆源");
            
            // 执行切换
            $newConfig = $this->switch->execute($currentHost, $subConfig, $groupConfig);
            
            if ($newConfig) {
                $subConfig = $newConfig;
                $this->logger->info("  切换成功，新 Mirror ID: " . ($newConfig['mirror_id'] ?? 'N/A'));
            } else {
                $this->logger->error("  切换失败");
            }
        } else {
            $this->logger->info("  未达到切换条件");
        }
    }
}
