<?php
// 文件位置: Module_Platform_Governance_AI_Services/api/support_human_chat_api.php

header('Content-Type: application/json');

require_once __DIR__ . '/config/treasurego_db_config.php';
require_once __DIR__ . '/../../Module_User_Account_Management/includes/auth.php';

start_session_safe();

// 2. 权限检查
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$pdo = getDatabaseConnection();

if (!$pdo) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

// Support chat is defined as Product_ID IS NULL.

function find_admin_recipient(PDO $pdo, int $user_id): ?int {
    // Prefer the admin the user already talked to most recently; otherwise pick any admin.
    // Use positional parameters to avoid duplicate named-parameter issues on some PDO drivers.
    $sql = "
        SELECT a.User_ID
        FROM User a
        LEFT JOIN (
            SELECT
                CASE
                    WHEN m.Message_Sender_ID = ? THEN m.Message_Reciver_ID
                    ELSE m.Message_Sender_ID
                END AS admin_id,
                MAX(m.Message_Sent_At) AS last_at
            FROM Message m
            JOIN User u ON u.User_ID = CASE
                WHEN m.Message_Sender_ID = ? THEN m.Message_Reciver_ID
                ELSE m.Message_Sender_ID
            END
            WHERE m.Product_ID IS NULL
              AND (m.Message_Sender_ID = ? OR m.Message_Reciver_ID = ?)
              AND u.User_Role = 'admin'
            GROUP BY admin_id
        ) hist ON hist.admin_id = a.User_ID
        WHERE a.User_Role = 'admin'
        ORDER BY hist.last_at IS NULL, hist.last_at DESC, a.User_ID ASC
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    // uid is used 4 times above
    $stmt->execute([$user_id, $user_id, $user_id, $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['User_ID'] : null;
}

// ==========================================
// 🟢 获取聊天记录 (GET)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Get all support messages between this user and ANY admin.
        $sql = "
            SELECT m.*
            FROM Message m
            JOIN User u_sender ON u_sender.User_ID = m.Message_Sender_ID
            JOIN User u_recv ON u_recv.User_ID = m.Message_Reciver_ID
            WHERE m.Product_ID IS NULL
              AND (m.Message_Sender_ID = ? OR m.Message_Reciver_ID = ?)
              AND (u_sender.User_Role = 'admin' OR u_recv.User_Role = 'admin')
            ORDER BY m.Message_Sent_At ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $user_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Mark all admin->user messages as read
        $updateSql = "
            UPDATE Message m
            JOIN User u_sender ON u_sender.User_ID = m.Message_Sender_ID
            SET m.Message_Is_Read = 1
            WHERE m.Product_ID IS NULL
              AND m.Message_Reciver_ID = ?
              AND m.Message_Is_Read = 0
              AND u_sender.User_Role = 'admin'
        ";
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([$user_id]);

        $agent_id = null;
        if (!empty($messages)) {
            $last = $messages[count($messages) - 1];
            // Best-effort: expose the admin id involved in the last message
            $agent_id = ((int)$last['Message_Sender_ID'] === $user_id) ? (int)$last['Message_Reciver_ID'] : (int)$last['Message_Sender_ID'];
        }

        echo json_encode([
            'status' => 'success',
            'data' => $messages,
            'my_id' => $user_id,
            'agent_id' => $agent_id
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ==========================================
// 🔵 发送消息给客服 (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $text = trim($input['message'] ?? '');

    if ($text === '') {
        echo json_encode(['status' => 'error', 'message' => 'Empty message']);
        exit;
    }

    try {
        $admin_id = find_admin_recipient($pdo, $user_id);
        if (!$admin_id) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'No admin available']);
            exit;
        }

        $sql = "
            INSERT INTO Message (Message_Sender_ID, Message_Reciver_ID, Product_ID, Message_Content, Message_Type)
            VALUES (?, ?, NULL, ?, 'text')
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $admin_id, $text]);

        echo json_encode(['status' => 'success', 'agent_id' => $admin_id]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
