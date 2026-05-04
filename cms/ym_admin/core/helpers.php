<?php
// ════════════════════════════════════════════════════════════════
// 通用工具函数
// ════════════════════════════════════════════════════════════════

function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void {
    header("Location: $url");
    exit;
}

function flash(string $key, string $msg): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION[$key] = $msg;
}

function getFlash(string $key): string {
    $msg = $_SESSION[$key] ?? '';
    unset($_SESSION[$key]);
    return $msg;
}

function paginate(int $total, int $page, int $perPage = PER_PAGE): array {
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    return [
        'page'        => $page,
        'total_pages' => $totalPages,
        'offset'      => ($page - 1) * $perPage,
        'total'       => $total,
    ];
}
