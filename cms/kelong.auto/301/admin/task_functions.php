<?php
/**
 * 消耗池任务系统 - 核心函数
 * 函数前缀: _r301task_ 避免与其他程序冲突
 */

// 任务数据文件 - 使用绝对路径避免常量冲突.
if (!defined('_R301TASK_DATA_FILE_')) {
    define('_R301TASK_DATA_FILE_', __DIR__ . '/data/tasks.json');
}
if (!defined('_R301TASK_LINKS_DIR_')) {
    define('_R301TASK_LINKS_DIR_', __DIR__ . '/data/task_links');
}

/**
 * 确保任务链接目录存在
 */
function _r301task_ensureLinksDir() {
    if (!is_dir(_R301TASK_LINKS_DIR_)) {
        @mkdir(_R301TASK_LINKS_DIR_, 0755, true);
    }
}

/**
 * 生成唯一任务ID
 */
function _r301task_generateId() {
    return 'task_' . date('Ymd') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
}

/**
 * 获取所有任务列表
 */
function _r301task_getAll() {
    if (!file_exists(_R301TASK_DATA_FILE_)) {
        return [];
    }
    $data = json_decode(file_get_contents(_R301TASK_DATA_FILE_), true);
    return is_array($data) ? $data : [];
}

/**
 * 保存所有任务
 */
function _r301task_saveAll($tasks) {
    $file = _R301TASK_DATA_FILE_;
    $dir = dirname($file);
    
    // 确保目录存在
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    
    // 使用临时文件 + 重命名确保原子写入
    $tempFile = $file . '.tmp.' . getmypid();
    $result = file_put_contents(
        $tempFile,
        json_encode($tasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
    
    if ($result !== false) {
        return rename($tempFile, $file);
    }
    
    @unlink($tempFile);
    return false;
}

/**
 * 获取单个任务
 */
function _r301task_getById($taskId) {
    $tasks = _r301task_getAll();
    foreach ($tasks as $task) {
        if ($task['id'] === $taskId) {
            return $task;
        }
    }
    return null;
}

/**
 * 创建新任务
 */
function _r301task_create($data) {
    $tasks = _r301task_getAll();
    
    $taskId = _r301task_generateId();
    
    $newTask = [
        'id' => $taskId,
        'name' => trim($data['name'] ?? '未命名任务'),
        'enabled' => false,  // 默认关闭
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        
        // 跳转设置
        'redirect_type' => $data['redirect_type'] ?? '302',  // 默认302临时重定向
        
        // 触发条件
        'conditions' => [
            'page_filter' => $data['page_filter'] ?? 'all',  // 默认所有页面
        ],
        
        // 链接池设置
        'links_settings' => [
            'default_count' => intval($data['default_count'] ?? 1),
            'selection_mode' => $data['selection_mode'] ?? 'random',
        ],
        
        // 速度控制设置
        'speed_control' => [
            'enabled' => false,                         // 默认不启用
            'dimension' => 'task',                      // 限速维度：domain（按域名）/ task（按任务）- 默认按任务
            'min_interval' => 60,                       // 最小间隔（秒）
            'max_per_hour' => 10,                       // 每小时最多次数
            'max_per_day' => 100,                       // 每天最多次数
        ],
        
        // 自定义参数（用于占位符替换）
        'custom_params' => [],
        
        // 蜘蛛筛选配置
        'spider_filter' => isset($data['spider_filter']) ? $data['spider_filter'] : [
            'enabled' => false,
            'types' => [
                'baidu_pc' => false,
                'baidu_mobile' => false,
                'google' => false,
                'sogou' => false
            ]
        ],
        
        // 统计
        'stats' => [
            'total_links' => 0,
            'remaining_links' => 0,
            'total_redirects' => 0,
        ],
    ];
    
    $tasks[] = $newTask;
    _r301task_saveAll($tasks);
    
    // 创建空的链接文件
    _r301task_ensureLinksDir();
    _r301task_saveLinks($taskId, []);
    
    // 初始化 Redis 中的 enabled 状态和完整配置
    require_once __DIR__ . '/redis_config.php';
    $redis = getRedis();
    if ($redis) {
        $prefix = REDIS_TASK_PREFIX;
        
        // 保存统计数据
        $statsKey = "{$prefix}{$taskId}:stats";
        $redis->hSet($statsKey, 'enabled', $newTask['enabled'] ? '1' : '0');
        $redis->hSet($statsKey, 'total_links', 0);
        $redis->hSet($statsKey, 'available_links', 0);
        $redis->hSet($statsKey, 'total_redirects', 0);
        
        // 保存完整的任务配置（包括 spider_filter）
        $configKey = "{$prefix}{$taskId}:config";
        $redis->set($configKey, json_encode($newTask, JSON_UNESCAPED_UNICODE));
    }
    
    return $taskId;
}

/**
 * 更新任务
 */
function _r301task_update($taskId, $data) {
    $tasks = _r301task_getAll();
    
    foreach ($tasks as &$task) {
        if ($task['id'] === $taskId) {
            // 更新基本信息
            if (isset($data['name'])) {
                $task['name'] = trim($data['name']);
            }
            if (isset($data['enabled'])) {
                $task['enabled'] = (bool)$data['enabled'];
            }
            if (isset($data['redirect_type'])) {
                $task['redirect_type'] = $data['redirect_type'];
            }
            
            // 更新触发条件
            if (isset($data['conditions'])) {
                $task['conditions'] = array_replace_recursive(
                    $task['conditions'],
                    $data['conditions']
                );
            }
            
            // 更新链接池设置
            if (isset($data['links_settings'])) {
                $task['links_settings'] = array_merge(
                    $task['links_settings'],
                    $data['links_settings']
                );
            }
            
            // 更新速度控制设置
            if (isset($data['speed_control'])) {
                $task['speed_control'] = array_merge(
                    $task['speed_control'] ?? [],
                    $data['speed_control']
                );
            }
            
            // 更新蜘蛛筛选配置
            if (isset($data['spider_filter'])) {
                $task['spider_filter'] = $data['spider_filter'];
                error_log("_r301task_update: 更新 spider_filter = " . json_encode($data['spider_filter']));
            }
            
            // 清除自动停止标记（如果明确传递 null）
            if (array_key_exists('auto_stopped_at', $data)) {
                if ($data['auto_stopped_at'] === null) {
                    unset($task['auto_stopped_at']);
                } else {
                    $task['auto_stopped_at'] = $data['auto_stopped_at'];
                }
            }
            
            $task['updated_at'] = date('Y-m-d H:i:s');
            
            // 同步配置到 Redis
            require_once __DIR__ . '/redis_config.php';
            $redis = getRedis();
            if ($redis) {
                $prefix = REDIS_TASK_PREFIX;
                $configKey = "{$prefix}{$taskId}:config";
                
                // 构建要存储到Redis的配置（包含完整的任务配置）
                $redisConfig = [
                    'name' => $task['name'],
                    'redirect_type' => $task['redirect_type'],
                    'speed_control' => $task['speed_control'] ?? ['enabled' => false],
                    'custom_params' => $task['custom_params'] ?? [],
                    'spider_filter' => $task['spider_filter'] ?? ['enabled' => false],
                ];
                
                error_log("_r301task_update: 同步到 Redis - Key: {$configKey}");
                error_log("_r301task_update: Redis 配置 = " . json_encode($redisConfig));
                
                // 保存到Redis
                $result = $redis->set($configKey, json_encode($redisConfig, JSON_UNESCAPED_UNICODE));
                error_log("_r301task_update: Redis 保存结果 = " . ($result ? 'success' : 'failed'));
            } else {
                error_log("_r301task_update: Redis 连接失败");
            }
            
            break;
        }
    }
    
    return _r301task_saveAll($tasks);
}

/**
 * 删除任务
 */
function _r301task_delete($taskId) {
    $tasks = _r301task_getAll();
    $tasks = array_filter($tasks, function($t) use ($taskId) {
        return $t['id'] !== $taskId;
    });
    $tasks = array_values($tasks);
    
    // 删除链接文件
    $linksFile = _R301TASK_LINKS_DIR_ . '/' . $taskId . '.json';
    if (file_exists($linksFile)) {
        @unlink($linksFile);
    }
    
    // ⚠️ 重要：删除 Redis 中的所有相关数据
    _r301task_deleteRedisData($taskId);
    
    return _r301task_saveAll($tasks);
}

/**
 * 删除任务的所有 Redis 数据
 */
function _r301task_deleteRedisData($taskId) {
    // 引入 Redis 配置
    require_once __DIR__ . '/redis_config.php';
    
    $redis = getRedis();
    if (!$redis) {
        // error_log("Warning: Failed to connect to Redis when deleting task {$taskId}");
        return false;
    }
    
    $prefix = REDIS_TASK_PREFIX;
    
    try {
        // 删除任务相关的所有键（使用消耗池专用前缀）
        $pattern = "{$prefix}{$taskId}:*";
        $keys = $redis->keys($pattern);
        
        if (!empty($keys)) {
            $deleted = $redis->del($keys);
            // error_log("Deleted {$deleted} Redis keys for task {$taskId}");
        }
        
        return true;
    } catch (Exception $e) {
        // error_log("Error deleting Redis data for task {$taskId}: " . $e->getMessage());
        return false;
    }
}

/**
 * 切换任务开关
 */
function _r301task_toggle($taskId, $enabled) {
    // 更新 JSON 文件
    $result = _r301task_update($taskId, ['enabled' => $enabled]);
    
    // ★ 同步更新 Redis（简单直接，不引入额外依赖）
    if ($result) {
        try {
            $redis = new Redis();
            if (@$redis->connect('127.0.0.1', 6379, 1)) {
                if (defined('REDIS_PASSWORD') && REDIS_PASSWORD) {
                    $redis->auth(REDIS_PASSWORD);
                }
                if (defined('REDIS_DB')) {
                    $redis->select(REDIS_DB);
                }
                
                $prefix = defined('REDIS_TASK_PREFIX') ? REDIS_TASK_PREFIX : 'task:';
                $statsKey = "{$prefix}{$taskId}:stats";
                $redis->hSet($statsKey, 'enabled', $enabled ? '1' : '0');
                $redis->close();
            }
        } catch (Exception $e) {
            // 静默失败，不影响主流程
            // error_log("Failed to sync enabled status to Redis: " . $e->getMessage());
        }
    }
    
    return $result;
}

/**
 * 获取任务的链接列表
 */
function _r301task_getLinks($taskId) {
    _r301task_ensureLinksDir();
    $file = _R301TASK_LINKS_DIR_ . '/' . $taskId . '.json';
    
    if (!file_exists($file)) {
        return [];
    }
    
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

/**
 * 保存任务的链接列表
 */
function _r301task_saveLinks($taskId, $links) {
    _r301task_ensureLinksDir();
    $file = _R301TASK_LINKS_DIR_ . '/' . $taskId . '.json';
    
    return file_put_contents(
        $file,
        json_encode($links, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

/**
 * 批量添加链接到任务
 * @param string $taskId 任务ID
 * @param array $newLinks 新链接数组 [['url' => '...', 'count' => 1], ...]
 * @param int $defaultCount 默认跳转次数
 * @return array ['added' => 数量, 'skipped' => 数量]
 */
function _r301task_addLinks($taskId, $newLinks, $defaultCount = 1) {
    $links = _r301task_getLinks($taskId);
    
    // 建立已存在URL的索引
    $existingUrls = [];
    foreach ($links as $link) {
        $existingUrls[$link['url']] = true;
    }
    
    $added = 0;
    $skipped = 0;
    
    foreach ($newLinks as $item) {
        $url = trim($item['url'] ?? '');
        if (empty($url)) continue;
        
        // 跳过已存在的
        if (isset($existingUrls[$url])) {
            $skipped++;
            continue;
        }
        
        $count = isset($item['count']) && $item['count'] > 0 
            ? intval($item['count']) 
            : $defaultCount;
        
        $links[] = [
            'url' => $url,
            'count' => $count,
            'used' => 0,
            'added_at' => date('Y-m-d H:i:s'),
        ];
        
        $existingUrls[$url] = true;
        $added++;
    }
    
    _r301task_saveLinks($taskId, $links);
    _r301task_updateStats($taskId);
    
    return ['added' => $added, 'skipped' => $skipped];
}

/**
 * 删除任务中的链接
 */
function _r301task_deleteLink($taskId, $url) {
    $links = _r301task_getLinks($taskId);
    $links = array_filter($links, function($l) use ($url) {
        return $l['url'] !== $url;
    });
    $links = array_values($links);
    
    _r301task_saveLinks($taskId, $links);
    _r301task_updateStats($taskId);
    
    return true;
}

/**
 * 清空任务的所有链接
 */
function _r301task_clearLinks($taskId) {
    _r301task_saveLinks($taskId, []);
    _r301task_updateStats($taskId);
    return true;
}

/**
 * 重置链接池（保留链接，重置已用次数，可选修改跳转次数）
 */
function _r301task_resetLinks($taskId, $newCount = null) {
    $links = _r301task_getLinks($taskId);
    
    if (empty($links)) {
        return false;
    }
    
    // 重置每个链接的已用次数，可选修改跳转次数
    foreach ($links as &$link) {
        $link['used'] = 0;
        if ($newCount !== null) {
            $link['count'] = max(1, intval($newCount));
        }
    }
    
    _r301task_saveLinks($taskId, $links);
    _r301task_updateStats($taskId);
    
    return true;
}

/**
 * 更新任务统计信息
 */
function _r301task_updateStats($taskId) {
    $links = _r301task_getLinks($taskId);
    
    $total = count($links);
    $remaining = 0;
    $redirects = 0;
    
    foreach ($links as $link) {
        $used = $link['used'] ?? 0;
        $count = $link['count'] ?? 1;
        $redirects += $used;
        
        if ($used < $count) {
            $remaining++;
        }
    }
    
    $tasks = _r301task_getAll();
    foreach ($tasks as &$task) {
        if ($task['id'] === $taskId) {
            $task['stats'] = [
                'total_links' => $total,
                'remaining_links' => $remaining,
                'total_redirects' => $redirects,
            ];
            break;
        }
    }
    
    _r301task_saveAll($tasks);
}

/**
 * 消耗链接（跳转时调用）
 * @return string|null 返回跳转URL，无可用链接返回null
 */
function _r301task_consumeLink($taskId, $selectionMode = 'random') {
    $links = _r301task_getLinks($taskId);
    
    if (empty($links)) {
        return null;
    }
    
    // 筛选可用链接
    $available = [];
    foreach ($links as $index => $link) {
        if (($link['used'] ?? 0) < ($link['count'] ?? 1)) {
            $available[] = ['index' => $index, 'link' => $link];
        }
    }
    
    if (empty($available)) {
        return null;
    }
    
    // 选择链接
    if ($selectionMode === 'sequential') {
        $selected = $available[0];
    } else {
        $selected = $available[array_rand($available)];
    }
    
    // 增加使用次数
    $links[$selected['index']]['used']++;
    $links[$selected['index']]['last_used'] = date('Y-m-d H:i:s');
    
    _r301task_saveLinks($taskId, $links);
    _r301task_updateStats($taskId);
    
    // 检查是否还有剩余可用链接，如果没有则自动停止任务
    $remainingCount = 0;
    foreach ($links as $link) {
        if (($link['used'] ?? 0) < ($link['count'] ?? 1)) {
            $remainingCount++;
        }
    }
    
    if ($remainingCount === 0) {
        _r301task_autoStop($taskId);
    }
    
    return $selected['link']['url'];
}

/**
 * 自动停止任务（链接消耗完时调用）
 */
function _r301task_autoStop($taskId) {
    $tasks = _r301task_getAll();
    
    foreach ($tasks as &$task) {
        if ($task['id'] === $taskId) {
            $task['enabled'] = false;
            $task['auto_stopped_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    
    _r301task_saveAll($tasks);
}

/**
 * 解析导入的链接文本
 * 支持格式：
 * - 纯URL（每行一个）
 * - URL,次数
 * - URL 次数（空格分隔）
 */
function _r301task_parseImportText($text, $defaultCount = 1) {
    $lines = explode("\n", $text);
    $result = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        
        // 尝试解析 URL,次数 格式
        if (strpos($line, ',') !== false) {
            $parts = explode(',', $line, 2);
            $url = trim($parts[0]);
            $count = isset($parts[1]) ? intval(trim($parts[1])) : $defaultCount;
        }
        // 尝试解析 URL 次数 格式（空格或制表符）
        elseif (preg_match('/^(\S+)\s+(\d+)$/', $line, $matches)) {
            $url = $matches[1];
            $count = intval($matches[2]);
        }
        // 纯URL
        else {
            $url = $line;
            $count = $defaultCount;
        }
        
        // 验证URL（允许包含占位符）
        if (!empty($url)) {
            // 如果URL包含占位符，先进行临时替换再验证
            $testUrl = $url;
            
            // 替换占位符为测试值
            $testUrl = preg_replace('/{年}/', '2025', $testUrl);
            $testUrl = preg_replace('/{月}/', '12', $testUrl);
            $testUrl = preg_replace('/{日}/', '22', $testUrl);
            $testUrl = preg_replace('/{数字\d+}/', '12345678', $testUrl);
            $testUrl = preg_replace('/{小写字母\d+}/', 'abcdefgh', $testUrl);
            $testUrl = preg_replace('/{大写字母\d+}/', 'ABCDEFGH', $testUrl);
            $testUrl = preg_replace('/{大小写字母\d+}/', 'AbCdEfGh', $testUrl);
            $testUrl = preg_replace('/{小写随机字符\d+}/', 'a1b2c3d4', $testUrl);
            $testUrl = preg_replace('/{大写随机字符\d+}/', 'A1B2C3D4', $testUrl);
            $testUrl = preg_replace('/{大小写随机字符\d+}/', 'A1b2C3d4', $testUrl);
            $testUrl = preg_replace('/{自定义参数\d+}/', 'test', $testUrl);
            
            // 验证替换后的URL
            if (filter_var($testUrl, FILTER_VALIDATE_URL)) {
                $result[] = [
                    'url' => $url,
                    'count' => max(1, $count),
                ];
            }
        }
    }
    
    return $result;
}

/**
 * 获取任务的条件摘要（用于列表显示）
 */
function _r301task_getConditionSummary($task) {
    $conditions = $task['conditions'] ?? [];
    $pageFilter = $conditions['page_filter'] ?? 'all';
    
    $summary = [];
    
    // 页面过滤
    if ($pageFilter === 'inner') {
        $summary[] = '仅内页';
    } else {
        $summary[] = '所有页面';
    }
    
    // 速度控制
    $speedControl = $task['speed_control'] ?? [];
    if (!empty($speedControl['enabled'])) {
        $summary[] = '已限速';
    }
    
    return implode(' · ', $summary);
}

/**
 * 获取所有已启用的任务
 */
function _r301task_getEnabledTasks() {
    $tasks = _r301task_getAll();
    $enabled = array_filter($tasks, function($t) {
        return !empty($t['enabled']);
    });
    return array_values($enabled);
}

/**
 * 导出任务配置为 JSON（包含链接数据）
 * @param string $taskId 任务ID
 * @param bool $includeLinks 是否包含链接数据
 * @return array|null 导出数据
 */
function _r301task_export($taskId, $includeLinks = true) {
    $task = _r301task_getById($taskId);
    if (!$task) {
        return null;
    }
    
    $exportData = [
        'export_version' => '1.0',
        'export_time' => date('Y-m-d H:i:s'),
        'task' => [
            'name' => $task['name'],
            'redirect_type' => $task['redirect_type'] ?? '302',
            'conditions' => $task['conditions'],
            'links_settings' => $task['links_settings'],
        ],
    ];
    
    if ($includeLinks) {
        $links = _r301task_getLinks($taskId);
        // 只导出未消耗完的链接，并重置使用次数
        $exportLinks = [];
        foreach ($links as $link) {
            $remaining = ($link['count'] ?? 1) - ($link['used'] ?? 0);
            if ($remaining > 0) {
                $exportLinks[] = [
                    'url' => $link['url'],
                    'count' => $remaining,  // 导出剩余次数
                ];
            }
        }
        $exportData['links'] = $exportLinks;
        $exportData['links_count'] = count($exportLinks);
    }
    
    return $exportData;
}

/**
 * 从导出数据导入任务
 * @param array $importData 导入的数据
 * @return array ['success' => bool, 'message' => string, 'task_id' => string]
 */
function _r301task_import($importData) {
    // 验证数据格式
    if (!isset($importData['task'])) {
        return ['success' => false, 'message' => '无效的导入数据格式'];
    }
    
    $taskData = $importData['task'];
    
    // 创建新任务
    $createData = [
        'name' => ($taskData['name'] ?? '导入的任务') . ' (导入)',
        'redirect_type' => $taskData['redirect_type'] ?? '302',
        'page_filter' => $taskData['conditions']['page_filter'] ?? 'all',
        'default_count' => $taskData['links_settings']['default_count'] ?? 1,
        'selection_mode' => $taskData['links_settings']['selection_mode'] ?? 'random',
    ];
    
    $taskId = _r301task_create($createData);
    if (!$taskId) {
        return ['success' => false, 'message' => '创建任务失败'];
    }
    
    // 更新完整的条件配置
    _r301task_update($taskId, [
        'conditions' => $taskData['conditions'] ?? [],
        'links_settings' => $taskData['links_settings'] ?? [],
    ]);
    
    // 导入链接
    $linksImported = 0;
    if (!empty($importData['links'])) {
        $result = _r301task_addLinks(
            $taskId, 
            $importData['links'], 
            $taskData['links_settings']['default_count'] ?? 1
        );
        $linksImported = $result['added'];
    }
    
    return [
        'success' => true, 
        'message' => "任务导入成功，共导入 {$linksImported} 条链接",
        'task_id' => $taskId,
        'links_imported' => $linksImported,
    ];
}

/**
 * 从远程URL获取并导入任务
 * @param string $url 远程JSON链接
 * @return array ['success' => bool, 'message' => string, 'task_id' => string]
 */
function _r301task_importFromUrl($url) {
    // 验证URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'message' => '无效的URL格式'];
    }
    
    // 获取远程数据
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => 'Task-Import/1.0',
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    
    $json = @file_get_contents($url, false, $context);
    if ($json === false) {
        return ['success' => false, 'message' => '无法获取远程数据，请检查链接是否可访问'];
    }
    
    $importData = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'message' => 'JSON解析失败：' . json_last_error_msg()];
    }
    
    return _r301task_import($importData);
}

