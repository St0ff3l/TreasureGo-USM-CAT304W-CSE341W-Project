<?php
header('Content-Type: application/json');
require_once '../../api/config/treasurego_db_config.php';
require_once '../../includes/auth.php';

start_session_safe();

if (!is_logged_in()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$current_user_id = $_SESSION['user_id'];
$contact_id = $_GET['contact_id'] ?? null;
$product_id = $_GET['product_id'] ?? null;

if (!$contact_id) {
    echo json_encode(['status' => 'error', 'message' => 'Contact ID required']);
    exit();
}

// Product chat is required here to prevent mixing with support tickets.
if ($product_id === null || $product_id === '' || strtolower((string)$product_id) === 'null') {
    echo json_encode(['status' => 'error', 'message' => 'product_id is required for product chat messages']);
    exit();
}
if (!ctype_digit((string)$product_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid product_id']);
    exit();
}
$product_id = (int)$product_id;

try {
    $pdo = getDBConnection();

    $sql = "
        SELECT 
            Message_ID,
            Message_Sender_ID as Sender_ID,
            Message_Reciver_ID as Receiver_ID,
            Message_Content,
            Message_Type,
            Message_Sent_At as Created_At,
            Message_Is_Read as Is_Read,
            Product_ID
        FROM Message 
        WHERE ((Message_Sender_ID = ? AND Message_Reciver_ID = ?) 
           OR (Message_Sender_ID = ? AND Message_Reciver_ID = ?))
          AND Product_ID = ?
        ORDER BY Message_Sent_At ASC
    ";

    $params = [$current_user_id, $contact_id, $contact_id, $current_user_id, $product_id];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $messages = $stmt->fetchAll();

    // 标记对方发给我的消息为已读
    $updateSql = "
        UPDATE Message 
        SET Message_Is_Read = 1 
        WHERE Message_Sender_ID = ? AND Message_Reciver_ID = ? AND Message_Is_Read = 0
          AND Product_ID = ?
    ";
    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute([$contact_id, $current_user_id, $product_id]);

    echo json_encode(['status' => 'success', 'data' => $messages]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
