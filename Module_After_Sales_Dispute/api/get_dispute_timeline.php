<?php
// api/get_dispute_timeline.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../Module_Transaction_Fund/api/config/treasurego_db_config.php';
require_once __DIR__ . '/../../Module_User_Account_Management/includes/auth.php'; // 假设你有鉴权库

session_start();
$userId = $_SESSION['user_id'] ?? 0;

if ($userId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$orderId = intval($_GET['order_id'] ?? 0);
if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing Order ID']);
    exit;
}

try {
    $pdo = getDatabaseConnection();

    // 1. 获取争议主信息
    $sqlMain = "SELECT 
                    d.Dispute_ID, d.Dispute_Status, d.Action_Required_By, d.Dispute_Reason, d.Dispute_Resolution_Outcome,
                    o.Orders_Buyer_ID, o.Orders_Seller_ID
                FROM Dispute d
                JOIN Orders o ON d.Order_ID = o.Orders_Order_ID
                WHERE d.Order_ID = ?";
    $stmt = $pdo->prepare($sqlMain);
    $stmt->execute([$orderId]);
    $dispute = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dispute) {
        echo json_encode(['success' => false, 'message' => 'No dispute found']);
        exit;
    }

    // 鉴权：只有买家和卖家能看
    if ($dispute['Orders_Buyer_ID'] != $userId && $dispute['Orders_Seller_ID'] != $userId) {
        echo json_encode(['success' => false, 'message' => 'Access Denied']);
        exit;
    }

    // 2. 获取补充记录 (历史时间线)
    // 按时间正序排列 (Oldest -> Newest)
    $sqlLog = "SELECT 
                    Record_ID, User_Role, Content, Evidence_Images, Record_Type, Created_At
               FROM Dispute_Supplement_Record
               WHERE Dispute_ID = ?
               ORDER BY Created_At ASC";
    $stmtLog = $pdo->prepare($sqlLog);
    $stmtLog->execute([$dispute['Dispute_ID']]);
    $timeline = $stmtLog->fetchAll(PDO::FETCH_ASSOC);

    // 3. 处理图片 JSON
    foreach ($timeline as &$log) {
        if (!empty($log['Evidence_Images'])) {
            $log['Evidence_Images'] = json_decode($log['Evidence_Images']);
        } else {
            $log['Evidence_Images'] = [];
        }
    }

    // 4. 确定当前用户角色
    $myRole = ($dispute['Orders_Buyer_ID'] == $userId) ? 'Buyer' : 'Seller';

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