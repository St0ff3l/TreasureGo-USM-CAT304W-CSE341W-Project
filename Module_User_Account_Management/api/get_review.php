<?php
// Module_User_Account_Management/api/get_review.php

require_once __DIR__ . '/config/treasurego_db_config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$reviewer_id = $_SESSION['user_id'];
$order_id = $_GET['order_id'] ?? null;

if (!$order_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing Order ID']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Fetch review
    $stmt = $pdo->prepare("SELECT Reviews_Rating, Reviews_Comment FROM Review WHERE Order_ID = ? AND User_ID = ?");
    $stmt->execute([$order_id, $reviewer_id]);
    $review = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($review) {
        echo json_encode(['success' => true, 'data' => $review]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Review not found']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
