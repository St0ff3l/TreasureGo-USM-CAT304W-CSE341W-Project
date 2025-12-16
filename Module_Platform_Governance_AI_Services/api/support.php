<?php
// ============================================
// TreasureGO AI Support API (最终正式版)
// ============================================

session_start();
// 生产环境建议关闭 display_errors，但在调试期开启无妨
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. 引入刚才修好的配置
require_once __DIR__ . '/config/treasurego_db_config.php';
require_once __DIR__ . '/config/DeepSeekService.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    // -----------------------------------------------------------------
    // 1. 权限检查
    // -----------------------------------------------------------------
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Auth Required']);
        exit;
    }

    $currentUserId = $_SESSION['user_id'];

    // -----------------------------------------------------------------
    // 2. 接收数据 & 调用 AI
    // -----------------------------------------------------------------
    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true);

    if (!isset($input['messages'])) {
        throw new Exception("Missing 'messages' parameter");
    }

    $messages = $input['messages'];
    $lastUserMessage = end($messages)['content'];

    // 调用 DeepSeek
    $aiService = new DeepSeekService();
    $result = $aiService->sendMessage($messages);
    $aiResponseText = $result['choices'][0]['message']['content'] ?? "System Error";

    // -----------------------------------------------------------------
    // 3. 数据库写入 (PDO 写法)
    // -----------------------------------------------------------------
    $insertedLogId = null;

    // 兼容性处理
    if (!isset($conn) && isset($pdo)) { $conn = $pdo; }

    if (isset($conn)) {
        // A. 意图识别
        $intent = 'General_Inquiry';
        // 兼容 PHP 8.0 之前的写法，防止报错
        if (stripos($lastUserMessage, 'refund') !== false || stripos($lastUserMessage, '退款') !== false) $intent = 'Refund';
        elseif (stripos($lastUserMessage, 'ship') !== false || stripos($lastUserMessage, '发货') !== false) $intent = 'Shipping';

        // B. 准备 SQL
        // 这里的问号数量 (4个) 必须和下面 execute 里的变量数量 (4个) 一致
        $sql = "INSERT INTO AIChatLog 
                (AILog_User_Query, AILog_Response, AILog_Intent_Recognized, AILog_Is_Resolved, AILog_Timestamp, User_ID) 
                VALUES (?, ?, ?, 0, NOW(), ?)";

        $stmt = $conn->prepare($sql);

        if ($stmt) {
            // C. 执行
            $success = $stmt->execute([
                $lastUserMessage,
                $aiResponseText,
                $intent,
                $currentUserId
            ]);

            if ($success) {
                $insertedLogId = $conn->lastInsertId();
            } else {
                // 记录错误但不中断 AI 回复
                error_log("DB Insert Error: " . print_r($stmt->errorInfo(), true));
            }
        }
    }

    // 4. 返回结果
    $result['db_log_id'] = $insertedLogId;
    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>