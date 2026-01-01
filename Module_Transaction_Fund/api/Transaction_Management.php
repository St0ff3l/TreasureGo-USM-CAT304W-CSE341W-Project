<?php
/**
 * TreasureGO - Transaction Management API
 * Backend API for Transaction_Management.html
 * Handles: Wallet logs retrieval, balance tracking, transaction history
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
    // Suppress non-critical warnings to preserve JSON format
    if (!(error_reporting() & $errno)) {
        return;
    }
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
        case 'get_wallet_logs':
            getWalletLogs($conn, $request);
            break;

        case 'get_wallet_balance':
            getWalletBalance($conn, $request);
            break;

        case 'create_wallet_log':
            createWalletLogAction($conn, $request);
            break;

        case 'get_statistics':
            getStatistics($conn, $request);
            break;

        case 'get_transaction_summary':
            getTransactionSummary($conn, $request);
            break;

        default:
            sendResponse(false, 'Invalid action', null, 400);
    }

} catch (Exception $e) {
    sendResponse(false, 'Error: ' . $e->getMessage(), null, 500);
}

// ==================== WALLET LOG FUNCTIONS ====================

/**
 * Get wallet logs for a user (all transactions)
 */
function getWalletLogs($conn, $request) {
    try {
        $userId = $request['user_id'] ?? $_GET['user_id'] ?? null;
        $limit = $request['limit'] ?? $_GET['limit'] ?? 50;
        $offset = $request['offset'] ?? $_GET['offset'] ?? 0;

        if (!$userId) {
            sendResponse(false, 'Missing required field: user_id', null, 400);
        }

        // Get total count (Matches schema: User_ID)
        $sql = "SELECT COUNT(*) as total FROM Wallet_Logs WHERE User_ID = :user_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $totalCount = $stmt->fetch()['total'];

        // Get paginated logs (Matches schema: User_ID, Created_AT)
        $sql = "SELECT * FROM Wallet_Logs 
                WHERE User_ID = :user_id 
                ORDER BY Created_AT DESC 
                LIMIT :limit OFFSET :offset";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendResponse(true, 'Wallet logs retrieved successfully', [
            'logs' => $logs,
            'total' => $totalCount,
            'limit' => $limit,
            'offset' => $offset
        ]);

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

        // Fetch the latest Balance_After
        $sql = "SELECT Balance_After FROM Wallet_Logs WHERE User_ID = :user_id ORDER BY Created_AT DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $balance = $result ? $result['Balance_After'] : 0.00;

        sendResponse(true, 'Wallet balance retrieved successfully', ['balance' => $balance]);

    } catch (PDOException $e) {
        sendResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

/**
 * Create wallet log entry (internal helper function)
 */
function createWalletLog($conn, $data) {
    $userId = $data['user_id'];
    $amount = $data['amount'];
    $type = $data['type'];
    $referenceId = $data['reference_id'] ?? null;
    $referenceType = $data['reference_type'] ?? '';

    // Get current balance
    $sql = "SELECT Balance_After FROM Wallet_Logs WHERE User_ID = :user_id ORDER BY Created_AT DESC LIMIT 1 FOR UPDATE";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':user_id' => $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $currentBalance = $result ? $result['Balance_After'] : 0.00;

    // Calculate new balance
    // Ensure logical handling of amount sign
    $changeAmount = ($type === 'withdrawal' || $type === 'purchase') ? -abs($amount) : abs($amount);
    $newBalance = $currentBalance + $changeAmount;

    // Build Description
    $description = ucfirst($type) . ' of $' . number_format(abs($amount), 2);

    // Insert log
    // Matches schema: Log_ID (Auto), User_ID, Amount, Balance_After, Description, Reference_Type, Reference_ID, Created_AT
    $sql = "INSERT INTO Wallet_Logs (User_ID, Amount, Balance_After, Description, Reference_Type, Reference_ID, Created_AT)
            VALUES (:user_id, :amount, :balance_after, :description, :reference_type, :reference_id, NOW(6))";

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
 * Create wallet log (API endpoint - for manual entries)
 */
function createWalletLogAction($conn, $request) {
    try {
        $userId = $request['user_id'] ?? null;
        $amount = $request['amount'] ?? null;
        $type = $request['type'] ?? null;
        $referenceId = $request['reference_id'] ?? null;
        $referenceType = $request['reference_type'] ?? '';

        // Validation
        if (!$userId || !$amount || !$type) {
            sendResponse(false, 'Missing required fields: user_id, amount, type', null, 400);
        }

        if ($amount <= 0) {
            sendResponse(false, 'Amount must be greater than 0', null, 400);
        }

        $conn->beginTransaction();

        $newBalance = createWalletLog($conn, [
            'user_id' => $userId,
            'amount' => $amount,
            'type' => $type,
            'reference_id' => $referenceId,
            'reference_type' => $referenceType
        ]);

        $conn->commit();

        sendResponse(true, 'Wallet log created successfully', [
            'new_balance' => $newBalance
        ]);

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        sendResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

// ==================== STATISTICS FUNCTIONS ====================

/**
 * Get transaction statistics for dashboard
 */
function getStatistics($conn, $request) {
    try {
        $userId = $request['user_id'] ?? $_GET['user_id'] ?? null;

        if (!$userId) {
            sendResponse(false, 'Missing required field: user_id', null, 400);
        }

        // Get current balance
        $sql = "SELECT Balance_After FROM Wallet_Logs WHERE User_ID = :user_id ORDER BY Created_AT DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $balance = $result ? $result['Balance_After'] : 0.00;

        // Get total transactions count
        $sql = "SELECT COUNT(*) as total FROM Wallet_Logs WHERE User_ID = :user_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $totalTransactions = $stmt->fetch()['total'];

        // Get total deposits (Income)
        $sql = "SELECT COALESCE(SUM(Amount), 0) as total FROM Wallet_Logs WHERE User_ID = :user_id AND Amount > 0";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $totalDeposits = $stmt->fetch()['total'];

        // Get total withdrawals (Expense)
        $sql = "SELECT COALESCE(ABS(SUM(Amount)), 0) as total FROM Wallet_Logs WHERE User_ID = :user_id AND Amount < 0";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $totalWithdrawals = $stmt->fetch()['total'];

        // Get transaction count by description type
        $sql = "SELECT Description, COUNT(*) as count FROM Wallet_Logs WHERE User_ID = :user_id GROUP BY Description ORDER BY count DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $transactionByType = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendResponse(true, 'Statistics retrieved successfully', [
            'balance' => $balance,
            'total_transactions' => $totalTransactions,
            'total_deposits' => $totalDeposits,
            'total_withdrawals' => $totalWithdrawals,
            'transactions_by_type' => $transactionByType
        ]);

    } catch (PDOException $e) {
        sendResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

/**
 * Get transaction summary (overview for period)
 */
function getTransactionSummary($conn, $request) {
    try {
        $userId = $request['user_id'] ?? $_GET['user_id'] ?? null;
        $days = $request['days'] ?? $_GET['days'] ?? 30; // Last 30 days by default

        if (!$userId) {
            sendResponse(false, 'Missing required field: user_id', null, 400);
        }

        // Get summary for specified period
        $sql = "SELECT 
                    DATE(Created_AT) as date,
                    COUNT(*) as transaction_count,
                    SUM(CASE WHEN Amount > 0 THEN Amount ELSE 0 END) as daily_deposits,
                    SUM(CASE WHEN Amount < 0 THEN ABS(Amount) ELSE 0 END) as daily_withdrawals
                FROM Wallet_Logs
                WHERE User_ID = :user_id 
                AND Created_AT >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY DATE(Created_AT)
                ORDER BY date DESC";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':days', (int)$days, PDO::PARAM_INT);
        $stmt->execute();
        $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get current balance
        $sql = "SELECT Balance_After FROM Wallet_Logs WHERE User_ID = :user_id ORDER BY Created_AT DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $currentBalance = $result ? $result['Balance_After'] : 0.00;

        sendResponse(true, 'Transaction summary retrieved successfully', [
            'current_balance' => $currentBalance,
            'period_days' => $days,
            'daily_summary' => $summary
        ]);

    } catch (PDOException $e) {
        sendResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

?>