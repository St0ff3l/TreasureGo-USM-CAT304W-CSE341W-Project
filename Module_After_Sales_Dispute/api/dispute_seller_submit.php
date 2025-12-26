<?php
// Module_After_Sales_Dispute/api/dispute_seller_submit.php

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// 请确保此路径正确
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
 * 验证并清理图片路径数组，确保只保留属于本模块本目录的路径
 */
function normalize_evidence_urls($urls) {
    if (!is_array($urls)) return [];

    $clean = [];
    $targetPrefix = 'Module_After_Sales_Dispute/assets/images/evidence_images/';

    foreach ($urls as $u) {
        $u = trim((string)$u);
        if ($u === '') continue;
        // 简单安全检查：必须包含指定前缀
        if (strpos($u, $targetPrefix) === false) continue;
        $clean[] = $u;
    }

    $clean = array_values(array_unique($clean));
    if (count($clean) > 6) $clean = array_slice($clean, 0, 6); // 限制最多6张
    return $clean;
}

function ensure_order_permission(PDO $pdo, $orderId, $userId) {
    $stmtO = $pdo->prepare('SELECT Orders_Buyer_ID, Orders_Seller_ID FROM Orders WHERE Orders_Order_ID = ?');
    $stmtO->execute([$orderId]);
    $order = $stmtO->fetch(PDO::FETCH_ASSOC);
    if (!$order) throw new Exception('Order not found');

    $isBuyer = intval($order['Orders_Buyer_ID']) === intval($userId);
    $isSeller = intval($order['Orders_Seller_ID']) === intval($userId);

    return [$order, $isBuyer, $isSeller];
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception('Database connection failed');

    // ===============================
    // GET: 获取证据列表
    // ===============================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = trim((string)($_GET['action'] ?? ''));
        if ($action !== 'list_evidence') {
            http_response_code(400);
            out(false, 'Invalid action');
        }

        $orderId = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        if ($orderId <= 0) out(false, 'Missing order_id');

        [$order, $isBuyer, $isSeller] = ensure_order_permission($pdo, $orderId, $userId);
        if (!$isBuyer && !$isSeller) throw new Exception('Permission denied');

        // 读取新的 Dispute_Seller_Evidence 字段
        $stmtD = $pdo->prepare('SELECT Dispute_ID, Dispute_Seller_Evidence FROM Dispute WHERE Order_ID = ? LIMIT 1');
        $stmtD->execute([$orderId]);
        $dispute = $stmtD->fetch(PDO::FETCH_ASSOC);
        if (!$dispute) throw new Exception('Dispute not found for this order');

        // 解析 JSON
        $json = $dispute['Dispute_Seller_Evidence'];
        $urls = [];
        if (!empty($json)) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $urls = $decoded;
            }
        }

        out(true, 'OK', [
            'dispute_id' => intval($dispute['Dispute_ID']),
            'order_id' => $orderId,
            'files' => array_map(function($u) {
                return ['url' => $u, 'type' => 'image'];
            }, $urls)
        ]);
    }

    // ===============================
    // POST: 上传图片 (Multipart)
    // ===============================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['evidence'])) {
        $action = trim((string)($_POST['action'] ?? 'upload_evidence'));
        if ($action !== 'upload_evidence') {
            http_response_code(400);
            out(false, 'Invalid action');
        }

        $orderId = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if ($orderId <= 0) out(false, 'Missing order_id');

        [$order, $isBuyer, $isSeller] = ensure_order_permission($pdo, $orderId, $userId);
        if (!$isSeller) throw new Exception('Permission denied: seller only');

        $stmtD = $pdo->prepare('SELECT Dispute_ID FROM Dispute WHERE Order_ID = ? LIMIT 1');
        $stmtD->execute([$orderId]);
        $dispute = $stmtD->fetch(PDO::FETCH_ASSOC);
        if (!$dispute) throw new Exception('Dispute not found for this order');

        // --- 核心修改：新路径 ---
        // 物理路径：api/../assets/images/evidence_images/
        $uploadDir = __DIR__ . '/../assets/images/evidence_images/';

        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0777, true)) {
                throw new Exception('Failed to create upload directory');
            }
        }

        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
        $maxFiles = 6;
        $files = $_FILES['evidence'];
        $count = is_array($files['name']) ? count($files['name']) : 0;

        if ($count <= 0) throw new Exception('No files uploaded');
        if ($count > $maxFiles) throw new Exception('Too many files (max 6)');

        $saved = [];

        for ($i = 0; $i < $count; $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

            $tmpName = $files['tmp_name'][$i];
            $origName = $files['name'][$i];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, $allowedExts, true)) throw new Exception('Invalid file type');

            // 文件名保持唯一
            $newFileName = 'DISPUTE_' . intval($dispute['Dispute_ID']) . '_' . uniqid() . '.' . $ext;
            $destination = $uploadDir . $newFileName;

            if (!move_uploaded_file($tmpName, $destination)) {
                throw new Exception('Failed to save uploaded file');
            }

            // 存入数据库的相对路径（Web路径）
            $dbPath = 'Module_After_Sales_Dispute/assets/images/evidence_images/' . $newFileName;

            $saved[] = [
                'url' => $dbPath,
                'type' => 'image',
                'original_name' => $origName,
            ];
        }

        if (count($saved) === 0) throw new Exception('No valid files uploaded');
        out(true, 'Uploaded', ['files' => $saved]);
    }

    // ===============================
    // POST: 提交申诉 (JSON)
    // ===============================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_FILES['evidence'])) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            http_response_code(400);
            out(false, 'Invalid JSON payload.');
        }

        $orderId = intval($data['order_id'] ?? 0);
        $responseText = trim((string)($data['seller_response'] ?? ''));
        $evidenceImages = $data['evidence_images'] ?? [];

        // 清理图片路径
        $cleanEvidence = normalize_evidence_urls($evidenceImages);

        if ($orderId <= 0) out(false, 'Missing order_id');
        if (mb_strlen($responseText) < 20) out(false, 'Please provide more details (at least 20 characters).');

        $pdo->beginTransaction();

        $stmtO = $pdo->prepare('SELECT Orders_Seller_ID FROM Orders WHERE Orders_Order_ID = ? FOR UPDATE');
        $stmtO->execute([$orderId]);
        $order = $stmtO->fetch(PDO::FETCH_ASSOC);
        if (!$order || intval($order['Orders_Seller_ID']) !== $userId) throw new Exception('Permission denied');

        $stmtD = $pdo->prepare('SELECT Dispute_ID, Dispute_Status, Dispute_Seller_Response FROM Dispute WHERE Order_ID = ? FOR UPDATE');
        $stmtD->execute([$orderId]);
        $dispute = $stmtD->fetch(PDO::FETCH_ASSOC);
        if (!$dispute) throw new Exception('Dispute not found');

        if (!empty($dispute['Dispute_Seller_Response'])) {
            throw new Exception('Seller response already submitted');
        }

        // --- 核心修改：分开存储 ---
        // 将图片数组转为 JSON 字符串
        $evidenceJson = json_encode($cleanEvidence); // 如果为空就是 "[]"

        $stmtUp = $pdo->prepare('UPDATE Dispute SET Dispute_Seller_Response = ?, Dispute_Seller_Evidence = ?, Dispute_Seller_Responded_At = NOW() WHERE Order_ID = ?');
        $stmtUp->execute([$responseText, $evidenceJson, $orderId]);

        $pdo->commit();
        out(true, 'Seller response submitted.', ['dispute_id' => intval($dispute['Dispute_ID'])]);
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    out(false, $e->getMessage());
}