<?php
/**
 * 全局设置 - 重定向到新的系统设置页面
 * 
 * 为了保持向后兼容，访问 rules.php 会自动跳转到 settings.php?tab=global
 */

header('Location: settings.php?tab=global');
exit;

