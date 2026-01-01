<?php
// Module_After_Sales_Dispute/api/dispute_seller_submit.php

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../Module_Transaction_Fund/api/config/treasurego_db_config.php';

session_start();

function out($success, $message, $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    out(false, 'Unauthorized');
}

$userId = intval($_SESSION['user_id']);

// Helper function: Clean image links
function normalize_evidence_urls($urls) {
    if (!is_array($urls)) return [];
    $clean = [];
    // Must include prefix to prevent malicious links
    $targetPrefix = 'Module_After_Sales_Dispute/assets/images/evidence_images/';
    foreach ($urls as $u) {
        $u = trim((string)$u);
        if ($u === '') continue;
        if (strpos($u, $targetPrefix) !== false) $clean[] = $u;
    }
    return array_values(array_unique($clean));
}

try {
    $pdo = getDatabaseConnection();

    // ==========================================
    // Logic Branch A: Upload Image (Handle Multipart/form-data)
    // ==========================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['evidence'])) {
        $orderId = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

        // 1. Simple permission check
        if ($orderId > 0) {
            $stmtCheck = $pdo->prepare("SELECT Orders_Seller_ID FROM Orders WHERE Orders_Order_ID = ?");
            $stmtCheck->execute([$orderId]);
            $o = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if (!$o || intval($o['Orders_Seller_ID']) !== $userId) {
                throw new Exception('Permission denied: You do not own this order.');
            }
        }

        // 2. Prepare directory
        // Physical path
        $uploadDir = __DIR__ . '/../assets/images/evidence_images/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $files = $_FILES['evidence'];
        // Handle single and multiple file uploads compatibility
        $fileNames = is_array($files['name']) ? $files['name'] : [$files['name']];
        $fileTmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
        $fileErrors = is_array($files['error']) ? $files['error'] : [$files['error']];

        $saved = [];
        $count = count($fileNames);

        for ($i = 0; $i < $count; $i++) {
            if (($fileErrors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

            $tmpName = $fileTmpNames[$i];
            $origName = $fileNames[$i];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, $allowed)) continue;

            // Generate unique filename: DISPUTE_SELLER_{OrderId}_{Time}_{Random}.ext
            $safeOrderId = $orderId > 0 ? $orderId : 'TEMP';
            $newFileName = sprintf('DISPUTE_SELLER_%s_%s_%s.%s', $safeOrderId, time(), uniqid(), $ext);
            $destination = $uploadDir . $newFileName;

            if (move_uploaded_file($tmpName, $destination)) {
                // Return web path to frontend (path stored in database)
                $dbPath = 'Module_After_Sales_Dispute/assets/images/evidence_images/' . $newFileName;

                $saved[] = [
                    'url' => $dbPath,
                    'type' => 'image',
                    'original_name' => $origName
                ];
            }
        }

        if (empty($saved)) throw new Exception('No valid images uploaded.');

        // Return success JSON, including files array for frontend map use
        out(true, 'Uploaded successfully', ['files' => $saved]);
    }

    // ==========================================
    // Logic Branch B: Submit Data
    // ==========================================
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (is_array($data)) {
        $orderId = intval($data['order_id'] ?? 0);
        $content = trim($data['seller_response'] ?? $data['dispute_details'] ?? '');
        $evidenceImgs = $data['evidence_images'] ?? [];

        // 1. Receive new parameters from frontend
        $reasonCode = trim($data['reason_code'] ?? 'Seller_Refused_Return');
        $receivedStatus = isset($data['received_status']) ? intval($data['received_status']) : null;

        // Construct Dispute_Reason (Title) and Dispute_Details (Specific reason code)
        $disputeReasonTitle = 'Seller Dispute';
        // The reasonCode here corresponds to the required field that caused the error, e.g., 'fake_tracking'
        $disputeDetails = $reasonCode;

        // If you want to include the received status in the description:
        if ($receivedStatus !== null) {
            $statusText = $receivedStatus === 1 ? "[Item Received]" : "[Item Not Received]";
            $content = $statusText . " " . $content;
        }

        if ($orderId <= 0) out(false, 'Missing Order ID');

        // Verify Seller permission
        $stmtOrder = $pdo->prepare('SELECT Orders_Buyer_ID, Orders_Seller_ID FROM Orders WHERE Orders_Order_ID = ?');
        $stmtOrder->execute([$orderId]);
        $orderInfo = $stmtOrder->fetch(PDO::FETCH_ASSOC);

        if (!$orderInfo) throw new Exception('Order not found');
        if (intval($orderInfo['Orders_Seller_ID']) !== $userId) throw new Exception('Permission denied: You are not the seller.');

        $buyerId = intval($orderInfo['Orders_Buyer_ID']);
        $stmtRefund = $pdo->prepare('SELECT Refund_ID FROM Refund_Requests WHERE Order_ID = ? LIMIT 1');
        $stmtRefund->execute([$orderId]);
        $refundRow = $stmtRefund->fetch(PDO::FETCH_ASSOC);
        $refundId = $refundRow ? intval($refundRow['Refund_ID']) : null;

        $pdo->beginTransaction();

        // Check if main table Dispute exists
        $stmtCheck = $pdo->prepare("SELECT Dispute_ID, Action_Required_By, Dispute_Seller_Evidence FROM Dispute WHERE Order_ID = ? AND Dispute_Status NOT IN ('Resolved', 'Closed', 'Cancelled')");
        $stmtCheck->execute([$orderId]);
        $existingDispute = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        $evidenceJson = json_encode(normalize_evidence_urls($evidenceImgs));

        if ($existingDispute) {
            // --- Case 1: Dispute exists (Append) ---
            $disputeId = $existingDispute['Dispute_ID'];

            // 1. Insert supplementary record
            $sqlSup = "INSERT INTO Dispute_Supplement_Record 
                      (Dispute_ID, User_ID, User_Role, Content, Evidence_Images, Record_Type, Created_At)
                      VALUES (?, ?, 'Seller', ?, ?, 'Evidence', NOW())";
            $pdo->prepare($sqlSup)->execute([$disputeId, $userId, $content, $evidenceJson]);

            // Check current status, if Both, change to Buyer (waiting for buyer), otherwise change to Admin
            $currentAction = $existingDispute['Action_Required_By'];
            $newAction = 'Admin';
            if ($currentAction === 'Both') {
                $newAction = 'Buyer'; // Seller done, now buyer's turn
            }

            // 2. Update main table status
            $sqlUp = "UPDATE Dispute SET 
            Action_Required_By = ?,  /* Change 'Admin' to placeholder ? here */
            Dispute_Status = CASE WHEN Dispute_Status = 'Pending Info' THEN 'In Review' ELSE Dispute_Status END,
            Seller_Description = COALESCE(NULLIF(Seller_Description, ''), ?),
            Dispute_Seller_Evidence = COALESCE(NULLIF(Dispute_Seller_Evidence, '[]'), ?)
          WHERE Dispute_ID = ?";

            // Note: $newAction is added to execute parameters
            $pdo->prepare($sqlUp)->execute([$newAction, $content, $evidenceJson, $disputeId]);

            $pdo->commit();
            out(true, 'Seller evidence added.', ['dispute_id' => $disputeId]);

        } else {
            // --- Case 2: Seller creates new dispute ---
            if (empty($content) && empty($evidenceImgs)) {
                throw new Exception('Please provide details or evidence.');
            }

            // Modify SQL: Must include Dispute_Details
            $sqlInsert = "INSERT INTO Dispute (
                Order_ID, Refund_ID, Reporting_User_ID, Reported_User_ID,
                Dispute_Reason, Dispute_Details, Dispute_Status, Dispute_Creation_Date, Action_Required_By,
                Seller_Description, Dispute_Seller_Evidence
            ) VALUES (?, ?, ?, ?, ?, ?, 'Open', NOW(), 'Admin', ?, ?)";

            $stmtIns = $pdo->prepare($sqlInsert);
            $stmtIns->execute([
                $orderId,
                $refundId,
                $userId,
                $buyerId,
                $disputeReasonTitle, // Dispute_Reason (Title)
                $disputeDetails,     // Dispute_Details (Required specific reason code)
                $content,
                $evidenceJson
            ]);
            $newDisputeId = $pdo->lastInsertId();

            // Insert a Supplement record as well
            $sqlSup = "INSERT INTO Dispute_Supplement_Record 
                      (Dispute_ID, User_ID, User_Role, Content, Evidence_Images, Record_Type, Created_At)
                      VALUES (?, ?, 'Seller', ?, ?, 'Evidence', NOW())";
            $pdo->prepare($sqlSup)->execute([$newDisputeId, $userId, $content, $evidenceJson]);

            if ($refundId) {
                $pdo->prepare("UPDATE Refund_Requests SET Refund_Status = 'dispute_in_progress', Refund_Updated_At = NOW() WHERE Refund_ID = ?")->execute([$refundId]);
            }

            $pdo->commit();
            out(true, 'Dispute opened by seller.', ['dispute_id' => $newDisputeId]);
        }
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    out(false, $e->getMessage());
}
?>