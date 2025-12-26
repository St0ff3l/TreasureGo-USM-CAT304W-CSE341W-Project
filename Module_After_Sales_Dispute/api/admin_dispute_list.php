<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../Module_Platform_Governance_AI_Services/api/config/treasurego_db_config.php';
require_once __DIR__ . '/../../Module_User_Account_Management/includes/auth.php';

start_session_safe();

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
if (!is_admin()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$pdo = getDatabaseConnection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

// Query params
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$from = isset($_GET['from']) ? trim($_GET['from']) : '';
$to = isset($_GET['to']) ? trim($_GET['to']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$pageSize = isset($_GET['pageSize']) ? min(100, max(5, intval($_GET['pageSize']))) : 20;
$offset = ($page - 1) * $pageSize;

try {
    $where = [];
    $params = [];

    if ($status !== '' && strtolower($status) !== 'all') {
        $where[] = 'd.Dispute_Status = :status';
        $params[':status'] = $status;
    }

    if ($q !== '') {
        $where[] = '(d.Dispute_ID = :q_id OR d.Order_ID = :q_order OR u1.User_Username LIKE :q_like OR u2.User_Username LIKE :q_like)';
        $params[':q_like'] = '%' . $q . '%';
        $params[':q_id'] = ctype_digit($q) ? intval($q) : -1;
        $params[':q_order'] = ctype_digit($q) ? intval($q) : -1;
    }

    if ($from !== '') {
        $where[] = 'd.Dispute_Creation_Date >= :from';
        $params[':from'] = $from . ' 00:00:00';
    }
    if ($to !== '') {
        $where[] = 'd.Dispute_Creation_Date <= :to';
        $params[':to'] = $to . ' 23:59:59';
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countSql = "SELECT COUNT(*)
                FROM Dispute d
                LEFT JOIN User u1 ON d.Reporting_User_ID = u1.User_ID
                LEFT JOIN User u2 ON d.Reported_User_ID = u2.User_ID
                $whereSql";

    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();

    $listSql = "SELECT
                    d.Dispute_ID,
                    d.Dispute_Reason,
                    d.Dispute_Status,
                    d.Dispute_Creation_Date,
                    d.Order_ID,
                    d.Refund_ID,
                    u1.User_Username AS Reporting_Username,
                    u2.User_Username AS Reported_Username,
                    o.Orders_Total_Amount,
                    rr.Refund_Type,
                    rr.Refund_Status
                FROM Dispute d
                LEFT JOIN User u1 ON d.Reporting_User_ID = u1.User_ID
                LEFT JOIN User u2 ON d.Reported_User_ID = u2.User_ID
                LEFT JOIN Orders o ON d.Order_ID = o.Orders_Order_ID
                LEFT JOIN Refund_Requests rr ON d.Refund_ID = rr.Refund_ID
                $whereSql
                ORDER BY d.Dispute_Creation_Date DESC
                LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($listSql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'items' => $items
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

