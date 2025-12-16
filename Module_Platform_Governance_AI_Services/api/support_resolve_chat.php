<?php
// ============================================
// API: 更新 "问题是否解决" 的状态
// 文件路径: Module_Platform_Governance_AI_Services/api/support_resolve_chat.php
// ============================================

session_start();
require_once __DIR__ . '/config/treasurego_db_config.php';

header("Content-Type: application/json; charset=UTF-8");

// 1. 权限检查
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Auth Required']);
    exit;
}

// 2. 接收数据
$input = json_decode(file_get_contents('php://input'), true);
$logId = $input['log_id'] ?? null;
$isResolved = isset($input['is_resolved']) ? (int)$input['is_resolved'] : 0;
$userId = $_SESSION['user_id'];

// 3. 兼容性处理 (防止 config 里只有 $pdo 没有 $conn)
if (!isset($conn) && isset($pdo)) {
    $conn = $pdo;
}

if (!$logId || !isset($conn)) {
    echo json_encode(['success' => false, 'message' => 'Invalid Request or DB Error']);
    exit;
}

try {
    // 4. 更新数据库
    // 只有当 Log ID 匹配且 User ID 也是当前用户时，才允许修改 (安全检查)
    $sql = "UPDATE AIChatLog SET AILog_Is_Resolved = ? WHERE AILog_ID = ? AND User_ID = ?";
    $stmt = $conn->prepare($sql);

    $success = $stmt->execute([$isResolved, $logId, $userId]);

    if ($success) {
        // 检查是否真的更新了一行（防止用户改别人的记录）
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true]);
        } else {
            // 虽然执行成功，但没影响行数 (可能是 ID 不对或已经是这个状态)
            echo json_encode(['success' => true, 'message' => 'No changes made']);
        }
    } else {
        $error = $stmt->errorInfo();
        echo json_encode(['success' => false, 'error' => $error[2]]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>