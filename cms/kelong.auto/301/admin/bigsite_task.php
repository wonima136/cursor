<?php
/**
 * 大站池任务详情
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bigsite_task_functions.php';
require_once __DIR__ . '/redis_config.php';

// 处理CSV模板下载
if (isset($_GET['action']) && $_GET['action'] === 'download_csv_template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bigsite_template.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    echo "跳转源URL,跳转对象URL,跳转次数,跳转类型\n";
    echo "example.com/page1.html,bigsite.com/target1.html,100,301\n";
    echo "example.com/page2.html,bigsite.com/target2.html,50,302\n";
    echo "example.com/page3.html,bigsite.com/target3.html,200,\n";
    echo "# 第4列为空时，使用任务默认设置\n";
    exit;
}

$taskId = $_GET['id'] ?? '';
if (empty($taskId)) {
    header('Location: bigsite_tasks.php');
    exit;
}

$task = _bigsiteTask_getById($taskId);
if (!$task) {
    header('Location: bigsite_tasks.php');
    exit;
}

// 处理导出CSV请求（GET方式）
if (isset($_GET['ajax']) && $_GET['action'] === 'export_rules') {
    require_once __DIR__ . '/redis_config.php';
    $rules = getAllActiveBigsiteRules($taskId);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bigsite_rules_' . $taskId . '_' . date('YmdHis') . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    
    // CSV表头
    echo "跳转源URL,跳转对象URL,跳转次数,已使用次数,跳转类型,状态,创建时间\n";
    
    foreach ($rules as $rule) {
        $sourceUrl = $rule['source_url'] ?? $rule['source'] ?? '';
        $targetUrl = $rule['target_url'] ?? $rule['target'] ?? '';
        $redirectCount = $rule['redirect_count'] ?? 0;
        $usedCount = $rule['used_count'] ?? 0;
        $redirectType = $rule['redirect_type'] ?? '302';
        $status = ($usedCount >= $redirectCount) ? '已完成' : '进行中';
        $createdAt = $rule['created_at'] ?? '';
        
        echo sprintf(
            "%s,%s,%d,%d,%s,%s,%s\n",
            $sourceUrl,
            $targetUrl,
            $redirectCount,
            $usedCount,
            $redirectType,
            $status,
            $createdAt
        );
    }
    exit;
}

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    // 检查登录
    if (!checkLogin()) {
        echo json_encode(['success' => false, 'message' => '未登录']);
        exit;
    }
    
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => '未知操作'];
    
    switch ($action) {
        case 'save_settings':
            $name = trim($_POST['name'] ?? '');
            $redirectType = in_array($_POST['redirect_type'] ?? '302', ['301', '302']) ? $_POST['redirect_type'] : '302';
            $probability = max(0, min(100, intval($_POST['probability'] ?? 100))); // ⭐ 新增：概率控制（0-100）
            
            if (empty($name)) {
                $response = ['success' => false, 'message' => '请输入任务名称'];
            } else {
                // ⭐ 修复：保留原有的 smart_strategy，避免被覆盖
                $currentTask = _bigsiteTask_getById($taskId);
                $smartStrategy = $currentTask['smart_strategy'] ?? $currentTask['settings']['smart_strategy'] ?? null;
                
                $updateData = [
                    'name' => $name,
                    'settings' => [
                        'redirect_type' => $redirectType,
                        'probability' => $probability,
                    ]
                ];
                
                // 如果存在智能策略，保留它
                if ($smartStrategy !== null) {
                    $updateData['smart_strategy'] = $smartStrategy;
                }
                
                _bigsiteTask_update($taskId, $updateData);
                
                // ⭐ 修复：批量更新所有规则的跳转类型
                require_once __DIR__ . '/redis_config.php';
                $redis = getRedis();
                if ($redis) {
                    $prefix = REDIS_PREFIX;
                    $ruleIds = $redis->sMembers("{$prefix}bigsite:task:{$taskId}:rules");
                    foreach ($ruleIds as $ruleId) {
                        $ruleKey = "{$prefix}bigsite:task:{$taskId}:rule:{$ruleId}";
                        $redis->hSet($ruleKey, 'redirect_type', $redirectType);
                    }
                }
                
                $response = ['success' => true, 'message' => '设置已保存'];
            }
            break;
            
        case 'save_smart_strategy':
            // ⭐ 新增：保存智能概率策略
            $enabled = !empty($_POST['enabled']);
            $rules = [
                '0' => max(0, min(100, intval($_POST['rule_0'] ?? 100))),
                '1' => max(0, min(100, intval($_POST['rule_1'] ?? 50))),
                '2' => max(0, min(100, intval($_POST['rule_2'] ?? 30))),
                '3' => max(0, min(100, intval($_POST['rule_3'] ?? 20))),
                '4' => max(0, min(100, intval($_POST['rule_4'] ?? 15))),
                '5+' => max(0, min(100, intval($_POST['rule_5plus'] ?? 10))),
            ];
            
            _bigsiteTask_update($taskId, [
                'settings' => [
                    'smart_strategy' => [
                        'enabled' => $enabled,
                        'rules' => $rules
                    ]
                ]
            ]);
            
            // 立即同步到Redis
            $task = _bigsiteTask_getById($taskId);
            _bigsiteTask_syncToRedis($task);
            
            $response = ['success' => true, 'message' => '智能策略已保存'];
            break;
            
        case 'batch_add_rules':
            $text = trim($_POST['text'] ?? '');
            $defaultCount = max(1, intval($_POST['default_count'] ?? 1));
            $redirectType = in_array($_POST['redirect_type'] ?? '302', ['301', '302']) ? $_POST['redirect_type'] : '302';
            $overwrite = !empty($_POST['overwrite']); // 是否覆盖已存在的规则
            
            if (empty($text)) {
                $response = ['success' => false, 'message' => '请输入链接'];
            } else {
                $result = _bigsiteTask_batchAddRules($taskId, $text, $defaultCount, $redirectType, $overwrite);
                $response = $result;
            }
            break;
            
        case 'check_existing_rules':
            // 检查哪些规则已存在
            $text = trim($_POST['text'] ?? '');
            if (empty($text)) {
                $response = ['success' => false, 'message' => '请输入链接'];
            } else {
                $result = _bigsiteTask_checkExistingRules($text);
                $response = $result;
            }
            break;
            
        case 'delete_rule':
            $sourceUrl = trim($_POST['source_url'] ?? '');
            if (deleteBigsiteRuleFromRedis($sourceUrl, $taskId)) {
                _bigsiteTask_updateStats($taskId);
                $response = ['success' => true, 'message' => '规则已删除'];
            } else {
                $response = ['success' => false, 'message' => '删除失败'];
            }
            break;
            
        case 'clear_rules':
            // ⭐ 修复：只删除规则，保留任务状态
            require_once __DIR__ . '/redis_config.php';
            $redis = getRedis();
            if ($redis) {
                $prefix = REDIS_PREFIX;
                // 只删除规则相关的键，保留config和stats
                $ruleKeys = $redis->keys("{$prefix}bigsite:task:{$taskId}:rule:*");
                if (!empty($ruleKeys)) {
                    $redis->del($ruleKeys);
                }
            }
            _bigsiteTask_updateStats($taskId);
            $response = ['success' => true, 'message' => '所有规则已清空'];
            break;
            
        case 'import_csv':
            // CSV文件导入
            if (empty($_FILES['csv_file'])) {
                $response = ['success' => false, 'message' => '未上传文件'];
                break;
            }
            
            $file = $_FILES['csv_file'];
            $tmpPath = $file['tmp_name'];
            
            // 检查文件类型
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'txt'])) {
                $response = ['success' => false, 'message' => '只支持CSV或TXT文件'];
                break;
            }
            
            // ⭐ 修复问题5：设置超时时间（5分钟）
            set_time_limit(300);
            
            // 读取CSV文件
            $handle = fopen($tmpPath, 'r');
            if (!$handle) {
                $response = ['success' => false, 'message' => '文件读取失败'];
                break;
            }
            
            $added = 0;
            $skipped = 0;
            $overwritten = 0; // ⭐ 修复问题1：添加覆盖计数
            $failed = 0;
            $errors = [];
            $lineNum = 0;
            
            // ⭐ 修复问题1：获取覆盖模式参数
            $overwrite = !empty($_POST['overwrite']);
            
            // ⭐ 修复问题2：获取任务默认跳转类型
            $defaultRedirectType = $task['settings']['redirect_type'] ?? '302';
            
            // 检查第一行是否为表头
            $firstLine = fgets($handle);
            $lineNum++;
            if (strpos($firstLine, '跳转源') !== false || strpos($firstLine, 'source') !== false) {
                // 是表头，跳过
            } else {
                // 不是表头，回退处理
                rewind($handle);
                $lineNum = 0;
            }
            
            // 逐行解析
            while (($line = fgets($handle)) !== false) {
                $lineNum++;
                $line = trim($line);
                
                // 跳过空行和注释
                if (empty($line) || strpos($line, '#') === 0) {
                    continue;
                }
                
                // ⭐ 修复问题6：使用更严格的CSV解析
                $parts = str_getcsv($line, ',', '"', '\\');
                
                if (count($parts) < 1) {
                    $errors[] = "第{$lineNum}行：格式错误";
                    $failed++;
                    continue;
                }
                
                $sourceUrl = trim($parts[0]);
                $targetUrl = isset($parts[1]) ? trim($parts[1]) : '';
                $redirectCount = isset($parts[2]) ? intval(trim($parts[2])) : 100;
                // ⭐ 新增：第4列为跳转类型（301/302）
                $redirectType = isset($parts[3]) ? trim($parts[3]) : '';
                
                // 验证数据
                if (empty($sourceUrl)) {
                    $errors[] = "第{$lineNum}行：跳转源URL为空";
                    $failed++;
                    continue;
                }
                
                if ($redirectCount < 1) {
                    $redirectCount = 100;
                }
                
                // ⭐ 新增：验证跳转类型，为空则使用任务默认值
                if (empty($redirectType) || !in_array($redirectType, ['301', '302'])) {
                    $redirectType = $defaultRedirectType;
                }
                
                // ⭐ 修复问题1：检查是否已存在
                $exists = isBigsiteRuleActiveInRedis($sourceUrl, $taskId);
                
                if ($exists && !$overwrite) {
                    // 已存在且不覆盖，跳过
                    $skipped++;
                    continue;
                }
                
                // 如果没有指定目标URL，从大站池随机选择
                if (empty($targetUrl)) {
                    $sites = getAllBigsiteSitesFromRedis($taskId);
                    if (!empty($sites)) {
                        $targetUrl = $sites[array_rand($sites)];
                    }
                }
                
                // 如果还是没有目标URL，跳过
                if (empty($targetUrl)) {
                    $errors[] = "第{$lineNum}行：无目标URL且大站池为空";
                    $failed++;
                    continue;
                }
                
                // ⭐ 修复问题1：覆盖模式处理
                if ($exists && $overwrite) {
                    // 先删除旧规则
                    deleteBigsiteRuleFromRedis($sourceUrl, $taskId);
                }
                
                // ⭐ 修复问题2：使用验证后的跳转类型
                if (addBigsiteRuleToRedis($sourceUrl, $targetUrl, $redirectCount, $redirectType, $task['name'], $taskId)) {
                    if ($exists && $overwrite) {
                        $overwritten++;
                    } else {
                        $added++;
                    }
                } else {
                    $errors[] = "第{$lineNum}行：写入Redis失败";
                    $failed++;
                }
            }
            
            fclose($handle);
            
            // ⭐ 修复问题4：删除临时文件
            @unlink($tmpPath);
            
            // 更新任务统计
            _bigsiteTask_updateStats($taskId);
            
            // ⭐ 修复问题3：增加错误信息数量并显示总数
            $response = [
                'success' => true,
                'added' => $added,
                'skipped' => $skipped,
                'overwritten' => $overwritten, // ⭐ 新增：覆盖数量
                'failed' => $failed,
                'errors' => array_slice($errors, 0, 20), // 增加到20条
                'total_errors' => count($errors), // ⭐ 新增：总错误数
                'message' => "导入完成！成功：{$added}，覆盖：{$overwritten}，跳过：{$skipped}，失败：{$failed}"
            ];
            break;
            
        case 'add_site':
            $url = trim($_POST['url'] ?? '');
            if (empty($url)) {
                $response = ['success' => false, 'message' => '请输入URL'];
            } else {
                addBigsiteSiteToRedis($url, $taskId);
                $response = ['success' => true, 'message' => 'URL已添加'];
            }
            break;
            
        case 'batch_add_sites':
            $text = trim($_POST['text'] ?? '');
            if (empty($text)) {
                $response = ['success' => false, 'message' => '请输入URL'];
            } else {
                $added = 0;
                foreach (explode("\n", $text) as $line) {
                    $line = trim($line);
                    if (empty($line) || strpos($line, '#') === 0) continue;
                    
                    $url = trim(explode(',', $line)[0]);
                    if (!empty($url)) {
                        addBigsiteSiteToRedis($url, $taskId);
                        $added++;
                    }
                }
                $response = ['success' => true, 'message' => "成功添加 {$added} 个URL"];
            }
            break;
            
        case 'delete_site':
            $url = trim($_POST['url'] ?? '');
            deleteBigsiteSiteFromRedis($url, $taskId);
            $response = ['success' => true, 'message' => 'URL已删除'];
            break;
            
        case 'clear_sites':
            clearBigsiteSitesFromRedis($taskId);
            $response = ['success' => true, 'message' => '所有URL已清空'];
            break;
    }
    
    echo json_encode($response);
    exit;
}

// 获取任务的所有规则
$taskRules = getAllActiveBigsiteRules($taskId);

// 获取大站URL列表
$sites = getAllBigsiteSitesFromRedis($taskId);

// 更新统计
$task['stats'] = getBigsiteTaskStatsFromRedis($taskId);

$pageTitle = htmlspecialchars($task['name']) . ' - 大站池任务';
require_once __DIR__ . '/header.php';
?>

<div class="page-header">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <div style="display: flex; align-items: center; gap: 12px;">
                <a href="bigsite_tasks.php" class="btn btn-secondary btn-sm">← 返回</a>
                <h1 class="page-title"><?php echo htmlspecialchars($task['name']); ?></h1>
                <span class="badge <?php echo $task['enabled'] ? 'badge-success' : 'badge-warning'; ?>">
                    <?php echo $task['enabled'] ? '已启用' : '已禁用'; ?>
                </span>
            </div>
            <p class="page-subtitle">管理任务规则和配置</p>
        </div>
        <div>
            <?php require_once __DIR__ . '/help_modal.php'; renderHelpModal('bigsite_task'); ?>
        </div>
    </div>
</div>

<!-- 统计卡片 -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon primary">🔗</div>
        <div class="stat-content">
            <h3><?php echo $task['stats']['total_rules']; ?></h3>
            <p>总规则数</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success">✅</div>
        <div class="stat-content">
            <h3><?php echo $task['stats']['completed_rules']; ?></h3>
            <p>已完成</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning">⏳</div>
        <div class="stat-content">
            <h3><?php echo $task['stats']['active_rules']; ?></h3>
            <p>活跃中</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon info">📊</div>
        <div class="stat-content">
            <h3><?php echo $task['stats']['total_redirects']; ?></h3>
            <p>总跳转次数</p>
        </div>
    </div>
</div>

<!-- 基本设置 -->
<div class="card">
    <div class="card-header">
        <span class="card-title">⚙️ 基本设置</span>
    </div>
    <div class="card-body">
        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 16px; align-items: end;">
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">任务名称</label>
                <input type="text" id="taskName" class="form-input" value="<?php echo htmlspecialchars($task['name']); ?>">
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">跳转方式</label>
                <select id="redirectType" class="form-input">
                    <option value="302" <?php echo ($task['settings']['redirect_type'] ?? '302') === '302' ? 'selected' : ''; ?>>302 临时重定向</option>
                    <option value="301" <?php echo ($task['settings']['redirect_type'] ?? '302') === '301' ? 'selected' : ''; ?>>301 永久重定向</option>
                </select>
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">跳转概率 (%)</label>
                <input type="number" id="probability" class="form-input" min="0" max="100" value="<?php echo intval($task['settings']['probability'] ?? 100); ?>" style="text-align: center;">
            </div>
            
            <button class="btn btn-primary" onclick="saveSettings()" style="height: 38px; white-space: nowrap;">💾 保存设置</button>
        </div>
    </div>
</div>

<!-- 智能概率策略. -->
<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <span class="card-title">⚡ 智能概率策略</span>
            <label style="display: flex; align-items: center; cursor: pointer; margin: 0;">
                <?php 
                // ⭐ 修复：兼容两种存储位置
                $smartStrategy = $task['smart_strategy'] ?? $task['settings']['smart_strategy'] ?? ['enabled' => false];
                ?>
                <input type="checkbox" id="smartStrategyEnabled" <?php echo !empty($smartStrategy['enabled']) ? 'checked' : ''; ?> onchange="toggleSmartStrategy()" style="margin-right: 8px;">
                <span style="font-size: 14px; font-weight: normal;">启用智能概率调整（启用后将替代上方的固定概率）</span>
            </label>
        </div>
        <div class="form-hint" style="margin: 0;">💡 根据每条规则的跳转次数自动调整概率，新链接高概率，老链接低概率</div>
    </div>
    <div class="card-body">
        <div id="smartStrategyRules" style="<?php echo empty($smartStrategy['enabled']) ? 'display: none;' : ''; ?>">
            <div style="display: flex; gap: 12px; align-items: end;">
                <?php 
                $strategyRules = $smartStrategy['rules'] ?? [
                    '0' => 100,
                    '1' => 50,
                    '2' => 30,
                    '3' => 20,
                    '4' => 15,
                    '5+' => 10
                ];
                ?>
                <div class="form-group" style="margin-bottom: 0; flex: 1;">
                    <label class="form-label">0次跳转</label>
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <input type="number" id="rule_0" class="form-input" min="0" max="100" value="<?php echo intval($strategyRules['0'] ?? 100); ?>" style="text-align: center;">
                        <span style="color: #94a3b8; font-size: 14px;">%</span>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom: 0; flex: 1;">
                    <label class="form-label">1次跳转</label>
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <input type="number" id="rule_1" class="form-input" min="0" max="100" value="<?php echo intval($strategyRules['1'] ?? 50); ?>" style="text-align: center;">
                        <span style="color: #94a3b8; font-size: 14px;">%</span>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom: 0; flex: 1;">
                    <label class="form-label">2次跳转</label>
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <input type="number" id="rule_2" class="form-input" min="0" max="100" value="<?php echo intval($strategyRules['2'] ?? 30); ?>" style="text-align: center;">
                        <span style="color: #94a3b8; font-size: 14px;">%</span>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom: 0; flex: 1;">
                    <label class="form-label">3次跳转</label>
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <input type="number" id="rule_3" class="form-input" min="0" max="100" value="<?php echo intval($strategyRules['3'] ?? 20); ?>" style="text-align: center;">
                        <span style="color: #94a3b8; font-size: 14px;">%</span>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom: 0; flex: 1;">
                    <label class="form-label">4次跳转</label>
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <input type="number" id="rule_4" class="form-input" min="0" max="100" value="<?php echo intval($strategyRules['4'] ?? 15); ?>" style="text-align: center;">
                        <span style="color: #94a3b8; font-size: 14px;">%</span>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom: 0; flex: 1;">
                    <label class="form-label">5次及以上</label>
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <input type="number" id="rule_5plus" class="form-input" min="0" max="100" value="<?php echo intval($strategyRules['5+'] ?? 10); ?>" style="text-align: center;">
                        <span style="color: #94a3b8; font-size: 14px;">%</span>
                    </div>
                </div>
                
                <button class="btn btn-primary" onclick="saveSmartStrategy()" style="height: 38px; white-space: nowrap; flex-shrink: 0;">💾 保存策略</button>
            </div>
        </div>
    </div>
</div>

<!-- 跳转规则 + 大站URL池 并排显示 -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
    <!-- 左侧：跳转规则 -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header">
            <span class="card-title">🔗 跳转规则 (<?php echo count($taskRules); ?>)</span>
            <div style="display: flex; gap: 6px;">
                <button class="btn btn-secondary btn-sm" onclick="exportRules()" title="导出CSV">📥</button>
                <button class="btn btn-secondary btn-sm" onclick="clearRules()" title="清空">🗑️</button>
                <button class="btn btn-primary btn-sm" onclick="showBatchAddModal()" title="批量添加">➕</button>
            </div>
        </div>
        
        <div style="max-height: 400px; overflow-y: auto; padding: 16px;">
            <?php if (empty($taskRules)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📭</div>
                <h3>还没有规则</h3>
                <p>点击"批量添加"开始添加跳转规则</p>
            </div>
            <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <?php foreach ($taskRules as $rule): ?>
                <?php 
                    $used = $rule['used_count'] ?? 0;
                    $total = $rule['redirect_count'] ?? 1;
                    $percent = $total > 0 ? round($used / $total * 100) : 0;
                    $isCompleted = $used >= $total;
                ?>
                <div style="padding: 12px; background: var(--bg-dark); border-radius: 8px; border: 1px solid var(--border);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 8px;">
                        <div style="flex: 1; min-width: 0;">
                            <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                                <span style="font-size: 13px; color: var(--text); word-break: break-all;">
                                    <?php echo htmlspecialchars($rule['source_url']); ?>
                                </span>
                                <span class="badge" style="font-size: 10px; padding: 2px 6px; background: <?php echo ($rule['redirect_type'] ?? '302') === '301' ? '#10b981' : '#3b82f6'; ?>; color: white;">
                                    <?php echo $rule['redirect_type'] ?? '302'; ?>
                                </span>
                            </div>
                            <div style="font-size: 12px; color: var(--text-muted); word-break: break-all;">
                                → <?php echo htmlspecialchars($rule['target_url']); ?>
                            </div>
                        </div>
                        <button class="btn btn-danger btn-sm" onclick="deleteRule('<?php echo htmlspecialchars(addslashes($rule['source_url'])); ?>')" title="删除" style="padding: 4px 8px;">🗑️</button>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div style="flex: 1; height: 4px; background: var(--border); border-radius: 2px; overflow: hidden;">
                            <div style="width: <?php echo $percent; ?>%; height: 100%; background: <?php echo $isCompleted ? 'var(--success)' : 'var(--primary)'; ?>;"></div>
                        </div>
                        <span style="font-size: 11px; color: var(--text-muted); white-space: nowrap;">
                            <?php echo $used; ?>/<?php echo $total; ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- 右侧：大站URL池 -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header">
            <span class="card-title">🌐 大站URL池 (<?php echo count($sites); ?>)</span>
            <div style="display: flex; gap: 6px;">
                <button class="btn btn-secondary btn-sm" onclick="clearSites()" title="清空">🗑️</button>
                <button class="btn btn-secondary btn-sm" onclick="showBatchAddSitesModal()" title="批量添加">📋</button>
                <button class="btn btn-primary btn-sm" onclick="showAddSiteModal()" title="添加">➕</button>
            </div>
        </div>
        
        <div style="max-height: 400px; overflow-y: auto; padding: 16px;">
            <?php if (empty($sites)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">🌐</div>
                <h3>还没有大站URL</h3>
                <p>添加高权重网站URL作为跳转目标</p>
            </div>
            <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <?php foreach ($sites as $site): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px; background: var(--bg-dark); border-radius: 6px; border: 1px solid var(--border);">
                    <span style="font-size: 13px; color: var(--text); word-break: break-all; flex: 1;">
                        <?php echo htmlspecialchars($site); ?>
                    </span>
                    <button class="btn btn-danger btn-sm" onclick="deleteSite('<?php echo htmlspecialchars(addslashes($site)); ?>')" style="padding: 4px 8px; margin-left: 8px;">🗑️</button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 添加大站URL弹窗 -->
<div class="modal-overlay" id="addSiteModal">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">添加大站URL</h3>
            <button class="modal-close" onclick="closeModal('addSiteModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">URL <span style="color: #ef4444;">*</span></label>
                <input type="text" id="siteUrl" class="form-input" placeholder="https://baidu.com">
                <p class="form-hint">输入高权重网站的URL</p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('addSiteModal')">取消</button>
            <button class="btn btn-primary" onclick="addSite()">添加</button>
        </div>
    </div>
</div>

<!-- 批量添加大站URL弹窗 -->
<div class="modal-overlay" id="batchAddSitesModal">
    <div class="modal" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title">批量添加大站URL</h3>
            <button class="modal-close" onclick="closeModal('batchAddSitesModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">URL列表</label>
                <textarea id="batchSitesText" class="form-textarea" rows="10" placeholder="每行一个URL，例如：
https://baidu.com
https://google.com
https://qq.com"></textarea>
                <p class="form-hint">每行一个URL，支持 # 开头的注释</p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('batchAddSitesModal')">取消</button>
            <button class="btn btn-primary" onclick="batchAddSites()">添加</button>
        </div>
    </div>
</div>

<!-- 批量添加规则弹窗 -->
<div class="modal-overlay" id="batchAddModal">
    <div class="modal" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title">批量添加跳转规则</h3>
            <button class="modal-close" onclick="closeModal('batchAddModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label">默认跳转次数</label>
                    <input type="number" id="batchDefaultCount" class="form-input" value="1" min="1">
                </div>
                <div class="form-group">
                    <label class="form-label">跳转方式</label>
                    <select id="batchRedirectType" class="form-input">
                        <option value="302">302 临时重定向</option>
                        <option value="301">301 永久重定向</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">方式1：文本输入</label>
                <textarea id="batchText" class="form-textarea" rows="8" placeholder="每行一条，格式：
来源URL
来源URL,目标URL
来源URL,目标URL,次数
来源URL,目标URL,次数,跳转类型

示例：
https://my-site.com/page1
https://my-site.com/page2,https://baidu.com
https://my-site.com/page3,https://baidu.com,10
https://my-site.com/page4,https://baidu.com,10,301"></textarea>
                <p class="form-hint">
                    当前大站池有 <strong><?php echo count($sites); ?></strong> 个URL，
                    未指定目标的链接将随机选择。第4列（跳转类型）可选，为空则使用下方选择的类型。
                </p>
            </div>
            
            <div class="form-group" style="border-top: 1px dashed #e2e8f0; padding-top: 16px; margin-top: 16px;">
                <label class="form-label">方式2：CSV文件上传 🆕</label>
                <input type="file" id="csvFile" accept=".csv,.txt" class="form-input" style="padding: 8px;">
                <p class="form-hint" style="margin-top: 8px;">
                    <strong>CSV格式：</strong>跳转源URL,跳转对象URL,跳转次数,跳转类型<br>
                    <strong>示例：</strong>example.com/page.html,bigsite.com/target.html,100,301<br>
                    <strong>说明：</strong>第4列（跳转类型）可选，为空则使用任务默认设置（当前：<?php echo $task['settings']['redirect_type'] ?? '302'; ?>）<br>
                    <a href="?action=download_csv_template" style="color: #3b82f6; text-decoration: underline;">📥 下载CSV模板</a>
                </p>
            </div>
        </div>
        <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
            <button class="btn btn-secondary" onclick="closeModal('batchAddModal')">取消</button>
            <div style="display: flex; gap: 8px;">
                <button class="btn btn-success" onclick="uploadCSV()" style="background: #10b981;">📁 上传CSV</button>
                <button class="btn btn-primary" onclick="batchAddRules()">✏️ 文本添加</button>
            </div>
        </div>
    </div>
</div>

<script>
function toggleSmartStrategy() {
    const enabled = document.getElementById('smartStrategyEnabled').checked;
    const rulesDiv = document.getElementById('smartStrategyRules');
    rulesDiv.style.display = enabled ? 'block' : 'none';
}

function saveSmartStrategy() {
    const enabled = document.getElementById('smartStrategyEnabled').checked;
    const rule_0 = parseInt(document.getElementById('rule_0').value) || 100;
    const rule_1 = parseInt(document.getElementById('rule_1').value) || 50;
    const rule_2 = parseInt(document.getElementById('rule_2').value) || 30;
    const rule_3 = parseInt(document.getElementById('rule_3').value) || 20;
    const rule_4 = parseInt(document.getElementById('rule_4').value) || 15;
    const rule_5plus = parseInt(document.getElementById('rule_5plus').value) || 10;
    
    // 验证概率范围
    const rules = [rule_0, rule_1, rule_2, rule_3, rule_4, rule_5plus];
    for (let i = 0; i < rules.length; i++) {
        if (rules[i] < 0 || rules[i] > 100) {
            alert('概率必须在0-100之间');
            return;
        }
    }
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'save_smart_strategy');
    formData.append('enabled', enabled ? '1' : '0');
    formData.append('rule_0', rule_0);
    formData.append('rule_1', rule_1);
    formData.append('rule_2', rule_2);
    formData.append('rule_3', rule_3);
    formData.append('rule_4', rule_4);
    formData.append('rule_5plus', rule_5plus);
    
    fetch('bigsite_task.php?id=<?php echo $taskId; ?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            location.reload();
        }
    });
}

function saveSettings() {
    const name = document.getElementById('taskName').value.trim();
    const redirectType = document.getElementById('redirectType').value;
    const probability = parseInt(document.getElementById('probability').value) || 100; // ⭐ 新增：获取概率值
    
    if (!name) {
        alert('请输入任务名称');
        return;
    }
    
    // ⭐ 新增：验证概率范围
    if (probability < 0 || probability > 100) {
        alert('跳转概率必须在0-100之间');
        return;
    }
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'save_settings');
    formData.append('name', name);
    formData.append('redirect_type', redirectType);
    formData.append('probability', probability); // ⭐ 新增：提交概率值
    
    fetch('bigsite_task.php?id=<?php echo $taskId; ?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            location.reload();
        }
    });
}

function showBatchAddModal() {
    document.getElementById('batchText').value = '';
    document.getElementById('batchDefaultCount').value = '1';
    document.getElementById('batchRedirectType').value = document.getElementById('redirectType').value;
    showModal('batchAddModal');
}

function batchAddRules() {
    const text = document.getElementById('batchText').value.trim();
    const defaultCount = document.getElementById('batchDefaultCount').value;
    const redirectType = document.getElementById('batchRedirectType').value;
    
    if (!text) {
        alert('请输入链接');
        return;
    }
    
    // 先检查是否有已存在的规则
    const checkFormData = new FormData();
    checkFormData.append('ajax', '1');
    checkFormData.append('action', 'check_existing_rules');
    checkFormData.append('text', text);
    
    fetch('bigsite_task.php?id=<?php echo $taskId; ?>', {
        method: 'POST',
        body: checkFormData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.existing_count > 0) {
            // 有已存在的规则，显示确认对话框
            showExistingRulesConfirm(data, text, defaultCount, redirectType);
        } else {
            // 没有已存在的规则，直接添加
            performBatchAdd(text, defaultCount, redirectType, false);
        }
    })
    .catch(err => {
        alert('检查失败：' + err.message);
    });
}

function performBatchAdd(text, defaultCount, redirectType, overwrite) {
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'batch_add_rules');
    formData.append('text', text);
    formData.append('default_count', defaultCount);
    formData.append('redirect_type', redirectType);
    formData.append('overwrite', overwrite ? '1' : '0');
    
    fetch('bigsite_task.php?id=<?php echo $taskId; ?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            closeModal('batchAddModal');
            location.reload();
        }
    })
    .catch(err => {
        alert('添加失败：' + err.message);
    });
}

// ⭐ 新增：CSV文件上传
function uploadCSV() {
    const fileInput = document.getElementById('csvFile');
    const file = fileInput.files[0];
    
    if (!file) {
        alert('请选择CSV文件');
        return;
    }
    
    // 检查文件大小（限制10MB）
    if (file.size > 10 * 1024 * 1024) {
        alert('文件太大，请选择小于10MB的文件');
        return;
    }
    
    // ⭐ 新增：询问是否覆盖已存在的规则
    const overwrite = confirm('是否覆盖已存在的规则？\n\n点击"确定"覆盖已存在的规则\n点击"取消"跳过已存在的规则');
    
    // 显示上传提示
    const btn = event.target;
    const originalText = btn.textContent;
    btn.textContent = '⏳ 上传中...';
    btn.disabled = true;
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'import_csv');
    formData.append('csv_file', file);
    formData.append('overwrite', overwrite ? '1' : '0'); // ⭐ 新增：覆盖参数
    
    fetch('bigsite_task.php?id=<?php echo $taskId; ?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        btn.textContent = originalText;
        btn.disabled = false;
        
        if (data.success) {
            let message = `导入完成！\n\n`;
            message += `✅ 成功添加：${data.added} 条\n`;
            if (data.overwritten > 0) {
                message += `🔄 覆盖更新：${data.overwritten} 条\n`; // ⭐ 新增：显示覆盖数量
            }
            message += `⏭️ 已存在跳过：${data.skipped} 条\n`;
            message += `❌ 失败：${data.failed} 条`;
            
            if (data.errors && data.errors.length > 0) {
                message += `\n\n错误详情（前${data.errors.length}条）：\n`;
                data.errors.forEach(err => {
                    message += `- ${err}\n`;
                });
                
                // ⭐ 新增：显示总错误数
                if (data.total_errors > data.errors.length) {
                    message += `\n... 还有 ${data.total_errors - data.errors.length} 条错误未显示`;
                }
            }
            
            alert(message);
            
            if (data.added > 0 || data.overwritten > 0) {
                closeModal('batchAddModal');
                location.reload();
            }
        } else {
            alert('导入失败：' + data.message);
        }
    })
    .catch(err => {
        btn.textContent = originalText;
        btn.disabled = false;
        alert('上传失败：' + err.message);
    });
}

function showExistingRulesConfirm(data, text, defaultCount, redirectType) {
    const existing = data.existing;
    const existingCount = data.existing_count;
    const newCount = data.new_count;
    
    let message = `检测到 ${existingCount} 条链接已存在：\n\n`;
    
    // 显示前 5 条已存在的规则
    const displayCount = Math.min(5, existing.length);
    for (let i = 0; i < displayCount; i++) {
        const item = existing[i];
        const info = item.info || {};
        message += `${i + 1}. ${item.url}\n`;
        if (info.target_url) {
            message += `   → ${info.target_url}\n`;
        }
        if (info.redirect_count !== undefined) {
            message += `   已跳转: ${info.used_count || 0}/${info.redirect_count} 次\n`;
        }
        if (info.task_name) {
            message += `   所属任务: ${info.task_name}\n`;
        }
        message += `\n`;
    }
    
    if (existingCount > displayCount) {
        message += `... 还有 ${existingCount - displayCount} 条已存在\n\n`;
    }
    
    if (newCount > 0) {
        message += `${newCount} 条链接是新的\n\n`;
    }
    
    message += `是否覆盖已存在的规则？\n\n`;
    message += `点击"确定"覆盖已存在的规则并继续\n`;
    message += `点击"取消"跳过已存在的规则并继续`;
    
    if (confirm(message)) {
        // 用户选择覆盖：overwrite=true，会删除旧规则后重新添加
        performBatchAdd(text, defaultCount, redirectType, true);
    } else {
        // 用户选择跳过：overwrite=false，跳过已存在的规则，只添加新规则
        performBatchAdd(text, defaultCount, redirectType, false);
    }
}

function deleteRule(sourceUrl) {
    if (!confirm('确定删除该规则？')) return;
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'delete_rule');
    formData.append('source_url', sourceUrl);
    
    fetch('bigsite_task.php?id=<?php echo $taskId; ?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message);
        }
    });
}

function exportRules() {
    // 直接跳转到导出URL
    window.location.href = 'bigsite_task.php?id=<?php echo $taskId; ?>&ajax=1&action=export_rules';
}

function clearRules() {
    if (!confirm('确定清空所有规则？\n\n此操作不可恢复！')) return;
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'clear_rules');
    
    fetch('bigsite_task.php?id=<?php echo $taskId; ?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            location.reload();
        }
    });
}

function showAddSiteModal() {
    document.getElementById('siteUrl').value = '';
    showModal('addSiteModal');
}

function addSite() {
    const url = document.getElementById('siteUrl').value.trim();
    
    if (!url) {
        alert('请输入URL');
        return;
    }
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'add_site');
    formData.append('url', url);
    
    fetch('bigsite_task.php?id=<?php echo $taskId; ?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            closeModal('addSiteModal');
            location.reload();
        }
    });
}

function showBatchAddSitesModal() {
    document.getElementById('batchSitesText').value = '';
    showModal('batchAddSitesModal');
}

function batchAddSites() {
    const text = document.getElementById('batchSitesText').value.trim();
    
    if (!text) {
        alert('请输入URL');
        return;
    }
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'batch_add_sites');
    formData.append('text', text);
    
    fetch('bigsite_task.php?id=<?php echo $taskId; ?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            closeModal('batchAddSitesModal');
            location.reload();
        }
    });
}

function deleteSite(url) {
    if (!confirm('确定删除该URL？')) return;
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'delete_site');
    formData.append('url', url);
    
    fetch('bigsite_task.php?id=<?php echo $taskId; ?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message);
        }
    });
}

function clearSites() {
    if (!confirm('确定清空所有大站URL？\n\n此操作不可恢复！')) return;
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'clear_sites');
    
    fetch('bigsite_task.php?id=<?php echo $taskId; ?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            location.reload();
        }
    });
}

function showModal(id) {
    document.getElementById(id).classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

