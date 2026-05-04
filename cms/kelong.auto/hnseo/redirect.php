<?php
/**
 * 域名跳转脚本
 * 将 7808.cn 的所有请求跳转到 beecloud.cn，保留原始URI路径
 */

// 获取当前请求的URI
$request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';

// 目标域名
$target_domain = 'https://beecloud.cn';

// 构建完整的跳转URL
$redirect_url = $target_domain . $request_uri;

// 301永久重定向
header("HTTP/1.1 301 Moved Permanently");
header("Location: " . $redirect_url);
exit();

