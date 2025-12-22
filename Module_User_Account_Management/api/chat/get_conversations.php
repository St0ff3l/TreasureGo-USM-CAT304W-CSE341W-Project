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

try {
    $pdo = getDBConnection();
    
    // 获取最近联系人列表及其最后一条消息
    // 逻辑：找到所有与我有关的消息，按对方ID分组，取最新的那条
    $sql = "
        SELECT 
            u.User_ID, 
            u.User_Username, 
            u.User_Profile_image as User_Avatar_Url,
            m.Message_Content,
            m.Message_Sent_At as Created_At,
            m.Message_Is_Read as Is_Read,
            m.Message_Sender_ID as Sender_ID
        FROM User u
        JOIN (
            SELECT 
                CASE 
                    WHEN Message_Sender_ID = ? THEN Message_Reciver_ID 
                    ELSE Message_Sender_ID 
                END AS Contact_ID,
                MAX(Message_ID) as Last_Msg_ID
            FROM Message
            WHERE Message_Sender_ID = ? OR Message_Reciver_ID = ?
            GROUP BY Contact_ID
        ) last_msg ON u.User_ID = last_msg.Contact_ID
        JOIN Message m ON m.Message_ID = last_msg.Last_Msg_ID
        ORDER BY m.Message_Sent_At DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$current_user_id, $current_user_id, $current_user_id]);
    $conversations = $stmt->fetchAll();

    echo json_encode(['status' => 'success', 'data' => $conversations]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
