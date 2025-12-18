<?php
// 文件路径: Module_Product_Ecosystem/api/Update_Product.php

require_once 'config/treasurego_db_config.php';
session_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// 1. 安全检查：必须登录
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Please login first.']);
    exit;
}

$current_user_id = $_SESSION['user_id'];

// 2. 获取前端发送的 JSON 数据
$input = json_decode(file_get_contents('php://input'), true);

$product_id = isset($input['product_id']) ? intval($input['product_id']) : 0;
$action = isset($input['action']) ? $input['action'] : ''; // 'update_price', 'toggle_status', 'delete'
$value = isset($input['value']) ? $input['value'] : null;  // 新价格 (如果是改价)

if ($product_id <= 0 || empty($action)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
    exit;
}

try {
    $pdo = getDatabaseConnection();

    // 3. 🔥 关键权限检查：确认这个商品属于当前登录用户
    $checkSql = "SELECT User_ID, Product_Status FROM Product WHERE Product_ID = ?";
    $stmt = $pdo->prepare($checkSql);
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'Product not found.']);
        exit;
    }

    if ($product['User_ID'] != $current_user_id) {
        echo json_encode(['success' => false, 'message' => 'Access Denied: You do not own this product.']);
        exit;
    }

    // 4. 根据动作执行逻辑
    if ($action === 'update_price') {
        // --- 修改价格 ---
        if (!is_numeric($value)) throw new Exception("Invalid price format.");

        $updateSql = "UPDATE Product SET Product_Price = ? WHERE Product_ID = ?";
        $stmt = $pdo->prepare($updateSql);
        $stmt->execute([$value, $product_id]);

        echo json_encode(['success' => true, 'message' => 'Price updated successfully.']);

    } elseif ($action === 'toggle_status') {
        // --- 上下架切换 ---
        // 如果当前是 Active，就改成 Unlisted；反之亦然
        $newStatus = ($product['Product_Status'] === 'Active') ? 'Unlisted' : 'Active';

        $updateSql = "UPDATE Product SET Product_Status = ? WHERE Product_ID = ?";
        $stmt = $pdo->prepare($updateSql);
        $stmt->execute([$newStatus, $product_id]);

        echo json_encode(['success' => true, 'new_status' => $newStatus, 'message' => 'Status changed to ' . $newStatus]);

    } elseif ($action === 'delete') {
        // --- 删除商品 ---
        // 1. 先删图片 (可选，防止孤儿数据)
        $delImg = "DELETE FROM Product_Images WHERE Product_ID = ?";
        $pdo->prepare($delImg)->execute([$product_id]);

        // 2. 再删商品
        $delProd = "DELETE FROM Product WHERE Product_ID = ?";
        $stmt = $pdo->prepare($delProd);
        $stmt->execute([$product_id]);

        echo json_encode(['success' => true, 'message' => 'Product deleted.']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>