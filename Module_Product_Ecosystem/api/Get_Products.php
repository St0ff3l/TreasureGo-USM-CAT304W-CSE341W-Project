<?php
// 文件路径: api/Get_Products.php

require_once 'config/treasurego_db_config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        throw new Exception("Database connection failed.");
    }

    // 1. 接收前端参数
    $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    $category = isset($_GET['category']) ? trim($_GET['category']) : 'All';
    $min_price = isset($_GET['min_price']) ? floatval($_GET['min_price']) : 0;
    $max_price = isset($_GET['max_price']) ? floatval($_GET['max_price']) : 999999;
    $conditions = isset($_GET['conditions']) ? $_GET['conditions'] : [];

    // 2. 构建 SQL 查询
    // 🔥 修改点：新增了 All_Images 字段，获取该商品所有图片
    $sql = "SELECT 
                p.Product_ID, 
                p.User_ID,
                p.Product_Title, 
                p.Product_Description, 
                p.Product_Price, 
                p.Product_Status,
                p.Product_Condition, 
                p.Product_Created_Time,
                p.Product_Location, 
                u.User_Username, 
                u.User_Average_Rating,
                /* 获取主图 */
                (SELECT Image_URL FROM Product_Images pi WHERE pi.Product_ID = p.Product_ID AND pi.Image_is_primary = 1 LIMIT 1) as Main_Image,
                /* 🔥 获取所有图片 (用逗号分隔) 🔥 */
                (SELECT GROUP_CONCAT(Image_URL SEPARATOR ',') FROM Product_Images pi WHERE pi.Product_ID = p.Product_ID) as All_Images
            FROM Product p
            JOIN User u ON p.User_ID = u.User_ID
            LEFT JOIN Categories c ON p.Category_ID = c.Category_ID 
            WHERE p.Product_Status = 'Active'";

    $params = [];

    // --- 动态添加筛选条件 ---

    if ($product_id > 0) {
        $sql .= " AND p.Product_ID = ?";
        $params[] = $product_id;
    }

    if (!empty($q)) {
        $sql .= " AND (p.Product_Title LIKE ? OR p.Product_Description LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }

    if ($category !== 'All') {
        $sql .= " AND (p.Category_ID = ? OR c.Category_Parent_ID = ?)";
        $params[] = $category;
        $params[] = $category;
    }

    if ($min_price > 0) {
        $sql .= " AND p.Product_Price >= ?";
        $params[] = $min_price;
    }
    if ($max_price > 0 && $max_price < 999999) {
        $sql .= " AND p.Product_Price <= ?";
        $params[] = $max_price;
    }

    if (!empty($conditions) && is_array($conditions)) {
        $placeholders = implode(',', array_fill(0, count($conditions), '?'));
        $sql .= " AND p.Product_Condition IN ($placeholders)";
        foreach ($conditions as $cond) {
            $params[] = $cond;
        }
    }

    $sql .= " ORDER BY p.Product_Created_Time DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $products]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>