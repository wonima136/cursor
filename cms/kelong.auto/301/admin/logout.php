<?php
/**
 * 退出登录
 */
require_once __DIR__ . '/config.php';

doLogout();
header('Location: login.php');
exit;
