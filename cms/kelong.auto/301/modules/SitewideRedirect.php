<?php
namespace Redirect301\Modules;

use Redirect301\Utils\DomainHelper;
use Redirect301\Utils\PlaceholderHelper;

/**
 * 整站重定向模块
 * 优先级：1（最高）
 */
class SitewideRedirect extends RedirectModule {
    
    public function getName() {
        return '整站重定向';
    }
    
    public function getPriority() {
        return 1;
    }
    
    public function check() {
        // 从 Redis 获取所有任务ID
        require_once __DIR__ . '/../admin/redis_config.php';
        $taskIds = getAllSitewideTaskIdsFromRedis();
        
        if (empty($taskIds)) {
            return null;
        }
        
        // 遍历所有任务
        foreach ($taskIds as $taskId) {
            $task = getSitewideTaskFromRedis($taskId);
            
            if (!$task || empty($task['enabled'])) {
                continue;
            }
            
            // 验证蜘蛛筛选
            if (!$this->validateSpider($task['spider_filter'] ?? [])) {
                continue; // 跳过此任务
            }
            
            // 检查源域名是否匹配
            if (!$this->matchSourceDomain($task)) {
                continue;
            }
            
            // 检查URI过滤
            if (!$this->matchUriFilter($task)) {
                continue;
            }
            
            // 生成目标URL
            $targetUrl = $this->buildTargetUrl($task);
            
            if ($targetUrl) {
                // 更新统计
                $this->incrementStats($task['id']);
                
                // 执行重定向
                $this->redirect($targetUrl, $task['name'], $task['redirect_type'] ?? 301);
            }
        }
        
        return null;
    }
    
    /**
     * 检查源域名是否匹配
     */
    private function matchSourceDomain($task) {
        $sourceDomains = $task['source_domains'] ?? [];
        
        if (empty($sourceDomains)) {
            return false;
        }
        
        foreach ($sourceDomains as $sourceDomain) {
            if (DomainHelper::matchDomain($sourceDomain, $this->currentHost)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查URI过滤
     */
    private function matchUriFilter($task) {
        $uriFilter = $task['uri_filter'] ?? [];
        
        if (empty($uriFilter['enabled']) || empty($uriFilter['rules'])) {
            return true; // 未启用过滤，默认匹配
        }
        
        $matched = false;
        
        foreach ($uriFilter['rules'] as $rule) {
            $type = $rule['type'] ?? 'exact';
            $value = $rule['value'] ?? '';
            
            if (empty($value)) continue;
            
            switch ($type) {
                case 'exact':
                    if ($this->currentUri === $value) {
                        $matched = true;
                    }
                    break;
                    
                case 'prefix':
                    if (strpos($this->currentUri, $value) === 0) {
                        $matched = true;
                    }
                    break;
                    
                case 'regex':
                    if (@preg_match($value, $this->currentUri)) {
                        $matched = true;
                    }
                    break;
            }
            
            if ($matched) break;
        }
        
        // 白名单模式：匹配才跳转
        // 黑名单模式：不匹配才跳转
        $mode = $uriFilter['mode'] ?? 'blacklist';
        return ($mode === 'whitelist') ? $matched : !$matched;
    }
    
    /**
     * 构建目标URL
     */
    private function buildTargetUrl($task) {
        $targetDomains = $task['target_domains'] ?? [];
        
        if (empty($targetDomains)) {
            return null;
        }
        
        // 随机选择一个目标域名
        $targetDomain = $targetDomains[array_rand($targetDomains)];
        
        // 解析目标域名，提取协议和域名
        $protocol = $this->getCurrentProtocol();
        $host = $targetDomain;
        
        // 如果目标域名包含协议头，提取出来
        if (preg_match('#^(https?)://(.+?)/?$#i', $targetDomain, $matches)) {
            $protocol = $matches[1];
            $host = rtrim($matches[2], '/');
        }
        
        $uri = '/';
        
        // 跟随二级域名
        if (!empty($task['follow_subdomain'])) {
            $subdomainPrefix = DomainHelper::extractSubdomainPrefix($this->currentHost);
            if ($subdomainPrefix) {
                $host = $subdomainPrefix . '.' . $host;
            }
        }
        
        // URI跟随模式
        if (!empty($task['follow_uri'])) {
            // 先应用URI替换规则
            $uri = $this->applyUriReplacements($task);
            
            // 如果没有匹配的替换规则（URI没有变化），检查备用URL池
            if ($uri === $this->currentUri && !empty($task['fallback_urls'])) {
                return $this->getFixedMappingUrl($task);
            }
        }
        
        $targetUrl = $protocol . '://' . $host . $uri;
        
        // 替换占位符
        $targetUrl = PlaceholderHelper::replace($targetUrl);
        
        return $targetUrl;
    }
    
    /**
     * 应用URI替换规则（支持多次替换）
     */
    private function applyUriReplacements($task) {
        $replacements = $task['uri_replacements'] ?? [];
        
        if (empty($replacements)) {
            return $this->currentUri;
        }
        
        $newUri = $this->currentUri;
        $hasReplacement = false;
        
        // 遍历所有替换规则，依次执行
        foreach ($replacements as $rule) {
            $find = $rule['find'] ?? '';
            $replace = $rule['replace'] ?? '';
            
            if (empty($find)) continue;
            
            // 检查是否匹配
            if (strpos($newUri, $find) !== false) {
                $newUri = str_replace($find, $replace, $newUri);
                $hasReplacement = true;
            }
        }
        
        // 如果有任何替换发生，应用占位符替换
        if ($hasReplacement) {
            $newUri = PlaceholderHelper::replace($newUri);
        }
        
        return $newUri;
    }
    
    /**
     * 获取固定映射的URL（从备用URI池）
     */
    private function getFixedMappingUrl($task) {
        $fallbackUrls = $task['fallback_urls'] ?? [];
        
        if (empty($fallbackUrls)) {
            return null;
        }
        
        $taskId = $task['id'];
        $sourceUrl = $this->currentUrl;
        
        // 生成Redis映射键
        // 格式: sitewide:{SITE_ID}:task:{taskId}:mapping:{md5}
        require_once __DIR__ . '/../admin/redis_config.php';
        $prefix = defined('REDIS_SITEWIDE_PREFIX') ? REDIS_SITEWIDE_PREFIX : 'sitewide:';
        $mappingKey = "{$prefix}task:{$taskId}:mapping:" . md5($sourceUrl);
        
        // 尝试从Redis获取已固定的映射
        if ($this->redis) {
            $redis = getRedis();
            if ($redis) {
                $cachedTargetUrl = $redis->get($mappingKey);
                if ($cachedTargetUrl) {
                    return $cachedTargetUrl;
                }
            }
        }
        
        // 随机选择一个备用URL/URI
        $selectedItem = $fallbackUrls[array_rand($fallbackUrls)];
        
        // 判断是完整URL还是URI路径
        if (preg_match('#^https?://#i', $selectedItem)) {
            // 如果是完整URL，直接使用
            $targetUrl = $selectedItem;
        } else {
            // 如果是URI路径，组合目标域名
            $targetDomains = $task['target_domains'] ?? [];
            if (empty($targetDomains)) {
                return null;
            }
            
            $targetDomain = $targetDomains[array_rand($targetDomains)];
            
            // 解析目标域名
            $protocol = $this->getCurrentProtocol();
            $host = $targetDomain;
            
            if (preg_match('#^(https?)://(.+?)/?$#i', $targetDomain, $matches)) {
                $protocol = $matches[1];
                $host = rtrim($matches[2], '/');
            }
            
            // 跟随二级域名
            if (!empty($task['follow_subdomain'])) {
                require_once __DIR__ . '/../utils/DomainHelper.php';
                $subdomainPrefix = DomainHelper::extractSubdomainPrefix($this->currentHost);
                if ($subdomainPrefix) {
                    $host = $subdomainPrefix . '.' . $host;
                }
            }
            
            // 确保URI以/开头
            $uri = $selectedItem;
            if ($uri[0] !== '/') {
                $uri = '/' . $uri;
            }
            
            $targetUrl = $protocol . '://' . $host . $uri;
        }
        
        // 保存固定映射到Redis（永久保存）
        if ($this->redis) {
            $redis = getRedis();
            if ($redis) {
                $redis->set($mappingKey, $targetUrl);
            }
        }
        
        return $targetUrl;
    }
    
    /**
     * 更新统计
     */
    private function incrementStats($taskId) {
        // 更新 Redis 统计
        require_once __DIR__ . '/../admin/redis_config.php';
        incrementSitewideTaskStats($taskId, 'total_redirects', 1);
    }
}

