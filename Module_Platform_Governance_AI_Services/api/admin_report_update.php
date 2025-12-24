<?php
// 文件路径: api/admin_report_update.php

header('Content-Type: application/json');
require_once 'config/treasurego_db_config.php';
session_start();

// 获取管理员ID (根据你的 Session 结构调整)
$adminId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 100000001;

// 1. 获取前端提交的 JSON 数据
$inputPayload = file_get_contents("php://input");
$data = json_decode($inputPayload, true);

// [关键修改] 对应 JS 中的 requestData 键名
$reportId      = $data['Report_ID'] ?? null;
$status        = $data['status'] ?? null;

// [关键修改] 接收两个回复内容
$replyReporter = $data['reply_to_reporter'] ?? ''; // 给举报人
$replyReported = $data['reply_to_reported'] ?? ''; // 给被举报人

// 处理布尔值 (前端 JSON 有时传 true/false，有时传字符串)
$shouldBan     = isset($data['shouldBan']) && ($data['shouldBan'] === true || $data['shouldBan'] === 'true');
$hideProduct   = isset($data['hideProduct']) && ($data['hideProduct'] === true || $data['hideProduct'] === 'true');
$banDuration   = $data['banDuration'] ?? '3d';

try {
    if (!$reportId || !$status) {
        throw new Exception("Missing parameters: Report_ID or status.");
    }

    $pdo = getDatabaseConnection();
    $pdo->beginTransaction();

    $actionId = null; // 用于存储封号记录的 ID (如果有)

    // =========================================================
    // 第一步：获取举报详情 (为了拿到 Target_ID 和 User_ID)
    // =========================================================
    $sqlSearch = "SELECT 
                    Reported_User_ID, 
                    Report_Type, 
                    Reported_Item_ID 
                  FROM Report 
                  WHERE Report_ID = ?";

    $stmtSearch = $pdo->prepare($sqlSearch);
    $stmtSearch->execute([$reportId]);
    $reportData = $stmtSearch->fetch(PDO::FETCH_ASSOC);

    if (!$reportData) {
        throw new Exception("Report record not found.");
    }

    $targetUserId = $reportData['Reported_User_ID'];
    $targetItemId = $reportData['Reported_Item_ID']; // 商品ID

    // =========================================================
    // 第二步：处理封号逻辑
    // =========================================================
    if ($status === 'Resolved' && $shouldBan) {
        $endDate = null;

        // 统一转小写处理
        $durationStr = strtolower($banDuration);

        if ($durationStr === 'forever' || $durationStr === 'permanent') {
            // 永久封禁：EndDate 设为 NULL (或者一个极其遥远的未来，取决于你的数据库设计，通常 NULL 表示永久)
            $endDate = null;
        } else {
            // 处理具体天数
            // intval 会自动把 "3d" 转成 3，把 "365d" 转成 365，把纯数字 "15" 转成 15
            $days = intval($durationStr);

            // 安全保底：如果解析出来是0 (比如误传了空字符)，默认封3天
            if ($days <= 0) $days = 3;

            $endDate = date('Y-m-d H:i:s', strtotime("+$days days"));
        }

        // 插入 Admin Action
        $sqlAction = "INSERT INTO Administrative_Action 
                      (Admin_Action_Type, Admin_Action_Reason, Admin_Action_Start_Date, Admin_Action_End_Date,
                       Admin_Action_Final_Resolution, Admin_ID, Target_User_ID, Admin_Action_Source) 
                      VALUES ('Ban', ?, NOW(), ?, 'Account banned via Report Center', ?, ?, 'report')";
        $stmtAction = $pdo->prepare($sqlAction);
        $stmtAction->execute([$replyReported, $endDate, $adminId, $targetUserId]);

        $actionId = $pdo->lastInsertId();

        // 更新 User 状态
        $sqlUser = "UPDATE User SET User_Status = 'banned' WHERE User_ID = ?";
        $pdo->prepare($sqlUser)->execute([$targetUserId]);
    }

    // =========================================================
    // 第三步：处理商品下架逻辑 (如果勾选)
    // =========================================================
    if ($status === 'Resolved' && $hideProduct && $targetItemId) {
        // 将商品状态设为 'unlisted' (对应前端 Inactive Tab)
        // 将审核状态设为 'rejected' 并附上拒绝理由
        $sqlHide = "UPDATE Product 
                    SET Product_Status = 'unlisted', 
                        Product_Review_Status = 'rejected',
                        Product_Review_Comment = ? 
                    WHERE Product_ID = ?";

        $stmtHide = $pdo->prepare($sqlHide);
        // 这里也使用给被举报人的回复作为理由
        $stmtHide->execute([$replyReported, $targetItemId]);
    }

    // =========================================================
    // 第四步：更新 Report 主表 (核心)
    // =========================================================
    $sqlUpdateReport = "UPDATE Report 
                        SET Report_Status = ?, 
                            Admin_Action_ID = ?, 
                            Report_Reply_To_Reporter = ?, 
                            Report_Reply_To_Reported = ?, 
                            Report_Updated_At = NOW()
                        WHERE Report_ID = ?";

    $stmtUpdate = $pdo->prepare($sqlUpdateReport);
    $stmtUpdate->execute([
        $status,
        $actionId,      // 如果没封号，这里是 null
        $replyReporter, // 存入给举报人的回复
        $replyReported, // 存入给被举报人的回复
        $reportId
    ]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Report processed successfully.']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // 调试用：返回详细错误，生产环境建议只返回 Generic Error
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>