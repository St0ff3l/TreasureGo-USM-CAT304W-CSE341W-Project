<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../Module_Platform_Governance_AI_Services/api/config/treasurego_db_config.php';
require_once __DIR__ . '/../../Module_User_Account_Management/includes/auth.php';

start_session_safe();

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

$disputeId = isset($_GET['dispute_id']) ? intval($_GET['dispute_id']) : 0;
if ($disputeId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing dispute_id']);
    exit;
}

try {
    $sql = "SELECT
                d.Dispute_ID,
                d.Dispute_Reason,
                d.Dispute_Details,
                d.Dispute_Status,
                d.Dispute_Creation_Date,
                d.Admin_Action_ID,
                d.Reporting_User_ID,
                d.Reported_User_ID,
                d.Order_ID,
                d.Refund_ID,

                -- 获取证据图片 (对应数据库 Dispute 表的字段)
                -- 这里的 AS 别名是为了配合前端 JS: renderImgs(d.Dispute_Evidence_Image, ...)
                d.Dispute_Buyer_Evidence AS Dispute_Evidence_Image, 
                d.Dispute_Seller_Evidence AS Dispute_Seller_Evidence_Image,

                -- 结果字段
                d.Dispute_Resolution_Outcome,
                d.Dispute_Refund_Amount,
                d.Dispute_Admin_Reply_To_Buyer,
                d.Dispute_Admin_Reply_To_Seller,
                d.Dispute_Admin_Resolved_At,
                d.Dispute_Admin_ID,

                -- 卖家回复字段
                d.Dispute_Seller_Response,
                d.Dispute_Seller_Responded_At,

                -- 买家信息
                u1.User_Username AS Reporting_Username,
                u1.User_Email AS Reporting_Email,
                u1.User_Profile_Image AS Reporting_User_Avatar,

                -- 卖家信息
                u2.User_Username AS Reported_Username,
                u2.User_Email AS Reported_Email,
                u2.User_Profile_Image AS Reported_User_Avatar,

                -- 订单信息
                o.Orders_Total_Amount,
                o.Orders_Status,
                o.Orders_Created_AT,
                o.Address_ID,

                -- 退款请求信息
                rr.Refund_Type,
                rr.Refund_Status,
                rr.Refund_Amount,
                rr.Refund_Reason,
                
                -- 🔥🔥🔥 【关键修改：这里补上了收货状态字段】 🔥🔥🔥
                rr.Refund_Has_Received_Goods,
                
                rr.Refund_Description,
                rr.Return_Address_Detail,
                rr.Return_Tracking_Number,
                rr.Request_Attempt,
                rr.Seller_Reject_Reason_Code,
                rr.Seller_Reject_Reason_Text,
                rr.Seller_Refuse_Receive_Reason_Code,
                rr.Seller_Refuse_Receive_Reason_Text

            FROM Dispute d
            LEFT JOIN User u1 ON d.Reporting_User_ID = u1.User_ID
            LEFT JOIN User u2 ON d.Reported_User_ID = u2.User_ID
            LEFT JOIN Orders o ON d.Order_ID = o.Orders_Order_ID
            LEFT JOIN Refund_Requests rr ON d.Refund_ID = rr.Refund_ID
            WHERE d.Dispute_ID = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$disputeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Dispute not found']);
        exit;
    }

    echo json_encode(['status' => 'success', 'data' => $row]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>