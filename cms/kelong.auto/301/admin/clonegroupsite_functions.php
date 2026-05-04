<?php
/**
 * 克隆站分组管理 - 核心函数库
 */

// ==================== 数据库管理 ====================

/**
 * 获取数据库连接
 */
function _clonegroupsite_getDB() {
    $dbPath = __DIR__ . '/data/clonegroupsite.db';
    
    // 确保目录存在
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    // 检查数据库文件是否是新创建的
    $isNewDb = !file_exists($dbPath);
    
    $db = new SQLite3($dbPath);
    $db->busyTimeout(5000);
    
    // 如果是新创建的数据库，设置权限
    if ($isNewDb) {
        chmod($dbPath, 0775);
        chown($dbPath, 'www');
        chgrp($dbPath, 'www');
    }
    
    // 初始化表结构
    _clonegroupsite_initTables($db);
    
    return $db;
}

/**
 * 初始化数据库表
 */
function _clonegroupsite_initTables($db) {
    // 分组表
    $db->exec("
        CREATE TABLE IF NOT EXISTS clonegroupsite_groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_name TEXT NOT NULL UNIQUE,
            group_title TEXT NOT NULL,
            domain_count INTEGER DEFAULT 0,
            redirect_mode TEXT DEFAULT 'random_three',
            redirect_target TEXT,
            include_three_terminal INTEGER DEFAULT 0,
            centralize_targets TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // 检查并添加 centralize_targets 字段（兼容旧数据库）
    $result = $db->query("PRAGMA table_info(clonegroupsite_groups)");
    $hasField = false;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] === 'centralize_targets') {
            $hasField = true;
            break;
        }
    }
    if (!$hasField) {
        $db->exec("ALTER TABLE clonegroupsite_groups ADD COLUMN centralize_targets TEXT");
    }
    
    // 域名表
    $db->exec("
        CREATE TABLE IF NOT EXISTS clonegroupsite_domains (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id INTEGER NOT NULL,
            group_name TEXT NOT NULL,
            group_title TEXT NOT NULL,
            domain TEXT NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (group_id) REFERENCES clonegroupsite_groups(id)
        )
    ");
    
    // 创建索引
    $db->exec("CREATE INDEX IF NOT EXISTS idx_domain ON clonegroupsite_domains(domain)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_group_name ON clonegroupsite_domains(group_name)");
}

// ==================== 数据清洗功能 ====================

/**
 * 清洗分组数据（完整同步：增删改查）
 */
function _clonegroupsite_cleanData() {
    // 确定JSON文件路径
    // 当前文件: /www/wwwroot/ke.ke/301/admin/clonegroupsite_functions.php
    // 目标文件: /www/wwwroot/ke.ke/data/domain_groups/groups.json
    $jsonPath = dirname(dirname(__DIR__)) . '/data/domain_groups/groups.json';
    
    if (!file_exists($jsonPath)) {
        return ['success' => false, 'message' => '数据文件不存在: ' . $jsonPath];
    }
    
    $jsonContent = file_get_contents($jsonPath);
    $groupsData = json_decode($jsonContent, true);
    
    if (!$groupsData || !is_array($groupsData)) {
        return ['success' => false, 'message' => 'JSON格式错误'];
    }
    
    $db = _clonegroupsite_getDB();
    $db->exec('BEGIN TRANSACTION');
    
    try {
        $stats = [
            'total_groups' => count($groupsData),
            'new_groups' => 0,
            'updated_groups' => 0,
            'deleted_groups' => 0,
            'new_domains' => 0,
            'updated_domains' => 0,
            'deleted_domains' => 0
        ];
        
        // 步骤1: 收集JSON中的所有分组名和域名
        $jsonGroupNames = [];
        $jsonDomainsByGroup = []; // group_name => [domain1, domain2, ...]
        
        foreach ($groupsData as $group) {
            // 验证必需字段
            if (!isset($group['name']) || !isset($group['value']) || !isset($group['domains'])) {
                continue;
            }
            
            $groupName = $group['name'];
            $groupTitle = $group['value'];
            $jsonGroupNames[] = $groupName;
            $jsonDomainsByGroup[$groupName] = [];
            
            foreach ($group['domains'] as $domainObj) {
                if (isset($domainObj['domain'])) {
                    $baseDomain = _clonegroupsite_extractBaseDomain($domainObj['domain']);
                    $jsonDomainsByGroup[$groupName][] = $baseDomain;
                }
            }
        }
        
        // 步骤2: 删除数据库中存在但JSON中不存在的分组
        $result = $db->query("SELECT id, group_name FROM clonegroupsite_groups");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (!in_array($row['group_name'], $jsonGroupNames)) {
                // 删除该分组及其所有域名
                $stmt = $db->prepare("DELETE FROM clonegroupsite_domains WHERE group_id = ?");
                $stmt->bindValue(1, $row['id'], SQLITE3_INTEGER);
                $stmt->execute();
                
                $stmt = $db->prepare("DELETE FROM clonegroupsite_groups WHERE id = ?");
                $stmt->bindValue(1, $row['id'], SQLITE3_INTEGER);
                $stmt->execute();
                
                $stats['deleted_groups']++;
            }
        }
        
        // 步骤3: 处理JSON中的每个分组（新增或更新）
        foreach ($groupsData as $group) {
            if (!isset($group['name']) || !isset($group['value']) || !isset($group['domains'])) {
                continue;
            }
            
            $groupName = $group['name'];
            $groupTitle = $group['value'];
            $domains = $group['domains'];
            
            // 插入或更新分组
            $groupResult = _clonegroupsite_insertOrUpdateGroup($db, $groupName, $groupTitle);
            if ($groupResult['is_new']) {
                $stats['new_groups']++;
            } else {
                $stats['updated_groups']++;
            }
            $groupId = $groupResult['id'];
            
            // 收集该分组在JSON中的所有域名
            $jsonDomains = $jsonDomainsByGroup[$groupName];
            
            // 步骤4: 删除数据库中存在但JSON中不存在的域名（针对当前分组）
            $result = $db->query("SELECT id, domain FROM clonegroupsite_domains WHERE group_id = {$groupId}");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if (!in_array($row['domain'], $jsonDomains)) {
                    $stmt = $db->prepare("DELETE FROM clonegroupsite_domains WHERE id = ?");
                    $stmt->bindValue(1, $row['id'], SQLITE3_INTEGER);
                    $stmt->execute();
                    $stats['deleted_domains']++;
                }
            }
            
            // 步骤5: 处理JSON中的每个域名（新增或更新）
            foreach ($domains as $domainObj) {
                if (!isset($domainObj['domain'])) {
                    continue;
                }
                
                $domain = $domainObj['domain'];
                $baseDomain = _clonegroupsite_extractBaseDomain($domain);
                
                // 检查域名是否已存在
                $stmt = $db->prepare("SELECT id, group_id FROM clonegroupsite_domains WHERE domain = ?");
                $stmt->bindValue(1, $baseDomain, SQLITE3_TEXT);
                $result = $stmt->execute();
                $existingDomain = $result->fetchArray(SQLITE3_ASSOC);
                
                if ($existingDomain) {
                    // 域名已存在，检查是否需要更新分组
                    if ($existingDomain['group_id'] != $groupId) {
                        // 域名从其他分组移动到当前分组
                        $stmt = $db->prepare("
                            UPDATE clonegroupsite_domains 
                            SET group_id = ?,
                                group_name = ?,
                                group_title = ?,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        $stmt->bindValue(1, $groupId, SQLITE3_INTEGER);
                        $stmt->bindValue(2, $groupName, SQLITE3_TEXT);
                        $stmt->bindValue(3, $groupTitle, SQLITE3_TEXT);
                        $stmt->bindValue(4, $existingDomain['id'], SQLITE3_INTEGER);
                        $stmt->execute();
                        $stats['updated_domains']++;
                    }
                    // 如果group_id相同，则无需更新
                } else {
                    // 域名不存在，新增
                    _clonegroupsite_insertDomain($db, $groupId, $groupName, $groupTitle, $baseDomain);
                    $stats['new_domains']++;
                }
            }
            
            // 更新分组的域名数量
            _clonegroupsite_updateGroupDomainCount($db, $groupId);
        }
        
        $db->exec('COMMIT');
        
        $stats['success'] = true;
        $message = "清洗完成！";
        $details = [];
        if ($stats['new_groups'] > 0) $details[] = "新增 {$stats['new_groups']} 个分组";
        if ($stats['updated_groups'] > 0) $details[] = "更新 {$stats['updated_groups']} 个分组";
        if ($stats['deleted_groups'] > 0) $details[] = "删除 {$stats['deleted_groups']} 个分组";
        if ($stats['new_domains'] > 0) $details[] = "新增 {$stats['new_domains']} 个域名";
        if ($stats['updated_domains'] > 0) $details[] = "更新 {$stats['updated_domains']} 个域名";
        if ($stats['deleted_domains'] > 0) $details[] = "删除 {$stats['deleted_domains']} 个域名";
        
        $stats['message'] = $message . (count($details) > 0 ? implode('，', $details) : '无变更');
        
        return $stats;
        
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        return ['success' => false, 'message' => '清洗失败: ' . $e->getMessage()];
    }
}

/**
 * 提取基础域名
 */
function _clonegroupsite_extractBaseDomain($domain) {
    // 去除协议
    $domain = preg_replace('#^https?://#', '', $domain);
    // 去除路径
    $domain = preg_replace('#/.*$#', '', $domain);
    // 去除端口
    $domain = preg_replace('#:\d+$#', '', $domain);
    // 去除www和m前缀
    $domain = preg_replace('#^(www\.|m\.)#', '', $domain);
    
    return strtolower(trim($domain));
}

/**
 * 插入或更新分组
 */
function _clonegroupsite_insertOrUpdateGroup($db, $groupName, $groupTitle) {
    // 检查是否已存在
    $stmt = $db->prepare("SELECT id FROM clonegroupsite_groups WHERE group_name = ?");
    $stmt->bindValue(1, $groupName, SQLITE3_TEXT);
    $result = $stmt->execute();
    $existing = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($existing) {
        // 更新分组标题
        $stmt = $db->prepare("
            UPDATE clonegroupsite_groups 
            SET group_title = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->bindValue(1, $groupTitle, SQLITE3_TEXT);
        $stmt->bindValue(2, $existing['id'], SQLITE3_INTEGER);
        $stmt->execute();
        
        return ['id' => $existing['id'], 'is_new' => false];
    } else {
        // 插入新分组
        $stmt = $db->prepare("
            INSERT INTO clonegroupsite_groups (group_name, group_title)
            VALUES (?, ?)
        ");
        $stmt->bindValue(1, $groupName, SQLITE3_TEXT);
        $stmt->bindValue(2, $groupTitle, SQLITE3_TEXT);
        $stmt->execute();
        
        return ['id' => $db->lastInsertRowID(), 'is_new' => true];
    }
}

/**
 * 检查域名是否存在
 */
function _clonegroupsite_domainExists($db, $domain) {
    $stmt = $db->prepare("SELECT id FROM clonegroupsite_domains WHERE domain = ?");
    $stmt->bindValue(1, $domain, SQLITE3_TEXT);
    $result = $stmt->execute();
    return $result->fetchArray(SQLITE3_ASSOC) !== false;
}

/**
 * 插入域名
 */
function _clonegroupsite_insertDomain($db, $groupId, $groupName, $groupTitle, $domain) {
    $stmt = $db->prepare("
        INSERT INTO clonegroupsite_domains (group_id, group_name, group_title, domain)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bindValue(1, $groupId, SQLITE3_INTEGER);
    $stmt->bindValue(2, $groupName, SQLITE3_TEXT);
    $stmt->bindValue(3, $groupTitle, SQLITE3_TEXT);
    $stmt->bindValue(4, $domain, SQLITE3_TEXT);
    $stmt->execute();
}

/**
 * 更新分组的域名数量
 */
function _clonegroupsite_updateGroupDomainCount($db, $groupId) {
    $stmt = $db->prepare("
        UPDATE clonegroupsite_groups 
        SET domain_count = (
            SELECT COUNT(*) FROM clonegroupsite_domains WHERE group_id = ?
        )
        WHERE id = ?
    ");
    $stmt->bindValue(1, $groupId, SQLITE3_INTEGER);
    $stmt->bindValue(2, $groupId, SQLITE3_INTEGER);
    $stmt->execute();
}

// ==================== 查询功能 ====================

/**
 * 获取所有分组
 */
function _clonegroupsite_getAllGroups() {
    $db = _clonegroupsite_getDB();
    
    $result = $db->query("
        SELECT * FROM clonegroupsite_groups 
        ORDER BY group_name ASC
    ");
    
    $groups = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $groups[] = $row;
    }
    
    return $groups;
}

/**
 * 获取分组详情
 */
function _clonegroupsite_getGroupByName($groupName) {
    $db = _clonegroupsite_getDB();
    
    $stmt = $db->prepare("SELECT * FROM clonegroupsite_groups WHERE group_name = ?");
    $stmt->bindValue(1, $groupName, SQLITE3_TEXT);
    $result = $stmt->execute();
    
    return $result->fetchArray(SQLITE3_ASSOC);
}

/**
 * 获取分组的所有域名
 */
function _clonegroupsite_getGroupDomains($groupName) {
    $db = _clonegroupsite_getDB();
    
    $stmt = $db->prepare("
        SELECT * FROM clonegroupsite_domains 
        WHERE group_name = ?
        ORDER BY domain ASC
    ");
    $stmt->bindValue(1, $groupName, SQLITE3_TEXT);
    $result = $stmt->execute();
    
    $domains = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $domains[] = $row;
    }
    
    return $domains;
}

/**
 * 获取统计信息
 */
function _clonegroupsite_getStats() {
    $db = _clonegroupsite_getDB();
    
    $result = $db->querySingle("SELECT COUNT(*) FROM clonegroupsite_groups");
    $totalGroups = $result ?: 0;
    
    $result = $db->querySingle("SELECT COUNT(*) FROM clonegroupsite_domains");
    $totalDomains = $result ?: 0;
    
    return [
        'total_groups' => $totalGroups,
        'total_domains' => $totalDomains
    ];
}

// ==================== 配置管理 ====================

/**
 * 更新分组的重定向配置
 */
function _clonegroupsite_updateGroupConfig($groupName, $redirectMode, $redirectTarget = '', $includeThreeTerminal = 0) {
    $db = _clonegroupsite_getDB();
    
    $stmt = $db->prepare("
        UPDATE clonegroupsite_groups 
        SET redirect_mode = ?,
            redirect_target = ?,
            include_three_terminal = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE group_name = ?
    ");
    $stmt->bindValue(1, $redirectMode, SQLITE3_TEXT);
    $stmt->bindValue(2, $redirectTarget, SQLITE3_TEXT);
    $stmt->bindValue(3, $includeThreeTerminal, SQLITE3_INTEGER);
    $stmt->bindValue(4, $groupName, SQLITE3_TEXT);
    
    return $stmt->execute();
}

// ==================== 工具函数 ====================

/**
 * 截断标题
 */
function _clonegroupsite_truncateTitle($title, $length = 20) {
    if (mb_strlen($title) > $length) {
        return mb_substr($title, 0, $length) . '...';
    }
    return $title;
}

/**
 * 获取重定向模式的显示名称
 */
function _clonegroupsite_getRedirectModeLabel($mode) {
    $labels = [
        'random_three' => '随机',
        'fixed_www' => 'www',
        'fixed_m' => 'm',
        'fixed_top' => '顶级',
        'custom_subdomain' => '自定义',
        'centralize' => '集权'
    ];
    
    return isset($labels[$mode]) ? $labels[$mode] : '未设置';
}

// ==================== 集权模式相关函数 ====================

/**
 * 加载 domain_suffixes.php 文件
 */
function _clonegroupsite_loadDomainSuffixes() {
    static $suffixes = null;
    
    if ($suffixes === null) {
        $suffixFile = __DIR__ . '/domain_suffixes.php';
        if (file_exists($suffixFile)) {
            include $suffixFile;
            $suffixes = isset($DOMAIN_SUFFIXES) ? $DOMAIN_SUFFIXES : array();
        } else {
            // 默认后缀列表
            $suffixes = ['com', 'cn', 'net', 'org', 'com.cn', 'co.uk'];
        }
    }
    
    return $suffixes;
}

/**
 * 解析集权对象格式
 * @return array ['type' => 'random'|'www'|'m'|'top', 'domain' => '处理后的域名']
 */
function _clonegroupsite_parseCentralizeTarget($target) {
    // 移除协议和路径
    $target = preg_replace('#^https?://#', '', $target);
    $target = preg_replace('#/.*$#', '', $target);
    $target = trim($target);
    
    // 1. 检查是否是 @.domain.com 格式（固定顶级）
    if (preg_match('/^@\.(.+)$/', $target, $matches)) {
        return [
            'type' => 'top',
            'domain' => $matches[1]
        ];
    }
    
    // 2. 检查是否是 www.domain.com 格式
    if (preg_match('/^www\.(.+)$/', $target)) {
        return [
            'type' => 'www',
            'domain' => $target
        ];
    }
    
    // 3. 检查是否是 m.domain.com 格式
    if (preg_match('/^m\.(.+)$/', $target)) {
        return [
            'type' => 'm',
            'domain' => $target
        ];
    }
    
    // 4. 否则是顶级域名格式（随机三端）
    return [
        'type' => 'random',
        'domain' => $target
    ];
}

/**
 * 提取集权对象的基础顶级域名（用于重复检测）
 */
function _clonegroupsite_extractCentralizeBaseDomain($target) {
    // 移除协议和路径
    $target = preg_replace('#^https?://#', '', $target);
    $target = preg_replace('#/.*$#', '', $target);
    // 移除 @ 前缀
    $target = ltrim($target, '@.');
    // 移除 www. 或 m. 前缀
    $target = preg_replace('/^(www|m)\./', '', $target);
    
    return _clonegroupsite_extractBaseDomain($target);
}

/**
 * 验证集权对象列表（检查顶级域名是否重复）
 */
function _clonegroupsite_validateCentralizeTargets($targets) {
    $baseDomains = [];
    $errors = [];
    
    foreach ($targets as $target) {
        $target = trim($target);
        if (empty($target)) {
            continue;
        }
        
        $baseDomain = _clonegroupsite_extractCentralizeBaseDomain($target);
        
        if (in_array($baseDomain, $baseDomains)) {
            $errors[] = "域名 {$baseDomain} 重复，请只保留一个（如 111.com 和 www.111.com 不能同时存在）";
        } else {
            $baseDomains[] = $baseDomain;
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * 更新分组的集权配置
 */
function _clonegroupsite_updateCentralizeConfig($groupName, $enabled, $domains) {
    $db = _clonegroupsite_getDB();
    
    $config = [
        'enabled' => $enabled,
        'domains' => $domains
    ];
    
    $stmt = $db->prepare("
        UPDATE clonegroupsite_groups
        SET centralize_targets = ?,
            redirect_mode = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE group_name = ?
    ");
    
    $stmt->bindValue(1, json_encode($config), SQLITE3_TEXT);
    $stmt->bindValue(2, $enabled ? 'centralize' : 'random_three', SQLITE3_TEXT);
    $stmt->bindValue(3, $groupName, SQLITE3_TEXT);
    
    return $stmt->execute();
}

// ==================== CSV导入导出功能 ====================

/**
 * 导出分组配置为CSV
 */
function _clonegroupsite_exportCSV() {
    $db = _clonegroupsite_getDB();
    
    // 查询所有分组
    $result = $db->query("
        SELECT 
            group_name,
            group_title,
            domain_count,
            redirect_mode,
            redirect_target,
            include_three_terminal,
            centralize_targets
        FROM clonegroupsite_groups
        ORDER BY group_name ASC
    ");
    
    // 准备CSV数据
    $csvData = [];
    
    // CSV表头
    $csvData[] = [
        '分组名称',
        '分组标题',
        '域名数量',
        '重定向模式',
        '重定向目标',
        '包含三端',
        '集权对象'
    ];
    
    // 数据行
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        // 处理重定向模式
        $modeLabel = _clonegroupsite_getRedirectModeFullLabel($row['redirect_mode']);
        
        // 处理集权对象
        $centralizeTargets = '';
        if ($row['redirect_mode'] === 'centralize' && !empty($row['centralize_targets'])) {
            $config = json_decode($row['centralize_targets'], true);
            if (isset($config['domains']) && is_array($config['domains'])) {
                $centralizeTargets = implode(',', $config['domains']);
            }
        }
        
        // 处理包含三端
        $includeThree = $row['include_three_terminal'] ? '是' : '否';
        
        $csvData[] = [
            $row['group_name'],
            $row['group_title'],
            $row['domain_count'],
            $modeLabel,
            isset($row['redirect_target']) ? $row['redirect_target'] : '',
            $includeThree,
            $centralizeTargets
        ];
    }
    
    return $csvData;
}

/**
 * 生成CSV文件并下载
 */
function _clonegroupsite_downloadCSV() {
    $csvData = _clonegroupsite_exportCSV();
    
    // 设置HTTP头
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="clonegroupsite_config_' . date('YmdHis') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // 输出UTF-8 BOM（让Excel正确识别UTF-8编码）
    echo "\xEF\xBB\xBF";
    
    // 输出CSV数据
    $output = fopen('php://output', 'w');
    foreach ($csvData as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

/**
 * 导入CSV配置
 */
function _clonegroupsite_importCSV($filePath) {
    if (!file_exists($filePath)) {
        return ['success' => false, 'message' => 'CSV文件不存在'];
    }
    
    // 读取CSV文件
    $csvData = [];
    $handle = fopen($filePath, 'r');
    
    // 跳过BOM
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }
    
    // 读取所有行
    while (($row = fgetcsv($handle)) !== false) {
        $csvData[] = $row;
    }
    fclose($handle);
    
    if (count($csvData) < 2) {
        return ['success' => false, 'message' => 'CSV文件格式错误：数据为空'];
    }
    
    // 验证表头
    $header = $csvData[0];
    $expectedHeader = ['分组名称', '分组标题', '域名数量', '重定向模式', '重定向目标', '包含三端', '集权对象'];
    
    if (count($header) < 7) {
        return ['success' => false, 'message' => 'CSV文件格式错误：列数不足'];
    }
    
    $db = _clonegroupsite_getDB();
    $db->exec('BEGIN TRANSACTION');
    
    try {
        $stats = [
            'total' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => []
        ];
        
        // 处理数据行（跳过表头）
        for ($i = 1; $i < count($csvData); $i++) {
            $row = $csvData[$i];
            
            // 确保至少有7列
            if (count($row) < 7) {
                $stats['errors'][] = "第{$i}行：列数不足";
                continue;
            }
            
            $stats['total']++;
            
            $groupName = trim($row[0]);
            $groupTitle = trim($row[1]);
            // $domainCount = $row[2]; // 域名数量不导入，由程序自动计算
            $modeLabel = trim($row[3]);
            $redirectTarget = trim($row[4]);
            $includeThree = trim($row[5]);
            $centralizeTargets = trim($row[6]);
            
            // 验证分组是否存在
            $stmt = $db->prepare("SELECT id FROM clonegroupsite_groups WHERE group_name = ?");
            $stmt->bindValue(1, $groupName, SQLITE3_TEXT);
            $result = $stmt->execute();
            $group = $result->fetchArray(SQLITE3_ASSOC);
            
            if (!$group) {
                $stats['skipped']++;
                $stats['errors'][] = "第{$i}行：分组 '{$groupName}' 不存在，跳过";
                continue;
            }
            
            // 转换重定向模式
            $redirectMode = _clonegroupsite_convertModeLabel($modeLabel);
            if (!$redirectMode) {
                $stats['errors'][] = "第{$i}行：未知的重定向模式 '{$modeLabel}'";
                $redirectMode = 'random_three'; // 默认值
            }
            
            // 转换包含三端
            $includeThreeTerminal = ($includeThree === '是' || $includeThree === '1') ? 1 : 0;
            
            // 处理集权对象
            $centralizeConfig = null;
            if ($redirectMode === 'centralize' && !empty($centralizeTargets)) {
                $domains = array_filter(array_map('trim', explode(',', $centralizeTargets)));
                if (!empty($domains)) {
                    // 验证集权对象
                    $validation = _clonegroupsite_validateCentralizeTargets($domains);
                    if (!$validation['valid']) {
                        $stats['errors'][] = "第{$i}行：" . $validation['message'];
                        $stats['skipped']++;
                        continue;
                    }
                    $centralizeConfig = json_encode([
                        'enabled' => true,
                        'domains' => $domains
                    ]);
                }
            }
            
            // 更新分组配置
            $stmt = $db->prepare("
                UPDATE clonegroupsite_groups
                SET 
                    group_title = ?,
                    redirect_mode = ?,
                    redirect_target = ?,
                    include_three_terminal = ?,
                    centralize_targets = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE group_name = ?
            ");
            
            $stmt->bindValue(1, $groupTitle, SQLITE3_TEXT);
            $stmt->bindValue(2, $redirectMode, SQLITE3_TEXT);
            $stmt->bindValue(3, $redirectTarget, SQLITE3_TEXT);
            $stmt->bindValue(4, $includeThreeTerminal, SQLITE3_INTEGER);
            $stmt->bindValue(5, $centralizeConfig, SQLITE3_TEXT);
            $stmt->bindValue(6, $groupName, SQLITE3_TEXT);
            
            if ($stmt->execute()) {
                $stats['updated']++;
            } else {
                $stats['errors'][] = "第{$i}行：更新失败";
            }
        }
        
        $db->exec('COMMIT');
        
        $stats['success'] = true;
        $message = "导入完成！共 {$stats['total']} 条记录，成功更新 {$stats['updated']} 个分组";
        if ($stats['skipped'] > 0) {
            $message .= "，跳过 {$stats['skipped']} 个";
        }
        $stats['message'] = $message;
        
        return $stats;
        
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        return ['success' => false, 'message' => '导入失败: ' . $e->getMessage()];
    }
}

/**
 * 转换重定向模式标签为代码
 */
function _clonegroupsite_convertModeLabel($label) {
    $map = [
        '随机三端' => 'random_three',
        '固定www端' => 'fixed_www',
        '固定m端' => 'fixed_m',
        '固定顶级域名' => 'fixed_top',
        '自定义二级' => 'custom_subdomain',
        '集权' => 'centralize'
    ];
    
    return isset($map[$label]) ? $map[$label] : null;
}

/**
 * 获取重定向模式完整标签（用于CSV导出）
 */
function _clonegroupsite_getRedirectModeFullLabel($mode) {
    $map = [
        'random_three' => '随机三端',
        'fixed_www' => '固定www端',
        'fixed_m' => '固定m端',
        'fixed_top' => '固定顶级域名',
        'custom_subdomain' => '自定义二级',
        'centralize' => '集权'
    ];
    
    return isset($map[$mode]) ? $map[$mode] : '未知';
}

// ==================== 统计功能 ====================

/**
 * 获取分组统计数据
 */
function _clonegroupsite_getGroupStats($groupName, $days = 30) {
    require_once __DIR__ . '/../core/Logger.php';
    
    // 使用正确的日志数据库路径
    $logDbPath = REDIRECT301_DATA_DIR . '/logs.db';
    if (!file_exists($logDbPath)) {
        return [
            'total' => 0,
            'today' => 0,
            'week' => 0,
            'month' => 0
        ];
    }
    
    try {
        $db = new SQLite3($logDbPath);
        $db->busyTimeout(5000);
        
        // 总跳转次数
        $stmt = $db->prepare("
            SELECT COUNT(*) as total 
            FROM redirect_logs 
            WHERE feature = 'clonegroup' AND task_name = ?
        ");
        $stmt->bindValue(1, $groupName, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $total = isset($row['total']) ? $row['total'] : 0;
        
        // 今日跳转
        $todayStart = strtotime('today');
        $stmt = $db->prepare("
            SELECT COUNT(*) as total 
            FROM redirect_logs 
            WHERE feature = 'clonegroup' AND task_name = ? AND timestamp >= ?
        ");
        $stmt->bindValue(1, $groupName, SQLITE3_TEXT);
        $stmt->bindValue(2, $todayStart, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $today = isset($row['total']) ? $row['total'] : 0;
        
        // 本周跳转
        $weekStart = strtotime('monday this week');
        $stmt = $db->prepare("
            SELECT COUNT(*) as total 
            FROM redirect_logs 
            WHERE feature = 'clonegroup' AND task_name = ? AND timestamp >= ?
        ");
        $stmt->bindValue(1, $groupName, SQLITE3_TEXT);
        $stmt->bindValue(2, $weekStart, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $week = isset($row['total']) ? $row['total'] : 0;
        
        // 本月跳转
        $monthStart = strtotime('first day of this month 00:00:00');
        $stmt = $db->prepare("
            SELECT COUNT(*) as total 
            FROM redirect_logs 
            WHERE feature = 'clonegroup' AND task_name = ? AND timestamp >= ?
        ");
        $stmt->bindValue(1, $groupName, SQLITE3_TEXT);
        $stmt->bindValue(2, $monthStart, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $month = isset($row['total']) ? $row['total'] : 0;
        
        $db->close();
        
        return [
            'total' => $total,
            'today' => $today,
            'week' => $week,
            'month' => $month
        ];
        
    } catch (Exception $e) {
        error_log("CloneGroupSite: 获取统计失败 - " . $e->getMessage());
        return [
            'total' => 0,
            'today' => 0,
            'week' => 0,
            'month' => 0
        ];
    }
}

/**
 * 获取所有分组的统计数据（批量）
 */
function _clonegroupsite_getAllGroupsStats() {
    require_once __DIR__ . '/../core/Logger.php';
    
    // 使用正确的日志数据库路径
    $logDbPath = REDIRECT301_DATA_DIR . '/logs.db';
    if (!file_exists($logDbPath)) {
        return [];
    }
    
    try {
        $db = new SQLite3($logDbPath);
        $db->busyTimeout(5000);
        
        // 批量查询所有分组的总跳转次数
        $stmt = $db->query("
            SELECT task_name, COUNT(*) as total 
            FROM redirect_logs 
            WHERE feature = 'clonegroup'
            GROUP BY task_name
        ");
        
        $stats = [];
        while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
            $stats[$row['task_name']] = [
                'total' => $row['total']
            ];
        }
        
        $db->close();
        
        return $stats;
        
    } catch (Exception $e) {
        error_log("CloneGroupSite: 批量获取统计失败 - " . $e->getMessage());
        return [];
    }
}

/**
 * 获取模块全局统计
 */
function _clonegroupsite_getGlobalStats() {
    require_once __DIR__ . '/../core/Logger.php';
    
    // 使用正确的日志数据库路径
    $logDbPath = REDIRECT301_DATA_DIR . '/logs.db';
    if (!file_exists($logDbPath)) {
        return [
            'total_redirects' => 0,
            'today_redirects' => 0,
            'top_groups' => []
        ];
    }
    
    try {
        $db = new SQLite3($logDbPath);
        $db->busyTimeout(5000);
        
        // 总跳转次数
        $result = $db->query("
            SELECT COUNT(*) as total 
            FROM redirect_logs 
            WHERE feature = 'clonegroup'
        ");
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $totalRedirects = isset($row['total']) ? $row['total'] : 0;
        
        // 今日跳转
        $todayStart = strtotime('today');
        $stmt = $db->prepare("
            SELECT COUNT(*) as total 
            FROM redirect_logs 
            WHERE feature = 'clonegroup' AND timestamp >= ?
        ");
        $stmt->bindValue(1, $todayStart, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $todayRedirects = isset($row['total']) ? $row['total'] : 0;
        
        // TOP 5 最活跃分组
        $result = $db->query("
            SELECT task_name, COUNT(*) as total 
            FROM redirect_logs 
            WHERE feature = 'clonegroup'
            GROUP BY task_name
            ORDER BY total DESC
            LIMIT 5
        ");
        
        $topGroups = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $topGroups[] = [
                'name' => $row['task_name'],
                'count' => $row['total']
            ];
        }
        
        $db->close();
        
        return [
            'total_redirects' => $totalRedirects,
            'today_redirects' => $todayRedirects,
            'top_groups' => $topGroups
        ];
        
    } catch (Exception $e) {
        error_log("CloneGroupSite: 获取全局统计失败 - " . $e->getMessage());
        return [
            'total_redirects' => 0,
            'today_redirects' => 0,
            'top_groups' => []
        ];
    }
}

