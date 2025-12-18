<?php
// 文件路径: Module_Product_Ecosystem/api/Get_Seller_Product_Detail.php

require_once 'config/treasurego_db_config.php';
session_start();

header('Content-Type: application/json');

try {
    // 1. 必须登录
    $current_user_id = $_SESSION['user_id'] ?? $_SESSION['User_ID'] ?? null;
    if (!$current_user_id) {
        throw new Exception("Not logged in");
    }

    $product_id = $_GET['product_id'] ?? null;
    if (!$product_id) {
        throw new Exception("Product ID required");
    }

    $pdo = getDatabaseConnection();

    // 2. 关键查询逻辑
    // 🔥 这里去掉了 Product_Status = 'Active' 的限制
    // 🔥 但是增加了 User_ID = ? 的限制，确保只有发布者自己能看到
    $sql = "SELECT 
                p.*,
                /* 获取所有图片，用逗号分隔 */
                (SELECT GROUP_CONCAT(Image_URL ORDER BY Image_is_primary DESC, Image_ID ASC) 
                 FROM Product_Images pi 
                 WHERE pi.Product_ID = p.Product_ID) as All_Images,
                /* 单独获取主图 */
                (SELECT Image_URL FROM Product_Images pi2 
                 WHERE pi2.Product_ID = p.Product_ID 
                 ORDER BY Image_is_primary DESC, Image_ID ASC LIMIT 1) as Main_Image
            FROM Product p
            WHERE p.Product_ID = ? AND p.User_ID = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$product_id, $current_user_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        // 如果查不到，说明要么ID不对，要么这商品不是你的
        echo json_encode(['success' => false, 'message' => 'Product not found or access denied.']);
    } else {
        // 成功返回数据
        echo json_encode(['success' => true, 'data' => [$product]]);
        // 注意：这里包了一层 [] 数组，为了兼容你前端的处理逻辑
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>