<?php
namespace Redirect301\Modules;

use Redirect301\Utils\DomainHelper;
use Redirect301\Utils\PlaceholderHelper;

/**
 * 大站池重定向模块
 * 优先级：2
 */
class BigsiteRedirect extends RedirectModule {
    
    public function getName() {
        return '大站池';
    }
    
    public function getPriority() {
        return 2;
    }
    
    public function check() {
        // 获取所有启用的任务
        $tasks = $this->getEnabledTasks();
        
        if (empty($tasks)) {
            return null;
        }
        
        // 遍历任务检查规则
        foreach ($tasks as $task) {
            // 验证蜘蛛筛选
            if (!$this->validateSpider($task['spider_filter'] ?? [])) {
                continue; // 跳过此任务
            }
            
            $result = $this->matchRule($task);
            
            if ($result) {
                // 更新统计
                $this->incrementStats($task['id']);
                
                // ⭐ 修复：使用规则级别的跳转类型
                $targetUrl = $result['url'];
                $redirectType = intval($result['redirect_type'] ?? $task['redirect_type'] ?? 301);
                
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
        
        // 获取所有大站池任务
        $taskKeys = $redis->keys($prefix . 'bigsite:task:*:stats');
        $tasks = [];
        
        foreach ($taskKeys as $key) {
            // 提取任务ID
            preg_match('/bigsite:task:(.+):stats/', $key, $matches);
            if (empty($matches[1])) {
                continue;
            }
            
            $taskId = $matches[1];
            
            // 获取任务统计
            $stats = $redis->hGetAll($key);
            
            if (empty($stats['enabled']) || $stats['enabled'] !== '1') {
                continue;
            }
            
            // 获取任务配置
            // 优先从 Redis 读取，如果不存在则从 JSON 文件读取（兼容旧系统）
            $configKey = $prefix . 'bigsite:task:' . $taskId . ':config';
            $config = $redis->get($configKey);
            
            if ($config) {
                // 从 Redis 读取
                $taskConfig = json_decode($config, true);
                if (!$taskConfig) {
                    continue;
                }
            } else {
                // 从 JSON 文件读取（兼容旧系统）
                $taskConfig = $this->loadTaskConfigFromFile($taskId);
                if (!$taskConfig) {
                    continue;
                }
            }
            
            $tasks[] = [
                'id' => $taskId,
                'name' => $taskConfig['name'] ?? '未命名任务',
                'redirect_type' => $taskConfig['redirect_type'] ?? $taskConfig['settings']['redirect_type'] ?? '301',
                'probability' => intval($taskConfig['probability'] ?? $taskConfig['settings']['probability'] ?? 100), // ⭐ 概率控制
                'smart_strategy' => $taskConfig['smart_strategy'] ?? [ // ⭐ 新增：智能策略
                    'enabled' => false,
                    'rules' => []
                ],
                'spider_filter' => $taskConfig['spider_filter'] ?? [],
                'rules' => $this->loadRules($taskId),
                'sites' => $this->loadSites($taskId)
            ];
        }
        
        return $tasks;
    }
    
    /**
     * 加载任务的规则
     */
    private function loadRules($taskId) {
        $prefix = $this->redis->getPrefix();
        $redis = $this->redis->getConnection();
        
        if (!$redis) {
            return [];
        }
        
        $rulesKey = $prefix . 'bigsite:task:' . $taskId . ':rules';
        $ruleIds = $redis->sMembers($rulesKey);
        $rules = [];
        
        foreach ($ruleIds as $ruleId) {
            $ruleKey = $prefix . 'bigsite:task:' . $taskId . ':rule:' . $ruleId;
            $ruleData = $redis->hGetAll($ruleKey);
            
            if (!empty($ruleData['source'])) {
                $rules[] = [
                    'id' => $ruleId,
                    'source' => $ruleData['source'],
                    'type' => $ruleData['type'] ?? 'domain',
                    'target' => $ruleData['target'] ?? $ruleData['target_url'] ?? ''  // ★ 加载预设的目标URL
                ];
            }
        }
        
        return $rules;
    }
    
    /**
     * 加载任务的大站URL池
     */
    private function loadSites($taskId) {
        $prefix = $this->redis->getPrefix();
        $redis = $this->redis->getConnection();
        
        if (!$redis) {
            return [];
        }
        
        $sitesKey = $prefix . 'bigsite:task:' . $taskId . ':sites';
        return $redis->sMembers($sitesKey);
    }
    
    /**
     * 匹配规则
     */
    private function matchRule($task) {
        $rules = $task['rules'] ?? [];
        $sites = $task['sites'] ?? [];
        
        // ⭐ 修复：只要有规则就可以继续（跳转对模式不需要sites）
        if (empty($rules)) {
            return null;
        }
        
        $prefix = $this->redis->getPrefix();
        $redis = $this->redis->getConnection();
        
        if (!$redis) {
            return null;
        }
        
        foreach ($rules as $rule) {
            $source = $rule['source'];
            $type = $rule['type'] ?? 'domain';
            $ruleId = $rule['id'];
            
            $matched = false;
            
            if ($type === 'url') {
                // 完整URL匹配
                $matched = $this->matchUrl($source);
            } else {
                // 域名匹配
                $matched = $this->matchDomain($source);
            }
            
            if ($matched) {
                // ★ 检查跳转次数限制
                $ruleKey = $prefix . 'bigsite:task:' . $task['id'] . ':rule:' . $ruleId;
                $usedKey = $prefix . 'bigsite:task:' . $task['id'] . ':rule:' . $ruleId . ':used';
                $completedKey = $prefix . 'bigsite:task:' . $task['id'] . ':rule:' . $ruleId . ':completed';
                
                // 检查是否已完成
                if ($redis->exists($completedKey)) {
                    continue;  // 跳过已完成的规则
                }
                
                // ⭐ 获取当前规则的使用次数（用于智能策略）
                $usedCount = intval($redis->get($usedKey) ?? 0);
                
                // ⭐ 智能概率判断
                $smartStrategy = $task['smart_strategy'] ?? [];
                if (!empty($smartStrategy['enabled'])) {
                    // 使用智能策略
                    $probability = $this->getSmartProbability($smartStrategy['rules'] ?? [], $usedCount);
                    $this->logger->debug("BigsiteRedirect: 使用智能策略概率 {$probability}% (已用{$usedCount}次)");
                } else {
                    // 使用任务固定概率
                    $probability = intval($task['probability'] ?? 100);
                    $this->logger->debug("BigsiteRedirect: 使用任务固定概率 {$probability}%");
                }
                
                // ⭐ 修复：先递增使用次数，再判断概率
                // 这样可以确保每次访问都会递减概率，而不是只有跳转成功才递减
                $usedCount = $redis->incr($usedKey);
                
                // 概率判断
                if ($probability < 100 && mt_rand(1, 100) > $probability) {
                    $this->logger->debug("BigsiteRedirect: 概率未命中，不跳转");
                    return null;
                }
                
                // 获取规则详情（包含跳转次数限制）
                $ruleData = $redis->hGetAll($ruleKey);
                $totalCount = (int)($ruleData['redirect_count'] ?? 1);
                
                // 检查是否超过限制
                if ($usedCount > $totalCount) {
                    // 标记为已完成（7天过期）
                    $redis->setex($completedKey, 604800, '1');
                    continue;
                }
                
                // 如果刚好达到上限，标记完成
                if ($usedCount >= $totalCount) {
                    $redis->setex($completedKey, 604800, '1');
                    // TODO: 记录到历史（可选）
                }
                
                // ★ 优先使用规则预设的目标URL（跳转对）
                $targetUrl = $rule['target'] ?? '';
                
                // 如果规则没有预设目标，则从大站URL池随机选择
                if (empty($targetUrl) && !empty($sites)) {
                    $targetUrl = $sites[array_rand($sites)];
                }
                
                // 如果还是没有目标URL，跳过此规则
                if (empty($targetUrl)) {
                    continue;
                }
                
                // 替换占位符
                $targetUrl = PlaceholderHelper::replace($targetUrl);
                
                // ⭐ 修复：返回目标URL和规则级别的跳转类型
                return [
                    'url' => $targetUrl,
                    'redirect_type' => $ruleData['redirect_type'] ?? ''
                ];
            }
        }
        
        return null;
    }
    
    /**
     * 匹配域名
     */
    private function matchDomain($pattern) {
        return DomainHelper::matchDomain($pattern, $this->currentHost);
    }
    
    /**
     * 获取智能策略概率
     */
    private function getSmartProbability($rules, $usedCount) {
        // 精确匹配使用次数
        if (isset($rules[(string)$usedCount])) {
            return intval($rules[(string)$usedCount]);
        }
        
        // 5次及以上使用 5+ 规则
        if ($usedCount >= 5 && isset($rules['5+'])) {
            return intval($rules['5+']);
        }
        
        // 默认100%
        return 100;
    }
    
    /**
     * 匹配URL
     */
    private function matchUrl($pattern) {
        // 移除协议头进行比较
        $currentUrl = preg_replace('#^https?://#i', '', $this->currentUrl);
        $pattern = preg_replace('#^https?://#i', '', $pattern);
        
        // 精确匹配
        if ($currentUrl === $pattern) {
            return true;
        }
        
        // 前缀匹配
        if (strpos($currentUrl, $pattern) === 0) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 更新统计
     */
    private function incrementStats($taskId) {
        $prefix = $this->redis->getPrefix();
        $statsKey = $prefix . 'bigsite:task:' . $taskId . ':stats';
        
        try {
            $this->redis->hIncrBy($statsKey, 'total_redirects', 1);
        } catch (\Exception $e) {
            // error_log("Failed to increment bigsite stats: " . $e->getMessage());
        }
    }
    
    /**
     * 从 JSON 文件加载任务配置（兼容旧系统）
     */
    private function loadTaskConfigFromFile($taskId) {
        $tasksFile = defined('_BIGSITE_TASK_DATA_FILE_') 
            ? _BIGSITE_TASK_DATA_FILE_ 
            : __DIR__ . '/../admin/data/bigsite_tasks.json';
        
        if (!file_exists($tasksFile)) {
            return null;
        }
        
        $content = @file_get_contents($tasksFile);
        if ($content === false) {
            return null;
        }
        
        $tasks = json_decode($content, true);
        if (!is_array($tasks)) {
            return null;
        }
        
        // 查找对应的任务
        foreach ($tasks as $task) {
            if (isset($task['id']) && $task['id'] === $taskId) {
                // 返回任务配置（大站池的配置结构相对简单）
                return [
                    'name' => $task['name'] ?? '未命名任务',
                    'redirect_type' => $task['redirect_type'] ?? $task['settings']['redirect_type'] ?? '301',
                    'spider_filter' => $task['spider_filter'] ?? []
                ];
            }
        }
        
        return null;
    }
}

