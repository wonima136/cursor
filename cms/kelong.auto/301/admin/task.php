<?php
/**
 * 任务详情/编辑页面
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/task_functions.php';

// 判断是否为 AJAX 请求
$isAjax = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']));

// 检查登录状态
if (!checkLogin()) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '登录已过期，请刷新页面重新登录']);
        exit;
    }
    header('Location: login.php');
    exit;
}

// 获取任务ID
$taskId = $_GET['id'] ?? '';
if (empty($taskId)) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '任务ID无效']);
        exit;
    }
    header('Location: tasks.php');
    exit;
}

// 获取任务信息
$task = _r301task_getById($taskId);
if (!$task) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '任务不存在']);
        exit;
    }
    header('Location: tasks.php');
    exit;
}

$pageTitle = htmlspecialchars($task['name']) . ' - 任务配置';

// 处理 AJAX 请求
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => '未知操作'];
    
    switch ($action) {
        // 保存基本设置
        case 'save_settings':
            $data = [
                'name' => trim($_POST['name'] ?? $task['name']),
                'redirect_type' => $_POST['redirect_type'] ?? '302',
                'conditions' => [
                    'page_filter' => $_POST['page_filter'] ?? 'all',
                ],
                'links_settings' => [
                    'default_count' => max(1, intval($_POST['default_count'] ?? 1)),
                    'selection_mode' => $_POST['selection_mode'] ?? 'random',
                ],
                'speed_control' => [
                    'enabled' => isset($_POST['speed_control_enabled']),
                    'dimension' => $_POST['speed_dimension'] ?? 'domain',
                    'min_interval' => max(0, intval($_POST['speed_min_interval'] ?? 60)),
                    'max_per_hour' => max(0, intval($_POST['speed_max_per_hour'] ?? 10)),
                    'max_per_day' => max(0, intval($_POST['speed_max_per_day'] ?? 100)),
                ],
            ];
            
            if (_r301task_update($taskId, $data)) {
                $response = ['success' => true, 'message' => '设置已保存'];
            } else {
                $response = ['success' => false, 'message' => '保存失败'];
            }
            break;
            
        // 检查已存在的链接
        case 'check_existing_links':
            require_once __DIR__ . '/redis_config.php';
            
            $text = $_POST['links_text'] ?? '';
            $defaultCount = max(1, intval($_POST['import_default_count'] ?? 1));
            
            if (empty($text)) {
                $response = ['success' => false, 'message' => '请输入链接'];
                break;
            }
            
            // 解析链接
            $parsed = _r301task_parseImportText($text, $defaultCount);
            if (empty($parsed)) {
                $response = ['success' => false, 'message' => '未解析到有效链接'];
                break;
            }
            
            // 检查哪些链接已存在
            $result = checkExistingTaskLinks($taskId, $parsed);
            $response = $result;
            break;
            
        // 批量导入链接（分批处理）
        case 'import_links':
            // 增加执行时间和内存限制
            @set_time_limit(300); // 5分钟
            @ini_set('memory_limit', '256M');
            
            require_once __DIR__ . '/redis_config.php';
            
            $text = $_POST['links_text'] ?? '';
            $defaultCount = max(1, intval($_POST['import_default_count'] ?? 1));
            $batchIndex = intval($_POST['batch_index'] ?? 0);
            $batchSize = intval($_POST['batch_size'] ?? 100); // 每批处理100条
            $overwrite = !empty($_POST['overwrite']); // 是否覆盖已存在的链接
            
            // ★ 调试日志
            error_log("导入链接 - 批次: {$batchIndex}, 默认次数: {$defaultCount}, 覆盖: " . ($overwrite ? '是' : '否'));
            
            // 第一批：解析所有链接并返回总数
            if ($batchIndex === 0) {
                $parsed = _r301task_parseImportText($text, $defaultCount);
                
                // ★ 调试：检查解析结果
                if (!empty($parsed)) {
                    error_log("解析了 " . count($parsed) . " 条链接，第一条: " . json_encode($parsed[0], JSON_UNESCAPED_UNICODE));
                }
                
                if (empty($parsed)) {
                    $response = ['success' => false, 'message' => '未解析到有效链接'];
                    break;
                }
                
                // 将解析结果存储到临时文件
                $tempFile = sys_get_temp_dir() . '/task_import_' . $taskId . '_' . time() . '.json';
                file_put_contents($tempFile, json_encode($parsed, JSON_UNESCAPED_UNICODE));
                
                $response = [
                    'success' => true,
                    'is_batch' => true,
                    'total' => count($parsed),
                    'batch_size' => $batchSize,
                    'temp_file' => basename($tempFile),
                    'message' => '解析完成，共 ' . count($parsed) . ' 条链接，准备分批导入...',
                ];
                break;
            }
            
            // 后续批次：从临时文件读取并分批导入
            $tempFile = sys_get_temp_dir() . '/' . ($_POST['temp_file'] ?? '');
            if (!file_exists($tempFile)) {
                $response = ['success' => false, 'message' => '临时文件不存在，请重新导入'];
                break;
            }
            
            $allLinks = json_decode(file_get_contents($tempFile), true);
            if (!$allLinks) {
                $response = ['success' => false, 'message' => '读取临时文件失败'];
                break;
            }
            
            // 计算当前批次的范围
            // ★ 修复：前端循环从 1 开始，所以这里要减 1
            $start = ($batchIndex - 1) * $batchSize;
            $batchLinks = array_slice($allLinks, $start, $batchSize);
            
            if (empty($batchLinks)) {
                // 所有批次处理完成，删除临时文件
                @unlink($tempFile);
                $response = [
                    'success' => true,
                    'is_complete' => true,
                    'message' => '所有链接导入完成！',
                ];
                break;
            }
            
            // 导入当前批次
            $addedCount = 0;
            $skippedCount = 0;
            $overwrittenCount = 0;
            
            if (function_exists('addTaskLinksToRedis')) {
                $result = addTaskLinksToRedis($taskId, $batchLinks, $overwrite);
                
                // ★ 调试日志
                error_log("批次 {$batchIndex} 导入结果: " . json_encode($result, JSON_UNESCAPED_UNICODE));
                
                if ($result['success']) {
                    $addedCount = $result['added'];
                    $skippedCount = $result['skipped'];
                    $overwrittenCount = $result['overwritten'];
                    
                    // ★ 调试日志
                    error_log("批次 {$batchIndex} 统计: added={$addedCount}, skipped={$skippedCount}, overwritten={$overwrittenCount}");
                } else {
                    // Redis 导入失败，记录错误
                    error_log("批次 {$batchIndex} Redis 导入失败");
                    $response = ['success' => false, 'message' => 'Redis 导入失败'];
                    break;
                }
            } else {
                // 降级到 JSON 文件
                $result = _r301task_addLinks($taskId, $batchLinks, $defaultCount);
                $addedCount = $result['added'];
            }
            
            // 构建进度信息
            $processed = $start + count($batchLinks);
            $progressMsg = sprintf('正在导入... %d/%d (%.1f%%)', 
                $processed, 
                count($allLinks), 
                min(100, $processed / count($allLinks) * 100)
            );
            
            if ($overwrittenCount > 0) {
                $progressMsg .= sprintf(' [本批新增 %d 条，覆盖 %d 条]', $addedCount, $overwrittenCount);
            } elseif ($skippedCount > 0) {
                $progressMsg .= sprintf(' [本批新增 %d 条，跳过 %d 条]', $addedCount, $skippedCount);
            }
            
            $response = [
                'success' => true,
                'is_batch' => true,
                'batch_index' => $batchIndex,
                'added' => $addedCount,
                'skipped' => $skippedCount,
                'overwritten' => $overwrittenCount,
                'total' => count($allLinks),
                'progress' => min(100, round($processed / count($allLinks) * 100, 1)),
                'message' => $progressMsg,
            ];
            break;
            
        // 删除单个链接
        case 'delete_link':
            $url = $_POST['url'] ?? '';
            if (_r301task_deleteLink($taskId, $url)) {
                $response = ['success' => true, 'message' => '链接已删除'];
            } else {
                $response = ['success' => false, 'message' => '删除失败'];
            }
            break;
            
        // 清空所有链接
        case 'clear_links':
            require_once __DIR__ . '/redis_config.php';
            
            // 优先清空 Redis
            $redisCleared = false;
            if (function_exists('clearTaskLinksFromRedis')) {
                $redisCleared = clearTaskLinksFromRedis($taskId);
            }
            
            // 同时清空 JSON 文件
            $jsonCleared = _r301task_clearLinks($taskId);
            
            if ($redisCleared || $jsonCleared) {
                $response = ['success' => true, 'message' => '链接已清空'];
            } else {
                $response = ['success' => false, 'message' => '清空失败'];
            }
            break;
            
        // 导出链接 (TXT)
        case 'export_links_txt':
            require_once __DIR__ . '/redis_config.php';
            
            // 优先从 Redis 导出
            if (function_exists('exportTaskLinksTxtFromRedis')) {
                $content = exportTaskLinksTxtFromRedis($taskId);
            } else {
                // 降级到 JSON 文件
                $links = _r301task_getLinks($taskId);
                if (empty($links)) {
                    $response = ['success' => false, 'message' => '没有可导出的链接'];
                    break;
                }
                
                $content = '';
                foreach ($links as $link) {
                    $url = $link['url'];
                    $count = $link['count'] ?? 1;
                    $used = $link['used'] ?? 0;
                    $remaining = $count - $used;
                    $content .= "{$url} {$remaining}\n";
                }
            }
            
            if (empty($content)) {
                $response = ['success' => false, 'message' => '没有可导出的链接'];
                break;
            }
            
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="task_' . $taskId . '_links_' . date('YmdHis') . '.txt"');
            echo $content;
            exit;
            
        // 导出链接 (CSV)
        case 'export_links_csv':
            require_once __DIR__ . '/redis_config.php';
            
            // 优先从 Redis 导出
            if (function_exists('exportTaskLinksCsvFromRedis')) {
                $content = exportTaskLinksCsvFromRedis($taskId);
                
                if (empty($content) || $content === "链接URL,总次数,已用次数,剩余次数,最后使用时间,创建时间\n") {
                    $response = ['success' => false, 'message' => '没有可导出的链接'];
                    break;
                }
                
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="task_' . $taskId . '_links_' . date('YmdHis') . '.csv"');
                echo "\xEF\xBB\xBF"; // UTF-8 BOM
                echo $content;
                exit;
            }
            
            // 降级到 JSON 文件
            $links = _r301task_getLinks($taskId);
            if (empty($links)) {
                $response = ['success' => false, 'message' => '没有可导出的链接'];
                break;
            }
            
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="task_' . $taskId . '_links_' . date('YmdHis') . '.csv"');
            
            // 输出 UTF-8 BOM（让 Excel 正确识别编码）
            echo "\xEF\xBB\xBF";
            
            // 输出表头
            echo "链接,总次数,已用次数,剩余次数\n";
            
            // 输出数据
            foreach ($links as $link) {
                $url = $link['url'];
                $count = $link['count'] ?? 1;
                $used = $link['used'] ?? 0;
                $remaining = max(0, $count - $used);
                
                // CSV 转义（如果包含逗号或引号，需要用引号包裹）
                if (strpos($url, ',') !== false || strpos($url, '"') !== false) {
                    $url = '"' . str_replace('"', '""', $url) . '"';
                }
                
                echo "{$url},{$count},{$used},{$remaining}\n";
            }
            exit;
            
        // 重置链接池（分批处理）
        case 'reset_links':
            // 增加执行时间限制
            @set_time_limit(300); // 5分钟
            
            require_once __DIR__ . '/redis_config.php';
            
            $newCount = max(1, intval($_POST['new_count'] ?? 1));
            $batchIndex = intval($_POST['batch_index'] ?? 0);
            $batchSize = intval($_POST['batch_size'] ?? 100); // 每批处理100条
            
            $redis = getRedis();
            if (!$redis) {
                $response = ['success' => false, 'message' => 'Redis 连接失败'];
                break;
            }
            
            $prefix = REDIS_TASK_PREFIX;
            $availableKey = "{$prefix}{$taskId}:available";
            
            // 第一批：获取总数并开始重置
            if ($batchIndex === 0) {
                // 获取所有链接键
                $pattern = "{$prefix}{$taskId}:link:*";
                $allKeys = $redis->keys($pattern);
                $total = count($allKeys);
                
                if ($total === 0) {
                    $response = ['success' => false, 'message' => '没有可重置的链接'];
                    break;
                }
                
                // 清空可用链接集合（准备重新添加）
                $redis->del($availableKey);
                
                // 开始处理第一批
                $batch = array_slice($allKeys, 0, $batchSize);
                $processed = 0;
                
                foreach ($batch as $linkKey) {
                    // 重置使用次数
                    $redis->hSet($linkKey, 'used', 0);
                    $redis->hDel($linkKey, 'last_used');
                    
                    // 更新跳转次数
                    $redis->hSet($linkKey, 'total', $newCount);
                    
                    // 提取 linkId 并重新添加到可用集合
                    $linkId = str_replace("{$prefix}{$taskId}:link:", '', $linkKey);
                    $redis->sAdd($availableKey, $linkId);
                    
                    $processed++;
                }
                
                $response = [
                    'success' => true,
                    'total' => $total,
                    'batch_size' => $batchSize,
                    'processed' => $processed,
                    'is_first_batch' => true
                ];
                
            } else {
                // 后续批次：继续重置
                $pattern = "{$prefix}{$taskId}:link:*";
                $allKeys = $redis->keys($pattern);
                $total = count($allKeys);
                
                $offset = ($batchIndex - 1) * $batchSize;
                $batch = array_slice($allKeys, $offset, $batchSize);
                $processed = 0;
                
                foreach ($batch as $linkKey) {
                    // 重置使用次数
                    $redis->hSet($linkKey, 'used', 0);
                    $redis->hDel($linkKey, 'last_used');
                    
                    // 更新跳转次数
                    $redis->hSet($linkKey, 'total', $newCount);
                    
                    // 提取 linkId 并重新添加到可用集合
                    $linkId = str_replace("{$prefix}{$taskId}:link:", '', $linkKey);
                    $redis->sAdd($availableKey, $linkId);
                    
                    $processed++;
                }
                
                $currentTotal = $offset + $processed;
                $isComplete = $currentTotal >= $total;
                
                // 如果是最后一批，更新统计并重置 JSON 文件
                if ($isComplete) {
                    // 更新 Redis 统计
                    $statsKey = "{$prefix}{$taskId}:stats";
                    $redis->hSet($statsKey, 'available_links', $total);
                    $redis->hSet($statsKey, 'total_redirects', 0);
                    $redis->hSet($statsKey, 'enabled', '1');
                    
                    // 同时重置 JSON 文件
                    _r301task_resetLinks($taskId, $newCount);
                    
                    // 清除自动停止标记并启用任务
                    $task = _r301task_getById($taskId);
                    if ($task && isset($task['auto_stopped_at'])) {
                        _r301task_update($taskId, [
                            'enabled' => true,
                            'auto_stopped_at' => null
                        ]);
                        _r301task_toggle($taskId, true);
                    }
                }
                
                $response = [
                    'success' => true,
                    'total' => $total,
                    'processed' => $processed,
                    'current_total' => $currentTotal,
                    'is_complete' => $isComplete
                ];
            }
            break;
            
        // 切换任务开关
        case 'toggle':
            $enabled = $_POST['enabled'] === '1';
            if (_r301task_toggle($taskId, $enabled)) {
                $response = ['success' => true, 'message' => $enabled ? '任务已启用' : '任务已停止'];
            } else {
                $response = ['success' => false, 'message' => '操作失败'];
            }
            break;
    }
    
    echo json_encode($response);
    exit;
}

// 重新获取任务信息（可能已更新）
require_once __DIR__ . '/redis_config.php';

$task = _r301task_getById($taskId);

// 优先从 Redis 获取链接和统计
$links = [];
$redisStats = null;

if (function_exists('getTaskLinksFromRedis')) {
    $links = getTaskLinksFromRedis($taskId, 100); // 限制显示前100条
    $redisStats = getTaskStatsFromRedis($taskId);
}

// 如果 Redis 没有数据，降级到 JSON 文件
if (empty($links)) {
    $links = _r301task_getLinks($taskId);
}
$conditions = $task['conditions'] ?? [];
$linksSettings = $task['links_settings'] ?? [];
$speedControl = $task['speed_control'] ?? ['enabled' => false];

require_once __DIR__ . '/header.php';
?>

<style>
.task-header-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

.task-header-bar .back-link {
    color: var(--text-muted);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 8px;
}

.task-header-bar .back-link:hover {
    color: var(--primary-light);
}

.task-status-bar {
    display: flex;
    align-items: center;
    gap: 16px;
}

.task-status-badge {
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 500;
}

.task-status-badge.running {
    background: rgba(34, 197, 94, 0.15);
    color: #22c55e;
}

.task-status-badge.stopped {
    background: rgba(239, 68, 68, 0.15);
    color: #ef4444;
}

.task-status-badge.completed {
    background: rgba(34, 197, 94, 0.15);
    color: #22c55e;
}

.section-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
}

@media (max-width: 1200px) {
    .section-grid {
        grid-template-columns: 1fr;
    }
}

/* 链接列表 */
.links-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.links-stats {
    display: flex;
    gap: 20px;
    font-size: 14px;
}

.links-stats span {
    color: var(--text-muted);
}

.links-stats strong {
    color: var(--text);
}

.links-table-wrapper {
    min-height: 300px;
    max-height: 650px;
    overflow-y: auto;
    overflow-x: hidden;
    border: 1px solid var(--border);
    border-radius: 8px;
}


.links-table {
    width: 100%;
    border-collapse: collapse;
}

.links-table th,
.links-table td {
    padding: 10px 12px;
    text-align: left;
    border-bottom: 1px solid var(--border);
}

.links-table tbody tr:last-child td {
    border-bottom: none;
}

.links-table th {
    background: var(--bg-dark);
    font-weight: 500;
    font-size: 13px;
    color: var(--text-muted);
    position: sticky;
    top: 0;
}

.links-table td {
    font-size: 13px;
}

.links-table tr:hover {
    background: var(--bg-dark);
}

.link-url {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.links-table {
    table-layout: fixed;
}

.links-table th:first-child,
.links-table td:first-child {
    width: auto;
}

.links-table th:nth-child(2),
.links-table td:nth-child(2) {
    width: 120px;
}

.links-table th:nth-child(3),
.links-table td:nth-child(3) {
    width: 60px;
    text-align: center;
}

.link-progress {
    display: flex;
    align-items: center;
    gap: 8px;
}

.progress-bar {
    width: 60px;
    height: 6px;
    background: var(--bg-dark);
    border-radius: 3px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: var(--primary);
    transition: width 0.3s;
}

.progress-fill.complete {
    background: var(--success);
}

.empty-links {
    text-align: center;
    padding: 40px;
    color: var(--text-muted);
    border: 2px dashed var(--border);
    border-radius: 8px;
    width: 100%;
    box-sizing: border-box;
}

/* 导入模态框 */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    z-index: 1000;
    justify-content: center;
    align-items: center;
}

.modal-overlay.active {
    display: flex;
}

.modal-content {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 24px;
    width: 100%;
    max-width: 600px;
    max-height: 80vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.modal-title {
    font-size: 18px;
    font-weight: 600;
}

.modal-close {
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 24px;
    cursor: pointer;
}

/* 导出菜单样式 */
#exportMenu button:hover {
    background: var(--bg-hover) !important;
}
</style>

<div class="task-header-bar">
    <a href="tasks.php" class="back-link">← 返回任务列表</a>
    <div class="task-status-bar">
        <button class="btn btn-secondary" onclick="downloadExport()" title="下载任务配置文件">📥 下载配置</button>
        <button class="btn btn-secondary" onclick="copyExportLink()" title="复制导出链接，可在其他服务器导入此任务">🔗 复制链接</button>
        <?php if ($task['enabled']): ?>
            <span class="task-status-badge running">🟢 运行中</span>
            <button class="btn btn-warning" onclick="toggleTask(false)">⏸️ 停止任务</button>
        <?php elseif (!empty($task['auto_stopped_at'])): ?>
            <span class="task-status-badge completed">✅ 已完成</span>
            <span style="font-size: 12px; color: var(--text-muted);">自动停止于 <?php echo $task['auto_stopped_at']; ?></span>
            <button class="btn btn-success" onclick="toggleTask(true)">🔄 重新启动</button>
        <?php else: ?>
            <span class="task-status-badge stopped">🔴 已停止</span>
            <button class="btn btn-success" onclick="toggleTask(true)">▶️ 启动任务</button>
        <?php endif; ?>
    </div>
</div>

<div class="page-header" style="margin-bottom: 24px;">
    <div style="display: flex; align-items: center; justify-content: space-between;">
        <div>
            <h1 class="page-title"><?php echo htmlspecialchars($task['name']); ?></h1>
            <p class="page-subtitle">
                创建于 <?php echo $task['created_at']; ?> · 
                已跳转 <?php echo $task['stats']['total_redirects'] ?? 0; ?> 次
            </p>
        </div>
        <div>
            <?php require_once __DIR__ . '/help_modal.php'; renderHelpModal('task'); ?>
        </div>
    </div>
</div>

<div class="section-grid">
    <!-- 左侧：触发条件配置 -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">⚙️ 触发条件配置</h3>
        </div>
        
        <form id="settingsForm">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">任务名称</label>
                    <input type="text" name="name" class="form-input" value="<?php echo htmlspecialchars($task['name']); ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">跳转方式</label>
                    <select name="redirect_type" class="form-select">
                        <option value="302" <?php echo ($task['redirect_type'] ?? '302') === '302' ? 'selected' : ''; ?>>302 临时重定向</option>
                        <option value="301" <?php echo ($task['redirect_type'] ?? '302') === '301' ? 'selected' : ''; ?>>301 永久重定向</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">页面过滤</label>
                <select name="page_filter" class="form-select">
                    <option value="all" <?php echo ($conditions['page_filter'] ?? 'all') === 'all' ? 'selected' : ''; ?>>所有页面</option>
                    <option value="inner" <?php echo ($conditions['page_filter'] ?? 'all') === 'inner' ? 'selected' : ''; ?>>仅内页跳转</option>
                </select>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">默认跳转次数</label>
                    <input type="number" name="default_count" class="form-input" 
                           value="<?php echo $linksSettings['default_count'] ?? 1; ?>" min="1">
                    <p class="form-hint">导入链接时的默认次数</p>
                </div>
                <div class="form-group">
                    <label class="form-label">链接选择方式</label>
                    <select name="selection_mode" class="form-select">
                        <option value="random" <?php echo ($linksSettings['selection_mode'] ?? 'random') === 'random' ? 'selected' : ''; ?>>随机选择</option>
                        <option value="sequential" <?php echo ($linksSettings['selection_mode'] ?? 'random') === 'sequential' ? 'selected' : ''; ?>>顺序轮询</option>
                    </select>
                </div>
            </div>
            
            <!-- 速度控制配置 -->
            <div class="form-group" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border);">
                <label class="spider-item" style="background: var(--bg-card); border: 1px solid var(--border); margin-bottom: 12px;">
                    <input type="checkbox" name="speed_control_enabled" id="speedControlEnabled" 
                           <?php echo !empty($speedControl['enabled']) ? 'checked' : ''; ?>
                           onchange="toggleSpeedControl()">
                    <span>⏱️ 启用速度控制（时间锁）</span>
                </label>
                
                <div class="speed-control-detail <?php echo empty($speedControl['enabled']) ? 'hidden' : ''; ?>" id="speedControlDetail" style="background: var(--bg-hover); padding: 16px; border-radius: 8px; border: 1px solid var(--border);">
                    <div class="form-group">
                        <label class="form-label">限速维度</label>
                        <select name="speed_dimension" class="form-select">
                            <option value="task" <?php echo ($speedControl['dimension'] ?? 'task') === 'task' ? 'selected' : ''; ?>>按任务限速（推荐：少量域名）</option>
                            <option value="domain" <?php echo ($speedControl['dimension'] ?? 'task') === 'domain' ? 'selected' : ''; ?>>按域名限速（推荐：单域名多内页）</option>
                        </select>
                        <p class="form-hint">
                            • 按域名：每个域名独立计数，适合单域名任务<br>
                            • 按任务：整个任务共享限制，适合多域名任务
                        </p>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">最小间隔（秒）</label>
                            <input type="number" name="speed_min_interval" class="form-input" 
                                   value="<?php echo $speedControl['min_interval'] ?? 60; ?>" min="0">
                            <p class="form-hint">两次跳转的最小时间间隔</p>
                        </div>
                        <div class="form-group">
                            <label class="form-label">每小时限制（次）</label>
                            <input type="number" name="speed_max_per_hour" class="form-input" 
                                   value="<?php echo $speedControl['max_per_hour'] ?? 10; ?>" min="0">
                            <p class="form-hint">0 表示不限制</p>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">每天限制（次）</label>
                        <input type="number" name="speed_max_per_day" class="form-input" 
                               value="<?php echo $speedControl['max_per_day'] ?? 100; ?>" min="0">
                        <p class="form-hint">0 表示不限制</p>
                    </div>
                    
                    <div style="background: var(--warning-bg); border: 1px solid var(--warning); border-radius: 6px; padding: 12px; margin-top: 12px;">
                        <p style="margin: 0; font-size: 13px; color: var(--warning);">
                            💡 <strong>使用建议：</strong><br>
                            • 单域名多内页：建议启用"按域名限速"，避免同一域名频繁跳转<br>
                            • 多域名多内页：可以不启用速度控制，快速消耗链接
                        </p>
                    </div>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 12px;">💾 保存设置</button>
        </form>
    </div>
    
    <!-- 右侧：链接管理 -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🔗 链接池管理</h3>
        </div>
        
        <div class="links-header">
            <div class="links-stats">
                <?php if ($redisStats): ?>
                    <span>总计 <strong id="totalLinks"><?php echo $redisStats['total_links']; ?></strong> 条</span>
                    <span>剩余 <strong id="remainingLinks"><?php echo $redisStats['available_links']; ?></strong> 条</span>
                    <span>已跳转 <strong><?php echo $redisStats['total_redirects']; ?></strong> 次</span>
                    <span style="color: var(--success); font-size: 12px;">⚡ Redis 模式</span>
                <?php else: ?>
                    <span>总计 <strong id="totalLinks"><?php echo count($links); ?></strong> 条</span>
                    <span>剩余 <strong id="remainingLinks"><?php echo $task['stats']['remaining_links'] ?? 0; ?></strong> 条</span>
                    <span style="color: var(--text-muted); font-size: 12px;">📄 JSON 模式</span>
                <?php endif; ?>
            </div>
            <div style="display: flex; gap: 10px;">
                <button class="btn btn-secondary btn-sm" onclick="showImportModal()">📥 导入</button>
                <button class="btn btn-primary btn-sm" onclick="showExportMenu(event)">📤 导出</button>
                <button class="btn btn-warning btn-sm" onclick="showResetModal()">🔄 重置</button>
                <button class="btn btn-danger btn-sm" onclick="clearAllLinks()">🗑️ 清空</button>
            </div>
        </div>
        
        <?php if (empty($links)): ?>
        <div class="empty-links">
            <p>📭 暂无链接</p>
            <p style="font-size: 13px;">点击「导入链接」添加跳转目标</p>
        </div>
        <?php else: ?>
        <div class="links-table-wrapper">
            <table class="links-table">
                <thead>
                    <tr>
                        <th>链接</th>
                        <th style="width: 120px;">进度</th>
                        <th style="width: 60px;">操作</th>
                    </tr>
                </thead>
                <tbody id="linksTableBody">
                    <?php foreach ($links as $link): 
                        $used = $link['used'] ?? 0;
                        $count = $link['count'] ?? 1;
                        $percent = $count > 0 ? min(100, round($used / $count * 100)) : 0;
                        $isComplete = $used >= $count;
                    ?>
                    <tr>
                        <td>
                            <div class="link-url" title="<?php echo htmlspecialchars($link['url']); ?>">
                                <?php echo htmlspecialchars($link['url']); ?>
                            </div>
                        </td>
                        <td>
                            <div class="link-progress">
                                <div class="progress-bar">
                                    <div class="progress-fill <?php echo $isComplete ? 'complete' : ''; ?>" style="width: <?php echo $percent; ?>%"></div>
                                </div>
                                <span style="font-size: 12px; color: var(--text-muted);"><?php echo $used; ?>/<?php echo $count; ?></span>
                            </div>
                        </td>
                        <td>
                            <button class="btn btn-danger btn-sm" onclick="deleteLink('<?php echo htmlspecialchars(addslashes($link['url'])); ?>')" style="padding: 4px 8px;">🗑️</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 导入链接模态框 -->
<div class="modal-overlay" id="importModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">📥 批量导入链接</h3>
            <button class="modal-close" onclick="hideImportModal()">&times;</button>
        </div>
        
        <form onsubmit="importLinks(event)">
            <div class="form-group">
                <label class="form-label">链接列表</label>
                <textarea id="linksText" class="form-textarea" rows="10" placeholder="每行一个链接，支持以下格式：
https://example.com/page1
https://example.com/page2,3
https://example.com/page3 5

格式说明：
- 纯链接：使用默认跳转次数
- 链接,次数：指定跳转次数
- 链接 次数：空格分隔也可以"></textarea>
            </div>
            
            <div class="form-group">
                <label class="form-label">默认跳转次数</label>
                <input type="number" id="importDefaultCount" class="form-input" value="<?php echo $linksSettings['default_count'] ?? 1; ?>" min="1">
                <p class="form-hint">未指定次数的链接使用此值</p>
            </div>
            
            <div style="display: flex; gap: 12px; margin-top: 20px;">
                <button type="button" class="btn btn-secondary" onclick="hideImportModal()" style="flex: 1;">取消</button>
                <button type="submit" class="btn btn-primary" style="flex: 1;">导入</button>
            </div>
        </form>
    </div>
</div>

<!-- 重置链接池弹窗 -->
<div class="modal-overlay" id="resetModal" style="display: none;">
    <div class="modal-content" style="max-width: 450px;">
        <div class="modal-header">
            <h3 class="modal-title">🔄 重置链接池</h3>
            <button class="modal-close" onclick="hideResetModal()">&times;</button>
        </div>
        
        <div style="padding: 20px;">
            <p style="color: var(--text); margin-bottom: 16px;">
                重置链接池将保留所有链接，但会清零已使用次数，让任务重新开始。
            </p>
            
            <div class="form-group">
                <label class="form-label">新的跳转次数</label>
                <input type="number" id="resetCount" class="form-input" value="<?php echo $linksSettings['default_count'] ?? 1; ?>" min="1">
                <p class="form-hint">每个链接将被设置为此跳转次数</p>
            </div>
            
            <div style="display: flex; gap: 12px; margin-top: 20px;">
                <button type="button" class="btn btn-secondary" onclick="hideResetModal()" style="flex: 1;">取消</button>
                <button type="button" class="btn btn-warning" onclick="confirmReset()" style="flex: 1;">确认重置</button>
            </div>
        </div>
    </div>
</div>

<!-- 导出菜单 -->
<div id="exportMenu" style="display: none; position: absolute; background: var(--bg-card); border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 1000; min-width: 150px;">
    <div style="padding: 8px;">
        <button onclick="exportLinks('txt')" style="width: 100%; padding: 10px; text-align: left; background: none; border: none; color: var(--text); cursor: pointer; border-radius: 4px; display: flex; align-items: center; gap: 8px;">
            <span>📄</span>
            <span>导出为 TXT</span>
        </button>
        <button onclick="exportLinks('csv')" style="width: 100%; padding: 10px; text-align: left; background: none; border: none; color: var(--text); cursor: pointer; border-radius: 4px; display: flex; align-items: center; gap: 8px; margin-top: 4px;">
            <span>📊</span>
            <span>导出为 CSV</span>
        </button>
    </div>
</div>

<script>
const taskId = '<?php echo $taskId; ?>';

function toggleSpeedControl() {
    const enabled = document.getElementById('speedControlEnabled').checked;
    document.getElementById('speedControlDetail').classList.toggle('hidden', !enabled);
}

// 保存设置
document.getElementById('settingsForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('ajax', '1');
    formData.append('action', 'save_settings');
    
    fetch('task.php?id=' + taskId, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            location.reload();
        }
    })
    .catch(err => alert('保存失败：' + err.message));
});

// 切换任务开关
function toggleTask(enabled) {
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'toggle');
    formData.append('enabled', enabled ? '1' : '0');
    
    fetch('task.php?id=' + taskId, {
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
    })
    .catch(err => alert('操作失败：' + err.message));
}

// 显示导入模态框
function showImportModal() {
    document.getElementById('importModal').classList.add('active');
    document.getElementById('linksText').focus();
}

// 隐藏导入模态框
function hideImportModal() {
    document.getElementById('importModal').classList.remove('active');
    document.getElementById('linksText').value = '';
}

// 导入链接
async function importLinks(e) {
    e.preventDefault();
    
    const text = document.getElementById('linksText').value.trim();
    if (!text) {
        alert('请输入链接');
        return;
    }
    
    const defaultCount = document.getElementById('importDefaultCount').value;
    const submitBtn = e.target.querySelector('button[type="submit"]');
    const originalBtnText = submitBtn.textContent;
    
    // 禁用提交按钮
    submitBtn.disabled = true;
    submitBtn.textContent = '正在检查...';
    
    try {
        // 第一步：检查已存在的链接
        const checkFormData = new FormData();
        checkFormData.append('ajax', '1');
        checkFormData.append('action', 'check_existing_links');
        checkFormData.append('links_text', text);
        checkFormData.append('import_default_count', defaultCount);
        
        const checkResponse = await fetch('task.php?id=' + taskId, {
            method: 'POST',
            body: checkFormData
        });
        
        const checkData = await checkResponse.json();
        
        if (!checkData.success) {
            alert('检查失败：' + checkData.message);
            submitBtn.disabled = false;
            submitBtn.textContent = originalBtnText;
            return;
        }
        
        // 如果有已存在的链接，显示确认对话框
        if (checkData.existing_count > 0) {
            const shouldOverwrite = showExistingLinksConfirm(checkData);
            // shouldOverwrite: true=覆盖, false=跳过
            // 继续导入，传递 overwrite 参数
            await performImport(text, defaultCount, submitBtn, originalBtnText, shouldOverwrite);
        } else {
            // 没有已存在的链接，直接导入
            await performImport(text, defaultCount, submitBtn, originalBtnText, false);
        }
    } catch (err) {
        alert('导入失败：' + err.message);
        submitBtn.disabled = false;
        submitBtn.textContent = originalBtnText;
    }
}

// 显示已存在链接的确认对话框
function showExistingLinksConfirm(data) {
    const existing = data.existing;
    const existingCount = data.existing_count;
    const newCount = data.new_count;
    
    let message = `检测到 ${existingCount} 条链接已存在：\n\n`;
    
    // 显示前 5 条已存在的链接
    const displayCount = Math.min(5, existing.length);
    for (let i = 0; i < displayCount; i++) {
        const item = existing[i];
        const info = item.info || {};
        message += `${i + 1}. ${item.url}\n`;
        if (info.total !== undefined) {
            message += `   已跳转: ${info.used || 0}/${info.total} 次\n`;
        }
        message += `\n`;
    }
    
    if (existingCount > displayCount) {
        message += `... 还有 ${existingCount - displayCount} 条已存在\n\n`;
    }
    
    if (newCount > 0) {
        message += `${newCount} 条链接是新的\n\n`;
    }
    
    message += `是否覆盖已存在的链接？\n\n`;
    message += `点击"确定"覆盖已存在的链接（重置跳转次数）\n`;
    message += `点击"取消"跳过已存在的链接`;
    
    const result = confirm(message);
    return result; // true=覆盖, false=跳过
}

// 执行导入
async function performImport(text, defaultCount, submitBtn, originalBtnText, overwrite) {
    submitBtn.textContent = '正在解析...';
    
    try {
        // 第二步：解析并导入链接
        const formData = new FormData();
        formData.append('ajax', '1');
        formData.append('action', 'import_links');
        formData.append('links_text', text);
        formData.append('import_default_count', defaultCount);
        formData.append('batch_index', '0');
        formData.append('batch_size', '100'); // 每批100条
        formData.append('overwrite', overwrite ? '1' : '0'); // 是否覆盖
        
        const response = await fetch('task.php?id=' + taskId, {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (!data.success) {
            alert(data.message);
            submitBtn.disabled = false;
            submitBtn.textContent = originalBtnText;
            return;
        }
        
        // 如果不是分批模式（链接数量少），直接完成
        if (!data.is_batch) {
            alert(data.message);
            location.reload();
            return;
        }
        
        // 分批上传
        const total = data.total;
        const batchSize = data.batch_size;
        const tempFile = data.temp_file;
        const totalBatches = Math.ceil(total / batchSize);
        
        let totalAdded = 0;
        let totalSkipped = 0;
        let totalOverwritten = 0;
        
        submitBtn.textContent = `正在导入 0/${total} (0%)`;
        
        // 逐批上传
        for (let i = 1; i <= totalBatches; i++) {
            const batchFormData = new FormData();
            batchFormData.append('ajax', '1');
            batchFormData.append('action', 'import_links');
            batchFormData.append('batch_index', i.toString());
            batchFormData.append('batch_size', batchSize.toString());
            batchFormData.append('temp_file', tempFile);
            batchFormData.append('import_default_count', defaultCount);
            batchFormData.append('overwrite', overwrite ? '1' : '0');
            
            const batchResponse = await fetch('task.php?id=' + taskId, {
                method: 'POST',
                body: batchFormData
            });
            
            const batchData = await batchResponse.json();
            
            if (!batchData.success) {
                alert('导入失败：' + batchData.message);
                submitBtn.disabled = false;
                submitBtn.textContent = originalBtnText;
                return;
            }
            
            // 累计统计
            totalAdded += (batchData.added || 0);
            totalSkipped += (batchData.skipped || 0);
            totalOverwritten += (batchData.overwritten || 0);
            
            // ★ 调试日志
            console.log(`批次 ${i}:`, {
                added: batchData.added,
                skipped: batchData.skipped,
                overwritten: batchData.overwritten,
                totalAdded,
                totalSkipped,
                totalOverwritten
            });
            
            // 更新进度
            if (batchData.is_complete) {
                submitBtn.textContent = '导入完成！';
                let msg = `成功处理 ${totalAdded + totalOverwritten} 条链接！`;
                if (totalAdded > 0 && totalOverwritten > 0) {
                    msg += `\n（新增 ${totalAdded} 条，覆盖 ${totalOverwritten} 条）`;
                } else if (totalOverwritten > 0) {
                    msg += `\n（覆盖 ${totalOverwritten} 条已存在）`;
                } else if (totalAdded > 0) {
                    msg += `\n（新增 ${totalAdded} 条）`;
                }
                if (totalSkipped > 0) {
                    msg += `\n跳过 ${totalSkipped} 条已存在的链接`;
                }
                alert(msg);
                setTimeout(() => location.reload(), 1000);
                return;
            } else {
                submitBtn.textContent = batchData.message;
            }
            
            // 短暂延迟，避免服务器压力过大
            await new Promise(resolve => setTimeout(resolve, 200));
        }
        
        // 所有批次处理完成，显示最终结果
        submitBtn.textContent = '导入完成！';
        
        // ★ 调试日志
        console.log('最终统计:', {
            totalAdded,
            totalSkipped,
            totalOverwritten,
            total: totalAdded + totalOverwritten
        });
        
        let msg = `成功处理 ${totalAdded + totalOverwritten} 条链接！`;
        if (totalAdded > 0 && totalOverwritten > 0) {
            msg += `\n（新增 ${totalAdded} 条，覆盖 ${totalOverwritten} 条）`;
        } else if (totalOverwritten > 0) {
            msg += `\n（覆盖 ${totalOverwritten} 条已存在）`;
        } else if (totalAdded > 0) {
            msg += `\n（新增 ${totalAdded} 条）`;
        }
        if (totalSkipped > 0) {
            msg += `\n跳过 ${totalSkipped} 条已存在的链接`;
        }
        alert(msg);
        setTimeout(() => location.reload(), 1000);
        
    } catch (err) {
        alert('导入失败：' + err.message);
        submitBtn.disabled = false;
        submitBtn.textContent = originalBtnText;
    }
}

// 删除单个链接
function deleteLink(url) {
    if (!confirm('确定要删除这个链接吗？')) return;
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'delete_link');
    formData.append('url', url);
    
    fetch('task.php?id=' + taskId, {
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
    })
    .catch(err => alert('删除失败：' + err.message));
}

// 清空所有链接
function clearAllLinks() {
    if (!confirm('确定要清空所有链接吗？此操作无法恢复！')) return;
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'clear_links');
    
    fetch('task.php?id=' + taskId, {
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
    })
    .catch(err => alert('清空失败：' + err.message));
}

// 显示导出菜单
function showExportMenu(event) {
    const menu = document.getElementById('exportMenu');
    const button = event.target.closest('button');
    const rect = button.getBoundingClientRect();
    
    menu.style.display = 'block';
    menu.style.top = (rect.bottom + 5) + 'px';
    menu.style.left = rect.left + 'px';
    
    // 点击其他地方关闭菜单
    setTimeout(() => {
        document.addEventListener('click', function closeMenu(e) {
            if (!menu.contains(e.target) && e.target !== button) {
                menu.style.display = 'none';
                document.removeEventListener('click', closeMenu);
            }
        });
    }, 0);
}

// 导出链接
function exportLinks(format) {
    const menu = document.getElementById('exportMenu');
    menu.style.display = 'none';
    
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'export_links_' + format);
    
    // 使用表单提交来下载文件
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'task.php?id=' + taskId;
    form.style.display = 'none';
    
    for (let [key, value] of formData.entries()) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = value;
        form.appendChild(input);
    }
    
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

// 显示重置弹窗
function showResetModal() {
    document.getElementById('resetModal').style.display = 'flex';
}

// 隐藏重置弹窗
function hideResetModal() {
    document.getElementById('resetModal').style.display = 'none';
}

// 确认重置
async function confirmReset() {
    const newCount = parseInt(document.getElementById('resetCount').value);
    
    if (newCount < 1) {
        alert('跳转次数必须大于0');
        return;
    }
    
    if (!confirm(`确定要重置链接池吗？\n\n所有链接的已用次数将清零，每个链接将设置为 ${newCount} 次跳转。`)) {
        return;
    }
    
    // 隐藏重置弹窗
    hideResetModal();
    
    // 显示进度提示
    const progressDiv = document.createElement('div');
    progressDiv.id = 'resetProgressDiv';
    progressDiv.style.cssText = 'position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: var(--bg-card); padding: 30px 40px; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); z-index: 10000; text-align: center; min-width: 300px;';
    progressDiv.innerHTML = `
        <div style="font-size: 48px; margin-bottom: 20px;">⏳</div>
        <div id="resetProgressText" style="font-size: 16px; color: var(--text); margin-bottom: 15px;">正在重置链接池...</div>
        <div style="background: var(--bg-dark); height: 8px; border-radius: 4px; overflow: hidden; margin-bottom: 10px;">
            <div id="resetProgressBar" style="background: var(--primary); height: 100%; width: 0%; transition: width 0.3s;"></div>
        </div>
        <div id="resetProgressDetail" style="font-size: 13px; color: var(--text-muted);">准备中...</div>
    `;
    document.body.appendChild(progressDiv);
    
    try {
        // 开始分批重置
        await performReset(newCount);
    } catch (err) {
        document.getElementById('resetProgressDiv')?.remove();
        alert('重置失败：' + err.message);
    }
}

// 执行分批重置
async function performReset(newCount) {
    const batchSize = 100; // 每批处理100条
    let batchIndex = 0;
    let total = 0;
    let currentTotal = 0;
    
    // 第一批：获取总数并开始重置
    const firstFormData = new FormData();
    firstFormData.append('ajax', '1');
    firstFormData.append('action', 'reset_links');
    firstFormData.append('new_count', newCount);
    firstFormData.append('batch_index', '0');
    firstFormData.append('batch_size', batchSize.toString());
    
    const firstResponse = await fetch('task.php?id=' + taskId, {
        method: 'POST',
        body: firstFormData
    });
    
    const firstData = await firstResponse.json();
    
    if (!firstData.success) {
        throw new Error(firstData.message || '重置失败');
    }
    
    total = firstData.total;
    currentTotal = firstData.processed;
    
    // 更新进度
    updateResetProgress(currentTotal, total);
    
    // 如果第一批就完成了（链接数 <= 100）
    if (currentTotal >= total) {
        document.getElementById('resetProgressDiv')?.remove();
        alert('链接池已重置，任务已重新启用');
        location.reload();
        return;
    }
    
    // 继续处理后续批次
    const totalBatches = Math.ceil(total / batchSize);
    
    for (let i = 1; i < totalBatches; i++) {
        const formData = new FormData();
        formData.append('ajax', '1');
        formData.append('action', 'reset_links');
        formData.append('new_count', newCount);
        formData.append('batch_index', (i + 1).toString());
        formData.append('batch_size', batchSize.toString());
        
        const response = await fetch('task.php?id=' + taskId, {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.message || '重置失败');
        }
        
        currentTotal = data.current_total;
        
        // 更新进度
        updateResetProgress(currentTotal, total);
        
        // 如果完成了
        if (data.is_complete) {
            break;
        }
    }
    
    // 全部完成
    document.getElementById('resetProgressDiv')?.remove();
    alert('链接池已重置，任务已重新启用');
    location.reload();
}

// 更新重置进度
function updateResetProgress(current, total) {
    const percent = Math.min(100, Math.round((current / total) * 100));
    
    const progressText = document.getElementById('resetProgressText');
    const progressBar = document.getElementById('resetProgressBar');
    const progressDetail = document.getElementById('resetProgressDetail');
    
    if (progressText) {
        progressText.textContent = `正在重置链接池... ${percent}%`;
    }
    
    if (progressBar) {
        progressBar.style.width = percent + '%';
    }
    
    if (progressDetail) {
        progressDetail.textContent = `已处理 ${current.toLocaleString()} / ${total.toLocaleString()} 条链接`;
    }
}

// 下载配置文件
function downloadExport() {
    window.location.href = 'task_export.php?id=' + taskId + '&download=1';
}

// 复制导出链接
function copyExportLink() {
    // 构建导出链接
    const baseUrl = window.location.origin + window.location.pathname.replace('task.php', 'task_export.php');
    const exportUrl = baseUrl + '?id=' + taskId;
    
    // 复制到剪贴板
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(exportUrl).then(function() {
            showCopySuccess();
        }).catch(function() {
            fallbackCopy(exportUrl);
        });
    } else {
        fallbackCopy(exportUrl);
    }
}

// 兼容旧浏览器的复制方法
function fallbackCopy(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    try {
        document.execCommand('copy');
        showCopySuccess();
    } catch (e) {
        prompt('请手动复制以下链接:', text);
    }
    document.body.removeChild(textarea);
}

// 显示复制成功提示
function showCopySuccess() {
    const toast = document.createElement('div');
    toast.textContent = '✅ 导出链接已复制到剪贴板';
    toast.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);background:#22c55e;color:#fff;padding:12px 24px;border-radius:8px;z-index:9999;font-size:14px;box-shadow:0 4px 12px rgba(0,0,0,0.3);';
    document.body.appendChild(toast);
    setTimeout(function() {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s';
        setTimeout(function() { toast.remove(); }, 300);
    }, 2000);
}

// 模态框点击外部关闭
document.getElementById('importModal').addEventListener('click', function(e) {
    if (e.target === this) hideImportModal();
});

document.getElementById('resetModal').addEventListener('click', function(e) {
    if (e.target === this) hideResetModal();
});

// ESC 关闭模态框
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        hideImportModal();
        hideResetModal();
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

