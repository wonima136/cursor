<?php
/**
 * 克隆站群重定向配置函数
 */

if (!defined('REDIRECT301_ROOT')) {
    die('Direct access not permitted');
}

/**
 * 获取 Redis 原生连接（不使用 RedisManager 的前缀）
 * 克隆站模块使用独立的 clone: 前缀，不与其他模块混淆
 */
function _r301clone_getRedis() {
    static $rawRedis = null;
    
    if ($rawRedis !== null) {
        return $rawRedis;
    }
    
    // 获取 RedisManager 实例
    if (function_exists('getRedis')) {
        $redisManager = getRedis();
        if ($redisManager) {
            // 检查是否是 RedisManager 对象
            if (method_exists($redisManager, 'getConnection')) {
                // 获取原生 Redis 连接，不使用 RedisManager 的前缀包装
                $rawRedis = $redisManager->getConnection();
                if ($rawRedis && $rawRedis instanceof Redis) {
                    return $rawRedis;
                }
            } elseif ($redisManager instanceof Redis) {
                // 如果已经是原生 Redis 对象，直接返回
                $rawRedis = $redisManager;
                return $rawRedis;
            }
        }
    }
    
    // 如果上面的方法失败，尝试直接创建 Redis 连接
    try {
        if (!class_exists('Redis')) {
            error_log('[CloneRedirect] Redis 扩展未安装');
            return null;
        }
        
        $rawRedis = new Redis();
        $connected = $rawRedis->connect('127.0.0.1', 6379, 3);
        
        if (!$connected) {
            error_log('[CloneRedirect] 无法连接到 Redis 服务器');
            return null;
        }
        
        // 选择数据库 1
        $rawRedis->select(1);
        
        return $rawRedis;
    } catch (Exception $e) {
        error_log('[CloneRedirect] Redis 连接异常: ' . $e->getMessage());
        return null;
    }
}

/**
 * 获取所有站群组
 */
function _r301clone_getAllGroups() {
    $jsonFile = __DIR__ . '/data/clone_groups.json';
    
    if (!file_exists($jsonFile)) {
        return [];
    }
    
    $data = json_decode(file_get_contents($jsonFile), true);
    return $data ?: [];
}

/**
 * 获取单个站群组
 */
function _r301clone_getGroup($groupId) {
    $all = _r301clone_getAllGroups();
    return $all[$groupId] ?? null;
}

/**
 * 创建站群组
 */
function _r301clone_createGroup($name) {
    $all = _r301clone_getAllGroups();
    
    // 生成唯一 ID
    $groupId = 'group_' . time() . '_' . substr(md5(uniqid()), 0, 8);
    
    $all[$groupId] = [
        'id' => $groupId,
        'name' => $name,
        'enabled' => false,  // 默认关闭
        'target_type' => 'external',  // 默认跳转到外部目标
        'target_url' => '',
        'redirect_type' => 301,
        'max_redirects' => 2,  // 默认跳转次数为2
        'probability' => 100,
        'domains' => [],
        'spider_filter' => [
            'enabled' => false,
            'types' => [
                'baidu_pc' => false,
                'baidu_mobile' => false,
                'google' => false,
                'sogou' => false
            ]
        ],
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    if (_r301clone_saveAllGroups($all)) {
        // 同步到 Redis
        _r301clone_syncGroupToRedis($groupId, $all[$groupId]);
        return $groupId;
    }
    
    return false;
}

/**
 * 更新站群组配置
 */
function _r301clone_updateGroup($groupId, $data) {
    $all = _r301clone_getAllGroups();
    
    if (!isset($all[$groupId])) {
        return ['success' => false, 'message' => '站群组不存在'];
    }
    
    // 更新配置
    $all[$groupId]['name'] = $data['name'] ?? $all[$groupId]['name'];
    $all[$groupId]['enabled'] = $data['enabled'] ?? false;
    $all[$groupId]['target_type'] = $data['target_type'] ?? 'external';
    $all[$groupId]['target_url'] = $data['target_url'] ?? '';
    $all[$groupId]['redirect_type'] = $data['redirect_type'] ?? 301;
    $all[$groupId]['max_redirects'] = $data['max_redirects'] ?? 0;
    $all[$groupId]['probability'] = $data['probability'] ?? 100;
    if (isset($data['spider_filter'])) {
        $all[$groupId]['spider_filter'] = $data['spider_filter'];
    }
    $all[$groupId]['updated_at'] = date('Y-m-d H:i:s');
    
    if (_r301clone_saveAllGroups($all)) {
        // 同步到 Redis
        _r301clone_syncGroupToRedis($groupId, $all[$groupId]);
        return ['success' => true, 'message' => '站群组配置已更新'];
    }
    
    return ['success' => false, 'message' => '保存失败'];
}

/**
 * 删除站群组
 */
function _r301clone_deleteGroup($groupId) {
    $all = _r301clone_getAllGroups();
    
    if (!isset($all[$groupId])) {
        return ['success' => false, 'message' => '站群组不存在'];
    }
    
    // 删除 Redis 数据
    _r301clone_deleteGroupFromRedis($groupId, $all[$groupId]);
    
    unset($all[$groupId]);
    
    if (_r301clone_saveAllGroups($all)) {
        return ['success' => true, 'message' => '站群组已删除'];
    }
    
    return ['success' => false, 'message' => '删除失败'];
}

/**
 * 切换站群组状态
 */
function _r301clone_toggleGroup($groupId) {
    $all = _r301clone_getAllGroups();
    
    if (!isset($all[$groupId])) {
        return ['success' => false, 'message' => '站群组不存在'];
    }
    
    $all[$groupId]['enabled'] = !($all[$groupId]['enabled'] ?? true);
    $all[$groupId]['updated_at'] = date('Y-m-d H:i:s');
    
    if (_r301clone_saveAllGroups($all)) {
        // 同步到 Redis
        _r301clone_syncGroupToRedis($groupId, $all[$groupId]);
        return ['success' => true, 'message' => '状态已切换'];
    }
    
    return ['success' => false, 'message' => '操作失败'];
}

/**
 * 添加域名到站群组
 */
function _r301clone_addDomains($groupId, $domains) {
    $all = _r301clone_getAllGroups();
    
    if (!isset($all[$groupId])) {
        return ['success' => false, 'message' => '站群组不存在'];
    }
    
    $existingDomains = $all[$groupId]['domains'] ?? [];
    $added = 0;
    $skipped = 0;
    
    foreach ($domains as $domain) {
        $domain = trim(strtolower($domain));
        if (empty($domain)) continue;
        
        // 检查是否已存在
        if (in_array($domain, $existingDomains)) {
            $skipped++;
            continue;
        }
        
        $existingDomains[] = $domain;
        $added++;
    }
    
    $all[$groupId]['domains'] = $existingDomains;
    $all[$groupId]['updated_at'] = date('Y-m-d H:i:s');
    
    if (_r301clone_saveAllGroups($all)) {
        // 同步到 Redis
        _r301clone_syncGroupToRedis($groupId, $all[$groupId]);
        return [
            'success' => true, 
            'message' => "成功添加 {$added} 个域名" . ($skipped > 0 ? "，跳过 {$skipped} 个重复域名" : '')
        ];
    }
    
    return ['success' => false, 'message' => '保存失败'];
}

/**
 * 从站群组移除域名
 */
function _r301clone_removeDomain($groupId, $domain) {
    $all = _r301clone_getAllGroups();
    
    if (!isset($all[$groupId])) {
        return ['success' => false, 'message' => '站群组不存在'];
    }
    
    $domains = $all[$groupId]['domains'] ?? [];
    $key = array_search($domain, $domains);
    
    if ($key === false) {
        return ['success' => false, 'message' => '域名不存在'];
    }
    
    array_splice($domains, $key, 1);
    $all[$groupId]['domains'] = $domains;
    $all[$groupId]['updated_at'] = date('Y-m-d H:i:s');
    
    if (_r301clone_saveAllGroups($all)) {
        // 同步到 Redis
        _r301clone_syncGroupToRedis($groupId, $all[$groupId]);
        return ['success' => true, 'message' => '域名已删除'];
    }
    
    return ['success' => false, 'message' => '删除失败'];
}

/**
 * 获取站群组统计数据（汇总）
 */
function _r301clone_getGroupStats($groupId) {
    $redis = _r301clone_getRedis();
    
    if (!$redis) {
        return [
            'total_count' => 0,
            'domain_count' => 0,
            'latest_redirect' => null
        ];
    }
    
    $group = _r301clone_getGroup($groupId);
    if (!$group) {
        return [
            'total_count' => 0,
            'domain_count' => 0,
            'latest_redirect' => null
        ];
    }
    
    $totalCount = 0;
    $domainCount = 0;
    $latestRedirect = null;
    $latestTimestamp = 0;
    
    $domains = $group['domains'] ?? [];
    
    foreach ($domains as $domain) {
        // 获取该顶级域名下所有二级域名的统计
        // 匹配模式：stats:*.domain 和 stats:domain（包括顶级域名本身）
        $patterns = [
            REDIS_CLONE_PREFIX . 'stats:' . $domain,      // 顶级域名本身
            REDIS_CLONE_PREFIX . 'stats:*.' . $domain     // 所有子域名
        ];
        
        foreach ($patterns as $pattern) {
            $keys = $redis->keys($pattern);
            
            foreach ($keys as $key) {
                $data = $redis->hGetAll($key);
                
                if ($data && isset($data['count'])) {
                    // 累加跳转次数
                    $totalCount += (int)$data['count'];
                    $domainCount++;
                    
                    // 找出最新的跳转时间
                    if (isset($data['last_redirect'])) {
                        $timestamp = strtotime($data['last_redirect']);
                        if ($timestamp > $latestTimestamp) {
                            $latestTimestamp = $timestamp;
                            $latestRedirect = $data['last_redirect'];
                        }
                    }
                }
            }
        }
    }
    
    return [
        'total_count' => $totalCount,
        'domain_count' => $domainCount,
        'latest_redirect' => $latestRedirect
    ];
}

/**
 * 重置站群组统计数据
 */
function _r301clone_resetGroupStats($groupId, $subdomain = null) {
    $redis = _r301clone_getRedis();
    
    if (!$redis) {
        return ['success' => false, 'message' => 'Redis 未连接'];
    }
    
    $group = _r301clone_getGroup($groupId);
    if (!$group) {
        return ['success' => false, 'message' => '站群组不存在'];
    }
    
    if ($subdomain) {
        // 重置单个二级域名
        $key = REDIS_CLONE_PREFIX . 'stats:' . $subdomain;
        $redis->del($key);
        $redis->del(REDIS_CLONE_PREFIX . 'mapping:' . $subdomain);
        return ['success' => true, 'message' => '统计已重置'];
    } else {
        // 重置所有域名的统计
        $domains = $group['domains'] ?? [];
        $count = 0;
        
        foreach ($domains as $domain) {
            $pattern = REDIS_CLONE_PREFIX . 'stats:*.' . $domain;
            $keys = $redis->keys($pattern);
            
            foreach ($keys as $key) {
                $redis->del($key);
                $count++;
            }
            
            // 同时删除映射
            $pattern = REDIS_CLONE_PREFIX . 'mapping:*.' . $domain;
            $keys = $redis->keys($pattern);
            
            foreach ($keys as $key) {
                $redis->del($key);
            }
        }
        
        return ['success' => true, 'message' => "已重置 {$count} 条统计记录"];
    }
}

/**
 * 保存所有站群组到文件
 */
function _r301clone_saveAllGroups($data) {
    $jsonFile = __DIR__ . '/data/clone_groups.json';
    $dir = dirname($jsonFile);
    
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    return file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

/**
 * 同步站群组到 Redis
 */
function _r301clone_syncGroupToRedis($groupId, $group) {
    // 获取 Redis 连接
    $redis = _r301clone_getRedis();
    
    if (!$redis) {
        error_log('[CloneRedirect] Redis 连接不可用，无法同步配置');
        return false;
    }
    
    // 为每个域名保存配置到 Redis
    $domains = $group['domains'] ?? [];
    
    if (empty($domains)) {
        error_log('[CloneRedirect] 站群组 ' . $groupId . ' 没有域名，跳过同步');
        return false;
    }
    
    foreach ($domains as $domain) {
        $key = REDIS_CLONE_PREFIX . 'config:' . $domain;
        $configData = json_encode([
            'group_id' => $groupId,
            'group_name' => $group['name'] ?? '',  // 添加任务名称
            'enabled' => $group['enabled'] ?? true,
            'target_type' => $group['target_type'] ?? 'external',
            'target_url' => $group['target_url'] ?? '',
            'redirect_type' => $group['redirect_type'] ?? 301,
            'max_redirects' => $group['max_redirects'] ?? 0,
            'probability' => $group['probability'] ?? 100,
            'spider_filter' => $group['spider_filter'] ?? [
                'enabled' => false,
                'types' => [
                    'baidu_pc' => false,
                    'baidu_mobile' => false,
                    'google' => false,
                    'sogou' => false
                ]
            ]
        ]);
        
        $result = $redis->setex($key, 86400 * 30, $configData);
        error_log('[CloneRedirect] 同步配置到 Redis: ' . $key . ' => ' . ($result ? '成功' : '失败'));
    }
    
    return true;
}

/**
 * 从 Redis 删除站群组
 */
function _r301clone_deleteGroupFromRedis($groupId, $group) {
    $redis = _r301clone_getRedis();
    
    if (!$redis) {
        return false;
    }
    
    $domains = $group['domains'] ?? [];
    
    foreach ($domains as $domain) {
        // 删除配置
        $redis->del(REDIS_CLONE_PREFIX . 'config:' . $domain);
        
        // 删除统计数据（包括顶级域名本身和所有子域名）
        // 匹配: stats:1-14.cn 和 stats:*.1-14.cn
        $patterns = [
            REDIS_CLONE_PREFIX . 'stats:' . $domain,      // 顶级域名本身
            REDIS_CLONE_PREFIX . 'stats:*.' . $domain     // 所有子域名
        ];
        
        foreach ($patterns as $pattern) {
            $keys = $redis->keys($pattern);
            if ($keys) {
                foreach ($keys as $key) {
                    $redis->del($key);
                }
            }
        }
        
        // 删除映射数据（包括顶级域名本身和所有子域名）
        // 匹配: mapping:1-14.cn 和 mapping:*.1-14.cn
        $patterns = [
            REDIS_CLONE_PREFIX . 'mapping:' . $domain,    // 顶级域名本身
            REDIS_CLONE_PREFIX . 'mapping:*.' . $domain   // 所有子域名
        ];
        
        foreach ($patterns as $pattern) {
            $keys = $redis->keys($pattern);
            if ($keys) {
                foreach ($keys as $key) {
                    $redis->del($key);
                }
            }
        }
    }
    
    return true;
}
