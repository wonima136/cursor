<?php
/**
 * 域名分组管理类
 * 功能：
 * 1. 批量配置生成
 * 2. 分组管理
 * 3. 缓存自动更新（基于访问次数）
 */

class DomainGroupManager {
    private $base_dir;
    private $groups_file;
    private $counters_dir;
    private $groupsDB; // 分组数据库
    private $domainsDB; // 域名数据库
    
    public function __construct() {
        // 使用绝对路径
        if (!defined('KELONG_ROOT_DIR')) {
            require_once __DIR__ . '/config.php';
        }
        
        $this->base_dir = KELONG_ROOT_DIR;
        $this->groups_file = KELONG_GROUPS_JSON;
        $this->counters_dir = KELONG_DOMAIN_GROUPS_DIR . '/visit_counters/';
        
        // 确保目录存在
        $this->ensureDirectories();
        
        // 🆕 初始化数据库
        require_once __DIR__ . '/GroupsDB.php';
        require_once __DIR__ . '/DomainsDB.php';
        $this->groupsDB = getGroupsDB();
        $this->domainsDB = getDomainsDB();
    }
    
    /**
     * 确保必要的目录存在
     */
    private function ensureDirectories() {
        $dirs = [
            dirname($this->groups_file),
            $this->counters_dir
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
        
        // 初始化groups.json
        if (!file_exists($this->groups_file)) {
            file_put_contents($this->groups_file, json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }
    
    /**
     * 获取所有分组
     * 🆕 从数据库读取 + 🚀 使用预处理的域名列表（极速）
     * @param bool $includeDomains 是否包含域名列表（默认true以兼容旧代码）
     */
    public function getAllGroups($includeDomains = true) {
        // 从数据库读取分组信息（包含预处理的域名列表）
        $groups = $this->groupsDB->getAllGroups();
        
        // 转换为旧格式（保持兼容性）
        $result = [];
        foreach ($groups as $group) {
            $groupId = $group['group_id'];
            
            // 🚀 直接从预处理字段读取域名列表（不需要查询 domains.db）
            $domainList = [];
            if ($includeDomains && !empty($group['domain_list_json'])) {
                $domains = json_decode($group['domain_list_json'], true) ?: [];
                foreach ($domains as $d) {
                    $domainList[] = [
                        'domain' => $d['domain'] ?? $d,
                        'visit_count' => 0,
                        'total_clone_source_switches' => 0
                    ];
                }
            }
            
            $result[$groupId] = [
                'group_id' => $groupId,
                'name' => $group['group_name'],
                'type' => $group['group_type'],
                'value' => $group['root_value'],
                'domains' => $domainList, // 🚀 从预处理字段读取，极快！
                'created_at' => $group['created_at'],
                'config' => json_decode($group['config_json'] ?: '{}', true),
                'subdomain_config' => [
                    'mode' => $group['subdomain_mode']
                ],
                // 统计字段
                'domain_count' => $group['domain_count'],
                'subdomain_count' => $group['subdomain_count']
            ];
        }
        
        return $result;
    }
    
    /**
     * 获取指定分组
     * 🆕 从数据库读取
     * @param bool $includeDomains 是否包含域名列表（默认true）
     */
    public function getGroup($groupId, $includeDomains = true) {
        // 从数据库读取分组信息
        $group = $this->groupsDB->getGroup($groupId);
        
        if (!$group) {
            return null;
        }
        
        // 获取域名列表（如果需要）
        $domainList = [];
        if ($includeDomains && $this->domainsDB) {
            $domains = $this->domainsDB->getDomainsByGroup($groupId);
            foreach ($domains as $d) {
                $domainList[] = [
                    'domain' => $d['domain'],
                    'visit_count' => 0,
                    'total_clone_source_switches' => 0
                ];
            }
        }
        
        // 解析完整配置信息
        $fullConfig = json_decode($group['config_json'] ?: '{}', true);
        
        // 转换为旧格式（保持兼容性）
        $result = [
            'group_id' => $group['group_id'],
            'name' => $group['group_name'],
            'type' => $group['group_type'],
            'value' => $group['root_value'],
            'domains' => $domainList, // 域名列表（可选）
            'created_at' => $group['created_at'],
            'config' => $fullConfig,
            'subdomain_config' => [
                'mode' => $group['subdomain_mode']
            ],
            'domain_count' => $group['domain_count'],
            'subdomain_count' => $group['subdomain_count']
        ];
        
        // 🆕 添加完整配置字段，确保访问计数和切换功能正常工作
        if (isset($fullConfig['cache_refresh_config'])) {
            $result['cache_refresh_config'] = $fullConfig['cache_refresh_config'];
            // 兼容旧字段名
            $result['cache_refresh'] = $fullConfig['cache_refresh_config'];
        }
        
        if (isset($fullConfig['clone_source_switch'])) {
            $result['clone_source_switch'] = $fullConfig['clone_source_switch'];
        }
        
        if (isset($fullConfig['subdomain_config'])) {
            $result['subdomain_config'] = array_merge($result['subdomain_config'], $fullConfig['subdomain_config']);
        }
        
        return $result;
    }
    
    /**
     * 根据域名获取所属分组
     * 🆕 从数据库查询
     */
    public function getDomainGroup($domain) {
        // 从域名数据库查询域名信息
        $domainInfo = $this->domainsDB->getDomain($domain);
        
        if (!$domainInfo) {
            return null;
        }
        
        $groupId = $domainInfo['group_id'];
        
        // 获取分组信息
        return $this->getGroup($groupId);
    }
    
    /**
     * 创建新分组
     * 🆕 只保存到数据库
     */
    public function createGroup($name, $type, $value, $domains = [], $cacheRefreshConfig = null, $cloneSourceSwitchConfig = null, $subdomainConfig = null) {
        // 生成唯一ID
        $groupId = 'group_' . time() . '_' . substr(md5(uniqid()), 0, 8);
        
        $groupData = [
            'group_id' => $groupId,
            'name' => $name,
            'type' => $type,  // 'root' 或 'custom_title'
            'value' => $value,
            'domains' => $domains,
            'created_at' => date('Y-m-d H:i:s'),
            'cache_refresh_config' => $cacheRefreshConfig ?: [
                'enabled' => false,
                'trigger_visits' => 3,
                'source_strategy' => 'all_random',  // group_random | all_random
                'reset_counter' => true,
                'log_updates' => true,
                'min_cache_size' => 30720  // 30KB
            ],
            'clone_source_switch' => $cloneSourceSwitchConfig ?: [
                'enabled' => false,
                'trigger_visits' => 10,
                'target_domain' => '',  // 目标克隆源域名，留空则随机
                'reset_counter' => true,  // 切换后重置计数器
                'log_updates' => true     // 记录切换日志
            ],
            'subdomain_config' => $subdomainConfig ?: [
                'mode' => 'fixed_top',  // independent | fixed_top | dynamic_top
                'inherit_tdk_mode' => true,  // 是否继承顶级的TDK配置方式
                'keyword_source' => 'from_parent'  // 关键词来源：from_parent（继承父级）
            ],
            'config' => [] // 保存完整配置
        ];
        
        // 🆕 保存到分组数据库（只保存汇总信息）
        if ($this->groupsDB) {
            $this->groupsDB->saveGroup(
                $groupId,
                $name,
                $type,
                [
                    'root_value' => $value,
                    'clone_url' => $groupData['config']['clone_url'] ?? '',
                    'subdomain_mode' => $groupData['subdomain_config']['mode'] ?? 'fixed_top',
                    'domain_count' => 0, // 初始为0，创建域名时更新
                    'subdomain_count' => 0,
                    'created_at' => $groupData['created_at'],
                    // 🆕 直接传递完整的分组配置，而不是再次JSON编码
                    'full_group_data' => $groupData
                ]
            );
        }
        
        // 保存到JSON文件（备份）
        $groups = $this->getAllGroupsFromDB(); // 从数据库获取
        $groups[$groupId] = $groupData;
        $this->saveGroups($groups);
        
        return $groupId;
    }
    
    /**
     * 更新分组
     * 🆕 更新数据库
     */
    public function updateGroup($groupId, $data) {
        // 从数据库获取当前分组
        $group = $this->getGroup($groupId);
        
        if (!$group) {
            return false;
        }
        
        // 更新字段
        foreach ($data as $key => $value) {
            if ($key !== 'created_at') {  // 保留创建时间
                $group[$key] = $value;
            }
        }
        
        $group['updated_at'] = date('Y-m-d H:i:s');
        
        // 更新数据库
        if ($this->groupsDB) {
            $this->groupsDB->saveGroup(
                $groupId,
                $group['name'],
                $group['type'],
                [
                    'root_value' => $group['value'],
                    'clone_url' => $group['config']['clone_url'] ?? '',
                    'subdomain_mode' => $group['subdomain_config']['mode'] ?? 'fixed_top',
                    'domain_count' => $group['domain_count'] ?? 0,
                    'subdomain_count' => $group['subdomain_count'] ?? 0,
                    'created_at' => $group['created_at'],
                    // 🆕 传递完整的分组数据
                    'full_group_data' => $group
                ]
            );
        }
        
        // 同步到JSON（备份）
        $groups = $this->getAllGroupsFromDB();
        $groups[$groupId] = $group;
        $this->saveGroups($groups);
        
        return true;
    }
    
    /**
     * 删除分组
     * 🆕 从数据库删除
     */
    public function deleteGroup($groupId) {
        // 从数据库获取分组
        $group = $this->getGroup($groupId);
        
        if (!$group) {
            return false;
        }
        
        // 引入 DomainConfigManager
        if (!class_exists('DomainConfigManager')) {
            require_once $this->base_dir . '/inc/DomainConfigManager.php';
        }
        $configManager = new DomainConfigManager();
        
        // 获取该组的所有域名
        $domains = $this->domainsDB->getDomainsByGroup($groupId);
        
        // 删除该组所有域名的配置文件、计数器和域名记录
        foreach ($domains as $domainInfo) {
            $domain = $domainInfo['domain'];
            
            // 🆕 只删除该域名的配置文件（不使用通配符，因为子域名已经在列表中）
            $configManager->deleteConfig($domain, false);
            
            // 删除计数器和数据库记录
            $this->deleteVisitCounter($domain);
            $this->domainsDB->deleteDomain($domain, false); // 不需要级联，因为已经遍历所有域名
        }
        
        // 从数据库删除分组
        if ($this->groupsDB) {
            $this->groupsDB->deleteGroup($groupId);
        }
        
        // 同步到JSON（备份）
        $groups = $this->getAllGroupsFromDB();
        unset($groups[$groupId]);
        $this->saveGroups($groups);
        
        return true;
    }
    
    /**
     * 保存分组数据
     */
    private function saveGroups($groups) {
        file_put_contents(
            $this->groups_file, 
            json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }
    
    /**
     * 从数据库获取所有分组（内部使用）
     * 返回与 getAllGroups() 相同格式的数据
     */
    private function getAllGroupsFromDB() {
        return $this->getAllGroups();
    }
    
    /**
     * 批量生成配置
     * 🆕 同时保存到 domains.db
     */
    public function batchGenerateConfigs($domains, $type, $value, $mode = 'independent', $group_id = null, $parent_domain = null) {
        if (!class_exists('DomainConfigManager')) {
            require_once $this->base_dir . '/inc/DomainConfigManager.php';
        }
        
        $configManager = new DomainConfigManager();
        
        $results = [];
        
        foreach ($domains as $domain) {
            $domain = trim($domain);
            if (empty($domain)) continue;
            
            // 生成TDK
            $tdk = $this->generateTDKFromRoot($value, $type);
            
            // 随机选择镜像ID
            $mirrorId = $this->selectRandomMirrorId();
            
            if (!$mirrorId) {
                $results[$domain] = [
                    'success' => false, 
                    'error' => '无法获取镜像源：data/mirrors/ 目录为空或没有包含 config.json 的镜像源文件夹，请先上传或生成镜像源'
                ];
                continue;
            }
            
            // 确定保存的域名
            if ($mode === 'independent') {
                $saveDomain = $domain;
            } else {
                $saveDomain = $this->extractTopDomain($domain);
            }
            
            // 直接生成镜像模式JSON配置
            $configManager->createMirrorConfig(
                $saveDomain,
                $mirrorId,
                $tdk,
                $value,  // 词根
                $mode
            );
            
            // 🆕 保存到 domains.db（如果有 group_id）
            if ($group_id && $this->domainsDB) {
                $this->domainsDB->addDomain(
                    $saveDomain,
                    $group_id,
                    [
                        'clone_url' => $mirrorId,
                        'title' => $tdk['title'],
                        'keywords' => $tdk['keywords'],
                        'description' => $tdk['description'],
                        'root_value' => $value,
                        'mode' => $mode
                    ],
                    $parent_domain
                );
            }
            
            $results[$domain] = [
                'success' => true,
                'domain' => $saveDomain,
                'mirror_id' => $mirrorId
            ];
        }
        
        // 🚀 更新 groups.db 的域名列表（预处理数据，提升查询性能）
        if ($group_id && $this->groupsDB && $this->domainsDB) {
            $allDomains = $this->domainsDB->getDomainsByGroup($group_id);
            $this->groupsDB->updateDomainList($group_id, $allDomains);
        }
        
        return $results;
    }
    
    /**
     * 从词根生成TDK
     */
    private function generateTDKFromRoot($value, $type) {
        if ($type === 'custom_title') {
            // 自定义标题 - 支持多种分隔符
            $keywords = $this->parseKeywordsFromTitle($value);
            return [
                'title' => $value,
                'keywords' => implode(',', $keywords),
                'description' => $value
            ];
        }
        
        // 从词根生成
        $keywordsFile = $this->base_dir . '/data/data_key/keywords_by_root/' . $value . '.txt';
        
        if (file_exists($keywordsFile)) {
            $keywords = file($keywordsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $keywords = array_filter(array_map('trim', $keywords));
            shuffle($keywords);
            $selected = array_slice($keywords, 0, 8);
        } else {
            // 从主库搜索
            $mainFile = $this->base_dir . '/data/data_key/key.txt';
            if (file_exists($mainFile)) {
                $allKeywords = file($mainFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $keywords = array_filter($allKeywords, function($kw) use ($value) {
                    return mb_strpos($kw, $value) !== false;
                });
                shuffle($keywords);
                $selected = array_slice($keywords, 0, 8);
            } else {
                $selected = [$value, $value, $value, $value, $value, $value, $value, $value];
            }
        }
        
        $title = implode('_', $selected);
        $keywordsStr = implode(',', $selected);
        
        return [
            'title' => $title,
            'keywords' => $keywordsStr,
            'description' => $title
        ];
    }
    
    /**
     * 从标题中解析关键词 - 支持多种分隔符
     * 支持的分隔符：下划线(_)、竖线(|)、连字符(-)、空格( )、逗号(,)
     */
    private function parseKeywordsFromTitle($title) {
        // 支持的分隔符，按优先级排序
        $separators = ['_', '|', '-', ' ', ','];
        
        // 遍历分隔符，找到第一个存在的
        foreach ($separators as $separator) {
            if (strpos($title, $separator) !== false) {
                $keywords = explode($separator, $title);
                // 清理和过滤关键词
                $keywords = array_filter(array_map('trim', $keywords));
                return array_values($keywords); // 重新索引数组
            }
        }
        
        // 如果没找到任何分隔符，整个标题作为单个关键词
        return [trim($title)];
    }
    
    /**
     * 随机选择镜像ID
     */
    private function selectRandomMirrorId() {
        $mirrorsDir = $this->base_dir . '/data/mirrors/';
        
        if (!is_dir($mirrorsDir)) {
            error_log("DomainGroupManager: 镜像目录不存在: {$mirrorsDir}");
            return null;
        }
        
        $dirs = scandir($mirrorsDir);
        $availableMirrors = [];
        
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..' || strpos($dir, 'mirror_') !== 0) {
                continue;
            }
            
            $configFile = $mirrorsDir . $dir . '/config.json';
            if (file_exists($configFile)) {
                $availableMirrors[] = $dir;  // 返回镜像ID，不是target_domain
            }
        }
        
        if (empty($availableMirrors)) {
            error_log("DomainGroupManager: 没有找到有效的镜像源（含config.json）");
            return null;
        }
        
        return $availableMirrors[array_rand($availableMirrors)];
    }
    
    /**
     * 提取顶级域名
     */
    /**
     * 获取下一个全局唯一序号
     * @param array $groups 所有分组
     * @return int 下一个唯一序号
     */
    private function getNextUniqueNumber($groups) {
        $maxNumber = 0;
        
        foreach ($groups as $group) {
            if (isset($group['unique_number'])) {
                $maxNumber = max($maxNumber, (int)$group['unique_number']);
            }
        }
        
        return $maxNumber + 1;
    }
    
    private function extractTopDomain($domain) {
        // 使用 DomainExtractor 正确提取顶级域名（支持双后缀）
        if (!class_exists('DomainExtractor')) {
            require_once __DIR__ . '/DomainExtractor.php';
        }
        
        $extractor = DomainExtractor::getInstance();
        return $extractor->extractTopDomain($domain);
    }
    
    /**
     * 获取访问计数器
     */
    public function getVisitCounter($domain) {
        $counterFile = $this->counters_dir . $domain . '.json';
        
        if (!file_exists($counterFile)) {
            return [
                'domain' => $domain,
                'group_id' => null,
                'visit_count' => 0,
                'last_refresh' => null,
                'last_visit' => null,
                'total_refreshes' => 0,
                'total_clone_source_switches' => 0,
                'current_cache_source' => null,
                'current_clone_source' => null
            ];
        }
        
        $content = file_get_contents($counterFile);
        return json_decode($content, true);
    }
    
    /**
     * 增加访问计数
     */
    public function incrementVisitCounter($domain) {
        $counter = $this->getVisitCounter($domain);
        $counter['visit_count']++;
        $counter['last_visit'] = date('Y-m-d H:i:s');
        
        // 获取分组信息
        $groupInfo = $this->getDomainGroup($domain);
        if ($groupInfo) {
            $counter['group_id'] = $groupInfo['group_id'];
        }
        
        $this->saveVisitCounter($domain, $counter);
        return $counter['visit_count'];
    }
    
    /**
     * 重置访问计数器
     */
    public function resetVisitCounter($domain) {
        $counter = $this->getVisitCounter($domain);
        $counter['visit_count'] = 0;
        $this->saveVisitCounter($domain, $counter);
    }
    
    /**
     * 切换克隆源
     */
    public function switchCloneSource($domain, $groupInfo) {
        try {
            // 读取配置文件
            $configFile = $this->base_dir . '/data/domain/' . $domain . '.txt';
            
            if (!file_exists($configFile)) {
                error_log("[克隆源切换] 配置文件不存在: {$configFile}");
                return false;
            }
            
            $configLines = file($configFile, FILE_IGNORE_NEW_LINES);
            if (count($configLines) < 10) {
                error_log("[克隆源切换] 配置文件格式错误");
                return false;
            }
            
            // 获取目标克隆源
            $targetDomain = $groupInfo['clone_source_switch']['target_domain'] ?? '';
            
            // 如果未指定目标克隆源，从公司信息.csv随机选择
            if (empty($targetDomain)) {
                $csvFile = $this->base_dir . '/data/data_key/公司信息.csv';
                if (file_exists($csvFile)) {
                    $csvLines = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    if (count($csvLines) > 1) {
                        $randomIndex = rand(1, count($csvLines) - 1);
                        $parts = str_getcsv($csvLines[$randomIndex]);
                        if (count($parts) >= 1) {
                            $targetDomain = 'www.' . trim($parts[0]);
                        }
                    }
                }
            }
            
            if (empty($targetDomain)) {
                error_log("[克隆源切换] 无法获取目标克隆源");
                return false;
            }
            
            // 记录旧的克隆源
            $oldSource = $configLines[0];
            
            // 更新配置文件第0行（克隆源）
            $configLines[0] = $targetDomain;
            
            // 保存配置文件
            file_put_contents($configFile, implode("\n", $configLines));
            
            
            // 更新访问计数器，记录切换次数
            $counter = $this->getVisitCounter($domain);
            $counter['total_clone_source_switches'] = ($counter['total_clone_source_switches'] ?? 0) + 1;
            $counter['last_clone_source_switch'] = date('Y-m-d H:i:s');
            $counter['current_clone_source'] = $targetDomain;
            $this->saveVisitCounter($domain, $counter);
            
            // 日志功能已移除
            
            return true;
            
        } catch (Exception $e) {
            error_log("[克隆源切换] 异常: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 切换克隆源（换汤不换药版本）
     * 只替换克隆源，保持TDK和关键词不变
     */
    public function switchCloneSourceWithCache($domain, $groupInfo, $currentCacheFile) {
        try {
            // 1. 读取配置（使用JSON配置管理器）
            if (!class_exists('DomainConfigManager')) {
                require_once $this->base_dir . '/inc/DomainConfigManager.php';
            }
            $configManager = new DomainConfigManager();
            
            $config = $configManager->getConfig($domain);
            
            if (!$config || $config['mode'] !== 'clone') {
                error_log("[克隆源切换] 配置不存在或不是克隆模式");
                return false;
            }
            
            // 保存当前的TDK和关键词（不变的部分）
            $currentTDK = [
                'target_keywords' => $config['target_keywords'] ?? '',
                'replace_keywords' => $config['replace_keywords'] ?? '',
                'site_title' => $config['tdk']['title'],
                'site_keywords' => $config['tdk']['keywords'],
                'site_description' => $config['tdk']['description']
            ];
            
            // 2. 获取新的克隆源（从预制镜像或CSV）
            $targetDomain = $groupInfo['clone_source_switch']['target_domain'] ?? '';
            
            if (empty($targetDomain)) {
                // 优先从预制镜像随机选择
                $mirrorsDir = $this->base_dir . '/data/mirrors/';
                if (is_dir($mirrorsDir)) {
                    $dirs = scandir($mirrorsDir);
                    $availableMirrors = [];
                    
                    foreach ($dirs as $dir) {
                        if ($dir === '.' || $dir === '..') {
                            continue;
                        }
                        
                        $configFile = $mirrorsDir . $dir . '/config.json';
                        if (file_exists($configFile)) {
                            $config = json_decode(file_get_contents($configFile), true);
                            if ($config && isset($config['target_domain'])) {
                                $availableMirrors[] = $config['target_domain'];
                            }
                        }
                    }
                    
                    if (!empty($availableMirrors)) {
                        $targetDomain = $availableMirrors[array_rand($availableMirrors)];
                    }
                }
                
                // 如果镜像目录为空，从公司信息.csv选择
                if (empty($targetDomain)) {
                    $csvFile = $this->base_dir . '/data/data_key/公司信息.csv';
                    if (file_exists($csvFile)) {
                        $csvLines = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                        if (count($csvLines) > 1) {
                            $randomIndex = rand(1, count($csvLines) - 1);
                            $parts = str_getcsv($csvLines[$randomIndex]);
                            if (count($parts) >= 1) {
                                $targetDomain = 'www.' . trim($parts[0]);
                            }
                        }
                    }
                }
            }
            
            if (empty($targetDomain)) {
                error_log("[克隆源切换] 无法获取目标克隆源");
                return false;
            }
            
            $oldSource = $config['target_domain'];
            
            // 3. 更新配置文件的克隆源（使用JSON配置管理器）
            $configManager->updateCloneSource($domain, $targetDomain);
            
            
            // 4. 优先从新克隆源重新抓取
            $newCacheContent = $this->cloneFromNewSource($targetDomain, $domain);
            
            if ($newCacheContent === false) {
                
                // 5. 抓取失败，从其他域名复制已有缓存
                $newCacheContent = $this->getCacheFromOtherDomain($domain, $groupInfo);
                
                if ($newCacheContent === false) {
                    error_log("[克隆源切换] 无法获取新缓存，保持原缓存");
                    // 只更新配置，不更新缓存
                    return true;
                }
            }
            
            // 6. 替换新缓存中的TDK和关键词为当前分组的值
            $newCacheContent = $this->replaceTDKInCache($newCacheContent, $currentTDK);
            
            // 7. 保存新缓存
            file_put_contents($currentCacheFile, $newCacheContent);
            
            // 7. 更新访问计数器
            $counter = $this->getVisitCounter($domain);
            $counter['total_clone_source_switches'] = ($counter['total_clone_source_switches'] ?? 0) + 1;
            $counter['last_clone_source_switch'] = date('Y-m-d H:i:s');
            $counter['current_clone_source'] = $targetDomain;
            $this->saveVisitCounter($domain, $counter);
            
            return true;
            
        } catch (Exception $e) {
            error_log("[克隆源切换] 异常: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 从新克隆源抓取内容
     */
    private function cloneFromNewSource($targetDomain, $currentDomain) {
        try {
            // 获取当前请求的路径
            $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
            
            // 构建克隆URL
            $cloneUrl = 'http://' . $targetDomain . $requestUri;
            
            
            // 使用 get_content 函数抓取（如果存在）
            if (function_exists('get_content')) {
                $content = get_content($cloneUrl);
            } else {
                // 使用 file_get_contents 备用
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 10,
                        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                    ]
                ]);
                $content = @file_get_contents($cloneUrl, false, $context);
            }
            
            // 检查内容是否有效
            if (empty($content) || strlen($content) < 10240) {  // 至少10KB
                error_log("[克隆源切换] 抓取内容无效或太小: " . strlen($content) . " 字节");
                return false;
            }
            
            return $content;
            
        } catch (Exception $e) {
            error_log("[克隆源切换] 抓取异常: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 从其他域名获取缓存（用于克隆源切换）
     */
    private function getCacheFromOtherDomain($currentDomain, $groupInfo) {
        // 策略：从本组其他域名或所有域名中随机选择
        $cacheDir = $this->base_dir . '/cachefile_yuan/';
        
        if (!is_dir($cacheDir)) {
            return false;
        }
        
        // 获取所有可用的缓存目录
        $availableCaches = [];
        $dirs = scandir($cacheDir);
        
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..' || $dir === $currentDomain) {
                continue;
            }
            
            $indexFile = $cacheDir . $dir . '/index.html';
            if (file_exists($indexFile)) {
                $size = filesize($indexFile);
                $minSize = $groupInfo['cache_refresh']['min_cache_size'] ?? 30720;
                
                if ($size >= $minSize) {
                    $availableCaches[] = [
                        'domain' => $dir,
                        'file' => $indexFile,
                        'size' => $size
                    ];
                }
            }
        }
        
        if (empty($availableCaches)) {
            error_log("[克隆源切换] 没有可用的缓存");
            return false;
        }
        
        // 随机选择一个
        $selected = $availableCaches[array_rand($availableCaches)];
        
        return file_get_contents($selected['file']);
    }
    
    /**
     * 替换缓存中的TDK和关键词（换汤不换药）
     */
    private function replaceTDKInCache($cacheContent, $currentTDK) {
        // 提取TDK
        $siteTitle = $currentTDK['site_title'];
        $siteKeywords = $currentTDK['site_keywords'];
        $siteDescription = $currentTDK['site_description'];
        
        
        // 1. 替换标题（多种格式）
        $titleReplaced = false;
        
        // 格式1: <title>xxx</title>
        if (preg_match('@<title>(.*?)</title>@is', $cacheContent)) {
            $cacheContent = preg_replace(
                '@<title>(.*?)</title>@is',
                '<title>' . $siteTitle . '</title>',
                $cacheContent,
                -1,
                $count
            );
            if ($count > 0) {
                $titleReplaced = true;
            }
        }
        
        if (!$titleReplaced) {
            error_log("[TDK替换] 警告：未找到标题标签");
        }
        
        // 2. 替换关键词meta标签（多种格式）
        $keywordsReplaced = 0;
        
        // 删除所有现有的keywords标签
        $cacheContent = preg_replace(
            '@<meta[^>]*?name=["\']?keywords["\']?[^>]*?>@is',
            '',
            $cacheContent,
            -1,
            $keywordsReplaced
        );
        
        // 在</title>后插入新的keywords标签
        $cacheContent = preg_replace(
            '@</title>@i',
            '</title>' . "\n" . '<meta name="keywords" content="' . $siteKeywords . '" />',
            $cacheContent,
            1
        );
        
        
        // 3. 替换描述meta标签（多种格式）
        $descReplaced = 0;
        
        // 删除所有现有的description标签
        $cacheContent = preg_replace(
            '@<meta[^>]*?name=["\']?description["\']?[^>]*?>@is',
            '',
            $cacheContent,
            -1,
            $descReplaced
        );
        
        // 在keywords后插入新的description标签
        $cacheContent = preg_replace(
            '@(<meta name="keywords"[^>]*?>)@i',
            '$1' . "\n" . '<meta name="description" content="' . $siteDescription . '" />',
            $cacheContent,
            1
        );
        
        
        // 4. 删除缓存中所有的 <h1> 标签（避免重复）
        // 因为 index.php 会重新插入 <h1>$guanjianzi</h1>
        
        // 方法1：删除 <body> 紧跟的 <h1>
        $cacheContent = preg_replace(
            '@<body([^>]*)>\s*<h1[^>]*>.*?</h1>@is',
            '<body$1>',
            $cacheContent,
            -1,
            $h1Count1
        );
        
        // 方法2：删除所有独立的 <h1> 标签（不在其他标签内的）
        $cacheContent = preg_replace(
            '@<h1[^>]*>.*?</h1>\s*@is',
            '',
            $cacheContent,
            -1,
            $h1Count2
        );
        
        $h1Replaced = $h1Count1 + $h1Count2;
        if ($h1Replaced > 0) {
        }
        
        // 5. 删除旧的 __overflow_a 链接（AddKeys函数会重新插入）
        $cacheContent = preg_replace(
            '@<a[^>]*?id=["\']__overflow_a["\'][^>]*>.*?</a>\s*</body>@is',
            '</body>',
            $cacheContent,
            -1,
            $aReplaced
        );
        
        if ($aReplaced > 0) {
        }
        
        // 6. 删除隐藏的关键词堆砌div
        $cacheContent = preg_replace(
            '@<div[^>]*?style=["\'][^"\']*display:\s*none[^"\']*["\'][^>]*>.*?</div>\s*</body>@is',
            '</body>',
            $cacheContent,
            -1,
            $divReplaced
        );
        
        if ($divReplaced > 0) {
        }
        
        // 7. 删除缓存中可能存在的友情链接表格（避免重复）
        $cacheContent = preg_replace(
            '@<table[^>]*?id=["\']table1["\'][^>]*>.*?</table>@is',
            '',
            $cacheContent,
            -1,
            $tableReplaced
        );
        
        if ($tableReplaced > 0) {
        }
        
        return $cacheContent;
    }
    
    /**
     * 保存访问计数器
     */
    public function saveVisitCounter($domain, $counter) {
        $counterFile = $this->counters_dir . $domain . '.json';
        file_put_contents(
            $counterFile,
            json_encode($counter, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }
    
    /**
     * 删除访问计数器
     */
    private function deleteVisitCounter($domain) {
        $counterFile = $this->counters_dir . $domain . '.json';
        if (file_exists($counterFile)) {
            unlink($counterFile);
        }
    }
    
    /**
     * 刷新域名缓存
     */
    public function refreshDomainCache($domain, $groupInfo = null) {
        if (!$groupInfo) {
            $groupInfo = $this->getDomainGroup($domain);
        }
        
        if (!$groupInfo || !$groupInfo['cache_refresh']['enabled']) {
            return false;
        }
        
        $config = $groupInfo['cache_refresh'];
        
        // 选择缓存源
        $sourceDomain = $this->selectCacheSource($domain, $groupInfo);
        
        if (!$sourceDomain) {
            // 日志功能已移除
            
            // 即使失败也重置计数器，避免每次访问都尝试更新
            if ($config['reset_counter']) {
                $counter = $this->getVisitCounter($domain);
                $counter['visit_count'] = 0;
                $counter['last_failed_refresh'] = date('Y-m-d H:i:s');
                $this->saveVisitCounter($domain, $counter);
            }
            
            return false;
        }
        
        // 执行缓存替换
        $result = $this->replaceCacheContent($domain, $sourceDomain, $config);
        
        if ($result) {
            // 更新计数器 - 成功
            $counter = $this->getVisitCounter($domain);
            $counter['last_refresh'] = date('Y-m-d H:i:s');
            $counter['total_refreshes']++;
            $counter['current_cache_source'] = $sourceDomain;
            
            if ($config['reset_counter']) {
                $counter['visit_count'] = 0;
            }
            
            $this->saveVisitCounter($domain, $counter);
            
            // 日志功能已移除
            
            return true;
        } else {
            // 更新计数器 - 失败
            // 即使失败也重置计数器，避免每次访问都尝试更新
            if ($config['reset_counter']) {
                $counter = $this->getVisitCounter($domain);
                $counter['visit_count'] = 0;
                $counter['last_failed_refresh'] = date('Y-m-d H:i:s');
                $this->saveVisitCounter($domain, $counter);
            }
            
            // 日志功能已移除
        }
        
        return false;
    }
    
    /**
     * 选择缓存源
     */
    private function selectCacheSource($targetDomain, $groupInfo) {
        $strategy = $groupInfo['cache_refresh']['source_strategy'];
        $minSize = $groupInfo['cache_refresh']['min_cache_size'];
        
        $cacheDir = $this->base_dir . '/cachefile_yuan/';
        
        if ($strategy === 'group_random') {
            // 从本组其他域名随机选择
            $candidates = array_filter($groupInfo['domains'], function($d) use ($targetDomain) {
                return $d !== $targetDomain;
            });
        } else {
            // 从所有域名随机选择
            $candidates = [];
            if (is_dir($cacheDir)) {
                $dirs = scandir($cacheDir);
                foreach ($dirs as $dir) {
                    if ($dir !== '.' && $dir !== '..' && $dir !== $targetDomain) {
                        $candidates[] = $dir;
                    }
                }
            }
        }
        
        if (empty($candidates)) {
            return null;
        }
        
        // 随机打乱
        shuffle($candidates);
        
        // 查找符合大小要求的缓存
        foreach ($candidates as $domain) {
            $domainCacheDir = $cacheDir . $domain;
            if (!is_dir($domainCacheDir)) continue;
            
            $files = glob($domainCacheDir . '/*.html');
            if (empty($files)) continue;
            
            // 随机选择一个缓存文件
            $cacheFile = $files[array_rand($files)];
            
            if (filesize($cacheFile) >= $minSize) {
                return $domain;
            }
        }
        
        return null;
    }
    
    /**
     * 替换缓存内容
     */
    private function replaceCacheContent($targetDomain, $sourceDomain, $config) {
        $cacheDir = $this->base_dir . '/cachefile_yuan/';
        $sourceCacheDir = $cacheDir . $sourceDomain;
        $targetCacheDir = $cacheDir . $targetDomain;
        
        if (!is_dir($sourceCacheDir)) {
            return false;
        }
        
        // 获取源缓存文件
        $sourceFiles = glob($sourceCacheDir . '/*.html');
        if (empty($sourceFiles)) {
            return false;
        }
        
        // 随机选择一个源缓存
        $sourceFile = $sourceFiles[array_rand($sourceFiles)];
        $sourceContent = file_get_contents($sourceFile);
        
        if (empty($sourceContent) || strlen($sourceContent) < $config['min_cache_size']) {
            return false;
        }
        
        // 读取目标域名和源域名配置（使用JSON配置管理器）
        if (!class_exists('DomainConfigManager')) {
            require_once $this->base_dir . '/inc/DomainConfigManager.php';
        }
        $configManager = new DomainConfigManager();
        
        $targetConfig = $configManager->getConfig($targetDomain);
        $sourceConfig = $configManager->getConfig($sourceDomain);
        
        if (!$targetConfig || !$sourceConfig) {
            return false;
        }
        
        // 提取目标域名的TDK（保持不变）
        $targetTDK = $targetConfig['tdk'];
        
        // 替换缓存中的TDK为目标域名的TDK
        $newContent = $this->replaceTDKInCache($sourceContent, [
            'replace_keywords' => $targetConfig['replace_keywords'] ?? '',
            'site_title' => $targetTDK['title'],
            'site_keywords' => $targetTDK['keywords'],
            'site_description' => $targetTDK['description']
        ]);
        
        // 确保目标缓存目录存在
        if (!is_dir($targetCacheDir)) {
            mkdir($targetCacheDir, 0755, true);
        }
        
        // 删除旧缓存
        $oldFiles = glob($targetCacheDir . '/*.html');
        foreach ($oldFiles as $oldFile) {
            unlink($oldFile);
        }
        
        // 保存新缓存（使用首页缓存文件名）
        $newCacheFile = $targetCacheDir . '/index.html';
        file_put_contents($newCacheFile, $newContent);
        
        return true;
    }
    
    /**
     * 获取分组统计信息
     */
    public function getGroupStats($groupId) {
        $group = $this->getGroup($groupId);
        if (!$group) {
            return null;
        }
        
        $stats = [
            'total_domains' => count($group['domains']),
            'domains_status' => []
        ];
        
        foreach ($group['domains'] as $domain) {
            $counter = $this->getVisitCounter($domain);
            $triggerVisits = $group['cache_refresh']['trigger_visits'];
            
            $stats['domains_status'][$domain] = [
                'visit_count' => $counter['visit_count'],
                'trigger_visits' => $triggerVisits,
                'progress' => $counter['visit_count'] . '/' . $triggerVisits,
                'last_refresh' => $counter['last_refresh'],
                'total_refreshes' => $counter['total_refreshes'],
                'total_clone_source_switches' => $counter['total_clone_source_switches'] ?? 0,
                'current_source' => $counter['current_cache_source'],
                'current_clone_source' => $counter['current_clone_source'] ?? null
            ];
        }
        
        return $stats;
    }
    
    /**
     * 自动分组生成配置
     * 将域名列表按指定数量分组，每组随机选择词根
     */
    public function autoGroupGenerate($domains, $domainsPerGroup, $roots, $mode = 'independent', $cacheRefreshConfig = null, $cloneSourceSwitchConfig = null) {
        $totalDomains = count($domains);
        $groupsCreated = 0;
        $configsCreated = 0;
        
        // 分组处理
        $chunks = array_chunk($domains, $domainsPerGroup);
        $totalGroups = count($chunks);
        $rootCount = count($roots);
        
        // 打乱词根列表，确保随机性
        shuffle($roots);
        
        // 词根使用计数器（用于均衡分配）
        $rootUsageCount = array_fill_keys($roots, 0);
        
        // 创建词根分配队列（均衡轮询）
        $rootQueue = [];
        if ($totalGroups <= $rootCount) {
            // 小组数量 <= 词根数量：每个小组使用不同的词根
            $rootQueue = array_slice($roots, 0, $totalGroups);
            shuffle($rootQueue); // 打乱顺序，避免固定模式
        } else {
            // 小组数量 > 词根数量：需要重复使用词根，但保证均衡
            $rounds = ceil($totalGroups / $rootCount); // 需要几轮循环
            for ($i = 0; $i < $rounds; $i++) {
                $shuffledRoots = $roots;
                shuffle($shuffledRoots); // 每轮重新打乱
                $rootQueue = array_merge($rootQueue, $shuffledRoots);
            }
            $rootQueue = array_slice($rootQueue, 0, $totalGroups); // 截取所需数量
        }
        
        // 为每个分组分配词根
        foreach ($chunks as $index => $groupDomains) {
            // 使用预分配的词根
            $selectedRoot = $rootQueue[$index];
            $rootUsageCount[$selectedRoot]++;
            
            // 生成分组名称
            $groupName = "自动分组-{$selectedRoot}-" . date('md-His') . "-" . ($index + 1);
            
            // 创建分组（使用传入的配置，默认禁用缓存更新）
            $groupId = $this->createGroup(
                $groupName,
                'root',
                $selectedRoot,
                $groupDomains,
                $cacheRefreshConfig ?: [
                    'enabled' => false,  // 默认禁用
                    'trigger_visits' => 3,
                    'source_strategy' => 'all_random',
                    'reset_counter' => true,
                    'log_updates' => true,
                    'min_cache_size' => 30 * 1024
                ],
                $cloneSourceSwitchConfig
            );
            
            if ($groupId) {
                $groupsCreated++;
                
                // 为每个域名生成配置
                $results = $this->batchGenerateConfigs($groupDomains, 'root', $selectedRoot, $mode, $groupId);
                $configsCreated += count(array_filter($results, function($r) { return $r['success']; }));
            }
        }
        
        // 返回结果包含词根使用统计
        return [
            'groups_created' => $groupsCreated,
            'configs_created' => $configsCreated,
            'total_domains' => $totalDomains,
            'root_usage' => $rootUsageCount  // 新增：词根使用统计
        ];
    }
    
    /**
     * 保存分组配置（新格式）
     * @param string $groupId 分组ID
     * @param array $config 配置数组
     * @return bool
     */
    public function saveGroupConfig($groupId, $config) {
        $groups = $this->getAllGroups();
        $groups[$groupId] = $config;
        
        return file_put_contents(
            $this->groups_file,
            json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        ) !== false;
    }
    
    /**
     * 获取分组配置（新格式）
     * @param string $groupId 分组ID
     * @return array|null
     */
    public function getGroupConfig($groupId) {
        $groups = $this->getAllGroups();
        return $groups[$groupId] ?? null;
    }
    
    /**
     * 删除分组配置
     * @param string $groupId 分组ID
     * @return bool
     */
    public function deleteGroupConfig($groupId) {
        $groups = $this->getAllGroups();
        
        if (!isset($groups[$groupId])) {
            return false;
        }
        
        unset($groups[$groupId]);
        
        return file_put_contents(
            $this->groups_file,
            json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        ) !== false;
    }
    
    /**
     * 🆕 添加域名到分组（自动同步到数据库）
     * 用于动态添加子域名配置时调用
     * 
     * @param string $domain 域名
     * @param string $group_id 分组ID
     * @param array $config 域名配置
     * @param string|null $parent_domain 父域名（子域名时需要）
     * @return bool
     */
    public function addDomainToGroup($domain, $group_id, $config = [], $parent_domain = null) {
        try {
            // 1. 保存到 domains.db
            if ($this->domainsDB) {
                $this->domainsDB->addDomain(
                    $domain,
                    $group_id,
                    $config,
                    $parent_domain
                );
            }
            
            // 2. 增量更新 groups.db（自动 +1 计数）
            if ($this->groupsDB) {
                $domainData = [
                    'domain' => $domain,
                    'parent_domain' => $parent_domain,
                    'clone_url' => $config['clone_url'] ?? '',
                    'title' => $config['title'] ?? '',
                    'keywords' => $config['keywords'] ?? '',
                    'description' => $config['description'] ?? '',
                    'level' => empty($parent_domain) ? 1 : 2,
                    'is_subdomain' => empty($parent_domain) ? 0 : 1,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                $this->groupsDB->addDomainToList($group_id, $domainData);
            }
            
            return true;
        } catch (Exception $e) {
            error_log("DomainGroupManager::addDomainToGroup Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 🆕 从分组中删除域名（自动同步到数据库）
     * 
     * @param string $domain 域名
     * @param string $group_id 分组ID
     * @return bool
     */
    public function removeDomainFromGroup($domain, $group_id) {
        try {
            // 1. 从 domains.db 删除
            if ($this->domainsDB) {
                $this->domainsDB->deleteDomain($domain);
            }
            
            // 2. 从 groups.db 删除（自动 -1 计数）
            if ($this->groupsDB) {
                $this->groupsDB->removeDomainFromList($group_id, $domain);
            }
            
            return true;
        } catch (Exception $e) {
            error_log("DomainGroupManager::removeDomainFromGroup Error: " . $e->getMessage());
            return false;
        }
    }
}

