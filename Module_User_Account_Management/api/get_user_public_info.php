<?php
header('Content-Type: application/json');
require_once '../api/config/treasurego_db_config.php';
require_once '../includes/auth.php';

start_session_safe();

// 允许未登录用户获取公开信息（例如商品详情页展示卖家信息），但为了安全，这里仅返回公开字段
// 如果业务要求必须登录才能看卖家信息，可以取消注释下面这行
// if (!is_logged_in()) { ... }

$user_id = $_GET['user_id'] ?? null;

if (!$user_id) {
    echo json_encode(['status' => 'error', 'message' => 'User ID required']);
    exit();
}

try {
    $pdo = getDBConnection();
    // 只查询公开信息：用户名、头像
    $stmt = $pdo->prepare("SELECT User_ID, User_Username, User_Profile_image as User_Avatar_Url FROM User WHERE User_ID = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if ($user) {
        echo json_encode(['status' => 'success', 'data' => $user]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'User not found']);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
