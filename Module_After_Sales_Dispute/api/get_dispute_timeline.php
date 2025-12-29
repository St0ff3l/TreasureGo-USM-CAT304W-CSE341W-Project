<?php
// Module_After_Sales_Dispute/api/get_dispute_timeline.php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../Module_Transaction_Fund/api/config/treasurego_db_config.php';
require_once __DIR__ . '/../../Module_User_Account_Management/includes/auth.php'; // 引入鉴权

start_session_safe(); // 确保 session 开启

$userId = $_SESSION['user_id'] ?? 0;
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin'; // 假设 session 里有 role，或者用 is_admin() 函数

if ($userId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 既支持传 order_id 也支持传 dispute_id
$orderId = intval($_GET['order_id'] ?? 0);
$disputeId = intval($_GET['dispute_id'] ?? 0);

if ($orderId <= 0 && $disputeId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing ID parameters']);
    exit;
}

try {
    $pdo = getDatabaseConnection();

    // 1. 获取争议主信息 (为了鉴权和拿 Dispute_ID)
    $sqlMain = "SELECT 
                    d.Dispute_ID, d.Dispute_Status, d.Action_Required_By, d.Dispute_Reason, 
                    o.Orders_Buyer_ID, o.Orders_Seller_ID
                FROM Dispute d
                JOIN Orders o ON d.Order_ID = o.Orders_Order_ID
                WHERE ";

    if ($disputeId > 0) {
        $sqlMain .= "d.Dispute_ID = ?";
        $param = $disputeId;
    } else {
        $sqlMain .= "d.Order_ID = ?";
        $param = $orderId;
    }

    $stmt = $pdo->prepare($sqlMain);
    $stmt->execute([$param]);
    $dispute = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dispute) {
        echo json_encode(['success' => false, 'message' => 'No dispute found']);
        exit;
    }

    // 2. 鉴权：只有 (买家 OR 卖家 OR 管理员) 能看
    // 如果你有 is_admin() 函数，请替换这里的判断
    if (!$isAdmin && $dispute['Orders_Buyer_ID'] != $userId && $dispute['Orders_Seller_ID'] != $userId) {
        echo json_encode(['success' => false, 'message' => 'Access Denied']);
        exit;
    }

    // 3. 获取补充记录 (历史时间线)
    $realDisputeId = $dispute['Dispute_ID'];
    $sqlLog = "SELECT 
                    Record_ID, User_Role, Content, Evidence_Images, Record_Type, Created_At
               FROM Dispute_Supplement_Record
               WHERE Dispute_ID = ?
               ORDER BY Created_At ASC";

    $stmtLog = $pdo->prepare($sqlLog);
    $stmtLog->execute([$realDisputeId]);
    $timeline = $stmtLog->fetchAll(PDO::FETCH_ASSOC);

    // 处理图片 JSON
    foreach ($timeline as &$log) {
        if (!empty($log['Evidence_Images'])) {
            $decoded = json_decode($log['Evidence_Images']);
            $log['Evidence_Images'] = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        } else {
            $log['Evidence_Images'] = [];
        }
    }

    // 确定当前查看者的角色
    $myRole = 'Viewer';
    if ($isAdmin) $myRole = 'Admin';
    elseif ($dispute['Orders_Buyer_ID'] == $userId) $myRole = 'Buyer';
    elseif ($dispute['Orders_Seller_ID'] == $userId) $myRole = 'Seller';

    echo json_encode([
        'success' => true,
        'data' => [
            'info' => $dispute,
            'timeline' => $timeline,
            'my_role' => $myRole
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>