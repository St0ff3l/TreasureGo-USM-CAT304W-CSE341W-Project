<?php
// 1. 屏蔽报错，只输出 JSON
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

// 2. 引入数据库配置
require_once __DIR__ . '/config/treasurego_db_config.php';

// 3. 开启 Session (必须放在最前面，否则无法获取 $_SESSION)
session_start();

$response = ['success' => false, 'balance' => 0.00, 'msg' => ''];

// ================== 核心修改开始 ==================

// 4. 动态获取 User ID
// 检查 Session 里有没有 'user_id'。
// 注意：你需要在登录页面(Login)设置 $_SESSION['user_id'] = 数据库里的ID
if (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
} else {
    // 如果没登录，直接返回错误，不查数据库
    echo json_encode(['success' => false, 'msg' => 'User not logged in']);
    exit;
}

// ================== 核心修改结束 ==================

try {
    $conn = getDatabaseConnection();

    if ($conn) {
        // 5. 使用动态的 $userId 去查询
        $sql = "SELECT Balance_After FROM Wallet_Logs WHERE User_ID = :uid ORDER BY Log_ID DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':uid', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $response['success'] = true;
            $response['balance'] = (float)$result['Balance_After'];
        } else {
            // 用户登录了，但钱包表里还没记录（可能是新用户）
            $response['success'] = true;
            $response['balance'] = 0.00;
        }
    } else {
        $response['msg'] = 'Database connection failed';
    }
} catch (Exception $e) {
    $response['msg'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
exit;
?>