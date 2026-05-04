<?php
/**
 * 仪表盘 - 首页
 */
$pageTitle = '仪表盘 - 301重定向管理系统';
require_once __DIR__ . '/header.php';

// 获取数据
$settings = getSettings();
$domains = getDomains();
$linksPool = getLinksPool();
updateLinksPoolStats($linksPool);

// 统计数据
$domainCount = count($domains['list']);
$enabledDomains = count(array_filter($domains['list'], function($d) { return $d['enabled']; }));
$linksCount = $linksPool['stats']['active_links'];
$linksRemaining = $linksPool['stats']['total_remaining'];
$todayConsumed = $linksPool['stats']['today_consumed'];

// 获取最近日志
$recentLogs = getRedirectLogs(null, 10);
?>

<div class="page-header">
    <h1 class="page-title">仪表盘</h1>
    <p class="page-subtitle">系统运行状态概览</p>
</div>

<!-- 全局状态 -->
<div class="card" style="margin-bottom: 30px;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h3 style="font-size: 18px; margin-bottom: 8px;">
                系统状态：
                <?php if ($settings['global_enabled']): ?>
                <span class="badge badge-success">● 运行中</span>
                <?php else: ?>
                <span class="badge badge-danger">● 已停止</span>
                <?php endif; ?>
            </h3>
            <p style="color: var(--text-muted); font-size: 14px;">
                消耗池概率：<strong><?php echo $settings['redirect_probability']; ?>%</strong> | 
                各模块独立配置跳转方式和概率
            </p>
        </div>
        <div>
            <a href="rules.php" class="btn btn-primary">⚙️ 修改规则</a>
        </div>
    </div>
</div>

<!-- 统计卡片 -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon primary">🌐</div>
        <div class="stat-content">
            <h3><?php echo $enabledDomains; ?> / <?php echo $domainCount; ?></h3>
            <p>启用域名 / 总域名</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon success">🔗</div>
        <div class="stat-content">
            <h3><?php echo $linksCount; ?></h3>
            <p>活跃链接数</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon warning">📊</div>
        <div class="stat-content">
            <h3><?php echo number_format($linksRemaining); ?></h3>
            <p>剩余跳转次数</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon info">📈</div>
        <div class="stat-content">
            <h3><?php echo number_format($todayConsumed); ?></h3>
            <p>今日消耗次数</p>
        </div>
    </div>
</div>

<!-- 快捷操作 -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header">
        <h3 class="card-title">快捷操作</h3>
    </div>
    <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px;">
        <a href="domains.php" class="btn btn-secondary" style="justify-content: center;">
            🌐 管理域名
        </a>
        <a href="links.php" class="btn btn-secondary" style="justify-content: center;">
            🔗 管理链接
        </a>
        <a href="rules.php" class="btn btn-secondary" style="justify-content: center;">
            ⚙️ 跳转规则
        </a>
        <a href="logs.php" class="btn btn-secondary" style="justify-content: center;">
            📝 查看日志
        </a>
        <a href="/hnseo/tongji.php" class="btn btn-secondary" style="justify-content: center;" target="_blank" title="在新窗口中查看当前组域名的蜘蛛访问统计">
            🕷️ 蜘蛛统计
        </a>
    </div>
</div>

<!-- 重定向模块 -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header">
        <h3 class="card-title">重定向模块</h3>
    </div>
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;">
        <a href="focus.php" class="btn btn-secondary" style="justify-content: center;">
            🎯 智能集权
        </a>
        <a href="tasks.php" class="btn btn-secondary" style="justify-content: center;">
            📋 消耗池任务
        </a>
        <a href="bigsite_tasks.php" class="btn btn-secondary" style="justify-content: center;">
            ⭐ 大站池任务
        </a>
        <a href="groups.php" class="btn btn-secondary" style="justify-content: center;">
            🔗 站群链轮
        </a>
        <a href="sitewide.php" class="btn btn-secondary" style="justify-content: center;">
            🌐 整站重定向
        </a>
        <a href="parasites.php" class="btn btn-secondary" style="justify-content: center;">
            🌱 寄生重定向
        </a>
        <a href="sitemap_tasks.php" class="btn btn-secondary" style="justify-content: center;">
            🗺️ 地图重定向
        </a>
        <a href="clone_redirect.php" class="btn btn-secondary" style="justify-content: center;">
            🧬 克隆站重定向
        </a>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
    <!-- 链接池状态 -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📦 链接消耗池状态</h3>
            <a href="links.php" class="btn btn-sm btn-secondary">管理</a>
        </div>
        <?php if ($linksPool['stats']['total_links'] > 0): ?>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div>
                <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 4px;">总链接数</p>
                <p style="font-size: 20px; font-weight: 600;"><?php echo $linksPool['stats']['total_links']; ?></p>
            </div>
            <div>
                <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 4px;">已完成</p>
                <p style="font-size: 20px; font-weight: 600; color: var(--success);"><?php echo $linksPool['stats']['completed_links']; ?></p>
                </div>
            <div>
                <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 4px;">进行中</p>
                <p style="font-size: 20px; font-weight: 600; color: var(--warning);"><?php echo $linksPool['stats']['active_links']; ?></p>
                </div>
            <div>
                <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 4px;">剩余次数</p>
                <p style="font-size: 20px; font-weight: 600; color: var(--info);"><?php echo number_format($linksPool['stats']['total_remaining']); ?></p>
            </div>
        </div>
        <?php else: ?>
        <div class="empty-state" style="padding: 30px;">
            <p>暂无链接数据</p>
            <a href="links.php" class="btn btn-sm btn-primary" style="margin-top: 10px;">上传链接</a>
        </div>
        <?php endif; ?>
    </div>
    </div>

<!-- 最近跳转记录 -->
<div class="card" style="margin-top: 20px;">
    <div class="card-header">
        <h3 class="card-title">📝 最近跳转记录</h3>
        <a href="logs.php" class="btn btn-sm btn-secondary">查看全部</a>
    </div>
    <?php if (!empty($recentLogs)): ?>
    <div style="max-height: 300px; overflow-y: auto;">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 180px;">时间</th>
                    <th>来源</th>
                    <th>目标</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentLogs as $log): 
                    // 解析日志格式：时间 | 来源URL -> 目标URL
                    if (preg_match('/^(.+?) \| (.+?) -> (.+)$/', $log, $matches)):
                ?>
                <tr>
                    <td style="color: var(--text-muted); font-size: 13px;"><?php echo htmlspecialchars($matches[1]); ?></td>
                    <td style="font-size: 13px; word-break: break-all;"><?php echo htmlspecialchars($matches[2]); ?></td>
                    <td style="font-size: 13px; word-break: break-all;"><?php echo htmlspecialchars($matches[3]); ?></td>
                </tr>
                <?php endif; endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state" style="padding: 40px;">
        <div class="empty-state-icon">📝</div>
        <h3>暂无跳转记录</h3>
        <p>系统开始运行后，跳转记录将显示在这里</p>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
