<?php
/**
 * TreasureGO - Fund Request Management API
 * Backend API for Fund_Request.html
 * Handles: Fund Requests creation, retrieval, status updates
 */

// Enable CORS for development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database configuration
require_once(__DIR__ . '/config/treasurego_db_config.php');

// Error handling
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];
$request = json_decode(file_get_contents('php://input'), true);

// Parse action from query string
parse_str(parse_url($request_uri, PHP_URL_QUERY), $query_params);
$action = $query_params['action'] ?? '';

// Response helper function
function sendResponse($success, $message, $data = null, $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// Get database connection
$conn = getDatabaseConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', null, 500);
}

// Main routing
try {
    switch ($action) {
        case 'create_fund_request':
            createFundRequest($conn, $request);
            break;

        case 'get_fund_requests':
            getFundRequests($conn, $request);
            break;

        case 'get_fund_request':
            getFundRequestById($conn, $request);
            break;

        case 'update_fund_request_status':
            updateFundRequestStatus($conn, $request);
            break;

        case 'get_statistics':
            getStatistics($conn, $request);
            break;

        default:
            sendResponse(false, 'Invalid action', null, 400);
    }

} catch (Exception $e) {
    sendResponse(false, 'Error: ' . $e->getMessage(), null, 500);
}

// ==================== FUND REQUEST FUNCTIONS ====================

/**
 * Create a new fund request
 */
function createFundRequest($conn, $request) {
    try {
        $userId = $request['user_id'] ?? null;
        $type = $request['type'] ?? null;
        $amount = $request['amount'] ?? null;
        $proofImage = $request['proof_image'] ?? '';
        $adminRemark = $request['admin_remark'] ?? '';

        // Validation
        if (!$userId || !$type || !$amount) {
            sendResponse(false, 'Missing required fields: user_id, type, amount', null, 400);
        }

        if ($amount <= 0) {
            sendResponse(false, 'Amount must be greater than 0', null, 400);
        }

        $validTypes = ['deposit', 'withdrawal'];
        if (!in_array($type, $validTypes)) {
            sendResponse(false, 'Invalid type. Must be: deposit or withdrawal', null, 400);
        }

        // Insert fund request
        $sql = "INSERT INTO Fund_Requests (User_ID, Type, Amount, Status, Proof_Image, Admin_Remark, Created_AT)
                VALUES (:user_id, :type, :amount, 'pending', :proof_image, :admin_remark, NOW())";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':type' => $type,
            ':amount' => $amount,
            ':proof_image' => $proofImage,
            ':admin_remark' => $adminRemark
        ]);

        $requestId = $conn->lastInsertId();

        sendResponse(true, 'Fund request created successfully', [
            'request_id' => $requestId,
            'status' => 'pending'
        ]);

    } catch (PDOException $e) {
        sendResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

/**
 * Get fund requests for a user
 */
function getFundRequests($conn, $request) {
    try {
        $userId = $request['user_id'] ?? $_GET['user_id'] ?? null;

        if (!$userId) {
            sendResponse(false, 'Missing required field: user_id', null, 400);
        }

        $sql = "SELECT REQUEST_ID, User_ID, Type, Amount, Status, Proof_Image, Admin_Remark,
                       Created_AT, Processed_AT
                FROM Fund_Requests
                WHERE User_ID = :user_id
                ORDER BY Created_AT DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $requests = $stmt->fetchAll();

        sendResponse(true, 'Fund requests retrieved successfully', $requests);

    } catch (PDOException $e) {
        sendResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

/**
 * Get fund request by ID
 */
function getFundRequestById($conn, $request) {
    try {
        $requestId = $request['request_id'] ?? $_GET['request_id'] ?? null;

        if (!$requestId) {
            sendResponse(false, 'Missing required field: request_id', null, 400);
        }

        $sql = "SELECT REQUEST_ID, User_ID, Type, Amount, Status, Proof_Image, Admin_Remark,
                       Created_AT, Processed_AT
                FROM Fund_Requests
                WHERE REQUEST_ID = :request_id";

        $stmt = $conn->prepare($sql);
        $stmt->execute([':request_id' => $requestId]);
        $fundRequest = $stmt->fetch();

        if ($fundRequest) {
            sendResponse(true, 'Fund request retrieved successfully', $fundRequest);
        } else {
            sendResponse(false, 'Fund request not found', null, 404);
        }

    } catch (PDOException $e) {
        sendResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

/**
 * Update fund request status (admin function)
 */
function updateFundRequestStatus($conn, $request) {
    try {
        $requestId = $request['request_id'] ?? null;
        $status = $request['status'] ?? null;
        $adminRemark = $request['admin_remark'] ?? '';

        if (!$requestId || !$status) {
            sendResponse(false, 'Missing required fields: request_id, status', null, 400);
        }

        $validStatuses = ['pending', 'approved', 'rejected', 'processing', 'completed'];
        if (!in_array($status, $validStatuses)) {
            sendResponse(false, 'Invalid status', null, 400);
        }

        // Update status
        $sql = "UPDATE Fund_Requests
                SET Status = :status, Admin_Remark = :admin_remark, Processed_AT = NOW()
                WHERE REQUEST_ID = :request_id";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':status' => $status,
            ':admin_remark' => $adminRemark,
            ':request_id' => $requestId
        ]);

        // If approved, update wallet balance
        if ($status === 'approved' || $status === 'completed') {
            // Get request details
            $sql = "SELECT User_ID, Type, Amount FROM Fund_Requests WHERE REQUEST_ID = :request_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':request_id' => $requestId]);
            $fundRequest = $stmt->fetch();

            if ($fundRequest) {
                createWalletLog($conn, [
                    'user_id' => $fundRequest['User_ID'],
                    'amount' => $fundRequest['Amount'],
                    'type' => $fundRequest['Type'],
                    'reference_id' => $requestId,
                    'reference_type' => 'fund_request'
                ]);
            }
        }

        sendResponse(true, 'Fund request status updated successfully', ['status' => $status]);

    } catch (PDOException $e) {
        sendResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

// ==================== WALLET LOG FUNCTIONS ====================

/**
 * Create wallet log entry
 */
function createWalletLog($conn, $data) {
    $userId = $data['user_id'];
    $amount = $data['amount'];
    $type = $data['type'];
    $referenceId = $data['reference_id'] ?? null;
    $referenceType = $data['reference_type'] ?? '';

    // Get current balance
    $sql = "SELECT Balance_After FROM Wallet_Logs WHERE User_id = :user_id ORDER BY Created_AT DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':user_id' => $userId]);
    $result = $stmt->fetch();
    $currentBalance = $result ? $result['Balance_After'] : 0.00;

    // Calculate new balance
    $changeAmount = ($type === 'withdrawal' || $type === 'purchase') ? -abs($amount) : abs($amount);
    $newBalance = $currentBalance + $changeAmount;

    // Description
    $description = ucfirst($type) . ' of $' . abs($amount);

    // Insert log
    $sql = "INSERT INTO Wallet_Logs (User_id, Amount, Balance_After, Description, Reference_Type, Reference_ID, Created_AT)
            VALUES (:user_id, :amount, :balance_after, :description, :reference_type, :reference_id, NOW())";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':user_id' => $userId,
        ':amount' => $changeAmount,
        ':balance_after' => $newBalance,
        ':description' => $description,
        ':reference_type' => $referenceType,
        ':reference_id' => $referenceId
    ]);

    return $newBalance;
}

/**
 * Get wallet logs for a user
 */
function getWalletLogs($conn, $request) {
    try {
        $userId = $request['user_id'] ?? $_GET['user_id'] ?? null;

        if (!$userId) {
            sendResponse(false, 'Missing required field: user_id', null, 400);
        }

        $sql = "SELECT * FROM Wallet_Logs WHERE User_id = :user_id ORDER BY Created_AT DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $logs = $stmt->fetchAll();

        sendResponse(true, 'Wallet logs retrieved successfully', $logs);

    } catch (PDOException $e) {
        sendResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

/**
 * Get current wallet balance for a user
 */
function getWalletBalance($conn, $request) {
    try {
        $userId = $request['user_id'] ?? $_GET['user_id'] ?? null;

        if (!$userId) {
            sendResponse(false, 'Missing required field: user_id', null, 400);
        }

        $sql = "SELECT Balance_After FROM Wallet_Logs WHERE User_id = :user_id ORDER BY Created_AT DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $result = $stmt->fetch();
        $balance = $result ? $result['Balance_After'] : 0.00;

        sendResponse(true, 'Wallet balance retrieved successfully', ['balance' => $balance]);

    } catch (PDOException $e) {
        sendResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

// ==================== STATISTICS FUNCTIONS ====================

/**
 * Get statistics for dashboard
 */
function getStatistics($conn, $request) {
    try {
        $userId = $request['user_id'] ?? $_GET['user_id'] ?? null;

        if (!$userId) {
            sendResponse(false, 'Missing required field: user_id', null, 400);
        }

        // Get wallet balance
        $sql = "SELECT Balance_After FROM Wallet_Logs WHERE User_id = :user_id ORDER BY Created_AT DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $result = $stmt->fetch();
        $balance = $result ? $result['Balance_After'] : 0.00;

        // Get pending fund requests
        $sql = "SELECT COUNT(*) as total FROM Fund_Requests WHERE User_ID = :user_id AND Status = 'pending'";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $pendingRequests = $stmt->fetch()['total'];

        // Get completed fund requests
        $sql = "SELECT COUNT(*) as total FROM Fund_Requests WHERE User_ID = :user_id AND Status = 'completed'";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $completedRequests = $stmt->fetch()['total'];

        sendResponse(true, 'Statistics retrieved successfully', [
            'balance' => $balance,
            'pending_requests' => $pendingRequests,
            'completed_requests' => $completedRequests
        ]);

    } catch (PDOException $e) {
        sendResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

?>

