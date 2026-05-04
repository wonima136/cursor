<?php
/**
 * 大站池管理（Redis 版本）
 */
$pageTitle = '大站池 - 301重定向管理系统';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/redis_config.php';

// 检查 Redis 连接
$redisAvailable = isRedisAvailable();
$redisError = !$redisAvailable ? 'Redis 连接失败，请检查 Redis 服务是否启动' : '';

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    if (!$redisAvailable) {
        echo json_encode(['success' => false, 'message' => 'Redis 连接失败']);
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => '未知操作'];
    
    switch ($action) {
        // 预检查 URL
        case 'pre_check':
            $text = trim($_POST['text'] ?? '');
            $urls = [];
            
            foreach (explode("\n", $text) as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, '#') === 0) continue;
                $parts = explode(',', $line);
                $url = trim($parts[0]);
                if (!empty($url)) {
                    $urls[] = $url;
                }
            }
            
            $result = preCheckBigsiteUrls($urls);
            $response = ['success' => true, 'data' => $result];
            break;
            
        // 批量添加规则
        case 'batch_add_rules':
            $text = trim($_POST['text'] ?? '');
            $defaultCount = max(1, intval($_POST['default_count'] ?? 1));
            $redirectType = in_array($_POST['redirect_type'] ?? '302', ['301', '302']) ? $_POST['redirect_type'] : '302';
            $mode = $_POST['mode'] ?? 'skip_history';
            $taskName = trim($_POST['task_name'] ?? ''); // 新增：任务名称
            
            $pool = getBigsitePool();
            $sites = getAllBigsiteSitesFromRedis();
            
            $added = 0;
            $skipped = 0;
            $noTarget = 0;
            
            foreach (explode("\n", $text) as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, '#') === 0) continue;
                
                $parts = explode(',', $line);
                $sourceUrl = trim($parts[0]);
                $redirectCount = isset($parts[1]) && is_numeric(trim($parts[1])) ? intval(trim($parts[1])) : $defaultCount;
                $targetUrl = isset($parts[2]) ? trim($parts[2]) : '';
                
                if (empty($sourceUrl)) continue;
                if ($redirectCount < 1) $redirectCount = $defaultCount;
                
                if (isBigsiteRuleActiveInRedis($sourceUrl)) {
                    $skipped++;
                    continue;
                }
                
                if ($mode === 'skip_history') {
                    $history = checkBigsiteUrlHistory($sourceUrl);
                    if ($history) {
                        $skipped++;
                        continue;
                    }
                }
                
                if (empty($targetUrl) && !empty($sites)) {
                    $targetUrl = $sites[array_rand($sites)];
                }
                
                if (empty($targetUrl)) {
                    $noTarget++;
                    continue;
                }
                
                if (addBigsiteRuleToRedis($sourceUrl, $targetUrl, $redirectCount, $redirectType, $taskName)) {
                    // 不在添加时写入历史，只在跳转完成时写入
                    $added++;
                }
            }
            
            $msg = "成功添加 {$added} 条规则";
            if ($skipped > 0) $msg .= "，跳过 {$skipped} 条";
            if ($noTarget > 0) $msg .= "，{$noTarget} 条因无目标URL跳过";
            
            $response = ['success' => true, 'message' => $msg, 'added' => $added];
            break;
            
        // 添加单个规则
        case 'add_rule':
            $sourceUrl = trim($_POST['source_url'] ?? '');
            $targetUrl = trim($_POST['target_url'] ?? '');
            $redirectCount = max(1, intval($_POST['redirect_count'] ?? 1));
            $redirectType = in_array($_POST['redirect_type'] ?? '302', ['301', '302']) ? $_POST['redirect_type'] : '302';
            $forceAdd = isset($_POST['force_add']);
            
            if (empty($sourceUrl)) {
                $response = ['success' => false, 'message' => '来源链接不能为空'];
                break;
            }
            
            if (isBigsiteRuleActiveInRedis($sourceUrl)) {
                $response = ['success' => false, 'message' => '该链接正在跳转中，请勿重复添加'];
                break;
            }
            
            if (!$forceAdd) {
                $history = checkBigsiteUrlHistory($sourceUrl);
                if ($history) {
                    $response = [
                        'success' => false, 
                        'warning' => 'history_exists',
                        "message" => "该链接曾于 {$history['completed_at']} 执行过跳转",
                        'history' => $history
                    ];
                    break;
                }
            }
            
            if (empty($targetUrl)) {
                $targetUrl = getRandomBigsiteSiteFromRedis();
            }
            
            if (empty($targetUrl)) {
                $response = ['success' => false, 'message' => '请先添加大站URL或指定跳转目标'];
                break;
            }
            
            if (addBigsiteRuleToRedis($sourceUrl, $targetUrl, $redirectCount, $redirectType)) {
                // 不在添加时写入历史，只在跳转完成时写入
                $response = ['success' => true, 'message' => '规则添加成功'];
            } else {
                $response = ['success' => false, 'message' => '添加失败'];
            }
            break;
            
        // 删除规则
        case 'delete_rule':
            $sourceUrl = trim($_POST['source_url'] ?? '');
            
            if (deleteBigsiteRuleFromRedis($sourceUrl)) {
                updateBigsiteHistoryStatus($sourceUrl, [
                    'status' => 'deleted',
                    'completed_at' => date('Y-m-d H:i:s')
                ]);
                $response = ['success' => true, 'message' => '规则已删除'];
            } else {
                $response = ['success' => false, 'message' => '删除失败'];
            }
            break;
            
        // 重置规则计数
        case 'reset_rule':
            $sourceUrl = trim($_POST['source_url'] ?? '');
            
            if (resetBigsiteRuleCount($sourceUrl)) {
                $response = ['success' => true, 'message' => '计数已重置'];
            } else {
                $response = ['success' => false, 'message' => '重置失败'];
            }
            break;
            
        // 清空所有规则
        case 'clear_rules':
            $rules = getAllActiveBigsiteRules();
            $ruleCount = count($rules);
            
            // 将活跃规则标记为已清空
            foreach ($rules as $rule) {
                updateBigsiteHistoryStatus($rule['source_url'], [
                    'status' => 'cleared',
                    'completed_at' => date('Y-m-d H:i:s')
                ]);
            }
            
            // 清空 Redis
            $cleared = clearAllBigsiteFromRedis();
            
            if ($cleared) {
                // 验证清空结果
                $remainingRules = getAllActiveBigsiteRules();
                if (empty($remainingRules)) {
                    $response = ['success' => true, 'message' => "成功清空 {$ruleCount} 条规则"];
                } else {
                    $response = ['success' => false, 'message' => '清空失败，仍有 ' . count($remainingRules) . ' 条规则残留'];
                }
            } else {
                $response = ['success' => false, 'message' => 'Redis 清空操作失败'];
            }
            break;
            
        // 添加大站 URL
        case 'add_site':
            $url = trim($_POST['url'] ?? '');
            
            if (!empty($url)) {
                addBigsiteSiteToRedis($url);
                
                $pool = getBigsitePool();
                $exists = false;
                foreach ($pool['sites'] as $site) {
                    if ($site['url'] === $url) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $pool['sites'][] = [
                        'url' => $url,
                        'note' => trim($_POST['note'] ?? ''),
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    saveBigsitePool($pool);
                }
                
                $response = ['success' => true, 'message' => '大站URL添加成功'];
            }
            break;
            
        // 批量添加大站 URL
        case 'batch_add_sites':
            $text = trim($_POST['text'] ?? '');
            $added = 0;
            $pool = getBigsitePool();
            
            foreach (explode("\n", $text) as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, '#') === 0) continue;
                
                $parts = explode(',', $line, 2);
                $url = trim($parts[0]);
                $note = isset($parts[1]) ? trim($parts[1]) : '';
                
                if (empty($url)) continue;
                
                addBigsiteSiteToRedis($url);
                
                $exists = false;
                foreach ($pool['sites'] as $site) {
                    if ($site['url'] === $url) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $pool['sites'][] = [
                        'url' => $url,
                        'note' => $note,
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    $added++;
                }
            }
            
            saveBigsitePool($pool);
            $response = ['success' => true, 'message' => "成功添加 {$added} 个大站URL"];
            break;
            
        // 删除大站 URL
        case 'delete_site':
            $url = trim($_POST['url'] ?? '');
            
            deleteBigsiteSiteFromRedis($url);
            
            $pool = getBigsitePool();
            foreach ($pool['sites'] as $i => $site) {
                if ($site['url'] === $url) {
                    array_splice($pool['sites'], $i, 1);
                    break;
                }
            }
            saveBigsitePool($pool);
            
            $response = ['success' => true, 'message' => '大站URL已删除'];
            break;
            
        // 清空大站 URL
        case 'clear_sites':
            clearBigsiteSitesFromRedis();
            
            $pool = getBigsitePool();
            $pool['sites'] = [];
            saveBigsitePool($pool);
            
            $response = ['success' => true, 'message' => '大站池已清空'];
            break;
            
        // 导出历史数据
        case 'export_history':
            $format = $_POST['format'] ?? 'csv';
            $data = exportBigsiteHistory($format);
            $response = ['success' => true, 'data' => $data, 'format' => $format];
            break;
            
        // 清空历史数据
        case 'clear_history':
            clearBigsiteHistory();
            $response = ['success' => true, 'message' => '历史数据已清空'];
            break;
            
        // 更新设置
        case 'update_settings':
            $settings = getSettings();
            $settings['bigsite_pool']['enabled'] = isset($_POST['enabled']);
            saveSettings($settings);
            $response = ['success' => true, 'message' => '设置已保存'];
            break;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取数据
$settings = getSettings();
$pool = getBigsitePool();
$activeRules = $redisAvailable ? getAllActiveBigsiteRules() : [];
$sites = $redisAvailable ? getAllBigsiteSitesFromRedis() : [];
$historyStats = getBigsiteHistoryStats();

// 如果历史统计为空，设置默认值
if (!is_array($historyStats)) {
    $historyStats = [
        'total_records' => 0,
        'completed_count' => 0,
        'total_redirects' => 0,
        'unique_urls' => 0
    ];
}

// 计算活跃规则的跳转次数和已完成数量
$activeRedirects = 0;
$completedInRedis = 0;
foreach ($activeRules as $rule) {
    $activeRedirects += $rule['used_count'] ?? 0;
    if (!empty($rule['is_completed'])) {
        $completedInRedis++;
    }
}

// 合并统计：已完成的 + 活跃中的
$historyStats['total_redirects'] += $activeRedirects;
$historyStats['completed_count'] += $completedInRedis;

// 同步 JSON 中的大站 URL 到 Redis
if ($redisAvailable && !empty($pool['sites']) && empty($sites)) {
    foreach ($pool['sites'] as $site) {
        addBigsiteSiteToRedis($site['url']);
    }
    $sites = getAllBigsiteSitesFromRedis();
}

require_once __DIR__ . '/header.php';
?>

<div class="page-header">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1 class="page-title">大站池管理</h1>
            <p class="page-subtitle">管理高权重网站跳转规则，使用 Redis 实现高性能查询</p>
        </div>
        <div class="badge <?php echo $redisAvailable ? 'badge-success' : 'badge-danger'; ?>">
            <?php echo $redisAvailable ? '✓ Redis 已连接' : '✗ Redis 未连接'; ?>
        </div>
    </div>
</div>

<?php if ($redisError): ?>
<div class="alert alert-error">
    ⚠️ <?php echo $redisError; ?>
</div>
<?php endif; ?>

<!-- 统计卡片 -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon primary">🔗</div>
        <div class="stat-content">
            <h3><?php echo count($activeRules); ?></h3>
            <p>活跃规则</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success">🌐</div>
        <div class="stat-content">
            <h3><?php echo count($sites); ?></h3>
            <p>大站URL</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning">✅</div>
        <div class="stat-content">
            <h3><?php echo $historyStats['completed_count']; ?></h3>
            <p>已完成</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon info">📊</div>
        <div class="stat-content">
            <h3><?php echo $historyStats['total_redirects']; ?></h3>
            <p>总跳转次数</p>
        </div>
    </div>
</div>

<!-- 跳转规则 + 大站URL池 并排显示 -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
    <!-- 跳转规则 -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header">
            <div style="display: flex; align-items: center; gap: 12px;">
                <span class="card-title">🔗 跳转规则</span>
                <label class="switch">
                    <input type="checkbox" id="bigsiteEnabled" <?php echo $settings['bigsite_pool']['enabled'] ? 'checked' : ''; ?>>
                    <span class="switch-slider"></span>
                </label>
                <span class="badge <?php echo $settings['bigsite_pool']['enabled'] ? 'badge-success' : 'badge-warning'; ?>" style="font-size: 11px;">
                    <?php echo $settings['bigsite_pool']['enabled'] ? '已启用' : '已禁用'; ?>
                </span>
            </div>
            <div style="display: flex; gap: 6px;">
                <button class="btn btn-secondary btn-sm" onclick="clearRules()" title="清空">🗑️</button>
                <button class="btn btn-secondary btn-sm" onclick="showBatchAddRulesModal()" title="批量添加">📋</button>
                <button class="btn btn-primary btn-sm" onclick="showAddRuleModal()" title="添加">➕</button>
            </div>
        </div>
        
        <div id="rulesList" style="max-height: 400px; overflow-y: auto;">
            <?php if (empty($activeRules)): ?>
            <div class="empty-state" style="padding: 40px 20px;">
                <div class="empty-state-icon">📭</div>
                <h3>暂无活跃规则</h3>
                <p>点击 ➕ 添加跳转规则</p>
            </div>
            <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <?php foreach ($activeRules as $rule): ?>
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
                                <?php if (!empty($rule['task_name'])): ?>
                                <span class="badge" style="font-size: 10px; padding: 2px 6px; background: #8b5cf6; color: white;" title="任务名称">
                                    📋 <?php echo htmlspecialchars($rule['task_name']); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size: 12px; color: var(--text-muted); word-break: break-all;">
                                → <?php echo htmlspecialchars($rule['target_url']); ?>
                            </div>
                        </div>
                        <div style="display: flex; gap: 4px; flex-shrink: 0;">
                            <button class="btn btn-secondary btn-sm" onclick="resetRule('<?php echo htmlspecialchars(addslashes($rule['source_url'])); ?>')" title="重置" style="padding: 4px 8px;">🔄</button>
                            <button class="btn btn-danger btn-sm" onclick="deleteRule('<?php echo htmlspecialchars(addslashes($rule['source_url'])); ?>')" title="删除" style="padding: 4px 8px;">🗑️</button>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div style="flex: 1; height: 4px; background: var(--border); border-radius: 2px; overflow: hidden;">
                            <div style="width: <?php echo $percent; ?>%; height: 100%; background: <?php echo $isCompleted ? 'var(--success)' : 'var(--primary)'; ?>;"></div>
                        </div>
                        <span class="badge <?php echo $isCompleted ? 'badge-success' : 'badge-info'; ?>" style="font-size: 11px; padding: 2px 8px;">
                            <?php echo $used; ?>/<?php echo $total; ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 大站URL池 -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header">
            <span class="card-title">🌐 大站URL池</span>
            <div style="display: flex; gap: 6px;">
                <button class="btn btn-secondary btn-sm" onclick="clearSites()" title="清空">🗑️</button>
                <button class="btn btn-secondary btn-sm" onclick="showBatchAddSitesModal()" title="批量添加">📋</button>
                <button class="btn btn-primary btn-sm" onclick="showAddSiteModal()" title="添加">➕</button>
            </div>
        </div>
        
        <div id="sitesList" style="max-height: 400px; overflow-y: auto;">
            <?php if (empty($pool['sites'])): ?>
            <div class="empty-state" style="padding: 40px 20px;">
                <div class="empty-state-icon">🌍</div>
                <h3>暂无大站URL</h3>
                <p>添加高权重网站URL用于随机跳转</p>
            </div>
            <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 6px;">
                <?php foreach ($pool['sites'] as $index => $site): ?>
                <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 12px; background: var(--bg-dark); border: 1px solid var(--border); border-radius: 8px;">
                    <div style="flex: 1; min-width: 0;">
                        <span style="color: var(--text); font-size: 13px; word-break: break-all;"><?php echo htmlspecialchars($site['url']); ?></span>
                        <?php if (!empty($site['note'])): ?>
                        <span style="color: var(--text-muted); font-size: 12px; margin-left: 8px;">(<?php echo htmlspecialchars($site['note']); ?>)</span>
                        <?php endif; ?>
                    </div>
                    <button onclick="deleteSite('<?php echo htmlspecialchars(addslashes($site['url'])); ?>')" style="background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 4px 8px; font-size: 14px; flex-shrink: 0;" title="删除">✕</button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 历史数据 -->
<div class="card">
    <div class="card-header">
        <span class="card-title">📜 历史数据</span>
        <div style="display: flex; gap: 8px;">
            <button class="btn btn-secondary btn-sm" onclick="exportHistory('csv')">
                📥 导出 CSV
            </button>
            <button class="btn btn-secondary btn-sm" onclick="exportHistory('json')">
                📥 导出 JSON
            </button>
            <button class="btn btn-danger btn-sm" onclick="clearHistory()">
                🗑️ 清空
            </button>
        </div>
    </div>
    
    <div class="form-row">
        <div style="text-align: center; padding: 20px; background: var(--bg-dark); border-radius: 8px;">
            <div style="font-size: 28px; font-weight: 600; color: var(--primary);"><?php echo $historyStats['total_records']; ?></div>
            <div style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">总记录数</div>
        </div>
        <div style="text-align: center; padding: 20px; background: var(--bg-dark); border-radius: 8px;">
            <div style="font-size: 28px; font-weight: 600; color: var(--success);"><?php echo $historyStats['completed_count']; ?></div>
            <div style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">已完成</div>
        </div>
        <div style="text-align: center; padding: 20px; background: var(--bg-dark); border-radius: 8px;">
            <div style="font-size: 28px; font-weight: 600; color: var(--warning);"><?php echo $historyStats['unique_urls']; ?></div>
            <div style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">唯一URL</div>
        </div>
        <div style="text-align: center; padding: 20px; background: var(--bg-dark); border-radius: 8px;">
            <div style="font-size: 28px; font-weight: 600; color: var(--info);"><?php echo $historyStats['total_redirects']; ?></div>
            <div style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">总跳转次数</div>
        </div>
    </div>
</div>

<!-- 添加规则弹窗 -->
<div class="modal-overlay" id="addRuleModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">添加跳转规则</h3>
            <button class="modal-close" onclick="closeModal('addRuleModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">来源链接 <span style="color: var(--error);">*</span></label>
                <input type="text" id="ruleSourceUrl" class="form-input" placeholder="https://your-site.com/page">
            </div>
            <div class="form-group">
                <label class="form-label">目标链接</label>
                <input type="text" id="ruleTargetUrl" class="form-input" placeholder="留空则从大站池随机选择">
            </div>
            <div class="form-group">
                <label class="form-label">跳转次数</label>
                <input type="number" id="ruleCount" class="form-input" value="1" min="1">
            </div>
            <div class="form-group">
                <label class="form-label">跳转方式</label>
                <select id="ruleRedirectType" class="form-input">
                    <option value="302">302 临时重定向</option>
                    <option value="301">301 永久重定向</option>
                </select>
            </div>
            <div id="ruleHistoryWarning" style="display: none; padding: 12px; background: rgba(245, 158, 11, 0.1); border: 1px solid var(--warning); border-radius: 8px; margin-top: 16px;">
                <p style="color: var(--warning); margin-bottom: 8px;">⚠️ <span id="ruleHistoryMsg"></span></p>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" id="ruleForceAdd">
                    <span>确认重新启用</span>
                </label>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('addRuleModal')">取消</button>
            <button class="btn btn-primary" onclick="addRule()">添加</button>
        </div>
    </div>
</div>

<!-- 批量添加规则弹窗 -->
<div class="modal-overlay" id="batchAddRulesModal">
    <div class="modal" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title">批量添加跳转规则</h3>
            <button class="modal-close" onclick="closeModal('batchAddRulesModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">任务名称 <span style="color: #ef4444;">*</span></label>
                <input type="text" id="batchRuleTaskName" class="form-input" placeholder="例如：首页推广、产品页导流等">
                <p class="form-hint">用于在日志中识别这批链接</p>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label">默认跳转次数</label>
                    <input type="number" id="batchRuleDefaultCount" class="form-input" value="1" min="1">
                    <p class="form-hint">未指定次数的链接将使用此值</p>
                </div>
                <div class="form-group">
                    <label class="form-label">跳转方式</label>
                    <select id="batchRuleRedirectType" class="form-input">
                        <option value="302">302 临时重定向</option>
                        <option value="301">301 永久重定向</option>
                    </select>
                    <p class="form-hint">应用于所有添加的规则</p>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">链接列表</label>
                <textarea id="batchRulesText" class="form-textarea" rows="10" placeholder="每行一条，格式：
来源URL
来源URL,次数
来源URL,次数,目标URL

示例：
https://my-site.com/page1
https://my-site.com/page2,10
https://my-site.com/page3,5,https://baidu.com"></textarea>
            </div>
            <div id="batchCheckResult" style="display: none; padding: 16px; background: rgba(245, 158, 11, 0.1); border: 1px solid var(--warning); border-radius: 8px;">
                <p style="color: var(--warning); font-weight: 500; margin-bottom: 12px;">⚠️ 检测到历史数据</p>
                <div style="display: flex; gap: 24px; margin-bottom: 12px;">
                    <div>新链接: <strong id="checkNewCount">0</strong></div>
                    <div>历史链接: <strong id="checkHistoryCount">0</strong></div>
                    <div>活跃中: <strong id="checkActiveCount">0</strong></div>
                </div>
                <div style="display: flex; gap: 16px;">
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                        <input type="radio" name="batchMode" value="skip_history" checked>
                        <span>仅处理新链接</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                        <input type="radio" name="batchMode" value="include_all">
                        <span>全部处理</span>
                    </label>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('batchAddRulesModal')">取消</button>
            <button class="btn btn-secondary" onclick="preCheckBatch()">预检查</button>
            <button class="btn btn-primary" onclick="batchAddRules()">添加</button>
        </div>
    </div>
</div>

<!-- 添加大站URL弹窗 -->
<div class="modal-overlay" id="addSiteModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">添加大站URL</h3>
            <button class="modal-close" onclick="closeModal('addSiteModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">URL</label>
                <input type="text" id="siteUrl" class="form-input" placeholder="https://baidu.com">
            </div>
            <div class="form-group">
                <label class="form-label">备注 <span style="color: var(--text-muted);">(可选)</span></label>
                <input type="text" id="siteNote" class="form-input" placeholder="备注信息">
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
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">批量添加大站URL</h3>
            <button class="modal-close" onclick="closeModal('batchAddSitesModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">URL列表</label>
                <textarea id="batchSitesText" class="form-textarea" rows="10" placeholder="每行一个URL，可选备注（用逗号分隔）

示例：
https://baidu.com
https://qq.com,腾讯
https://sina.com.cn,新浪"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('batchAddSitesModal')">取消</button>
            <button class="btn btn-primary" onclick="batchAddSites()">添加</button>
        </div>
    </div>
</div>

<script>
// 开关切换
document.getElementById('bigsiteEnabled').addEventListener('change', function() {
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'update_settings');
    if (this.checked) formData.append('enabled', '1');
    
    fetch('bigsite.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
});

// 弹窗控制
function showModal(id) {
    document.getElementById(id).classList.add('active');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}
function showAddRuleModal() {
    document.getElementById('ruleSourceUrl').value = '';
    document.getElementById('ruleTargetUrl').value = '';
    document.getElementById('ruleCount').value = '1';
    document.getElementById('ruleHistoryWarning').style.display = 'none';
    document.getElementById('ruleForceAdd').checked = false;
    showModal('addRuleModal');
}
function showBatchAddRulesModal() {
    document.getElementById('batchRulesText').value = '';
    document.getElementById('batchCheckResult').style.display = 'none';
    showModal('batchAddRulesModal');
}
function showAddSiteModal() {
    document.getElementById('siteUrl').value = '';
    document.getElementById('siteNote').value = '';
    showModal('addSiteModal');
}
function showBatchAddSitesModal() {
    document.getElementById('batchSitesText').value = '';
    showModal('batchAddSitesModal');
}

// 添加规则
function addRule() {
    const sourceUrl = document.getElementById('ruleSourceUrl').value.trim();
    const targetUrl = document.getElementById('ruleTargetUrl').value.trim();
    const count = document.getElementById('ruleCount').value;
    const redirectType = document.getElementById('ruleRedirectType').value;
    const forceAdd = document.getElementById('ruleForceAdd').checked;
    
    if (!sourceUrl) {
        alert('请输入来源链接');
        return;
    }
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'add_rule');
    formData.append('source_url', sourceUrl);
    formData.append('target_url', targetUrl);
    formData.append('redirect_count', count);
    formData.append('redirect_type', redirectType);
    if (forceAdd) formData.append('force_add', '1');
    
    fetch('bigsite.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeModal('addRuleModal');
            location.reload();
        } else if (data.warning === 'history_exists') {
            document.getElementById('ruleHistoryWarning').style.display = 'block';
            document.getElementById('ruleHistoryMsg').textContent = data.message;
        } else {
            alert(data.message);
        }
    });
}

// 预检查
function preCheckBatch() {
    const text = document.getElementById('batchRulesText').value.trim();
    if (!text) {
        alert('请输入链接');
        return;
    }
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'pre_check');
    formData.append('text', text);
    
    fetch('bigsite.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const result = data.data;
            if (result.history_count > 0 || result.active_count > 0) {
                document.getElementById('batchCheckResult').style.display = 'block';
                document.getElementById('checkNewCount').textContent = result.new_count;
                document.getElementById('checkHistoryCount').textContent = result.history_count;
                document.getElementById('checkActiveCount').textContent = result.active_count;
            } else {
                document.getElementById('batchCheckResult').style.display = 'none';
                alert('共 ' + result.total + ' 条链接，全部为新链接');
            }
        }
    });
}

// 批量添加规则
function batchAddRules() {
    const taskName = document.getElementById('batchRuleTaskName').value.trim();
    const text = document.getElementById('batchRulesText').value.trim();
    const defaultCount = document.getElementById('batchRuleDefaultCount').value;
    const redirectType = document.getElementById('batchRuleRedirectType').value;
    const modeEl = document.querySelector('input[name="batchMode"]:checked');
    const mode = modeEl ? modeEl.value : 'skip_history';
    
    if (!taskName) {
        alert('请输入任务名称');
        return;
    }
    
    if (!text) {
        alert('请输入链接');
        return;
    }
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'batch_add_rules');
    formData.append('task_name', taskName);
    formData.append('text', text);
    formData.append('default_count', defaultCount);
    formData.append('redirect_type', redirectType);
    formData.append('mode', mode);
    
    fetch('bigsite.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success && data.added > 0) {
            closeModal('batchAddRulesModal');
            location.reload();
        }
    });
}

// 删除规则
function deleteRule(sourceUrl) {
    if (!confirm('确定删除该规则？')) return;
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'delete_rule');
    formData.append('source_url', sourceUrl);
    
    fetch('bigsite.php', {
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

// 重置规则计数
function resetRule(sourceUrl) {
    if (!confirm('确定重置该规则的计数？')) return;
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'reset_rule');
    formData.append('source_url', sourceUrl);
    
    fetch('bigsite.php', {
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

// 清空规则
function clearRules() {
    if (!confirm('确定清空所有规则？此操作不可恢复！')) return;
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'clear_rules');
    
    fetch('bigsite.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}

// 添加大站URL
function addSite() {
    const url = document.getElementById('siteUrl').value.trim();
    const note = document.getElementById('siteNote').value.trim();
    
    if (!url) {
        alert('请输入URL');
        return;
    }
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'add_site');
    formData.append('url', url);
    formData.append('note', note);
    
    fetch('bigsite.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeModal('addSiteModal');
            location.reload();
        } else {
            alert(data.message);
        }
    });
}

// 批量添加大站URL
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
    
    fetch('bigsite.php', {
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

// 删除大站URL
function deleteSite(url) {
    if (!confirm('确定删除该URL？')) return;
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'delete_site');
    formData.append('url', url);
    
    fetch('bigsite.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}

// 清空大站URL
function clearSites() {
    if (!confirm('确定清空所有大站URL？')) return;
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'clear_sites');
    
    fetch('bigsite.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}

// 导出历史
function exportHistory(format) {
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'export_history');
    formData.append('format', format);
    
    fetch('bigsite.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const blob = new Blob([data.data], {type: format === 'csv' ? 'text/csv;charset=utf-8' : 'application/json'});
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'bigsite_history_' + new Date().toISOString().slice(0,10) + '.' + format;
            a.click();
            URL.revokeObjectURL(url);
        }
    });
}

// 清空历史
function clearHistory() {
    if (!confirm('确定清空所有历史数据？此操作不可恢复！')) return;
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'clear_history');
    
    fetch('bigsite.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}

// 点击遮罩关闭弹窗
document.querySelectorAll('.modal-overlay').forEach(function(modal) {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
        }
    });
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
