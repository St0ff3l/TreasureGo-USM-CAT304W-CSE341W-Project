<?php
// Module_After_Sales_Dispute/api/dispute_buyer_submit.php

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// 数据库配置文件路径
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

/**
 * 辅助函数：清理图片路径，确保安全
 */
function normalize_evidence_urls($urls) {
    if (!is_array($urls)) return [];
    $clean = [];
    // 允许的路径片段，防止保存恶意链接
    $validPathNeedle = 'assets/images/evidence_images/';

    foreach ($urls as $u) {
        $u = trim((string)$u);
        if ($u === '') continue;

        // 简单校验：必须包含本模块图片目录，且是图片后缀
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
    // 逻辑分支 A: 上传图片 (处理 Multipart/form-data)
    // ==========================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['evidence'])) {

        // 1. 简单验证订单权限 (可选，但推荐)
        $orderId = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if ($orderId > 0) {
            $stmtCheck = $pdo->prepare("SELECT Orders_Buyer_ID FROM Orders WHERE Orders_Order_ID = ?");
            $stmtCheck->execute([$orderId]);
            $o = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if (!$o || intval($o['Orders_Buyer_ID']) !== $userId) {
                throw new Exception('Permission denied: You do not own this order.');
            }
        }

        // 2. 准备目录
        // 物理路径: api/../assets/images/evidence_images/
        $uploadDir = __DIR__ . '/../assets/images/evidence_images/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0777, true)) throw new Exception('Failed to create upload directory');
        }

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

            // 生成唯一文件名: DISPUTE_BUYER_{OrderId}_{Timestamp}_{Random}.ext
            $safeOrderId = $orderId > 0 ? $orderId : 'TEMP';
            $newFileName = sprintf('DISPUTE_BUYER_%s_%s_%s.%s', $safeOrderId, time(), uniqid(), $ext);
            $destination = $uploadDir . $newFileName;

            if (move_uploaded_file($tmpName, $destination)) {
                // 返回给前端的 Web 路径 (存入数据库的路径)
                $dbPath = 'Module_After_Sales_Dispute/assets/images/evidence_images/' . $newFileName;

                $saved[] = [
                    'url' => $dbPath,
                    'type' => 'image',
                    'original_name' => $origName
                ];
            }
        }

        if (empty($saved)) throw new Exception('No valid images uploaded (check format/size).');

        // 返回成功 JSON
        out(true, 'Uploaded successfully', ['files' => $saved]);
    }

    // ==========================================
    // 逻辑分支 B: 提交申诉 (处理 JSON Payload)
    // ==========================================
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (is_array($data) && isset($data['dispute_reason'])) {

        // 1. 获取参数
        $orderId = intval($data['order_id'] ?? 0);
        $reason = trim($data['dispute_reason'] ?? '');
        $details = trim($data['dispute_details'] ?? '');
        $tracking = trim($data['return_tracking_number'] ?? '');
        $evidenceImgs = $data['evidence_images'] ?? []; // 这是一个 URL 字符串数组

        // 2. 校验
        if ($orderId <= 0) out(false, 'Missing Order ID');
        if (empty($reason)) out(false, 'Missing Reason');
        if (mb_strlen($details) < 10) out(false, 'Details too short (min 10 chars).');

        $pdo->beginTransaction();

        // 3. 锁定订单并验证权限
        $stmtOrder = $pdo->prepare('SELECT Orders_Buyer_ID, Orders_Seller_ID FROM Orders WHERE Orders_Order_ID = ? FOR UPDATE');
        $stmtOrder->execute([$orderId]);
        $orderInfo = $stmtOrder->fetch(PDO::FETCH_ASSOC);

        if (!$orderInfo) throw new Exception('Order not found');
        if (intval($orderInfo['Orders_Buyer_ID']) !== $userId) throw new Exception('Permission denied');

        $sellerId = intval($orderInfo['Orders_Seller_ID']);

        // 4. 获取 Refund_ID (必须先有退款/退货申请才能申诉)
        $stmtRefund = $pdo->prepare('SELECT Refund_ID FROM Refund_Requests WHERE Order_ID = ? LIMIT 1');
        $stmtRefund->execute([$orderId]);
        $refundRow = $stmtRefund->fetch(PDO::FETCH_ASSOC);

        if (!$refundRow) throw new Exception('No refund request found. You must request a return/refund before disputing.');
        $refundId = intval($refundRow['Refund_ID']);

        // 5. 检查是否已有进行中的申诉
        $stmtCheck = $pdo->prepare("SELECT Dispute_ID FROM Dispute WHERE Order_ID = ? AND Dispute_Status NOT IN ('Resolved', 'Closed', 'Cancelled')");
        $stmtCheck->execute([$orderId]);
        if ($stmtCheck->fetch()) throw new Exception('A dispute is already in progress for this order.');

        // 6. 准备数据
        $cleanDetails = $details;
        if (!empty($tracking)) {
            $cleanDetails = "[Tracking: $tracking] " . $cleanDetails;
        }

        // 清理图片路径并转 JSON
        $validEvidence = normalize_evidence_urls($evidenceImgs);
        $evidenceJson = count($validEvidence) > 0 ? json_encode($validEvidence) : '[]';

        // 7. 插入申诉记录
        // 注意：这里使用了 Dispute_Buyer_Evidence 字段
        $sqlInsert = "INSERT INTO Dispute (
            Order_ID, Refund_ID, Reporting_User_ID, Reported_User_ID,
            Dispute_Reason, Dispute_Details, Dispute_Status, 
            Dispute_Buyer_Evidence, Dispute_Creation_Date
        ) VALUES (?, ?, ?, ?, ?, ?, 'Open', ?, NOW())";

        $stmtIns = $pdo->prepare($sqlInsert);
        $stmtIns->execute([
            $orderId, $refundId, $userId, $sellerId,
            $reason, $cleanDetails, $evidenceJson
        ]);

        $newDisputeId = $pdo->lastInsertId();

        // 8. 更新退款表状态
        $stmtUpRefund = $pdo->prepare("UPDATE Refund_Requests SET Refund_Status = 'dispute_in_progress', Refund_Updated_At = NOW() WHERE Refund_ID = ?");
        $stmtUpRefund->execute([$refundId]);

        $pdo->commit();
        out(true, 'Dispute submitted successfully.', ['dispute_id' => $newDisputeId]);
    }

    // 如果既不是上传也不是提交 JSON
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