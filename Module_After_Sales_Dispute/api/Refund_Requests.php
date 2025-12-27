<?php
// =================================================================
// 1. 初始化设置
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
    // 2. 数据库连接
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
    // 3. 数据校验与准备
    // =================================================================
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

    $raw_type = isset($_POST['refund_type']) ? $_POST['refund_type'] : '';
    $allowed_types = ['refund_only', 'return_refund']; // 后端实际存储值为 return_refund 对应前端 return_refund

    // 前端可能传的是 refund_only 或 return_refund
    // 数据库枚举通常是 'refund_only', 'return_refund'
    if (!in_array($raw_type, $allowed_types)) {
        throw new Exception("Invalid Refund Type: '{$raw_type}'");
    }
    $refund_type = $raw_type;

    // 🔥🔥🔥 新增：是否收到货状态接收 (0=No, 1=Yes)
    $has_received = isset($_POST['has_received']) ? intval($_POST['has_received']) : 0;

    $reason = isset($_POST['reason']) ? $_POST['reason'] : '';
    $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0.00;
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';

    if ($order_id <= 0 || empty($reason) || $amount <= 0) {
        throw new Exception("Missing required fields.");
    }

    // =================================================================
    // 4. 开启事务
    // =================================================================
    $conn->beginTransaction();

    // (A) 查询订单信息
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

    // (B) 检查是否已有退款申请 (限制尝试次数)
    $checkDup = "SELECT Refund_ID, Refund_Status, Request_Attempt FROM Refund_Requests WHERE Order_ID = ?";
    $stmtDup = $conn->prepare($checkDup);
    $stmtDup->execute([$order_id]);
    $existingRefund = $stmtDup->fetch(PDO::FETCH_ASSOC);

    if ($existingRefund) {
        // 更新逻辑 (第2次申请)
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
            $has_received, // 🔥 更新收到货状态
            $amount,
            $reason,
            $description,
            $existingRefund['Refund_ID']
        ]);

        $new_refund_id = $existingRefund['Refund_ID'];

    } else {
        // (C) 插入新申请
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
            $has_received, // 🔥 插入收到货状态
            $amount,
            $reason,
            $description
        ]);

        $new_refund_id = $conn->lastInsertId();
    }

    // 更新主订单状态
    $updateOrderSql = "UPDATE Orders SET Orders_Status = 'After Sales Processing' WHERE Orders_Order_ID = ?";
    $stmtUpdateOrder = $conn->prepare($updateOrderSql);
    $stmtUpdateOrder->execute([$order_id]);

    // =================================================================
    // (D) 处理双图片上传 (支持 evidence_receipt 和 evidence_defect)
    // =================================================================

    // 物理路径
    $uploadDir = __DIR__ . '/../uploads/refund_evidence/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0777, true)) {
            throw new Exception("Failed to create upload directory.");
        }
    }

    // 辅助函数：批量处理图片
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

                // 生成唯一文件名
                $newFileName = 'REFUND_' . $refundId . '_' . uniqid() . '.' . $ext;
                $destination = $uploadDir . $newFileName;

                if (move_uploaded_file($tmpName, $destination)) {
                    // 数据库存相对路径
                    $dbPath = 'Module_After_Sales_Dispute/uploads/refund_evidence/' . $newFileName;
                    $stmtEvidence->execute([$refundId, $userId, $type, $dbPath, $category]);
                }
            }
        }
    }

    // 1. 处理收货/物流证明 (receipt_proof)
    process_evidence_upload($conn, 'evidence_receipt', $new_refund_id, $current_user_id, $uploadDir, 'receipt_proof');

    // 2. 处理缺陷/实物证明 (defect_evidence)
    // 注意：之前旧代码可能用 'evidence'，为了兼容你可以保留 'evidence' 的判断，或者全改为 'evidence_defect'
    // 这里优先处理新字段名 evidence_defect，如果没有则尝试 evidence (旧版兼容)
    if (isset($_FILES['evidence_defect'])) {
        process_evidence_upload($conn, 'evidence_defect', $new_refund_id, $current_user_id, $uploadDir, 'defect_evidence');
    } elseif (isset($_FILES['evidence'])) {
        process_evidence_upload($conn, 'evidence', $new_refund_id, $current_user_id, $uploadDir, 'defect_evidence');
    }

    // =================================================================
    // 5. 提交事务
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