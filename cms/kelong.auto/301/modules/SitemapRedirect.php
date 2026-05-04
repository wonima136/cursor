<?php
namespace Redirect301\Modules;

use Redirect301\Utils\PlaceholderHelper;

/**
 * 地图重定向模块
 * 优先级：4.5
 * 
 * 功能：
 * 1. 根据比例决定跳转到域名首页或内页
 * 2. 从地图页抓取链接并缓存
 * 3. 智能缓存刷新（按使用次数）
 * 4. 自动过滤资源文件
 * 5. 支持目录过滤
 */
class SitemapRedirect extends RedirectModule {
    
    // 资源文件后缀（硬编码，自动过滤）
    private $resourceExtensions = [
        'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg',
        'css', 'js', 'json', 'xml',
        'mp3', 'mp4', 'avi', 'mov', 'wmv', 'flv',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar',
        'ico', 'woff', 'woff2', 'ttf', 'eot'
    ];
    
    public function getName() {
        return '地图重定向';
    }
    
    public function getPriority() {
        return 4.5;
    }
    
    public function check() {
        // 模块级别的概率控制
        // 从配置文件中获取模块概率
        $configFile = __DIR__ . '/../admin/config.php';
        $moduleProbability = 100; // 默认100%
        if (file_exists($configFile)) {
            $configContent = file_get_contents($configFile);
            if (preg_match("/\\\$config\['sitemap_probability'\]\s*=\s*(\d+);/", $configContent, $matches)) {
                $moduleProbability = intval($matches[1]);
            }
        }
        
        if ($moduleProbability < 100 && mt_rand(1, 100) > $moduleProbability) {
            // 未命中概率，整个模块不执行，让后续模块处理
            return null;
        }
        
        // 获取所有启用的任务
        $tasks = $this->getEnabledTasks();
        
        if (empty($tasks)) {
            return null;
        }
        
        // 遍历任务检查匹配
        foreach ($tasks as $task) {
            // 验证蜘蛛筛选
            if (!$this->validateSpider($task['spider_filter'] ?? [])) {
                continue;
            }
            
            $targetUrl = $this->getRedirectUrl($task);
            
            if ($targetUrl) {
                // 更新统计
                $this->incrementStats($task['id'], $targetUrl);
                
                // 获取跳转类型
                $redirectType = intval($task['redirect_type'] ?? 301);
                
                // 执行重定向
                $this->redirect($targetUrl, $task['name'], $redirectType);
            }
        }
        
        return null;
    }
    
    /**
     * 获取所有启用的任务
     */
    private function getEnabledTasks() {
        $prefix = $this->redis->getPrefix();
        $redis = $this->redis->getConnection();
        
        if (!$redis) {
            return [];
        }
        
        // ★ 使用独立的 Redis 前缀：sitemap:SITE_ID:task:
        // 从 bigsite:SITE_ID: 改为 sitemap:SITE_ID:
        $sitemapPrefix = str_replace('bigsite:', 'sitemap:', $prefix);
        $taskKeys = $redis->keys($sitemapPrefix . 'task:*:config');
        $tasks = [];
        
        foreach ($taskKeys as $key) {
            // 提取任务ID
            preg_match('/task:(.+):config/', $key, $matches);
            if (empty($matches[1])) {
                continue;
            }
            
            $taskId = $matches[1];
            
            // 获取任务配置
            $config = $redis->get($key);
            if (!$config) {
                continue;
            }
            
            $taskConfig = json_decode($config, true);
            if (!$taskConfig || empty($taskConfig['enabled'])) {
                continue;
            }
            
            $tasks[] = [
                'id' => $taskId,
                'name' => $taskConfig['name'] ?? '未命名任务',
                'domains' => $taskConfig['domains'] ?? [],
                'sitemap_path' => $taskConfig['sitemap_path'] ?? '/sitemap.html',
                'ratio' => $taskConfig['ratio'] ?? ['domain' => 30, 'inner' => 70],
                'max_links' => intval($taskConfig['max_links'] ?? 50),
                'include_directory' => !empty($taskConfig['include_directory']),
                'specified_paths' => $taskConfig['specified_paths'] ?? [],
                'redirect_type' => $taskConfig['redirect_type'] ?? '301',
                'spider_filter' => $taskConfig['spider_filter'] ?? []
            ];
        }
        
        return $tasks;
    }
    
    /**
     * 获取重定向URL
     */
    private function getRedirectUrl($task) {
        if (empty($task['domains'])) {
            return null;
        }
        
        // 根据比例决定跳转类型
        $ratio = $task['ratio'];
        $domainRatio = intval($ratio['domain'] ?? 30);
        $random = mt_rand(1, 100);
        
        if ($random <= $domainRatio) {
            // 跳转到域名首页
            return $this->getDomainUrl($task);
        } else {
            // 跳转到内页链接
            return $this->getInnerPageUrl($task);
        }
    }
    
    /**
     * 获取域名首页URL
     */
    private function getDomainUrl($task) {
        $domains = $task['domains'];
        if (empty($domains)) {
            return null;
        }
        
        // 随机选择一个域名
        $domain = $domains[array_rand($domains)];
        
        // 确保有协议
        if (!preg_match('#^https?://#', $domain)) {
            $domain = 'http://' . $domain;
        }
        
        // 确保以 / 结尾
        $domain = rtrim($domain, '/') . '/';
        
        return $domain;
    }
    
    /**
     * 获取内页URL
     */
    private function getInnerPageUrl($task) {
        $prefix = $this->redis->getPrefix();
        $redis = $this->redis->getConnection();
        
        if (!$redis) {
            return $this->getDomainUrl($task); // 降级到域名首页
        }
        
        $taskId = $task['id'];
        $domains = $task['domains'];
        $maxLinks = $task['max_links'];
        
        // 随机选择一个域名
        $domain = $domains[array_rand($domains)];
        $domainKey = md5($domain);
        
        // 使用正确的 sitemap 前缀（直接替换前缀部分）
        $siteId = str_replace(['bigsite:', ':'], '', $prefix);
        $sitemapPrefix = "sitemap:{$siteId}:";
        
        // 检查缓存
        $linksKey = "{$sitemapPrefix}task:{$taskId}:domain:{$domainKey}:links";
        $usedKey = "{$sitemapPrefix}task:{$taskId}:domain:{$domainKey}:used";
        
        $cachedLinks = $redis->lRange($linksKey, 0, -1);
        $usedCount = intval($redis->get($usedKey) ?? 0);
        $actualLinksCount = count($cachedLinks);
        
        // 如果缓存为空或已用完，重新抓取
        // 当实际链接数少于maxLinks时，使用实际链接数作为阈值
        // 这样可以在链接用完后及时重新抓取，获取可能更新的sitemap内容
        $refetchThreshold = ($actualLinksCount > 0 && $actualLinksCount < $maxLinks) 
            ? $actualLinksCount 
            : $maxLinks;
        if (empty($cachedLinks) || $usedCount >= $refetchThreshold) {
            $this->logger->debug("域名 {$domain} 的链接已用完（{$usedCount}/{$maxLinks}），开始重新抓取...");
            
            $links = $this->fetchSitemapLinks($task, $domain);
            
            if (empty($links)) {
                // 抓取失败，降级到域名首页
                $this->logger->debug("域名 {$domain} 重新抓取失败，降级到首页");
                return $this->getDomainUrl($task);
            }
            
            // 缓存链接
            $redis->del($linksKey);
            foreach ($links as $link) {
                $redis->rPush($linksKey, $link);
            }
            
            // 重置使用计数
            $redis->set($usedKey, 0);
            $cachedLinks = $links;
            $usedCount = 0;
            
            $this->logger->debug("域名 {$domain} 重新抓取成功，获取到 " . count($links) . " 条链接");
        }
        
        // 从缓存中随机选择一条
        if (empty($cachedLinks)) {
            return $this->getDomainUrl($task);
        }
        
        $selectedLink = $cachedLinks[array_rand($cachedLinks)];
        
        // 增加使用计数
        $redis->incr($usedKey);
        
        // 替换占位符
        $selectedLink = PlaceholderHelper::replace($selectedLink);
        
        return $selectedLink;
    }
    
    /**
     * 抓取地图页链接
     * @param array $task 任务配置
     * @param string $domain 指定要抓取的域名
     */
    private function fetchSitemapLinks($task, $domain) {
        $sitemapPath = $task['sitemap_path'];
        $maxLinks = $task['max_links'];
        $includeDirs = $task['include_directory'];
        $specifiedPaths = $task['specified_paths'];
        
        // 对指定域名尝试3次
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            // 构建地图页URL
            $sitemapUrl = $this->buildSitemapUrl($domain, $sitemapPath);
            
            $this->logger->debug("尝试抓取域名 {$domain} 的sitemap（第{$attempt}次）: {$sitemapUrl}");
            
            // 抓取地图页
            $html = $this->fetchUrl($sitemapUrl);
            
            if ($html === false) {
                // 记录失败
                $error = "抓取失败（第{$attempt}次）";
                $this->logger->debug("域名 {$domain} {$error}");
                if ($attempt < 3) {
                    sleep(1); // 等待1秒后重试
                    continue;
                }
                $this->recordFailure($task['id'], $domain, $error);
                return [];
            }
            
            // 提取链接（从该域名的地图页提取该域名的链接）
            $links = $this->extractLinks($html, $domain, $domain);
            
            if (empty($links)) {
                $error = "未提取到有效链接（第{$attempt}次）";
                $this->logger->debug("域名 {$domain} {$error}");
                if ($attempt < 3) {
                    sleep(1);
                    continue;
                }
                $this->recordFailure($task['id'], $domain, $error);
                return [];
            }
            
            // 过滤链接
            $links = $this->filterLinks($links, $includeDirs, $specifiedPaths);
            
            if (empty($links)) {
                $error = "过滤后无有效链接（第{$attempt}次）";
                $this->logger->debug("域名 {$domain} {$error}");
                if ($attempt < 3) {
                    sleep(1);
                    continue;
                }
                $this->recordFailure($task['id'], $domain, $error);
                return [];
            }
            
            // 取前N条
            $links = array_slice($links, 0, $maxLinks);
            
            // 打乱顺序
            shuffle($links);
            
            // 清除失败记录（如果存在）
            $this->clearFailure($task['id'], $domain);
            
            $this->logger->debug("域名 {$domain} 抓取成功，获取到 " . count($links) . " 条链接");
            
            return $links;
        }
        
        // 3次都失败
        return [];
    }
    
    /**
     * 构建地图页URL
     */
    private function buildSitemapUrl($domain, $path) {
        // 清理域名
        $domain = trim($domain);
        $domain = preg_replace('#^https?://#i', '', $domain);
        $domain = rtrim($domain, '/');
        
        // 清理路径
        $path = trim($path);
        if (!empty($path) && $path[0] !== '/') {
            $path = '/' . $path;
        }
        
        return 'http://' . $domain . $path;
    }
    
    /**
     * 抓取URL内容
     */
    private function fetchUrl($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'seo in my life');
        
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || $html === false) {
            return false;
        }
        
        return $html;
    }
    
    /**
     * 提取sitemap中的所有链接（兼容HTML和XML格式）
     */
    private function extractLinks($html, $sourceDomain, $targetDomain) {
        // 清除所有HTML/XML标签，保留纯文本内容
        $text = strip_tags($html);
        
        // 提取所有http/https开头的链接
        preg_match_all('#https?://[^\s<>"\']+#i', $text, $matches);
        
        if (empty($matches[0])) {
            return [];
        }
        
        // 只保留目标域名的链接
        $filtered = [];
        foreach ($matches[0] as $url) {
            $urlHost = parse_url($url, PHP_URL_HOST);
            if ($urlHost === $targetDomain) {
                $filtered[] = $url;
            }
        }
        
        return array_unique($filtered);
    }
    
    /**
     * 过滤链接
     */
    private function filterLinks($links, $includeDirs, $specifiedPaths) {
        $filtered = [];
        
        foreach ($links as $url) {
            // 过滤资源文件
            if ($this->isResourceFile($url)) {
                continue;
            }
            
            // 过滤目录链接（如果未启用）
            if (!$includeDirs && $this->isDirectoryUrl($url)) {
                continue;
            }
            
            // 指定目录过滤
            if (!empty($specifiedPaths)) {
                if (!$this->matchesSpecifiedPaths($url, $specifiedPaths)) {
                    continue;
                }
            }
            
            $filtered[] = $url;
        }
        
        return $filtered;
    }
    
    /**
     * 判断是否为资源文件
     */
    private function isResourceFile($url) {
        $path = parse_url($url, PHP_URL_PATH);
        if (empty($path)) {
            return false;
        }
        
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, $this->resourceExtensions);
    }
    
    /**
     * 判断是否为目录URL
     */
    private function isDirectoryUrl($url) {
        $path = parse_url($url, PHP_URL_PATH);
        if (empty($path)) {
            return false;
        }
        
        // 以 / 结尾的认为是目录
        return substr($path, -1) === '/';
    }
    
    /**
     * 判断URL是否匹配指定路径
     */
    private function matchesSpecifiedPaths($url, $paths) {
        $urlPath = parse_url($url, PHP_URL_PATH);
        if (empty($urlPath)) {
            return false;
        }
        
        foreach ($paths as $path) {
            $path = trim($path);
            if (empty($path)) {
                continue;
            }
            
            // 确保路径以 / 开头
            if ($path[0] !== '/') {
                $path = '/' . $path;
            }
            
            // 检查URL路径是否包含指定路径（不限位置）
            if (strpos($urlPath, $path) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 记录抓取失败
     */
    private function recordFailure($taskId, $domain, $error) {
        $failFile = __DIR__ . '/../admin/data/sitemap_failed.json';
        
        // 确保目录存在
        $dir = dirname($failFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // 读取现有数据
        $data = [];
        if (file_exists($failFile)) {
            $content = file_get_contents($failFile);
            $data = json_decode($content, true) ?: [];
        }
        
        // 更新失败记录
        if (!isset($data[$taskId])) {
            $data[$taskId] = [];
        }
        
        if (!isset($data[$taskId][$domain])) {
            $data[$taskId][$domain] = [
                'fail_count' => 0
            ];
        }
        
        $data[$taskId][$domain]['last_fail_time'] = date('Y-m-d H:i:s');
        $data[$taskId][$domain]['fail_count']++;
        $data[$taskId][$domain]['error'] = $error;
        
        // 保存
        file_put_contents($failFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    
    /**
     * 清除失败记录
     */
    private function clearFailure($taskId, $domain) {
        $failFile = __DIR__ . '/../admin/data/sitemap_failed.json';
        
        if (!file_exists($failFile)) {
            return;
        }
        
        $content = file_get_contents($failFile);
        $data = json_decode($content, true) ?: [];
        
        if (isset($data[$taskId][$domain])) {
            unset($data[$taskId][$domain]);
            
            // 如果任务下没有失败记录了，删除任务节点
            if (empty($data[$taskId])) {
                unset($data[$taskId]);
            }
            
            file_put_contents($failFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
    }
    
    /**
     * 更新统计
     */
    private function incrementStats($taskId, $targetUrl) {
        require_once __DIR__ . '/../admin/redis_config.php';
        
        // 更新总跳转次数
        incrementSitemapTaskStats($taskId, 'total', 1);
        
        // 判断是域名首页还是内页
        $path = parse_url($targetUrl, PHP_URL_PATH);
        if (empty($path) || $path === '/') {
            // 域名首页
            incrementSitemapTaskStats($taskId, 'domain_jumps', 1);
        } else {
            // 内页
            incrementSitemapTaskStats($taskId, 'inner_jumps', 1);
            
            // 记录内页跳转（用于TOP排行）
            incrementSitemapInnerPageStats($taskId, $targetUrl);
        }
        
        // 记录域名跳转次数
        $domain = parse_url($targetUrl, PHP_URL_HOST);
        if ($domain) {
            incrementSitemapTaskStats($taskId, $domain, 1);
        }
    }
}

