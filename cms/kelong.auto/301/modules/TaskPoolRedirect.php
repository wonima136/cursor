<?php
namespace Redirect301\Modules;

/**
 * 消耗池重定向模块
 * 优先级：4
 */
class TaskPoolRedirect extends RedirectModule {
    
    public function getName() {
        return '消耗池';
    }
    
    public function getPriority() {
        return 4;
    }
    
    public function check() {
        // 1. 全局概率判断（只判断一次）
        if (!$this->checkGlobalProbability()) {
            return null;
        }
        
        // 2. 获取所有启用的任务
        $tasks = $this->getEnabledTasks();
        
        if (empty($tasks)) {
            return null;
        }
        
        // 3. 实现任务轮询机制（公平分配）
        $tasks = $this->rotateTasksOrder($tasks);
        
        // 4. 遍历所有任务，严格按照配置检查
        error_log("TaskPoolRedirect: 开始遍历 " . count($tasks) . " 个任务");
        foreach ($tasks as $task) {
            error_log("TaskPoolRedirect: 检查任务 " . $task['id'] . " - " . $task['name']);
            error_log("TaskPoolRedirect: spider_filter = " . json_encode($task['spider_filter'] ?? []));
            
            // 验证蜘蛛筛选
            if (!$this->validateSpider($task['spider_filter'] ?? [])) {
                error_log("TaskPoolRedirect: 蜘蛛验证失败，跳过任务");
                continue;
            }
            
            error_log("TaskPoolRedirect: 蜘蛛验证通过");
            
            // 检查速度限制
            if (!$this->checkSpeedLimit($task)) {
                continue;
            }
            
            // 消耗一个链接
            $targetUrl = $this->consumeLink($task);
            
            if ($targetUrl) {
                // 应用占位符
                $targetUrl = $this->applyPlaceholders($targetUrl, $task);
                
                // 执行重定向
                $httpCode = $task['redirect_type'] ?? 301;
                $this->redirect($targetUrl, $task['name'], $httpCode);
                return; // 成功跳转，结束
            }
        }
        
        // 5. 没有找到可用任务，放弃跳转
        // error_log("TaskPoolRedirect: Probability passed but no available task found (blocked by speed limit or no links)");
        return null;
    }
    
    /**
     * 获取所有启用的任务
     */
    private function getEnabledTasks() {
        $prefix = $this->redis->getPrefix();
        
        // 兼容旧系统的任务前缀
        // 旧系统使用: task:{SITE_ID}:
        // 新系统使用: bigsite:{SITE_ID}:task:
        // 优先使用旧系统格式
        if (defined('REDIS_TASK_PREFIX')) {
            $taskPrefix = REDIS_TASK_PREFIX;
        } else {
            // 从 bigsite:xxxxx: 提取 xxxxx
            $siteId = str_replace(['bigsite:', ':'], '', $prefix);
            $taskPrefix = 'task:' . $siteId . ':';
        }
        
        // 调试日志
        // error_log("TaskPoolRedirect: 使用任务前缀 = {$taskPrefix}");
        
        // 从Redis获取所有任务
        $redis = $this->redis->getConnection();
        if (!$redis) {
            // error_log("TaskPoolRedirect: Redis连接失败");
            return [];
        }
        
        $taskKeys = $redis->keys($taskPrefix . '*:stats');
        // error_log("TaskPoolRedirect: 找到 " . count($taskKeys) . " 个任务键");
        $tasks = [];
        
        foreach ($taskKeys as $key) {
            // 提取任务ID
            $taskId = str_replace([$taskPrefix, ':stats'], '', $key);
            
            // 获取任务统计
            $stats = $redis->hGetAll($key);
            
            // ★ 检查是否启用（必须明确启用才执行，如果字段不存在则默认禁用）
            $enabled = isset($stats['enabled']) && $stats['enabled'] === '1';
            if (!$enabled) {
                $this->logger->debug("TaskPoolRedirect: Task {$taskId} is not enabled, skipping.");
                continue;
            }
            
            // 检查是否还有可用链接
            $availableLinks = intval($stats['available_links'] ?? 0);
            if ($availableLinks <= 0) {
                // 自动禁用任务，避免后续无谓的判断
                $this->autoDisableTask($taskId, $redis, $key);
                continue;
            }
            
            // 获取任务配置
            // 优先从 Redis 读取，如果不存在则从 JSON 文件读取（兼容旧系统）
            $configKey = $taskPrefix . $taskId . ':config';
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
                'redirect_type' => intval($taskConfig['redirect_type'] ?? 301),
                'speed_control' => $taskConfig['speed_control'] ?? ['enabled' => false],
                'custom_params' => $taskConfig['custom_params'] ?? [],
                'spider_filter' => $taskConfig['spider_filter'] ?? ['enabled' => false],
                'available_links' => $availableLinks
            ];
        }
        
        return $tasks;
    }
    
    /**
     * 检查全局概率（只判断一次）
     */
    private function checkGlobalProbability() {
        // 消耗池使用全局设置中的概率
        $probability = $this->getGlobalProbability();
        
        // ★ 边界检查：确保概率在有效范围内
        $probability = max(0, min(100, floatval($probability)));
        
        if ($probability >= 100) {
            return true;
        }
        
        if ($probability <= 0) {
            return false;
        }
        
        $rand = mt_rand(1, 100);
        $passed = $rand <= $probability;
        
        if ($passed) {
            // error_log("TaskPoolRedirect: Global probability check passed ({$rand} <= {$probability})");
        }
        
        return $passed;
    }
    
    /**
     * 检查速度限制
     * @param array $task 任务配置
     * @return bool
     */
    private function checkSpeedLimit($task) {
        // 检查任务是否启用了速度控制
        $speedControl = $task['speed_control'] ?? [];
        if (empty($speedControl['enabled'])) {
            return true; // 未启用速度控制，直接通过
        }
        
        $taskId = $task['id'];
        $dimension = $speedControl['dimension'] ?? 'task'; // 默认按任务限速
        
        // 根据限速维度选择不同的检查方法
        if ($dimension === 'domain') {
            return $this->checkDomainSpeedLimit($taskId, $speedControl);
        } else {
            return $this->checkTaskSpeedLimit($taskId, $speedControl);
        }
    }
    
    /**
     * 按域名检查速度限制
     */
    private function checkDomainSpeedLimit($taskId, $speedControl) {
        // 提取当前访问的域名
        $currentHost = $_SERVER['HTTP_HOST'] ?? '';
        if (empty($currentHost)) {
            return true; // 无法获取域名，放行
        }
        
        // 提取主域名（去掉www等前缀）
        $domain = preg_replace('/^www\./i', '', $currentHost);
        
        $redis = $this->redis->getConnection();
        if (!$redis) {
            return true; // Redis不可用，放行
        }
        
        // 使用消耗池专用前缀 task:xxx:
        require_once __DIR__ . '/../admin/redis_config.php';
        $prefix = REDIS_TASK_PREFIX;
        $limitKeyPrefix = "{$prefix}{$taskId}:limit:domain:{$domain}";
        
        return $this->checkSpeedLimitInternal($redis, $limitKeyPrefix, $speedControl, "domain:{$domain}");
    }
    
    /**
     * 按任务检查速度限制
     */
    private function checkTaskSpeedLimit($taskId, $speedControl) {
        $redis = $this->redis->getConnection();
        if (!$redis) {
            return true; // Redis不可用，放行
        }
        
        // 使用消耗池专用前缀 task:xxx:
        require_once __DIR__ . '/../admin/redis_config.php';
        $prefix = REDIS_TASK_PREFIX;
        $limitKeyPrefix = "{$prefix}{$taskId}:limit";
        
        return $this->checkSpeedLimitInternal($redis, $limitKeyPrefix, $speedControl, "task");
    }
    
    /**
     * 速度限制检查的内部实现
     */
    private function checkSpeedLimitInternal($redis, $keyPrefix, $speedControl, $logLabel) {
        $now = time();
        
        // 1. 检查最小间隔
        $minInterval = intval($speedControl['min_interval'] ?? 0);
        if ($minInterval > 0) {
            $lastTimeKey = "{$keyPrefix}:last_time";
            $lastTime = (int)$redis->get($lastTimeKey);
            
            if ($lastTime > 0 && ($now - $lastTime) < $minInterval) {
                $remaining = $minInterval - ($now - $lastTime);
                // error_log("TaskPoolRedirect: Speed limit hit for {$logLabel} - min_interval ({$remaining}s remaining)");
                return false;
            }
        }
        
        // 2. 检查每小时限制
        $maxPerHour = intval($speedControl['max_per_hour'] ?? 0);
        if ($maxPerHour > 0) {
            $hourKey = "{$keyPrefix}:hourly:" . date('YmdH');
            $hourlyCount = (int)$redis->get($hourKey);
            
            if ($hourlyCount >= $maxPerHour) {
                // error_log("TaskPoolRedirect: Speed limit hit for {$logLabel} - max_per_hour ({$hourlyCount}/{$maxPerHour})");
                return false;
            }
        }
        
        // 3. 检查每天限制
        $maxPerDay = intval($speedControl['max_per_day'] ?? 0);
        if ($maxPerDay > 0) {
            $dayKey = "{$keyPrefix}:daily:" . date('Ymd');
            $dailyCount = (int)$redis->get($dayKey);
            
            if ($dailyCount >= $maxPerDay) {
                // error_log("TaskPoolRedirect: Speed limit hit for {$logLabel} - max_per_day ({$dailyCount}/{$maxPerDay})");
                return false;
            }
        }
        
        // 通过所有检查，记录本次访问
        if ($minInterval > 0) {
            $redis->set("{$keyPrefix}:last_time", $now);
            $redis->expire("{$keyPrefix}:last_time", $minInterval * 2); // 过期时间为间隔的2倍
        }
        
        if ($maxPerHour > 0) {
            $hourKey = "{$keyPrefix}:hourly:" . date('YmdH');
            $redis->incr($hourKey);
            $redis->expire($hourKey, 3600); // 1小时过期
        }
        
        if ($maxPerDay > 0) {
            $dayKey = "{$keyPrefix}:daily:" . date('Ymd');
            $redis->incr($dayKey);
            $redis->expire($dayKey, 86400); // 24小时过期
        }
        
        return true;
    }
    
    /**
     * 应用占位符到目标URL
     */
    private function applyPlaceholders($url, $task) {
        // 使用 PlaceholderHelper 替换占位符
        if (class_exists('\Redirect301\Utils\PlaceholderHelper')) {
            // 获取自定义参数
            $customParams = [];
            if (!empty($task['custom_params'])) {
                $customParams = $task['custom_params'];
            }
            
            $url = \Redirect301\Utils\PlaceholderHelper::replace($url, $customParams);
        }
        
        return $url;
    }
    
    /**
     * 实现任务轮询机制（公平分配）
     */
    private function rotateTasksOrder($tasks) {
        if (count($tasks) <= 1) {
            return $tasks;
        }
        
        $redis = $this->redis->getConnection();
        if (!$redis) {
            return $tasks; // Redis不可用，返回原顺序
        }
        
        $prefix = $this->redis->getPrefix();
        $rotateKey = "{$prefix}consumption_pool:last_task_index";
        
        // 获取上次执行的任务索引
        $lastIndex = (int)$redis->get($rotateKey);
        
        // 计算本次开始的索引（从下一个任务开始）
        $startIndex = ($lastIndex + 1) % count($tasks);
        
        // 重新排列任务顺序
        $rotatedTasks = array_merge(
            array_slice($tasks, $startIndex),
            array_slice($tasks, 0, $startIndex)
        );
        
        // 更新索引（指向本次开始的任务）
        $redis->set($rotateKey, $startIndex);
        $redis->expire($rotateKey, 86400); // 24小时过期
        
        // error_log("TaskPoolRedirect: Rotated tasks order, starting from index {$startIndex}");
        
        return $rotatedTasks;
    }
    
    /**
     * 获取全局跳转概率
     */
    private function getGlobalProbability() {
        // 从全局设置中读取概率
        $settingsFile = defined('SETTINGS_FILE') 
            ? SETTINGS_FILE 
            : __DIR__ . '/../admin/data/settings.json';
        
        if (!file_exists($settingsFile)) {
            return 20; // 默认20%
        }
        
        $content = @file_get_contents($settingsFile);
        if ($content === false) {
            return 20;
        }
        
        $settings = json_decode($content, true);
        if (!is_array($settings)) {
            return 20;
        }
        
        return floatval($settings['redirect_probability'] ?? 20);
    }
    
    /**
     * 消耗一个链接
     */
    private function consumeLink($task) {
        $taskId = $task['id'];
        $prefix = $this->redis->getPrefix();
        
        // 使用任务前缀（兼容旧系统）
        if (defined('REDIS_TASK_PREFIX')) {
            $taskPrefix = REDIS_TASK_PREFIX;
        } else {
            $siteId = str_replace(['bigsite:', ':'], '', $prefix);
            $taskPrefix = 'task:' . $siteId . ':';
        }
        
        $redis = $this->redis->getConnection();
        
        if (!$redis) {
            return null;
        }
        
        // 简单的链接消耗逻辑（不使用 WATCH，避免高并发阻塞）
        $availableKey = $taskPrefix . $taskId . ':available';
        $linkId = $redis->sRandMember($availableKey);
        
        if (!$linkId) {
            return null;
        }
        
        $linkKey = $taskPrefix . $taskId . ':link:' . $linkId;
        $linkData = $redis->hGetAll($linkKey);
        
        if (empty($linkData['url'])) {
            return null;
        }
        
        // 获取当前使用次数和总次数
        $used = (int)($linkData['used'] ?? 0);
        $total = (int)($linkData['total'] ?? 1);
        
        // 检查是否已用完
        if ($used >= $total) {
            // 已用完，从集合中移除
            $redis->sRem($availableKey, $linkId);
            $redis->hIncrBy($taskPrefix . $taskId . ':stats', 'available_links', -1);
            return null;
        }
        
        // 递增 used 字段
        $newUsed = $redis->hIncrBy($linkKey, 'used', 1);
        $redis->hSet($linkKey, 'last_used', date('Y-m-d H:i:s'));
        
        // 如果刚好用完，从集合中移除
        if ($newUsed >= $total) {
            $redis->sRem($availableKey, $linkId);
            $redis->hIncrBy($taskPrefix . $taskId . ':stats', 'available_links', -1);
        }
        
        // 更新任务统计
        $redis->hIncrBy($taskPrefix . $taskId . ':stats', 'total_redirects', 1);
        
        return $linkData['url'];
    }
    
    /**
     * 自动禁用任务（当链接用完时）
     */
    private function autoDisableTask($taskId, $redis, $statsKey) {
        // 1. 更新 Redis 中的 enabled 状态
        $redis->hSet($statsKey, 'enabled', '0');
        // error_log("TaskPoolRedirect: Task {$taskId} has no available links, auto-disabled in Redis.");
        
        // 2. 更新 JSON 文件中的 enabled 状态
        $tasksFile = defined('_R301TASK_DATA_FILE_') 
            ? _R301TASK_DATA_FILE_ 
            : __DIR__ . '/../admin/data/tasks.json';
        
        if (!file_exists($tasksFile)) {
            return;
        }
        
        $content = @file_get_contents($tasksFile);
        if ($content === false) {
            return;
        }
        
        $tasks = json_decode($content, true);
        if (!is_array($tasks)) {
            return;
        }
        
        // 查找并更新任务
        $updated = false;
        foreach ($tasks as &$task) {
            if (isset($task['id']) && $task['id'] === $taskId) {
                $task['enabled'] = false;
                $task['auto_stopped_at'] = date('Y-m-d H:i:s');
                $task['updated_at'] = date('Y-m-d H:i:s');
                $updated = true;
                break;
            }
        }
        unset($task);
        
        if ($updated) {
            // 使用临时文件 + 重命名确保原子写入
            $tempFile = $tasksFile . '.tmp.' . getmypid();
            $result = file_put_contents(
                $tempFile,
                json_encode($tasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                LOCK_EX
            );
            
            if ($result !== false) {
                rename($tempFile, $tasksFile);
                // error_log("TaskPoolRedirect: Task {$taskId} auto-disabled in JSON file.");
            } else {
                @unlink($tempFile);
            }
        }
    }
    
    /**
     * 从 JSON 文件加载任务配置（兼容旧系统）
     */
    private function loadTaskConfigFromFile($taskId) {
        $tasksFile = defined('_R301TASK_DATA_FILE_') 
            ? _R301TASK_DATA_FILE_ 
            : __DIR__ . '/../admin/data/tasks.json';
        
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
                // 转换旧格式到新格式
                return $this->convertOldTaskFormat($task);
            }
        }
        
        return null;
    }
    
    /**
     * 转换旧任务格式到新格式
     */
    private function convertOldTaskFormat($task) {
        // 构建新格式（移除爬虫配置）
        return [
            'id' => $task['id'] ?? '',
            'name' => $task['name'] ?? '未命名任务',
            'redirect_type' => intval($task['redirect_type'] ?? 301),
            'speed_control' => $task['speed_control'] ?? ['enabled' => false],
            'custom_params' => $task['custom_params'] ?? [],
        ];
    }
}

