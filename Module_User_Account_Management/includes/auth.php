<?php
// includes/auth.php

// 安全地开启 Session
function start_session_safe() {
    if (session_status() === PHP_SESSION_NONE) {
        // 关键代码：设置 Cookie 路径为 '/' (整个网站有效)
        // 必须在 session_start() 之前调用
        session_set_cookie_params(0, '/');
        session_start();
    }
}

// 检查是否登录
function is_logged_in() {
    start_session_safe();
    return isset($_SESSION['user_id']);
}

// 强制要求登录 (用于 Pages 层的门卫)
function require_login() {
    if (!is_logged_in()) {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        $wantsJson = str_contains($accept, 'application/json') || ($xrw === 'XMLHttpRequest');

        if ($wantsJson) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit();
        }

        header("Location: ../pages/login.php");
        exit();
    }
}

// 获取当前用户 ID
function get_current_user_id() {
    start_session_safe();
    return $_SESSION['user_id'] ?? null;
}

// 检查是否是管理员
function is_admin() {
    start_session_safe();
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

// 强制要求管理员权限
function require_admin() {
    if (!is_logged_in()) {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        $wantsJson = str_contains($accept, 'application/json') || ($xrw === 'XMLHttpRequest');

        if ($wantsJson) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit();
        }

        header("Location: ../pages/login.php");
        exit();
    }
    if (!is_admin()) {
        http_response_code(403);
        die("Access Denied: Admin privileges required.");
    }
}
?>