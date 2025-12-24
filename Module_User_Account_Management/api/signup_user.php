<?php
// api/signup_user.php
// ✅ 修复版：强制验证邮箱成功后再存入数据库

session_start(); // 开启 Session 以读取验证码
error_reporting(E_ALL);
ini_set('display_errors', 0);

function fatal_handler() {
    $error = error_get_last();
    if ($error !== NULL && $error['type'] === E_ERROR) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'PHP Fatal Error: ' . $error['message']]);
        exit;
    }
}
register_shutdown_function("fatal_handler");

header('Content-Type: application/json');

require_once '../api/config/treasurego_db_config.php';
require_once '../includes/utils.php';

try {
    $input = getJsonInput();
    $username = trim($input['username'] ?? '');
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $code = trim($input['code'] ?? '');

    if (empty($username) || empty($email) || empty($password) || empty($code)) {
        jsonResponse(false, 'All fields (including verification code) are required.');
    }

    // 1. 验证 Session 中的验证码
    if (!isset($_SESSION['signup_verify'])) {
        jsonResponse(false, 'Please click "Send Code" first.');
    }

    $verifyData = $_SESSION['signup_verify'];

    // 检查邮箱是否一致
    if ($verifyData['email'] !== $email) {
        jsonResponse(false, 'Email mismatch. Please resend code.');
    }

    // 检查过期
    if (time() > $verifyData['expires_at']) {
        jsonResponse(false, 'Verification code expired. Please resend.');
    }

    // 检查验证码
    if (!password_verify($code, $verifyData['code_hash'])) {
        jsonResponse(false, 'Invalid verification code.');
    }

    // =================================================
    // 验证通过，开始存入数据库
    // =================================================
    $pdo = getDBConnection();

    // 2. 再次检查邮箱是否已被注册 (防止并发注册)
    $stmt = $pdo->prepare("SELECT User_ID FROM User WHERE User_Email = ? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        jsonResponse(false, 'Email already registered.');
    }

    // 3. 创建用户 (直接设为 active 和 verified)
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    
    // 注意：这里直接设为 'active' 和 User_Email_Verified = 1
    $sql = "INSERT INTO User (User_Username, User_Email, User_Password_Hash, User_Role, User_Status, User_Email_Verified, User_Created_At)
            VALUES (?, ?, ?, 'user', 'active', 1, NOW())";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$username, $email, $passwordHash]);
    
    // 4. 清除 Session
    unset($_SESSION['signup_verify']);

    jsonResponse(true, 'Signup successful! You can now login.');

} catch (Exception $e) {
    jsonResponse(false, 'System error: ' . $e->getMessage());
}
?>
