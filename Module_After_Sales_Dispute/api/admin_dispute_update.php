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

$adminId = get_current_user_id();

$pdo = getDatabaseConnection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$disputeId = intval($payload['Dispute_ID'] ?? 0);
$newStatus = trim($payload['Dispute_Status'] ?? '');

// 🔥 Dispute Resolution (refund direction & admin messages)
$outcome = trim($payload['Dispute_Resolution_Outcome'] ?? ''); // refund_buyer | refund_seller | partial
$refundAmount = $payload['Dispute_Refund_Amount'] ?? null; // decimal, may come as string
$replyBuyer = trim($payload['Dispute_Admin_Reply_To_Buyer'] ?? '');
$replySeller = trim($payload['Dispute_Admin_Reply_To_Seller'] ?? '');

// Optional: create an Admin Action and link to dispute
$actionType = trim($payload['Admin_Action_Type'] ?? '');
$actionReason = trim($payload['Admin_Action_Reason'] ?? '');
$actionResolution = trim($payload['Admin_Action_Final_Resolution'] ?? '');
$actionEndDate = $payload['Admin_Action_End_Date'] ?? null; // allow null

$allowedStatuses = ['Open', 'Closed', 'In Review', 'Resolved'];
$allowedOutcomes = ['refund_buyer', 'refund_seller', 'partial'];

if ($disputeId <= 0 || $newStatus === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing Dispute_ID or Dispute_Status']);
    exit;
}
if (!in_array($newStatus, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid Dispute_Status']);
    exit;
}

// If admin is resolving, require outcome + both replies. Partial/refund_buyer require amount.
$isResolving = ($newStatus === 'Resolved');
if ($isResolving) {
    if ($outcome === '' || !in_array($outcome, $allowedOutcomes, true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Resolved dispute requires Dispute_Resolution_Outcome (refund_buyer/refund_seller/partial)']);
        exit;
    }
    if ($replyBuyer === '' || $replySeller === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Resolved dispute requires replies for both buyer and seller']);
        exit;
    }

    if ($outcome === 'refund_buyer' || $outcome === 'partial') {
        if ($refundAmount === null || $refundAmount === '' || !is_numeric($refundAmount)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Refund amount is required for outcome refund_buyer/partial']);
            exit;
        }
        $refundAmount = floatval($refundAmount);
        if ($refundAmount <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Refund amount must be > 0']);
            exit;
        }
    } else {
        // refund_seller: default to 0
        $refundAmount = null;
    }
}

// If any admin action field provided, require the full set.
$wantsAction = ($actionType !== '' || $actionReason !== '' || $actionResolution !== '' || ($actionEndDate !== null && $actionEndDate !== ''));
if ($wantsAction) {
    if ($actionType === '' || $actionReason === '' || $actionResolution === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Admin action requires Admin_Action_Type, Admin_Action_Reason, Admin_Action_Final_Resolution']);
        exit;
    }
    if ($actionEndDate === '') $actionEndDate = null;
}

try {
    $pdo->beginTransaction();

    // Lock dispute row and gather required linkage (order/refund/buyer)
    $stmt = $pdo->prepare('SELECT Dispute_ID, Order_ID, Refund_ID, Reporting_User_ID, Reported_User_ID FROM Dispute WHERE Dispute_ID = ? FOR UPDATE');
    $stmt->execute([$disputeId]);
    $dispute = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dispute) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Dispute not found']);
        exit;
    }

    $adminActionId = null;

    if ($wantsAction) {
        $targetUserId = intval($dispute['Reported_User_ID']);

        $sqlAction = "INSERT INTO Administrative_Action
                      (Admin_Action_Type, Admin_Action_Reason, Admin_Action_Start_Date, Admin_Action_End_Date,
                       Admin_Action_Final_Resolution, Admin_ID, Target_User_ID, Admin_Action_Source)
                      VALUES (?, ?, NOW(), ?, ?, ?, ?, 'dispute')";

        $stmtAction = $pdo->prepare($sqlAction);
        $stmtAction->execute([
            $actionType,
            $actionReason,
            $actionEndDate,
            $actionResolution,
            $adminId,
            $targetUserId
        ]);

        $adminActionId = $pdo->lastInsertId();
    }

    // =============================
    // 1) Update Dispute fields
    // =============================
    if ($isResolving) {
        $sqlUp = 'UPDATE Dispute
                  SET Dispute_Status = ?,
                      Admin_Action_ID = COALESCE(?, Admin_Action_ID),
                      Dispute_Resolution_Outcome = ?,
                      Dispute_Refund_Amount = ?,
                      Dispute_Admin_Reply_To_Buyer = ?,
                      Dispute_Admin_Reply_To_Seller = ?,
                      Dispute_Admin_Resolved_At = NOW(),
                      Dispute_Admin_ID = ?
                  WHERE Dispute_ID = ?';
        $pdo->prepare($sqlUp)->execute([
            $newStatus,
            $adminActionId,
            $outcome,
            $refundAmount,
            $replyBuyer,
            $replySeller,
            $adminId,
            $disputeId
        ]);
    } else {
        // non-resolve updates
        if ($adminActionId !== null) {
            $pdo->prepare('UPDATE Dispute SET Dispute_Status = ?, Admin_Action_ID = ? WHERE Dispute_ID = ?')->execute([$newStatus, $adminActionId, $disputeId]);
        } else {
            $pdo->prepare('UPDATE Dispute SET Dispute_Status = ? WHERE Dispute_ID = ?')->execute([$newStatus, $disputeId]);
        }
    }

    // =============================
    // 2) Sync money flow + Refund_Requests when resolved
    // =============================
    if ($isResolving) {
        $refundId = $dispute['Refund_ID'] ? intval($dispute['Refund_ID']) : 0;
        $orderId = intval($dispute['Order_ID']);
        $buyerId = intval($dispute['Reporting_User_ID']);

        if ($refundId <= 0) {
            throw new Exception('Resolved dispute requires Refund_ID');
        }

        // Lock refund row
        $stmtR = $pdo->prepare('SELECT Refund_ID, Refund_Amount, Refund_Status FROM Refund_Requests WHERE Refund_ID = ? FOR UPDATE');
        $stmtR->execute([$refundId]);
        $refundRow = $stmtR->fetch(PDO::FETCH_ASSOC);
        if (!$refundRow) throw new Exception('Refund request not found for dispute');

        if ($outcome === 'refund_seller') {
            // Buyer loses: close refund
            $pdo->prepare("UPDATE Refund_Requests SET Refund_Status = 'closed', Refund_Updated_At = NOW() WHERE Refund_ID = ?")->execute([$refundId]);

        } else {
            // refund_buyer / partial: credit buyer wallet with refundAmount
            $amountToRefund = floatval($refundAmount);

            // current wallet
            $balanceStmt = $pdo->prepare("SELECT Balance_After FROM Wallet_Logs WHERE User_ID = ? ORDER BY Log_ID DESC LIMIT 1 FOR UPDATE");
            $balanceStmt->execute([$buyerId]);
            $lastLog = $balanceStmt->fetch(PDO::FETCH_ASSOC);
            $currentBalance = $lastLog ? floatval($lastLog['Balance_After']) : 0.0;
            $newBalance = $currentBalance + $amountToRefund;

            $desc = "Dispute resolved: refund for Order #{$orderId} (Dispute #{$disputeId})";
            $logSql = "INSERT INTO Wallet_Logs (User_ID, Amount, Balance_After, Description, Reference_Type, Reference_ID, Created_AT)
                       VALUES (?, ?, ?, ?, 'Order', ?, NOW())";
            $pdo->prepare($logSql)->execute([$buyerId, $amountToRefund, $newBalance, $desc, $orderId]);

            // mark refund completed
            $pdo->prepare("UPDATE Refund_Requests SET Refund_Status = 'completed', Refund_Completed_At = NOW(), Refund_Updated_At = NOW() WHERE Refund_ID = ?")->execute([$refundId]);
        }

        // reflect in Orders_Status (keep existing behavior: cancelled on buyer refund)
        if ($outcome === 'refund_buyer' || $outcome === 'partial') {
            $pdo->prepare("UPDATE Orders SET Orders_Status = 'cancelled' WHERE Orders_Order_ID = ?")->execute([$orderId]);
        }
    }

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'data' => [
            'Dispute_ID' => $disputeId,
            'Dispute_Status' => $newStatus,
            'Admin_Action_ID' => $adminActionId,
            'Dispute_Resolution_Outcome' => $outcome,
            'Dispute_Refund_Amount' => $refundAmount
        ]
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
