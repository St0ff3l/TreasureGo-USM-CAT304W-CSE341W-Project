<?php
/**
 * TreasureGO - Transaction & Fund Management API
 * Backend API for TXN_FUND.html
 * Handles: Fund Requests, Orders, Wallet Logs, Transactions
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

        // ==================== FUND REQUESTS ====================
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

        // ==================== ORDERS ====================
        case 'get_orders':
            getOrders($conn, $request);
            break;

        case 'get_order':
            getOrderById($conn, $request);
            break;

        case 'create_order':
            createOrder($conn, $request);
            break;

        case 'update_order_status':
            updateOrderStatus($conn, $request);
            break;

        case 'confirm_receipt':
            confirmOrderReceipt($conn, $request);
            break;

        // ==================== WALLET LOGS ====================
        case 'get_wallet_logs':
            getWalletLogs($conn, $request);
            break;

        case 'get_wallet_balance':
            getWalletBalance($conn, $request);
            break;

        // ==================== STATISTICS ====================
        case 'get_statistics':
            getStatistics($conn, $request);
            break;

        // ==================== TEST ====================
        case 'test_connection':
            testConnection($conn);
            break;

        default:
            sendResponse(false, 'Invalid action', null, 400);
    }

} catch (Exception $e) {
    sendResponse(false, 'Server error: ' . $e->getMessage(), null, 500);
}

// ==================== FUND REQUESTS FUNCTIONS ====================

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

        $validTypes = ['deposit', 'withdrawal', 'refund', 'transfer'];
        if (!in_array($type, $validTypes)) {
            sendResponse(false, 'Invalid type. Must be: deposit, withdrawal, refund, or transfer', null, 400);
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

// ==================== ORDERS FUNCTIONS ====================

/**
 * Get orders for a user
 */
function getOrders($conn, $request) {
    try {
        $userId = $request['user_id'] ?? $_GET['user_id'] ?? null;

        if (!$userId) {
            sendResponse(false, 'Missing required field: user_id', null, 400);
        }

        $sql = "SELECT Orders_Order_ID, Orders_Buyer_ID, Orders_Seller_ID,
                       Orders_Total_Amount, Orders_Platform_Fee, Orders_Status, Orders_Created_AT
                FROM Orders
                WHERE Orders_Buyer_ID = :user_id OR Orders_Seller_ID = :user_id
                ORDER BY Orders_Created_AT DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $orders = $stmt->fetchAll();

        sendResponse(true, 'Orders retrieved successfully', $orders);

    } catch (PDOException $e) {
        sendResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

/**
 * Get order by ID
 */
function getOrderById($conn, $request) {
    try {
        $orderId = $request['order_id'] ?? $_GET['order_id'] ?? null;

        if (!$orderId) {
            sendResponse(false, 'Missing required field: order_id', null, 400);
        }

        $sql = "SELECT Orders_Order_ID, Orders_Buyer_ID, Orders_Seller_ID,
                       Orders_Total_Amount, Orders_Platform_Fee, Orders_Status, Orders_Created_AT
                FROM Orders
                WHERE Orders_Order_ID = :order_id";

        $stmt = $conn->prepare($sql);
        $stmt->execute([':order_id' => $orderId]);
        $order = $stmt->fetch();

        if ($order) {
            sendResponse(true, 'Order retrieved successfully', $order);
        } else {
            sendResponse(false, 'Order not found', null, 404);
        }

    } catch (PDOException $e) {
        sendResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

/**
 * Create a new order
 */
function createOrder($conn, $request) {
    try {
        $buyerId = $request['buyer_id'] ?? null;
        $sellerId = $request['seller_id'] ?? null;
        $totalAmount = $request['total_amount'] ?? null;
        $platformFee = $request['platform_fee'] ?? 0;

        if (!$buyerId || !$sellerId || !$totalAmount) {
            sendResponse(false, 'Missing required fields: buyer_id, seller_id, total_amount', null, 400);
        }

        // Calculate platform fee if not provided (5%)
        if ($platformFee == 0) {
            $platformFee = $totalAmount * 0.05;
        }

        // Insert order
        $sql = "INSERT INTO Orders (Orders_Buyer_ID, Orders_Seller_ID, Orders_Total_Amount,
                                    Orders_Platform_Fee, Orders_Status, Orders_Created_AT)
                VALUES (:buyer_id, :seller_id, :total_amount, :platform_fee, 'pending', NOW())";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':buyer_id' => $buyerId,
            ':seller_id' => $sellerId,
            ':total_amount' => $totalAmount,
            ':platform_fee' => $platformFee
        ]);

        $orderId = $conn->lastInsertId();

        sendResponse(true, 'Order created successfully', [
            'order_id' => $orderId,
            'status' => 'pending'
        ]);

    } catch (PDOException $e) {
        sendResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

/**
 * Update order status
 */
function updateOrderStatus($conn, $request) {
    try {
        $orderId = $request['order_id'] ?? null;
        $status = $request['status'] ?? null;

        if (!$orderId || !$status) {
            sendResponse(false, 'Missing required fields: order_id, status', null, 400);
        }

        $validStatuses = ['pending', 'paid', 'processing', 'shipped', 'completed', 'cancelled', 'refunded'];
        if (!in_array($status, $validStatuses)) {
            sendResponse(false, 'Invalid status', null, 400);
        }

        $sql = "UPDATE Orders SET Orders_Status = :status WHERE Orders_Order_ID = :order_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':status' => $status,
            ':order_id' => $orderId
        ]);

        sendResponse(true, 'Order status updated successfully', ['status' => $status]);

    } catch (PDOException $e) {
        sendResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

/**
 * Confirm order receipt
 */
function confirmOrderReceipt($conn, $request) {
    try {
        $orderId = $request['order_id'] ?? null;
        $buyerId = $request['buyer_id'] ?? null;

        if (!$orderId || !$buyerId) {
            sendResponse(false, 'Missing required fields: order_id, buyer_id', null, 400);
        }

        // Verify buyer
        $sql = "SELECT Orders_Seller_ID, Orders_Total_Amount, Orders_Platform_Fee
                FROM Orders
                WHERE Orders_Order_ID = :order_id AND Orders_Buyer_ID = :buyer_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':order_id' => $orderId, ':buyer_id' => $buyerId]);
        $order = $stmt->fetch();

        if (!$order) {
            sendResponse(false, 'Order not found or unauthorized', null, 404);
        }

        // Update order status
        $sql = "UPDATE Orders SET Orders_Status = 'completed' WHERE Orders_Order_ID = :order_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':order_id' => $orderId]);

        // Create wallet log for seller (release funds)
        $sellerAmount = $order['Orders_Total_Amount'] - $order['Orders_Platform_Fee'];
        createWalletLog($conn, [
            'user_id' => $order['Orders_Seller_ID'],
            'amount' => $sellerAmount,
            'type' => 'sale',
            'reference_id' => $orderId,
            'reference_type' => 'order'
        ]);

        sendResponse(true, 'Order receipt confirmed successfully', ['status' => 'completed']);

    } catch (PDOException $e) {
        sendResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

// ==================== WALLET LOGS FUNCTIONS ====================

/**
 * Get wallet logs for a user
 */
function getWalletLogs($conn, $request) {
    try {
        $userId = $request['user_id'] ?? $_GET['user_id'] ?? null;

        if (!$userId) {
            sendResponse(false, 'Missing required field: user_id', null, 400);
        }

        $sql = "SELECT Log_ID, User_id, Amount, Balance_After, Description,
                       Reference_Type, Reference_ID, Created_AT
                FROM Wallet_Logs
                WHERE User_id = :user_id
                ORDER BY Created_AT DESC
                LIMIT 50";

        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $logs = $stmt->fetchAll();

        sendResponse(true, 'Wallet logs retrieved successfully', $logs);

    } catch (PDOException $e) {
        sendResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

/**
 * Get wallet balance for a user
 */
function getWalletBalance($conn, $request) {
    try {
        $userId = $request['user_id'] ?? $_GET['user_id'] ?? null;

        if (!$userId) {
            sendResponse(false, 'Missing required field: user_id', null, 400);
        }

        $sql = "SELECT Balance_After FROM Wallet_Logs
                WHERE User_id = :user_id
                ORDER BY Created_AT DESC
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $result = $stmt->fetch();

        $balance = $result ? $result['Balance_After'] : 0.00;

        sendResponse(true, 'Wallet balance retrieved successfully', ['balance' => $balance]);

    } catch (PDOException $e) {
        sendResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

/**
 * Create wallet log (internal function)
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

        // Get total orders
        $sql = "SELECT COUNT(*) as total FROM Orders WHERE Orders_Buyer_ID = :user_id OR Orders_Seller_ID = :user_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $totalOrders = $stmt->fetch()['total'];

        // Get pending fund requests
        $sql = "SELECT COUNT(*) as total FROM Fund_Requests WHERE User_ID = :user_id AND Status = 'pending'";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $pendingRequests = $stmt->fetch()['total'];

        // Get completed transactions
        $sql = "SELECT COUNT(*) as total FROM Orders WHERE (Orders_Buyer_ID = :user_id OR Orders_Seller_ID = :user_id) AND Orders_Status = 'completed'";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $completedTransactions = $stmt->fetch()['total'];

        sendResponse(true, 'Statistics retrieved successfully', [
            'balance' => number_format($balance, 2),
            'total_orders' => $totalOrders,
            'pending_requests' => $pendingRequests,
            'completed_transactions' => $completedTransactions
        ]);

    } catch (PDOException $e) {
        sendResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

// ==================== TEST FUNCTION ====================

/**
 * Test database connection
 */
function testConnection($conn) {
    try {
        $sql = "SELECT DATABASE() as db_name, NOW() as current_time";
        $stmt = $conn->query($sql);
        $result = $stmt->fetch();

        sendResponse(true, 'Database connection successful', [
            'database' => $result['db_name'],
            'server_time' => $result['current_time'],
            'host' => DB_HOST,
            'port' => DB_PORT
        ]);

    } catch (PDOException $e) {
        sendResponse(false, 'Connection test failed: ' . $e->getMessage(), null, 500);
    }
}

?>

