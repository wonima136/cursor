<?php
/**
 * 子域名路由器
 * 根据配置的模式，将请求分发到对应的处理器
 * 
 * 三种模式完全独立：
 * - mode_duli (独立配置模式)
 * - mode_guding (固定顶级模式)
 * - mode_dongtai (动态顶级模式)
 */
class SubdomainRouter {
    private $base_dir;

    public function __construct() {
        $this->base_dir = dirname(__DIR__);
    }
    
    /**
     * 分发请求到对应的模式处理器
     * @param string $currentHost 当前访问的域名
     * @param string $topDomain 顶级域名
     * @param array $topConfig 顶级域名配置
     * @param array $groupConfig 分组配置
     * @param string $requestUri 请求URI
     * @return bool 是否处理成功
     */
    public function route($currentHost, $topDomain, $topConfig, $groupConfig, $requestUri) {
        $mode = isset($groupConfig['subdomain_config']['mode']) ? $groupConfig['subdomain_config']['mode'] : 'independent';
        
        error_log("[路由器] ========== 路由分发 ==========");
        error_log("[路由器] 模式: $mode");
        error_log("[路由器] 子域名: $currentHost");
        error_log("[路由器] 顶级域名: $topDomain");
        
        try {
            switch ($mode) {
                case 'independent':
                    error_log("[路由器] → 进入独立配置模式");
                    require_once $this->base_dir . '/mode_duli/Handler.php';
                    error_log("[路由器] → Handler文件已加载");
                    $handler = new DuliHandler();
                    error_log("[路由器] → Handler实例已创建");
                    $handler->handle($currentHost, $topDomain, $topConfig, $groupConfig, $requestUri);
                    error_log("[路由器] → handle()方法已调用");
                    return true;
                
                case 'fixed_top':
                    error_log("[路由器] → 进入固定顶级模式");
                    error_log("[路由器] → base_dir: " . $this->base_dir);
                    $handler_file = $this->base_dir . '/mode_guding/Handler.php';
                    error_log("[路由器] → Handler文件路径: " . $handler_file);
                    error_log("[路由器] → 文件是否存在: " . (file_exists($handler_file) ? '是' : '否'));
                    require_once $handler_file;
                    error_log("[路由器] → Handler文件已加载");
                    $handler = new GudingHandler();
                    error_log("[路由器] → Handler实例已创建");
                    $handler->handle($currentHost, $topDomain, $topConfig, $groupConfig, $requestUri);
                    error_log("[路由器] → handle()方法已调用");
                    return true;
                
                case 'dynamic_top':
                    error_log("[路由器] → 进入动态顶级模式");
                    require_once $this->base_dir . '/mode_dongtai/Handler.php';
                    error_log("[路由器] → Handler文件已加载");
                    $handler = new DongtaiHandler();
                    error_log("[路由器] → Handler实例已创建");
                    $handler->handle($currentHost, $topDomain, $topConfig, $groupConfig, $requestUri);
                    error_log("[路由器] → handle()方法已调用");
                    return true;
                
                default:
                    error_log("[路由器] ✗ 未知模式: $mode，使用默认独立模式");
                    require_once $this->base_dir . '/mode_duli/Handler.php';
                    $handler = new DuliHandler();
                    $handler->handle($currentHost, $topDomain, $topConfig, $groupConfig, $requestUri);
                    return true;
            }
        } catch (Error $e) {
            error_log("[路由器] ✗ 错误: " . $e->getMessage());
            error_log("[路由器] 文件: " . $e->getFile() . ":" . $e->getLine());
            error_log("[路由器] 堆栈: " . $e->getTraceAsString());
            return false;
        } catch (Exception $e) {
            error_log("[路由器] ✗ 异常: " . $e->getMessage());
            error_log("[路由器] 堆栈: " . $e->getTraceAsString());
            return false;
        }
    }
}
