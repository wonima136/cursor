<?php
/**
 * 整站重定向系统 - 核心函数
 * 函数前缀: _r301sitewide_ 避免与其他程序冲突
 */

// 数据文件路径
if (!defined('_R301SITEWIDE_DATA_FILE_')) {
    define('_R301SITEWIDE_DATA_FILE_', __DIR__ . '/data/sitewide.json');
}

/**
 * 生成唯一任务ID
 */
function _r301sitewide_generateId() {
    return 'site_' . date('Ymd') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
}

/**
 * 获取所有任务配置
 */
function _r301sitewide_getConfig() {
    if (!file_exists(_R301SITEWIDE_DATA_FILE_)) {
        return ['enabled' => false, 'tasks' => []];
    }
    $data = json_decode(file_get_contents(_R301SITEWIDE_DATA_FILE_), true);
    if (!is_array($data)) {
        return ['enabled' => false, 'tasks' => []];
    }
    // 确保结构完整
    if (!isset($data['enabled'])) {
        $data['enabled'] = false;
    }
    if (!isset($data['tasks'])) {
        $data['tasks'] = [];
    }
    return $data;
}

/**
 * 保存所有任务配置
 */
function _r301sitewide_saveConfig($config) {
    $file = _R301SITEWIDE_DATA_FILE_;
    $dir = dirname($file);
    
    // 确保目录存在
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true)) {
            error_log("Failed to create directory: {$dir}");
            return false;
        }
    }
    
    // 检查目录是否可写
    if (!is_writable($dir)) {
        error_log("Directory not writable: {$dir}");
        return false;
    }
    
    // JSON编码
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        error_log("JSON encode failed: " . json_last_error_msg());
        return false;
    }
    
    // 使用临时文件 + 重命名确保原子写入
    $tempFile = $file . '.tmp.' . getmypid();
    $result = file_put_contents($tempFile, $json, LOCK_EX);
    
    if ($result === false) {
        error_log("Failed to write temp file: {$tempFile}");
        @unlink($tempFile);
        return false;
    }
    
    // 重命名
    if (!@rename($tempFile, $file)) {
        error_log("Failed to rename {$tempFile} to {$file}");
        @unlink($tempFile);
        return false;
    }
    
    // 设置文件权限
    @chmod($file, 0644);
    
    return true;
}

/**
 * 获取单个任务
 */
function _r301sitewide_getById($taskId) {
    $config = _r301sitewide_getConfig();
    foreach ($config['tasks'] as $task) {
        if ($task['id'] === $taskId) {
            return $task;
        }
    }
    return null;
}

/**
 * 创建新任务
 */
function _r301sitewide_create($data) {
    $config = _r301sitewide_getConfig();
    
    $task = [
        'id' => _r301sitewide_generateId(),
        'name' => trim($data['name'] ?? '未命名任务'),
        'enabled' => isset($data['enabled']) ? (bool)$data['enabled'] : false,
        'source_domains' => $data['source_domains'] ?? [],
        'target_domains' => $data['target_domains'] ?? [],
        'redirect_type' => $data['redirect_type'] ?? '301',
        'follow_subdomain' => isset($data['follow_subdomain']) ? (bool)$data['follow_subdomain'] : false,
        'follow_uri' => isset($data['follow_uri']) ? (bool)$data['follow_uri'] : true,
        'uri_replacements' => $data['uri_replacements'] ?? [],
        'fallback_urls' => $data['fallback_urls'] ?? [],
        'uri_filter' => [
            'enabled' => isset($data['uri_filter']['enabled']) ? (bool)$data['uri_filter']['enabled'] : false,
            'mode' => $data['uri_filter']['mode'] ?? 'blacklist',
            'rules' => $data['uri_filter']['rules'] ?? [],
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
            'total_redirects' => 0,
        ],
        'created_at' => date('Y-m-d H:i:s'),
    ];
    
    $config['tasks'][] = $task;
    
    // 保存到 JSON
    if (!_r301sitewide_saveConfig($config)) {
        return false;
    }
    
    // 保存到 Redis
    require_once __DIR__ . '/redis_config.php';
    saveSitewideTaskToRedis($task['id'], $task);
    
    return $task['id'];
}

/**
 * 更新任务
 */
function _r301sitewide_update($taskId, $data) {
    $config = _r301sitewide_getConfig();
    $found = false;
    
    foreach ($config['tasks'] as &$task) {
        if ($task['id'] === $taskId) {
            // 更新字段
            if (isset($data['name'])) $task['name'] = trim($data['name']);
            if (isset($data['enabled'])) $task['enabled'] = (bool)$data['enabled'];
            if (isset($data['source_domains'])) $task['source_domains'] = $data['source_domains'];
            if (isset($data['target_domains'])) $task['target_domains'] = $data['target_domains'];
            if (isset($data['redirect_type'])) $task['redirect_type'] = $data['redirect_type'];
            if (isset($data['follow_subdomain'])) $task['follow_subdomain'] = (bool)$data['follow_subdomain'];
            if (isset($data['follow_uri'])) $task['follow_uri'] = (bool)$data['follow_uri'];
            if (isset($data['uri_replacements'])) $task['uri_replacements'] = $data['uri_replacements'];
            if (isset($data['fallback_urls'])) $task['fallback_urls'] = $data['fallback_urls'];
            if (isset($data['uri_filter'])) $task['uri_filter'] = $data['uri_filter'];
            if (isset($data['spider_filter'])) $task['spider_filter'] = $data['spider_filter'];
            
            $task['updated_at'] = date('Y-m-d H:i:s');
            $found = true;
            break;
        }
    }
    
    if ($found) {
        // 保存到 JSON
        if (!_r301sitewide_saveConfig($config)) {
            return false;
        }
        
        // 更新到 Redis
        require_once __DIR__ . '/redis_config.php';
        foreach ($config['tasks'] as $t) {
            if ($t['id'] === $taskId) {
                saveSitewideTaskToRedis($taskId, $t);
                break;
            }
        }
        
        return true;
    }
    return false;
}

/**
 * 删除任务
 */
function _r301sitewide_delete($taskId) {
    if (empty($taskId)) {
        error_log("Delete failed: empty taskId");
        return false;
    }
    
    $config = _r301sitewide_getConfig();
    $newTasks = [];
    $found = false;
    
    foreach ($config['tasks'] as $task) {
        if ($task['id'] === $taskId) {
            $found = true;
            error_log("Deleting task: {$taskId} ({$task['name']})");
            
            // ★ 清理 Redis 中的所有数据（任务配置 + 固定映射）
            require_once __DIR__ . '/redis_config.php';
            deleteSitewideTaskFromRedis($taskId);
            
            // ★ 从全局域名索引中移除该任务的源域名
            _r301sitewide_removeDomainsFromGlobalIndex($task['source_domains'] ?? []);
            
            continue; // 跳过要删除的任务
        }
        $newTasks[] = $task;
    }
    
    if (!$found) {
        error_log("Delete failed: task not found: {$taskId}");
        return false;
    }
    
    $config['tasks'] = $newTasks;
    
    error_log("Saving config with " . count($newTasks) . " tasks remaining");
    $result = _r301sitewide_saveConfig($config);
    
    if ($result) {
        error_log("Delete successful: {$taskId}");
    } else {
        error_log("Delete failed: save config failed for task {$taskId}");
    }
    
    return $result;
}

/**
 * 切换任务开关
 */
function _r301sitewide_toggle($taskId, $enabled) {
    return _r301sitewide_update($taskId, ['enabled' => $enabled]);
}

/**
 * 切换全局开关
 */
function _r301sitewide_toggleGlobal($enabled) {
    $config = _r301sitewide_getConfig();
    $config['enabled'] = (bool)$enabled;
    return _r301sitewide_saveConfig($config);
}

/**
 * 增加跳转统计
 */
function _r301sitewide_incrementStats($taskId) {
    $config = _r301sitewide_getConfig();
    
    foreach ($config['tasks'] as &$task) {
        if ($task['id'] === $taskId) {
            if (!isset($task['stats'])) {
                $task['stats'] = ['total_redirects' => 0];
            }
            $task['stats']['total_redirects']++;
            
            // 使用临时文件 + 重命名确保原子写入
            $file = _R301SITEWIDE_DATA_FILE_;
            $tempFile = $file . '.tmp.' . getmypid();
            $result = file_put_contents(
                $tempFile,
                json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                LOCK_EX
            );
            
            if ($result !== false) {
                @rename($tempFile, $file);
            } else {
                @unlink($tempFile);
            }
            
            return true;
        }
    }
    
    return false;
}

/**
 * 批量解析域名列表
 */
function _r301sitewide_parseDomains($text) {
    $lines = explode("\n", $text);
    $domains = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $originalLine = $line;
        
        // 判断是否为完整URL（包含协议头或路径）
        $isFullUrl = (
            preg_match('#^https?://#i', $line) || 
            strpos($line, '/') !== false ||
            strpos($line, '{') !== false
        );
        
        if ($isFullUrl) {
            // 完整URL：保留原样，只做基本验证
            // 替换占位符为测试值进行验证
            $testUrl = $line;
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
            
            // 如果没有协议头，添加一个用于验证
            if (!preg_match('#^https?://#i', $testUrl)) {
                $testUrl = 'http://' . $testUrl;
            }
            
            // 验证URL格式
            if (filter_var($testUrl, FILTER_VALIDATE_URL)) {
                $domains[] = $originalLine; // 保存原始URL（可能包含占位符）
            }
        } else {
            // 纯域名：移除协议头、路径、端口
            $line = preg_replace('#^https?://#i', '', $line);
            $line = preg_replace('#/.*$#', '', $line);
            $line = preg_replace('#:\d+$#', '', $line);
            
            // 验证域名格式（允许包含占位符）
            $testDomain = $line;
            
            // 如果包含占位符，先替换为测试值
            $testDomain = preg_replace('/{年}/', '2025', $testDomain);
            $testDomain = preg_replace('/{月}/', '12', $testDomain);
            $testDomain = preg_replace('/{日}/', '22', $testDomain);
            $testDomain = preg_replace('/{数字\d+}/', '12345678', $testDomain);
            $testDomain = preg_replace('/{小写字母\d+}/', 'abcdefgh', $testDomain);
            $testDomain = preg_replace('/{大写字母\d+}/', 'ABCDEFGH', $testDomain);
            $testDomain = preg_replace('/{大小写字母\d+}/', 'AbCdEfGh', $testDomain);
            $testDomain = preg_replace('/{小写随机字符\d+}/', 'a1b2c3d4', $testDomain);
            $testDomain = preg_replace('/{大写随机字符\d+}/', 'A1B2C3D4', $testDomain);
            $testDomain = preg_replace('/{大小写随机字符\d+}/', 'A1b2C3d4', $testDomain);
            $testDomain = preg_replace('/{自定义参数\d+}/', 'test', $testDomain);
            
            // 验证替换后的域名格式
            if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $testDomain)) {
                $domains[] = $line; // 保存原始域名（可能包含占位符）
            }
        }
    }
    
    return array_unique($domains);
}

/**
 * 解析URI替换规则
 */
function _r301sitewide_parseReplacements($text) {
    $lines = explode("\n", $text);
    $replacements = [];
    
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
 * 获取已启用的任务列表
 */
function _r301sitewide_getEnabledTasks() {
    $config = _r301sitewide_getConfig();
    
    if (!$config['enabled']) {
        return [];
    }
    
    $enabled = array_filter($config['tasks'], function($task) {
        return !empty($task['enabled']);
    });
    
    return array_values($enabled);
}

/**
 * 检查当前域名是否匹配源域名列表（包括所有二级域名）
 */
function _r301sitewide_matchSourceDomain($currentHost, $sourceDomains) {
    $currentHost = strtolower($currentHost);
    
    foreach ($sourceDomains as $sourceDomain) {
        $sourceDomain = strtolower($sourceDomain);
        
        // 精确匹配
        if ($currentHost === $sourceDomain) {
            return true;
        }
        
        // 匹配所有二级域名: *.example.com
        // 如果当前域名以 .sourceDomain 结尾
        if (substr($currentHost, -(strlen($sourceDomain) + 1)) === '.' . $sourceDomain) {
            return true;
        }
    }
    
    return false;
}

/**
 * 检查URI是否匹配过滤规则
 */
function _r301sitewide_matchUriFilter($uri, $filterConfig) {
    if (!$filterConfig['enabled'] || empty($filterConfig['rules'])) {
        return true; // 未启用过滤，默认匹配
    }
    
    $matched = false;
    
    foreach ($filterConfig['rules'] as $rule) {
        $type = $rule['type'] ?? 'exact';
        $value = $rule['value'] ?? '';
        
        if (empty($value)) continue;
        
        switch ($type) {
            case 'exact':
                // 精确匹配
                if ($uri === $value) {
                    $matched = true;
                }
                break;
                
            case 'prefix':
                // 前缀匹配
                if (strpos($uri, $value) === 0) {
                    $matched = true;
                }
                break;
                
            case 'regex':
                // 正则匹配（用户自己提供完整正则表达式）
                if (@preg_match($value, $uri)) {
                    $matched = true;
                }
                break;
        }
        
        if ($matched) break;
    }
    
    // 白名单模式：匹配才跳转
    // 黑名单模式：不匹配才跳转
    $mode = $filterConfig['mode'] ?? 'blacklist';
    if ($mode === 'whitelist') {
        return $matched;
    } else {
        return !$matched;
    }
}

/**
 * 获取任务摘要信息
 */
function _r301sitewide_getSummary($task) {
    $parts = [];
    
    // 源域名
    $sourceCount = count($task['source_domains'] ?? []);
    if ($sourceCount > 0) {
        $first = $task['source_domains'][0];
        if ($sourceCount > 1) {
            $parts[] = "{$first} (+". ($sourceCount - 1) .")";
        } else {
            $parts[] = $first;
        }
    }
    
    // 目标域名
    $targetCount = count($task['target_domains'] ?? []);
    if ($targetCount > 0) {
        $first = $task['target_domains'][0];
        if ($targetCount > 1) {
            $parts[] = "→ {$first} (+". ($targetCount - 1) .")";
        } else {
            $parts[] = "→ {$first}";
        }
    }
    
    // 重定向类型
    $parts[] = $task['redirect_type'] ?? '301';
    
    return implode(' | ', $parts);
}

/**
 * 从全局域名索引中移除域名
 */
function _r301sitewide_removeDomainsFromGlobalIndex($domains) {
    if (empty($domains)) {
        return;
    }
    
    try {
        require_once __DIR__ . '/domain_index.php';
        $redis = _domainIndex_getRedis();
        if (!$redis) {
            return;
        }
        
        $prefix = defined('_REDIRECT301_REDIS_PREFIX_') ? _REDIRECT301_REDIS_PREFIX_ : 'redirect301:';
        $domainsKey = "{$prefix}domains";
        
        foreach ($domains as $domain) {
            // 清理域名（移除占位符）
            $cleanDomain = preg_replace('/{[^}]+}\.?/', '', $domain);
            $cleanDomain = strtolower(trim($cleanDomain));
            if (!empty($cleanDomain)) {
                $redis->sRem($domainsKey, $cleanDomain);
            }
        }
    } catch (Exception $e) {
        error_log("从全局域名索引移除域名失败: " . $e->getMessage());
    }
}

