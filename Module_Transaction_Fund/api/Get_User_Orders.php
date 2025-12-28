<?php
// api/Get_User_Orders.php

error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config/treasurego_db_config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'msg' => 'Please login first']);
    exit;
}

$userId = $_SESSION['user_id'];
$response = ['success' => false, 'buying' => [], 'selling' => []];

try {
    $conn = getDatabaseConnection();

    // ======================================================
    // 1. 查询“我买的” (Buying)
    // ======================================================
    $sqlBuy = "SELECT 
                    o.Orders_Order_ID, 
                    o.Orders_Total_Amount, 
                    o.Orders_Status, 
                    MAX(o.Orders_Created_AT) AS Orders_Created_AT,
                    o.Orders_Seller_ID,
                    o.Orders_Buyer_ID,
                    MAX(o.Address_ID) AS Address_ID,
                    
                    /* 基本订单信息 */
                    MAX(s.Shipments_Shipped_Time) AS Orders_Shipped_At,
                    MAX(s.Shipments_Tracking_Number) AS Tracking_Number,
                    MAX(u.User_Username) AS Seller_Username,

                    /* 🔥 退款信息 (关联 Refund_Requests) */
                    MAX(rr.Refund_Status) AS Refund_Status,
                    MAX(rr.Refund_Type) AS Refund_Type,
                    MAX(rr.Refund_Amount) AS Refund_Amount,

                    /* 🔥🔥 退款详情 🔥🔥 */
                    MAX(rr.Refund_Reason) AS Refund_Reason,
                    MAX(rr.Refund_Description) AS Refund_Description,
                    MAX(rr.Refund_Updated_At) AS Refund_Updated_At,

                    /* 🔥 新增：申请次数 & 卖家原因 & 拒收原因 */
                    MAX(rr.Request_Attempt) AS Request_Attempt,
                    MAX(rr.Seller_Reject_Reason_Code) AS Seller_Reject_Reason_Code,
                    MAX(rr.Seller_Reject_Reason_Text) AS Seller_Reject_Reason_Text,
                    MAX(rr.Seller_Refuse_Receive_Reason_Code) AS Seller_Refuse_Receive_Reason_Code,
                    MAX(rr.Seller_Refuse_Receive_Reason_Text) AS Seller_Refuse_Receive_Reason_Text,
                    
                    /* 🔥🔥 [新增] 获取退货单号 🔥🔥 */
                    MAX(rr.Return_Tracking_Number) AS Return_Tracking_Number,

                    /* 🔥🔥 退款凭证图片 (多张图用逗号拼起来) 🔥🔥 */
                    GROUP_CONCAT(DISTINCT re.Evidence_File_Url SEPARATOR ',') AS Refund_Images,

                    /* 🔥 卖家退货地址 (优先读快照，没有则读默认) */
                    COALESCE(MAX(rr.Return_Address_Detail), MAX(sa.Address_Detail)) AS Seller_Return_Address,
                    MAX(sa.Address_Receiver_Name) AS Seller_Name,
                    MAX(sa.Address_Phone_Number) AS Seller_Phone,

                    p.Product_ID, 
                    p.Product_Title,
                    p.Product_Description,
                    p.Product_Condition,
                    
                    /* 动态判断配送方式 */
                    (CASE WHEN MAX(o.Address_ID) IS NULL THEN 'meetup' ELSE 'shipping' END) AS Delivery_Method,
                    p.Product_Location,
                    
                    c.Category_Name,

                    (SELECT Image_URL FROM Product_Images WHERE Product_ID = p.Product_ID AND Image_is_primary = 1 LIMIT 1) AS Main_Image,
                    GROUP_CONCAT(DISTINCT pi.Image_URL SEPARATOR ',') AS All_Images,

                    /* ===== 🔥🔥 新增：争议状态与参与记录字段 🔥🔥 ===== */
                    MAX(d.Dispute_ID) AS Dispute_ID,
                    MAX(d.Dispute_Status) AS Dispute_Status,
                    MAX(d.Action_Required_By) AS Action_Required_By,
                    MAX(d.Reporting_User_ID) AS Reporting_User_ID,
                    
                    /* 用于判断买家/卖家是否已参与 */
                    MAX(d.Buyer_Description) AS Buyer_Description,
                    MAX(d.Seller_Description) AS Seller_Description,
                    MAX(d.Dispute_Buyer_Evidence) AS Dispute_Buyer_Evidence,
                    MAX(d.Dispute_Seller_Evidence) AS Dispute_Seller_Evidence,
                    MAX(d.Dispute_Seller_Response) AS Dispute_Seller_Response,

                    /* ===== 新增：管理员争议处理结果 ===== */
                    MAX(d.Dispute_Resolution_Outcome) AS Dispute_Resolution_Outcome,
                    MAX(d.Dispute_Refund_Amount) AS Dispute_Refund_Amount,
                    MAX(d.Dispute_Admin_Reply_To_Buyer) AS Dispute_Admin_Reply_To_Buyer,
                    MAX(d.Dispute_Admin_Reply_To_Seller) AS Dispute_Admin_Reply_To_Seller,
                    MAX(d.Dispute_Admin_Resolved_At) AS Dispute_Admin_Resolved_At,
                    MAX(d.Dispute_Admin_ID) AS Dispute_Admin_ID

               FROM Orders o
               JOIN Product p ON o.Product_ID = p.Product_ID
               LEFT JOIN Categories c ON p.Category_ID = c.Category_ID
               LEFT JOIN Product_Images pi ON p.Product_ID = pi.Product_ID
               
               LEFT JOIN User u ON o.Orders_Seller_ID = u.User_ID

               LEFT JOIN Shipments s ON o.Orders_Order_ID = s.Order_ID AND s.Shipments_Type = 'forward'
               
               /* 关联退款表 */
               LEFT JOIN Refund_Requests rr ON o.Orders_Order_ID = rr.Order_ID
               
               /* 关联退款凭证表 */
               LEFT JOIN Refund_Evidence re ON rr.Refund_ID = re.Refund_ID

               /* 关联卖家地址 */
               LEFT JOIN Address sa ON o.Orders_Seller_ID = sa.Address_User_ID AND sa.Address_Is_Default = 1

               /* 关联争议表 */
               LEFT JOIN Dispute d ON o.Orders_Order_ID = d.Order_ID
               
               WHERE o.Orders_Buyer_ID = :uid
               GROUP BY o.Orders_Order_ID
               ORDER BY Orders_Created_AT DESC";

    $stmtBuy = $conn->prepare($sqlBuy);
    $stmtBuy->execute([':uid' => $userId]);
    $response['buying'] = $stmtBuy->fetchAll(PDO::FETCH_ASSOC);

    // ======================================================
    // 2. 查询“我卖的” (Selling)
    // ======================================================
    $sqlSell = "SELECT 
                    o.Orders_Order_ID, 
                    o.Orders_Total_Amount, 
                    o.Orders_Status, 
                    MAX(o.Orders_Created_AT) AS Orders_Created_AT,
                    o.Orders_Seller_ID,
                    o.Orders_Buyer_ID,
                    MAX(o.Address_ID) AS Address_ID,

                    MAX(s.Shipments_Shipped_Time) AS Orders_Shipped_At,
                    MAX(s.Shipments_Tracking_Number) AS Tracking_Number,
                    MAX(u.User_Username) AS Buyer_Username,

                    /* 🔥 退款信息 */
                    MAX(rr.Refund_Status) AS Refund_Status,
                    MAX(rr.Refund_Type) AS Refund_Type,
                    MAX(rr.Refund_Amount) AS Refund_Amount,
                    
                    /* 🔥🔥 退款详情 🔥🔥 */
                    MAX(rr.Refund_Reason) AS Refund_Reason,
                    MAX(rr.Refund_Description) AS Refund_Description,
                    MAX(rr.Refund_Updated_At) AS Refund_Updated_At,

                    /* 🔥 新增：申请次数 & 卖家原因 & 拒收原因 */
                    MAX(rr.Request_Attempt) AS Request_Attempt,
                    MAX(rr.Seller_Reject_Reason_Code) AS Seller_Reject_Reason_Code,
                    MAX(rr.Seller_Reject_Reason_Text) AS Seller_Reject_Reason_Text,
                    MAX(rr.Seller_Refuse_Receive_Reason_Code) AS Seller_Refuse_Receive_Reason_Code,
                    MAX(rr.Seller_Refuse_Receive_Reason_Text) AS Seller_Refuse_Receive_Reason_Text,

                    /* 🔥🔥 [新增] 获取退货单号 🔥🔥 */
                    MAX(rr.Return_Tracking_Number) AS Return_Tracking_Number,

                    /* 🔥🔥 退款凭证图片 🔥🔥 */
                    GROUP_CONCAT(DISTINCT re.Evidence_File_Url SEPARATOR ',') AS Refund_Images,

                    /* 🔥 卖家(我)的退货地址 (优先读快照) */
                    COALESCE(MAX(rr.Return_Address_Detail), MAX(sa.Address_Detail)) AS Seller_Return_Address,
                    MAX(sa.Address_Receiver_Name) AS Seller_Name,
                    MAX(sa.Address_Phone_Number) AS Seller_Phone,

                    p.Product_ID, 
                    p.Product_Title,
                    p.Product_Description,
                    p.Product_Condition,
                    
                    /* 动态判断配送方式 */
                    (CASE WHEN MAX(o.Address_ID) IS NULL THEN 'meetup' ELSE 'shipping' END) AS Delivery_Method,
                    p.Product_Location,

                    c.Category_Name,

                    (SELECT Image_URL FROM Product_Images WHERE Product_ID = p.Product_ID AND Image_is_primary = 1 LIMIT 1) AS Main_Image,
                    GROUP_CONCAT(DISTINCT pi.Image_URL SEPARATOR ',') AS All_Images,

                    /* ===== 🔥🔥 新增：争议状态与参与记录字段 🔥🔥 ===== */
                    MAX(d.Dispute_ID) AS Dispute_ID,
                    MAX(d.Dispute_Status) AS Dispute_Status,
                    MAX(d.Action_Required_By) AS Action_Required_By,
                    MAX(d.Reporting_User_ID) AS Reporting_User_ID,
                    
                    /* 用于判断买家/卖家是否已参与 */
                    MAX(d.Buyer_Description) AS Buyer_Description,
                    MAX(d.Seller_Description) AS Seller_Description,
                    MAX(d.Dispute_Buyer_Evidence) AS Dispute_Buyer_Evidence,
                    MAX(d.Dispute_Seller_Evidence) AS Dispute_Seller_Evidence,
                    MAX(d.Dispute_Seller_Response) AS Dispute_Seller_Response,

                    /* ===== 新增：管理员争议处理结果 ===== */
                    MAX(d.Dispute_Resolution_Outcome) AS Dispute_Resolution_Outcome,
                    MAX(d.Dispute_Refund_Amount) AS Dispute_Refund_Amount,
                    MAX(d.Dispute_Admin_Reply_To_Buyer) AS Dispute_Admin_Reply_To_Buyer,
                    MAX(d.Dispute_Admin_Reply_To_Seller) AS Dispute_Admin_Reply_To_Seller,
                    MAX(d.Dispute_Admin_Resolved_At) AS Dispute_Admin_Resolved_At,
                    MAX(d.Dispute_Admin_ID) AS Dispute_Admin_ID

               FROM Orders o
               JOIN Product p ON o.Product_ID = p.Product_ID
               LEFT JOIN Categories c ON p.Category_ID = c.Category_ID
               LEFT JOIN Product_Images pi ON p.Product_ID = pi.Product_ID

               LEFT JOIN User u ON o.Orders_Buyer_ID = u.User_ID

               LEFT JOIN Shipments s ON o.Orders_Order_ID = s.Order_ID AND s.Shipments_Type = 'forward'

               LEFT JOIN Refund_Requests rr ON o.Orders_Order_ID = rr.Order_ID
               
               /* 关联退款凭证表 */
               LEFT JOIN Refund_Evidence re ON rr.Refund_ID = re.Refund_ID

               /* 关联卖家地址 */
               LEFT JOIN Address sa ON o.Orders_Seller_ID = sa.Address_User_ID AND sa.Address_Is_Default = 1

               /* 关联争议表 */
               LEFT JOIN Dispute d ON o.Orders_Order_ID = d.Order_ID

               WHERE o.Orders_Seller_ID = :uid
               GROUP BY o.Orders_Order_ID
               ORDER BY Orders_Created_AT DESC";

    $stmtSell = $conn->prepare($sqlSell);
    $stmtSell->execute([':uid' => $userId]);
    $response['selling'] = $stmtSell->fetchAll(PDO::FETCH_ASSOC);

    $response['success'] = true;

} catch (Exception $e) {
    $response['msg'] = $e->getMessage();
}

echo json_encode($response);
?>