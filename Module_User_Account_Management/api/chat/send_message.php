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
$message_content = $input['message'] ?? null;

if (!$receiver_id || empty($message_content)) {
    echo json_encode(['status' => 'error', 'message' => 'Receiver ID and Message content are required']);
    exit();
}

try {
    $pdo = getDBConnection();
    
    $sql = "
        INSERT INTO Message (Message_Sender_ID, Message_Reciver_ID, Message_Content) 
        VALUES (?, ?, ?)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$current_user_id, $receiver_id, $message_content]);

    echo json_encode(['status' => 'success', 'message' => 'Message sent']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
