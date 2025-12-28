<?php
// Module_After_Sales_Dispute/api/dispute_buyer_submit.php

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../Module_Transaction_Fund/api/config/treasurego_db_config.php';

session_start();

function out($success, $message, $extra = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    out(false, 'Unauthorized: Please login first.');
}

$userId = intval($_SESSION['user_id']);

function normalize_evidence_urls($urls) {
    if (!is_array($urls)) return [];
    $clean = [];
    $validPathNeedle = 'assets/images/evidence_images/';
    foreach ($urls as $u) {
        $u = trim((string)$u);
        if ($u === '') continue;
        if (strpos($u, $validPathNeedle) !== false && preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $u)) {
            $clean[] = $u;
        }
    }
    return array_values(array_unique($clean));
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception('Database connection failed');

    // ==========================================
    // 逻辑分支 A: 上传图片 (不变)
    // ==========================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['evidence'])) {
        $orderId = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

        // 1. 简单验证
        if ($orderId > 0) {
            $stmtCheck = $pdo->prepare("SELECT Orders_Buyer_ID FROM Orders WHERE Orders_Order_ID = ?");
            $stmtCheck->execute([$orderId]);
            $o = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if (!$o || intval($o['Orders_Buyer_ID']) !== $userId) {
                throw new Exception('Permission denied: You do not own this order.');
            }
        }

        $uploadDir = __DIR__ . '/../assets/images/evidence_images/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $files = $_FILES['evidence'];
        $count = is_array($files['name']) ? count($files['name']) : 0;
        $saved = [];

        for ($i = 0; $i < $count; $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            $tmpName = $files['tmp_name'][$i];
            $origName = $files['name'][$i];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) continue;

            $safeOrderId = $orderId > 0 ? $orderId : 'TEMP';
            $newFileName = sprintf('DISPUTE_BUYER_%s_%s_%s.%s', $safeOrderId, time(), uniqid(), $ext);
            $destination = $uploadDir . $newFileName;

            if (move_uploaded_file($tmpName, $destination)) {
                $dbPath = 'Module_After_Sales_Dispute/assets/images/evidence_images/' . $newFileName;
                $saved[] = ['url' => $dbPath, 'type' => 'image', 'original_name' => $origName];
            }
        }
        if (empty($saved)) throw new Exception('No valid images uploaded.');
        out(true, 'Uploaded successfully', ['files' => $saved]);
    }

    // ==========================================
    // 逻辑分支 B: 提交申诉
    // ==========================================
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (is_array($data) && (isset($data['dispute_reason']) || isset($data['dispute_details']))) {

        $orderId = intval($data['order_id'] ?? 0);
        $reason = trim($data['dispute_reason'] ?? '');
        $details = trim($data['dispute_details'] ?? '');
        $tracking = trim($data['return_tracking_number'] ?? '');
        $evidenceImgs = $data['evidence_images'] ?? [];

        if ($orderId <= 0) out(false, 'Missing Order ID');
        if (empty($details) && empty($evidenceImgs) && empty($reason)) {
            out(false, 'Please provide details or evidence.');
        }

        $pdo->beginTransaction();

        $stmtOrder = $pdo->prepare('SELECT Orders_Buyer_ID, Orders_Seller_ID FROM Orders WHERE Orders_Order_ID = ? FOR UPDATE');
        $stmtOrder->execute([$orderId]);
        $orderInfo = $stmtOrder->fetch(PDO::FETCH_ASSOC);

        if (!$orderInfo) throw new Exception('Order not found');
        if (intval($orderInfo['Orders_Buyer_ID']) !== $userId) throw new Exception('Permission denied: Not the buyer.');

        $sellerId = intval($orderInfo['Orders_Seller_ID']);

        $stmtRefund = $pdo->prepare('SELECT Refund_ID FROM Refund_Requests WHERE Order_ID = ? LIMIT 1');
        $stmtRefund->execute([$orderId]);
        $refundRow = $stmtRefund->fetch(PDO::FETCH_ASSOC);
        if (!$refundRow) throw new Exception('No refund request found.');
        $refundId = intval($refundRow['Refund_ID']);

        // 检查是否存在争议
        $stmtCheck = $pdo->prepare("SELECT Dispute_ID, Action_Required_By FROM Dispute WHERE Order_ID = ? AND Dispute_Status NOT IN ('Resolved', 'Closed', 'Cancelled')");
        $stmtCheck->execute([$orderId]);
        $existingDispute = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        $cleanDetails = $details;
        if (!empty($tracking)) {
            $cleanDetails = "[Tracking: $tracking] " . $cleanDetails;
        }
        $evidenceJson = json_encode(normalize_evidence_urls($evidenceImgs));

        if ($existingDispute) {
            // ========================================================
            // 场景 A: 争议已存在 -> 追加证据 (Supplement)
            // ========================================================
            $disputeId = $existingDispute['Dispute_ID'];

            // 1. 插入子表记录
            $sqlSup = "INSERT INTO Dispute_Supplement_Record 
                      (Dispute_ID, User_ID, User_Role, Content, Evidence_Images, Record_Type, Created_At)
                      VALUES (?, ?, 'Buyer', ?, ?, 'Evidence', NOW())";
            $pdo->prepare($sqlSup)->execute([$disputeId, $userId, $cleanDetails, $evidenceJson]);

            // 2. 更新状态流转 & 尝试填充主表买家字段
            $currentAction = $existingDispute['Action_Required_By'];
            $newAction = 'Admin';
            if ($currentAction === 'Both') $newAction = 'Seller';
            else if ($currentAction === 'Buyer') $newAction = 'Admin';

            // 🔥 核心修改：使用 COALESCE(NULLIF(..., ''), ?)
            // 如果主表字段是 NULL 或 空字符串，则填入当前内容；否则保持原样（不覆盖初始记录）。
            // 证据字段如果之前是 '[]' 也视为无效，尝试填入新的。

            $sqlUp = "UPDATE Dispute SET 
                        Action_Required_By = ?, 
                        Dispute_Status = CASE WHEN Dispute_Status = 'Pending Info' THEN 'In Review' ELSE Dispute_Status END,
                        Buyer_Description = COALESCE(NULLIF(Buyer_Description, ''), ?),
                        Dispute_Buyer_Evidence = COALESCE(NULLIF(Dispute_Buyer_Evidence, '[]'), ?)
                      WHERE Dispute_ID = ?";

            $pdo->prepare($sqlUp)->execute([$newAction, $cleanDetails, $evidenceJson, $disputeId]);

            $pdo->commit();
            out(true, 'Additional evidence submitted.', ['dispute_id' => $disputeId]);

        } else {
            // ========================================================
            // 场景 B: 首次提交争议 (Create)
            // ========================================================
            if (empty($reason)) out(false, 'Missing Dispute Reason');

            // 🔥 核心修改：写入 Buyer_Description
            $sqlInsert = "INSERT INTO Dispute (
                Order_ID, Refund_ID, Reporting_User_ID, Reported_User_ID,
                Dispute_Reason, Dispute_Status, Dispute_Creation_Date, Action_Required_By,
                Buyer_Description, Dispute_Buyer_Evidence
            ) VALUES (?, ?, ?, ?, ?, 'Open', NOW(), 'Admin', ?, ?)";

            $stmtIns = $pdo->prepare($sqlInsert);
            $stmtIns->execute([
                $orderId, $refundId, $userId, $sellerId,
                $reason,
                $cleanDetails, $evidenceJson
            ]);
            $newDisputeId = $pdo->lastInsertId();

            // 同步插入第一条记录
            $sqlSup = "INSERT INTO Dispute_Supplement_Record 
                      (Dispute_ID, User_ID, User_Role, Content, Evidence_Images, Record_Type, Created_At)
                      VALUES (?, ?, 'Buyer', ?, ?, 'Evidence', NOW())";
            $pdo->prepare($sqlSup)->execute([$newDisputeId, $userId, $cleanDetails, $evidenceJson]);

            $pdo->prepare("UPDATE Refund_Requests SET Refund_Status = 'dispute_in_progress', Refund_Updated_At = NOW() WHERE Refund_ID = ?")->execute([$refundId]);

            $pdo->commit();
            out(true, 'Dispute submitted successfully.', ['dispute_id' => $newDisputeId]);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(400);
        out(false, 'Invalid request parameters.');
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    out(false, $e->getMessage());
}
?>