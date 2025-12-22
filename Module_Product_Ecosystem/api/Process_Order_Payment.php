<?php
// api/Process_Order_Payment.php

error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/treasurego_db_config.php';
session_start();

$response = ['success' => false, 'msg' => 'Unknown error'];

// 1. 验证登录
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'msg' => 'User not logged in']);
    exit;
}
$buyerId = $_SESSION['user_id'];

// 2. 获取前端数据
$input = json_decode(file_get_contents('php://input'), true);
$totalAmount = isset($input['total_amount']) ? floatval($input['total_amount']) : 0.00;
$productId   = isset($input['product_id']) ? intval($input['product_id']) : 0;
// 配送方式虽然还没存入Orders表，但逻辑上可以保留，或者你在Orders表再加个字段存它
$shippingType = isset($input['shipping_type']) ? $input['shipping_type'] : 'meetup';

if ($totalAmount <= 0 || $productId === 0) {
    echo json_encode(['success' => false, 'msg' => 'Invalid payment data']);
    exit;
}

try {
    $conn = getDatabaseConnection();

    // === 开启事务 (Transaction) ===
    $conn->beginTransaction();

    // ----------------------------------------------------------------
    // 3. 获取商品信息 & 卖家ID (关键步骤：使用 FOR UPDATE 锁住商品防止并发购买)
    // ----------------------------------------------------------------
    $sqlProduct = "SELECT User_ID AS Seller_ID, Product_Price, Product_Status, Product_Title 
                   FROM Product 
                   WHERE Product_ID = :pid 
                   FOR UPDATE";
    $stmtProd = $conn->prepare($sqlProduct);
    $stmtProd->execute([':pid' => $productId]);
    $productInfo = $stmtProd->fetch(PDO::FETCH_ASSOC);

    // 校验商品有效性
    if (!$productInfo) {
        throw new Exception("Product not found");
    }
    if ($productInfo['Product_Status'] !== 'Active') {
        throw new Exception("Product is already sold or unavailable");
    }
    if ($productInfo['Seller_ID'] == $buyerId) {
        throw new Exception("You cannot buy your own product");
    }

    $sellerId = $productInfo['Seller_ID'];
    $productPrice = floatval($productInfo['Product_Price']);

    // ----------------------------------------------------------------
    // 4. 检查买家余额 (并锁定钱包行)
    // ----------------------------------------------------------------
    $sqlCheck = "SELECT Balance_After FROM Wallet_Logs WHERE User_ID = :uid ORDER BY Log_ID DESC LIMIT 1 FOR UPDATE";
    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->execute([':uid' => $buyerId]);
    $walletResult = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    $currentBalance = $walletResult ? (float)$walletResult['Balance_After'] : 0.00;

    if ($currentBalance < $totalAmount) {
        throw new Exception("Insufficient balance");
    }

    // ----------------------------------------------------------------
    // 5. 执行扣款 (插入 Wallet_Logs)
    // ----------------------------------------------------------------
    $newBalance = $currentBalance - $totalAmount;
    $negativeAmount = -1 * $totalAmount;
    $walletDesc = "Payment for Order: " . $productInfo['Product_Title'];

    $sqlInsertWallet = "INSERT INTO Wallet_Logs 
                  (User_ID, Amount, Balance_After, Description, Reference_Type, Created_AT) 
                  VALUES 
                  (:uid, :amount, :balance_after, :desc, 'order_payment', NOW())";

    $stmtWallet = $conn->prepare($sqlInsertWallet);
    $stmtWallet->execute([
        ':uid' => $buyerId,
        ':amount' => $negativeAmount,
        ':balance_after' => $newBalance,
        ':desc' => $walletDesc
    ]);

    // ----------------------------------------------------------------
    // 6. 生成订单 (插入 Orders 表)
    // ----------------------------------------------------------------
    // 计算 2% 的平台服务费 (注意：通常是按商品原价算的，不是按含运费的总价)
    // 这里我们用商品原价 * 0.02 记录下来
    $platformFee = $productPrice * 0.02;

    $sqlOrder = "INSERT INTO Orders (
                    Orders_Buyer_ID, 
                    Orders_Seller_ID, 
                    Product_ID,          /* 记得执行SQL添加这个字段 */
                    Orders_Total_Amount, 
                    Orders_Platform_Fee, 
                    Orders_Status, 
                    Orders_Created_AT
                ) VALUES (
                    :buyer_id,
                    :seller_id,
                    :product_id,
                    :total_amount,
                    :platform_fee,
                    'Paid',              /* 初始状态为已支付 */
                    NOW()
                )";

    $stmtOrder = $conn->prepare($sqlOrder);
    $stmtOrder->execute([
        ':buyer_id' => $buyerId,
        ':seller_id' => $sellerId,
        ':product_id' => $productId,
        ':total_amount' => $totalAmount,
        ':platform_fee' => $platformFee,
    ]);

    // ----------------------------------------------------------------
    // 7. 更新商品状态为已售出 (Sold)
    // ----------------------------------------------------------------
    $sqlUpdateProd = "UPDATE Product SET Product_Status = 'Sold' WHERE Product_ID = :pid";
    $stmtUpdateProd = $conn->prepare($sqlUpdateProd);
    $stmtUpdateProd->execute([':pid' => $productId]);

    // === 提交事务 ===
    $conn->commit();

    $response['success'] = true;
    $response['msg'] = 'Payment successful';

} catch (Exception $e) {
    if (isset($conn)) { $conn->rollBack(); } // 出错回滚
    $response['msg'] = $e->getMessage(); // 返回具体错误信息给前端
}

echo json_encode($response);
?>