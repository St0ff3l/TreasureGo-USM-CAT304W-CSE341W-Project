<?php
// ==============================================================================
// API: Submit Report (Product focus)
// Path: Module_Platform_Governance_AI_Services/api/report_submit.php
// Method: POST (JSON)
// Auth: Session required (uses $_SESSION['user_id'] as Reporting_User_ID)
// Inserts into: Report
//
// Expected payload (from pages/report.html):
// {
//   "type": "product",
//   "reportReason": "Spam" | "Scam" | ...,
//   "details": "...",
//   "reportedUserId": 123,            // optional but recommended
//   "reportedItemId": 456,            // REQUIRED for product
//   "contactEmail": "..."            // optional (not stored unless you add column)
// }
//
// Note:
// - Current implementation supports PRODUCT reports only (type=product).
// - Report_Status is set to 'Pending'; Report_Creation_Date is NOW().
// - Admin_Action_ID is NULL.
// ==============================================================================

session_start();
require_once __DIR__ . '/config/treasurego_db_config.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // If you later add CORS headers, you can exit early here.
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

// 1) Auth check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Auth Required']);
    exit;
}
$reportingUserId = (int)$_SESSION['user_id'];

// 2) Parse JSON
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$type = isset($input['type']) ? strtolower(trim((string)$input['type'])) : 'product';
$reportReason = isset($input['reportReason']) ? trim((string)$input['reportReason']) : '';
$details = isset($input['details']) ? trim((string)$input['details']) : '';

$reportedUserId = null;
if (isset($input['reportedUserId']) && $input['reportedUserId'] !== null && $input['reportedUserId'] !== '') {
    $reportedUserId = (int)$input['reportedUserId'];
}

$reportedItemId = null;
if (isset($input['reportedItemId']) && $input['reportedItemId'] !== null && $input['reportedItemId'] !== '') {
    $reportedItemId = (int)$input['reportedItemId'];
}

// 3) Validate (product-only for now)
if ($type !== 'product') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Only product reports are supported for now (type=product).']);
    exit;
}

if ($reportReason === '' || mb_strlen($reportReason, 'UTF-8') > 50) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid report reason']);
    exit;
}

if ($details === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Details are required']);
    exit;
}

if (!$reportedItemId || $reportedItemId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'reportedItemId is required for product reports']);
    exit;
}

if (!isset($conn) && isset($pdo)) {
    $conn = $pdo;
}

if (!isset($conn)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

try {
    // 4) Derive reported user from DB (source of truth)
    $ctxSql = "SELECT p.User_ID AS Seller_User_ID, p.Product_Title
               FROM Product p
               WHERE p.Product_ID = ?
               LIMIT 1";
    $ctxStmt = $conn->prepare($ctxSql);
    $ctxStmt->execute([$reportedItemId]);
    $ctxRow = $ctxStmt->fetch(PDO::FETCH_ASSOC);

    if (!$ctxRow) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit;
    }

    $dbSellerUserId = (int)$ctxRow['Seller_User_ID'];
    $dbProductTitle = $ctxRow['Product_Title'] ?? null;

    // If frontend sent a reportedUserId, we can cross-check (optional)
    if ($reportedUserId !== null && $reportedUserId > 0 && $reportedUserId !== $dbSellerUserId) {
        // Not fatal: override to DB value to prevent tampering
        $reportedUserId = $dbSellerUserId;
    } else {
        $reportedUserId = $dbSellerUserId;
    }

    // 5) Insert report
    $sql = "INSERT INTO Report (
                Report_Reason,
                Report_Status,
                Report_Creation_Date,
                Admin_Action_ID,
                Reporting_User_ID,
                Reported_User_ID,
                Reported_Item_ID
            ) VALUES (?, 'Pending', NOW(), NULL, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $ok = $stmt->execute([
        $reportReason,
        $reportingUserId,
        $reportedUserId,
        $reportedItemId
    ]);

    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database insertion failed']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'report_id' => $conn->lastInsertId(),
        'status' => 'Pending',
        'reported_user_id' => $reportedUserId,
        'product_title' => $dbProductTitle
    ]);

} catch (PDOException $e) {
    // Common FK errors can happen here (invalid product/user id)
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error',
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage()
    ]);
}
?>
