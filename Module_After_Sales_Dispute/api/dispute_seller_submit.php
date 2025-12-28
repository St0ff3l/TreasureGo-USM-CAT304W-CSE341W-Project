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

function normalize_evidence_urls($urls) {
    if (!is_array($urls)) return [];
    $clean = [];
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
    // 逻辑分支 A: 上传图片
    // ==========================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['evidence'])) {
        // ... (省略具体的上传代码实现，请保持你原文件中的 Branch A 代码逻辑) ...
        // ...
        // 简单示意：
        out(true, 'Images uploaded (simplified)', []);
    }

    // ==========================================
    // 逻辑分支 B: 提交数据
    // ==========================================
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (is_array($data)) {
        $orderId = intval($data['order_id'] ?? 0);
        $content = trim($data['seller_response'] ?? $data['dispute_details'] ?? '');
        $evidenceImgs = $data['evidence_images'] ?? [];
        $reason = trim($data['dispute_reason'] ?? 'Seller Initiated Dispute');

        if ($orderId <= 0) out(false, 'Missing Order ID');

        $stmtOrder = $pdo->prepare('SELECT Orders_Buyer_ID, Orders_Seller_ID FROM Orders WHERE Orders_Order_ID = ?');
        $stmtOrder->execute([$orderId]);
        $orderInfo = $stmtOrder->fetch(PDO::FETCH_ASSOC);

        if (!$orderInfo) throw new Exception('Order not found');
        if (intval($orderInfo['Orders_Seller_ID']) !== $userId) throw new Exception('Permission denied: You are not the seller.');

        $buyerId = intval($orderInfo['Orders_Buyer_ID']);

        $stmtRefund = $pdo->prepare('SELECT Refund_ID FROM Refund_Requests WHERE Order_ID = ? LIMIT 1');
        $stmtRefund->execute([$orderId]);
        $refundRow = $stmtRefund->fetch(PDO::FETCH_ASSOC);
        if (!$refundRow) throw new Exception('No refund request context found.');
        $refundId = intval($refundRow['Refund_ID']);

        $pdo->beginTransaction();

        $stmtCheck = $pdo->prepare("SELECT Dispute_ID, Action_Required_By FROM Dispute WHERE Order_ID = ? AND Dispute_Status NOT IN ('Resolved', 'Closed', 'Cancelled')");
        $stmtCheck->execute([$orderId]);
        $existingDispute = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        $evidenceJson = json_encode(normalize_evidence_urls($evidenceImgs));

        if ($existingDispute) {
            // =================================================
            // 情况 1: 争议已存在 (追加记录)
            // =================================================
            $disputeId = $existingDispute['Dispute_ID'];

            // 1. 插入补充记录
            $sqlSup = "INSERT INTO Dispute_Supplement_Record 
                      (Dispute_ID, User_ID, User_Role, Content, Evidence_Images, Record_Type, Created_At)
                      VALUES (?, ?, 'Seller', ?, ?, 'Evidence', NOW())";
            $pdo->prepare($sqlSup)->execute([$disputeId, $userId, $content, $evidenceJson]);

            // 2. 更新主表状态 & 填充 Seller_Description (如果为空)
            // 🔥 核心修改：使用 COALESCE(NULLIF(...)) 确保 Seller_Description 被填充
            $sqlUp = "UPDATE Dispute SET 
                        Action_Required_By = 'Admin', 
                        Dispute_Status = CASE WHEN Dispute_Status = 'Pending Info' THEN 'In Review' ELSE Dispute_Status END,
                        Seller_Description = COALESCE(NULLIF(Seller_Description, ''), ?),
                        Dispute_Seller_Evidence = COALESCE(NULLIF(Dispute_Seller_Evidence, '[]'), ?)
                      WHERE Dispute_ID = ?";

            $pdo->prepare($sqlUp)->execute([$content, $evidenceJson, $disputeId]);

            $pdo->commit();
            out(true, 'Seller evidence added.', ['dispute_id' => $disputeId]);

        } else {
            // =================================================
            // 情况 2: 卖家创建新争议
            // =================================================
            if (empty($content) && empty($evidenceImgs)) {
                throw new Exception('Please provide details or evidence.');
            }

            // 🔥 核心修改：写入 Seller_Description
            $sqlInsert = "INSERT INTO Dispute (
                Order_ID, Refund_ID, Reporting_User_ID, Reported_User_ID,
                Dispute_Reason, Dispute_Status, Dispute_Creation_Date, Action_Required_By,
                Seller_Description, Dispute_Seller_Evidence
            ) VALUES (?, ?, ?, ?, ?, 'Open', NOW(), 'Admin', ?, ?)";

            $stmtIns = $pdo->prepare($sqlInsert);
            $stmtIns->execute([
                $orderId, $refundId, $userId, $buyerId,
                $reason,
                $content, $evidenceJson
            ]);
            $newDisputeId = $pdo->lastInsertId();

            $sqlSup = "INSERT INTO Dispute_Supplement_Record 
                      (Dispute_ID, User_ID, User_Role, Content, Evidence_Images, Record_Type, Created_At)
                      VALUES (?, ?, 'Seller', ?, ?, 'Evidence', NOW())";
            $pdo->prepare($sqlSup)->execute([$newDisputeId, $userId, $content, $evidenceJson]);

            $pdo->prepare("UPDATE Refund_Requests SET Refund_Status = 'dispute_in_progress', Refund_Updated_At = NOW() WHERE Refund_ID = ?")->execute([$refundId]);

            $pdo->commit();
            out(true, 'Dispute opened by seller.', ['dispute_id' => $newDisputeId]);
        }
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    out(false, $e->getMessage());
}
?>