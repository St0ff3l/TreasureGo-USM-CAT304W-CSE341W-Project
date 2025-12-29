<?php
// Module_Platform_Governance_AI_Services/api/admin_report_get.php

// 1. 基础配置
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config/treasurego_db_config.php';

try {
    if (!isset($pdo)) {
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
            u2.User_Profile_Image as reportedUserImage,
            
            -- 关联商品信息
            r.Reported_Item_ID as reportedItemId,
            CASE 
                WHEN r.Report_Type = 'product' AND p.Product_Title IS NOT NULL THEN p.Product_Title
                ELSE u2.User_Username 
            END as targetName,

            -- 获取商品主图 (用于列表左侧图标)
            (SELECT Image_URL 
             FROM Product_Images pi 
             WHERE pi.Product_ID = p.Product_ID 
             LIMIT 1
            ) as productImage,

            -- 【核心修改】获取举报证据图片 (用于详情弹窗)
            -- 将多张图片的路径用逗号拼接成一个字符串
            GROUP_CONCAT(re.File_Path) as evidence_paths

        FROM Report r
        LEFT JOIN User u1 ON r.Reporting_User_ID = u1.User_ID
        LEFT JOIN User u2 ON r.Reported_User_ID = u2.User_ID
        LEFT JOIN Product p ON r.Reported_Item_ID = p.Product_ID
        -- 【核心修改】关联证据表
        LEFT JOIN Report_Evidence re ON r.Report_ID = re.Report_ID
        
        -- 【核心修改】必须分组，否则 GROUP_CONCAT 会报错或只返回一行
        GROUP BY r.Report_ID
        
        ORDER BY r.Report_Creation_Date DESC";

    $listStmt = $pdo->query($listSql);
    $rawReports = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. 数据后处理 (格式化)
    $reports = [];
    foreach ($rawReports as $row) {
        // 处理证据图片：将字符串 "path1.jpg,path2.jpg" 转为数组 ["path1.jpg", "path2.jpg"]
        if (!empty($row['evidence_paths'])) {
            $row['evidence'] = explode(',', $row['evidence_paths']);
        } else {
            $row['evidence'] = [];
        }

        // 移除不需要发送给前端的临时字段
        unset($row['evidence_paths']);

        $reports[] = $row;
    }

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