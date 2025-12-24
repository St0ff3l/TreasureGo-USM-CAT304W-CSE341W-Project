<?php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'fail', 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/config/treasurego_db_config.php';

try {
    // 获取该用户的所有地址，最新的在前面
    $sql = "SELECT * FROM Address WHERE Address_User_ID = :uid ORDER BY Address_Created_At DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':uid' => $_SESSION['user_id']]);

    $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $addresses]);
} catch (Exception $e) {
    echo json_encode(['status' => 'fail', 'message' => $e->getMessage()]);
}
?>