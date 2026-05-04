<?php
/**
 * 后台公共头部
 */
require_once __DIR__ . '/config.php';

// 检查登录状态
if (!checkLogin()) {
    header('Location: login.php');
    exit;
}

// 获取当前页面
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// 获取系统名称
$systemName = getSystemName();

// 处理页面标题：将 "301重定向管理系统" 替换为自定义系统名称.
if (isset($pageTitle)) {
    $pageTitle = str_replace('301重定向管理系统', $systemName, $pageTitle);
} else {
    $pageTitle = $systemName;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --primary-light: #818cf8;
            --bg-dark: #0f172a;
            --bg-card: #1e293b;
            --bg-hover: #334155;
            --text: #e2e8f0;
            --text-muted: #94a3b8;
            --border: #334155;
            --error: #ef4444;
            --success: #22c55e;
            --warning: #f59e0b;
            --info: #3b82f6;
            --sidebar-width: 240px;
        }
        
        body {
            font-family: 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif;
            background: var(--bg-dark);
            color: var(--text);
            min-height: 100vh;
        }
        
        /* 侧边栏 */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            width: var(--sidebar-width);
            background: var(--bg-card);
            border-right: 1px solid var(--border);
            padding: 20px 0;
            overflow-y: auto;
        }
        
        .sidebar-logo {
            padding: 0 20px 20px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 20px;
        }
        
        .sidebar-logo h1 {
            font-size: 18px;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .sidebar-logo span {
            font-size: 24px;
        }
        
        .nav-menu {
            list-style: none;
        }
        
        .nav-item {
            margin: 4px 12px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: var(--text-muted);
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.2s;
            font-size: 14px;
        }
        
        .nav-link:hover {
            background: var(--bg-hover);
            color: var(--text);
        }
        
        .nav-link.active {
            background: var(--primary);
            color: white;
        }
        
        .nav-icon {
            font-size: 18px;
            width: 24px;
            text-align: center;
        }
        
        .nav-section {
            padding: 12px 20px 8px;
            font-size: 11px;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 1px;
        }
        
        /* 下拉菜单 */
        .nav-dropdown {
            position: relative;
        }
        
        .dropdown-arrow {
            margin-left: auto;
            font-size: 10px;
            transition: transform 0.2s;
        }
        
        .nav-dropdown.open .dropdown-arrow {
            transform: rotate(180deg);
        }
        
        .nav-submenu {
            list-style: none;
            margin: 4px 0;
            padding-left: 0;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .nav-subitem {
            margin: 2px 12px;
        }
        
        .nav-subitem .nav-link {
            padding: 10px 16px 10px 40px;
            font-size: 13px;
        }
        
        .nav-subitem .nav-icon {
            font-size: 16px;
        }
        
        /* 主内容区 */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 30px;
            min-height: 100vh;
        }
        
        .page-header {
            margin-bottom: 30px;
        }
        
        .page-title {
            font-size: 24px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 8px;
        }
        
        .page-subtitle {
            color: var(--text-muted);
            font-size: 14px;
        }
        
        /* 卡片 */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
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
            font-size: 16px;
            font-weight: 600;
            color: var(--text);
        }
        
        /* 统计卡片 */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .stat-icon.primary { background: rgba(99, 102, 241, 0.2); }
        .stat-icon.success { background: rgba(34, 197, 94, 0.2); }
        .stat-icon.warning { background: rgba(245, 158, 11, 0.2); }
        .stat-icon.info { background: rgba(59, 130, 246, 0.2); }
        
        .stat-content h3 {
            font-size: 24px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 4px;
        }
        
        .stat-content p {
            font-size: 13px;
            color: var(--text-muted);
        }
        
        /* 按钮 */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-secondary {
            background: var(--bg-hover);
            color: var(--text);
        }
        
        .btn-secondary:hover {
            background: var(--border);
        }
        
        .btn-danger {
            background: var(--error);
            color: white;
        }
        
        .btn-danger:hover {
            background: #dc2626;
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        /* 表单 */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            color: var(--text);
            font-size: 14px;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .form-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        
        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 10px 14px;
            background: var(--bg-dark);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
        }
        
        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        /* 开关 */
        .switch {
            position: relative;
            display: inline-block;
            width: 48px;
            height: 26px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .switch-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--border);
            border-radius: 26px;
            transition: 0.3s;
        }
        
        .switch-slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background: white;
            border-radius: 50%;
            transition: 0.3s;
        }
        
        .switch input:checked + .switch-slider {
            background: var(--primary);
        }
        
        .switch input:checked + .switch-slider:before {
            transform: translateX(22px);
        }
        
        /* 表格 */
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th,
        .table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        
        .table th {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table td {
            font-size: 14px;
            color: var(--text);
        }
        
        .table tr:hover td {
            background: var(--bg-hover);
        }
        
        /* 状态标签 */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-success {
            background: rgba(34, 197, 94, 0.2);
            color: var(--success);
        }
        
        .badge-danger {
            background: rgba(239, 68, 68, 0.2);
            color: var(--error);
        }
        
        .badge-warning {
            background: rgba(245, 158, 11, 0.2);
            color: var(--warning);
        }
        
        .badge-info {
            background: rgba(59, 130, 246, 0.2);
            color: var(--info);
        }
        
        /* 消息提示 */
        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid var(--success);
            color: var(--success);
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--error);
            color: var(--error);
        }
        
        .alert-warning {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid var(--warning);
            color: var(--warning);
        }
        
        /* 滑块 */
        .range-container {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .range-slider {
            flex: 1;
            -webkit-appearance: none;
            height: 8px;
            background: var(--border);
            border-radius: 4px;
            outline: none;
        }
        
        .range-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            background: var(--primary);
            border-radius: 50%;
            cursor: pointer;
        }
        
        .range-value {
            min-width: 50px;
            text-align: center;
            font-weight: 600;
            color: var(--primary-light);
        }
        
        /* 空状态 */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
        
        .empty-state h3 {
            font-size: 18px;
            color: var(--text);
            margin-bottom: 8px;
        }
        
        .empty-state p {
            font-size: 14px;
            margin-bottom: 20px;
        }
        
        /* 模态框 */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
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
            padding: 0;
            line-height: 1;
        }
        
        .modal-close:hover {
            color: var(--text);
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            padding: 16px 24px;
            border-top: 1px solid var(--border);
        }
        
        /* 响应式 */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                z-index: 100;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="sidebar-logo">
            <h1><span>🔄</span> <?php echo htmlspecialchars($systemName); ?></h1>
        </div>
        <nav>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="index.php" class="nav-link <?php echo $currentPage === 'index' ? 'active' : ''; ?>">
                        <span class="nav-icon">📊</span>
                        仪表盘
                    </a>
                </li>
                
                <li class="nav-section">跳转管理</li>
                <li class="nav-item">
                    <a href="focus.php" class="nav-link <?php echo $currentPage === 'focus' ? 'active' : ''; ?>">
                        <span class="nav-icon">🎯</span>
                        智能集权
                    </a>
                </li>
                <li class="nav-item">
                    <a href="tasks.php" class="nav-link <?php echo in_array($currentPage, ['tasks', 'task']) ? 'active' : ''; ?>">
                        <span class="nav-icon">📋</span>
                        消耗池任务
                    </a>
                </li>
                <li class="nav-item">
                    <a href="bigsite_tasks.php" class="nav-link <?php echo in_array($currentPage, ['bigsite_tasks', 'bigsite_task']) ? 'active' : ''; ?>">
                        <span class="nav-icon">⭐</span>
                        大站池任务
                    </a>
                </li>
                <li class="nav-item">
                    <a href="groups.php" class="nav-link <?php echo in_array($currentPage, ['groups', 'group']) ? 'active' : ''; ?>">
                        <span class="nav-icon">🔗</span>
                        站群链轮
                    </a>
                </li>
                <li class="nav-item">
                    <a href="sitewide.php" class="nav-link <?php echo in_array($currentPage, ['sitewide', 'sitewide_task']) ? 'active' : ''; ?>">
                        <span class="nav-icon">🌐</span>
                        整站重定向
                    </a>
                </li>
                <li class="nav-item">
                    <a href="parasites.php" class="nav-link <?php echo in_array($currentPage, ['parasites', 'parasite']) ? 'active' : ''; ?>">
                        <span class="nav-icon">🌱</span>
                        寄生重定向
                    </a>
                </li>
                <li class="nav-item">
                    <a href="sitemap_tasks.php" class="nav-link <?php echo in_array($currentPage, ['sitemap_tasks', 'sitemap_task']) ? 'active' : ''; ?>">
                        <span class="nav-icon">🗺️</span>
                        地图重定向
                    </a>
                </li>
                
                <li class="nav-item">
                    <a href="clone_redirect.php" class="nav-link <?php echo $currentPage === 'clone_redirect' ? 'active' : ''; ?>">
                        <span class="nav-icon">🧬</span>
                        克隆站重定向
                    </a>
                </li>
                
                <li class="nav-item">
                    <a href="clonegroupsite.php" class="nav-link <?php echo $currentPage === 'clonegroupsite' ? 'active' : ''; ?>">
                        <span class="nav-icon">🌐</span>
                        克隆站分组
                    </a>
                </li>
                
                <li class="nav-section">系统</li>
                <li class="nav-item">
                    <a href="settings.php" class="nav-link <?php echo in_array($currentPage, ['settings', 'rules']) ? 'active' : ''; ?>">
                        <span class="nav-icon">⚙️</span>
                        系统设置
                    </a>
                </li>
                <li class="nav-item">
                    <a href="placeholders.php" class="nav-link <?php echo $currentPage === 'placeholders' ? 'active' : ''; ?>">
                        <span class="nav-icon">🏷️</span>
                        占位符管理
                    </a>
                </li>
                <li class="nav-item">
                    <a href="logs.php" class="nav-link <?php echo $currentPage === 'logs' ? 'active' : ''; ?>">
                        <span class="nav-icon">📋</span>
                        跳转日志
                    </a>
                </li>
                <li class="nav-item nav-dropdown">
                    <a href="javascript:void(0)" class="nav-link <?php echo in_array($currentPage, ['performance', 'rebuild_index']) ? 'active' : ''; ?>" onclick="toggleDropdown(this)">
                        <span class="nav-icon">🔧</span>
                        系统工具
                        <span class="dropdown-arrow">▼</span>
                    </a>
                    <ul class="nav-submenu" style="display: <?php echo in_array($currentPage, ['performance', 'rebuild_index']) ? 'block' : 'none'; ?>">
                        <li class="nav-subitem">
                            <a href="performance.php" class="nav-link <?php echo $currentPage === 'performance' ? 'active' : ''; ?>">
                                <span class="nav-icon">⚡</span>
                                性能优化
                            </a>
                        </li>
                        <li class="nav-subitem">
                            <a href="rebuild_index.php" class="nav-link <?php echo $currentPage === 'rebuild_index' ? 'active' : ''; ?>">
                                <span class="nav-icon">🔨</span>
                                重建索引
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a href="/hnseo/tongji.php" class="nav-link" target="_blank" title="查看当前组域名的蜘蛛访问统计">
                        <span class="nav-icon">🕷️</span>
                        蜘蛛统计
                    </a>
                </li>
                <li class="nav-item">
                    <a href="logout.php" class="nav-link">
                        <span class="nav-icon">🚪</span>
                        退出登录
                    </a>
                </li>
            </ul>
        </nav>
    </aside>
    
    <main class="main-content">
    
    <script>
    // 下拉菜单切换
    function toggleDropdown(element) {
        const parent = element.parentElement;
        const submenu = parent.querySelector('.nav-submenu');
        
        if (submenu) {
            const isOpen = submenu.style.display === 'block';
            submenu.style.display = isOpen ? 'none' : 'block';
            parent.classList.toggle('open');
        }
    }
    </script>

