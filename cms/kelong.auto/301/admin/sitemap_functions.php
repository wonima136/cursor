<?php
/**
 * 地图重定向 - 后台操作函数
 */

require_once __DIR__ . '/redis_config.php';

// 数据文件路径
define('_SITEMAP_DATA_FILE_', __DIR__ . '/data/sitemap_tasks.json');
define('_SITEMAP_FAILED_FILE_', __DIR__ . '/data/sitemap_failed.json');

/**
 * 生成任务ID
 */
function _sitemap_generateId() {
    return 'sitemap_' . time() . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
}

/**
 * 获取所有任务
 */
function _sitemap_getAll() {
    if (!file_exists(_SITEMAP_DATA_FILE_)) {
        return [];
    }
    
    $content = file_get_contents(_SITEMAP_DATA_FILE_);
    $data = json_decode($content, true);
    
    if (!is_array($data)) {
        return [];
    }
    
    // 兼容旧格式和新格式
    if (isset($data['tasks']) && is_array($data['tasks'])) {
        return $data['tasks'];
    }
    
    return is_array($data) ? $data : [];
}

/**
 * 保存所有任务
 */
function _sitemap_saveAll($tasks) {
    // 确保目录存在
    $dir = dirname(_SITEMAP_DATA_FILE_);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    // 保存为新格式
    $data = [
        'tasks' => array_values($tasks)
    ];
    
    $result = file_put_contents(
        _SITEMAP_DATA_FILE_, 
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
    
    // 同步所有任务到 Redis
    if ($result !== false) {
        foreach ($tasks as $task) {
            saveSitemapTaskToRedis($task['id'], $task);
        }
    }
    
    return $result;
}

/**
 * 根据ID获取任务
 */
function _sitemap_getById($taskId) {
    $tasks = _sitemap_getAll();
    foreach ($tasks as $task) {
        if ($task['id'] === $taskId) {
            // 加载统计数据
            $task['stats'] = getSitemapTaskStats($taskId);
            return $task;
        }
    }
    return null;
}

/**
 * 创建新任务
 */
function _sitemap_create($data) {
    $tasks = _sitemap_getAll();
    
    $taskId = _sitemap_generateId();
    
    $newTask = [
        'id' => $taskId,
        'name' => trim($data['name'] ?? '未命名任务'),
        'enabled' => false,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        
        // 域名列表
        'domains' => [],
        
        // 地图页路径
        'sitemap_path' => '/sitemap.xml',
        
        // 跳转比例
        'ratio' => [
            'domain' => 30,  // 域名首页比例
            'inner' => 70    // 内页比例
        ],
        
        // 每次获取链接数量
        'max_links' => 50,
        
        // 包含目录链接
        'include_directory' => false,
        
        // 指定目录过滤
        'specified_paths' => [],
        
        // 跳转类型
        'redirect_type' => '301',
        
        // 蜘蛛筛选
        'spider_filter' => $data['spider_filter'] ?? [
            'enabled' => false,
            'types' => []
        ]
    ];
    
    $tasks[] = $newTask;
    
    if (_sitemap_saveAll($tasks)) {
        return $newTask;
    }
    
    return null;
}

/**
 * 更新任务
 */
function _sitemap_update($taskId, $data) {
    $tasks = _sitemap_getAll();
    $updated = false;
    
    foreach ($tasks as &$task) {
        if ($task['id'] === $taskId) {
            // 更新允许的字段
            if (isset($data['name'])) {
                $task['name'] = trim($data['name']);
            }
            if (isset($data['enabled'])) {
                $task['enabled'] = (bool)$data['enabled'];
            }
            if (isset($data['domains'])) {
                $task['domains'] = $data['domains'];
            }
            if (isset($data['sitemap_path'])) {
                $task['sitemap_path'] = trim($data['sitemap_path']);
            }
            if (isset($data['ratio'])) {
                $task['ratio'] = $data['ratio'];
            }
            if (isset($data['max_links'])) {
                $task['max_links'] = intval($data['max_links']);
            }
            if (isset($data['include_directory'])) {
                $task['include_directory'] = (bool)$data['include_directory'];
            }
            if (isset($data['specified_paths'])) {
                $task['specified_paths'] = $data['specified_paths'];
            }
            if (isset($data['redirect_type'])) {
                $task['redirect_type'] = $data['redirect_type'];
            }
            if (isset($data['redirect_type'])) {
                $task['redirect_type'] = $data['redirect_type'];
            }
            if (isset($data['spider_filter'])) {
                $task['spider_filter'] = $data['spider_filter'];
            }
            
            $task['updated_at'] = date('Y-m-d H:i:s');
            $updated = true;
            break;
        }
    }
    unset($task);
    
    if ($updated) {
        return _sitemap_saveAll($tasks);
    }
    
    return false;
}

/**
 * 删除任务
 */
function _sitemap_delete($taskId) {
    $tasks = _sitemap_getAll();
    $newTasks = [];
    $deleted = false;
    
    foreach ($tasks as $task) {
        if ($task['id'] === $taskId) {
            $deleted = true;
            // ★ 删除 Redis 中的所有相关数据
            deleteSitemapTaskFromRedis($taskId);
            
            // ★ 删除失败记录
            _sitemap_clearFailureRecords($taskId);
            
            continue;
        }
        $newTasks[] = $task;
    }
    
    if ($deleted) {
        return _sitemap_saveAll($newTasks);
    }
    
    return false;
}

/**
 * 切换任务启用状态
 */
function _sitemap_toggle($taskId, $enabled) {
    return _sitemap_update($taskId, ['enabled' => $enabled]);
}

/**
 * 获取失败记录
 */
function _sitemap_getFailureRecords($taskId = null) {
    if (!file_exists(_SITEMAP_FAILED_FILE_)) {
        return [];
    }
    
    $content = file_get_contents(_SITEMAP_FAILED_FILE_);
    $data = json_decode($content, true) ?: [];
    
    if ($taskId !== null) {
        return $data[$taskId] ?? [];
    }
    
    return $data;
}

/**
 * 清除失败记录
 */
function _sitemap_clearFailureRecords($taskId, $domain = null) {
    if (!file_exists(_SITEMAP_FAILED_FILE_)) {
        return true;
    }
    
    $content = file_get_contents(_SITEMAP_FAILED_FILE_);
    $data = json_decode($content, true) ?: [];
    
    if ($domain !== null) {
        // 清除特定域名的失败记录
        if (isset($data[$taskId][$domain])) {
            unset($data[$taskId][$domain]);
            
            if (empty($data[$taskId])) {
                unset($data[$taskId]);
            }
        }
    } else {
        // 清除整个任务的失败记录
        if (isset($data[$taskId])) {
            unset($data[$taskId]);
        }
    }
    
    return file_put_contents(
        _SITEMAP_FAILED_FILE_,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    ) !== false;
}

/**
 * 清除域名缓存
 */
function _sitemap_clearDomainCache($taskId, $domain) {
    return clearSitemapDomainCache($taskId, $domain);
}

/**
 * 测试抓取地图页
 */
function _sitemap_testFetch($domain, $sitemapPath) {
    // 构建URL
    $domain = preg_replace('#^https?://#i', '', trim($domain));
    $domain = rtrim($domain, '/');
    
    $path = trim($sitemapPath);
    if (!empty($path) && $path[0] !== '/') {
        $path = '/' . $path;
    }
    
    $url = 'http://' . $domain . $path;
    
    // 抓取
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
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode !== 200 || $html === false) {
        return [
            'success' => false,
            'error' => $error ?: "HTTP {$httpCode}",
            'url' => $url
        ];
    }
    
    // 提取链接
    preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches);
    
    $links = [];
    if (!empty($matches[1])) {
        foreach ($matches[1] as $link) {
            $link = trim($link);
            if (empty($link) || $link === '#' || strpos($link, 'javascript:') === 0) {
                continue;
            }
            
            // 处理相对路径
            if ($link[0] === '/') {
                $link = 'http://' . $domain . $link;
            } elseif (!preg_match('#^https?://#i', $link)) {
                continue;
            }
            
            $links[] = $link;
        }
    }
    
    $links = array_unique($links);
    
    return [
        'success' => true,
        'url' => $url,
        'total' => count($links),
        'links' => array_slice($links, 0, 20) // 只返回前20条用于预览
    ];
}

/**
 * 预抓取任务的所有域名sitemap
 */
function _sitemap_prefetch($taskId) {
    // 设置执行时间限制（最多120秒）
    set_time_limit(120);
    
    // 标记开始抓取
    _sitemap_setPrefetchStatus($taskId, 'running');
    
    $task = _sitemap_getById($taskId);
    if (!$task) {
        _sitemap_setPrefetchStatus($taskId, 'completed');
        return ['success' => false, 'message' => '任务不存在'];
    }
    
    $domains = $task['domains'] ?? [];
    if (empty($domains)) {
        _sitemap_setPrefetchStatus($taskId, 'completed');
        return ['success' => false, 'message' => '没有配置域名'];
    }
    
    $sitemapPath = $task['sitemap_path'] ?? '/sitemap.xml';
    $maxLinks = $task['max_links'] ?? 50;
    $includeDirs = $task['include_directory'] ?? false;
    $specifiedPaths = $task['specified_paths'] ?? [];
    
    // 在函数开始时获取Redis连接（复用）
    require_once __DIR__ . '/redis_config.php';
    $redis = getRedis();
    if (!$redis) {
        _sitemap_setPrefetchStatus($taskId, 'completed');
        return ['success' => false, 'message' => 'Redis连接失败'];
    }
    
    $successCount = 0;
    $failedDomains = [];
    $startTime = time();
    
    foreach ($domains as $domain) {
        $domain = trim($domain);
        if (empty($domain)) continue;
        
        // 检查是否超时（超过100秒就停止）
        if (time() - $startTime > 100) {
            $failedDomains[] = [
                'domain' => $domain,
                'error' => '预抓取超时，已跳过',
                'time' => date('Y-m-d H:i:s')
            ];
            continue;
        }
        
        $success = false;
        $lastError = '';
        
        // 尝试3次
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $url = 'http://' . preg_replace('#^https?://#i', '', rtrim($domain, '/')) . $sitemapPath;
            
            // 抓取sitemap
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8); // 减少到8秒
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // 连接超时5秒
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'seo in my life');
            
            $html = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($httpCode !== 200 || $html === false) {
                $lastError = $error ?: "HTTP {$httpCode}";
                if ($attempt < 3) {
                    sleep(1); // 等待1秒后重试
                }
                continue;
            }
            
            // 清除所有HTML标签，保留纯文本内容
            $text = strip_tags($html);
            
            // 提取所有http/https开头的链接
            preg_match_all('#https?://[^\s<>"\']+#i', $text, $matches);
            $links = array_unique($matches[0]);
            
            // 过滤链接
            $filteredLinks = [];
            foreach ($links as $link) {
                // 排除资源文件
                if (preg_match('/\.(jpg|jpeg|png|gif|css|js|ico|svg|woff|woff2|ttf|eot|mp4|mp3|pdf|zip|rar)$/i', $link)) {
                    continue;
                }
                
                // 目录链接处理
                if (!$includeDirs && substr($link, -1) === '/') {
                    continue;
                }
                
                // 指定目录过滤
                if (!empty($specifiedPaths)) {
                    $matched = false;
                    foreach ($specifiedPaths as $path) {
                        if (strpos($link, $path) !== false) {
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) continue;
                }
                
                $filteredLinks[] = $link;
            }
            
            if (empty($filteredLinks)) {
                $lastError = '未提取到有效链接';
                if ($attempt < 3) {
                    sleep(1);
                }
                continue;
            }
            
            // 取前N条并打乱
            $filteredLinks = array_slice($filteredLinks, 0, $maxLinks);
            shuffle($filteredLinks);
            
            // 保存到Redis
            $prefix = REDIS_SITEMAP_PREFIX;
            $domainKey = md5($domain);
            $linksKey = "{$prefix}task:{$taskId}:domain:{$domainKey}:links";
            $usedKey = "{$prefix}task:{$taskId}:domain:{$domainKey}:used";
            
            // 清空旧数据
            $redis->del($linksKey);
            
            // 保存新链接
            foreach ($filteredLinks as $link) {
                $redis->rPush($linksKey, $link);
            }
            
            // 重置使用计数
            $redis->set($usedKey, 0);
            
            $success = true;
            break;
        }
        
        if ($success) {
            $successCount++;
            // 清除失败记录
            _sitemap_clearFailureRecords($taskId, $domain);
        } else {
            $failedDomains[] = [
                'domain' => $domain,
                'error' => $lastError,
                'time' => date('Y-m-d H:i:s')
            ];
            // 记录失败
            _sitemap_recordFailure($taskId, $domain, $lastError);
        }
    }
    
    // 标记抓取完成
    _sitemap_setPrefetchStatus($taskId, 'completed');
    
    return [
        'success' => true,
        'total' => count($domains),
        'success_count' => $successCount,
        'failed_count' => count($failedDomains),
        'failed_domains' => $failedDomains
    ];
}

/**
 * 设置预抓取状态
 */
function _sitemap_setPrefetchStatus($taskId, $status) {
    require_once __DIR__ . '/redis_config.php';
    $redis = getRedis();
    
    if (!$redis) {
        return false;
    }
    
    $prefix = REDIS_SITEMAP_PREFIX;
    $statusKey = "{$prefix}task:{$taskId}:prefetch_status";
    $redis->set($statusKey, $status);
    $redis->expire($statusKey, 86400); // 24小时
    
    return true;
}

/**
 * 获取预抓取状态
 */
function _sitemap_getPrefetchStatus($taskId) {
    require_once __DIR__ . '/redis_config.php';
    $redis = getRedis();
    
    if (!$redis) {
        return 'completed';
    }
    
    $prefix = REDIS_SITEMAP_PREFIX;
    $statusKey = "{$prefix}task:{$taskId}:prefetch_status";
    $status = $redis->get($statusKey);
    
    // 如果没有状态，返回completed
    if (!$status) {
        return 'completed';
    }
    
    return $status; // running, completed, failed
}

/**
 * 检查Redis中域名的链接数据
 * 返回实际有链接数据的域名数量
 */
function _sitemap_countCachedDomains($taskId, $domains) {
    require_once __DIR__ . '/redis_config.php';
    $redis = getRedis();
    if (!$redis) {
        return 0;
    }
    
    $cachedCount = 0;
    $prefix = REDIS_SITEMAP_PREFIX;
    
    foreach ($domains as $domain) {
        $domain = trim($domain);
        if (empty($domain)) continue;
        
        $domainKey = md5($domain);
        $linksKey = "{$prefix}task:{$taskId}:domain:{$domainKey}:links";
        
        // 检查这个域名是否有缓存的链接
        $linkCount = $redis->lLen($linksKey);
        if ($linkCount > 0) {
            $cachedCount++;
        }
    }
    
    return $cachedCount;
}

/**
 * 记录域名抓取失败
 */
function _sitemap_recordFailure($taskId, $domain, $error) {
    $failureFile = __DIR__ . '/data/sitemap_failed.json';
    
    $failures = [];
    if (file_exists($failureFile)) {
        $failures = json_decode(file_get_contents($failureFile), true) ?: [];
    }
    
    if (!isset($failures[$taskId])) {
        $failures[$taskId] = [];
    }
    
    $failures[$taskId][$domain] = [
        'error' => $error,
        'last_fail_time' => date('Y-m-d H:i:s'),
        'fail_count' => 3
    ];
    
    file_put_contents($failureFile, json_encode($failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

