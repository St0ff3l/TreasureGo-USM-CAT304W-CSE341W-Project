<?php
// 文件位置: Module_Platform_Governance_AI_Services/api/support.php
// 作用：接收 support.html 的请求，调用 config 里的 AI 服务

// 1. 引入核心服务文件
// 注意：因为 support.php 和 config 文件夹在同一级，所以路径是 'config/...'
require_once 'config/DeepSeekService.php';

// 2. 允许跨域 (CORS)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

// 处理预检请求 (浏览器自动发的)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    // 3. 接收前端 JSON 数据
    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true);

    // 简单验证
    if (!isset($input['messages'])) {
        throw new Exception("缺少 'messages' 参数");
    }

    // 4. 实例化服务 (核心逻辑都在这里面)
    // 这里不需要传 Key，因为 Key 藏在 DeepSeekService 类里面了
    $aiService = new DeepSeekService();

    // 5. 获取结果并返回
    $result = $aiService->sendMessage($input['messages']);
    echo json_encode($result);

} catch (Exception $e) {
    // 错误处理
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>