<?php
namespace Redirect301\Modules;

use Redirect301\Core\Logger;
use Redirect301\Core\Config;
use Redirect301\Core\RedisManager;
use Redirect301\Utils\PlaceholderHelper;

/**
 * 克隆站专用重定向模块
 * 
 * 功能：
 * 1. 针对顶级域名配置，处理所有二级/三级域名的跳转
 * 2. 支持跳转到三端（@/www/m 随机并固定）
 * 3. 支持跳转到外部目标（带占位符）
 * 4. 按二级域名单独计算跳转次数
 * 5. 支持读取克隆配置文件的 {标题} 占位符
 */
class CloneRedirect extends RedirectModule {
    
    public function getName() {
        return 'clone';
    }
    
    public function getPriority() {
        return 0; // 最高优先级，在所有模块之前执行
    }
    
    private $cloneConfigDir;
    private $rawRedis; // 原生 Redis 连接，不使用 RedisManager 的前缀
    
    public function __construct(Logger $logger, Config $config, RedisManager $redis) {
        parent::__construct($logger, $config, $redis);
        $this->cloneConfigDir = dirname(REDIRECT301_ROOT) . '/data/domain';
        
        // 获取原生 Redis 连接，避免使用 bigsite: 前缀
        // 克隆站模块使用独立的 clone:SITE_ID: 前缀
        $this->rawRedis = $redis->getConnection();
    }
    
    /**
     * 检查是否需要执行克隆站重定向
     */
    public function check() {
        $currentDomain = $_SERVER['HTTP_HOST'];
        $topDomain = $this->getTopDomain($currentDomain);
        
        // 获取该顶级域名的克隆重定向配置
        $config = $this->getConfigByTopDomain($topDomain);
        
        if (!$config || !$config['enabled']) {
            return false;
        }
        
        // 验证蜘蛛筛选
        if (!$this->validateSpider($config['spider_filter'] ?? [])) {
            return false; // 跳过此任务
        }
        
        // 检查概率
        if (!$this->checkProbability($config['probability'])) {
            return false;
        }
        
        // 检查跳转次数限制
        if (!$this->checkRedirectLimit($currentDomain, $config)) {
            return false;
        }
        
        // 执行跳转
        return $this->executeRedirect($currentDomain, $topDomain, $config);
    }
    
    /**
     * 检查概率
     * @param int $probability 概率值 (0-100)
     * @return bool
     */
    private function checkProbability($probability) {
        if ($probability >= 100) {
            return true;
        }
        
        if ($probability <= 0) {
            return false;
        }
        
        return (mt_rand(1, 100) <= $probability);
    }
    
    /**
     * 执行重定向
     */
    private function executeRedirect($currentDomain, $topDomain, $config) {
        $redirectType = $config['redirect_type'] ?? 301;
        
        // 判断跳转类型
        if ($config['target_type'] === 'three_terminals') {
            // 跳转到三端
            $targetUrl = $this->getThreeTerminalTarget($currentDomain, $topDomain);
        } else {
            // 跳转到外部目标
            $targetUrl = $this->buildExternalTarget($currentDomain, $topDomain, $config['target_url']);
        }
        
        if (empty($targetUrl)) {
            return false;
        }
        
        // 增加跳转统计
        $this->incrementStats($currentDomain, $config);
        
        // 使用基类的 redirect() 方法，确保日志正确记录
        // 传递任务名称（站群组名称）
        $taskName = $config['group_name'] ?? '';
        $this->redirect($targetUrl, $taskName, $redirectType);
    }
    
    /**
     * 获取三端跳转目标
     * 首次随机选择，后续固定
     */
    private function getThreeTerminalTarget($currentDomain, $topDomain) {
        $protocol = $this->getProtocol();
        
        // 检查 Redis 中是否已有固定映射
        // 使用完整的键名：clone:SITE_ID:mapping:域名
        $mappingKey = REDIS_CLONE_PREFIX . 'mapping:' . $currentDomain;
        $fixedTerminal = $this->rawRedis->get($mappingKey);
        
        if ($fixedTerminal) {
            // 已有固定映射
            return $protocol . $fixedTerminal . '/';
        }
        
        // 首次访问，随机选择三端
        $terminals = [
            $topDomain,           // @
            'www.' . $topDomain,  // www
            'm.' . $topDomain     // m
        ];
        
        $selectedTerminal = $terminals[array_rand($terminals)];
        
        // 保存映射关系（永久保存）
        $this->rawRedis->set($mappingKey, $selectedTerminal);
        
        return $protocol . $selectedTerminal . '/';
    }
    
    /**
     * 构建外部跳转目标（支持占位符）
     */
    private function buildExternalTarget($currentDomain, $topDomain, $targetTemplate) {
        // 读取克隆配置文件
        $cloneConfig = $this->readCloneConfig($currentDomain, $topDomain);
        
        if (!$cloneConfig) {
            return '';
        }
        
        // 替换 {标题} 占位符
        $title = $cloneConfig['title'] ?? '';
        $encodedTitle = urlencode($title);
        
        $targetUrl = str_replace('{标题}', $encodedTitle, $targetTemplate);
        
        // 处理其他标准占位符
        $targetUrl = PlaceholderHelper::replace($targetUrl);
        
        return $targetUrl;
    }
    
    /**
     * 读取克隆配置文件
     * 优先级：
     * 1. 当前二级域名配置（test.1-14.cn.txt）
     * 2. 调用 API 生成（为当前二级域名生成专属配置）
     * 3. 顶级域名配置（1-14.cn.txt，作为兜底方案）
     */
    private function readCloneConfig($currentDomain, $topDomain) {
        // 第1优先：尝试读取当前域名配置
        $config = $this->readConfigFile($currentDomain);
        if ($config) {
            return $config;
        }
        
        // 第2优先：调用 API 为当前二级域名生成配置
        $this->triggerAPIGeneration($currentDomain);
        
        // API 调用后，等待 200ms 确保文件写入完成
        usleep(200000);
        
        // 重新读取配置文件
        $config = $this->readConfigFile($currentDomain);
        if ($config) {
            return $config;
        }
        
        // 第3优先（兜底）：读取顶级域名配置
        $config = $this->readConfigFile($topDomain);
        if ($config) {
            return $config;
        }
        
        // 都没有，返回 null
        return null;
    }
    
    /**
     * 读取配置文件（独立函数）
     */
    private function readConfigFile($domain) {
        $configFile = $this->cloneConfigDir . '/' . $domain . '.txt';
        
        // 清除文件状态缓存
        clearstatcache(true, $configFile);
        
        if (!file_exists($configFile)) {
            return null;
        }
        
        $lines = file($configFile, FILE_IGNORE_NEW_LINES);
        
        if (count($lines) < 8) {
            return null;
        }
        
        return [
            'target_domain' => trim($lines[0]),
            'target_keywords' => trim($lines[1]),
            'replace_keywords' => trim($lines[2]),
            'update_homepage' => trim($lines[3]),
            'debug_mode' => trim($lines[4]),
            'title' => trim($lines[5]),  // 第6行（索引5）
            'keywords' => trim($lines[6]),
            'description' => trim($lines[7])
        ];
    }
    
    /**
     * 触发 API 生成配置文件（独立函数）
     */
    private function triggerAPIGeneration($domain) {
        // 调用 API 生成配置（API 只负责生成文件，不返回数据）
        $this->generateConfigViaAPI($domain);
    }
    
    /**
     * 通过 API 生成配置
     */
    private function generateConfigViaAPI($domain) {
        // 构建 API URL（相对于克隆程序根目录）
        $apiUrl = dirname($this->cloneConfigDir) . '/../api_generate_config.php';
        
        // 检查 API 文件是否存在
        if (!file_exists($apiUrl)) {
            // 尝试通过 HTTP 调用（如果是远程部署）
            $protocol = $this->getProtocol();
            $apiUrl = $protocol . $_SERVER['HTTP_HOST'] . '/api_generate_config.php';
            return $this->callAPIViaHTTP($apiUrl, $domain);
        }
        
        // 本地调用 API
        return $this->callAPILocally($apiUrl, $domain);
    }
    
    /**
     * 本地调用 API（直接 include）
     */
    private function callAPILocally($apiFile, $domain) {
        // 保存当前的 $_REQUEST 和 $_GET
        $originalRequest = $_REQUEST;
        $originalGet = $_GET;
        
        // 设置 API 参数
        $_REQUEST = $_GET = [
            'domain' => $domain,
            'mode' => 'top',
            'format' => 'json'
        ];
        
        // 捕获输出
        ob_start();
        
        try {
            // 包含 API 文件
            include $apiFile;
            $output = ob_get_clean();
            
            // 恢复原始 $_REQUEST 和 $_GET
            $_REQUEST = $originalRequest;
            $_GET = $originalGet;
            
            // 解析 JSON 响应
            $response = json_decode($output, true);
            
            if ($response && $response['success'] && isset($response['config'])) {
                return [
                    'target_domain' => $response['config']['target_domain'] ?? '',
                    'target_keywords' => $response['config']['target_keywords'] ?? '',
                    'replace_keywords' => $response['config']['replace_keywords'] ?? '',
                    'update_homepage' => $response['config']['update_home'] ?? '0',
                    'debug_mode' => $response['config']['debug_mode'] ?? '0',
                    'title' => $response['config']['site_title'] ?? '',
                    'keywords' => $response['config']['site_keywords'] ?? '',
                    'description' => $response['config']['site_description'] ?? ''
                ];
            }
        } catch (Exception $e) {
            ob_end_clean();
            // 恢复原始 $_REQUEST 和 $_GET
            $_REQUEST = $originalRequest;
            $_GET = $originalGet;
        }
        
        return null;
    }
    
    /**
     * 通过 HTTP 调用 API
     */
    private function callAPIViaHTTP($apiUrl, $domain) {
        $url = $apiUrl . '?' . http_build_query([
            'domain' => $domain,
            'mode' => 'top',
            'format' => 'json'
        ]);
        
        // 使用 cURL
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3); // 3秒超时
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode == 200 && $response) {
                $data = json_decode($response, true);
                
                if ($data && $data['success'] && isset($data['config'])) {
                    return [
                        'target_domain' => $data['config']['target_domain'] ?? '',
                        'target_keywords' => $data['config']['target_keywords'] ?? '',
                        'replace_keywords' => $data['config']['replace_keywords'] ?? '',
                        'update_homepage' => $data['config']['update_home'] ?? '0',
                        'debug_mode' => $data['config']['debug_mode'] ?? '0',
                        'title' => $data['config']['site_title'] ?? '',
                        'keywords' => $data['config']['site_keywords'] ?? '',
                        'description' => $data['config']['site_description'] ?? ''
                    ];
                }
            }
        }
        
        // 使用 file_get_contents（备用方案）
        if (ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 3,
                    'ignore_errors' => true
                ]
            ]);
            
            $response = @file_get_contents($url, false, $context);
            
            if ($response) {
                $data = json_decode($response, true);
                
                if ($data && $data['success'] && isset($data['config'])) {
                    return [
                        'target_domain' => $data['config']['target_domain'] ?? '',
                        'target_keywords' => $data['config']['target_keywords'] ?? '',
                        'replace_keywords' => $data['config']['replace_keywords'] ?? '',
                        'update_homepage' => $data['config']['update_home'] ?? '0',
                        'debug_mode' => $data['config']['debug_mode'] ?? '0',
                        'title' => $data['config']['site_title'] ?? '',
                        'keywords' => $data['config']['site_keywords'] ?? '',
                        'description' => $data['config']['site_description'] ?? ''
                    ];
                }
            }
        }
        
        return null;
    }
    
    /**
     * 检查跳转次数限制
     */
    private function checkRedirectLimit($currentDomain, $config) {
        $maxCount = $config['max_redirects'] ?? 0;
        
        if ($maxCount <= 0) {
            // 无限制
            return true;
        }
        
        // 获取当前域名的跳转次数
        // 使用完整的键名：clone:SITE_ID:stats:域名
        $statsKey = REDIS_CLONE_PREFIX . 'stats:' . $currentDomain;
        $currentCount = (int)$this->rawRedis->hGet($statsKey, 'count');
        
        return $currentCount < $maxCount;
    }
    
    /**
     * 增加跳转统计
     */
    private function incrementStats($currentDomain, $config) {
        // 使用完整的键名：clone:SITE_ID:stats:域名
        $statsKey = REDIS_CLONE_PREFIX . 'stats:' . $currentDomain;
        
        $this->rawRedis->hIncrBy($statsKey, 'count', 1);
        $this->rawRedis->hSet($statsKey, 'last_redirect', date('Y-m-d H:i:s'));
        
        // 如果是首次跳转，记录创建时间
        if (!$this->rawRedis->hExists($statsKey, 'created_at')) {
            $this->rawRedis->hSet($statsKey, 'created_at', date('Y-m-d H:i:s'));
        }
    }
    
    /**
     * 获取配置（从 Redis 或 JSON）
     */
    private function getConfigByTopDomain($topDomain) {
        // 优先从 Redis 读取
        // 使用完整的键名：clone:SITE_ID:config:域名
        $redisKey = REDIS_CLONE_PREFIX . 'config:' . $topDomain;
        $configJson = $this->rawRedis->get($redisKey);
        
        if ($configJson) {
            return json_decode($configJson, true);
        }
        
        // 从站群组 JSON 文件读取
        $jsonFile = REDIRECT301_ROOT . '/admin/data/clone_groups.json';
        
        if (!file_exists($jsonFile)) {
            return null;
        }
        
        $allGroups = json_decode(file_get_contents($jsonFile), true);
        
        if (!$allGroups) {
            return null;
        }
        
        // 遍历所有站群组，查找包含该域名的组
        foreach ($allGroups as $groupId => $group) {
            $domains = $group['domains'] ?? [];
            
            // 检查该域名是否在这个站群组中
            if (in_array($topDomain, $domains)) {
                // 构建配置
                $config = [
                    'group_id' => $groupId,
                    'group_name' => $group['name'] ?? '',  // 添加任务名称
                    'enabled' => $group['enabled'] ?? true,
                    'target_type' => $group['target_type'] ?? 'three_terminals',
                    'target_url' => $group['target_url'] ?? '',
                    'redirect_type' => $group['redirect_type'] ?? 301,
                    'max_redirects' => $group['max_redirects'] ?? 0,
                    'probability' => $group['probability'] ?? 100
                ];
                
                // 同步到 Redis（30天过期）
                $this->rawRedis->setex($redisKey, 86400 * 30, json_encode($config));
                
                return $config;
            }
        }
        
        return null;
    }
    
    /**
     * 判断域名类型
     */
    private function getDomainType($currentDomain, $topDomain) {
        if ($currentDomain === $topDomain) {
            return 'root';  // @
        }
        
        if ($currentDomain === 'www.' . $topDomain) {
            return 'www';
        }
        
        if ($currentDomain === 'm.' . $topDomain) {
            return 'm';
        }
        
        // 其他二级/三级域名
        return 'subdomain';
    }
    
    /**
     * 获取顶级域名
     * 复用克隆程序的逻辑
     */
    private function getTopDomain($domain) {
        $host = strtolower($domain);
        
        // 双后缀域名列表
        $double_suffix_domains = [
            'com.cn', 'net.cn', 'org.cn', 'edu.cn', 'gov.cn', 'mil.cn',
            'ah.cn', 'bj.cn', 'cq.cn', 'fj.cn', 'gd.cn', 'gs.cn', 'gz.cn', 'gx.cn',
            'ha.cn', 'hb.cn', 'he.cn', 'hi.cn', 'hl.cn', 'hn.cn', 'jl.cn', 'js.cn',
            'jx.cn', 'ln.cn', 'nm.cn', 'nx.cn', 'qh.cn', 'sc.cn', 'sd.cn', 'sh.cn',
            'sn.cn', 'sx.cn', 'tj.cn', 'xj.cn', 'xz.cn', 'yn.cn', 'zj.cn', 'hk.cn', 'mo.cn', 'tw.cn', 'ac.cn',
            'com.uk', 'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'net.uk',
            'com.hk', 'org.hk', 'net.hk', 'edu.hk', 'gov.hk',
            'com.tw', 'org.tw', 'net.tw', 'edu.tw', 'gov.tw',
            'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au',
            'co.jp', 'or.jp', 'ne.jp', 'ac.jp', 'go.jp'
        ];
        
        // 检查双后缀域名
        foreach ($double_suffix_domains as $suffix) {
            if (preg_match('/^(.+\.' . preg_quote($suffix, '/') . ')$/i', $host, $matches)) {
                $domain_with_suffix = $matches[1];
                $parts = explode('.', $domain_with_suffix);
                $suffix_parts = explode('.', $suffix);
                $suffix_count = count($suffix_parts);
                
                if (count($parts) > $suffix_count) {
                    return implode('.', array_slice($parts, -($suffix_count + 1)));
                } else {
                    return $domain_with_suffix;
                }
            }
        }
        
        // 单后缀域名
        $parts = explode('.', $host);
        $count = count($parts);
        
        if ($count <= 2) {
            return $host;
        }
        
        return implode('.', array_slice($parts, -2));
    }
    
    /**
     * 获取协议
     */
    private function getProtocol() {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            return 'https://';
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return 'https://';
        }
        return 'http://';
    }
}

