<?php
// Module_Platform_Governance_AI_Services/api/admin_dispute_update.php

header('Content-Type: application/json; charset=utf-8');

// Include DB config and Auth
require_once __DIR__ . '/../../Module_Platform_Governance_AI_Services/api/config/treasurego_db_config.php';
require_once __DIR__ . '/../../Module_User_Account_Management/includes/auth.php';

start_session_safe();

// 1. Authorization Check
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

// 2. Retrieve Parameters
$payload = json_decode(file_get_contents('php://input'), true);
$disputeId = intval($payload['Dispute_ID'] ?? 0);
$newStatus = trim($payload['Dispute_Status'] ?? '');

// Resolution Outcome (Prevent empty string causing ENUM error)
$outcome = trim($payload['Dispute_Resolution_Outcome'] ?? '');
if ($outcome === '') $outcome = null;

$refundAmount = $payload['Dispute_Refund_Amount'] ?? null;
$replyBuyer = trim($payload['Dispute_Admin_Reply_To_Buyer'] ?? '');
$replySeller = trim($payload['Dispute_Admin_Reply_To_Seller'] ?? '');

// Action Required By
$actionRequiredBy = trim($payload['Action_Required_By'] ?? '');
$validActions = ['None', 'Buyer', 'Seller', 'Admin', 'Both'];
if ($actionRequiredBy === '' || !in_array($actionRequiredBy, $validActions)) {
    $actionRequiredBy = null;
}

// 3. Basic Validation
$allowedStatuses = ['Open', 'Closed', 'In Review', 'Resolved'];
$allowedOutcomes = ['refund_buyer', 'refund_seller', 'partial'];

if ($disputeId <= 0 || $newStatus === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing Dispute_ID or Dispute_Status']);
    exit;
}

// If status is Resolved, validate resolution parameters
$isResolving = ($newStatus === 'Resolved');
if ($isResolving) {
    if (!$outcome || !in_array($outcome, $allowedOutcomes, true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Resolved dispute requires a valid Outcome']);
        exit;
    }
    if ($replyBuyer === '' || $replySeller === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Replies required for both parties']);
        exit;
    }
    // Validate refund amount for refund_buyer or partial outcomes
    if (($outcome === 'refund_buyer' || $outcome === 'partial') && (!is_numeric($refundAmount) || floatval($refundAmount) < 0)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid Refund Amount']);
        exit;
    }
}

try {
    $pdo->beginTransaction();

    // 4. Lock Dispute Record
    $stmt = $pdo->prepare('SELECT Dispute_ID, Order_ID, Refund_ID, Reporting_User_ID, Reported_User_ID FROM Dispute WHERE Dispute_ID = ? FOR UPDATE');
    $stmt->execute([$disputeId]);
    $dispute = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dispute) throw new Exception('Dispute not found');

    // =================================================================================
    // [STEP A] Pre-calculate Financials (If Resolving)
    // Calculating here allows logging full financial details in Administrative_Action later
    // =================================================================================
    $amountToBuyer = 0.00;
    $amountToSeller = 0.00;
    $totalOrderAmount = 0.00;
    $buyerId = 0;
    $sellerId = 0;

    if ($isResolving) {
        $orderId = intval($dispute['Order_ID']);
        $stmtOrd = $pdo->prepare("SELECT Orders_Total_Amount, Orders_Buyer_ID, Orders_Seller_ID FROM Orders WHERE Orders_Order_ID = ?");
        $stmtOrd->execute([$orderId]);
        $orderInfo = $stmtOrd->fetch(PDO::FETCH_ASSOC);

        if (!$orderInfo) throw new Exception('Order not found');

        $totalOrderAmount = floatval($orderInfo['Orders_Total_Amount']);
        $buyerId = intval($orderInfo['Orders_Buyer_ID']);
        $sellerId = intval($orderInfo['Orders_Seller_ID']);

        // Calculate distribution based on outcome
        if ($outcome === 'refund_buyer') {
            // Full Refund
            $amountToBuyer = $totalOrderAmount;
            $amountToSeller = 0.00;
        } elseif ($outcome === 'refund_seller') {
            // Refund Rejected (Full Release to Seller)
            $amountToBuyer = 0.00;
            $amountToSeller = $totalOrderAmount;
        } elseif ($outcome === 'partial') {
            // Partial Refund
            $amountToBuyer = floatval($refundAmount);
            // Cap at total order amount
            if ($amountToBuyer > $totalOrderAmount) $amountToBuyer = $totalOrderAmount;
            $amountToSeller = $totalOrderAmount - $amountToBuyer;
        }
    }

    // =================================================================================
    // [STEP B] Update Dispute Table
    // =================================================================================
    $sql = "UPDATE Dispute SET
                Dispute_Status = ?,
                Action_Required_By = COALESCE(?, Action_Required_By),
                Dispute_Resolution_Outcome = ?,
                Dispute_Refund_Amount = ?,
                Dispute_Admin_Reply_To_Buyer = ?,
                Dispute_Admin_Reply_To_Seller = ?,
                Dispute_Admin_Resolved_At = CASE WHEN ? = 'Resolved' THEN NOW() ELSE Dispute_Admin_Resolved_At END,
                Dispute_Admin_ID = ?
            WHERE Dispute_ID = ?";

    $finalActionBy = $isResolving ? 'None' : $actionRequiredBy;

    // Refund amount in Dispute table typically records amount returned to buyer.
    // If refund rejected, set to 0.
    $dbRefundAmount = ($outcome === 'refund_seller') ? 0 : $amountToBuyer;

    $pdo->prepare($sql)->execute([
        $newStatus, $finalActionBy, $outcome, $dbRefundAmount, $replyBuyer, $replySeller, $newStatus, $adminId, $disputeId
    ]);

    // =================================================================================
    // [STEP C] Insert Timeline Records
    // =================================================================================
    if (!empty($replyBuyer)) {
        $pdo->prepare("INSERT INTO Dispute_Supplement_Record (Dispute_ID, User_ID, User_Role, Content, Record_Type, Created_At) VALUES (?, ?, 'Admin', ?, 'System', NOW())")
            ->execute([$disputeId, $adminId, "[Instruction to Buyer]: " . $replyBuyer]);
    }
    if (!empty($replySeller)) {
        $pdo->prepare("INSERT INTO Dispute_Supplement_Record (Dispute_ID, User_ID, User_Role, Content, Record_Type, Created_At) VALUES (?, ?, 'Admin', ?, 'System', NOW())")
            ->execute([$disputeId, $adminId, "[Instruction to Seller]: " . $replySeller]);
    }
    if ($isResolving) {
        $pdo->prepare("INSERT INTO Dispute_Supplement_Record (Dispute_ID, User_ID, User_Role, Content, Record_Type, Created_At) VALUES (?, ?, 'Admin', ?, 'System', NOW())")
            ->execute([$disputeId, $adminId, "Dispute Resolved by Admin. Outcome: " . $outcome]);
    }

    // =================================================================================
    // [STEP D] Log Administrative Action - With Full Financial Details
    // =================================================================================
    if ($isResolving) {
        // Format amount strings
        $strBuyer = number_format($amountToBuyer, 2);
        $strSeller = number_format($amountToSeller, 2);
        $resolutionDetail = "";

        if ($outcome === 'partial') {
            // e.g., "Partial Refund: Buyer(RM 20.00) / Seller(RM 80.00)"
            $resolutionDetail = "Partial Refund: Buyer(RM {$strBuyer}) / Seller(RM {$strSeller})";
        } elseif ($outcome === 'refund_buyer') {
            $resolutionDetail = "Full Refund to Buyer (RM {$strBuyer})";
        } elseif ($outcome === 'refund_seller') {
            $resolutionDetail = "Refund Rejected - Full Release to Seller (RM {$strSeller})";
        } else {
            $resolutionDetail = ucfirst($outcome);
        }

        $actionReason = "Resolved Dispute #{$disputeId}. Decision: {$resolutionDetail}";
        $targetUserId = intval($dispute['Reported_User_ID']);

        // Removed non-existent Created_At field
        $sqlAdminAction = "INSERT INTO Administrative_Action 
            (Admin_Action_Type, Admin_Action_Reason, Admin_Action_Start_Date, Admin_Action_Final_Resolution, Admin_ID, Target_User_ID, Admin_Action_Source)
            VALUES 
            ('Dispute_Resolution', ?, NOW(), ?, ?, ?, 'dispute')";

        $pdo->prepare($sqlAdminAction)->execute([
            $actionReason,
            $resolutionDetail, // Includes seller amount detail now
            $adminId,
            $targetUserId
        ]);
    }

    // =================================================================================
    // [STEP E] Financial Settlement & Data Sync (Execute Calculations)
    // =================================================================================
    if ($isResolving) {
        $orderId = intval($dispute['Order_ID']);
        $refundId = intval($dispute['Refund_ID']);
        $newOrderStatus = ($outcome === 'refund_buyer') ? 'cancelled' : 'completed';

        // 1. Credit Buyer (if any)
        if ($amountToBuyer > 0) {
            $stmtBal = $pdo->prepare("SELECT Balance_After FROM Wallet_Logs WHERE User_ID = ? ORDER BY Log_ID DESC LIMIT 1 FOR UPDATE");
            $stmtBal->execute([$buyerId]);
            $lastLog = $stmtBal->fetch(PDO::FETCH_ASSOC);
            $currentBal = $lastLog ? floatval($lastLog['Balance_After']) : 0.00;
            $newBal = $currentBal + $amountToBuyer;

            $desc = "Refund: Order #{$orderId} (Dispute #{$disputeId})";
            $pdo->prepare("INSERT INTO Wallet_Logs (User_ID, Amount, Balance_After, Description, Reference_Type, Reference_ID, Created_AT) VALUES (?, ?, ?, ?, 'Order', ?, NOW())")
                ->execute([$buyerId, $amountToBuyer, $newBal, $desc, $orderId]);
        }

        // 2. Credit Seller (if any)
        if ($amountToSeller > 0) {
            $stmtBal = $pdo->prepare("SELECT Balance_After FROM Wallet_Logs WHERE User_ID = ? ORDER BY Log_ID DESC LIMIT 1 FOR UPDATE");
            $stmtBal->execute([$sellerId]);
            $lastLog = $stmtBal->fetch(PDO::FETCH_ASSOC);
            $currentBal = $lastLog ? floatval($lastLog['Balance_After']) : 0.00;
            $newBal = $currentBal + $amountToSeller;

            $desc = "Earnings: Order #{$orderId} (Dispute Settled)";
            $pdo->prepare("INSERT INTO Wallet_Logs (User_ID, Amount, Balance_After, Description, Reference_Type, Reference_ID, Created_AT) VALUES (?, ?, ?, ?, 'Order', ?, NOW())")
                ->execute([$sellerId, $amountToSeller, $newBal, $desc, $orderId]);
        }

        // 3. Update Refund_Requests (Sync Amount)
        // Ensure Refund_Amount reflects actual refunded amount, not requested amount
        $finalRefundStatus = ($outcome === 'refund_seller') ? 'closed' : 'completed';

        $pdo->prepare("UPDATE Refund_Requests SET 
                        Refund_Status = ?,
                        Refund_Amount = ?, 
                        Refund_Completed_At = NOW(),
                        Refund_Updated_At = NOW()
                       WHERE Refund_ID = ?")
            ->execute([$finalRefundStatus, $amountToBuyer, $refundId]);

        // 4. Update Orders
        $pdo->prepare("UPDATE Orders SET Orders_Status = ? WHERE Orders_Order_ID = ?")
            ->execute([$newOrderStatus, $orderId]);
    }

    $pdo->commit();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>