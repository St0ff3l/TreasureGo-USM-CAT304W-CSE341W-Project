<?php
// 文件位置: Module_Platform_Governance_AI_Services/api/support.php

// 1. 开启 Session (读取登录状态)
session_start();

// 2. 调试配置
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 3. 引入配置
require_once __DIR__ . '/config/treasurego_db_config.php';
require_once __DIR__ . '/config/DeepSeekService.php';

// 4. CORS 设置
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    // =================================================================
    // 🛑 核心修改 1: 严格检查登录状态 (游客禁止入内)
    // =================================================================
    if (!isset($_SESSION['user_id'])) {
        // 如果 Session 里没有 user_id，直接报错，终止程序
        http_response_code(401); // 401 代表未授权
        echo json_encode([
            'error' => '用户未登录',
            'message' => 'Guest access denied. Please login first.',
            'redirect' => '../../Module_User_Account_Management/pages/login.php' // 告诉前端去哪登录
        ]);
        exit; // 🚨 停止往下执行！不调用 AI，不存数据库
    }

    // 获取当前登录的用户 ID
    $currentUserId = $_SESSION['user_id'];

    // -----------------------------------------------------------------

    // 5. 接收前端数据
    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true);

    if (!isset($input['messages'])) {
        throw new Exception("缺少 'messages' 参数");
    }

    $messages = $input['messages'];
    $lastUserMessage = end($messages)['content']; // 用户的问题

    // =================================================================
    // 🚀 核心逻辑 2: 调用 AI 服务
    // =================================================================
    $aiService = new DeepSeekService();
    $result = $aiService->sendMessage($messages);

    // 获取 AI 的文本回复
    $aiResponseText = $result['choices'][0]['message']['content'] ?? "System Error: No AI response.";

    // =================================================================
    // 💾 核心逻辑 3: 双向记录入库 (用户问题 + AI回答)
    // =================================================================
    if (isset($conn) && $conn) {
        // A. 意图识别
        $intent = 'General_Inquiry';
        if (strpos($lastUserMessage, '退款') !== false) $intent = 'Refund';
        elseif (strpos($lastUserMessage, '发货') !== false) $intent = 'Shipping';
        elseif (strpos($lastUserMessage, '密码') !== false) $intent = 'Account';
        elseif (strpos($lastUserMessage, '投诉') !== false) $intent = 'Complaint';

        // B. 插入数据库
        // 注意看这里：AILog_Response 字段对应的就是 $aiResponseText
        $sql = "INSERT INTO AIChatLog (AILog_User_Query, AILog_Response, AILog_Intent_Recognized, AILog_Is_Resolved, AILog_Timestamp, User_ID) VALUES (?, ?, ?, 0, NOW(), ?)";

        $stmt = $conn->prepare($sql);

        if ($stmt) {
            // 绑定参数: s(Query), s(Response), s(Intent), i(UserID)
            $stmt->bind_param("sssi", $lastUserMessage, $aiResponseText, $intent, $currentUserId);

            if (!$stmt->execute()) {
                error_log("AIChatLog Insert Error: " . $stmt->error);
            }
            $stmt->close();
        }
    }

    // 6. 返回结果给前端
    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>