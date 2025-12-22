<?php
// api/Get_User_Orders.php

error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config/treasurego_db_config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'msg' => 'Please login first']);
    exit;
}

$userId = $_SESSION['user_id'];
$response = ['success' => false, 'buying' => [], 'selling' => []];

try {
    $conn = getDatabaseConnection();

    // 1. 查询“我买的” (Buying)
    // 注意：这里修改了字段名以匹配你的数据库表定义 (Orders_Order_ID, Orders_Total_Amount 等)
    $sqlBuy = "SELECT 
                    o.Orders_Order_ID, o.Orders_Total_Amount, o.Orders_Status, o.Orders_Created_AT,
                    o.Orders_Seller_ID,
                    p.Product_ID, p.Product_Title,
                    (SELECT Image_URL FROM Product_Images WHERE Product_ID = p.Product_ID AND Image_is_primary = 1 LIMIT 1) AS Main_Image
               FROM Orders o
               JOIN Product p ON o.Product_ID = p.Product_ID
               WHERE o.Orders_Buyer_ID = :uid
               ORDER BY o.Orders_Created_AT DESC";

    $stmtBuy = $conn->prepare($sqlBuy);
    $stmtBuy->execute([':uid' => $userId]);
    $response['buying'] = $stmtBuy->fetchAll(PDO::FETCH_ASSOC);

    // 2. 查询“我卖的” (Selling)
    $sqlSell = "SELECT 
                    o.Orders_Order_ID, o.Orders_Total_Amount, o.Orders_Status, o.Orders_Created_AT,
                    o.Orders_Buyer_ID,
                    p.Product_ID, p.Product_Title,
                    (SELECT Image_URL FROM Product_Images WHERE Product_ID = p.Product_ID AND Image_is_primary = 1 LIMIT 1) AS Main_Image
               FROM Orders o
               JOIN Product p ON o.Product_ID = p.Product_ID
               WHERE o.Orders_Seller_ID = :uid
               ORDER BY o.Orders_Created_AT DESC";

    $stmtSell = $conn->prepare($sqlSell);
    $stmtSell->execute([':uid' => $userId]);
    $response['selling'] = $stmtSell->fetchAll(PDO::FETCH_ASSOC);

    $response['success'] = true;

} catch (Exception $e) {
    $response['msg'] = $e->getMessage();
}

echo json_encode($response);
?>