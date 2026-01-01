<?php
// =================================================================
// 1. Initialization Settings
// =================================================================
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
ob_start();

session_start();

function send_json_response($success, $message, $data = []) {
    ob_clean();
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $data));
    exit;
}

try {
    // =================================================================
    // 2. Database Connection
    // =================================================================
    $db_path = __DIR__ . '/config/treasurego_db_config.php';

    if (!file_exists($db_path)) {
        throw new Exception("Config file not found at: " . $db_path);
    }
    require_once $db_path;

    if (!isset($conn) || !$conn) {
        throw new Exception("Database connection failed.");
    }

    if (!isset($_SESSION['user_id'])) {
        send_json_response(false, 'Unauthorized: Please log in first.');
    }
    $current_user_id = $_SESSION['user_id'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_json_response(false, 'Invalid request method.');
    }

    // =================================================================
    // 3. Data Validation and Preparation
    // =================================================================
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

    $raw_type = isset($_POST['refund_type']) ? $_POST['refund_type'] : '';
    $allowed_types = ['refund_only', 'return_refund']; // Backend actual stored values correspond to frontend

    // Frontend might pass refund_only or return_refund
    // Database enum is typically 'refund_only', 'return_refund'
    if (!in_array($raw_type, $allowed_types)) {
        throw new Exception("Invalid Refund Type: '{$raw_type}'");
    }
    $refund_type = $raw_type;

    // New: Receive received goods status (0=No, 1=Yes)
    $has_received = isset($_POST['has_received']) ? intval($_POST['has_received']) : 0;

    $reason = isset($_POST['reason']) ? $_POST['reason'] : '';
    $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0.00;
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';

    if ($order_id <= 0 || empty($reason) || $amount <= 0) {
        throw new Exception("Missing required fields.");
    }

    // =================================================================
    // 4. Start Transaction
    // =================================================================
    $conn->beginTransaction();

    // (A) Query Order Information
    $orderQuery = "SELECT Orders_Buyer_ID, Orders_Seller_ID, Orders_Total_Amount, Orders_Status, Address_ID FROM Orders WHERE Orders_Order_ID = ?";
    $stmt = $conn->prepare($orderQuery);
    $stmt->execute([$order_id]);
    $orderData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$orderData) {
        throw new Exception("Order #{$order_id} not found.");
    }

    if ($orderData['Orders_Buyer_ID'] != $current_user_id) {
        throw new Exception("Permission denied: You are not the buyer.");
    }
    if ($amount > floatval($orderData['Orders_Total_Amount'])) {
        throw new Exception("Refund amount exceeds order total.");
    }

    // (B) Check if Refund Request Exists (Limit attempts)
    $checkDup = "SELECT Refund_ID, Refund_Status, Request_Attempt FROM Refund_Requests WHERE Order_ID = ?";
    $stmtDup = $conn->prepare($checkDup);
    $stmtDup->execute([$order_id]);
    $existingRefund = $stmtDup->fetch(PDO::FETCH_ASSOC);

    if ($existingRefund) {
        // Update Logic (2nd attempt)
        $attempt = isset($existingRefund['Request_Attempt']) ? intval($existingRefund['Request_Attempt']) : 1;

        if ($attempt >= 2) {
            throw new Exception("Refund request limit reached (max 2 attempts). Please proceed to dispute.");
        }

        $updateReqSql = "UPDATE Refund_Requests
                         SET Refund_Type = ?,
                             Refund_Has_Received_Goods = ?, 
                             Refund_Amount = ?,
                             Refund_Reason = ?,
                             Refund_Description = ?,
                             Refund_Status = 'pending_approval',
                             Refund_Updated_At = NOW(),
                             Request_Attempt = Request_Attempt + 1
                         WHERE Refund_ID = ?";

        $stmtUpdate = $conn->prepare($updateReqSql);
        $stmtUpdate->execute([
            $refund_type,
            $has_received, // Update received status
            $amount,
            $reason,
            $description,
            $existingRefund['Refund_ID']
        ]);

        $new_refund_id = $existingRefund['Refund_ID'];

    } else {
        // (C) Insert New Request
        $insertReqSql = "INSERT INTO Refund_Requests (
            Order_ID, Buyer_ID, Seller_ID, Refund_Type, Refund_Has_Received_Goods, 
            Refund_Amount, Refund_Reason, Refund_Description, Refund_Status, Refund_Created_At, Request_Attempt
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending_approval', NOW(), 1)";

        $stmtInsert = $conn->prepare($insertReqSql);
        $stmtInsert->execute([
            $order_id,
            $current_user_id,
            $orderData['Orders_Seller_ID'],
            $refund_type,
            $has_received, // Insert received status
            $amount,
            $reason,
            $description
        ]);

        $new_refund_id = $conn->lastInsertId();
    }

    // Update Main Order Status
    $updateOrderSql = "UPDATE Orders SET Orders_Status = 'After Sales Processing' WHERE Orders_Order_ID = ?";
    $stmtUpdateOrder = $conn->prepare($updateOrderSql);
    $stmtUpdateOrder->execute([$order_id]);

    // =================================================================
    // (D) Handle Dual Image Upload (Support evidence_receipt and evidence_defect)
    // =================================================================

    // Physical Path
    $uploadDir = __DIR__ . '/../uploads/refund_evidence/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0777, true)) {
            throw new Exception("Failed to create upload directory.");
        }
    }

    // Helper Function: Batch Process Images
    function process_evidence_upload($conn, $fileKey, $refundId, $userId, $uploadDir, $category) {
        if (!isset($_FILES[$fileKey]) || empty($_FILES[$fileKey]['name'][0])) {
            return;
        }

        $evidenceSql = "INSERT INTO Refund_Evidence (Refund_ID, Uploader_ID, Evidence_File_Type, Evidence_File_Url, Evidence_Stage, Evidence_Category, Evidence_Created_At) VALUES (?, ?, ?, ?, 'apply', ?, NOW())";
        $stmtEvidence = $conn->prepare($evidenceSql);

        $files = $_FILES[$fileKey];
        $count = count($files['name']);

        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $tmpName = $files['tmp_name'][$i];
                $name = $files['name'][$i];

                $type = strpos($files['type'][$i], 'video') !== false ? 'video' : 'image';
                $ext = pathinfo($name, PATHINFO_EXTENSION);

                // Generate Unique Filename
                $newFileName = 'REFUND_' . $refundId . '_' . uniqid() . '.' . $ext;
                $destination = $uploadDir . $newFileName;

                if (move_uploaded_file($tmpName, $destination)) {
                    // Store Relative Path in Database
                    $dbPath = 'Module_After_Sales_Dispute/uploads/refund_evidence/' . $newFileName;
                    $stmtEvidence->execute([$refundId, $userId, $type, $dbPath, $category]);
                }
            }
        }
    }

    // 1. Process Receipt/Logistics Proof (receipt_proof)
    process_evidence_upload($conn, 'evidence_receipt', $new_refund_id, $current_user_id, $uploadDir, 'receipt_proof');

    // 2. Process Defect/Item Proof (defect_evidence)
    // Note: Prioritize new field name evidence_defect, otherwise try evidence (legacy compatibility)
    if (isset($_FILES['evidence_defect'])) {
        process_evidence_upload($conn, 'evidence_defect', $new_refund_id, $current_user_id, $uploadDir, 'defect_evidence');
    } elseif (isset($_FILES['evidence'])) {
        process_evidence_upload($conn, 'evidence', $new_refund_id, $current_user_id, $uploadDir, 'defect_evidence');
    }

    // =================================================================
    // 5. Commit Transaction
    // =================================================================
    $conn->commit();
    send_json_response(true, 'Refund request submitted successfully!', ['refund_id' => $new_refund_id]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    send_json_response(false, 'Error: ' . $e->getMessage());
}
?>