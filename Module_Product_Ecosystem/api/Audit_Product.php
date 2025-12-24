<?php
// Module_Product_Ecosystem/api/Audit_Product.php

// 1. 开启 Session 以获取管理员 ID
session_start();

header('Content-Type: application/json');

// 引入数据库配置文件
require_once __DIR__ . '/config/treasurego_db_config.php';

// 获取 POST JSON 数据
$input = json_decode(file_get_contents('php://input'), true);

$productId = $input['product_id'] ?? null;
$action = $input['action'] ?? null; // 'approve' 或 'reject'
$reason = $input['reason'] ?? null; // 拒绝理由

// 2. 获取管理员 ID (假设登录时存入了 session['user_id'])
// 如果没有登录，设置为 0 或报错，这里暂时给个默认值防止报错，实际请确保已登录
$adminId = $_SESSION['user_id'] ?? 0;

if (!$productId || !$action) {
    echo json_encode(['success' => false, 'msg' => 'Missing parameters']);
    exit;
}

if ($adminId == 0) {
    // 可选：如果没有获取到 Admin ID，可以阻止操作
    // echo json_encode(['success' => false, 'msg' => 'Unauthorized']); exit;
}

try {
    // 开启事务 (确保两张表操作同时成功)
    $conn->beginTransaction();

    $newStatus = '';
    $newListingStatus = '';
    $reviewResult = ''; // 对应 Product_Admin_Review 表的 Result 字段
    $comment = null;

    if ($action === 'approve') {
        $newStatus = 'approved';      // Product 表状态
        $newListingStatus = 'Active';
        $reviewResult = 'Approved';   // Review 表状态 (符合你的表结构 Enum/Varchar)
        $comment = 'Approved by Admin';
    } elseif ($action === 'reject') {
        $newStatus = 'rejected';
        $newListingStatus = 'Inactive';
        $reviewResult = 'Rejected';
        $comment = $reason;
    } else {
        throw new Exception("Invalid action type");
    }

    // --- 第一步：更新 Product 主表 ---
    // 更新状态，以便前台知道该商品已处理
    $sqlProduct = "UPDATE Product 
                   SET Product_Review_Status = ?,
                       Product_Status = ?,
                       Product_Review_Comment = ? 
                   WHERE Product_ID = ?";
    $stmtProduct = $conn->prepare($sqlProduct);
    if (!$stmtProduct->execute([$newStatus, $newListingStatus, $comment, $productId])) {
        throw new Exception("Failed to update Product table");
    }

    // --- 第二步：插入 Product_Admin_Review 审核记录表 ---
    // 注意：这里使用了 NOW() 记录数据库当前时间
    $sqlReview = "INSERT INTO Product_Admin_Review 
                  (Admin_Review_Result, Admin_Review_Comment, Admin_Review_Time, Product_ID, Admin_ID) 
                  VALUES (?, ?, NOW(), ?, ?)";
    $stmtReview = $conn->prepare($sqlReview);
    // 绑定参数: Result, Comment, ProductID, AdminID
    if (!$stmtReview->execute([$reviewResult, $comment, $productId, $adminId])) {
        throw new Exception("Failed to insert Audit Log");
    }

    // 提交事务
    $conn->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    // 发生错误，回滚所有操作
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Audit_Product Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'msg' => 'Database Error: ' . $e->getMessage()]);
}
?>