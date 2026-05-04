<?php
define('SITE_NAME', '域名管理系统');

// ── 后台登录密码（bcrypt hash）──────────────────────────────────
define('ADMIN_PASSWORD_HASH', '$2y$10$1ILnt5MPSksOB0E2.oWCiOwVY/.kb0BuAkLc2.sPZWimUxTSBs3Ye');

// ── 数据库路径 ─────────────────────────────────────────────────
$_DATA_DIR = dirname(__DIR__) . '/data';
define('DATA_DIR',      $_DATA_DIR);
define('MASTER_DB',     $_DATA_DIR . '/master.db');
define('TAG_DB_DIR',    $_DATA_DIR . '/tags');
define('DOMAIN_DB_DIR', $_DATA_DIR . '/domains');

define('WARN_DAYS_RED',    7);   // 7天内到期 → 红色
define('WARN_DAYS_YELLOW', 30);  // 30天内到期 → 黄色
define('PER_PAGE', 50);

// 域名状态
define('STATUS_LABELS', [
    'normal'    => ['label' => '正常',   'class' => 'success'],
    'paused'    => ['label' => '暂停解析', 'class' => 'warning'],
    'cancelled' => ['label' => '已注销',  'class' => 'secondary'],
]);

// 备案类型
define('ICP_LABELS', [
    'none'     => ['label' => '无备案',   'class' => 'secondary'],
    'self'     => ['label' => '自备案',   'class' => 'info'],
    'included' => ['label' => '自带备案', 'class' => 'primary'],
]);

// 历史记录类型
define('ACTION_LABELS', [
    'note'          => ['label' => '备注',   'icon' => 'bi-chat-text'],
    'renewal'       => ['label' => '续费',   'icon' => 'bi-arrow-clockwise'],
    'dns_change'    => ['label' => 'DNS变更','icon' => 'bi-diagram-3'],
    'status_change' => ['label' => '状态变更','icon' => 'bi-toggle-on'],
    'transfer'      => ['label' => '转移',   'icon' => 'bi-box-arrow-right'],
    'icp_change'    => ['label' => '备案变更','icon' => 'bi-shield-check'],
]);
