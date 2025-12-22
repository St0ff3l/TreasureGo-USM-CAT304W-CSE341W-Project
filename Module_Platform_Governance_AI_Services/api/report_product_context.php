<?php
// ==============================================================================
// API: Get Product Report Context
// Path: Module_Platform_Governance_AI_Services/api/report_product_context.php
// Method: GET
// Auth: optional for fetch; returns only product title + seller basics needed for report
//
// Query params (aliases):
//   product_id (preferred) OR itemId OR reportedItemId
//
// Returns:
// {
//   success: true,
//   data: {
//     // legacy fields
//     product_id: 100000014,
//     product_title: "...",
//     seller_user_id: 100000001,
//     seller_username: "...",
//
//     // report-friendly fields (aliases)
//     reported_item_id: 100000014,
//     reported_item_title: "...",
//     reported_user_id: 100000001,
//     reported_user_name: "..."
//   }
// }
// ==============================================================================

session_start();
require_once __DIR__ . '/config/treasurego_db_config.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

$productId = 0;
if (isset($_GET['product_id'])) {
    $productId = (int)$_GET['product_id'];
} elseif (isset($_GET['itemId'])) {
    $productId = (int)$_GET['itemId'];
} elseif (isset($_GET['reportedItemId'])) {
    $productId = (int)$_GET['reportedItemId'];
}

if ($productId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'product_id is required']);
    exit;
}

if (!isset($conn) && isset($pdo)) {
    $conn = $pdo;
}

if (!isset($conn)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

try {
    // Product.User_ID references User.User_ID
    // User table column for name seems to be User_Username based on existing code.
    $sql = "SELECT p.Product_ID, p.Product_Title, p.User_ID AS Seller_User_ID, u.User_Username
            FROM Product p
            JOIN User u ON u.User_ID = p.User_ID
            WHERE p.Product_ID = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit;
    }

    $pid = (int)$row['Product_ID'];
    $title = $row['Product_Title'];
    $sellerId = (int)$row['Seller_User_ID'];
    $sellerName = $row['User_Username'];

    echo json_encode([
        'success' => true,
        'data' => [
            // legacy
            'product_id' => $pid,
            'product_title' => $title,
            'seller_user_id' => $sellerId,
            'seller_username' => $sellerName,

            // report-friendly aliases
            'reported_item_id' => $pid,
            'reported_item_title' => $title,
            'reported_user_id' => $sellerId,
            'reported_user_name' => $sellerName
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
}
?>
