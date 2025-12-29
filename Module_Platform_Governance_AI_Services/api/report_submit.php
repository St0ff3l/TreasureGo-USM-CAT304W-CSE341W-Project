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
// 2) Parse JSON OR FormData
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (stripos($contentType, 'application/json') !== false) {
    // JSON 提交（无图片）
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
} else {
    // FormData 提交（有图片）
    $input = $_POST;
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

    $contactEmail = isset($input['contactEmail']) ? trim((string)$input['contactEmail']) : null;

    // 5) Insert report
    $sql = "INSERT INTO Report (
                Report_Type,
                Report_Reason,
                Report_Description,
                Report_Status,
                Report_Creation_Date,
                Admin_Action_ID,
                Reporting_User_ID,
                Report_Contact_Email,
                Reported_User_ID,
                Reported_Item_ID
            ) VALUES (?, ?, ?, 'Pending', NOW(), NULL, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $ok = $stmt->execute([
        $type,
        $reportReason,
        $details,
        $reportingUserId,
        $contactEmail,
        $reportedUserId,
        $reportedItemId
    ]);

    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database insertion failed']);
        exit;
    }
    $reportId = (int)$conn->lastInsertId();

// =======================
// 处理图片上传（可选）
// =======================
    $savedPaths = [];

    if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
        $maxCount = 3;
        $maxSize = 2 * 1024 * 1024; // 2MB 每张
        $allowedMime = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp'
        ];

        // 存放目录（确保这个目录在服务器可写）
        $uploadDir = __DIR__ . '/../../Public_Assets/uploads/report_evidence';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);

        $count = min(count($_FILES['images']['name']), $maxCount);

        for ($i = 0; $i < $count; $i++) {
            $err  = $_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            $tmp  = $_FILES['images']['tmp_name'][$i] ?? null;
            $size = (int)($_FILES['images']['size'][$i] ?? 0);

            if ($err === UPLOAD_ERR_NO_FILE) continue;
            if ($err !== UPLOAD_ERR_OK || !$tmp) continue;
            if ($size <= 0 || $size > $maxSize) continue;

            $mime = $finfo->file($tmp);
            if (!isset($allowedMime[$mime])) continue;

            $ext = $allowedMime[$mime];

            // 安全文件名：reportId_time_rand.ext
            $filename = $reportId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $absPath = $uploadDir . '/' . $filename;

            if (!move_uploaded_file($tmp, $absPath)) continue;

            // 给前端用的 web path
            $webPath = '/Public_Assets/uploads/report_evidence/' . $filename;
            $savedPaths[] = $webPath;

            // 写入 Report_Evidence
            $evStmt = $conn->prepare("INSERT INTO Report_Evidence (Report_ID, File_Path) VALUES (?, ?)");
            $evStmt->execute([$reportId, $webPath]);
        }
    }

    echo json_encode([
        'success' => true,
        'report_id' => $reportId,
        'status' => 'Pending',
        'reported_user_id' => $reportedUserId,
        'product_title' => $dbProductTitle,
        'evidence' => $savedPaths
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
