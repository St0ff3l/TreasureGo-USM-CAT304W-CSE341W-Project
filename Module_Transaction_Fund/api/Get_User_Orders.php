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
                    GROUP_CONCAT(DISTINCT pi.Image_URL SEPARATOR ',') AS All_Images

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
                    GROUP_CONCAT(DISTINCT pi.Image_URL SEPARATOR ',') AS All_Images

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