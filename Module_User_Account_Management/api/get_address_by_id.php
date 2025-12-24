<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'fail', 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/config/treasurego_db_config.php';

$addressId = isset($_GET['address_id']) ? intval($_GET['address_id']) : 0;
if ($addressId <= 0) {
    echo json_encode(['status' => 'fail', 'message' => 'Invalid address_id']);
    exit;
}

try {
    $conn = getDatabaseConnection();

    // Only return addresses belonging to this user (buyer)
    $sql = "SELECT * FROM Address WHERE Address_ID = :aid AND Address_User_ID = :uid LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':aid' => $addressId, ':uid' => $_SESSION['user_id']]);

    $addr = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$addr) {
        echo json_encode(['status' => 'fail', 'message' => 'Address not found']);
        exit;
    }

    echo json_encode(['status' => 'success', 'data' => $addr]);
} catch (Exception $e) {
    echo json_encode(['status' => 'fail', 'message' => $e->getMessage()]);
}

