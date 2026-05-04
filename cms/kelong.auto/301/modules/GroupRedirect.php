<?php
namespace Redirect301\Modules;

use Redirect301\Utils\DomainHelper;

/**
 * 站群链轮重定向模块
 * 优先级：5（最低）
 */
class GroupRedirect extends RedirectModule {
    
    public function getName() {
        return '站群链轮';
    }
    
    public function getPriority() {
        return 5;
    }
    
    public function check() {
        // ★ 优先从 Redis 读取配置，如果失败则回退到 JSON
        $groups = $this->loadGroupsFromRedis();
        
        if (empty($groups)) {
            // 回退到 JSON 文件
            error_log("GroupRedirect: Redis 没有数据，从 JSON 文件读取");
            $groups = $this->config->load('groups');
        } else {
            error_log("GroupRedirect: 从 Redis 读取配置成功");
        }
        
        if (empty($groups) || !is_array($groups)) {
            error_log("GroupRedirect: 没有找到任何分组配置");
            return null;
        }
        
        error_log("GroupRedirect: 找到 " . count($groups) . " 个分组");
        
        // 遍历所有启用的分组
        foreach ($groups as $group) {
            if (empty($group['enabled'])) {
                error_log("GroupRedirect: 分组 {$group['name']} 未启用");
                continue;
            }
            
            // 验证蜘蛛筛选
            if (!$this->validateSpider($group['spider_filter'] ?? [])) {
                error_log("GroupRedirect: 分组 {$group['name']} 蜘蛛筛选未通过");
                continue; // 跳过此分组
            }
            
            error_log("GroupRedirect: 检查分组 {$group['name']}，当前域名: {$this->currentHost}");
            
            // 检查当前域名是否在该分组中
            $result = $this->checkGroup($group);
            
            if ($result) {
                list($targetUrl, $currentDomain) = $result;
                error_log("GroupRedirect: 匹配成功，跳转到 {$targetUrl}");
                
                // 更新统计
                $this->incrementStats($group['id']);
                
                // ★ 优先使用单个域名的跳转类型，如果没有则使用任务配置
                $httpCode = 302; // 默认302
                if (is_array($currentDomain) && isset($currentDomain['redirect_type'])) {
                    $httpCode = intval($currentDomain['redirect_type']);
                    error_log("GroupRedirect: 使用单个域名的跳转类型: {$httpCode}");
                } else {
                    // 优先读取根级别的redirect_type，兼容旧版settings结构
                    $httpCode = intval($group['redirect_type'] ?? $group['settings']['redirect_type'] ?? 302);
                    error_log("GroupRedirect: 使用任务的跳转类型: {$httpCode}");
                }
                
                $this->redirect($targetUrl, $group['name'], $httpCode);
            } else {
                error_log("GroupRedirect: 当前域名不在链轮中或概率未命中");
            }
        }
        
        return null;
    }
    
    /**
     * 检查分组
     */
    private function checkGroup($group) {
        $domains = $group['domains'] ?? [];
        $settings = $group['settings'] ?? [];
        $probability = floatval($settings['probability'] ?? 30);
        
        if (empty($domains)) {
            error_log("GroupRedirect: 分组没有域名");
            return null;
        }
        
        error_log("GroupRedirect: 分组有 " . count($domains) . " 个域名，任务概率: {$probability}%");
        
        // 查找当前域名在链轮中的位置
        $currentIndex = -1;
        
        foreach ($domains as $index => $domain) {
            $domainName = is_array($domain) ? ($domain['domain'] ?? '') : $domain;
            
            if (empty($domainName)) {
                continue;
            }
            
            error_log("GroupRedirect: 检查域名 #{$index}: {$domainName} vs {$this->currentHost}");
            
            if (DomainHelper::matchDomain($domainName, $this->currentHost)) {
                $currentIndex = $index;
                error_log("GroupRedirect: 域名匹配成功，索引: {$currentIndex}");
                break;
            }
        }
        
        if ($currentIndex === -1) {
            error_log("GroupRedirect: 当前域名不在链轮中");
            return null; // 当前域名不在链轮中
        }
        
        // 获取当前域名的配置
        $currentDomain = $domains[$currentIndex];
        
        // ★ 优先级1: 检查当前域名是否设置了"固定目标"（固定目标 100% 跳转，不受概率影响）
        if (is_array($currentDomain) && !empty($currentDomain['fixed_target'])) {
            $fixedTarget = trim($currentDomain['fixed_target']);
            error_log("GroupRedirect: 当前域名设置了固定目标: {$fixedTarget}，100% 跳转");
            
            // 使用当前域名的配置构建目标URL（不使用任务组的权重关键词）
            $targetUrl = $this->buildTargetUrlWithDomainConfig($group, $currentDomain, $fixedTarget, true);
            return [$targetUrl, $currentDomain];
        }
        
        // ★ 优先级2: 检查整组是否设置了"固定目标"（固定目标 100% 跳转，不受概率影响）
        $groupFixedTarget = trim($group['fixed_target'] ?? $settings['fixed_target'] ?? '');
        if (!empty($groupFixedTarget)) {
            error_log("GroupRedirect: 整组设置了固定目标: {$groupFixedTarget}，100% 跳转");
            
            // 使用任务组的配置构建目标URL（使用任务组的权重关键词）
            $targetUrl = $this->buildTargetUrlWithDomainConfig($group, $currentDomain, $groupFixedTarget, false);
            return [$targetUrl, $currentDomain];
        }
        
        // ★ 优先级3: 链轮跳转（受任务概率影响）
        error_log("GroupRedirect: 没有固定目标，检查任务概率");
        
        // 检查概率
        if ($probability < 100) {
            $rand = mt_rand(1, 100);
            error_log("GroupRedirect: 概率检查 - 随机数: {$rand}, 阈值: {$probability}");
            if ($rand > $probability) {
                error_log("GroupRedirect: 概率未命中，不跳转");
                return null;
            }
        }
        
        error_log("GroupRedirect: 概率命中，执行链轮跳转");
        
        // ★ 获取链轮模式（sequential=顺序/跑火车, random=随机）
        $chainMode = $group['chain_mode'] ?? $settings['chain_mode'] ?? 'sequential';
        
        if ($chainMode === 'random') {
            // 随机模式：从其他域名中随机选择一个
            error_log("GroupRedirect: 使用随机链轮模式");
            
            // 排除当前域名
            $otherDomains = [];
            foreach ($domains as $idx => $domain) {
                if ($idx !== $currentIndex) {
                    $otherDomains[] = $domain;
                }
            }
            
            if (empty($otherDomains)) {
                error_log("GroupRedirect: 没有其他域名可跳转");
                return null;
            }
            
            // 随机选择
            $nextDomain = $otherDomains[array_rand($otherDomains)];
            $nextDomainName = is_array($nextDomain) ? ($nextDomain['domain'] ?? '') : $nextDomain;
            
            error_log("GroupRedirect: 随机链轮跳转到: {$nextDomainName}");
        } else {
            // 顺序模式（跑火车）：按顺序跳转到下一个域名
            error_log("GroupRedirect: 使用顺序链轮模式（跑火车）");
            
            $nextIndex = ($currentIndex + 1) % count($domains);
            $nextDomain = $domains[$nextIndex];
            $nextDomainName = is_array($nextDomain) ? ($nextDomain['domain'] ?? '') : $nextDomain;
            
            error_log("GroupRedirect: 顺序链轮跳转到: {$nextDomainName}");
        }
        
        if (empty($nextDomainName)) {
            return null;
        }
        
        // 使用任务组的配置（使用任务组的权重关键词）
        $targetUrl = $this->buildTargetUrlWithDomainConfig($group, $currentDomain, $nextDomainName, false);
        return [$targetUrl, $currentDomain];
    }
    
    /**
     * 构建目标URL（旧方法，保留兼容性）
     */
    private function buildTargetUrl($group, $targetDomain) {
        $settings = $group['settings'] ?? [];
        $protocol = $this->getCurrentProtocol();
        $host = $targetDomain;
        $uri = '/';
        
        // 跟随二级域名
        if (!empty($settings['follow_subdomain'])) {
            $subdomainPrefix = DomainHelper::extractSubdomainPrefix($this->currentHost);
            if ($subdomainPrefix) {
                $host = $subdomainPrefix . '.' . $targetDomain;
            }
        }
        
        // 跟随URI（优先读取根级别配置，兼容旧版settings结构）
        $followUri = !empty($group['follow_uri']) || !empty($settings['follow_uri']);
        if ($followUri) {
            $uri = $this->currentUri;
        }
        
        return $protocol . '://' . $host . $uri;
    }
    
    /**
     * 构建目标URL（支持单个域名配置优先）
     * 
     * @param array $group 分组配置
     * @param array|string $domainConfig 当前域名配置
     * @param string $targetDomain 目标域名
     * @param bool $useDomainConfigOnly 是否只使用单个域名配置（不使用任务组的权重关键词）
     * @return string
     */
    private function buildTargetUrlWithDomainConfig($group, $domainConfig, $targetDomain, $useDomainConfigOnly = false) {
        $settings = $group['settings'] ?? [];
        $protocol = $this->getCurrentProtocol();
        
        // 如果目标域名已经是完整URL（包含协议），直接使用
        if (preg_match('#^https?://#i', $targetDomain)) {
            error_log("GroupRedirect: 目标是完整URL: {$targetDomain}");
            return $targetDomain;
        }
        
        // 检查目标域名是否包含路径（例如：baidu.com/path/to/page.html）
        $hasPath = (strpos($targetDomain, '/') !== false);
        
        $host = $targetDomain;
        $uri = '/';
        
        // 如果目标域名已经包含路径，提取域名和路径
        if ($hasPath) {
            $parts = explode('/', $targetDomain, 2);
            $host = $parts[0];
            $uri = '/' . $parts[1];
        }
        
        // ★ 优先使用单个域名的配置，如果没有则使用任务配置
        $followSubdomain = false;
        $followUri = false;
        
        if (is_array($domainConfig)) {
            // 检查是否有单独配置（不为空且不是"跟随任务"）
            if (isset($domainConfig['follow_subdomain'])) {
                $followSubdomain = !empty($domainConfig['follow_subdomain']);
            } else {
                // 默认跟随任务配置（优先读取根级别，兼容旧版settings结构）
                $followSubdomain = !empty($group['follow_subdomain']) || !empty($settings['follow_subdomain']);
            }
            
            if (isset($domainConfig['follow_uri'])) {
                $followUri = !empty($domainConfig['follow_uri']);
            } else {
                // 默认跟随任务配置（优先读取根级别，兼容旧版settings结构）
                $followUri = !empty($group['follow_uri']) || !empty($settings['follow_uri']);
            }
        } else {
            // 如果域名配置是字符串（旧格式），使用任务配置（优先读取根级别，兼容旧版settings结构）
            $followSubdomain = !empty($group['follow_subdomain']) || !empty($settings['follow_subdomain']);
            $followUri = !empty($group['follow_uri']) || !empty($settings['follow_uri']);
        }
        
        // ★ 权重二级/内页关键词处理（只在非单个域名固定目标时使用）
        $weightKeywords = $settings['weight_keywords'] ?? [];
        $weightMode = $settings['weight_mode'] ?? '';
        
        if (!$useDomainConfigOnly && !empty($weightKeywords) && is_array($weightKeywords)) {
            // 随机选择一个关键词
            $keyword = $weightKeywords[array_rand($weightKeywords)];
            error_log("GroupRedirect: 使用权重关键词 - {$keyword}");
            
            // 根据组合模式处理
            if ($weightMode === 'subdomain') {
                // 组合二级域名：news.example.com
                $host = $keyword . '.' . $host;
                error_log("GroupRedirect: 拼接二级域名 - {$host}");
            } elseif ($weightMode === 'uri') {
                // 组合内页URI：example.com/news
                if (!$hasPath) {
                    $uri = '/' . $keyword;
                    error_log("GroupRedirect: 拼接内页URI - {$uri}");
                }
            }
        } elseif ($useDomainConfigOnly) {
            error_log("GroupRedirect: 单个域名固定目标，不使用任务组的权重关键词");
        }
        
        // 跟随二级域名（在权重关键词之后处理）
        if ($followSubdomain) {
            $subdomainPrefix = DomainHelper::extractSubdomainPrefix($this->currentHost);
            if ($subdomainPrefix) {
                // 如果已经有权重关键词，则在最前面添加原二级前缀
                if (!empty($weightKeywords) && $weightMode === 'subdomain') {
                    $host = $subdomainPrefix . '.' . $host;
                    error_log("GroupRedirect: 跟随二级域名（权重模式）- {$subdomainPrefix}.{$host}");
                } else {
                    $host = $subdomainPrefix . '.' . $targetDomain;
                    error_log("GroupRedirect: 跟随二级域名 - {$subdomainPrefix}.{$targetDomain}");
                }
            }
        }
        
        // 跟随URI（如果目标域名已经包含路径，则不再追加currentUri）
        if ($followUri && !$hasPath) {
            // 如果使用了权重关键词的URI模式，不覆盖
            if (empty($weightKeywords) || $weightMode !== 'uri') {
                $uri = $this->currentUri;
                error_log("GroupRedirect: 跟随URI - {$uri}");
            }
        }
        
        $finalUrl = $protocol . '://' . $host . $uri;
        error_log("GroupRedirect: 最终URL - {$finalUrl}");
        
        // 替换占位符
        $finalUrl = \Redirect301\Utils\PlaceholderHelper::replace($finalUrl);
        
        return $finalUrl;
    }
    
    /**
     * 从 Redis 加载站群链轮配置
     */
    private function loadGroupsFromRedis() {
        // 需要引入 Redis 函数
        $redisConfigFile = dirname(__DIR__) . '/admin/redis_config.php';
        if (file_exists($redisConfigFile)) {
            require_once $redisConfigFile;
            
            if (function_exists('getAllGroupsFromRedis')) {
                return getAllGroupsFromRedis();
            }
        }
        
        return [];
    }
    
    /**
     * 更新统计
     */
    private function incrementStats($groupId) {
        // ★ 只更新 Redis，不再更新 JSON 文件
        // 原因：每次跳转都写入 JSON 会导致并发冲突，覆盖用户刚添加的任务
        // JSON 文件只在后台管理操作时写入，统计数据完全依赖 Redis
        $redisConfigFile = dirname(__DIR__) . '/admin/redis_config.php';
        if (file_exists($redisConfigFile)) {
            require_once $redisConfigFile;
            
            if (function_exists('incrementGroupStats')) {
                incrementGroupStats($groupId, 'total_redirects', 1);
            }
        }
        
        // ★ 已移除 JSON 写入逻辑，避免频繁刷新 groups.json 导致数据丢失
    }
}

