<?php
// 文件路径: Module_Product_Ecosystem/api/Get_User_Listings.php

require_once __DIR__ . '/config/treasurego_db_config.php';
session_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    // 1. 检查登录
    $current_user_id = $_SESSION['user_id'] ?? $_SESSION['User_ID'] ?? null;

    if (!$current_user_id) {
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit;
    }

    $pdo = getDatabaseConnection();
    if (!$pdo) {
        throw new Exception("Database connection failed.");
    }

    // 2. 构建 SQL 查询
    // 逻辑：子查询获取主图，如果没有主图则取 ID 最小的那张
    // Update: Join Orders to get Order info for Sold items
    // Note: Orders table might use different column names. Based on previous context, it's Orders_Order_ID.
    // Assuming Orders table links Product_ID.
    $sql = "SELECT 
                p.Product_ID, 
                p.User_ID,
                p.Product_Title, 
                p.Product_Description, 
                p.Product_Price, 
                p.Product_Status, 
                p.Product_Review_Status,
                p.Product_Condition, 
                p.Product_Created_Time,
                (SELECT Image_URL FROM Product_Images pi 
                 WHERE pi.Product_ID = p.Product_ID 
                 ORDER BY pi.Image_is_primary DESC, pi.Image_ID ASC 
                 LIMIT 1) as Main_Image,
                o.Orders_Order_ID as Order_ID,
                o.Orders_Buyer_ID as Buyer_ID,
                r.Reviews_Rating,
                r.Reviews_Comment,
                r.Reviews_ID
            FROM Product p
            LEFT JOIN Orders o ON p.Product_ID = o.Product_ID
            LEFT JOIN Review r ON r.Order_ID = o.Orders_Order_ID AND r.User_ID = p.User_ID
            WHERE p.User_ID = ?
            ORDER BY p.Product_Created_Time DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$current_user_id]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $products]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>