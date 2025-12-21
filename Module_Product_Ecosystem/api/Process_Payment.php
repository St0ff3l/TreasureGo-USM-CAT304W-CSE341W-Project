<?php
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/treasurego_db_config.php';
session_start();

$response = ['success' => false, 'msg' => 'Unknown error'];

// 1. 获取当前用户 (动态获取)
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'msg' => 'User not logged in']);
    exit;
}
$userId = $_SESSION['user_id'];

// 2. 获取前端传来的支付信息 (价格 & 套餐名)
// 使用 json_decode 读取前端 fetch 发送的 JSON 数据
$input = json_decode(file_get_contents('php://input'), true);
$price = isset($input['price']) ? floatval($input['price']) : 0.00;
$planName = isset($input['plan']) ? $input['plan'] : 'Membership';

if ($price <= 0) {
    echo json_encode(['success' => false, 'msg' => 'Invalid price']);
    exit;
}

try {
    $conn = getDatabaseConnection();

    // === 开启事务 (Transaction) ===
    // 这一点非常重要！保证查询余额和扣款是原子操作，防止并发问题
    $conn->beginTransaction();

    // 3. 再次查询最新余额 (后端校验)
    // 即使前端判断了余额充足，后端必须再判断一次，防止黑客绕过前端
    $sqlCheck = "SELECT Balance_After FROM Wallet_Logs WHERE User_ID = :uid ORDER BY Log_ID DESC LIMIT 1 FOR UPDATE";
    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->bindParam(':uid', $userId, PDO::PARAM_INT);
    $stmtCheck->execute();
    $result = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    $currentBalance = $result ? (float)$result['Balance_After'] : 0.00;

    // 4. 判断余额是否足够
    if ($currentBalance < $price) {
        $conn->rollBack(); // 回滚事务
        echo json_encode(['success' => false, 'msg' => 'Insufficient balance (Server Check)']);
        exit;
    }

    // 5. 计算新余额
    $newBalance = $currentBalance - $price;

    // 6. 插入扣款记录 (Amount 为负数)
    $sqlInsert = "INSERT INTO Wallet_Logs 
                  (User_ID, Amount, Balance_After, Description, Reference_Type, Created_AT) 
                  VALUES 
                  (:uid, :amount, :balance_after, :desc, 'membership_pay', NOW())";

    // Amount 存为负数，例如 -9.90
    $negativeAmount = -1 * $price;
    $description = "Purchase " . $planName;

    $stmtInsert = $conn->prepare($sqlInsert);
    $stmtInsert->bindParam(':uid', $userId, PDO::PARAM_INT);
    $stmtInsert->bindParam(':amount', $negativeAmount);
    $stmtInsert->bindParam(':balance_after', $newBalance);
    $stmtInsert->bindParam(':desc', $description);
    $stmtInsert->execute();

    // === 提交事务 ===
    $conn->commit();

    $response['success'] = true;
    $response['msg'] = 'Payment successful';

} catch (Exception $e) {
    if (isset($conn)) { $conn->rollBack(); } // 出错就回滚，不扣钱
    $response['msg'] = 'Database Error: ' . $e->getMessage();
}

echo json_encode($response);
?>