<?php
// Admin Support Dashboard Chat API
// Provides: list conversations, get messages, send message, upload image

header('Content-Type: application/json');

require_once __DIR__ . '/config/treasurego_db_config.php';
require_once __DIR__ . '/../../Module_User_Account_Management/includes/auth.php';

function json_ok($data = []) {
    echo json_encode(['status' => 'success', 'data' => $data]);
    exit();
}
function json_err($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit();
}

start_session_safe();

// Strict gate
require_admin();

$admin_id = (int)get_current_user_id();

$pdo = getDatabaseConnection();
if (!$pdo) {
    json_err('Database connection failed', 500);
}

$action = $_GET['action'] ?? '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'conversations') {
        // Admin sees ALL conversations. Group by (other user, product)
        $sql = "
            SELECT 
                u.User_ID, 
                u.User_Username, 
                u.User_Profile_image as User_Avatar_Url,
                p.Product_Title as Product_Name,
                pi.Image_URL as Product_Image_Url,
                m.Product_ID,
                m.Message_Content,
                m.Message_Type,
                m.Message_Sent_At as Created_At,
                m.Message_Is_Read as Is_Read,
                m.Message_Sender_ID as Sender_ID
            FROM User u
            JOIN (
                SELECT 
                    CASE 
                        WHEN Message_Sender_ID = ? THEN Message_Reciver_ID
                        ELSE Message_Sender_ID
                    END AS Contact_ID,
                    Product_ID,
                    MAX(Message_ID) as Last_Msg_ID
                FROM Message
                GROUP BY Contact_ID, Product_ID
            ) last_msg ON u.User_ID = last_msg.Contact_ID
            JOIN Message m ON m.Message_ID = last_msg.Last_Msg_ID
            LEFT JOIN Product p ON m.Product_ID = p.Product_ID
            LEFT JOIN Product_Images pi ON p.Product_ID = pi.Product_ID AND pi.Image_is_primary = 1
            ORDER BY m.Message_Sent_At DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$admin_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_ok($rows);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'messages') {
        $contact_id = $_GET['contact_id'] ?? null;
        $product_id = $_GET['product_id'] ?? null;

        if (!$contact_id) json_err('Contact ID required');

        $isSupport = ($product_id === null || $product_id === '' || strtolower((string)$product_id) === 'null');

        if ($isSupport) {
            // Public pool support: any admin <-> this user messages (Product_ID IS NULL)
            $sql = "
                SELECT 
                    m.Message_ID,
                    m.Message_Sender_ID as Sender_ID,
                    m.Message_Reciver_ID as Receiver_ID,
                    m.Message_Content,
                    m.Message_Type,
                    m.Message_Sent_At as Created_At,
                    m.Message_Is_Read as Is_Read,
                    m.Product_ID
                FROM Message m
                JOIN User u_sender ON u_sender.User_ID = m.Message_Sender_ID
                JOIN User u_recv ON u_recv.User_ID = m.Message_Reciver_ID
                WHERE m.Product_ID IS NULL
                  AND (
                      (m.Message_Sender_ID = ? AND u_recv.User_Role = 'admin')
                      OR
                      (m.Message_Reciver_ID = ? AND u_sender.User_Role = 'admin')
                  )
            ";
            $params = [$contact_id, $contact_id];

            $sql .= " ORDER BY m.Message_Sent_At ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Mark user's messages read (any user -> this admin messages are handled per-admin; for pool, mark ALL user->admin as read)
            $updateSql = "
                UPDATE Message m
                JOIN User u_recv ON u_recv.User_ID = m.Message_Reciver_ID
                SET m.Message_Is_Read = 1
                WHERE m.Product_ID IS NULL
                  AND m.Message_Sender_ID = ?
                  AND m.Message_Is_Read = 0
                  AND u_recv.User_Role = 'admin'
            ";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([$contact_id]);

            json_ok($messages);
        }

        // Product chat: keep original strict admin<->contact behavior
        $sql = "
            SELECT 
                Message_ID,
                Message_Sender_ID as Sender_ID,
                Message_Reciver_ID as Receiver_ID,
                Message_Content,
                Message_Type,
                Message_Sent_At as Created_At,
                Message_Is_Read as Is_Read,
                Product_ID
            FROM Message
            WHERE ((Message_Sender_ID = ? AND Message_Reciver_ID = ?) 
               OR (Message_Sender_ID = ? AND Message_Reciver_ID = ?))
              AND Product_ID = ?
            ORDER BY Message_Sent_At ASC
        ";
        $params = [$admin_id, $contact_id, $contact_id, $admin_id, $product_id];

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $updateSql = "UPDATE Message SET Message_Is_Read = 1 
                      WHERE Message_Sender_ID = ? AND Message_Reciver_ID = ? AND Message_Is_Read = 0 AND Product_ID = ?";
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([$contact_id, $admin_id, $product_id]);

        json_ok($messages);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'send') {
        $input = json_decode(file_get_contents('php://input'), true);

        $receiver_id = $input['receiver_id'] ?? null;
        $product_id = $input['product_id'] ?? null;
        $message = trim($input['message'] ?? '');

        if (!$receiver_id || $message === '') json_err('receiver_id and message are required');

        if (empty($product_id) || strtolower((string)$product_id) === 'null') {
            $product_id = null;
        }

        $sql = "INSERT INTO Message (Message_Sender_ID, Message_Reciver_ID, Product_ID, Message_Content, Message_Type, Message_Sent_At)
                VALUES (?, ?, ?, ?, 'text', NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$admin_id, $receiver_id, $product_id, $message]);

        json_ok(['message' => 'sent']);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload') {
        if (!isset($_POST['receiver_id'])) json_err('receiver_id required');
        $receiver_id = $_POST['receiver_id'];
        $product_id = $_POST['product_id'] ?? null;
        if (empty($product_id) || strtolower((string)$product_id) === 'null') $product_id = null;

        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            json_err('No image uploaded or upload error');
        }

        $file = $_FILES['image'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $max_size = 5 * 1024 * 1024;

        if (!in_array($file['type'], $allowed_types, true)) {
            json_err('Invalid file type. Only JPG, PNG, GIF, WEBP allowed.');
        }
        if ($file['size'] > $max_size) {
            json_err('File too large. Max 5MB.');
        }

        // Store under this module's assets
        $upload_dir = __DIR__ . '/../assets/chat_images/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid('adminchat_', true) . '.' . $ext;
        $filepath = $upload_dir . $filename;

        // Public path relative to web root (best effort); you may adjust if routing differs.
        $public_path = '/Module_Platform_Governance_AI_Services/assets/chat_images/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            json_err('Failed to save file');
        }

        $sql = "INSERT INTO Message (Message_Sender_ID, Message_Reciver_ID, Product_ID, Message_Content, Message_Type, Message_Sent_At)
                VALUES (?, ?, ?, ?, 'image', NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$admin_id, $receiver_id, $product_id, $public_path]);

        json_ok(['url' => $public_path]);
    }

    json_err('Unknown action', 404);

} catch (Exception $e) {
    json_err($e->getMessage(), 500);
}
