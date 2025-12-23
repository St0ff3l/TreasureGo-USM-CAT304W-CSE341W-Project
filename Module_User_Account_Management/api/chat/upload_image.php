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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit();
}

$receiver_id = $_POST['receiver_id'] ?? null;
$product_id = $_POST['product_id'] ?? null;
if (empty($product_id)) $product_id = null;

if (!$receiver_id) {
    echo json_encode(['status' => 'error', 'message' => 'Receiver ID required']);
    exit();
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['status' => 'error', 'message' => 'No image uploaded or upload error']);
    exit();
}

$file = $_FILES['image'];
$allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$max_size = 5 * 1024 * 1024; // 5MB

if (!in_array($file['type'], $allowed_types)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid file type. Only JPG, PNG, GIF, WEBP allowed.']);
    exit();
}

if ($file['size'] > $max_size) {
    echo json_encode(['status' => 'error', 'message' => 'File too large. Max 5MB.']);
    exit();
}

// Create directory if not exists
$upload_dir = '../../../Public_Assets/chat_images/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Generate unique filename
$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = uniqid('chat_', true) . '.' . $ext;
$filepath = $upload_dir . $filename;
$db_path = '../../Public_Assets/chat_images/' . $filename; // Path stored in DB (relative to page or absolute? Let's stick to relative to project root or consistent with other images)

// Actually, let's store relative to project root if possible, or relative to the chat.php location.
// In chat.php, we are in Module_User_Account_Management/pages/
// The image is in Public_Assets/chat_images/
// So from chat.php, it is ../../Public_Assets/chat_images/
// Let's store the path that is easy to use.
// Let's store: "Public_Assets/chat_images/filename" and handle the relative part in frontend or store "../../Public_Assets/chat_images/filename"
// The product images seem to be stored as "Module_Product_Ecosystem/Public_Product_Images/..."
// Let's store "../../Public_Assets/chat_images/" . $filename to be safe for now as it works with the current structure.

if (move_uploaded_file($file['tmp_name'], $filepath)) {
    try {
        $pdo = getDBConnection();
        $sql = "
            INSERT INTO Message (Message_Sender_ID, Message_Reciver_ID, Product_ID, Message_Content, Message_Type) 
            VALUES (?, ?, ?, ?, 'image')
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$current_user_id, $receiver_id, $product_id, $db_path]);
        
        echo json_encode(['status' => 'success', 'message' => 'Image sent', 'data' => ['url' => $db_path]]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save file']);
}
?>