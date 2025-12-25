<?php
// api/Refund_Actions.php

header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/config/treasurego_db_config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$userId = $_SESSION['user_id'];
$action = $input['action'] ?? '';
$orderId = $input['order_id'] ?? 0;

if (!$orderId) {
    echo json_encode(['success' => false, 'message' => 'Missing Order ID']);
    exit;
}

$conn = getDatabaseConnection();

try {
    // =================================================================
    // 🟢 场景 1: 卖家处理申请 (Approve / Reject)
    // =================================================================
    if ($action === 'seller_decision') {
        $decision = $input['decision'];
        $refundType = $input['refund_type'];

        // 验证卖家身份
        $stmt = $conn->prepare("SELECT Orders_Seller_ID FROM Orders WHERE Orders_Order_ID = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order || $order['Orders_Seller_ID'] != $userId) {
            throw new Exception("You are not the seller of this order.");
        }

        // --- 卖家拒绝 ---
        if ($decision === 'reject') {
            $conn->prepare("UPDATE Refund_Requests SET Refund_Status = 'rejected', Refund_Updated_At = NOW() WHERE Order_ID = ?")
                ->execute([$orderId]);
        }
        // --- 卖家同意 ---
        else if ($decision === 'approve') {

            // 情况 B1: 仅退款 (Refund Only) -> 立即打钱
            if ($refundType === 'refund_only') {

                $stmt = $conn->prepare("SELECT Refund_Amount, Buyer_ID FROM Refund_Requests WHERE Order_ID = ?");
                $stmt->execute([$orderId]);
                $refundData = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$refundData) throw new Exception("Refund request not found.");

                $amount = $refundData['Refund_Amount'];
                $buyerId = $refundData['Buyer_ID'];

                // 🔥 开启事务
                $conn->beginTransaction();

                try {
                    // 1. 查找该用户最后一条流水记录，获取当前余额
                    $balanceStmt = $conn->prepare("SELECT Balance_After FROM Wallet_Logs WHERE User_ID = ? ORDER BY Log_ID DESC LIMIT 1 FOR UPDATE");
                    $balanceStmt->execute([$buyerId]);
                    $lastLog = $balanceStmt->fetch(PDO::FETCH_ASSOC);

                    $currentBalance = $lastLog ? $lastLog['Balance_After'] : 0;

                    // 2. 计算新余额
                    $newBalance = $currentBalance + $amount;

                    // 3. 写入新的流水记录
                    $logSql = "INSERT INTO Wallet_Logs 
                               (User_ID, Amount, Balance_After, Description, Reference_Type, Reference_ID, Created_AT) 
                               VALUES (?, ?, ?, ?, ?, ?, NOW())";

                    $desc = "Refund for Order #$orderId (Refund Only)";

                    $conn->prepare($logSql)->execute([
                        $buyerId,
                        $amount,
                        $newBalance,
                        $desc,
                        'Order',
                        $orderId
                    ]);

                    // 4. 更新状态
                    $conn->prepare("UPDATE Refund_Requests SET Refund_Status = 'completed', Refund_Completed_At = NOW() WHERE Order_ID = ?")->execute([$orderId]);
                    $conn->prepare("UPDATE Orders SET Orders_Status = 'cancelled' WHERE Orders_Order_ID = ?")->execute([$orderId]);

                    $conn->commit();

                } catch (Exception $e) {
                    $conn->rollBack();
                    throw $e;
                }

            }
            // 情况 B2: 退货退款 -> 只改状态
            else {
                // 状态变为 awaiting_return (前端会根据 delivery_method 显示“确认面交收货”或“填写运单号”)
                $conn->prepare("UPDATE Refund_Requests SET Refund_Status = 'awaiting_return', Refund_Updated_At = NOW() WHERE Order_ID = ?")
                    ->execute([$orderId]);
            }
        }
        echo json_encode(['success' => true]);
    }

    // =================================================================
    // 🟢 场景 2: 卖家确认收到退货 (用于面交退货完成时打钱) 🔥🔥 新增部分 🔥🔥
    // =================================================================
    else if ($action === 'seller_confirm_return_received') {

        // 1. 验证卖家身份
        $stmt = $conn->prepare("SELECT Orders_Seller_ID FROM Orders WHERE Orders_Order_ID = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order || $order['Orders_Seller_ID'] != $userId) {
            throw new Exception("You are not the seller of this order.");
        }

        // 2. 获取退款金额
        $stmt = $conn->prepare("SELECT Refund_Amount, Buyer_ID FROM Refund_Requests WHERE Order_ID = ?");
        $stmt->execute([$orderId]);
        $refundData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$refundData) throw new Exception("Refund request not found.");

        $amount = $refundData['Refund_Amount'];
        $buyerId = $refundData['Buyer_ID'];

        // 🔥 开启事务 (执行打钱逻辑)
        $conn->beginTransaction();

        try {
            // (1) 查最新余额
            $balanceStmt = $conn->prepare("SELECT Balance_After FROM Wallet_Logs WHERE User_ID = ? ORDER BY Log_ID DESC LIMIT 1 FOR UPDATE");
            $balanceStmt->execute([$buyerId]);
            $lastLog = $balanceStmt->fetch(PDO::FETCH_ASSOC);
            $currentBalance = $lastLog ? $lastLog['Balance_After'] : 0;

            // (2) 计算新余额
            $newBalance = $currentBalance + $amount;

            // (3) 写日志
            $logSql = "INSERT INTO Wallet_Logs (User_ID, Amount, Balance_After, Description, Reference_Type, Reference_ID, Created_AT) VALUES (?, ?, ?, ?, 'Order', ?, NOW())";
            $conn->prepare($logSql)->execute([
                $buyerId,
                $amount,
                $newBalance,
                "Refund for Order #$orderId (Return Received)",
                $orderId
            ]);

            // (4) 更新状态
            $conn->prepare("UPDATE Refund_Requests SET Refund_Status = 'completed', Refund_Completed_At = NOW() WHERE Order_ID = ?")->execute([$orderId]);
            $conn->prepare("UPDATE Orders SET Orders_Status = 'cancelled' WHERE Orders_Order_ID = ?")->execute([$orderId]);

            $conn->commit();
            echo json_encode(['success' => true]);

        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    // =================================================================
    // 🟢 场景 3: 买家提交快递单号
    // =================================================================
    else if ($action === 'submit_return_tracking') {
        $input['tracking'] ? null : throw new Exception("Tracking required");
        // 这里可以加一行 update 把单号写进数据库
        $conn->prepare("UPDATE Refund_Requests SET Refund_Status = 'awaiting_confirm' WHERE Order_ID = ?")->execute([$orderId]);
        echo json_encode(['success' => true]);
    }

    // =================================================================
    // 🟢 场景 4: 买家确认面交退货 (备用)
    // =================================================================
    else if ($action === 'confirm_return_handover') {
        $conn->prepare("UPDATE Refund_Requests SET Refund_Status = 'awaiting_confirm' WHERE Order_ID = ?")->execute([$orderId]);
        echo json_encode(['success' => true]);
    }
    else {
        throw new Exception("Invalid action");
    }

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>