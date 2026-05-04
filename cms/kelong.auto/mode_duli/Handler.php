<?php
/**
 * 独立配置模式 - 主处理器
 * 每个子域名有自己独立的配置文件和TDK
 */
class DuliHandler {
    private $config;
    private $tdk;
    private $render;
    private $counter;
    private $switch;
    private $logger;
    
    public function __construct() {
        require_once __DIR__ . '/Config.php';
        require_once __DIR__ . '/TDK.php';
        require_once __DIR__ . '/Render.php';
        require_once __DIR__ . '/Counter.php';
        require_once __DIR__ . '/Switch.php';
        require_once dirname(__DIR__) . '/inc/Logger.php';
        
        $this->config = new DuliConfig();
        $this->tdk = new DuliTDK();
        $this->render = new DuliRender();
        $this->counter = new DuliCounter();
        $this->switch = new DuliSwitch();
        $this->logger = new Logger('mode_duli');
    }
    
    /**
     * 处理子域名请求
     */
    public function handle($currentHost, $topDomain, $topConfig, $groupConfig, $requestUri) {
        $this->logger->info("========== 独立模式请求开始 ==========");
        $this->logger->info("子域名: {$currentHost}");
        $this->logger->info("顶级域名: {$topDomain}");
        $this->logger->info("请求URI: {$requestUri}");
        
        // 1. 获取或创建子域名配置
        $this->logger->info("步骤1: 获取/创建子域名配置");
        $this->config->setLogger($this->logger);
        $subConfig = $this->config->getOrCreate($currentHost, $topDomain, $groupConfig);
        
        if (!$subConfig) {
            $this->logger->error("配置获取失败");
            echo "域名配置生成失败";
            exit;
        }
        
        $this->logger->info("配置获取成功");
        $this->logger->debug("Mirror ID: " . ($subConfig['mirror_id'] ?? 'N/A'));
        $this->logger->debug("Source Domain: " . ($subConfig['source_domain'] ?? 'N/A'));
        
        // 2. 如果是首页，处理访问计数和切换逻辑
        if ($requestUri === '/') {
            $this->logger->info("步骤2: 处理首页访问");
            $this->handleHomepage($currentHost, $subConfig, $groupConfig);
        } else {
            $this->logger->info("步骤2: 跳过（非首页）");
        }
        
        // 3. 渲染页面
        $this->logger->info("步骤3: 开始渲染页面");
        $this->render->setLogger($this->logger);
        $this->render->render($subConfig, $requestUri);
        
        $this->logger->info("========== 独立模式请求结束 ==========");
    }
    
    /**
     * 处理首页访问
     */
    private function handleHomepage($currentHost, &$subConfig, $groupConfig) {
        // 检查是否启用了访问次数切换
        $switchEnabled = $groupConfig['clone_source_switch']['enabled'] ?? false;
        
        if (!$switchEnabled) {
            $this->logger->info("  首页处理: 访问次数切换未启用，跳过");
            return;
        }
        
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
