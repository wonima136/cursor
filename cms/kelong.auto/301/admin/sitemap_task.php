<?php
/**
 * 地图重定向 - 任务详情/编辑
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sitemap_functions.php';

// 判断是否为 AJAX 请求
$isAjax = $_SERVER['REQUEST_METHOD'] === 'POST';

// 检查登录
if (!checkLogin()) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '登录已过期，请刷新页面重新登录']);
        exit;
    }
    header('Location: login.php');
    exit;
}

$taskId = $_GET['id'] ?? '';
if (empty($taskId)) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '任务ID无效']);
        exit;
    }
    header('Location: sitemap_tasks.php');
    exit;
}

$task = _sitemap_getById($taskId);
if (!$task) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '任务不存在']);
        exit;
    }
    header('Location: sitemap_tasks.php');
    exit;
}

$pageTitle = htmlspecialchars($task['name']) . ' - 地图重定向';

// 处理AJAX请求
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'save_settings':
            // 保存基础设置
            $domains = $_POST['domains'] ?? '';
            $domains = array_filter(array_map('trim', explode("\n", $domains)));
            
            $sitemapPath = trim($_POST['sitemap_path'] ?? '/sitemap.xml');
            
            $domainRatio = intval($_POST['domain_ratio'] ?? 30);
            $domainRatio = max(0, min(100, $domainRatio));
            $innerRatio = 100 - $domainRatio;
            
            $maxLinks = intval($_POST['max_links'] ?? 50);
            $maxLinks = max(10, $maxLinks);
            
            $includeDirectory = !empty($_POST['include_directory']);
            
            $specifiedPaths = $_POST['specified_paths'] ?? '';
            $specifiedPaths = array_filter(array_map('trim', explode("\n", $specifiedPaths)));
            
            $redirectType = $_POST['redirect_type'] ?? '301';
            
            $updateData = [
                'domains' => $domains,
                'sitemap_path' => $sitemapPath,
                'ratio' => [
                    'domain' => $domainRatio,
                    'inner' => $innerRatio
                ],
                'max_links' => $maxLinks,
                'include_directory' => $includeDirectory,
                'specified_paths' => $specifiedPaths,
                'redirect_type' => $redirectType
            ];
            
            if (_sitemap_update($taskId, $updateData)) {
                $response = ['success' => true, 'message' => '设置已保存'];
            } else {
                $response = ['success' => false, 'message' => '保存失败'];
            }
            break;
            
        case 'clear_cache':
            $domain = $_POST['domain'] ?? '';
            if (empty($domain)) {
                echo json_encode(['success' => false, 'message' => '域名不能为空']);
                exit;
            }
            
            if (_sitemap_clearDomainCache($taskId, $domain)) {
                echo json_encode(['success' => true, 'message' => '缓存已清除']);
            } else {
                echo json_encode(['success' => false, 'message' => '清除失败']);
            }
            exit;
            
        case 'clear_failure':
            $domain = $_POST['domain'] ?? '';
            if (_sitemap_clearFailureRecords($taskId, $domain)) {
                echo json_encode(['success' => true, 'message' => '失败记录已清除']);
            } else {
                echo json_encode(['success' => false, 'message' => '清除失败']);
            }
            exit;
            
        case 'test_fetch':
            $domain = $_POST['domain'] ?? '';
            $sitemapPath = $_POST['sitemap_path'] ?? '/sitemap.xml';
            
            if (empty($domain)) {
                echo json_encode(['success' => false, 'message' => '域名不能为空']);
                exit;
            }
            
            $result = _sitemap_testFetch($domain, $sitemapPath);
            echo json_encode($result);
            exit;
            
        default:
            $response = ['success' => false, 'message' => '未知操作'];
            break;
    }
    
    // 输出响应（如果还没有exit）
    if (isset($response)) {
        echo json_encode($response);
    }
    exit;
}

// 获取统计数据
$stats = $task['stats'];
$total = intval($stats['total'] ?? 0);
$domainJumps = intval($stats['domain_jumps'] ?? 0);
$innerJumps = intval($stats['inner_jumps'] ?? 0);

// 获取内页TOP排行
$innerTop = getSitemapInnerPageTop($taskId, 10);

// 获取失败记录
$failures = _sitemap_getFailureRecords($taskId);

// 计算域名跳转分布
$domainStats = [];
foreach ($task['domains'] as $domain) {
    $count = intval($stats[$domain] ?? 0);
    if ($count > 0) {
        $domainStats[$domain] = $count;
    }
}

require_once __DIR__ . '/header.php';
?>

<style>
/* 页面头部 */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

.page-title {
    font-size: 24px;
    font-weight: 600;
    color: var(--text);
    margin: 0;
}

.page-subtitle {
    color: var(--text-muted);
    font-size: 14px;
    margin-top: 4px;
}

/* 卡片 */
.card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border);
}

.card-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--text);
    margin: 0;
}

/* 统计卡片 */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
    transition: all 0.2s;
}

.stat-card:hover {
    border-color: var(--primary);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1);
}

.stat-card h3 {
    margin: 0 0 12px 0;
    font-size: 13px;
    color: var(--text-muted);
    font-weight: 500;
}

.stat-value {
    font-size: 28px;
    font-weight: 600;
    color: var(--text);
    margin: 0;
}

/* 表单样式 */
.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: var(--text);
    font-size: 14px;
}

.form-control {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 14px;
    background: var(--bg-dark);
    color: var(--text);
    transition: all 0.2s;
    font-family: inherit;
}

.form-control:focus {
    outline: none;
    border-color: var(--primary);
    background: var(--bg-card);
}

.form-textarea {
    resize: vertical;
    min-height: 100px;
    font-family: monospace;
}

.form-hint {
    margin-top: 6px;
    font-size: 12px;
    color: var(--text-muted);
}

/* 表单行布局 */
.form-row-2col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

.form-row-2col .form-group {
    margin-bottom: 0;
}

.form-row-3col {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}

.form-row-3col .form-group {
    margin-bottom: 0;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    user-select: none;
    padding: 8px 0;
}

.checkbox-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.checkbox-label span {
    color: var(--text);
    font-size: 14px;
}

/* 响应式 */
@media (max-width: 768px) {
    .form-row-2col,
    .form-row-3col {
        grid-template-columns: 1fr;
    }
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    user-select: none;
}

.checkbox-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

/* 按钮样式 */
.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-sm {
    padding: 6px 12px;
    font-size: 12px;
    border-radius: 6px;
}

.btn-primary {
    background: var(--primary);
    color: white;
}

.btn-primary:hover {
    background: var(--primary-hover);
}

.btn-secondary {
    background: var(--bg-dark);
    color: var(--text);
    border: 1px solid var(--border);
}

.btn-secondary:hover {
    background: var(--bg-hover);
}

.btn-success {
    background: var(--success);
    color: white;
}

.btn-success:hover {
    opacity: 0.9;
}

.btn-danger {
    background: var(--error);
    color: white;
}

.btn-danger:hover {
    opacity: 0.9;
}

.failure-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.failure-item {
    padding: 16px;
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 6px;
    margin-bottom: 12px;
}

.failure-item:last-child {
    margin-bottom: 0;
}

.failure-domain {
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 8px;
}

.failure-meta {
    display: flex;
    gap: 16px;
    font-size: 13px;
    color: var(--text-secondary);
    margin-bottom: 8px;
}

.failure-error {
    font-size: 13px;
    color: var(--danger);
    padding: 8px;
    background: rgba(220, 53, 69, 0.1);
    border-radius: 4px;
    margin-bottom: 8px;
}

.top-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.top-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    border-bottom: 1px solid var(--border);
}

.top-item:last-child {
    border-bottom: none;
}

.top-rank {
    font-weight: 600;
    color: var(--primary);
    margin-right: 12px;
}

.top-url {
    flex: 1;
    color: var(--text-primary);
    word-break: break-all;
}

.top-count {
    font-weight: 600;
    color: var(--text-secondary);
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-muted);
}
</style>

<div class="content-wrapper">
    <!-- 页面头部 -->
    <div class="page-header">
        <div>
            <div style="margin-bottom: 8px;">
                <a href="sitemap_tasks.php" style="color: var(--text-muted); text-decoration: none; font-size: 14px;">
                    ← 返回列表
                </a>
            </div>
            <h1 class="page-title"><?php echo htmlspecialchars($task['name']); ?></h1>
        </div>
    </div>

    <!-- 统计卡片 -->
    <div class="stats-grid">
        <div class="stat-card">
            <h3>总跳转次数</h3>
            <p class="stat-value"><?php echo number_format($total); ?></p>
        </div>
        <div class="stat-card">
            <h3>域名首页跳转</h3>
            <p class="stat-value"><?php echo number_format($domainJumps); ?></p>
        </div>
        <div class="stat-card">
            <h3>内页跳转</h3>
            <p class="stat-value"><?php echo number_format($innerJumps); ?></p>
        </div>
        <div class="stat-card">
            <h3>域名数量</h3>
            <p class="stat-value"><?php echo count($task['domains']); ?></p>
        </div>
    </div>

    <!-- 基础配置 -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">⚙️ 基础配置</h2>
        </div>
        
        <form id="settingsForm">
            <!-- 域名和基础配置 -->
            <div class="form-row-2col">
                <!-- 左列：域名列表 -->
                <div class="form-group">
                    <label>域名列表</label>
                    <textarea name="domains" class="form-control form-textarea" rows="8" placeholder="每行一个域名&#10;&#10;示例：&#10;domain1.com&#10;domain2.com&#10;domain3.com"><?php echo implode("\n", $task['domains']); ?></textarea>
                    <div class="form-hint">💡 每行输入一个域名，程序会随机选择域名进行跳转</div>
                </div>

                <!-- 右列：其他配置 -->
                <div>
                    <div class="form-group">
                        <label>地图页路径</label>
                        <input type="text" name="sitemap_path" class="form-control" 
                               value="<?php echo htmlspecialchars($task['sitemap_path']); ?>" 
                               placeholder="/sitemap.xml">
                        <div class="form-hint">💡 地图页的URI路径，如：/sitemap.xml 或 /sitemap.html</div>
                    </div>
                    
                    <div class="form-row-3col" style="margin-top: 16px;">
                        <div class="form-group">
                            <label>链接数量</label>
                            <input type="number" name="max_links" class="form-control" 
                                   value="<?php echo $task['max_links']; ?>" 
                                   min="10" placeholder="50">
                        </div>
                        <div class="form-group">
                            <label>首页比例</label>
                            <div style="display: flex; align-items: center; gap: 4px;">
                                <input type="number" id="domainRatio" name="domain_ratio" class="form-control" 
                                       value="<?php echo $task['ratio']['domain']; ?>" 
                                       min="0" max="100" onchange="updateRatioDisplay()" style="text-align: center;">
                                <span style="color: var(--text-muted); font-size: 14px;">%</span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>内页比例</label>
                            <div style="display: flex; align-items: center; gap: 4px;">
                                <input type="number" id="innerRatio" class="form-control" 
                                       value="<?php echo $task['ratio']['inner']; ?>" 
                                       readonly style="cursor: not-allowed; text-align: center; background: var(--bg-dark);">
                                <span style="color: var(--text-muted); font-size: 14px;">%</span>
                            </div>
                        </div>
                    </div>
                    <div class="form-hint" id="ratioHint" style="margin-top: 8px; text-align: center; color: var(--primary);"></div>
                </div>
            </div>

            <!-- 过滤和跳转配置 -->
            <div class="form-row-2col" style="margin-top: 24px; padding-top: 24px; border-top: 1px solid var(--border);">
                <div class="form-group">
                    <label>指定目录过滤（可选）</label>
                    <textarea name="specified_paths" class="form-control form-textarea" rows="6" placeholder="留空则保留所有链接&#10;&#10;如需指定目录，每行一个：&#10;/article/&#10;/post/&#10;/news/"><?php echo implode("\n", $task['specified_paths']); ?></textarea>
                    <div class="form-hint">💡 只保留指定目录下的链接。留空则保留所有（除资源文件外）</div>
                </div>

                <div>
                    <div class="form-group">
                        <label>过滤规则</label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="include_directory" value="1" 
                                   <?php echo $task['include_directory'] ? 'checked' : ''; ?>>
                            <span>包含目录链接（以 / 结尾的链接）</span>
                        </label>
                        <div class="form-hint">💡 开启后，目录链接也会纳入跳转范围</div>
                    </div>

                    <div class="form-group" style="margin-top: 16px;">
                        <label>跳转类型</label>
                        <select name="redirect_type" class="form-control">
                            <option value="301" <?php echo $task['redirect_type'] === '301' ? 'selected' : ''; ?>>301 永久重定向</option>
                            <option value="302" <?php echo $task['redirect_type'] === '302' ? 'selected' : ''; ?>>302 临时重定向</option>
                        </select>
                    </div>

                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <span>💾</span>
                <span>保存配置</span>
            </button>
        </form>
    </div>

    <!-- 域名跳转分布 -->
    <?php if (!empty($domainStats)): ?>
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">📊 域名跳转分布</h2>
        </div>
        <ul class="top-list">
            <?php foreach ($domainStats as $domain => $count): ?>
            <li class="top-item">
                <span class="top-url"><?php echo htmlspecialchars($domain); ?></span>
                <span class="top-count"><?php echo number_format($count); ?> 次</span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- 内页TOP排行 -->
    <?php if (!empty($innerTop)): ?>
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">🏆 最常跳转的内页 TOP 10</h2>
        </div>
        <ul class="top-list">
            <?php 
            $rank = 1;
            foreach ($innerTop as $url => $count): 
            ?>
            <li class="top-item">
                <span class="top-rank">#<?php echo $rank++; ?></span>
                <span class="top-url"><?php echo htmlspecialchars($url); ?></span>
                <span class="top-count"><?php echo number_format($count); ?> 次</span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- 抓取失败的域名 -->
    <?php if (!empty($failures)): ?>
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">⚠️ 抓取失败的域名 (<?php echo count($failures); ?>个)</h2>
        </div>
        <ul class="failure-list">
            <?php foreach ($failures as $domain => $info): ?>
            <li class="failure-item">
                <div class="failure-domain"><?php echo htmlspecialchars($domain); ?></div>
                <div class="failure-meta">
                    <span>最后失败：<?php echo htmlspecialchars($info['last_fail_time']); ?></span>
                    <span>失败次数：<?php echo intval($info['fail_count']); ?> 次</span>
                </div>
                <div class="failure-error">
                    错误信息：<?php echo htmlspecialchars($info['error']); ?>
                </div>
                <div style="display: flex; gap: 8px;">
                    <button class="btn btn-secondary btn-sm" onclick="testFetch('<?php echo htmlspecialchars($domain); ?>')">
                        🔄 重新测试
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="clearFailure('<?php echo htmlspecialchars($domain); ?>')">
                        ✓ 清除记录
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="clearCache('<?php echo htmlspecialchars($domain); ?>')">
                        🗑️ 清除缓存
                    </button>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</div>

<script>
const taskId = '<?php echo $taskId; ?>';

// 更新比例显示
function updateRatioDisplay() {
    let domainRatio = parseInt(document.getElementById('domainRatio').value);
    domainRatio = Math.max(0, Math.min(100, domainRatio));
    
    document.getElementById('domainRatio').value = domainRatio;
    document.getElementById('innerRatio').value = 100 - domainRatio;
    
    // 更新提示文本
    const hint = document.getElementById('ratioHint');
    let text = '';
    
    if (domainRatio === 0) {
        text = '💡 100% 跳转到内页链接';
    } else if (domainRatio === 100) {
        text = '💡 100% 跳转到域名首页';
    } else {
        text = `💡 ${domainRatio}% 跳转到首页，${100 - domainRatio}% 跳转到内页`;
    }
    
    hint.textContent = text;
}

// 页面加载时初始化
document.addEventListener('DOMContentLoaded', function() {
    updateRatioDisplay();
});

// 保存设置
document.getElementById('settingsForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'save_settings');
    
    fetch('sitemap_task.php?id=' + taskId, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✓ ' + data.message);
            location.reload();
        } else {
            alert('✗ ' + (data.message || '保存失败'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('✗ 保存失败，请重试');
    });
});

// 测试抓取
function testFetch(domain) {
    const sitemapPath = document.querySelector('input[name="sitemap_path"]').value;
    
    const formData = new FormData();
    formData.append('action', 'test_fetch');
    formData.append('domain', domain);
    formData.append('sitemap_path', sitemapPath);
    
    fetch('sitemap_task.php?id=' + taskId, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(`✓ 抓取成功！\n\n地图页：${data.url}\n提取链接：${data.total} 条\n\n前20条链接：\n${data.links.slice(0, 10).join('\n')}\n...`);
        } else {
            alert(`✗ 抓取失败\n\n地图页：${data.url}\n错误：${data.error}`);
        }
    });
}

// 清除失败记录
function clearFailure(domain) {
    const formData = new FormData();
    formData.append('action', 'clear_failure');
    formData.append('domain', domain);
    
    fetch('sitemap_task.php?id=' + taskId, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✓ 失败记录已清除');
            location.reload();
        } else {
            alert('✗ ' + (data.message || '清除失败'));
        }
    });
}

// 清除缓存
function clearCache(domain) {
    const formData = new FormData();
    formData.append('action', 'clear_cache');
    formData.append('domain', domain);
    
    fetch('sitemap_task.php?id=' + taskId, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✓ 缓存已清除');
        } else {
            alert('✗ ' + (data.message || '清除失败'));
        }
    });
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>



