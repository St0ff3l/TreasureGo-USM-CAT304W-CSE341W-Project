<?php
// 文件路径: Module_User_Account_Management/api/Get_User_Reviews.php

// 1. 设置错误报告
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

try {
    // 2. 启动 Session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // 3. --- 指定唯一的、正确的配置文件路径 ---
    // 从当前文件 (Get_User_Reviews.php) 往上退两级，进入 Product 模块找配置
    $configPath = __DIR__ . '/../../Module_Product_Ecosystem/api/config/treasurego_db_config.php';

    if (!file_exists($configPath)) {
        throw new Exception("找不到数据库配置文件。请确认文件是否存在于: " . $configPath);
    }

    require_once $configPath;

    // 4. 再次检查函数是否存在 (调试用)
    if (!function_exists('getDatabaseConnection')) {
        // 如果还找不到，说明加载的文件内容不对，可能是空文件
        throw new Exception("配置文件已加载，但在该文件中未找到 getDatabaseConnection() 函数。请检查该文件内容是否完整。");
    }

    $pdo = getDatabaseConnection();
    if (!$pdo) {
        throw new Exception("数据库连接失败 (PDO 返回为空)。");
    }

    // 5. 检查登录
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Please login first']);
        exit;
    }

    $current_user_id = $_SESSION['user_id'];

    // 6. 获取用户评分数据 (从 User 表)
    $sqlUser = "SELECT User_Average_Rating, User_Review_Count FROM User WHERE User_ID = ?";
    $stmtUser = $pdo->prepare($sqlUser);
    $stmtUser->execute([$current_user_id]);
    $userStats = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$userStats) {
        $userStats = ['User_Average_Rating' => '0.0', 'User_Review_Count' => 0];
    }

    // 7. 获取评价列表 (从 Review 表关联查询)
    $sqlReviews = "
        SELECT 
            r.Reviews_ID,
            r.Reviews_Rating,
            r.Reviews_Comment,
            r.Reviews_Created_At,
            
            p.Product_ID,
            p.Product_Title,
            (SELECT Image_URL FROM Product_Images pi WHERE pi.Product_ID = p.Product_ID LIMIT 1) as Main_Image,
            
            u.User_Username as Reviewer_Name,
            u.User_Profile_Image as Reviewer_Avatar,
            
            -- 判断评价者身份
            CASE 
                WHEN o.Orders_Buyer_ID = r.User_ID THEN 'Buyer'
                WHEN o.Orders_Seller_ID = r.User_ID THEN 'Seller'
                ELSE 'Unknown'
            END as Reviewer_Role

        FROM Review r
        LEFT JOIN Orders o ON r.Order_ID = o.Orders_Order_ID
        LEFT JOIN Product p ON o.Product_ID = p.Product_ID
        LEFT JOIN User u ON r.User_ID = u.User_ID
        
        WHERE r.Target_User_ID = ? 
        ORDER BY r.Reviews_Created_At DESC
    ";

    $stmtReviews = $pdo->prepare($sqlReviews);
    $stmtReviews->execute([$current_user_id]);
    $reviewsList = $stmtReviews->fetchAll(PDO::FETCH_ASSOC);

    // 8. 返回最终数据
    echo json_encode([
        'success' => true,
        'user_stats' => $userStats,
        'data' => $reviewsList
    ]);

} catch (Throwable $e) {
    // 捕获所有错误并返回 JSON
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'API Error: ' . $e->getMessage()
    ]);
}
?>