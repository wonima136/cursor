<?php
/**
 * Redis 配置文件
 */

// 引入配置文件以获取 REDIRECT301_DATA_DIR 常量
if (!defined('REDIRECT301_DATA_DIR')) {
    require_once __DIR__ . '/config.php';
}

// Redis 连接配置
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);
define('REDIS_PASSWORD', '');  // 如果有密码请填写
define('REDIS_DB', 1);         // 使用DB1，避免与其他程序冲突

// ★★★ 站点唯一标识（自动生成）★★★
// 根据 301 程序所在目录自动生成唯一标识，无需手动配置
// 使用目录路径的 MD5 前8位作为唯一标识
define('SITE_ID', substr(md5(dirname(__DIR__)), 0, 8));

// Redis 键名前缀（自动包含站点标识）
define('REDIS_PREFIX', 'bigsite:' . SITE_ID . ':');
define('REDIS_TASK_PREFIX', 'task:' . SITE_ID . ':');
define('REDIS_SITEWIDE_PREFIX', 'sitewide:' . SITE_ID . ':');
define('REDIS_PARASITE_PREFIX', 'parasite:' . SITE_ID . ':');
define('REDIS_CLONE_PREFIX', 'clone:' . SITE_ID . ':');

// 历史数据文件
define('BIGSITE_HISTORY_FILE', REDIRECT301_DATA_DIR . '/bigsite_history.json');

/**
 * 获取 Redis 连接
 */
function getRedis() {
    static $redis = null;
    
    if ($redis === null) {
        $redis = new Redis();
        
        try {
            // 连接超时3秒
            $redis->connect(REDIS_HOST, REDIS_PORT, 3);
            
            // ★★★ 关键修复：设置读写超时为3秒 ★★★
            // 防止Redis操作阻塞导致PHP-FPM进程挂起
            $redis->setOption(Redis::OPT_READ_TIMEOUT, 3);
            
            if (REDIS_PASSWORD) {
                $redis->auth(REDIS_PASSWORD);
            }
            
            $redis->select(REDIS_DB);
        } catch (Exception $e) {
            error_log('Redis connection failed: ' . $e->getMessage());
            return null;
        }
    }
    
    return $redis;
}

/**
 * 检查 Redis 是否可用
 */
function isRedisAvailable() {
    $redis = getRedis();
    if (!$redis) return false;
    
    try {
        return $redis->ping() === '+PONG' || $redis->ping() === true;
    } catch (Exception $e) {
        return false;
    }
}

// ==================== 大站池 Redis 操作 ====================

/**
 * 添加跳转规则到 Redis
 */
function addBigsiteRuleToRedis($sourceUrl, $targetUrl, $redirectCount, $redirectType = '302', $taskName = '', $taskId = '') {
    $redis = getRedis();
    if (!$redis) return false;
    
    if (empty($taskId)) {
        error_log("Warning: addBigsiteRuleToRedis called without taskId");
        return false;
    }
    
    $ruleId = md5($sourceUrl); // ★ 使用固定的ID，方便查询和更新
    $prefix = REDIS_PREFIX;
    
    // ★ 新版数据结构：bigsite:task:{taskId}:rule:{ruleId}
    $ruleKey = "{$prefix}bigsite:task:{$taskId}:rule:{$ruleId}";
    
    // 写入规则详情
    $ruleData = [
        'id' => $ruleId,
        'source' => $sourceUrl,
        'source_url' => $sourceUrl, // ★ 兼容后台显示
        'target' => $targetUrl,
        'target_url' => $targetUrl, // ★ 兼容后台显示
        'type' => 'url', // URL 完整匹配
        'redirect_count' => $redirectCount,
        'redirect_type' => $redirectType,
        'task_name' => $taskName,
        'task_id' => $taskId,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    $redis->hMSet($ruleKey, $ruleData);
    
    // ★ 将规则ID添加到规则列表集合
    $rulesSetKey = "{$prefix}bigsite:task:{$taskId}:rules";
    $redis->sAdd($rulesSetKey, $ruleId);
    
    // 初始化使用次数计数器（新版使用独立的键）
    $usedKey = "{$prefix}bigsite:task:{$taskId}:rule:{$ruleId}:used";
    $redis->set($usedKey, 0);
    
    // 更新任务统计
    $statsKey = "{$prefix}bigsite:task:{$taskId}:stats";
    $redis->hIncrBy($statsKey, 'total_rules', 1);
    $redis->hIncrBy($statsKey, 'active_rules', 1);
    
    return true;
}

/**
 * 从 Redis 删除跳转规则
 */
function deleteBigsiteRuleFromRedis($sourceUrl, $taskId = '') {
    $redis = getRedis();
    if (!$redis) return false;
    
    $ruleId = md5($sourceUrl);
    $prefix = REDIS_PREFIX;
    
    // 如果提供了任务ID，删除任务级别的规则
    if (!empty($taskId)) {
        $taskPrefix = "{$prefix}bigsite:task:{$taskId}:";
        
        // 删除规则详情
        $redis->del("{$taskPrefix}rule:{$ruleId}");
        // 删除使用计数
        $redis->del("{$taskPrefix}rule:{$ruleId}:used");
        // 删除完成标记
        $redis->del("{$taskPrefix}rule:{$ruleId}:completed");
        // 从规则集合中移除
        $redis->sRem("{$taskPrefix}rules", $ruleId);
        
        return true;
    }
    
    // 兼容旧版（全局规则）
    $redis->del("{$prefix}rule:{$ruleId}");
    $redis->del("{$prefix}used:{$ruleId}");
    $redis->del("{$prefix}completed:{$ruleId}");
    $redis->del("{$prefix}index:{$sourceUrl}");
    
    // 删除路径索引
    $path = parse_url($sourceUrl, PHP_URL_PATH);
    if ($path && $path !== $sourceUrl) {
        $redis->del("{$prefix}path:{$path}");
    }
    
    return true;
}

/**
 * 检查规则是否在 Redis 中存在（活跃状态）
 */
function isBigsiteRuleActiveInRedis($sourceUrl, $taskId = '') {
    $redis = getRedis();
    if (!$redis) return false;
    
    $prefix = REDIS_PREFIX;
    $ruleId = md5($sourceUrl);
    
    // 如果提供了任务ID，检查任务级别的规则
    if (!empty($taskId)) {
        $ruleKey = "{$prefix}bigsite:task:{$taskId}:rule:{$ruleId}";
        return $redis->exists($ruleKey);
    }
    
    // 兼容旧版（全局规则）
    return $redis->exists($prefix . "rule:{$ruleId}");
}

/**
 * 获取 Redis 中的规则详情
 */
function getBigsiteRuleFromRedis($sourceUrl, $taskId = '') {
    $redis = getRedis();
    if (!$redis) return null;
    
    $ruleId = md5($sourceUrl);
    $prefix = REDIS_PREFIX;
    
    // 如果提供了任务ID，从任务级别获取规则
    if (!empty($taskId)) {
        $ruleKey = "{$prefix}bigsite:task:{$taskId}:rule:{$ruleId}";
        $rule = $redis->hGetAll($ruleKey);
        
        if (empty($rule)) return null;
        
        $usedKey = "{$prefix}bigsite:task:{$taskId}:rule:{$ruleId}:used";
        $rule['used_count'] = (int)$redis->get($usedKey);
        
        return $rule;
    }
    
    // 兼容旧版（全局规则）
    $rule = $redis->hGetAll("{$prefix}rule:{$ruleId}");
    if (empty($rule)) return null;
    
    $rule['used_count'] = (int)$redis->get("{$prefix}used:{$ruleId}");
    
    return $rule;
}

/**
 * 获取所有活跃规则（从 Redis）
 */
function getAllActiveBigsiteRules($taskId = '') {
    $redis = getRedis();
    if (!$redis) return [];
    
    $prefix = REDIS_PREFIX;
    $rules = [];
    
    // 如果提供了任务ID，获取该任务的所有规则
    if (!empty($taskId)) {
        $taskPrefix = "{$prefix}bigsite:task:{$taskId}:";
        
        // 从规则集合获取所有规则ID
        $ruleIds = $redis->sMembers("{$taskPrefix}rules");
        
        foreach ($ruleIds as $ruleId) {
            $ruleKey = "{$taskPrefix}rule:{$ruleId}";
            $rule = $redis->hGetAll($ruleKey);
            
            if (!empty($rule)) {
                // 获取使用次数
                $usedKey = "{$taskPrefix}rule:{$ruleId}:used";
                $rule['used_count'] = (int)$redis->get($usedKey);
                
                // 检查是否已标记为完成
                $completedKey = "{$taskPrefix}rule:{$ruleId}:completed";
                $rule['is_completed'] = (bool)$redis->get($completedKey);
                
                $rules[] = $rule;
            }
        }
        
        return $rules;
    }
    
    // 兼容旧版：获取所有全局规则
    $keys = $redis->keys("{$prefix}rule:*");
    
    foreach ($keys as $key) {
        $rule = $redis->hGetAll($key);
        if (!empty($rule)) {
            $md5Key = str_replace("{$prefix}rule:", '', $key);
            $rule['used_count'] = (int)$redis->get("{$prefix}used:{$md5Key}");
            $rule['is_completed'] = (bool)$redis->get("{$prefix}completed:{$md5Key}");
            $rules[] = $rule;
        }
    }
    
    return $rules;
}

/**
 * 清空 Redis 中所有大站池数据
 */
function clearAllBigsiteFromRedis() {
    $redis = getRedis();
    if (!$redis) return false;
    
    $prefix = REDIS_PREFIX;
    $deletedCount = 0;
    $totalFound = 0;
    
    // 删除所有相关 key
    $patterns = ['rule:*', 'used:*', 'index:*', 'path:*', 'completed:*', 'sites'];
    
    foreach ($patterns as $pattern) {
        $fullPattern = "{$prefix}{$pattern}";
        $keys = $redis->keys($fullPattern);
        
        if (!empty($keys) && is_array($keys)) {
            $totalFound += count($keys);
            // 逐个删除，确保每个key都被删除
            foreach ($keys as $key) {
                $result = $redis->del($key);
                if ($result > 0) {
                    $deletedCount++;
                }
            }
        }
    }
    
    // 记录详细日志
    error_log("Bigsite Clear: Found {$totalFound} keys, deleted {$deletedCount} keys. Prefix: {$prefix}, DB: " . REDIS_DB);
    
    // 验证清空结果
    $remainingKeys = $redis->keys("{$prefix}*");
    if (!empty($remainingKeys)) {
        error_log("Bigsite Clear Warning: Still have " . count($remainingKeys) . " keys remaining after clear!");
    }
    
    return $deletedCount > 0 || $totalFound === 0;
}

/**
 * 重置规则计数
 */
function resetBigsiteRuleCount($sourceUrl) {
    $redis = getRedis();
    if (!$redis) return false;
    
    $key = md5($sourceUrl);
    $redis->set(REDIS_PREFIX . "used:{$key}", 0);
    
    return true;
}

// ==================== 大站 URL 池操作 ====================

/**
 * 添加大站 URL 到 Redis
 */
function addBigsiteSiteToRedis($url, $taskId = '') {
    $redis = getRedis();
    if (!$redis) return false;
    
    $prefix = REDIS_PREFIX;
    
    // 如果提供了任务ID，使用新版结构
    if (!empty($taskId)) {
        return $redis->sAdd("{$prefix}bigsite:task:{$taskId}:sites", $url);
    }
    
    // 兼容旧版（全局大站池）
    return $redis->sAdd($prefix . 'sites', $url);
}

/**
 * 从 Redis 删除大站 URL
 */
function deleteBigsiteSiteFromRedis($url, $taskId = '') {
    $redis = getRedis();
    if (!$redis) return false;
    
    $prefix = REDIS_PREFIX;
    
    // 如果提供了任务ID，从任务的URL池删除
    if (!empty($taskId)) {
        return $redis->sRem("{$prefix}bigsite:task:{$taskId}:sites", $url);
    }
    
    // 兼容旧版（全局大站池）
    return $redis->sRem($prefix . 'sites', $url);
}

/**
 * 获取所有大站 URL
 */
function getAllBigsiteSitesFromRedis($taskId = '') {
    $redis = getRedis();
    if (!$redis) return [];
    
    $prefix = REDIS_PREFIX;
    
    // 如果提供了任务ID，从任务的URL池获取
    if (!empty($taskId)) {
        return $redis->sMembers("{$prefix}bigsite:task:{$taskId}:sites") ?: [];
    }
    
    // 兼容旧版（全局大站池）
    return $redis->sMembers($prefix . 'sites') ?: [];
}

/**
 * 随机获取一个大站 URL
 */
function getRandomBigsiteSiteFromRedis() {
    $redis = getRedis();
    if (!$redis) return null;
    
    return $redis->sRandMember(REDIS_PREFIX . 'sites');
}

/**
 * 清空大站 URL 池
 */
function clearBigsiteSitesFromRedis($taskId = '') {
    $redis = getRedis();
    if (!$redis) return false;
    
    $prefix = REDIS_PREFIX;
    
    // 如果提供了任务ID，清空任务的URL池
    if (!empty($taskId)) {
        return $redis->del("{$prefix}bigsite:task:{$taskId}:sites");
    }
    
    // 兼容旧版（全局大站池）
    return $redis->del($prefix . 'sites');
}

// ==================== 历史数据操作 ====================

/**
 * 加载历史数据
 */
function loadBigsiteHistory() {
    if (file_exists(BIGSITE_HISTORY_FILE)) {
        $data = json_decode(file_get_contents(BIGSITE_HISTORY_FILE), true);
        if ($data) {
            return $data;
        }
    }
    return ['records' => [], 'used_urls' => []];
}

/**
 * 保存历史数据
 */
function saveBigsiteHistory($history) {
    return file_put_contents(
        BIGSITE_HISTORY_FILE, 
        json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

/**
 * 添加完成的跳转记录到历史
 * 注意：此函数现在只在跳转真正完成时由 redirect.php 调用
 * 后台添加规则时不再调用此函数
 */
function addCompletedToBigsiteHistory($sourceUrl, $targetUrl, $redirectCount, $actualCount) {
    $history = loadBigsiteHistory();
    
    $history['records'][] = [
        'source_url' => $sourceUrl,
        'target_url' => $targetUrl,
        'redirect_count' => $redirectCount,
        'actual_count' => $actualCount,
        'status' => 'completed',
        'completed_at' => date('Y-m-d H:i:s')
    ];
    
    if (!in_array($sourceUrl, $history['used_urls'])) {
        $history['used_urls'][] = $sourceUrl;
    }
    
    saveBigsiteHistory($history);
}

/**
 * 检查 URL 是否在历史记录中
 */
function checkBigsiteUrlHistory($sourceUrl) {
    $history = loadBigsiteHistory();
    
    foreach ($history['records'] as $record) {
        if ($record['source_url'] === $sourceUrl && $record['status'] === 'completed') {
            return $record;
        }
    }
    
    return null;
}

/**
 * 预检查 URL 列表（批量添加前）
 */
function preCheckBigsiteUrls($urls) {
    $history = loadBigsiteHistory();
    $historySet = array_flip($history['used_urls']);
    
    $newUrls = [];
    $historyUrls = [];
    $activeUrls = [];
    
    foreach ($urls as $url) {
        // 检查是否在 Redis 中活跃
        if (isBigsiteRuleActiveInRedis($url)) {
            $activeUrls[] = $url;
        } elseif (isset($historySet[$url])) {
            $historyUrls[] = $url;
        } else {
            $newUrls[] = $url;
        }
    }
    
    return [
        'total' => count($urls),
        'new_count' => count($newUrls),
        'history_count' => count($historyUrls),
        'active_count' => count($activeUrls),
        'new_urls' => $newUrls,
        'history_urls' => $historyUrls,
        'active_urls' => $activeUrls
    ];
}

/**
 * 导出历史数据
 */
function exportBigsiteHistory($format = 'csv') {
    $history = loadBigsiteHistory();
    
    if ($format === 'csv') {
        $output = "来源链接,目标链接,设定次数,实际次数,创建时间,完成时间,状态\n";
        foreach ($history['records'] as $record) {
            $output .= implode(',', [
                '"' . str_replace('"', '""', $record['source_url']) . '"',
                '"' . str_replace('"', '""', $record['target_url']) . '"',
                $record['redirect_count'],
                $record['actual_count'] ?? 0,
                $record['created_at'],
                $record['completed_at'] ?? '',
                $record['status']
            ]) . "\n";
        }
        return $output;
    }
    
    return json_encode($history['records'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * 清空历史数据
 */
function clearBigsiteHistory() {
    saveBigsiteHistory(['records' => [], 'used_urls' => []]);
    return true;
}

/**
 * 获取历史统计
 * 注意：现在历史文件只记录已完成的跳转，所有记录都是 completed 状态
 */
function getBigsiteHistoryStats() {
    $history = loadBigsiteHistory();
    
    // 确保 history 数据结构正确
    if (!is_array($history)) {
        $history = ['records' => [], 'used_urls' => []];
    }
    if (!isset($history['records']) || !is_array($history['records'])) {
        $history['records'] = [];
    }
    if (!isset($history['used_urls']) || !is_array($history['used_urls'])) {
        $history['used_urls'] = [];
    }
    
    $totalRedirects = 0;
    foreach ($history['records'] as $record) {
        $totalRedirects += $record['actual_count'] ?? 0;
    }
    
    // 现在所有记录都是已完成的，所以 completed_count = total_records
    return [
        'total_records' => count($history['records']),
        'completed_count' => count($history['records']),
        'total_redirects' => $totalRedirects,
        'unique_urls' => count($history['used_urls'])
    ];
}

/**
 * 获取指定任务的统计信息
 */
function getBigsiteTaskStatsFromRedis($taskId) {
    $redis = getRedis();
    if (!$redis) {
        return [
            'total_rules' => 0,
            'active_rules' => 0,
            'completed_rules' => 0,
            'total_redirects' => 0,
        ];
    }
    
    $prefix = REDIS_PREFIX;
    $allRules = getAllActiveBigsiteRules($taskId); // ★ 传入 taskId
    
    $totalRules = 0;
    $activeRules = 0;
    $completedRules = 0;
    $totalRedirects = 0;
    
    foreach ($allRules as $rule) {
        $totalRules++;
        $used = $rule['used_count'] ?? 0;
        $total = $rule['redirect_count'] ?? 1;
        
        $totalRedirects += $used;
        
        if ($used >= $total || !empty($rule['is_completed'])) {
            $completedRules++;
        } else {
            $activeRules++;
        }
    }
    
    return [
        'total_rules' => $totalRules,
        'active_rules' => $activeRules,
        'completed_rules' => $completedRules,
        'total_redirects' => $totalRedirects,
    ];
}

/**
 * 删除指定任务的所有数据（规则、统计、配置、URL池）
 */
function deleteBigsiteTaskFromRedis($taskId) {
    $redis = getRedis();
    if (!$redis) {
        error_log("Warning: Failed to connect to Redis when deleting bigsite task {$taskId}");
        return false;
    }
    
    $prefix = REDIS_PREFIX;
    
    try {
        // 删除任务相关的所有键
        // 需要匹配所有可能的键格式（包括旧版本可能产生的重复前缀）
        $patterns = [
            "{$prefix}bigsite:task:{$taskId}:*",           // 正常格式
            "{$prefix}{$prefix}bigsite:task:{$taskId}:*",  // 重复前缀（兼容旧版bug）
        ];
        
        $totalDeleted = 0;
        foreach ($patterns as $pattern) {
            $keys = $redis->keys($pattern);
            if (!empty($keys)) {
                $deleted = $redis->del($keys);
                $totalDeleted += $deleted;
                error_log("Deleted {$deleted} Redis keys with pattern: {$pattern}");
            }
        }
        
        if ($totalDeleted > 0) {
            error_log("Total deleted {$totalDeleted} Redis keys for bigsite task {$taskId}");
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error deleting Redis data for bigsite task {$taskId}: " . $e->getMessage());
        return false;
    }
}

// ============================================
// 消耗池任务 Redis 函数
// ============================================

/**
 * 添加任务链接到 Redis
 * @param string $taskId 任务ID
 * @param array $links 链接数组 [['url' => '...', 'count' => 5], ...]
 * @return bool
 */
function addTaskLinksToRedis($taskId, $links, $overwrite = false) {
    $redis = getRedis();
    if (!$redis) return ['success' => false, 'added' => 0, 'skipped' => 0, 'overwritten' => 0];
    
    $prefix = REDIS_TASK_PREFIX;
    $availableKey = "{$prefix}{$taskId}:available";
    
    $addedCount = 0;
    $skippedCount = 0;
    $overwrittenCount = 0;
    
    foreach ($links as $link) {
        $linkId = md5($link['url']);
        $linkKey = "{$prefix}{$taskId}:link:{$linkId}";
        
        // 检查链接是否已存在
        $exists = $redis->exists($linkKey);
        
        if ($exists && !$overwrite) {
            // 已存在且不覆盖，跳过
            $skippedCount++;
            continue;
        }
        
        if ($exists && $overwrite) {
            // 覆盖模式：获取旧链接信息，判断是否需要更新 available
            $oldLink = $redis->hGetAll($linkKey);
            $oldUsed = (int)($oldLink['used'] ?? 0);
            $oldTotal = (int)($oldLink['total'] ?? 1);
            $wasAvailable = $oldUsed < $oldTotal;
            
            // 直接覆盖链接数据（不删除，避免统计混乱）
            $redis->hMSet($linkKey, [
                'url' => $link['url'],
                'total' => $link['count'] ?? 1,
                'used' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            // 确保在可用集合中（如果之前已用完，需要重新加入）
            $redis->sAdd($availableKey, $linkId);
            
            $overwrittenCount++;
            
            // 如果之前不可用，现在变可用了，需要增加 available 计数
            if (!$wasAvailable) {
                $statsKey = "{$prefix}{$taskId}:stats";
                $currentAvailable = (int)$redis->hGet($statsKey, 'available_links');
                $redis->hSet($statsKey, 'available_links', $currentAvailable + 1);
            }
        } else {
            // 新增模式
            $redis->hMSet($linkKey, [
                'url' => $link['url'],
                'total' => $link['count'] ?? 1,
                'used' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            // 添加到可用链接集合
            $redis->sAdd($availableKey, $linkId);
            
            if (!$exists) {
                $addedCount++;
            }
        }
    }
    
    // 更新统计（只更新新增的链接）
    // 注意：覆盖的链接已经在上面的逻辑中单独处理了 available_links
    if ($addedCount > 0) {
        $statsKey = "{$prefix}{$taskId}:stats";
        $currentTotal = (int)$redis->hGet($statsKey, 'total_links');
        $currentAvailable = (int)$redis->hGet($statsKey, 'available_links');
        
        $redis->hSet($statsKey, 'total_links', $currentTotal + $addedCount);
        $redis->hSet($statsKey, 'available_links', $currentAvailable + $addedCount);
    }
    
    return ['success' => true, 'added' => $addedCount, 'skipped' => $skippedCount, 'overwritten' => $overwrittenCount];
}

/**
 * 检查任务中哪些链接已存在
 * @param string $taskId 任务ID
 * @param array $links 要检查的链接列表
 * @return array 返回已存在和新链接的信息
 */
function checkExistingTaskLinks($taskId, $links) {
    $redis = getRedis();
    if (!$redis) {
        return [
            'success' => false,
            'existing' => [],
            'existing_count' => 0,
            'new' => [],
            'new_count' => 0
        ];
    }
    
    $prefix = REDIS_TASK_PREFIX;
    $existing = [];
    $new = [];
    
    foreach ($links as $link) {
        $url = $link['url'];
        $linkId = md5($url);
        $linkKey = "{$prefix}{$taskId}:link:{$linkId}";
        
        if ($redis->exists($linkKey)) {
            // 获取已存在链接的详情
            $linkInfo = $redis->hGetAll($linkKey);
            $existing[] = [
                'url' => $url,
                'info' => [
                    'url' => $linkInfo['url'] ?? $url,
                    'total' => (int)($linkInfo['total'] ?? 1),
                    'used' => (int)($linkInfo['used'] ?? 0),
                    'created_at' => $linkInfo['created_at'] ?? '未知'
                ]
            ];
        } else {
            $new[] = $url;
        }
    }
    
    return [
        'success' => true,
        'existing' => $existing,
        'existing_count' => count($existing),
        'new' => $new,
        'new_count' => count($new)
    ];
}

/**
 * 从 Redis 删除单个任务链接
 * @param string $taskId 任务ID
 * @param string $url 链接URL
 * @return bool 是否删除成功
 */
function deleteTaskLinkFromRedis($taskId, $url) {
    $redis = getRedis();
    if (!$redis) return false;
    
    $prefix = REDIS_TASK_PREFIX;
    $linkId = md5($url);
    $linkKey = "{$prefix}{$taskId}:link:{$linkId}";
    $availableKey = "{$prefix}{$taskId}:available";
    
    // 获取链接信息（用于更新统计）
    $link = $redis->hGetAll($linkKey);
    if (empty($link)) {
        return false; // 链接不存在
    }
    
    $used = (int)($link['used'] ?? 0);
    $total = (int)($link['total'] ?? 1);
    $isAvailable = $used < $total;
    
    // 删除链接数据
    $redis->del($linkKey);
    
    // 从可用集合中移除
    $redis->sRem($availableKey, $linkId);
    
    // 更新统计
    $statsKey = "{$prefix}{$taskId}:stats";
    $currentTotal = (int)$redis->hGet($statsKey, 'total_links');
    $currentAvailable = (int)$redis->hGet($statsKey, 'available_links');
    
    $redis->hSet($statsKey, 'total_links', max(0, $currentTotal - 1));
    if ($isAvailable) {
        $redis->hSet($statsKey, 'available_links', max(0, $currentAvailable - 1));
    }
    
    return true;
}

/**
 * 从 Redis 获取随机链接并消耗（原子操作）
 * @param string $taskId 任务ID
 * @return string|null 返回链接URL，如果没有可用链接返回null
 */
function consumeTaskLinkFromRedis($taskId) {
    $redis = getRedis();
    if (!$redis) return null;
    
    $prefix = REDIS_TASK_PREFIX;
    $availableKey = "{$prefix}{$taskId}:available";
    
    // 1. 随机获取一个可用链接 ID（O(1)）
    $linkId = $redis->sRandMember($availableKey);
    if (!$linkId) {
        return null; // 没有可用链接
    }
    
    $linkKey = "{$prefix}{$taskId}:link:{$linkId}";
    
    // 2. 使用 WATCH 实现乐观锁（处理并发）
    $redis->watch($linkKey);
    
    // 3. 获取链接信息
    $link = $redis->hGetAll($linkKey);
    if (empty($link)) {
        $redis->unwatch();
        // 链接不存在，从可用集合中移除
        $redis->sRem($availableKey, $linkId);
        // ★ 修复：递减统计值（清理脏数据）
        $redis->hIncrBy("{$prefix}{$taskId}:stats", 'available_links', -1);
        // 递归获取下一个
        return consumeTaskLinkFromRedis($taskId);
    }
    
    $used = (int)($link['used'] ?? 0);
    $total = (int)($link['total'] ?? 1);
    
    // 4. 检查是否已用完
    if ($used >= $total) {
        $redis->unwatch();
        // 从可用集合中移除
        $redis->sRem($availableKey, $linkId);
        // ★ 修复：递减统计值（清理脏数据）
        $redis->hIncrBy("{$prefix}{$taskId}:stats", 'available_links', -1);
        // 递归获取下一个
        return consumeTaskLinkFromRedis($taskId);
    }
    
    // 5. 原子递增使用次数
    $redis->multi();
    $redis->hIncrBy($linkKey, 'used', 1);
    $redis->hSet($linkKey, 'last_used', date('Y-m-d H:i:s'));
    $result = $redis->exec();
    
    if ($result === false) {
        // 并发冲突，重试
        return consumeTaskLinkFromRedis($taskId);
    }
    
    $newUsed = $used + 1;
    
    // 6. 如果用完了，从可用集合中移除
    if ($newUsed >= $total) {
        $redis->sRem($availableKey, $linkId);
        $redis->hIncrBy("{$prefix}{$taskId}:stats", 'available_links', -1);
    }
    
    // 7. 更新统计
    $redis->hIncrBy("{$prefix}{$taskId}:stats", 'total_redirects', 1);
    
    // 8. 返回URL（占位符替换在 redirect.php 中处理）
    return $link['url'];
}

/**
 * 获取任务统计信息（从 Redis）
 * @param string $taskId 任务ID
 * @return array
 */
function getTaskStatsFromRedis($taskId) {
    $redis = getRedis();
    if (!$redis) return null;
    
    $prefix = REDIS_TASK_PREFIX;
    $stats = $redis->hGetAll("{$prefix}{$taskId}:stats");
    
    return [
        'total_links' => (int)($stats['total_links'] ?? 0),
        'available_links' => (int)($stats['available_links'] ?? 0),
        'total_redirects' => (int)($stats['total_redirects'] ?? 0),
    ];
}

/**
 * 获取任务所有链接详情（从 Redis）
 * @param string $taskId 任务ID
 * @param int $limit 限制返回数量（0=不限制）
 * @return array
 */
function getTaskLinksFromRedis($taskId, $limit = 0) {
    $redis = getRedis();
    if (!$redis) return [];
    
    $prefix = REDIS_TASK_PREFIX;
    
    // ★ 修复：直接获取所有链接键，而不是只从 available 集合读取
    // 这样即使 available 集合数据不一致，也能显示所有链接
    $pattern = "{$prefix}{$taskId}:link:*";
    $linkKeys = $redis->keys($pattern);
    
    if (empty($linkKeys)) {
        return [];
    }
    
    $links = [];
    foreach ($linkKeys as $linkKey) {
        $link = $redis->hGetAll($linkKey);
        
        if (!empty($link) && !empty($link['url'])) {
            $used = (int)($link['used'] ?? 0);
            $total = (int)($link['total'] ?? 1);
            
            $links[] = [
                'url' => $link['url'],
                'count' => $total,
                'used' => $used,
                'remaining' => max(0, $total - $used),
                'last_used' => $link['last_used'] ?? '',
                'created_at' => $link['created_at'] ?? ''
            ];
        }
    }
    
    // 按剩余次数排序（剩余多的在前）
    usort($links, function($a, $b) {
        return $b['remaining'] - $a['remaining'];
    });
    
    // 限制数量
    if ($limit > 0 && count($links) > $limit) {
        $links = array_slice($links, 0, $limit);
    }
    
    return $links;
}

/**
 * 清空任务的所有链接（从 Redis）
 * @param string $taskId 任务ID
 * @return bool
 */
function clearTaskLinksFromRedis($taskId) {
    $redis = getRedis();
    if (!$redis) return false;
    
    $prefix = REDIS_TASK_PREFIX;
    $availableKey = "{$prefix}{$taskId}:available";
    
    // 获取所有链接ID
    $linkIds = $redis->sMembers($availableKey);
    
    // 删除所有链接详情
    foreach ($linkIds as $linkId) {
        $linkKey = "{$prefix}{$taskId}:link:{$linkId}";
        $redis->del($linkKey);
    }
    
    // 删除可用链接集合
    $redis->del($availableKey);
    
    // 重置统计
    $statsKey = "{$prefix}{$taskId}:stats";
    $redis->hMSet($statsKey, [
        'total_links' => 0,
        'available_links' => 0,
        'total_redirects' => 0
    ]);
    
    return true;
}

/**
 * 重置任务链接的使用次数（从 Redis）
 * @param string $taskId 任务ID
 * @param int|null $newCount 新的跳转次数（可选，如果提供则更新每个链接的total字段）
 * @return bool
 */
function resetTaskLinksFromRedis($taskId, $newCount = null) {
    $redis = getRedis();
    if (!$redis) return false;
    
    $prefix = REDIS_TASK_PREFIX;
    $availableKey = "{$prefix}{$taskId}:available";
    
    // 获取所有链接ID（包括已用完的）
    $pattern = "{$prefix}{$taskId}:link:*";
    $keys = $redis->keys($pattern);
    
    $resetCount = 0;
    
    foreach ($keys as $linkKey) {
        // 重置使用次数
        $redis->hSet($linkKey, 'used', 0);
        $redis->hDel($linkKey, 'last_used');
        
        // 如果提供了新的跳转次数，更新 total 字段
        if ($newCount !== null) {
            $redis->hSet($linkKey, 'total', max(1, intval($newCount)));
        }
        
        // 提取 linkId
        $linkId = str_replace("{$prefix}{$taskId}:link:", '', $linkKey);
        
        // 重新添加到可用集合
        $redis->sAdd($availableKey, $linkId);
        
        $resetCount++;
    }
    
    // 更新统计
    $statsKey = "{$prefix}{$taskId}:stats";
    $redis->hSet($statsKey, 'available_links', $resetCount);
    $redis->hSet($statsKey, 'total_redirects', 0);
    
    // 如果任务之前被自动停止，重新启用它
    $redis->hSet($statsKey, 'enabled', '1');
    
    return true;
}

/**
 * 删除整个任务的所有 Redis 数据
 * @param string $taskId 任务ID
 * @return bool
 */
function deleteTaskFromRedis($taskId) {
    $redis = getRedis();
    if (!$redis) return false;
    
    $prefix = REDIS_TASK_PREFIX;
    
    // 删除所有相关键
    $pattern = "{$prefix}{$taskId}:*";
    $keys = $redis->keys($pattern);
    
    foreach ($keys as $key) {
        $redis->del($key);
    }
    
    return true;
}

/**
 * 导出任务链接为 TXT 格式（从 Redis）
 * @param string $taskId 任务ID
 * @return string
 */
function exportTaskLinksTxtFromRedis($taskId) {
    $links = getTaskLinksFromRedis($taskId);
    
    $output = [];
    foreach ($links as $link) {
        $remaining = $link['count'] - $link['used'];
        $output[] = $link['url'] . ' ' . $remaining;
    }
    
    return implode("\n", $output);
}

/**
 * 导出任务链接为 CSV 格式（从 Redis）
 * @param string $taskId 任务ID
 * @return string
 */
function exportTaskLinksCsvFromRedis($taskId) {
    $links = getTaskLinksFromRedis($taskId);
    
    $output = "链接URL,总次数,已用次数,剩余次数,最后使用时间,创建时间\n";
    
    foreach ($links as $link) {
        $remaining = $link['count'] - $link['used'];
        $output .= sprintf(
            '"%s",%d,%d,%d,"%s","%s"' . "\n",
            $link['url'],
            $link['count'],
            $link['used'],
            $remaining,
            $link['last_used'],
            $link['created_at']
        );
    }
    
    return $output;
}

// ============================================
// 站群链轮 Redis 函数
// ============================================

/**
 * 站群链轮 Redis 键名前缀
 */
define('REDIS_GROUP_PREFIX', 'group:' . SITE_ID . ':');

/**
 * 保存站群链轮分组配置到 Redis
 * @param string $groupId 分组ID
 * @param array $groupData 分组数据
 * @return bool
 */
function saveGroupToRedis($groupId, $groupData) {
    $redis = getRedis();
    if (!$redis) {
        return false;
    }
    
    try {
        $prefix = REDIS_GROUP_PREFIX;
        
        // 1. 保存分组配置（JSON字符串）
        $configKey = "{$prefix}{$groupId}:config";
        $redis->set($configKey, json_encode($groupData));
        
        // 2. 保存域名列表到 Set
        $domainsSetKey = "{$prefix}{$groupId}:domains";
        $redis->del($domainsSetKey); // 先清空
        
        if (!empty($groupData['domains'])) {
            foreach ($groupData['domains'] as $domain) {
                $domainName = is_array($domain) ? ($domain['domain'] ?? '') : $domain;
                if ($domainName) {
                    $redis->sAdd($domainsSetKey, $domainName);
                    
                    // 3. 保存单个域名的详细配置
                    if (is_array($domain)) {
                        $domainKey = "{$prefix}{$groupId}:domain:" . md5($domainName);
                        $redis->hMSet($domainKey, [
                            'domain' => $domainName,
                            'enabled' => !empty($domain['enabled']) ? '1' : '0',
                            'fixed_target' => $domain['fixed_target'] ?? '',
                            'redirect_type' => $domain['redirect_type'] ?? '',
                            'follow_subdomain' => isset($domain['follow_subdomain']) ? ($domain['follow_subdomain'] ? '1' : '0') : '',
                            'follow_uri' => isset($domain['follow_uri']) ? ($domain['follow_uri'] ? '1' : '0') : '',
                            'weight' => $domain['weight'] ?? 1,
                            'added_at' => $domain['added_at'] ?? date('Y-m-d H:i:s')
                        ]);
                    }
                }
            }
        }
        
        // 4. 保存统计数据
        $statsKey = "{$prefix}{$groupId}:stats";
        $stats = $groupData['stats'] ?? [];
        $redis->hMSet($statsKey, [
            'total_domains' => $stats['total_domains'] ?? 0,
            'enabled_domains' => $stats['enabled_domains'] ?? 0,
            'total_redirects' => $stats['total_redirects'] ?? 0
        ]);
        
        // 5. 保存分组基本信息（用于列表查询）
        $redis->hMSet("{$prefix}{$groupId}:info", [
            'id' => $groupId,
            'name' => $groupData['name'] ?? '',
            'enabled' => !empty($groupData['enabled']) ? '1' : '0',
            'created_at' => $groupData['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $groupData['updated_at'] ?? date('Y-m-d H:i:s')
        ]);
        
        // 6. 将分组ID添加到全局分组列表
        $redis->sAdd("{$prefix}all_groups", $groupId);
        
        return true;
    } catch (Exception $e) {
        error_log("保存站群链轮到Redis失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 从 Redis 获取站群链轮分组配置
 * @param string $groupId 分组ID
 * @return array|null
 */
function getGroupFromRedis($groupId) {
    $redis = getRedis();
    if (!$redis) {
        return null;
    }
    
    try {
        $prefix = REDIS_GROUP_PREFIX;
        $configKey = "{$prefix}{$groupId}:config";
        
        $config = $redis->get($configKey);
        if ($config) {
            return json_decode($config, true);
        }
        
        return null;
    } catch (Exception $e) {
        error_log("从Redis获取站群链轮失败: " . $e->getMessage());
        return null;
    }
}

/**
 * 从 Redis 获取所有站群链轮分组
 * @return array
 */
function getAllGroupsFromRedis() {
    $redis = getRedis();
    if (!$redis) {
        return [];
    }
    
    try {
        $prefix = REDIS_GROUP_PREFIX;
        $groupIds = $redis->sMembers("{$prefix}all_groups");
        
        $groups = [];
        foreach ($groupIds as $groupId) {
            $group = getGroupFromRedis($groupId);
            if ($group) {
                $groups[] = $group;
            }
        }
        
        return $groups;
    } catch (Exception $e) {
        error_log("从Redis获取所有站群链轮失败: " . $e->getMessage());
        return [];
    }
}

/**
 * 从 Redis 删除站群链轮分组
 * @param string $groupId 分组ID
 * @return bool
 */
function deleteGroupFromRedis($groupId) {
    $redis = getRedis();
    if (!$redis) {
        return false;
    }
    
    try {
        $prefix = REDIS_GROUP_PREFIX;
        
        // 1. 获取所有域名
        $domainsSetKey = "{$prefix}{$groupId}:domains";
        $domains = $redis->sMembers($domainsSetKey);
        
        // 2. 删除每个域名的配置
        foreach ($domains as $domain) {
            $domainKey = "{$prefix}{$groupId}:domain:" . md5($domain);
            $redis->del($domainKey);
        }
        
        // 3. 删除相关键
        $redis->del($domainsSetKey);
        $redis->del("{$prefix}{$groupId}:config");
        $redis->del("{$prefix}{$groupId}:stats");
        $redis->del("{$prefix}{$groupId}:info");
        
        // 4. 从全局列表中移除
        $redis->sRem("{$prefix}all_groups", $groupId);
        
        return true;
    } catch (Exception $e) {
        error_log("从Redis删除站群链轮失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 更新站群链轮统计数据
 * @param string $groupId 分组ID
 * @param string $field 字段名
 * @param int $increment 增量（默认1）
 * @return bool
 */
function incrementGroupStats($groupId, $field = 'total_redirects', $increment = 1) {
    $redis = getRedis();
    if (!$redis) {
        return false;
    }
    
    try {
        $prefix = REDIS_GROUP_PREFIX;
        $statsKey = "{$prefix}{$groupId}:stats";
        $redis->hIncrBy($statsKey, $field, $increment);
        return true;
    } catch (Exception $e) {
        error_log("更新站群链轮统计失败: " . $e->getMessage());
        return false;
    }
}

// ==================== 整站重定向 Redis 函数 ====================

/**
 * 保存整站重定向任务到 Redis
 */
function saveSitewideTaskToRedis($taskId, $taskData) {
    $redis = getRedis();
    if (!$redis) return false;
    
    try {
        $prefix = REDIS_SITEWIDE_PREFIX;
        
        // 保存任务配置
        $configKey = "{$prefix}task:{$taskId}:config";
        $redis->hMSet($configKey, [
            'id' => $taskData['id'],
            'name' => $taskData['name'],
            'enabled' => $taskData['enabled'] ? '1' : '0',
            'redirect_type' => $taskData['redirect_type'] ?? '301',
            'follow_subdomain' => isset($taskData['follow_subdomain']) && $taskData['follow_subdomain'] ? '1' : '0',
            'follow_uri' => isset($taskData['follow_uri']) && $taskData['follow_uri'] ? '1' : '0',
            'created_at' => $taskData['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $taskData['updated_at'] ?? date('Y-m-d H:i:s'),
        ]);
        
        // 保存源域名（Set）
        $sourceDomainKey = "{$prefix}task:{$taskId}:source_domains";
        $redis->del($sourceDomainKey);
        if (!empty($taskData['source_domains'])) {
            $redis->sAdd($sourceDomainKey, ...$taskData['source_domains']);
        }
        
        // 保存目标域名（Set）
        $targetDomainKey = "{$prefix}task:{$taskId}:target_domains";
        $redis->del($targetDomainKey);
        if (!empty($taskData['target_domains'])) {
            $redis->sAdd($targetDomainKey, ...$taskData['target_domains']);
        }
        
        // 保存备用 URL（Set）
        $fallbackUrlKey = "{$prefix}task:{$taskId}:fallback_urls";
        $redis->del($fallbackUrlKey);
        if (!empty($taskData['fallback_urls'])) {
            $redis->sAdd($fallbackUrlKey, ...$taskData['fallback_urls']);
        }
        
        // 保存 URI 替换规则（Hash）
        $uriReplacementKey = "{$prefix}task:{$taskId}:uri_replacements";
        $redis->del($uriReplacementKey);
        if (!empty($taskData['uri_replacements'])) {
            foreach ($taskData['uri_replacements'] as $idx => $rule) {
                $redis->hSet($uriReplacementKey, "rule_{$idx}", json_encode($rule));
            }
        }
        
        // 保存 URI 过滤规则（Hash）
        $uriFilterKey = "{$prefix}task:{$taskId}:uri_filter";
        $redis->del($uriFilterKey);
        if (!empty($taskData['uri_filter'])) {
            $redis->hMSet($uriFilterKey, [
                'enabled' => isset($taskData['uri_filter']['enabled']) && $taskData['uri_filter']['enabled'] ? '1' : '0',
                'mode' => $taskData['uri_filter']['mode'] ?? 'blacklist',
                'rules' => json_encode($taskData['uri_filter']['rules'] ?? []),
            ]);
        }
        
        // 保存蜘蛛筛选配置（Hash）
        $spiderFilterKey = "{$prefix}task:{$taskId}:spider_filter";
        $redis->del($spiderFilterKey);
        if (!empty($taskData['spider_filter'])) {
            $redis->hMSet($spiderFilterKey, [
                'enabled' => isset($taskData['spider_filter']['enabled']) && $taskData['spider_filter']['enabled'] ? '1' : '0',
                'baidu_pc' => isset($taskData['spider_filter']['types']['baidu_pc']) && $taskData['spider_filter']['types']['baidu_pc'] ? '1' : '0',
                'baidu_mobile' => isset($taskData['spider_filter']['types']['baidu_mobile']) && $taskData['spider_filter']['types']['baidu_mobile'] ? '1' : '0',
                'google' => isset($taskData['spider_filter']['types']['google']) && $taskData['spider_filter']['types']['google'] ? '1' : '0',
                'sogou' => isset($taskData['spider_filter']['types']['sogou']) && $taskData['spider_filter']['types']['sogou'] ? '1' : '0',
            ]);
        }
        
        // 初始化统计数据
        $statsKey = "{$prefix}task:{$taskId}:stats";
        if (!$redis->exists($statsKey)) {
            $redis->hMSet($statsKey, [
                'total_redirects' => 0,
            ]);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("保存整站重定向任务到 Redis 失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 从 Redis 获取整站重定向任务
 */
function getSitewideTaskFromRedis($taskId) {
    $redis = getRedis();
    if (!$redis) return null;
    
    try {
        $prefix = REDIS_SITEWIDE_PREFIX;
        
        // 获取任务配置
        $configKey = "{$prefix}task:{$taskId}:config";
        $config = $redis->hGetAll($configKey);
        
        if (empty($config)) {
            return null;
        }
        
        // 获取源域名
        $sourceDomainKey = "{$prefix}task:{$taskId}:source_domains";
        $sourceDomains = $redis->sMembers($sourceDomainKey) ?: [];
        
        // 获取目标域名
        $targetDomainKey = "{$prefix}task:{$taskId}:target_domains";
        $targetDomains = $redis->sMembers($targetDomainKey) ?: [];
        
        // 获取备用 URL
        $fallbackUrlKey = "{$prefix}task:{$taskId}:fallback_urls";
        $fallbackUrls = $redis->sMembers($fallbackUrlKey) ?: [];
        
        // 获取 URI 替换规则
        $uriReplacementKey = "{$prefix}task:{$taskId}:uri_replacements";
        $uriReplacementsRaw = $redis->hGetAll($uriReplacementKey) ?: [];
        $uriReplacements = [];
        foreach ($uriReplacementsRaw as $rule) {
            $uriReplacements[] = json_decode($rule, true);
        }
        
        // 获取 URI 过滤规则
        $uriFilterKey = "{$prefix}task:{$taskId}:uri_filter";
        $uriFilterRaw = $redis->hGetAll($uriFilterKey) ?: [];
        $uriFilter = [
            'enabled' => ($uriFilterRaw['enabled'] ?? '0') === '1',
            'mode' => $uriFilterRaw['mode'] ?? 'blacklist',
            'rules' => !empty($uriFilterRaw['rules']) ? json_decode($uriFilterRaw['rules'], true) : [],
        ];
        
        // 获取蜘蛛筛选配置
        $spiderFilterKey = "{$prefix}task:{$taskId}:spider_filter";
        $spiderFilterRaw = $redis->hGetAll($spiderFilterKey) ?: [];
        $spiderFilter = [
            'enabled' => ($spiderFilterRaw['enabled'] ?? '0') === '1',
            'types' => [
                'baidu_pc' => ($spiderFilterRaw['baidu_pc'] ?? '0') === '1',
                'baidu_mobile' => ($spiderFilterRaw['baidu_mobile'] ?? '0') === '1',
                'google' => ($spiderFilterRaw['google'] ?? '0') === '1',
                'sogou' => ($spiderFilterRaw['sogou'] ?? '0') === '1',
            ]
        ];
        
        // 获取统计数据
        $statsKey = "{$prefix}task:{$taskId}:stats";
        $stats = $redis->hGetAll($statsKey) ?: [];
        
        return [
            'id' => $config['id'],
            'name' => $config['name'],
            'enabled' => ($config['enabled'] ?? '0') === '1',
            'redirect_type' => $config['redirect_type'] ?? '301',
            'follow_subdomain' => ($config['follow_subdomain'] ?? '0') === '1',
            'follow_uri' => ($config['follow_uri'] ?? '1') === '1',
            'source_domains' => array_values($sourceDomains),
            'target_domains' => array_values($targetDomains),
            'fallback_urls' => array_values($fallbackUrls),
            'uri_replacements' => $uriReplacements,
            'uri_filter' => $uriFilter,
            'spider_filter' => $spiderFilter,
            'stats' => [
                'total_redirects' => (int)($stats['total_redirects'] ?? 0),
            ],
            'created_at' => $config['created_at'] ?? '',
            'updated_at' => $config['updated_at'] ?? '',
        ];
    } catch (Exception $e) {
        error_log("从 Redis 获取整站重定向任务失败: " . $e->getMessage());
        return null;
    }
}

/**
 * 获取所有整站重定向任务ID
 */
function getAllSitewideTaskIdsFromRedis() {
    $redis = getRedis();
    if (!$redis) return [];
    
    try {
        $prefix = REDIS_SITEWIDE_PREFIX;
        $pattern = "{$prefix}task:*:config";
        $keys = $redis->keys($pattern);
        
        $taskIds = [];
        foreach ($keys as $key) {
            // 从键名中提取任务ID: sitewide:{SITE_ID}:task:{taskId}:config
            if (preg_match('#:task:([^:]+):config$#', $key, $matches)) {
                $taskIds[] = $matches[1];
            }
        }
        
        return $taskIds;
    } catch (Exception $e) {
        error_log("获取整站重定向任务ID列表失败: " . $e->getMessage());
        return [];
    }
}

/**
 * 删除整站重定向任务的所有 Redis 数据
 */
function deleteSitewideTaskFromRedis($taskId) {
    $redis = getRedis();
    if (!$redis) return false;
    
    try {
        $prefix = REDIS_SITEWIDE_PREFIX;
        
        // 删除所有相关键
        $patterns = [
            "{$prefix}task:{$taskId}:*",  // 任务配置和数据
            "{$prefix}{$taskId}:mapping:*", // 固定映射（旧格式兼容）
        ];
        
        $deletedCount = 0;
        foreach ($patterns as $pattern) {
            $keys = $redis->keys($pattern);
            if (!empty($keys)) {
                foreach ($keys as $key) {
                    if ($redis->del($key)) {
                        $deletedCount++;
                    }
                }
            }
        }
        
        error_log("删除整站重定向任务 {$taskId} 的 Redis 数据：共 {$deletedCount} 个键");
        return true;
    } catch (Exception $e) {
        error_log("删除整站重定向任务 Redis 数据失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 更新整站重定向任务统计
 */
function incrementSitewideTaskStats($taskId, $field = 'total_redirects', $increment = 1) {
    $redis = getRedis();
    if (!$redis) return false;
    
    try {
        $prefix = REDIS_SITEWIDE_PREFIX;
        $statsKey = "{$prefix}task:{$taskId}:stats";
        $redis->hIncrBy($statsKey, $field, $increment);
        return true;
    } catch (Exception $e) {
        error_log("更新整站重定向统计失败: " . $e->getMessage());
        return false;
    }
}

// ============================================================
// 寄生重定向 Redis 函数
// ============================================================

/**
 * 保存寄生重定向任务到 Redis
 */
function saveParasiteTaskToRedis($taskId, $taskData) {
    $redis = getRedis();
    if (!$redis) return false;
    
    try {
        $prefix = REDIS_PARASITE_PREFIX;
        $taskKey = "{$prefix}task:{$taskId}";
        
        // 保存任务基本信息
        $redis->hMSet($taskKey, [
            'id' => $taskData['id'],
            'name' => $taskData['name'] ?? '',
            'enabled' => $taskData['enabled'] ? '1' : '0',
            'manage_type' => $taskData['manage_type'] ?? 'directory',
            'redirect_mode' => $taskData['redirect_mode'] ?? 'focus',
            'source_domain' => $taskData['source_domain'] ?? '',
            'source_path' => $taskData['source_path'] ?? '',
            'target_url' => $taskData['target_url'] ?? '',
            'created_at' => $taskData['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $taskData['updated_at'] ?? date('Y-m-d H:i:s'),
        ]);
        
        // 保存设置
        if (!empty($taskData['settings'])) {
            $settingsKey = "{$prefix}task:{$taskId}:settings";
            $settings = $taskData['settings'];
            
            // 将数组字段序列化为JSON
            if (isset($settings['replacements']) && is_array($settings['replacements'])) {
                $settings['replacements'] = json_encode($settings['replacements']);
            }
            if (isset($settings['exclude_paths']) && is_array($settings['exclude_paths'])) {
                $settings['exclude_paths'] = json_encode($settings['exclude_paths']);
            }
            if (isset($settings['exclude_extensions']) && is_array($settings['exclude_extensions'])) {
                $settings['exclude_extensions'] = json_encode($settings['exclude_extensions']);
            }
            
            $redis->hMSet($settingsKey, $settings);
        }
        
        // 保存统计
        if (!empty($taskData['stats'])) {
            $statsKey = "{$prefix}task:{$taskId}:stats";
            $redis->hMSet($statsKey, $taskData['stats']);
        }
        
        // 保存源域名列表
        if (!empty($taskData['source_domains'])) {
            $sourceDomainsKey = "{$prefix}task:{$taskId}:source_domains";
            $redis->del($sourceDomainsKey);
            foreach ($taskData['source_domains'] as $domain) {
                $domainData = is_array($domain) ? json_encode($domain) : $domain;
                $redis->sAdd($sourceDomainsKey, $domainData);
            }
        }
        
        // 保存目标域名列表
        if (!empty($taskData['target_domains'])) {
            $targetDomainsKey = "{$prefix}task:{$taskId}:target_domains";
            $redis->del($targetDomainsKey);
            foreach ($taskData['target_domains'] as $domain) {
                $redis->sAdd($targetDomainsKey, $domain);
            }
        }
        
        // 保存目录规则
        if (!empty($taskData['directories'])) {
            $directoriesKey = "{$prefix}task:{$taskId}:directories";
            $redis->del($directoriesKey);
            foreach ($taskData['directories'] as $dir) {
                $redis->hSet($directoriesKey, $dir['id'], json_encode($dir));
            }
        }
        
        // 保存蜘蛛筛选配置
        if (!empty($taskData['spider_filter'])) {
            $spiderFilterKey = "{$prefix}task:{$taskId}:spider_filter";
            $redis->hMSet($spiderFilterKey, [
                'enabled' => isset($taskData['spider_filter']['enabled']) && $taskData['spider_filter']['enabled'] ? '1' : '0',
                'baidu_pc' => isset($taskData['spider_filter']['types']['baidu_pc']) && $taskData['spider_filter']['types']['baidu_pc'] ? '1' : '0',
                'baidu_mobile' => isset($taskData['spider_filter']['types']['baidu_mobile']) && $taskData['spider_filter']['types']['baidu_mobile'] ? '1' : '0',
                'google' => isset($taskData['spider_filter']['types']['google']) && $taskData['spider_filter']['types']['google'] ? '1' : '0',
                'sogou' => isset($taskData['spider_filter']['types']['sogou']) && $taskData['spider_filter']['types']['sogou'] ? '1' : '0',
            ]);
        }
        
        // 添加到任务列表
        $redis->sAdd("{$prefix}tasks", $taskId);
        
        return true;
    } catch (Exception $e) {
        error_log("保存寄生重定向任务到Redis失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 从 Redis 获取寄生重定向任务
 */
function getParasiteTaskFromRedis($taskId) {
    $redis = getRedis();
    if (!$redis) return null;
    
    try {
        $prefix = REDIS_PARASITE_PREFIX;
        $taskKey = "{$prefix}task:{$taskId}";
        
        // 检查任务是否存在
        if (!$redis->exists($taskKey)) {
            return null;
        }
        
        // 获取任务基本信息
        $task = $redis->hGetAll($taskKey);
        if (empty($task)) {
            return null;
        }
        
        // 转换布尔值
        $task['enabled'] = ($task['enabled'] ?? '0') === '1';
        
        // 获取设置
        $settingsKey = "{$prefix}task:{$taskId}:settings";
        if ($redis->exists($settingsKey)) {
            $task['settings'] = $redis->hGetAll($settingsKey);
            
            // 反序列化JSON字段
            if (isset($task['settings']['replacements']) && is_string($task['settings']['replacements'])) {
                $task['settings']['replacements'] = json_decode($task['settings']['replacements'], true) ?: [];
            }
            if (isset($task['settings']['exclude_paths']) && is_string($task['settings']['exclude_paths'])) {
                $task['settings']['exclude_paths'] = json_decode($task['settings']['exclude_paths'], true) ?: [];
            }
            if (isset($task['settings']['exclude_extensions']) && is_string($task['settings']['exclude_extensions'])) {
                $task['settings']['exclude_extensions'] = json_decode($task['settings']['exclude_extensions'], true) ?: [];
            }
        } else {
            $task['settings'] = [];
        }
        
        // 获取统计
        $statsKey = "{$prefix}task:{$taskId}:stats";
        if ($redis->exists($statsKey)) {
            $task['stats'] = $redis->hGetAll($statsKey);
        } else {
            $task['stats'] = ['total_redirects' => 0];
        }
        
        // 获取源域名列表
        $sourceDomainsKey = "{$prefix}task:{$taskId}:source_domains";
        if ($redis->exists($sourceDomainsKey)) {
            $sourceDomains = $redis->sMembers($sourceDomainsKey);
            $task['source_domains'] = array_map(function($item) {
                $decoded = json_decode($item, true);
                return $decoded !== null ? $decoded : $item;
            }, $sourceDomains);
        } else {
            $task['source_domains'] = [];
        }
        
        // 获取目标域名列表
        $targetDomainsKey = "{$prefix}task:{$taskId}:target_domains";
        if ($redis->exists($targetDomainsKey)) {
            $task['target_domains'] = $redis->sMembers($targetDomainsKey);
        } else {
            $task['target_domains'] = [];
        }
        
        // 获取目录规则
        $directoriesKey = "{$prefix}task:{$taskId}:directories";
        if ($redis->exists($directoriesKey)) {
            $directories = $redis->hGetAll($directoriesKey);
            $task['directories'] = array_map(function($item) {
                return json_decode($item, true);
            }, $directories);
            $task['directories'] = array_values($task['directories']);
        } else {
            $task['directories'] = [];
        }
        
        // 获取蜘蛛筛选配置
        $spiderFilterKey = "{$prefix}task:{$taskId}:spider_filter";
        if ($redis->exists($spiderFilterKey)) {
            $spiderFilterRaw = $redis->hGetAll($spiderFilterKey);
            $task['spider_filter'] = [
                'enabled' => ($spiderFilterRaw['enabled'] ?? '0') === '1',
                'types' => [
                    'baidu_pc' => ($spiderFilterRaw['baidu_pc'] ?? '0') === '1',
                    'baidu_mobile' => ($spiderFilterRaw['baidu_mobile'] ?? '0') === '1',
                    'google' => ($spiderFilterRaw['google'] ?? '0') === '1',
                    'sogou' => ($spiderFilterRaw['sogou'] ?? '0') === '1',
                ]
            ];
        } else {
            $task['spider_filter'] = [
                'enabled' => false,
                'types' => [
                    'baidu_pc' => false,
                    'baidu_mobile' => false,
                    'google' => false,
                    'sogou' => false
                ]
            ];
        }
        
        return $task;
    } catch (Exception $e) {
        error_log("从Redis获取寄生重定向任务失败: " . $e->getMessage());
        return null;
    }
}

/**
 * 获取所有寄生重定向任务ID
 */
function getAllParasiteTaskIdsFromRedis() {
    $redis = getRedis();
    if (!$redis) return [];
    
    try {
        $prefix = REDIS_PARASITE_PREFIX;
        return $redis->sMembers("{$prefix}tasks");
    } catch (Exception $e) {
        error_log("获取寄生重定向任务列表失败: " . $e->getMessage());
        return [];
    }
}

/**
 * 删除寄生重定向任务从 Redis
 */
function deleteParasiteTaskFromRedis($taskId) {
    $redis = getRedis();
    if (!$redis) return false;
    
    try {
        $prefix = REDIS_PARASITE_PREFIX;
        
        // 删除所有相关的键
        $keys = [
            "{$prefix}task:{$taskId}",
            "{$prefix}task:{$taskId}:settings",
            "{$prefix}task:{$taskId}:stats",
            "{$prefix}task:{$taskId}:source_domains",
            "{$prefix}task:{$taskId}:target_domains",
            "{$prefix}task:{$taskId}:directories",
        ];
        
        foreach ($keys as $key) {
            $redis->del($key);
        }
        
        // 从任务列表中移除
        $redis->sRem("{$prefix}tasks", $taskId);
        
        return true;
    } catch (Exception $e) {
        error_log("从Redis删除寄生重定向任务失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 更新寄生重定向任务统计
 */
function incrementParasiteTaskStats($taskId, $field = 'total_redirects', $increment = 1) {
    $redis = getRedis();
    if (!$redis) return false;
    
    try {
        $prefix = REDIS_PARASITE_PREFIX;
        $statsKey = "{$prefix}task:{$taskId}:stats";
        $redis->hIncrBy($statsKey, $field, $increment);
        return true;
    } catch (Exception $e) {
        error_log("更新寄生重定向统计失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 更新寄生重定向目录规则统计
 */
function incrementParasiteDirStats($taskId, $dirId, $field = 'total_redirects', $increment = 1) {
    $redis = getRedis();
    if (!$redis) return false;
    
    try {
        $prefix = REDIS_PARASITE_PREFIX;
        $statsKey = "{$prefix}task:{$taskId}:dir:{$dirId}:stats";
        $redis->hIncrBy($statsKey, $field, $increment);
        return true;
    } catch (Exception $e) {
        error_log("更新寄生重定向目录统计失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 获取寄生重定向目录规则统计
 */
function getParasiteDirStats($taskId, $dirId) {
    $redis = getRedis();
    if (!$redis) return [];
    
    try {
        $prefix = REDIS_PARASITE_PREFIX;
        $statsKey = "{$prefix}task:{$taskId}:dir:{$dirId}:stats";
        $stats = $redis->hGetAll($statsKey);
        return $stats ?: [];
    } catch (Exception $e) {
        error_log("获取寄生重定向目录统计失败: " . $e->getMessage());
        return [];
    }
}

