<?php
// ============================================
// TreasureGO AI Support API (V11: 智能语言跟随 + 链接修复版)
// ============================================

session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/treasurego_db_config.php';
require_once __DIR__ . '/config/DeepSeekService.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

try {
    // 1. 权限检查
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Auth Required']);
        exit;
    }
    $currentUserId = $_SESSION['user_id'];

    // 2. 接收数据
    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true);
    if (!isset($input['messages'])) { throw new Exception("Missing messages"); }

    $userMessages = $input['messages'];
    $lastUserMessage = trim(end($userMessages)['content']);

    // 3. 数据库连接
    if (!isset($conn) && isset($pdo)) { $conn = $pdo; }
    if (!isset($conn)) { throw new Exception("Database connection failed"); }

    // =========================================================
    // 🚀 特性 A: 极简输入拦截 (输入 "1" 时的处理)
    // 这里保留三语，因为 "1" 无法判断用户语言，三语最稳妥
    // =========================================================
    if (strlen($lastUserMessage) <= 2 || is_numeric($lastUserMessage)) {
        $recSql = "SELECT KB_Question FROM KnowledgeBase ORDER BY RAND() LIMIT 3";
        $recStmt = $conn->query($recSql);
        $questions = $recStmt->fetchAll(PDO::FETCH_COLUMN);

        // 注意：这里使用 \n 换行，前端会自动转为 <br>
        $replyText = "Hello! / 您好！ / Hai!\n";
        $replyText .= "Are you looking for these? 👇\n\n";

        if ($questions) {
            foreach ($questions as $q) {
                $replyText .= "🔹 " . $q . "\n";
            }
        } else {
            $replyText .= "\nPlease type a keyword (e.g., Refund).";
        }

        echo json_encode([
            'choices' => [['message' => ['content' => $replyText]]],
            'db_log_id' => null,
            'show_resolution_buttons' => false
        ]);
        exit;
    }

    // =========================================================
    // 🧠 特性 B: AI 智能回复 (语言跟随 + 自动链接)
    // =========================================================

    // 读取数据
    $intentStr = "";
    $kbStr = "";

    try {
        $stmtIntents = $conn->query("SELECT intent_code, description FROM AI_Intents WHERE is_active = 1");
        while ($row = $stmtIntents->fetch(PDO::FETCH_ASSOC)) {
            $intentStr .= "- " . $row['intent_code'] . ": " . $row['description'] . "\n";
        }
    } catch (Exception $e) {}

    try {
        $stmtKB = $conn->query("SELECT KB_Question, KB_Answer FROM KnowledgeBase");
        while ($row = $stmtKB->fetch(PDO::FETCH_ASSOC)) {
            $kbStr .= "Q: " . $row['KB_Question'] . "\nA: " . $row['KB_Answer'] . "\n---\n";
        }
    } catch (Exception $e) {}

    // --- 构建 Prompt (核心修改) ---
    // 我们不再让 PHP 负责道歉，而是让 AI 根据用户语言道歉
    $systemContent = "You are TreasureGo's AI Customer Support.

【Official Knowledge Base】:
$kbStr

【Language Protocol】:
1. **Detect Language**: Identify if user speaks English, Chinese, or Malay.
2. **Strictly Follow**: Answer in the EXACT SAME language as the user.
3. **Translation**: If KB is English but user asks in Chinese, translate the answer to Chinese.

【Instructions】:
1. Answer ONLY based on the Knowledge Base.
2. If user is greeting, reply politely in their language ({TYPE:CHAT}).
3. **CRITICAL**: If the user asks a business question but it is NOT in the Knowledge Base:
   - You must apologize **in the user's language**.
   - Tell them you cannot find the info and ask them to click the link below.
   - Mark this response as **{TYPE:FALLBACK}**.

【Output Format】:
{INTENT:Intent_Code} {TYPE:Type} Your_Message

Intent List:
$intentStr";

    array_unshift($userMessages, ["role" => "system", "content" => $systemContent]);

    $aiService = new DeepSeekService();
    $result = $aiService->sendMessage($userMessages);
    $rawAiContent = $result['choices'][0]['message']['content'] ?? "{INTENT:General} {TYPE:CHAT} Error";

    // --- 解析结果 ---
    $intent = 'General_Inquiry';
    $msgType = 'CHAT';
    $finalReply = $rawAiContent;

    // 提取标签
    if (preg_match('/\{INTENT:(.*?)\}/', $rawAiContent, $matches)) {
        $intent = trim($matches[1]);
        $finalReply = str_replace($matches[0], '', $finalReply);
    }
    if (preg_match('/\{TYPE:(.*?)\}/', $rawAiContent, $matches)) {
        $msgType = trim($matches[1]);
        $finalReply = str_replace($matches[0], '', $finalReply);
    }

    $finalReply = trim($finalReply);

    // 🛠️ 核心逻辑：如果 AI 说是 FALLBACK，PHP 负责贴上链接
    if (strtoupper($msgType) === 'FALLBACK') {
        // 在 AI 的道歉语后面，追加 HTML 链接
        // 前端修改后，这个 <a> 标签将会变成可点击的按钮
        $finalReply .= "\n\n🔗 <a href='report.html' style='color:#4F46E5; font-weight:bold; text-decoration:underline;'>Click for Human Support / 人工客服</a>";
        $showButtons = false;
    } else {
        // 只有给出 SOLUTION 时才显示 Yes/No 按钮
        $showButtons = (strtoupper($msgType) === 'SOLUTION');
    }

    // --- 存库 ---
    $insertedLogId = null;
    $sqlLog = "INSERT INTO AIChatLog 
            (AILog_User_Query, AILog_Response, AILog_Intent_Recognized, AILog_Is_Resolved, AILog_Timestamp, User_ID) 
            VALUES (?, ?, ?, 0, NOW(), ?)";

    $stmtLog = $conn->prepare($sqlLog);
    if ($stmtLog) {
        $stmtLog->execute([$lastUserMessage, $finalReply, $intent, $currentUserId]);
        $insertedLogId = $conn->lastInsertId();
    }

    // --- 返回 ---
    $result['choices'][0]['message']['content'] = $finalReply;
    $result['db_log_id'] = $insertedLogId;
    $result['show_resolution_buttons'] = $showButtons;

    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>