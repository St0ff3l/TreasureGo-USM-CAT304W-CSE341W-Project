<?php
// =================================================================
// 1. 初始化设置：禁止 HTML 报错，确保只输出 JSON
// =================================================================
ini_set('display_errors', 0); // 关闭页面错误显示
error_reporting(E_ALL);       // 记录所有错误到日志

header('Content-Type: application/json; charset=utf-8');
ob_start(); // 开启缓冲区

session_start();

// 通用响应函数
function send_json_response($success, $message, $data = []) {
    ob_clean(); // 清除缓冲区
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $data));
    exit;
}

try {
    // =================================================================
    // 2. 引入数据库连接 (PDO)
    // =================================================================
    $db_path = __DIR__ . '/config/treasurego_db_config.php';

    if (!file_exists($db_path)) {
        throw new Exception("Config file not found at: " . $db_path);
    }
    require_once $db_path;

    if (!isset($conn) || !$conn) {
        throw new Exception("Database connection failed.");
    }

    // 权限验证
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

    // Refund Type 强校验 (必须匹配数据库 ENUM)
    $raw_type = isset($_POST['refund_type']) ? $_POST['refund_type'] : '';
    $allowed_types = ['refund_only', 'return_refund'];

    if (!in_array($raw_type, $allowed_types)) {
        throw new Exception("Invalid Refund Type: '{$raw_type}'");
    }
    $refund_type = $raw_type;

    $reason = isset($_POST['reason']) ? $_POST['reason'] : '';
    $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0.00;
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';

    if ($order_id <= 0 || empty($reason) || $amount <= 0) {
        throw new Exception("Missing required fields.");
    }

    // =================================================================
    // 4. 数据库操作 (开启事务 - 关键步骤)
    // =================================================================
    $conn->beginTransaction();

    // (A) 查询订单信息 (确保订单存在且归属正确)
    $orderQuery = "SELECT Orders_Buyer_ID, Orders_Seller_ID, Orders_Total_Amount, Orders_Status FROM Orders WHERE Orders_Order_ID = ?";
    $stmt = $conn->prepare($orderQuery);
    $stmt->execute([$order_id]);
    $orderData = $stmt->fetch();

    if (!$orderData) {
        throw new Exception("Order #{$order_id} not found.");
    }

    // 权限与金额检查
    if ($orderData['Orders_Buyer_ID'] != $current_user_id) {
        throw new Exception("Permission denied: You are not the buyer.");
    }
    if ($amount > floatval($orderData['Orders_Total_Amount'])) {
        throw new Exception("Refund amount exceeds order total.");
    }

    // (B) 检查是否已有退款申请
    $checkDup = "SELECT Refund_ID, Refund_Status, Request_Attempt FROM Refund_Requests WHERE Order_ID = ?";
    $stmtDup = $conn->prepare($checkDup);
    $stmtDup->execute([$order_id]);
    $existingRefund = $stmtDup->fetch(PDO::FETCH_ASSOC);

    // ✅ 新规则：同一订单允许最多提交两次。
    // - 第一次：INSERT
    // - 第二次：UPDATE 现有记录，Request_Attempt + 1，并把状态重置为 pending_approval
    // - 第三次：拒绝
    if ($existingRefund) {
        // 如果数据库还没有 Request_Attempt 字段，这里会是 null。
        // 为了不让旧库直接崩溃，我们按“旧逻辑”处理。
        if (!array_key_exists('Request_Attempt', $existingRefund) || $existingRefund['Request_Attempt'] === null) {
            throw new Exception("A refund request already exists for this order. (DB not patched for multi-attempt)");
        }

        $attempt = intval($existingRefund['Request_Attempt']);
        if ($attempt >= 2) {
            throw new Exception("Refund request limit reached (max 2 attempts). Please proceed to dispute.");
        }

        // 第二次提交：更新原记录
        $updateReqSql = "UPDATE Refund_Requests
                         SET Refund_Type = ?,
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
            $amount,
            $reason,
            $description,
            $existingRefund['Refund_ID']
        ]);

        $new_refund_id = $existingRefund['Refund_ID'];

    } else {
        // (C) 插入主表 Refund_Requests
        $insertReqSql = "INSERT INTO Refund_Requests (
            Order_ID, Buyer_ID, Seller_ID, Refund_Type, Refund_Has_Received_Goods, 
            Refund_Amount, Refund_Reason, Refund_Description, Refund_Status, Refund_Created_At, Request_Attempt
        ) VALUES (?, ?, ?, ?, 1, ?, ?, ?, 'pending_approval', NOW(), 1)";

        $stmtInsert = $conn->prepare($insertReqSql);
        $stmtInsert->execute([
            $order_id,
            $current_user_id,
            $orderData['Orders_Seller_ID'],
            $refund_type,
            $amount,
            $reason,
            $description
        ]);

        // 获取刚插入的 Refund_ID
        $new_refund_id = $conn->lastInsertId();
    }

    // =================================================================
    // 🔥🔥🔥 核心修改：同步更新 Orders 表状态 🔥🔥🔥
    // =================================================================
    // 你的 Orders_Status 是 varchar(20)，'pending_approval' 长度为 16，完全可以存入。
    $updateOrderSql = "UPDATE Orders SET Orders_Status = 'After Sales Processing' WHERE Orders_Order_ID = ?";
    $stmtUpdateOrder = $conn->prepare($updateOrderSql);

    // 执行更新
    if (!$stmtUpdateOrder->execute([$order_id])) {
        throw new Exception("Failed to update Order Status in Orders table.");
    }

    // (D) 处理图片上传
    if (isset($_FILES['evidence']) && !empty($_FILES['evidence']['name'][0])) {
        // 物理路径：api/../uploads/refund_evidence/
        $uploadDir = __DIR__ . '/../uploads/refund_evidence/';

        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0777, true)) {
                throw new Exception("Failed to create upload directory.");
            }
        }

        $evidenceSql = "INSERT INTO Refund_Evidence (Refund_ID, Uploader_ID, Evidence_File_Type, Evidence_File_Url, Evidence_Stage, Evidence_Created_At) VALUES (?, ?, ?, ?, 'apply', NOW())";
        $stmtEvidence = $conn->prepare($evidenceSql);

        $files = $_FILES['evidence'];
        $count = count($files['name']);

        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $tmpName = $files['tmp_name'][$i];
                $name = $files['name'][$i];

                $type = strpos($files['type'][$i], 'video') !== false ? 'video' : 'image';
                $ext = pathinfo($name, PATHINFO_EXTENSION);

                $newFileName = 'REFUND_' . $new_refund_id . '_' . uniqid() . '.' . $ext;
                $destination = $uploadDir . $newFileName;

                if (move_uploaded_file($tmpName, $destination)) {
                    // 数据库存相对路径
                    $dbPath = 'Module_After_Sales_Dispute/uploads/refund_evidence/' . $newFileName;
                    $stmtEvidence->execute([$new_refund_id, $current_user_id, $type, $dbPath]);
                }
            }
        }
    }

    // =================================================================
    // 5. 提交事务
    // =================================================================
    $conn->commit();
    send_json_response(true, 'Refund request submitted successfully!', ['refund_id' => $new_refund_id]);

} catch (Exception $e) {
    // 发生错误时回滚
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    send_json_response(false, 'Error: ' . $e->getMessage());
}
?>