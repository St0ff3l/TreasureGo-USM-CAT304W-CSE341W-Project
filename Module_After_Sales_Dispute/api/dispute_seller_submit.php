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

// 辅助函数：清理图片链接
function normalize_evidence_urls($urls) {
    if (!is_array($urls)) return [];
    $clean = [];
    // 必须包含的前缀，防止恶意链接
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
    // 逻辑分支 A: 上传图片 (处理 Multipart/form-data)
    // ==========================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['evidence'])) {
        $orderId = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

        // 1. 简单权限验证
        if ($orderId > 0) {
            $stmtCheck = $pdo->prepare("SELECT Orders_Seller_ID FROM Orders WHERE Orders_Order_ID = ?");
            $stmtCheck->execute([$orderId]);
            $o = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if (!$o || intval($o['Orders_Seller_ID']) !== $userId) {
                throw new Exception('Permission denied: You do not own this order.');
            }
        }

        // 2. 准备目录
        // 物理路径
        $uploadDir = __DIR__ . '/../assets/images/evidence_images/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $files = $_FILES['evidence'];
        // 处理单文件和多文件上传的兼容性
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

            // 生成唯一文件名: DISPUTE_SELLER_{OrderId}_{Time}_{Random}.ext
            $safeOrderId = $orderId > 0 ? $orderId : 'TEMP';
            $newFileName = sprintf('DISPUTE_SELLER_%s_%s_%s.%s', $safeOrderId, time(), uniqid(), $ext);
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

        if (empty($saved)) throw new Exception('No valid images uploaded.');

        // 🔥 返回成功 JSON，包含 files 数组供前端 map 使用
        out(true, 'Uploaded successfully', ['files' => $saved]);
    }

    // ==========================================
    // 逻辑分支 B: 提交数据 (修正版 - 修复 1364 错误)
    // ==========================================
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (is_array($data)) {
        $orderId = intval($data['order_id'] ?? 0);
        $content = trim($data['seller_response'] ?? $data['dispute_details'] ?? '');
        $evidenceImgs = $data['evidence_images'] ?? [];

        // 🔥 1. 接收前端传来的新参数
        $reasonCode = trim($data['reason_code'] ?? 'Seller_Refused_Return');
        $receivedStatus = isset($data['received_status']) ? intval($data['received_status']) : null;

        // 构造 Dispute_Reason (大标题) 和 Dispute_Details (具体原因代码)
        $disputeReasonTitle = 'Seller Dispute';
        // 这里的 reasonCode 就是导致报错的那个必填项，比如 'fake_tracking'
        $disputeDetails = $reasonCode;

        // 如果想把收到货的状态也记录进描述里：
        if ($receivedStatus !== null) {
            $statusText = $receivedStatus === 1 ? "[Item Received]" : "[Item Not Received]";
            $content = $statusText . " " . $content;
        }

        if ($orderId <= 0) out(false, 'Missing Order ID');

        // 验证 Seller 权限
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

        // 检查主表 Dispute 是否存在
        $stmtCheck = $pdo->prepare("SELECT Dispute_ID, Action_Required_By, Dispute_Seller_Evidence FROM Dispute WHERE Order_ID = ? AND Dispute_Status NOT IN ('Resolved', 'Closed', 'Cancelled')");
        $stmtCheck->execute([$orderId]);
        $existingDispute = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        $evidenceJson = json_encode(normalize_evidence_urls($evidenceImgs));

        if ($existingDispute) {
            // --- 情况 1: 争议已存在 (追加) ---
            $disputeId = $existingDispute['Dispute_ID'];

            // 1. 插入补充记录
            $sqlSup = "INSERT INTO Dispute_Supplement_Record 
                      (Dispute_ID, User_ID, User_Role, Content, Evidence_Images, Record_Type, Created_At)
                      VALUES (?, ?, 'Seller', ?, ?, 'Evidence', NOW())";
            $pdo->prepare($sqlSup)->execute([$disputeId, $userId, $content, $evidenceJson]);
// 🔥 [修复开始] 检查当前状态，如果是 Both，则改为 Buyer (等待买家)，否则改为 Admin
            $currentAction = $existingDispute['Action_Required_By'];
            $newAction = 'Admin';
            if ($currentAction === 'Both') {
                $newAction = 'Buyer'; // 卖家交完了，现在轮到买家
            }

// 2. 更新主表状态
            $sqlUp = "UPDATE Dispute SET 
            Action_Required_By = ?,  /* 👈 这里的 'Admin' 改为占位符 ? */
            Dispute_Status = CASE WHEN Dispute_Status = 'Pending Info' THEN 'In Review' ELSE Dispute_Status END,
            Seller_Description = COALESCE(NULLIF(Seller_Description, ''), ?),
            Dispute_Seller_Evidence = COALESCE(NULLIF(Dispute_Seller_Evidence, '[]'), ?)
          WHERE Dispute_ID = ?";

// 🔥 注意 execute 参数里多了一个 $newAction
            $pdo->prepare($sqlUp)->execute([$newAction, $content, $evidenceJson, $disputeId]);
// 🔥 [修复结束]

            $pdo->commit();
            out(true, 'Seller evidence added.', ['dispute_id' => $disputeId]);

        } else {
            // --- 情况 2: 卖家创建新争议 (修复这里) ---
            if (empty($content) && empty($evidenceImgs)) {
                throw new Exception('Please provide details or evidence.');
            }

            // 🔥 修改 SQL：必须包含 Dispute_Details
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
                $disputeReasonTitle, // Dispute_Reason (标题)
                $disputeDetails,     // 🔥 Dispute_Details (必填的具体原因代码)
                $content,
                $evidenceJson
            ]);
            $newDisputeId = $pdo->lastInsertId();

            // 同时也插入一条 Supplement 记录
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