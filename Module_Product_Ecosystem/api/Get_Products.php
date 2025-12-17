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
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    // 注意：这里的 $category 现在接收到的是数字 ID (例如 100000005)
    $category = isset($_GET['category']) ? trim($_GET['category']) : 'All';
    $min_price = isset($_GET['min_price']) ? floatval($_GET['min_price']) : 0;
    $max_price = isset($_GET['max_price']) ? floatval($_GET['max_price']) : 999999;
    $conditions = isset($_GET['conditions']) ? $_GET['conditions'] : [];

    // 2. 构建 SQL 查询
    $sql = "SELECT 
                p.Product_ID, 
                p.Product_Title, 
                p.Product_Price, 
                p.Product_Condition, 
                p.Product_Created_Time,
                p.Product_Location,
                u.User_Username, 
                u.User_Average_Rating,
                (SELECT Image_URL FROM Product_Images pi WHERE pi.Product_ID = p.Product_ID AND pi.Image_is_primary = 1 LIMIT 1) as Main_Image
            FROM Product p
            JOIN User u ON p.User_ID = u.User_ID
            LEFT JOIN Categories c ON p.Category_ID = c.Category_ID 
            WHERE p.Product_Status = 'Active'";

    $params = [];

    // --- 动态添加筛选条件 ---

    // 1. 关键词搜索
    if (!empty($q)) {
        $sql .= " AND (p.Product_Title LIKE ? OR p.Product_Description LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }

    // 2. 分类筛选 (🔥 核心修改点 🔥)
    if ($category !== 'All') {
        // 原来的代码是查 Name，现在改成查 ID
        // p.Category_ID 指的是商品表里的分类ID字段
        $sql .= " AND p.Category_ID = ?";
        $params[] = $category;
    }

    // 3. 价格筛选
    if ($min_price > 0) {
        $sql .= " AND p.Product_Price >= ?";
        $params[] = $min_price;
    }
    if ($max_price > 0 && $max_price < 999999) {
        $sql .= " AND p.Product_Price <= ?";
        $params[] = $max_price;
    }

    // 4. 成色筛选
    if (!empty($conditions) && is_array($conditions)) {
        $placeholders = implode(',', array_fill(0, count($conditions), '?'));
        $sql .= " AND p.Product_Condition IN ($placeholders)";
        foreach ($conditions as $cond) {
            $params[] = $cond;
        }
    }

    // 排序 (默认按最新)
    $sql .= " ORDER BY p.Product_Created_Time DESC";

    // 3. 执行查询
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $products]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>