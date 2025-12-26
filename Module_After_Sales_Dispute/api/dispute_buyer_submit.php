<?php
// Module_After_Sales_Dispute/api/dispute_buyer_submit.php
// Buyer submits a dispute => insert Dispute row + set Refund_Requests.Refund_Status = 'dispute_in_progress'

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// Use the same DB config as Refund_Actions.php so table names/schema align
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    out(false, 'Method not allowed.');
}

$userId = intval($_SESSION['user_id']);

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    out(false, 'Invalid JSON payload.');
}

$orderId = intval($data['order_id'] ?? 0);
$disputeReason = trim($data['dispute_reason'] ?? '');
$disputeDetails = trim($data['dispute_details'] ?? '');
$disputeType = trim($data['dispute_type'] ?? ''); // rejected_return | refused_return_received
$returnTracking = trim($data['return_tracking_number'] ?? '');

if ($orderId <= 0) out(false, 'Missing order_id');
if ($disputeReason === '') out(false, 'Missing dispute_reason');
if (mb_strlen($disputeDetails) < 20) out(false, 'Please provide more details (at least 20 characters).');

$allowedTypes = ['rejected_return', 'refused_return_received'];
if ($disputeType !== '' && !in_array($disputeType, $allowedTypes, true)) {
    out(false, 'Invalid dispute_type');
}
if ($disputeType === 'refused_return_received' && $returnTracking === '') {
    out(false, 'Return tracking number is required.');
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception('Database connection failed');

    $pdo->beginTransaction();

    // 1) Verify order belongs to buyer and fetch seller id
    $stmt = $pdo->prepare('SELECT Orders_Buyer_ID, Orders_Seller_ID FROM Orders WHERE Orders_Order_ID = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) throw new Exception('Order not found');
    if (intval($order['Orders_Buyer_ID']) !== $userId) throw new Exception('Permission denied: buyer only');

    $sellerId = intval($order['Orders_Seller_ID']);

    // 2) Find refund request for this order
    $stmtR = $pdo->prepare('SELECT Refund_ID FROM Refund_Requests WHERE Order_ID = ? FOR UPDATE');
    $stmtR->execute([$orderId]);
    $refund = $stmtR->fetch(PDO::FETCH_ASSOC);

    if (!$refund) throw new Exception('Refund request not found for this order');
    $refundId = intval($refund['Refund_ID']);

    // 3) Prevent duplicate dispute per order
    $stmtD = $pdo->prepare('SELECT Dispute_ID FROM Dispute WHERE Order_ID = ? LIMIT 1');
    $stmtD->execute([$orderId]);
    $existing = $stmtD->fetch(PDO::FETCH_ASSOC);
    if ($existing) throw new Exception('A dispute already exists for this order');

    // 4) Insert dispute
    $detailsFull = $disputeDetails;
    if ($disputeType === 'refused_return_received') {
        $detailsFull = "[Return Tracking] {$returnTracking}\n\n" . $detailsFull;
    }

    $sqlIns = 'INSERT INTO Dispute
        (Dispute_Reason, Dispute_Details, Dispute_Status, Dispute_Creation_Date,
         Reporting_User_ID, Reported_User_ID, Order_ID, Refund_ID)
        VALUES (?, ?, ?, NOW(), ?, ?, ?, ?)';

    $stmtIns = $pdo->prepare($sqlIns);
    $stmtIns->execute([
        $disputeReason,
        $detailsFull,
        'Open',
        $userId,
        $sellerId,
        $orderId,
        $refundId
    ]);

    $disputeId = $pdo->lastInsertId();

    // 5) Mark refund as dispute in progress
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

