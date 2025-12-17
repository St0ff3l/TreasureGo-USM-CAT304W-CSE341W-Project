<?php
// ============================================
// File: api/admin_kb_delete.php
// Description: Delete a specific knowledge base entry
// ============================================

session_start();
require_once __DIR__ . '/config/treasurego_db_config.php';

// Set JSON header
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 1. Auth Check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Authentication Required']);
    exit;
}

// 2. Get Input Data
$input = json_decode(file_get_contents('php://input'), true);

// 3. Validation: Delete only needs an ID
if (empty($input['id'])) {
    echo json_encode(['success' => false, 'error' => 'ID is missing']);
    exit;
}

try {
    // 4. Connect DB
    if (!isset($conn) && isset($pdo)) { $conn = $pdo; }

    // 5. Execute Delete
    $sql = "DELETE FROM KnowledgeBase WHERE KB_ID = ?";
    $stmt = $conn->prepare($sql);
    $success = $stmt->execute([$input['id']]);

    if ($success) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Delete operation failed in DB']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>