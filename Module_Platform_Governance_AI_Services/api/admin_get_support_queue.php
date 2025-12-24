<?php
// 文件: Module_Platform_Governance_AI_Services/api/admin_get_support_queue.php
header('Content-Type: application/json');

// ==========================================================================
// 1. 引用配置
// ==========================================================================

// 数据库配置：引用当前模块下的 config (您刚才提供的文件)
require_once __DIR__ . '/config/treasurego_db_config.php';

// 身份验证：依然跨模块引用 User 模块的 auth.php
require_once __DIR__ . '/../../Module_User_Account_Management/includes/auth.php';

// ==========================================================================

start_session_safe();

// Admin only
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
if (!is_admin()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$pdo = getDatabaseConnection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

try {
    // Public pool: any Product_ID IS NULL message between any user and any admin.
    // We group by the *user* side (non-admin) so the queue shows one entry per user.
    $sql = "
        SELECT 
            u.User_ID, 
            u.User_Username, 
            u.User_Profile_image as User_Avatar_Url,
            'Support Ticket' as Product_Name, 
            NULL as Product_Image_Url,
            NULL as Product_ID,
            m.Message_Content,
            m.Message_Type,
            m.Message_Sent_At as Created_At,
            m.Message_Is_Read as Is_Read,
            m.Message_Sender_ID as Sender_ID
        FROM User u
        JOIN (
            SELECT 
                CASE
                    WHEN u_sender.User_Role = 'admin' THEN m.Message_Reciver_ID
                    ELSE m.Message_Sender_ID
                END AS User_ID,
                MAX(m.Message_ID) as Last_Msg_ID
            FROM Message m
            JOIN User u_sender ON u_sender.User_ID = m.Message_Sender_ID
            JOIN User u_recv ON u_recv.User_ID = m.Message_Reciver_ID
            WHERE m.Product_ID IS NULL
              AND (u_sender.User_Role = 'admin' OR u_recv.User_Role = 'admin')
            GROUP BY User_ID
        ) last_msg ON u.User_ID = last_msg.User_ID
        JOIN Message m ON m.Message_ID = last_msg.Last_Msg_ID
        ORDER BY m.Message_Sent_At DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $conversations]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>