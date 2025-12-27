<?php
// 文件路径: Module_Product_Ecosystem/api/get_public_user_products.php

// 1. 引入数据库配置
// 请确保路径正确指向你的配置文件
require_once __DIR__ . '/config/treasurego_db_config.php';

// 2. 设置响应头：JSON 格式 + 允许跨域
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    // 3. 获取前端传来的 user_id
    // 例如：get_public_user_products.php?user_id=100000005
    $target_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

    // 如果没有传 ID 或 ID 无效，返回空数组
    if ($target_user_id <= 0) {
        echo json_encode(['status' => 'success', 'data' => []]);
        exit;
    }

    $pdo = getDatabaseConnection();
    if (!$pdo) {
        throw new Exception("Database connection failed");
    }

    // 4. 构建 SQL 查询
    // 使用 AS 别名适配前端 JS (Name, Price, Status, Image_Url)
    $sql = "SELECT 
                p.Product_ID, 
                p.Product_Title as Name,            /* 转为前端用的 Name */
                p.Product_Price as Price,           /* 转为前端用的 Price */
                p.Product_Status as Status,         /* 转为前端用的 Status */
                p.Product_Created_Time as Created_At,
                p.Product_Condition,
                p.Product_Location,
                p.Delivery_Method,
                
                /* --- 图片获取逻辑 --- */
                /* 假设你有一个 Product_Images 表。如果没有，请删除这段子查询，改用 null 或默认图 */
                (SELECT Image_URL 
                 FROM Product_Images pi 
                 WHERE pi.Product_ID = p.Product_ID 
                 ORDER BY pi.Image_is_primary DESC, pi.Image_ID ASC 
                 LIMIT 1) as Image_Url

            FROM Product p
            WHERE p.User_ID = ? 
            
            /* --- 关键过滤逻辑 --- */
            /* 1. 如果商品是 'Active' (在售)，必须通过审核 ('approved') 才能显示 */
            /* 2. 如果商品是 'Sold' (已售)，则直接显示 (通常已售商品不需要再次审核状态) */
            AND (
                (p.Product_Status = 'Active' AND p.Product_Review_Status = 'approved')
                OR 
                (p.Product_Status = 'Sold')
            )
            
            ORDER BY p.Product_Created_Time DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$target_user_id]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. 返回 JSON 结果
    echo json_encode([
        'status' => 'success',
        'data' => $products
    ]);

} catch (Exception $e) {
    // 错误处理
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>