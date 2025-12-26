<?php
// Module_After_Sales_Dispute/api/dispute_seller_submit.php
// Seller submits a statement for an existing dispute (admin-only visibility).

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
$responseText = trim($data['seller_response'] ?? '');

if ($orderId <= 0) out(false, 'Missing order_id');
if (mb_strlen($responseText) < 20) out(false, 'Please provide more details (at least 20 characters).');

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception('Database connection failed');

    $pdo->beginTransaction();

    // Verify seller matches order
    $stmtO = $pdo->prepare('SELECT Orders_Seller_ID FROM Orders WHERE Orders_Order_ID = ? FOR UPDATE');
    $stmtO->execute([$orderId]);
    $order = $stmtO->fetch(PDO::FETCH_ASSOC);
    if (!$order) throw new Exception('Order not found');
    if (intval($order['Orders_Seller_ID']) !== $userId) throw new Exception('Permission denied: seller only');

    // Lock dispute by order
    $stmtD = $pdo->prepare('SELECT Dispute_ID, Dispute_Status, Dispute_Seller_Response FROM Dispute WHERE Order_ID = ? FOR UPDATE');
    $stmtD->execute([$orderId]);
    $dispute = $stmtD->fetch(PDO::FETCH_ASSOC);
    if (!$dispute) throw new Exception('Dispute not found for this order');

    // Allow update if empty; if already set, prevent overwrite (keeps audit integrity)
    if (!empty($dispute['Dispute_Seller_Response'])) {
        throw new Exception('Seller response already submitted');
    }

    // Prevent response after resolved/closed (optional safety)
    $status = strtolower(trim($dispute['Dispute_Status'] ?? ''));
    if ($status === 'resolved' || $status === 'closed') {
        throw new Exception('Dispute is already closed/resolved');
    }

    $stmtUp = $pdo->prepare('UPDATE Dispute SET Dispute_Seller_Response = ?, Dispute_Seller_Responded_At = NOW() WHERE Order_ID = ?');
    $stmtUp->execute([$responseText, $orderId]);

    $pdo->commit();
    out(true, 'Seller response submitted.', ['dispute_id' => intval($dispute['Dispute_ID'])]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    out(false, $e->getMessage());
}

