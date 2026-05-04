<?php
/**
 * 寄生重定向功能函数
 */

// 引入 Redis 配置.
require_once __DIR__ . '/redis_config.php';

// 数据文件路径
define('_R301PARASITE_DATA_FILE_', __DIR__ . '/data/parasites.json');

/**
 * 生成唯一ID
 */
function _r301parasite_generateId() {
    return 'pst_' . substr(md5(uniqid(mt_rand(), true)), 0, 12);
}

/**
 * 获取所有任务
 */
function _r301parasite_getAll() {
    if (!file_exists(_R301PARASITE_DATA_FILE_)) {
        return [];
    }
    $content = file_get_contents(_R301PARASITE_DATA_FILE_);
    $data = json_decode($content, true);
    
    // 兼容新格式 {enabled: true, tasks: [...]}
    if (isset($data['tasks']) && is_array($data['tasks'])) {
        return $data['tasks'];
    }
    
    // 兼容旧格式 [...]
    return is_array($data) ? $data : [];
}

/**
 * 保存所有任务
 */
function _r301parasite_saveAll($tasks) {
    $dir = dirname(_R301PARASITE_DATA_FILE_);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    // 读取现有配置以保留 enabled 字段
    $enabled = true;
    if (file_exists(_R301PARASITE_DATA_FILE_)) {
        $content = file_get_contents(_R301PARASITE_DATA_FILE_);
        $data = json_decode($content, true);
        if (isset($data['enabled'])) {
            $enabled = $data['enabled'];
        }
    }
    
    // 保存为新格式 {enabled: true, tasks: [...]}
    $data = [
        'enabled' => $enabled,
        'tasks' => array_values($tasks)
    ];
    
    $result = file_put_contents(
        _R301PARASITE_DATA_FILE_, 
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
    
    // 同步所有任务到 Redis
    if ($result !== false) {
        foreach ($tasks as $task) {
            saveParasiteTaskToRedis($task['id'], $task);
        }
    }
    
    return $result;
}

/**
 * 根据ID获取任务
 */
function _r301parasite_getById($taskId) {
    $tasks = _r301parasite_getAll();
    foreach ($tasks as $task) {
        if ($task['id'] === $taskId) {
            // 加载目录规则的统计数据
            if (!empty($task['directories']) && is_array($task['directories'])) {
                foreach ($task['directories'] as &$dir) {
                    if (!empty($dir['id'])) {
                        $dirStats = getParasiteDirStats($taskId, $dir['id']);
                        $dir['stats'] = $dirStats;
                    }
                }
                unset($dir); // 解除引用
            }
            return $task;
        }
    }
    return null;
}

/**
 * 创建新任务
 */
function _r301parasite_create($data) {
    $tasks = _r301parasite_getAll();
    
    $taskId = _r301parasite_generateId();
    
    $newTask = [
        'id' => $taskId,
        'name' => trim($data['name'] ?? '未命名任务'),
        'enabled' => false,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        
        // 管理类型: directory(按目录) / domain(按域名)
        'manage_type' => $data['manage_type'] ?? 'directory',
        
        // 按目录管理时的设置
        'source_path' => $data['source_path'] ?? '',  // 源目录
        'source_domains' => [],  // 源域名列表
        
        // 按域名管理时的设置
        'source_domain' => $data['source_domain'] ?? '',  // 源域名
        'directories' => [],  // 目录规则列表
        
        // 跳转模式: focus(集权) / interlink(互连)
        'redirect_mode' => $data['redirect_mode'] ?? 'focus',
        
        // 集权模式的目标域名列表
        'target_domains' => [],
        
        // 蜘蛛筛选配置
        'spider_filter' => $data['spider_filter'] ?? [
            'enabled' => false,
            'types' => [
                'baidu_pc' => false,
                'baidu_mobile' => false,
                'google' => false,
                'sogou' => false
            ]
        ],
        
        // 通用设置
        'settings' => [
            'path_mode' => 'strip_prefix',  // strip_prefix / keep_full
            'replacements' => [],  // 自定义替换规则
            'redirect_type' => '301',
            'probability' => 100,
            'exclude_paths' => [],
            'exclude_extensions' => ['.css', '.js', '.jpg', '.jpeg', '.png', '.gif', '.ico', '.svg', '.woff', '.woff2', '.ttf'],
        ],
        
        // 统计
        'stats' => [
            'total_redirects' => 0,
        ],
    ];
    
    $tasks[] = $newTask;
    _r301parasite_saveAll($tasks);
    
    return $taskId;
}

/**
 * 更新任务
 */
function _r301parasite_update($taskId, $data) {
    $tasks = _r301parasite_getAll();
    
    foreach ($tasks as &$task) {
        if ($task['id'] === $taskId) {
            // 更新基本信息
            if (isset($data['name'])) {
                $task['name'] = trim($data['name']);
            }
            if (isset($data['source_path'])) {
                $task['source_path'] = trim($data['source_path']);
            }
            if (isset($data['source_domain'])) {
                $task['source_domain'] = trim($data['source_domain']);
            }
            if (isset($data['redirect_mode'])) {
                $task['redirect_mode'] = $data['redirect_mode'];
            }
            if (isset($data['source_domains'])) {
                $task['source_domains'] = $data['source_domains'];
            }
            if (isset($data['target_domains'])) {
                $task['target_domains'] = $data['target_domains'];
            }
            if (isset($data['directories'])) {
                $task['directories'] = $data['directories'];
            }
            if (isset($data['settings'])) {
                $task['settings'] = array_merge($task['settings'] ?? [], $data['settings']);
            }
            if (isset($data['spider_filter'])) {
                $task['spider_filter'] = $data['spider_filter'];
            }
            
            $task['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    
    return _r301parasite_saveAll($tasks);
}

/**
 * 删除任务
 */
function _r301parasite_delete($taskId) {
    // 从 Redis 删除
    deleteParasiteTaskFromRedis($taskId);
    
    // 从 JSON 删除
    $tasks = _r301parasite_getAll();
    $tasks = array_filter($tasks, function($t) use ($taskId) {
        return $t['id'] !== $taskId;
    });
    return _r301parasite_saveAll(array_values($tasks));
}

/**
 * 切换任务状态
 */
function _r301parasite_toggle($taskId, $enabled) {
    $tasks = _r301parasite_getAll();
    
    foreach ($tasks as &$task) {
        if ($task['id'] === $taskId) {
            $task['enabled'] = (bool)$enabled;
            $task['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    
    return _r301parasite_saveAll($tasks);
}

/**
 * 添加源域名（按目录管理模式）
 */
function _r301parasite_addSourceDomains($taskId, $domainsText) {
    $tasks = _r301parasite_getAll();
    $added = 0;
    $skipped = 0;
    
    foreach ($tasks as &$task) {
        if ($task['id'] === $taskId && $task['manage_type'] === 'directory') {
            $lines = explode("\n", $domainsText);
            $existingDomains = array_column($task['source_domains'], 'domain');
            $existingDomainsLower = array_map('strtolower', $existingDomains);
            
            foreach ($lines as $line) {
                $domain = trim($line);
                if (empty($domain) || strpos($domain, '#') === 0) {
                    continue;
                }
                
                // 移除协议头
                $domain = preg_replace('#^https?://#i', '', $domain);
                $domain = rtrim($domain, '/');
                
                // 用小写检查重复
                $domainLower = strtolower($domain);
                if (in_array($domainLower, $existingDomainsLower)) {
                    $skipped++;
                    continue;
                }
                
                $task['source_domains'][] = [
                    'domain' => $domain, // 保留原始大小写，支持占位符
                    'enabled' => true,
                    'added_at' => date('Y-m-d H:i:s'),
                ];
                $existingDomainsLower[] = $domainLower;
                $added++;
            }
            
            $task['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    
    _r301parasite_saveAll($tasks);
    return ['added' => $added, 'skipped' => $skipped];
}

/**
 * 更新源域名状态
 */
function _r301parasite_updateSourceDomain($taskId, $domain, $enabled) {
    $tasks = _r301parasite_getAll();
    
    foreach ($tasks as &$task) {
        if ($task['id'] === $taskId) {
            foreach ($task['source_domains'] as &$d) {
                if (strtolower($d['domain']) === strtolower($domain)) {
                    $d['enabled'] = (bool)$enabled;
                    break;
                }
            }
            $task['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    
    return _r301parasite_saveAll($tasks);
}

/**
 * 删除源域名
 */
function _r301parasite_deleteSourceDomain($taskId, $domain) {
    $tasks = _r301parasite_getAll();
    
    foreach ($tasks as &$task) {
        if ($task['id'] === $taskId) {
            $task['source_domains'] = array_filter($task['source_domains'], function($d) use ($domain) {
                return strtolower($d['domain']) !== strtolower($domain);
            });
            $task['source_domains'] = array_values($task['source_domains']);
            $task['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    
    return _r301parasite_saveAll($tasks);
}

/**
 * 添加目标域名（集权模式）
 */
function _r301parasite_addTargetDomains($taskId, $domainsText) {
    $tasks = _r301parasite_getAll();
    $added = 0;
    $skipped = 0;
    
    foreach ($tasks as &$task) {
        if ($task['id'] === $taskId) {
            $lines = explode("\n", $domainsText);
            $existingDomainsLower = array_map('strtolower', $task['target_domains']);
            
            foreach ($lines as $line) {
                $domain = trim($line);
                if (empty($domain) || strpos($domain, '#') === 0) {
                    continue;
                }
                
                // 移除协议头
                $domain = preg_replace('#^https?://#i', '', $domain);
                $domain = rtrim($domain, '/');
                
                // 用小写检查重复
                $domainLower = strtolower($domain);
                if (in_array($domainLower, $existingDomainsLower)) {
                    $skipped++;
                    continue;
                }
                
                $task['target_domains'][] = $domain; // 保留原始大小写，支持占位符
                $existingDomainsLower[] = $domainLower;
                $added++;
            }
            
            $task['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    
    _r301parasite_saveAll($tasks);
    return ['added' => $added, 'skipped' => $skipped];
}

/**
 * 删除目标域名
 */
function _r301parasite_deleteTargetDomain($taskId, $domain) {
    $tasks = _r301parasite_getAll();
    
    foreach ($tasks as &$task) {
        if ($task['id'] === $taskId) {
            $task['target_domains'] = array_filter($task['target_domains'], function($d) use ($domain) {
                return strtolower($d) !== strtolower($domain);
            });
            $task['target_domains'] = array_values($task['target_domains']);
            $task['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    
    return _r301parasite_saveAll($tasks);
}

/**
 * 添加目录规则（按域名管理模式）
 */
function _r301parasite_addDirectory($taskId, $data) {
    $tasks = _r301parasite_getAll();
    
    foreach ($tasks as &$task) {
        if ($task['id'] === $taskId) {
            $path = trim($data['path'] ?? '');
            if (empty($path)) {
                return false;
            }
            
            // 确保路径格式正确
            if ($path[0] !== '/') {
                $path = '/' . $path;
            }
            if (substr($path, -1) !== '/') {
                $path .= '/';
            }
            
            // 初始化 directories 数组（如果不存在）
            if (!isset($task['directories'])) {
                $task['directories'] = [];
            }
            
            // 检查是否已存在
            foreach ($task['directories'] as $dir) {
                if ($dir['path'] === $path) {
                    return false;
                }
            }
            
            // 构建目录规则
            $newDir = [
                'id' => 'dir_' . substr(md5(uniqid(mt_rand(), true)), 0, 8),
                'path' => $path,
                'target_domain' => trim($data['target_domain'] ?? ''),
                'enabled' => true,
                'path_mode' => $data['path_mode'] ?? 'strip_prefix',
                'replacements' => $data['replacements'] ?? [],
                'redirect_type' => $data['redirect_type'] ?? '301',
                'probability' => max(1, min(100, intval($data['probability'] ?? 100))),
                'stats' => ['total_redirects' => 0],
                'added_at' => date('Y-m-d H:i:s'),
            ];
            
            // 如果提供了 target_domains 数组，添加到配置中
            if (isset($data['target_domains']) && is_array($data['target_domains'])) {
                $newDir['target_domains'] = array_values(array_filter(array_map('trim', $data['target_domains'])));
            }
            
            $task['directories'][] = $newDir;
            
            $task['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    
    return _r301parasite_saveAll($tasks);
}

/**
 * 更新目录规则
 */
function _r301parasite_updateDirectory($taskId, $dirId, $data) {
    $tasks = _r301parasite_getAll();
    
    foreach ($tasks as &$task) {
        if ($task['id'] === $taskId) {
            foreach ($task['directories'] as &$dir) {
                if ($dir['id'] === $dirId) {
                    if (isset($data['enabled'])) {
                        $dir['enabled'] = (bool)$data['enabled'];
                    }
                    
                    // 处理目标域名（单个或多个）
                    // 优先处理多个域名
                    if (isset($data['target_domains'])) {
                        if (is_array($data['target_domains'])) {
                            $cleanDomains = array_values(array_filter(array_map('trim', $data['target_domains'])));
                            if (empty($cleanDomains)) {
                                // 空数组，删除 target_domains 字段
                                unset($dir['target_domains']);
                            } else {
                                // 有有效域名，保存并清除单个域名字段
                                $dir['target_domains'] = $cleanDomains;
                                unset($dir['target_domain']);
                            }
                        }
                    }
                    
                    // 处理单个域名
                    if (isset($data['target_domain'])) {
                        $singleDomain = trim($data['target_domain']);
                        if (empty($singleDomain)) {
                            // 空字符串，删除 target_domain 字段
                            unset($dir['target_domain']);
                        } else {
                            // 有有效域名，保存并清除多个域名数组
                            $dir['target_domain'] = $singleDomain;
                            unset($dir['target_domains']);
                        }
                    }
                    
                    if (isset($data['path_mode'])) {
                        $dir['path_mode'] = $data['path_mode'];
                    }
                    if (isset($data['replacements'])) {
                        $dir['replacements'] = $data['replacements'];
                    }
                    if (isset($data['redirect_type'])) {
                        $dir['redirect_type'] = $data['redirect_type'];
                    }
                    if (isset($data['probability'])) {
                        // 验证概率值范围（1-100）
                        $prob = intval($data['probability']);
                        $prob = max(1, min(100, $prob));
                        $dir['probability'] = $prob;
                    }
                    break;
                }
            }
            $task['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    
    return _r301parasite_saveAll($tasks);
}

/**
 * 删除目录规则
 */
function _r301parasite_deleteDirectory($taskId, $dirId) {
    $tasks = _r301parasite_getAll();
    
    foreach ($tasks as &$task) {
        if ($task['id'] === $taskId) {
            $task['directories'] = array_filter($task['directories'], function($d) use ($dirId) {
                return $d['id'] !== $dirId;
            });
            $task['directories'] = array_values($task['directories']);
            $task['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    
    return _r301parasite_saveAll($tasks);
}

/**
 * 增加统计计数
 */
function _r301parasite_incrementStats($taskId, $dirId = null) {
    $tasks = _r301parasite_getAll();
    
    foreach ($tasks as &$task) {
        if ($task['id'] === $taskId) {
            $task['stats']['total_redirects'] = ($task['stats']['total_redirects'] ?? 0) + 1;
            
            if ($dirId) {
                foreach ($task['directories'] as &$dir) {
                    if ($dir['id'] === $dirId) {
                        $dir['stats']['total_redirects'] = ($dir['stats']['total_redirects'] ?? 0) + 1;
                        break;
                    }
                }
            }
            break;
        }
    }
    
    return _r301parasite_saveAll($tasks);
}

/**
 * 获取启用的任务列表
 */
function _r301parasite_getEnabled() {
    $tasks = _r301parasite_getAll();
    return array_filter($tasks, function($t) {
        return !empty($t['enabled']);
    });
}

/**
 * 获取任务摘要信息
 */
function _r301parasite_getSummary($task) {
    $summary = [];
    
    if ($task['manage_type'] === 'directory') {
        $enabledDomains = count(array_filter($task['source_domains'], function($d) {
            return !empty($d['enabled']);
        }));
        $totalDomains = count($task['source_domains']);
        $summary['source'] = $task['source_path'] . " ({$enabledDomains}/{$totalDomains}域名)";
        $summary['mode'] = $task['redirect_mode'] === 'focus' ? '集权' : '互连';
        $summary['target'] = $task['redirect_mode'] === 'focus' 
            ? (count($task['target_domains']) > 0 ? implode(', ', array_slice($task['target_domains'], 0, 2)) . (count($task['target_domains']) > 2 ? '...' : '') : '未设置')
            : '组内互跳';
    } else {
        $enabledDirs = count(array_filter($task['directories'], function($d) {
            return !empty($d['enabled']);
        }));
        $totalDirs = count($task['directories']);
        $summary['source'] = $task['source_domain'] . " ({$enabledDirs}/{$totalDirs}目录)";
        $summary['mode'] = '-';
        $summary['target'] = '多目标';
    }
    
    return $summary;
}

/**
 * 解析替换规则文本
 */
function _r301parasite_parseReplacements($text) {
    $replacements = [];
    $lines = explode("\n", $text);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // 支持格式: find -> replace 或 find => replace 或 find,replace
        if (preg_match('/^(.+?)\s*(?:->|=>|,)\s*(.+)$/', $line, $matches)) {
            $replacements[] = [
                'find' => trim($matches[1]),
                'replace' => trim($matches[2]),
            ];
        }
    }
    
    return $replacements;
}

/**
 * 格式化替换规则为文本
 */
function _r301parasite_formatReplacements($replacements) {
    $lines = [];
    foreach ($replacements as $r) {
        $lines[] = $r['find'] . ' -> ' . $r['replace'];
    }
    return implode("\n", $lines);
}

/**
 * 为目录添加多个目标域名
 */
function _r301parasite_addDirectoryTargets($taskId, $dirId, $domains) {
    $tasks = _r301parasite_getAll();
    $added = 0;
    $skipped = 0;
    
    foreach ($tasks as &$task) {
        if ($task['id'] === $taskId) {
            foreach ($task['directories'] as &$dir) {
                if ($dir['id'] === $dirId) {
                    // 初始化 target_domains 数组（如果不存在）
                    if (!isset($dir['target_domains'])) {
                        $dir['target_domains'] = [];
                    }
                    
                    // 获取已存在的域名列表（用于去重）
                    $existingDomains = [];
                    foreach ($dir['target_domains'] as $d) {
                        if (is_string($d)) {
                            $existingDomains[] = trim($d);
                        } elseif (is_array($d) && isset($d['domain'])) {
                            $existingDomains[] = trim($d['domain']);
                        }
                    }
                    
                    // 处理输入的域名
                    $domainList = array_filter(array_map('trim', explode("\n", $domains)));
                    
                    foreach ($domainList as $domain) {
                        if (empty($domain)) {
                            continue;
                        }
                        
                        // 检查是否已存在
                        if (in_array($domain, $existingDomains)) {
                            $skipped++;
                            continue;
                        }
                        
                        // 添加新域名（使用简单字符串格式）
                        $dir['target_domains'][] = $domain;
                        $existingDomains[] = $domain;
                        $added++;
                    }
                    
                    break;
                }
            }
            $task['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    
    if ($added > 0 || $skipped > 0) {
        _r301parasite_saveAll($tasks);
    }
    
    return ['added' => $added, 'skipped' => $skipped];
}

/**
 * 删除目录的某个目标域名
 */
function _r301parasite_deleteDirectoryTarget($taskId, $dirId, $targetDomain) {
    $tasks = _r301parasite_getAll();
    
    foreach ($tasks as &$task) {
        if ($task['id'] === $taskId) {
            foreach ($task['directories'] as &$dir) {
                if ($dir['id'] === $dirId) {
                    if (isset($dir['target_domains']) && is_array($dir['target_domains'])) {
                        // 过滤掉要删除的域名
                        $dir['target_domains'] = array_values(array_filter($dir['target_domains'], function($d) use ($targetDomain) {
                            if (is_string($d)) {
                                return trim($d) !== trim($targetDomain);
                            } elseif (is_array($d) && isset($d['domain'])) {
                                return trim($d['domain']) !== trim($targetDomain);
                            }
                            return true;
                        }));
                    }
                    break;
                }
            }
            $task['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    
    return _r301parasite_saveAll($tasks);
}

