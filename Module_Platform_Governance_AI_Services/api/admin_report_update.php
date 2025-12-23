<?php
header('Content-Type: application/json');
require_once 'config/treasurego_db_config.php';
session_start();

// 获取管理员ID
$adminId = $_SESSION['admin_id'] ?? 100000001;

$data = json_decode(file_get_contents("php://input"), true);
$reportId = $data['id'] ?? null;
$status   = $data['status'] ?? null;
$reply    = $data['reply'] ?? '';
// 确保 shouldBan 是布尔值
$shouldBan = isset($data['shouldBan']) && ($data['shouldBan'] === true || $data['shouldBan'] === 'true');

try {
    if (!$reportId || !$status) throw new Exception("Missing parameters.");

    $pdo->beginTransaction();

    $actionId = null;

    // 只有在 Resolved 且勾选了封号时执行
    if ($status === 'Resolved' && $shouldBan) {
        // A. 查出被举报人
        $stmtSearch = $pdo->prepare("SELECT Reported_User_ID FROM Report WHERE Report_ID = ?");
        $stmtSearch->execute([$reportId]);
        $reportData = $stmtSearch->fetch();

        if (!$reportData) throw new Exception("Report record not found.");
        $targetUserId = $reportData['Reported_User_ID'];

        // B. 插入处罚记录 (确保 source 是小写的 'report')
        $sqlAction = "INSERT INTO Administrative_Action 
                      (Admin_Action_Type, Admin_Action_Reason, Admin_Action_Start_Date, 
                       Admin_Action_Final_Resolution, Admin_ID, Target_User_ID, Admin_Action_Source) 
                      VALUES ('Ban', ?, NOW(), 'Account banned via Report Center', ?, ?, 'report')";
        $stmtAction = $pdo->prepare($sqlAction);
        $stmtAction->execute([$reply, $adminId, $targetUserId]);

        $actionId = $pdo->lastInsertId();

        if (!$actionId) throw new Exception("Failed to insert into Administrative_Action.");

        // C. 更新用户状态
        $sqlUser = "UPDATE User SET User_Status = 'banned' WHERE User_ID = ?";
        $pdo->prepare($sqlUser)->execute([$targetUserId]);
    }

    // D. 更新举报表 (即便不封号也要执行)
    $sqlUpdateReport = "UPDATE Report SET Report_Status = ?, Admin_Action_ID = ? WHERE Report_ID = ?";
    $pdo->prepare($sqlUpdateReport)->execute([$status, $actionId, $reportId]);

    $pdo->commit();
    echo json_encode(['success' => true, 'actionId' => $actionId]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>