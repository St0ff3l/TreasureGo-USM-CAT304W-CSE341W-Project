<?php
// Module_After_Sales_Dispute/api/dispute_buyer_submit.php
// 1. Upload Evidence (Multipart)
// 2. Submit Dispute (JSON) => insert Dispute row + images + set Refund_Status

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

/**
 * 验证并清理图片路径数组，只保留本模块目录下的图片
 */
function normalize_evidence_urls($urls) {
    if (!is_array($urls)) return [];
    $clean = [];

    // --- 原来的严格检查 (注释掉) ---
    // $targetPrefix = 'Module_After_Sales_Dispute/assets/images/evidence_images/';

    foreach ($urls as $u) {
        $u = trim((string)$u);
        if ($u === '') continue;

        // --- 新的宽松检查 ---
        // 只要包含 'evidence_images' 或者是图片扩展名，就允许
        // 这样可以避免因为路径前缀写错导致存不进去
        if (strpos($u, 'evidence_images') !== false || preg_match('/\.(jpg|jpeg|png|webp)$/i', $u)) {
            $clean[] = $u;
        }
    }
    return array_values(array_unique($clean));
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception('Database connection failed');

    // ==========================================
    // 逻辑分支 A: 上传图片 (Multipart/form-data)
    // ==========================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['evidence'])) {
        $uploadDir = __DIR__ . '/../assets/images/evidence_images/';

        // 如果目录不存在则创建
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0777, true)) throw new Exception('Failed to create upload dir');
        }

        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $files = $_FILES['evidence'];
        $count = is_array($files['name']) ? count($files['name']) : 0;
        $saved = [];

        for ($i = 0; $i < $count; $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

            $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) continue;

            // 生成唯一文件名: BUYER_EVIDENCE_用户ID_随机串.jpg
            $newNa = 'BUYER_EVIDENCE_' . $userId . '_' . uniqid() . '.' . $ext;

            if (move_uploaded_file($files['tmp_name'][$i], $uploadDir . $newNa)) {
                // 返回相对路径给前端
                $saved[] = [
                    'url' => 'Module_After_Sales_Dispute/assets/images/evidence_images/' . $newNa,
                    'type' => 'image',
                    'original_name' => $files['name'][$i]
                ];
            }
        }

        if (empty($saved)) out(false, 'No valid images uploaded (check format or size).');
        out(true, 'Uploaded', ['files' => $saved]);
    }

    // ==========================================
    // 逻辑分支 B: 提交申诉 (JSON Payload)
    // ==========================================
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    // 如果不是上传图片，且 JSON 为空，则报错
    if (!is_array($data)) {
        // 如果前面没处理上传，也没发JSON，那就是错误请求
        if (!isset($_FILES['evidence'])) {
            http_response_code(400);
            out(false, 'Invalid request format.');
        } else {
            exit; // 上传逻辑已结束
        }
    }

    // 解析 JSON 参数
    $orderId = intval($data['order_id'] ?? 0);
    $disputeReason = trim($data['dispute_reason'] ?? '');
    $disputeDetails = trim($data['dispute_details'] ?? '');
    $disputeType = trim($data['dispute_type'] ?? '');
    $returnTracking = trim($data['return_tracking_number'] ?? '');
    $evidenceImages = $data['evidence_images'] ?? []; // 接收前端传回的图片路径数组

    // 基础验证
    if ($orderId <= 0) out(false, 'Missing order_id');
    if ($disputeReason === '') out(false, 'Missing dispute_reason');
    if (mb_strlen($disputeDetails) < 20) out(false, 'Please provide more details (at least 20 characters).');

    // 验证 dispute_type
    $allowedTypes = ['rejected_return', 'refused_return_received', 'other'];
    if ($disputeType !== '' && !in_array($disputeType, $allowedTypes, true)) {
        // 兼容旧代码逻辑，如果不严格校验可以注释掉
        // out(false, 'Invalid dispute_type');
    }
    if ($disputeType === 'refused_return_received' && $returnTracking === '') {
        out(false, 'Return tracking number is required.');
    }

    $pdo->beginTransaction();

    // 1) 验证订单归属 & 获取卖家ID
    $stmt = $pdo->prepare('SELECT Orders_Buyer_ID, Orders_Seller_ID FROM Orders WHERE Orders_Order_ID = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) throw new Exception('Order not found');
    if (intval($order['Orders_Buyer_ID']) !== $userId) throw new Exception('Permission denied: buyer only');

    $sellerId = intval($order['Orders_Seller_ID']);

    // 2) 查找关联的退款申请
    $stmtR = $pdo->prepare('SELECT Refund_ID FROM Refund_Requests WHERE Order_ID = ? FOR UPDATE');
    $stmtR->execute([$orderId]);
    $refund = $stmtR->fetch(PDO::FETCH_ASSOC);

    if (!$refund) throw new Exception('Refund request not found for this order. Start a return/refund first.');
    $refundId = intval($refund['Refund_ID']);

    // 3) 防止重复提交
    $stmtD = $pdo->prepare("SELECT Dispute_ID FROM Dispute WHERE Order_ID = ? AND Dispute_Status NOT IN ('Closed', 'Resolved') LIMIT 1");
    $stmtD->execute([$orderId]);
    $existing = $stmtD->fetch(PDO::FETCH_ASSOC);
    if ($existing) throw new Exception('An active dispute already exists for this order.');

    // 4) 插入数据
    $detailsFull = $disputeDetails;
    if ($disputeType === 'refused_return_received' || !empty($returnTracking)) {
        $detailsFull = "[Return Tracking: {$returnTracking}]\n\n" . $detailsFull;
    }

    // 处理图片路径，转为 JSON
    $cleanEvidence = normalize_evidence_urls($evidenceImages);
    $evidenceJson = !empty($cleanEvidence) ? json_encode($cleanEvidence) : NULL;

    // --- 修改点：SQL 增加了 Dispute_Buyer_Evidence 字段 ---
    $sqlIns = 'INSERT INTO Dispute
        (Dispute_Reason, Dispute_Details, Dispute_Status, Dispute_Creation_Date,
         Reporting_User_ID, Reported_User_ID, Order_ID, Refund_ID, Dispute_Buyer_Evidence)
        VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?)';

    $stmtIns = $pdo->prepare($sqlIns);
    $stmtIns->execute([
        $disputeReason,
        $detailsFull,
        'Open',
        $userId,
        $sellerId,
        $orderId,
        $refundId,
        $evidenceJson // 存入 JSON
    ]);

    $disputeId = $pdo->lastInsertId();

    // 5) 更新退款状态为 dispute_in_progress
    $stmtUp = $pdo->prepare("UPDATE Refund_Requests SET Refund_Status = 'dispute_in_progress', Refund_Updated_At = NOW() WHERE Refund_ID = ?");
    $stmtUp->execute([$refundId]);

    $pdo->commit();
    out(true, 'Dispute submitted successfully.', [
        'dispute_id' => (int)$disputeId,
        'refund_id' => $refundId
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    out(false, $e->getMessage());
}
?>