<?php
/**
 * 大站池任务管理函数
 */

// 数据文件路径
define('_BIGSITE_TASK_DATA_FILE_', __DIR__ . '/data/bigsite_tasks.json');

/**
 * 生成唯一ID
 */
function _bigsiteTask_generateId() {
    return 'bst_' . date('Ymd') . '_' . substr(md5(uniqid()), 0, 8);
}

/**
 * 获取所有任务
 */
function _bigsiteTask_getAll() {
    if (!file_exists(_BIGSITE_TASK_DATA_FILE_)) {
        return [];
    }
    $data = json_decode(file_get_contents(_BIGSITE_TASK_DATA_FILE_), true);
    return is_array($data) ? $data : [];
}

/**
 * 保存所有任务（原子写入）
 */
function _bigsiteTask_saveAll($tasks) {
    $dir = dirname(_BIGSITE_TASK_DATA_FILE_);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    
    // 使用临时文件 + rename 实现原子写入
    $tmpFile = _BIGSITE_TASK_DATA_FILE_ . '.tmp.' . uniqid();
    $content = json_encode($tasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if (file_put_contents($tmpFile, $content, LOCK_EX) === false) {
        return false;
    }
    
    return rename($tmpFile, _BIGSITE_TASK_DATA_FILE_);
}

/**
 * 根据ID获取任务
 */
function _bigsiteTask_getById($taskId) {
    $tasks = _bigsiteTask_getAll();
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
function _bigsiteTask_create($data) {
    $tasks = _bigsiteTask_getAll();
    
    $newTask = [
        'id' => _bigsiteTask_generateId(),
        'name' => $data['name'] ?? '未命名任务',
        'enabled' => false,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'settings' => [
            'redirect_type' => '302',
            'probability' => 100, // ⭐ 新增：默认100%概率
        ],
        'spider_filter' => $data['spider_filter'] ?? [
            'enabled' => false,
            'types' => [
                'baidu_pc' => false,
                'baidu_mobile' => false,
                'google' => false,
                'sogou' => false
            ]
        ],
        'stats' => [
            'total_rules' => 0,
            'active_rules' => 0,
            'completed_rules' => 0,
            'total_redirects' => 0,
        ]
    ];
    
    $tasks[] = $newTask;
    _bigsiteTask_saveAll($tasks);
    
    // 同步到 Redis
    _bigsiteTask_syncToRedis($newTask);
    
    return $newTask;
}

/**
 * 更新任务
 */
function _bigsiteTask_update($taskId, $data) {
    $tasks = _bigsiteTask_getAll();
    
    foreach ($tasks as &$task) {
        if ($task['id'] === $taskId) {
            $task = array_merge($task, $data);
            $task['updated_at'] = date('Y-m-d H:i:s');
            _bigsiteTask_saveAll($tasks);
            
            // 同步到 Redis（包括 spider_filter）
            _bigsiteTask_syncToRedis($task);
            
            return true;
        }
    }
    
    return false;
}

/**
 * 删除任务
 */
function _bigsiteTask_delete($taskId) {
    $tasks = _bigsiteTask_getAll();
    $newTasks = [];
    
    foreach ($tasks as $task) {
        if ($task['id'] !== $taskId) {
            $newTasks[] = $task;
        }
    }
    
    // 删除 Redis 中该任务的所有规则
    require_once __DIR__ . '/redis_config.php';
    deleteBigsiteTaskFromRedis($taskId);
    
    return _bigsiteTask_saveAll($newTasks);
}

/**
 * 切换任务启用状态
 */
function _bigsiteTask_toggle($taskId) {
    $tasks = _bigsiteTask_getAll();
    
    foreach ($tasks as &$task) {
        if ($task['id'] === $taskId) {
            $task['enabled'] = !$task['enabled'];
            $task['updated_at'] = date('Y-m-d H:i:s');
            $result = _bigsiteTask_saveAll($tasks);
            
            // ★ 同步更新 Redis（简单直接）
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
                        
                        $prefix = defined('REDIS_PREFIX') ? REDIS_PREFIX : 'bigsite:';
                        $statsKey = "{$prefix}bigsite:task:{$taskId}:stats";
                        $redis->hSet($statsKey, 'enabled', $task['enabled'] ? '1' : '0');
                        $redis->close();
                    }
                } catch (Exception $e) {
                    error_log("Failed to sync bigsite enabled status to Redis: " . $e->getMessage());
                }
            }
            
            return $task['enabled'];
        }
    }
    
    return false;
}

/**
 * 获取已启用的任务
 */
function _bigsiteTask_getEnabled() {
    $tasks = _bigsiteTask_getAll();
    return array_filter($tasks, function($t) {
        return !empty($t['enabled']);
    });
}

/**
 * 更新任务统计
 */
function _bigsiteTask_updateStats($taskId) {
    require_once __DIR__ . '/redis_config.php';
    
    $stats = getBigsiteTaskStatsFromRedis($taskId);
    
    $tasks = _bigsiteTask_getAll();
    foreach ($tasks as &$task) {
        if ($task['id'] === $taskId) {
            $task['stats'] = $stats;
            $task['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    
    _bigsiteTask_saveAll($tasks);
}

/**
 * 批量添加规则到任务
 */
function _bigsiteTask_batchAddRules($taskId, $text, $defaultCount, $redirectType, $overwrite = false) {
    require_once __DIR__ . '/redis_config.php';
    
    $task = _bigsiteTask_getById($taskId);
    if (!$task) {
        return ['success' => false, 'message' => '任务不存在'];
    }
    
    $taskName = $task['name'];
    $sites = getAllBigsiteSitesFromRedis($taskId);
    
    $added = 0;
    $skipped = 0;
    $overwritten = 0;
    $noTarget = 0;
    
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        
        $parts = explode(',', $line);
        $sourceUrl = trim($parts[0]);
        // ⭐ 修改格式：来源URL,目标URL,次数,跳转类型（与CSV格式一致）
        $targetUrl = isset($parts[1]) ? trim($parts[1]) : '';
        $redirectCount = isset($parts[2]) && is_numeric(trim($parts[2])) ? intval(trim($parts[2])) : $defaultCount;
        // ⭐ 新增：第4列为跳转类型
        $lineRedirectType = isset($parts[3]) ? trim($parts[3]) : '';
        
        if (empty($sourceUrl)) continue;
        if ($redirectCount < 1) $redirectCount = $defaultCount;
        
        // ⭐ 新增：验证跳转类型，为空则使用弹窗选择的类型
        if (empty($lineRedirectType) || !in_array($lineRedirectType, ['301', '302'])) {
            $lineRedirectType = $redirectType;
        }
        
        // 检查是否已存在
        $exists = isBigsiteRuleActiveInRedis($sourceUrl, $taskId);
        
        if ($exists && !$overwrite) {
            // 已存在且不覆盖，跳过
            $skipped++;
            continue;
        }
        
        // 如果没有指定目标，从大站池随机选择
        if (empty($targetUrl) && !empty($sites)) {
            $targetUrl = $sites[array_rand($sites)];
        }
        
        if (empty($targetUrl)) {
            $noTarget++;
            continue;
        }
        
        // 添加规则到 Redis（如果已存在会自动覆盖）
        $addSuccess = false;
        
        if ($exists && $overwrite) {
            // 覆盖模式：先删除旧规则，再添加新规则
            deleteBigsiteRuleFromRedis($sourceUrl, $taskId);
            // ⭐ 修复：使用行级跳转类型
            $addSuccess = addBigsiteRuleToRedis($sourceUrl, $targetUrl, $redirectCount, $lineRedirectType, $taskName, $taskId);
            if ($addSuccess) {
                $overwritten++;
            }
        } elseif (!$exists) {
            // 新增模式：直接添加
            // ⭐ 修复：使用行级跳转类型
            $addSuccess = addBigsiteRuleToRedis($sourceUrl, $targetUrl, $redirectCount, $lineRedirectType, $taskName, $taskId);
            if ($addSuccess) {
                $added++;
            }
        }
        // 注意：如果 $exists && !$overwrite，已经在前面 continue 跳过了
    }
    
    // 更新任务统计
    _bigsiteTask_updateStats($taskId);
    
    // 构建提示信息
    $total = $added + $overwritten;
    if ($total > 0) {
        $msg = "成功处理 {$total} 条规则";
        if ($added > 0 && $overwritten > 0) {
            $msg .= "（新增 {$added} 条，覆盖 {$overwritten} 条）";
        } elseif ($overwritten > 0) {
            $msg .= "（覆盖 {$overwritten} 条已存在）";
        } elseif ($added > 0) {
            $msg .= "（新增 {$added} 条）";
        }
    } else {
        $msg = "未添加任何规则";
    }
    
    if ($skipped > 0) $msg .= "，跳过 {$skipped} 条已存在";
    if ($noTarget > 0) $msg .= "，{$noTarget} 条无目标URL";
    
    return ['success' => true, 'message' => $msg, 'added' => $added, 'overwritten' => $overwritten, 'skipped' => $skipped, 'total' => $total];
}

/**
 * 检查哪些规则已存在
 */
function _bigsiteTask_checkExistingRules($text) {
    require_once __DIR__ . '/redis_config.php';
    
    $existing = [];
    $new = [];
    
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        
        $parts = explode(',', $line);
        $sourceUrl = trim($parts[0]);
        
        if (empty($sourceUrl)) continue;
        
        if (isBigsiteRuleActiveInRedis($sourceUrl, $taskId)) {
            // 获取已存在规则的详情
            $ruleInfo = getBigsiteRuleFromRedis($sourceUrl, $taskId);
            
            // 如果无法获取详情，使用默认值
            if (!$ruleInfo) {
                $ruleInfo = [
                    'source_url' => $sourceUrl,
                    'target_url' => '未知',
                    'redirect_count' => 0,
                    'used_count' => 0,
                    'task_name' => '未知任务'
                ];
            }
            
            $existing[] = [
                'url' => $sourceUrl,
                'info' => $ruleInfo
            ];
        } else {
            $new[] = $sourceUrl;
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
 * 同步任务配置到 Redis
 */
function _bigsiteTask_syncToRedis($task) {
    require_once __DIR__ . '/redis_config.php';
    
    $redis = getRedis();
    if (!$redis) {
        return false;
    }
    
    // 使用与其他大站池函数相同的前缀格式
    $prefix = 'bigsite:' . SITE_ID . ':';
    $taskId = $task['id'];
    
    // 同步任务配置
    $configKey = $prefix . 'bigsite:task:' . $taskId . ':config';
    $config = [
        'name' => $task['name'] ?? '未命名任务',
        'redirect_type' => $task['redirect_type'] ?? $task['settings']['redirect_type'] ?? '302',
        'probability' => intval($task['probability'] ?? $task['settings']['probability'] ?? 100), // ⭐ 概率控制
        'smart_strategy' => $task['smart_strategy'] ?? $task['settings']['smart_strategy'] ?? [ // ⭐ 新增：智能策略
            'enabled' => false,
            'rules' => [
                '0' => 100,
                '1' => 50,
                '2' => 30,
                '3' => 20,
                '4' => 15,
                '5+' => 10
            ]
        ],
        'spider_filter' => $task['spider_filter'] ?? []
    ];
    $redis->set($configKey, json_encode($config));
    
    // 同步任务统计
    $statsKey = $prefix . 'bigsite:task:' . $taskId . ':stats';
    $redis->hSet($statsKey, 'enabled', $task['enabled'] ? '1' : '0');
    
    return true;
}

