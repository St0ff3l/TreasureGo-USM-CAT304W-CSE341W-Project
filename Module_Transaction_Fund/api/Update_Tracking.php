<?php
// api/Update_Tracking.php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config/treasurego_db_config.php';
session_start();

// 1. 检查登录
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$data = json_decode(file_get_contents("php://input"), true);

$orderId = $data['order_id'] ?? null;
$tracking = $data['tracking'] ?? null;

// 2. 验证输入
if (!$orderId || !$tracking) {
    echo json_encode(['success' => false, 'message' => 'Missing order ID or tracking number']);
    exit;
}

try {
    $conn = getDatabaseConnection();

    // 开启事务 (Transaction)
    // 因为我们要同时修改 Orders 表 和 插入 Shipments 表，必须确保一致性
    $conn->beginTransaction();

    // ---------------------------------------------------------
    // 第一步：验证当前用户是否是该订单的卖家
    // ---------------------------------------------------------
    $checkSql = "SELECT Orders_Order_ID FROM Orders 
                 WHERE Orders_Order_ID = :oid AND Orders_Seller_ID = :uid";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute([':oid' => $orderId, ':uid' => $userId]);

    if ($checkStmt->rowCount() === 0) {
        throw new Exception("Order not found or you are not the seller.");
    }

    // ---------------------------------------------------------
    // 第二步：更新 Orders 表 (状态 + 发货时间)
    // ---------------------------------------------------------
    $updateOrderSql = "UPDATE Orders 
                       SET Orders_Status = 'shipped', 
                           Orders_Shipped_At = NOW() 
                       WHERE Orders_Order_ID = :oid";
    $updateStmt = $conn->prepare($updateOrderSql);
    $updateStmt->execute([':oid' => $orderId]);

    // ---------------------------------------------------------
    // 第三步：插入 Shipments 表 (快递单号)
    // ---------------------------------------------------------
    // 注意：Shipments_Courier_Name 是必填项，由于前端只有单号输入框，
    // 我们这里暂时默认填入 'Standard Express'，或者你可以改为让前端传
    $insertShipmentSql = "INSERT INTO Shipments (
                            Order_ID, 
                            Shipments_Tracking_Number, 
                            Shipments_Courier_Name, 
                            Shipments_Type, 
                            Shipments_Status, 
                            Shipments_Shipped_Time
                          ) VALUES (
                            :oid, 
                            :tracking, 
                            'Standard Express', 
                            'forward', 
                            'shipped', 
                            NOW()
                          )";

    $insertStmt = $conn->prepare($insertShipmentSql);
    $insertStmt->execute([
        ':oid' => $orderId,
        ':tracking' => $tracking
    ]);

    // 提交事务 (所有操作生效)
    $conn->commit();

    echo json_encode(['success' => true, 'message' => 'Order shipped successfully']);

} catch (Exception $e) {
    // 发生错误，回滚事务 (撤销所有操作)
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>