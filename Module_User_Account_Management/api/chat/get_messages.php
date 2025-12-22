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

if (!$contact_id) {
    echo json_encode(['status' => 'error', 'message' => 'Contact ID required']);
    exit();
}

try {
    $pdo = getDBConnection();
    
    // 获取与指定联系人的聊天记录
    // 映射字段名以匹配前端逻辑
    $sql = "
        SELECT 
            Message_ID,
            Message_Sender_ID as Sender_ID,
            Message_Reciver_ID as Receiver_ID,
            Message_Content,
            Message_Sent_At as Created_At,
            Message_Is_Read as Is_Read
        FROM Message 
        WHERE (Message_Sender_ID = ? AND Message_Reciver_ID = ?) 
           OR (Message_Sender_ID = ? AND Message_Reciver_ID = ?)
        ORDER BY Message_Sent_At ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$current_user_id, $contact_id, $contact_id, $current_user_id]);
    $messages = $stmt->fetchAll();

    // 标记对方发给我的消息为已读
    $updateSql = "
        UPDATE Message 
        SET Message_Is_Read = 1 
        WHERE Message_Sender_ID = ? AND Message_Reciver_ID = ? AND Message_Is_Read = 0
    ";
    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute([$contact_id, $current_user_id]);

    echo json_encode(['status' => 'success', 'data' => $messages]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
