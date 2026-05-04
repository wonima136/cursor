<?php
/**
 * 站群链轮系统 - 核心函数
 * 函数前缀: _r301group_ 避免与其他程序冲突
 */

// 数据文件 - 使用绝对路径避免常量冲突
if (!defined('_R301GROUP_DATA_FILE_')) {
    define('_R301GROUP_DATA_FILE_', __DIR__ . '/data/groups.json');
}

/**
 * 生成唯一分组ID
 */
function _r301group_generateId() {
    return 'grp_' . date('Ymd') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
}

/**
 * 获取所有分组
 */
function _r301group_getAll() {
    if (!file_exists(_R301GROUP_DATA_FILE_)) {
        return [];
    }
    $data = json_decode(file_get_contents(_R301GROUP_DATA_FILE_), true);
    return is_array($data) ? $data : [];
}

/**
 * 保存所有分组（使用文件锁确保原子性）
 */
function _r301group_saveAll($groups) {
    $file = _R301GROUP_DATA_FILE_;
    $dir = dirname($file);
    
    // 确保目录存在
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    
    // 使用临时文件 + 重命名确保原子写入
    $tempFile = $file . '.tmp.' . getmypid();
    $result = file_put_contents(
        $tempFile,
        json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
    
    if ($result !== false) {
        // 原子重命名
        return rename($tempFile, $file);
    }
    
    // 失败时清理临时文件
    @unlink($tempFile);
    return false;
}

/**
 * 获取单个分组
 */
function _r301group_getById($groupId) {
    $groups = _r301group_getAll();
    foreach ($groups as $group) {
        if ($group['id'] === $groupId) {
            return $group;
        }
    }
    return null;
}

/**
 * 创建新分组
 */
function _r301group_create($data) {
    $groups = _r301group_getAll();
    
    $groupId = _r301group_generateId();
    
    $newGroup = [
        'id' => $groupId,
        'name' => trim($data['name'] ?? '未命名分组'),
        'enabled' => false,  // 默认关闭
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        
        // 跳转设置（从传入数据中读取，否则使用默认值）
        'redirect_type' => $data['redirect_type'] ?? '302',
        'probability' => $data['probability'] ?? 20,
        'follow_subdomain' => isset($data['follow_subdomain']) ? (bool)$data['follow_subdomain'] : false,
        'follow_uri' => isset($data['follow_uri']) ? (bool)$data['follow_uri'] : false,
        'chain_mode' => $data['chain_mode'] ?? 'sequential', // 链轮模式：sequential=顺序/跑火车, random=随机
        
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
        
        // 兼容旧版settings结构
        'settings' => [
            'redirect_type' => $data['redirect_type'] ?? '302',
            'probability' => $data['probability'] ?? 20,
            'follow_subdomain' => isset($data['follow_subdomain']) ? (bool)$data['follow_subdomain'] : false,
            'follow_uri' => isset($data['follow_uri']) ? (bool)$data['follow_uri'] : false,
            'chain_mode' => $data['chain_mode'] ?? 'sequential',
            'fixed_target' => '',
            'weight_keywords' => [],
            'weight_mode' => '',
        ],
        
        // 域名列表
        'domains' => [],
        
        // 统计
        'stats' => [
            'total_domains' => 0,
            'enabled_domains' => 0,
            'total_redirects' => 0,
        ],
    ];
    
    $groups[] = $newGroup;
    _r301group_saveAll($groups);
    
    // 同步到Redis
    require_once __DIR__ . '/redis_config.php';
    saveGroupToRedis($groupId, $newGroup);
    
    return $groupId;
}

/**
 * 更新分组
 */
function _r301group_update($groupId, $data) {
    $groups = _r301group_getAll();
    $updatedGroup = null;
    
    foreach ($groups as &$group) {
        if ($group['id'] === $groupId) {
            // 更新基本信息
            if (isset($data['name'])) {
                $group['name'] = trim($data['name']);
            }
            if (isset($data['enabled'])) {
                $group['enabled'] = (bool)$data['enabled'];
            }
            
            // ★ 更新根级别的跳转设置字段（确保程序能正确读取）
            if (isset($data['redirect_type'])) {
                $group['redirect_type'] = $data['redirect_type'];
            }
            if (isset($data['probability'])) {
                $group['probability'] = $data['probability'];
            }
            if (array_key_exists('follow_subdomain', $data)) {
                $group['follow_subdomain'] = $data['follow_subdomain'];
            }
            if (array_key_exists('follow_uri', $data)) {
                $group['follow_uri'] = $data['follow_uri'];
            }
            if (array_key_exists('fixed_target', $data)) {
                $group['fixed_target'] = trim($data['fixed_target']);
            }
            if (isset($data['chain_mode'])) {
                $group['chain_mode'] = $data['chain_mode'];
            }
            if (isset($data['spider_filter'])) {
                $group['spider_filter'] = $data['spider_filter'];
            }
            
            // 更新跳转设置（settings）
            if (isset($data['settings'])) {
                $group['settings'] = array_merge($group['settings'], $data['settings']);
            }
            
            // 更新域名列表
            if (isset($data['domains'])) {
                $group['domains'] = $data['domains'];
            }
            
            $group['updated_at'] = date('Y-m-d H:i:s');
            
            // 更新统计
            _r301group_updateStatsInternal($group);
            $updatedGroup = $group;
            break;
        }
    }
    
    $result = _r301group_saveAll($groups);
    
    // 同步到Redis
    if ($result && $updatedGroup) {
        require_once __DIR__ . '/redis_config.php';
        saveGroupToRedis($groupId, $updatedGroup);
    }
    
    return $result;
}

/**
 * 内部更新统计（不保存）
 */
function _r301group_updateStatsInternal(&$group) {
    $total = count($group['domains']);
    $enabled = 0;
    foreach ($group['domains'] as $d) {
        if (!empty($d['enabled'])) {
            $enabled++;
        }
    }
    $group['stats']['total_domains'] = $total;
    $group['stats']['enabled_domains'] = $enabled;
}

/**
 * 删除分组
 */
function _r301group_delete($groupId) {
    $groups = _r301group_getAll();
    $groups = array_filter($groups, function($g) use ($groupId) {
        return $g['id'] !== $groupId;
    });
    $groups = array_values($groups);
    
    $result = _r301group_saveAll($groups);
    
    // 同步删除Redis数据
    if ($result) {
        require_once __DIR__ . '/redis_config.php';
        deleteGroupFromRedis($groupId);
    }
    
    return $result;
}

/**
 * 切换分组开关
 */
function _r301group_toggle($groupId, $enabled) {
    return _r301group_update($groupId, ['enabled' => $enabled]);
}

/**
 * 添加域名到分组
 */
function _r301group_addDomain($groupId, $domain, $weight = 1) {
    $groups = _r301group_getAll();
    $updatedGroup = null;
    
    foreach ($groups as &$group) {
        if ($group['id'] === $groupId) {
            // 检查域名是否已存在（使用小写比较）
            $domainLower = strtolower($domain);
            foreach ($group['domains'] as $d) {
                if (strtolower($d['domain']) === $domainLower) {
                    return false; // 已存在
                }
            }
            
            $group['domains'][] = [
                'domain' => trim($domain), // 保留原始大小写，支持占位符
                'enabled' => true,
                'weight' => max(1, intval($weight)),
                'fixed_target' => '',
                'added_at' => date('Y-m-d H:i:s'),
            ];
            
            _r301group_updateStatsInternal($group);
            $group['updated_at'] = date('Y-m-d H:i:s');
            $updatedGroup = $group;
            break;
        }
    }
    
    $result = _r301group_saveAll($groups);
    
    // 同步到Redis
    if ($result && $updatedGroup) {
        require_once __DIR__ . '/redis_config.php';
        saveGroupToRedis($groupId, $updatedGroup);
    }
    
    return $result;
}

/**
 * 批量添加域名
 */
function _r301group_addDomains($groupId, $domainsText, $defaultWeight = 1) {
    $lines = explode("\n", $domainsText);
    $added = 0;
    $skipped = 0;
    
    $groups = _r301group_getAll();
    $groupIndex = -1;
    
    foreach ($groups as $i => $g) {
        if ($g['id'] === $groupId) {
            $groupIndex = $i;
            break;
        }
    }
    
    if ($groupIndex === -1) {
        return ['added' => 0, 'skipped' => 0];
    }
    
    // 建立已存在域名的索引
    $existingDomains = [];
    foreach ($groups[$groupIndex]['domains'] as $d) {
        $existingDomains[strtolower($d['domain'])] = true;
    }
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        
        // 支持格式: domain 或 domain,weight
        $parts = preg_split('/[\s,]+/', $line);
        $domain = trim($parts[0]); // 保留原始大小写，因为可能包含占位符
        $weight = isset($parts[1]) ? max(1, intval($parts[1])) : $defaultWeight;
        
        // 验证域名格式（允许包含占位符）
        $testDomain = $domain;
        
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
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $testDomain)) {
            $skipped++;
            continue;
        }
        
        // 用于检查重复时，使用小写
        $domainLower = strtolower($domain);
        
        // 检查是否已存在（使用小写比较）
        if (isset($existingDomains[$domainLower])) {
            $skipped++;
            continue;
        }
        
        $groups[$groupIndex]['domains'][] = [
            'domain' => $domain, // 保存原始域名（可能包含占位符）
            'enabled' => true,
            'weight' => $weight,
            'fixed_target' => '',
            // 不设置 redirect_type, follow_subdomain, follow_uri
            // 让它们使用分组的全局设置
            'added_at' => date('Y-m-d H:i:s'),
        ];
        
        $existingDomains[$domainLower] = true;
        $added++;
    }
    
    _r301group_updateStatsInternal($groups[$groupIndex]);
    $groups[$groupIndex]['updated_at'] = date('Y-m-d H:i:s');
    _r301group_saveAll($groups);
    
    return ['added' => $added, 'skipped' => $skipped];
}

/**
 * 更新域名设置
 */
function _r301group_updateDomain($groupId, $domain, $data) {
    $groups = _r301group_getAll();
    $updatedGroup = null;
    
    foreach ($groups as &$group) {
        if ($group['id'] === $groupId) {
            foreach ($group['domains'] as &$d) {
                if (strtolower($d['domain']) === strtolower($domain)) {
                    if (isset($data['enabled'])) {
                        $d['enabled'] = (bool)$data['enabled'];
                    }
                    if (isset($data['weight'])) {
                        $d['weight'] = max(1, intval($data['weight']));
                    }
                    if (isset($data['fixed_target'])) {
                        $d['fixed_target'] = trim($data['fixed_target']);
                    }
                    if (array_key_exists('follow_subdomain', $data)) {
                        $d['follow_subdomain'] = (bool)$data['follow_subdomain'];
                    }
                    if (array_key_exists('follow_uri', $data)) {
                        $d['follow_uri'] = (bool)$data['follow_uri'];
                    }
                    if (isset($data['redirect_type'])) {
                        $d['redirect_type'] = in_array($data['redirect_type'], ['301', '302']) ? $data['redirect_type'] : '302';
                    }
                    break;
                }
            }
            _r301group_updateStatsInternal($group);
            $group['updated_at'] = date('Y-m-d H:i:s');
            $updatedGroup = $group;
            break;
        }
    }
    
    $result = _r301group_saveAll($groups);
    
    // 同步到Redis
    if ($result && $updatedGroup) {
        require_once __DIR__ . '/redis_config.php';
        saveGroupToRedis($groupId, $updatedGroup);
    }
    
    return $result;
}

/**
 * 删除域名
 */
function _r301group_deleteDomain($groupId, $domain) {
    $groups = _r301group_getAll();
    
    foreach ($groups as &$group) {
        if ($group['id'] === $groupId) {
            $group['domains'] = array_filter($group['domains'], function($d) use ($domain) {
                return strtolower($d['domain']) !== strtolower($domain);
            });
            $group['domains'] = array_values($group['domains']);
            _r301group_updateStatsInternal($group);
            $group['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    
    return _r301group_saveAll($groups);
}

/**
 * 清空分组域名
 */
function _r301group_clearDomains($groupId) {
    $groups = _r301group_getAll();
    
    foreach ($groups as &$group) {
        if ($group['id'] === $groupId) {
            $group['domains'] = [];
            _r301group_updateStatsInternal($group);
            $group['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    
    return _r301group_saveAll($groups);
}

/**
 * 根据域名查找所属分组
 * @param string $domain 完整域名或顶级域名
 * @return array|null 返回分组信息和域名配置
 */
function _r301group_findByDomain($domain) {
    $groups = _r301group_getAll();
    $domain = strtolower($domain);
    
    foreach ($groups as $group) {
        if (empty($group['enabled'])) {
            continue;
        }
        
        foreach ($group['domains'] as $d) {
            if (strtolower($d['domain']) === $domain) {
                return [
                    'group' => $group,
                    'domain_config' => $d,
                ];
            }
        }
    }
    
    return null;
}

/**
 * 获取所有已启用的分组
 */
function _r301group_getEnabled() {
    $groups = _r301group_getAll();
    return array_filter($groups, function($g) {
        return !empty($g['enabled']);
    });
}

/**
 * 增加分组跳转统计
 */
function _r301group_incrementStats($groupId) {
    $groups = _r301group_getAll();
    
    foreach ($groups as &$group) {
        if ($group['id'] === $groupId) {
            $group['stats']['total_redirects'] = ($group['stats']['total_redirects'] ?? 0) + 1;
            break;
        }
    }
    
    _r301group_saveAll($groups);
}

/**
 * 解析权重关键词文本为数组
 */
function _r301group_parseWeightKeywords($text) {
    if (empty($text)) {
        return [];
    }
    
    $lines = explode("\n", $text);
    $keywords = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (!empty($line) && strpos($line, '#') !== 0) {
            $keywords[] = $line;
        }
    }
    
    return $keywords;
}

/**
 * 获取分组摘要信息（用于列表显示）
 */
function _r301group_getSummary($group) {
    $settings = $group['settings'] ?? [];
    
    $parts = [];
    $parts[] = ($settings['redirect_type'] ?? '301') . '跳转';
    $parts[] = ($settings['probability'] ?? 30) . '%概率';
    
    if (!empty($settings['follow_subdomain'])) {
        $parts[] = '跟随二级域名';
    }
    if (!empty($settings['follow_uri'])) {
        $parts[] = '跟随URI';
    }
    if (!empty($settings['fixed_target'])) {
        $parts[] = '固定目标:' . $settings['fixed_target'];
    }
    
    // 显示权重模式
    if (!empty($settings['weight_mode']) && !empty($settings['weight_keywords'])) {
        $mode = $settings['weight_mode'] === 'subdomain' ? '组合二级' : '组合内页';
        $count = count($settings['weight_keywords']);
        $parts[] = "{$mode}({$count}个关键词)";
    }
    
    return implode(' · ', $parts);
}

