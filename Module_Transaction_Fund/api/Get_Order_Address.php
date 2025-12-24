<?php
// api/Get_Order_Address.php

error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/treasurego_db_config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$orderId = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order_id']);
    exit;
}

try {
    $conn = getDatabaseConnection();
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $uid = intval($_SESSION['user_id']);

    // 1) Ensure current user is either buyer or seller of this order
    $stmt = $conn->prepare("SELECT Orders_Order_ID, Orders_Buyer_ID, Orders_Seller_ID, Address_ID FROM Orders WHERE Orders_Order_ID = :oid LIMIT 1");
    $stmt->execute([':oid' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }

    if ((int)$order['Orders_Buyer_ID'] !== $uid && (int)$order['Orders_Seller_ID'] !== $uid) {
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }

    // 2) Meetup orders can have NULL address
    $addressId = $order['Address_ID'] ?? null;
    if (!$addressId) {
        echo json_encode(['success' => true, 'data' => null]);
        exit;
    }

    // 3) Return the buyer's address record linked by the order
    $stmtAddr = $conn->prepare("SELECT Address_ID, Address_User_ID, Address_Receiver_Name, Address_Detail, Address_Phone_Number, Address_Is_Default, Address_Created_At FROM Address WHERE Address_ID = :aid LIMIT 1");
    $stmtAddr->execute([':aid' => $addressId]);
    $addr = $stmtAddr->fetch(PDO::FETCH_ASSOC);

    if (!$addr) {
        echo json_encode(['success' => true, 'data' => null]);
        exit;
    }

    echo json_encode(['success' => true, 'data' => $addr]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
