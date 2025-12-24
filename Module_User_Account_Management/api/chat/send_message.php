<?php
header('Content-Type: application/json');
require_once '../../api/config/treasurego_db_config.php';
require_once '../../includes/auth.php';

start_session_safe();

if (!is_logged_in()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$current_user_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);

$receiver_id = $input['receiver_id'] ?? null;
$product_id = $input['product_id'] ?? null;
$message_content = $input['message'] ?? null;

// Debug logging
// error_log("Send Message Debug: User=$current_user_id, Receiver=$receiver_id, Product=$product_id, Content=$message_content");

if (!$receiver_id || empty($message_content)) {
    echo json_encode(['status' => 'error', 'message' => 'Receiver ID and Message content are required']);
    exit();
}

try {
    $pdo = getDBConnection();
    
    // Ensure product_id is treated as NULL if empty
    if (empty($product_id)) {
        $product_id = null;
    }

    $sql = "
        INSERT INTO Message (Message_Sender_ID, Message_Reciver_ID, Product_ID, Message_Content) 
        VALUES (?, ?, ?, ?)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$current_user_id, $receiver_id, $product_id, $message_content]);

    echo json_encode(['status' => 'success', 'message' => 'Message sent']);

} catch (Exception $e) {
    error_log("Send Message Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>