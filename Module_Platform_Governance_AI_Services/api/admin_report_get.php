<?php
// 1. 基础配置
header('Content-Type: application/json');
// 允许跨域 (参照你的 Get_Products.php)
header('Access-Control-Allow-Origin: *');

require_once 'config/treasurego_db_config.php';

try {
    if (!$pdo) {
        throw new Exception("Database connection failed.");
    }

    // 2. 获取统计数据 (Stats)
    $statsQuery = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN Report_Status = 'Pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN Report_Status = 'Resolved' THEN 1 ELSE 0 END) as resolved,
            SUM(CASE WHEN Report_Status = 'Dismissed' THEN 1 ELSE 0 END) as dismissed,
            SUM(CASE WHEN Report_Status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM Report";
    $statsStmt = $pdo->query($statsQuery);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // 3. 获取详细列表 (Report List)
    // 关键修改：参照 Get_Products.php 的逻辑获取 Image_URL
    $listSql = "
        SELECT 
            r.Report_ID as id,
            r.Report_Type as type,
            r.Report_Reason as reason,
            r.Report_Description as details,
            r.Report_Status as status,
            r.Report_Creation_Date as date,
            r.Report_Contact_Email as contactEmail,
            
            -- 举报者信息
            u1.User_ID as reporterId,
            u1.User_Username as reporter, 
            u1.User_Email as reporterAccountEmail,
            
            -- 被举报人信息
            u2.User_ID as reportedUserId,
            u2.User_Username as reportedUserName,
            u2.User_Email as reportedUserEmail,
            
            -- 关联商品信息
            r.Reported_Item_ID as reportedItemId,
            CASE 
                WHEN r.Report_Type = 'product' AND p.Product_Title IS NOT NULL THEN p.Product_Title
                ELSE u2.User_Username 
            END as targetName,

            -- 【关键修改】参照你的 Get_Products.php 获取图片
            -- 使用 Image_URL 字段，且限制取 1 张
            (SELECT Image_URL 
             FROM Product_Images pi 
             WHERE pi.Product_ID = p.Product_ID 
             LIMIT 1
            ) as productImage

        FROM Report r
        LEFT JOIN User u1 ON r.Reporting_User_ID = u1.User_ID
        LEFT JOIN User u2 ON r.Reported_User_ID = u2.User_ID
        LEFT JOIN Product p ON r.Reported_Item_ID = p.Product_ID
        ORDER BY r.Report_Creation_Date DESC";

    $listStmt = $pdo->query($listSql);
    $reports = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'stats' => [
            'total' => (int)$stats['total'],
            'pending' => (int)$stats['pending'],
            'resolved' => (int)$stats['resolved'],
            'dismissed' => (int)$stats['dismissed'],
            'cancelled' => (int)$stats['cancelled']
        ],
        'reports' => $reports
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>