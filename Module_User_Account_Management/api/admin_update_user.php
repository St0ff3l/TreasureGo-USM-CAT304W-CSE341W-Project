<?php
// api/admin_update_user.php
header('Content-Type: application/json');
require_once '../api/config/treasurego_db_config.php';
require_once '../includes/auth.php';
require_once '../includes/utils.php';

start_session_safe();

if (!is_admin()) {
    http_response_code(403);
    jsonResponse(false, 'Admin access required');
}

$input = getJsonInput();
$userId = $input['user_id'] ?? null;
$status = $input['status'] ?? null;
$role = $input['role'] ?? null;

if (!$userId) {
    jsonResponse(false, 'Missing user_id');
}

try {
    $pdo = getDBConnection();
    
    if ($status) {
        $validStatuses = ['active', 'pending', 'banned'];
        if (!in_array($status, $validStatuses)) {
            jsonResponse(false, 'Invalid status');
        }
        $stmt = $pdo->prepare("UPDATE User SET User_Status = ? WHERE User_ID = ?");
        $stmt->execute([$status, $userId]);
    }

    if ($role) {
        $validRoles = ['user', 'admin'];
        if (!in_array($role, $validRoles)) {
            jsonResponse(false, 'Invalid role');
        }
        $stmt = $pdo->prepare("UPDATE User SET User_Role = ? WHERE User_ID = ?");
        $stmt->execute([$role, $userId]);
    }

    jsonResponse(true, 'User updated successfully');
} catch (Exception $e) {
    jsonResponse(false, 'Database error: ' . $e->getMessage());
}
?>