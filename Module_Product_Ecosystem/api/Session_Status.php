<?php
// 文件路径: Module_Product_Ecosystem/api/Session_Status.php

// 1. 开启 Session (必须放在第一行)
session_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// 2. 检查 Session 中是否有用户 ID
if (isset($_SESSION['user_id'])) {
    echo json_encode([
        'is_logged_in' => true,
        'user' => [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? 'User',
            'role' => $_SESSION['role'] ?? 'user'
        ]
    ]);
} else {
    echo json_encode([
        'is_logged_in' => false
    ]);
}
?>