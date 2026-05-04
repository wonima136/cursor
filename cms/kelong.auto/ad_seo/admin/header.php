<?php
/**
 * 后台公共侧边栏导航
 * 需要在调用页面中已定义 CURRENT_PAGE 常量
 */
$navItems = [
    'config'    => ['icon' => '⚙️', 'label' => '系统配置', 'href' => 'config_manage.php'],
    'whitelist' => ['icon' => '🔒', 'label' => 'IP白名单', 'href' => 'whitelist.php'],
    'templates' => ['icon' => '📄', 'label' => '模板编辑', 'href' => 'templates.php'],
    'redis'     => ['icon' => '🗄️', 'label' => 'Redis管理', 'href' => 'redis_manage.php'],
];

$currentPage = defined('CURRENT_PAGE') ? CURRENT_PAGE : '';

// 读取后台标题
$sidebarTitle = 'WAF拦截系统';
if (defined('CONFIG_FILE') && file_exists(CONFIG_FILE)) {
    $cfg = file_get_contents(CONFIG_FILE);
    if (preg_match("/define\('ADMIN_TITLE',\s*'([^']*)'\);/", $cfg, $m)) $sidebarTitle = $m[1];
}
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <span class="sidebar-icon">🛡️</span>
        <span class="sidebar-brand"><?php echo htmlspecialchars($sidebarTitle); ?></span>
    </div>

    <nav class="sidebar-nav">
        <?php foreach ($navItems as $key => $item): ?>
        <a href="<?php echo $item['href']; ?>"
           class="nav-item <?php echo ($currentPage === $key) ? 'active' : ''; ?>">
            <span class="nav-icon"><?php echo $item['icon']; ?></span>
            <span class="nav-label"><?php echo $item['label']; ?></span>
        </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <a href="index.php?logout=1" class="nav-item nav-logout">
            <span class="nav-icon">🚪</span>
            <span class="nav-label">退出登录</span>
        </a>
    </div>
</aside>

<div class="main-content" id="mainContent">
