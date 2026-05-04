<?php
/**
 * 任务执行器（Worker）
 * 在后台执行具体的任务
 * 
 * 使用方式：php TaskWorker.php task_id
 */

// 禁用超时
set_time_limit(0);
ini_set('max_execution_time', 0);

// 获取任务ID
$task_id = $argv[1] ?? null;

if (!$task_id) {
    die("Error: 缺少任务ID\n");
}

// 引入必要的类
require_once __DIR__ . '/TaskQueueManager.php';
require_once __DIR__ . '/DomainConfigManager.php';
require_once __DIR__ . '/DomainGroupManager.php';
require_once __DIR__ . '/GroupsDB.php';
require_once __DIR__ . '/DomainsDB.php';

// 实例化管理器
$taskManager = new TaskQueueManager();
$domainManager = new DomainConfigManager();
$groupManager = new DomainGroupManager();
$groupsDB = getGroupsDB();
$domainsDB = getDomainsDB();

// 获取任务
$task = $taskManager->getTask($task_id);
if (!$task) {
    die("Error: 任务不存在\n");
}

// 记录进程ID
$task['pid'] = getmypid();
$taskManager->log($task_id, "Worker进程启动，PID: " . getmypid());

try {
    // 根据任务类型执行
    switch ($task['type']) {
        case TaskQueueManager::TYPE_BATCH_CREATE_DOMAIN:
            executeBatchCreateDomain($task, $taskManager, $domainManager, $groupManager);
            break;
            
        case TaskQueueManager::TYPE_BATCH_UPDATE_CACHE:
            executeBatchUpdateCache($task, $taskManager);
            break;
            
        case TaskQueueManager::TYPE_CLEAR_ALL_GROUPS:
            executeClearAllGroups($task, $taskManager, $domainManager, $groupManager);
            break;
            
        default:
            throw new Exception("未知的任务类型: " . $task['type']);
    }
    
    // 任务完成
    $taskManager->completeTask($task_id);
    $taskManager->log($task_id, "任务执行完成");
    
} catch (Exception $e) {
    // 任务失败
    $taskManager->completeTask($task_id, $e->getMessage());
    $taskManager->log($task_id, "任务执行失败: " . $e->getMessage());
}

/**
 * 执行批量创建域名任务
 */
function executeBatchCreateDomain($task, $taskManager, $domainManager, $groupManager) {
    global $groupsDB, $domainsDB;
    
    $task_id = $task['id'];
    
    // 🚀 统一处理：从data_file读取数据（轻量级任务）或直接使用data（传统任务）
    if (isset($task['data_file']) && file_exists($task['data_file'])) {
        $data = json_decode(file_get_contents($task['data_file']), true);
    } else {
        $data = $task['data'];
    }
    
    // 🆕 判断是分组创建还是简单域名创建
    if (isset($data['config_mode'])) {
        // 分组创建模式（来自 group_manage_v2.php）
        executeGroupBatchCreate($task, $taskManager, $groupManager, $data);
    } else {
        // 简单域名创建模式（来自 batch_create_api.php）
        executeSimpleBatchCreate($task, $taskManager, $domainManager, $groupManager, $data);
    }
}

/**
 * 执行分组批量创建（支持group_manage_v2.php的复杂逻辑）
 */
function executeGroupBatchCreate($task, $taskManager, $groupManager, $data = null) {
    global $groupsDB, $domainsDB;
    
    $task_id = $task['id'];
    
    // 如果没有传入data，从文件读取
    if ($data === null) {
        if (isset($task['data_file']) && file_exists($task['data_file'])) {
            $data = json_decode(file_get_contents($task['data_file']), true);
        } else {
            $data = $task['data'];
        }
    }
    
    // 提取参数
    $configMode = $data['config_mode'] ?? 'independent';
    $groupName = $data['group_name'] ?? '';
    $domainsInput = $data['domains'] ?? [];
    $domainsPerGroup = $data['domains_per_group'] ?? 5;
    $enabled = $data['enabled'] ?? false;
    $visitCount = $data['visit_count'] ?? 3;
    $rootMode = $data['root_mode'] ?? 'random';
    $fixedRoot = $data['fixed_root'] ?? '';
    $customTitles = $data['custom_titles'] ?? '';
    
    // 🆕 智能分组名称生成：如果用户没有输入分组名称，根据模式自动生成
    if (empty(trim($groupName))) {
        $timestamp = date('md-Hi'); // 月日-时分
        $switchStatus = $enabled ? "切换{$visitCount}次" : "不切换";
        
        switch ($rootMode) {
            case 'fixed':
                $groupName = "固定词根分组-{$timestamp}-{$switchStatus}";
                break;
            case 'custom_title':
                $groupName = "标题列表分组-{$timestamp}-{$switchStatus}";
                break;
            case 'random_title':
                $groupName = "随机标题分组-{$timestamp}-{$switchStatus}";
                break;
            case 'random':
            default:
                $groupName = "随机词根分组-{$timestamp}-{$switchStatus}";
                break;
        }
    }
    
    $taskManager->log($task_id, "开始分组批量创建，配置模式: {$configMode}, 域名数: " . count($domainsInput));
    
    // 泛二级域名配置
    $subdomainConfig = [
        'mode' => $configMode,
        'inherit_tdk_mode' => true,
        'keyword_source' => 'from_parent'
    ];
    
    // 克隆源切换配置
    $cloneSourceSwitchConfig = [
        'enabled' => $enabled,
        'trigger_visits' => $visitCount,
        'reset_counter' => true,
        'log_updates' => true
    ];
    
    // 转换域名列表为对象数组格式
    $domains = [];
    foreach ($domainsInput as $domain) {
        $domains[] = [
            'domain' => $domain,
            'visit_count' => 0,
            'total_clone_source_switches' => 0
        ];
    }
    
    // 将域名列表分组
    $domainChunks = array_chunk($domains, $domainsPerGroup);
    $totalGroups = count($domainChunks);
    
    $taskManager->setTotal($task_id, $totalGroups);
    $taskManager->log($task_id, "将创建 {$totalGroups} 个分组");
    
    // 🍳 多厨师模式：计算当前厨师的起始序号
    $workerNumber = $data['worker_number'] ?? 1;
    $totalWorkers = $data['total_workers'] ?? 1;
    
    if ($totalWorkers > 1) {
        $taskManager->log($task_id, "多厨师模式：我是厨师 {$workerNumber}/{$totalWorkers}");
    }
    
    // 计算全局起始序号
    $allGroups = $groupManager->getAllGroups();
    $existingNumbers = [];
    
    foreach ($allGroups as $groupId => $group) {
        $existingGroupName = $group['name'] ?? '';
        if (preg_match('/-小组-(\d+)(?:-(\d+))?$/', $existingGroupName, $matches)) {
            $lastNumber = isset($matches[2]) && $matches[2] !== '' ? (int)$matches[2] : (int)$matches[1];
            $existingNumbers[] = $lastNumber;
        }
    }
    
    $globalStartNumber = empty($existingNumbers) ? 1 : max($existingNumbers) + 1;
    
    // 🍳 多厨师模式：每个厨师使用不同的序号范围，避免冲突
    // 例如：2000个域名，4个厨师，每个处理500个
    // 厨师1: 序号 1-100
    // 厨师2: 序号 101-200
    // 厨师3: 序号 201-300
    // 厨师4: 序号 301-400
    $domainsPerWorker = ceil(count($domainsInput) / max($totalWorkers, 1));
    $groupsPerWorker = ceil($domainsPerWorker / $domainsPerGroup);
    $startNumber = $globalStartNumber + ($workerNumber - 1) * $groupsPerWorker;
    
    $successCount = 0;
    $failedCount = 0;
    $processedGroups = 0;
    
    // 根据不同模式处理
    if ($rootMode === 'custom_title' && !empty($customTitles)) {
        // 标题列表模式
        $titles = array_filter(array_map('trim', explode("\n", $customTitles)));
        $taskManager->log($task_id, "使用标题列表模式，共 " . count($titles) . " 个标题");
        
        foreach ($domainChunks as $index => $groupDomains) {
            // 检查停止信号
            if ($taskManager->shouldStop($task_id)['should_stop']) {
                $taskManager->log($task_id, "检测到停止信号");
                $taskManager->markAsStopped($task_id);
                return;
            }
            
            $titleIndex = $index % count($titles);
            $title = $titles[$titleIndex];
            $uniqueNumber = $startNumber + $index;
            
            try {
                $result = $groupManager->createGroup(
                    $groupName . "-" . $uniqueNumber,
                    'custom_title',
                    $title,
                    $groupDomains,
                    null,
                    $cloneSourceSwitchConfig,
                    $subdomainConfig
                );
                
                if ($result) {
                    $domainList = array_map(function($d) { return is_array($d) ? $d['domain'] : $d; }, $groupDomains);
                    $groupManager->batchGenerateConfigs($domainList, 'custom_title', $title, $configMode, $result);
                    
                    $successCount++;
                    $taskManager->log($task_id, "✓ 分组 {$uniqueNumber} 创建成功 (" . count($domainList) . " 个域名)");
                } else {
                    $failedCount++;
                    $taskManager->log($task_id, "✗ 分组 {$uniqueNumber} 创建失败");
                }
            } catch (Exception $e) {
                $failedCount++;
                $taskManager->log($task_id, "✗ 分组 {$uniqueNumber} 创建异常: " . $e->getMessage());
            }
            
            $processedGroups++;
            $taskManager->updateProgress($task_id, $processedGroups, $successCount, $failedCount);
            
            // 每创建5个分组休息一下
            if ($processedGroups % 5 === 0) {
                usleep(200000); // 0.2秒
                $taskManager->log($task_id, "进度: {$processedGroups}/{$totalGroups} ({$successCount} 成功, {$failedCount} 失败)");
            }
        }
        
    } elseif ($rootMode === 'fixed' && !empty($fixedRoot)) {
        // 固定词根模式
        $taskManager->log($task_id, "使用固定词根模式，词根: {$fixedRoot}");
        
        foreach ($domainChunks as $index => $groupDomains) {
            if ($taskManager->shouldStop($task_id)['should_stop']) {
                $taskManager->log($task_id, "检测到停止信号");
                $taskManager->markAsStopped($task_id);
                return;
            }
            
            $uniqueNumber = $startNumber + $index;
            
            try {
                $result = $groupManager->createGroup(
                    $groupName . "-" . $uniqueNumber,
                    'root',
                    $fixedRoot,
                    $groupDomains,
                    null,
                    $cloneSourceSwitchConfig,
                    $subdomainConfig
                );
                
                if ($result) {
                    $domainList = array_map(function($d) { return is_array($d) ? $d['domain'] : $d; }, $groupDomains);
                    $groupManager->batchGenerateConfigs($domainList, 'root', $fixedRoot, $configMode, $result);
                    
                    $successCount++;
                    $taskManager->log($task_id, "✓ 分组 {$uniqueNumber} 创建成功");
                } else {
                    $failedCount++;
                    $taskManager->log($task_id, "✗ 分组 {$uniqueNumber} 创建失败");
                }
            } catch (Exception $e) {
                $failedCount++;
                $taskManager->log($task_id, "✗ 分组 {$uniqueNumber} 创建异常: " . $e->getMessage());
            }
            
            $processedGroups++;
            $taskManager->updateProgress($task_id, $processedGroups, $successCount, $failedCount);
            
            if ($processedGroups % 5 === 0) {
                usleep(200000);
                $taskManager->log($task_id, "进度: {$processedGroups}/{$totalGroups}");
            }
        }
        
    } elseif ($rootMode === 'random_title' && !empty($customTitles)) {
        // 随机标题模式 - 按设定分组大小，组内域名使用随机标题
        $titles = array_filter(array_map('trim', explode("\n", $customTitles)));
        $taskManager->log($task_id, "使用随机标题模式，共 " . count($titles) . " 个标题，每组 {$domainsPerGroup} 个域名");
        
        foreach ($domainChunks as $index => $groupDomains) {
            if ($taskManager->shouldStop($task_id)['should_stop']) {
                $taskManager->log($task_id, "检测到停止信号");
                $taskManager->markAsStopped($task_id);
                return;
            }
            
            $uniqueNumber = $startNumber + $index;
            $groupNameWithNumber = $groupName . "-" . $uniqueNumber;
            
            try {
                // 为组内每个域名随机分配标题，但它们属于同一个组
                $domainsWithRandomTitles = [];
                
                foreach ($groupDomains as $domainIndex => $domainData) {
                    $randomTitleIndex = array_rand($titles);
                    $randomTitle = $titles[$randomTitleIndex];
                    
                    // 保存域名和其随机标题的关联
                    $domainsWithRandomTitles[] = [
                        'domain_data' => $domainData,
                        'random_title' => $randomTitle
                    ];
                }
                
                // 创建一个大组，包含设定数量的域名
                $result = $groupManager->createGroup(
                    $groupNameWithNumber,
                    'random_title',  // 使用特殊的标题模式标识
                    '', // 不使用统一标题，每个域名有自己的随机标题
                    $groupDomains,
                    null,
                    $cloneSourceSwitchConfig,
                    $subdomainConfig
                );
                
                if ($result) {
                    // 为组内每个域名生成配置，使用各自的随机标题
                    foreach ($domainsWithRandomTitles as $item) {
                        $domainData = $item['domain_data'];
                        $randomTitle = $item['random_title'];
                        $domainName = is_array($domainData) ? $domainData['domain'] : $domainData;
                        
                        $groupManager->batchGenerateConfigs(
                            [$domainName], 
                            'custom_title', 
                            $randomTitle, 
                            $configMode, 
                            $result
                        );
                    }
                    
                    $successCount++;
                    $taskManager->log($task_id, "✅ 分组 {$uniqueNumber} 创建成功 ({$domainsPerGroup} 个域名，随机标题模式)");
                    
                    // 记录前几个域名的标题示例
                    $sampleTitles = array_slice($domainsWithRandomTitles, 0, 3);
                    foreach ($sampleTitles as $sample) {
                        $domainName = is_array($sample['domain_data']) ? $sample['domain_data']['domain'] : $sample['domain_data'];
                        $titlePreview = mb_substr($sample['random_title'], 0, 20);
                        $taskManager->log($task_id, "   └─ {$domainName}: {$titlePreview}...");
                    }
                    
                    if (count($domainsWithRandomTitles) > 3) {
                        $remaining = count($domainsWithRandomTitles) - 3;
                        $taskManager->log($task_id, "   └─ ...还有 {$remaining} 个域名使用随机标题");
                    }
                    
                } else {
                    $failedCount++;
                    $taskManager->log($task_id, "❌ 分组 {$uniqueNumber} 创建失败");
                }
                
            } catch (Exception $e) {
                $failedCount++;
                $taskManager->log($task_id, "❌ 分组 {$uniqueNumber} 创建异常: " . $e->getMessage());
            }
            
            $processedGroups++;
            $taskManager->updateProgress($task_id, $processedGroups, $successCount, $failedCount);
            
            if ($processedGroups % 5 === 0) {
                usleep(200000);
                $taskManager->log($task_id, "进度: {$processedGroups}/{$totalGroups} (随机标题模式)");
            }
        }
        
    } else {
        // 随机词根模式
        $baseDir = dirname(__DIR__);
        $rootsFile = $baseDir . '/data/data_key/统计词根.txt';
        $roots = [];
        
        if (file_exists($rootsFile)) {
            $allRoots = file($rootsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $roots = array_unique(array_filter(array_map('trim', $allRoots)));
        }
        
        if (empty($roots)) {
            throw new Exception("随机词根模式失败：词根文件不存在或为空");
        }
        
        $taskManager->log($task_id, "使用随机词根模式，词根库: " . count($roots) . " 个");
        
        foreach ($domainChunks as $index => $groupDomains) {
            if ($taskManager->shouldStop($task_id)['should_stop']) {
                $taskManager->log($task_id, "检测到停止信号");
                $taskManager->markAsStopped($task_id);
                return;
            }
            
            $randomRoot = $roots[array_rand($roots)];
            $uniqueNumber = $startNumber + $index;
            
            try {
                $result = $groupManager->createGroup(
                    $groupName . "-" . $uniqueNumber,
                    'root',
                    $randomRoot,
                    $groupDomains,
                    null,
                    $cloneSourceSwitchConfig,
                    $subdomainConfig
                );
                
                if ($result) {
                    $domainList = array_map(function($d) { return is_array($d) ? $d['domain'] : $d; }, $groupDomains);
                    $groupManager->batchGenerateConfigs($domainList, 'root', $randomRoot, $configMode, $result);
                    
                    $successCount++;
                    $taskManager->log($task_id, "✓ 分组 {$uniqueNumber} 创建成功 (词根: {$randomRoot})");
                } else {
                    $failedCount++;
                    $taskManager->log($task_id, "✗ 分组 {$uniqueNumber} 创建失败");
                }
            } catch (Exception $e) {
                $failedCount++;
                $taskManager->log($task_id, "✗ 分组 {$uniqueNumber} 创建异常: " . $e->getMessage());
            }
            
            $processedGroups++;
            $taskManager->updateProgress($task_id, $processedGroups, $successCount, $failedCount);
            
            if ($processedGroups % 5 === 0) {
                usleep(200000);
                $taskManager->log($task_id, "进度: {$processedGroups}/{$totalGroups}");
            }
        }
    }
    
    $taskManager->log($task_id, "分组批量创建完成！共 {$totalGroups} 个分组，成功: {$successCount}, 失败: {$failedCount}");
}

/**
 * 执行简单批量创建（原有逻辑）
 */
function executeSimpleBatchCreate($task, $taskManager, $domainManager, $groupManager, $data = null) {
    global $groupsDB, $domainsDB;
    
    $task_id = $task['id'];
    
    // 如果没有传入data，从任务中获取
    if ($data === null) {
        if (isset($task['data_file']) && file_exists($task['data_file'])) {
            $data = json_decode(file_get_contents($task['data_file']), true);
        } else {
            $data = $task['data'];
        }
    }
    
    // 获取任务参数
    $group_id = $data['group_id'] ?? null;
    $group_config = $data['group_config'] ?? [];
    $domains = $data['domains'] ?? [];
    
    $total = count($domains);
    $taskManager->setTotal($task_id, $total);
    $taskManager->log($task_id, "开始批量创建域名，总数: {$total}");
    
    $processed = 0;
    $success = 0;
    $failed = 0;
    
    foreach ($domains as $domain) {
        // 检查是否应该停止
        $stop_status = $taskManager->shouldStop($task_id);
        if ($stop_status['should_stop']) {
            $taskManager->log($task_id, "检测到停止信号，停止执行");
            
            // 如果需要回滚
            if ($stop_status['rollback']) {
                $taskManager->log($task_id, "开始回滚已创建的数据...");
                $rollback_result = $taskManager->rollbackTask($task_id);
                $taskManager->log($task_id, "回滚完成: 删除 {$rollback_result['deleted']} 个，失败 {$rollback_result['failed']} 个");
            }
            
            $taskManager->markAsStopped($task_id);
            return;
        }
        
        try {
            // 创建域名配置
            $config = array_merge($group_config, ['domain' => $domain]);
            
            // 保存配置到JSON文件
            $domainManager->saveConfig($domain, $config);
            
            // 🆕 保存到域名数据库
            if ($group_id && $domainsDB) {
                $domainsDB->addDomain($domain, $group_id, $config);
            }
            
            $success++;
            
            // 记录已创建的项目（用于回滚）
            $created_item = ['domain' => $domain, 'time' => date('Y-m-d H:i:s')];
            
            $taskManager->log($task_id, "✓ 成功: {$domain}");
            
        } catch (Exception $e) {
            $failed++;
            $created_item = null;
            $taskManager->log($task_id, "✗ 失败: {$domain} - " . $e->getMessage());
        }
        
        $processed++;
        
        // 更新进度
        $taskManager->updateProgress($task_id, $processed, $success, $failed, $created_item);
        
        // 每处理10个域名休息一下，避免系统负载过高
        if ($processed % 10 === 0) {
            usleep(100000); // 0.1秒
            $taskManager->log($task_id, "进度: {$processed}/{$total} ({$success} 成功, {$failed} 失败)");
        }
    }
    
    // 🆕 更新分组的域名列表（预处理数据）
    if ($group_id && $groupsDB && $domainsDB) {
        // 从 domains.db 获取该分组的所有域名
        $allDomains = $domainsDB->getDomainsByGroup($group_id);
        // 更新 groups.db 的 domain_list_json 字段
        $groupsDB->updateDomainList($group_id, $allDomains);
        $taskManager->log($task_id, "已更新分组的预处理数据: {$success} 个域名");
    }
    
    $taskManager->log($task_id, "批量创建完成！总计: {$total}, 成功: {$success}, 失败: {$failed}");
}

/**
 * 执行批量更新缓存任务
 */
function executeBatchUpdateCache($task, $taskManager) {
    $task_id = $task['id'];
    $data = $task['data'];
    
    $domains = $data['domains'] ?? [];
    $total = count($domains);
    
    $taskManager->setTotal($task_id, $total);
    $taskManager->log($task_id, "开始批量更新缓存，总数: {$total}");
    
    $processed = 0;
    $success = 0;
    $failed = 0;
    
    $base_dir = dirname(__DIR__);
    $cache_dir = $base_dir . '/cachefile_yuan';
    
    foreach ($domains as $domain) {
        // 检查是否应该停止
        $stop_status = $taskManager->shouldStop($task_id);
        if ($stop_status['should_stop']) {
            $taskManager->log($task_id, "检测到停止信号，停止执行");
            $taskManager->markAsStopped($task_id);
            return;
        }
        
        try {
            // 删除域名的缓存目录
            $domain_cache_dir = $cache_dir . '/' . $domain;
            if (is_dir($domain_cache_dir)) {
                deleteDirectory($domain_cache_dir);
                $success++;
                $taskManager->log($task_id, "✓ 清理缓存: {$domain}");
            } else {
                $success++;
                $taskManager->log($task_id, "- 无缓存: {$domain}");
            }
            
        } catch (Exception $e) {
            $failed++;
            $taskManager->log($task_id, "✗ 失败: {$domain} - " . $e->getMessage());
        }
        
        $processed++;
        $taskManager->updateProgress($task_id, $processed, $success, $failed);
        
        if ($processed % 10 === 0) {
            usleep(50000); // 0.05秒
            $taskManager->log($task_id, "进度: {$processed}/{$total}");
        }
    }
    
    $taskManager->log($task_id, "批量更新缓存完成！总计: {$total}, 成功: {$success}, 失败: {$failed}");
}

/**
 * 执行清空所有分组任务
 */
function executeClearAllGroups($task, $taskManager, $domainManager, $groupManager) {
    global $groupsDB, $domainsDB;
    
    $task_id = $task['id'];
    $taskManager->log($task_id, "开始执行清空所有分组任务");
    
    // 获取任务数据
    $data = $task['data'] ?? [];
    
    $deletedGroups = 0;
    $deletedDomains = 0;
    $deletedFiles = 0;
    $errors = [];
    
    try {
        // 步骤1: 获取所有分组信息（用于统计）
        $taskManager->log($task_id, "正在获取所有分组信息...");
        $allGroups = $groupManager->getAllGroups();
        $totalGroups = count($allGroups);
        
        $taskManager->log($task_id, "发现 {$totalGroups} 个分组需要清理");
        $taskManager->setTotal($task_id, 4); // 4个主要步骤
        
        // 步骤2: 清空配置文件目录
        $taskManager->log($task_id, "步骤 1/4: 清空域名配置文件...");
        $domainConfigDir = dirname(dirname(__DIR__)) . '/data/domain';
        
        if (is_dir($domainConfigDir)) {
            $files = glob($domainConfigDir . '/*.{json,txt}', GLOB_BRACE);
            $totalFiles = count($files);
            $taskManager->log($task_id, "发现 {$totalFiles} 个配置文件");
            
            if ($totalFiles > 0) {
                $batchSize = 50; // 每批处理50个文件
                for ($i = 0; $i < $totalFiles; $i += $batchSize) {
                    // 检查停止信号
                    if ($taskManager->shouldStop($task_id)['should_stop']) {
                        $taskManager->log($task_id, "在文件删除过程中检测到停止信号");
                        $taskManager->markAsStopped($task_id);
                        return;
                    }
                    
                    $batch = array_slice($files, $i, $batchSize);
                    
                    foreach ($batch as $file) {
                        try {
                            if (file_exists($file) && unlink($file)) {
                                $deletedFiles++;
                            } else {
                                $errors[] = "删除配置文件失败: " . basename($file) . " (文件不存在或无权限)";
                                $taskManager->log($task_id, "⚠️  删除失败: " . basename($file));
                            }
                        } catch (Exception $e) {
                            $errors[] = "删除配置文件异常: " . basename($file) . " - " . $e->getMessage();
                            $taskManager->log($task_id, "❌ 删除异常: " . basename($file) . " - " . $e->getMessage());
                        }
                    }
                    
                    $progress = min(100, round(($i + $batchSize) / $totalFiles * 100));
                    $taskManager->log($task_id, "配置文件清理进度: {$progress}% ({$deletedFiles}/{$totalFiles})");
                    
                    // 短暂休息，避免系统负载过高
                    usleep(10000); // 0.01秒，减少等待时间
                }
                
                // 记录最终删除结果
                $taskManager->log($task_id, "✅ 配置文件删除完成: {$deletedFiles}/{$totalFiles} 个文件");
            } else {
                $taskManager->log($task_id, "✅ 没有需要删除的配置文件");
            }
        } else {
            $taskManager->log($task_id, "⚠️  配置文件目录不存在: {$domainConfigDir}");
        }
        
        $deletedDomains = $deletedFiles;
        
        // 根据实际删除结果更新进度
        if ($deletedFiles == 0 && $totalFiles == 0) {
            // 没有文件需要删除，视为成功
            $taskManager->updateProgress($task_id, 1, 1, 0);
        } elseif ($deletedFiles == $totalFiles) {
            // 全部删除成功
            $taskManager->updateProgress($task_id, 1, 1, 0);
        } else {
            // 部分删除失败
            $failedFiles = $totalFiles - $deletedFiles;
            $taskManager->updateProgress($task_id, 1, $deletedFiles > 0 ? 1 : 0, $failedFiles > 0 ? 1 : 0);
            $taskManager->log($task_id, "⚠️  步骤1部分失败: 成功{$deletedFiles}个，失败{$failedFiles}个");
        }
        
        // 检查停止信号
        if ($taskManager->shouldStop($task_id)['should_stop']) {
            $taskManager->log($task_id, "在数据库清空前检测到停止信号");
            $taskManager->markAsStopped($task_id);
            return;
        }
        
        // 步骤3: 清空数据库
        $taskManager->log($task_id, "步骤 2/4: 清空域名数据库...");
        if ($domainsDB && $domainsDB->clearAll()) {
            $taskManager->log($task_id, "域名数据库清空成功");
        } else {
            $errors[] = "域名数据库清空失败";
        }
        $taskManager->updateProgress($task_id, 2, 2, 0);
        
        // 检查停止信号
        if ($taskManager->shouldStop($task_id)['should_stop']) {
            $taskManager->log($task_id, "在分组数据库清空前检测到停止信号");
            $taskManager->markAsStopped($task_id);
            return;
        }
        
        $taskManager->log($task_id, "步骤 3/4: 清空分组数据库...");
        if ($groupsDB && $groupsDB->clearAll()) {
            $deletedGroups = $totalGroups;
            $taskManager->log($task_id, "分组数据库清空成功");
        } else {
            $errors[] = "分组数据库清空失败";
        }
        $taskManager->updateProgress($task_id, 3, 3, 0);
        
        // 检查停止信号
        if ($taskManager->shouldStop($task_id)['should_stop']) {
            $taskManager->log($task_id, "在groups.json清空前检测到停止信号");
            $taskManager->markAsStopped($task_id);
            return;
        }
        
        // 步骤4: 清空 groups.json 文件
        $taskManager->log($task_id, "步骤 4/4: 清空分组配置文件...");
        $step4Success = false;
        try {
            $groupsJsonFile = dirname(dirname(__DIR__)) . '/data/domain_groups/groups.json';
            
            // 检查停止信号
            if ($taskManager->shouldStop($task_id)['should_stop']) {
                $taskManager->log($task_id, "在清空分组配置文件前检测到停止信号");
                $taskManager->markAsStopped($task_id);
                return;
            }
            
            $result = file_put_contents($groupsJsonFile, json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            if ($result !== false) {
                $taskManager->log($task_id, "✅ 分组配置文件清空成功 (groups.json)");
                $step4Success = true;
            } else {
                $errors[] = "分组配置文件清空失败：写入文件返回false";
                $taskManager->log($task_id, "❌ groups.json 写入失败");
            }
        } catch (Exception $e) {
            $errors[] = "清空 groups.json 失败: " . $e->getMessage();
            $taskManager->log($task_id, "❌ 清空 groups.json 异常: " . $e->getMessage());
        }
        
        // 根据第4步结果更新进度
        if ($step4Success) {
            $taskManager->updateProgress($task_id, 4, 4, 0);
        } else {
            $taskManager->updateProgress($task_id, 4, 3, 1);
        }
        
        // 任务完成总结
        $taskManager->log($task_id, "========== 清空完成 ==========");
        $taskManager->log($task_id, "✅ 已删除 {$deletedGroups} 个分组");
        $taskManager->log($task_id, "✅ 已删除 {$deletedDomains} 个配置文件");
        $taskManager->log($task_id, "✅ 已清空所有数据库记录");
        
        if (!empty($errors)) {
            $taskManager->log($task_id, "⚠️  部分操作出现错误：");
            foreach ($errors as $error) {
                $taskManager->log($task_id, "  - " . $error);
            }
            // 如果有错误，标记任务为部分失败
            $errorMessage = count($errors) . " 个操作失败: " . implode('; ', array_slice($errors, 0, 3));
            $taskManager->completeTask($task_id, $errorMessage);
        } else {
            // 无错误，完美完成
            $taskManager->log($task_id, "🎉 所有操作均成功完成！");
            $taskManager->completeTask($task_id);
        }
        
    } catch (Exception $e) {
        $taskManager->log($task_id, "❌ 清空任务执行失败: " . $e->getMessage());
        throw $e;
    }
}

/**
 * 递归删除目录
 */
function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }
    
    return rmdir($dir);
}
