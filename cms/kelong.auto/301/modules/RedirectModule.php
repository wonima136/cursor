<?php
namespace Redirect301\Modules;

use Redirect301\Core\Logger;
use Redirect301\Core\Config;
use Redirect301\Core\RedisManager;
use Redirect301\Utils\SpiderDetector;
use Redirect301\Utils\SpiderValidator;
use Redirect301\Utils\PlaceholderHelper;

/**
 * 重定向模块基类
 * 所有重定向模块都继承此类
 */
abstract class RedirectModule {
    protected $logger;
    protected $config;
    protected $redis;
    protected $currentUrl;
    protected $currentHost;
    protected $currentUri;
    protected $spiderInfo;
    protected $clientIp;
    
    public function __construct(Logger $logger, Config $config, RedisManager $redis) {
        $this->logger = $logger;
        $this->config = $config;
        $this->redis = $redis;
        
        // 初始化请求信息
        $this->currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $this->currentUri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->currentUrl = $this->getCurrentProtocol() . '://' . $this->currentHost . $this->currentUri;
        $this->clientIp = $this->getClientIp();
        
        // 检测蜘蛛类型
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $this->spiderInfo = SpiderDetector::detect($userAgent);
    }
    
    /**
     * 检查是否应该执行重定向
     * 返回目标URL或null
     */
    abstract public function check();
    
    /**
     * 获取模块名称
     */
    abstract public function getName();
    
    /**
     * 获取模块优先级（数字越小优先级越高）
     */
    abstract public function getPriority();
    
    /**
     * 执行重定向
     */
    protected function redirect($targetUrl, $taskName = '', $httpCode = 301) {
        // 调试日志（已注释）
        // $debugLog = dirname(__DIR__) . '/redirect_debug.log';
        // file_put_contents($debugLog, date('Y-m-d H:i:s') . " REDIRECT START: {$this->getName()} -> {$targetUrl} (HTTP {$httpCode})\n", FILE_APPEND);
        
        // 先记录日志，再执行跳转
        try {
            // 替换占位符
            $targetUrl = PlaceholderHelper::replace($targetUrl);
            
            $this->logger->log([
                'client_ip' => $this->clientIp,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'spider_type' => $this->spiderInfo['type'] ?? 'unknown',
                'spider_subtype' => $this->spiderInfo['subtype'] ?? '',
                'feature' => $this->getName(),
                'source_url' => $this->currentUrl,
                'target_url' => $targetUrl,
                'task_name' => $taskName,
                'status_code' => $httpCode
            ]);
            // file_put_contents($debugLog, date('Y-m-d H:i:s') . " REDIRECT: log saved\n", FILE_APPEND);
        } catch (\Exception $e) {
            // 记录日志错误但不阻塞跳转
            // file_put_contents($debugLog, date('Y-m-d H:i:s') . " REDIRECT: log error - {$e->getMessage()}\n", FILE_APPEND);
        }
        
        // 执行跳转
        header("Location: {$targetUrl}", true, $httpCode);
        // file_put_contents($debugLog, date('Y-m-d H:i:s') . " REDIRECT: header sent, exiting\n", FILE_APPEND);
        exit;
    }
    
    /**
     * 检查蜘蛛类型是否匹配
     */
    protected function matchSpider($spiderConfig) {
        $spiderType = $this->spiderInfo['type'] ?? '';
        $spiderSubtype = $this->spiderInfo['subtype'] ?? '';
        
        // 如果配置为空，返回 false
        if (empty($spiderConfig)) {
            return false;
        }
        
        // 检查全局 enabled 字段
        if (isset($spiderConfig['enabled']) && !$spiderConfig['enabled']) {
            return false;
        }
        
        // 百度蜘蛛特殊处理
        if ($spiderType === 'baidu_spider') {
            $hasEnabledSubtype = !empty($spiderConfig['baidu_render']) 
                              || !empty($spiderConfig['baidu_mobile']) 
                              || !empty($spiderConfig['baidu_pc']);
            
            if (!$hasEnabledSubtype) {
                return false;
            }
            
            if ($spiderSubtype) {
                if (empty($spiderConfig[$spiderSubtype])) {
                    return false;
                }
            }
            
            // IP过滤
            $ipFilter = trim($spiderConfig['ip_filter'] ?? '');
            if (!empty($ipFilter) && strpos($this->clientIp, $ipFilter) !== 0) {
                return false;
            }
            
            return true;
        }
        
        // 其他蜘蛛类型（谷歌、必应、搜狗、360、神马）
        // 需要检查对应的配置字段是否启用
        $spiderTypeMap = [
            'google_spider' => 'google',
            'bing_spider' => 'bing',
            'sogou_spider' => 'sogou',
            '360_spider' => '360',
            'shenma_spider' => 'shenma',
        ];
        
        // 如果是已知的蜘蛛类型，检查对应的配置字段
        if (isset($spiderTypeMap[$spiderType])) {
            $configKey = $spiderTypeMap[$spiderType];
            return !empty($spiderConfig[$configKey]);
        }
        
        // 未知蜘蛛类型，默认不匹配
        return false;
    }
    
    /**
     * 验证蜘蛛筛选（新方法，使用 SpiderValidator）
     * @param array $spiderFilter 蜘蛛筛选配置 ['enabled' => bool, 'types' => [...]]
     * @return bool 是否通过验证
     */
    protected function validateSpider($spiderFilter) {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        error_log("RedirectModule::validateSpider - UA: $userAgent");
        error_log("RedirectModule::validateSpider - spiderFilter: " . json_encode($spiderFilter));
        
        // 如果未启用蜘蛛筛选，直接通过
        if (empty($spiderFilter['enabled'])) {
            error_log("RedirectModule::validateSpider - 蜘蛛筛选未启用，通过");
            return true;
        }
        
        // 只检查蜘蛛类型是否匹配任务配置（不做IP验证，IP验证已在全局完成）
        
        // 白名单UA直接通过
        if (stripos($userAgent, 'seo in my life') !== false) {
            error_log("RedirectModule::validateSpider - 白名单UA，通过");
            return true;
        }
        
        // 识别蜘蛛类型
        $spiderInfo = \Redirect301\Utils\SpiderDetector::detect($userAgent);
        $spiderType = $spiderInfo['type'] ?? '';
        $spiderSubtype = $spiderInfo['subtype'] ?? '';
        
        error_log("RedirectModule::validateSpider - 识别结果: type=$spiderType, subtype=$spiderSubtype");
        
        // 检查是否是目标蜘蛛（百度、谷歌、搜狗）
        if (!in_array($spiderType, ['baidu_spider', 'google_spider', 'sogou_spider'])) {
            // 不是目标蜘蛛，跳过此任务（让其他模块处理）
            error_log("RedirectModule::validateSpider - 不是目标蜘蛛，跳过");
            return false;
        }
        
        // 检查任务配置中是否启用了该蜘蛛类型
        $types = $spiderFilter['types'] ?? [];
        
        error_log("RedirectModule::validateSpider - 任务配置types: " . json_encode($types));
        
        // 根据蜘蛛类型和子类型检查
        $enabled = false;
        if ($spiderType === 'baidu_spider') {
            if ($spiderSubtype === 'baidu_pc' && !empty($types['baidu_pc'])) {
                $enabled = true;
                error_log("RedirectModule::validateSpider - 百度PC已启用");
            } elseif ($spiderSubtype === 'baidu_mobile' && !empty($types['baidu_mobile'])) {
                $enabled = true;
                error_log("RedirectModule::validateSpider - 百度移动已启用");
            } else {
                error_log("RedirectModule::validateSpider - 百度蜘蛛但未启用对应类型");
            }
        } elseif ($spiderType === 'google_spider' && !empty($types['google'])) {
            $enabled = true;
            error_log("RedirectModule::validateSpider - 谷歌蜘蛛已启用");
        } elseif ($spiderType === 'sogou_spider' && !empty($types['sogou'])) {
            $enabled = true;
            error_log("RedirectModule::validateSpider - 搜狗蜘蛛已启用");
        }
        
        // 如果任务配置中未启用该蜘蛛，跳过此任务
        if (!$enabled) {
            error_log("RedirectModule::validateSpider - 蜘蛛类型未启用，跳过");
            return false;
        }
        
        // 蜘蛛类型匹配，通过验证
        error_log("RedirectModule::validateSpider - 验证通过");
        return true;
    }
    
    /**
     * 获取当前协议
     */
    protected function getCurrentProtocol() {
        $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
                   (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        return $isHttps ? 'https' : 'http';
    }
    
    /**
     * 获取客户端IP
     */
    protected function getClientIp() {
        $headers = [
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                return $ip;
            }
        }
        
        return 'unknown';
    }
    
    /**
     * 检查是否是静态资源
     */
    protected function isStaticResource() {
        $staticExtensions = ['jpg', 'jpeg', 'png', 'gif', 'css', 'js', 'webp', 'ico', 'svg', 'woff', 'woff2', 'ttf', 'eot'];
        $pathInfo = pathinfo(strtok($this->currentUri, '?'));
        return isset($pathInfo['extension']) && in_array(strtolower($pathInfo['extension']), $staticExtensions);
    }
    
    /**
     * 检查是否是后台路径
     */
    protected function isAdminPath() {
        return strpos($this->currentUri, '/admin/') === 0;
    }
    
    /**
     * 检查是否是首页
     */
    protected function isHomepage() {
        $uri = strtok($this->currentUri, '?');
        return in_array($uri, ['/', '/index.php', '/index.html', '/default.php', '/default.html']);
    }
}

