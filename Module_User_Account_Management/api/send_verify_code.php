<?php
// api/send_verify_code.php
session_start(); // 开启 Session 以存储临时验证码
header('Content-Type: application/json');

require_once '../api/config/treasurego_db_config.php';
require_once '../includes/utils.php';
require_once '../includes/sendgrid_mailer.php';

$input = getJsonInput();
$email = trim($input['email'] ?? '');
$purpose = $input['purpose'] ?? 'signup'; // signup 或 reset_password

if (empty($email)) {
    jsonResponse(false, 'Email is required.');
}

try {
    $pdo = getDBConnection();

    // =================================================
    // 场景 A: 注册 (Signup) - 用户还不存在
    // =================================================
    if ($purpose === 'signup') {
        // 1. 检查邮箱是否已被注册
        $stmt = $pdo->prepare("SELECT User_ID FROM User WHERE User_Email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            jsonResponse(false, 'Email already registered. Please login.');
        }

        // 2. 生成验证码
        $code = generateVerificationCode();
        $codeHash = password_hash($code, PASSWORD_BCRYPT);
        $expiresAt = time() + 600; // 10分钟后过期

        // 3. 存入 Session (不存数据库，因为没有 User_ID)
        $_SESSION['signup_verify'] = [
            'email' => $email,
            'code_hash' => $codeHash,
            'expires_at' => $expiresAt
        ];

        // 4. 发送邮件
        $subject = "Verify Your Email - TreasureGo";
        $body = "<h2>Welcome to TreasureGo!</h2><p>Your verification code is: <b style='font-size: 24px;'>$code</b></p><p>This code expires in 10 minutes.</p>";

        if (sendEmail($email, $subject, $body)) {
            jsonResponse(true, 'Verification code sent.');
        } else {
            jsonResponse(false, 'Failed to send email.');
        }
    } 
    
    // =================================================
    // 场景 B: 重置密码 (Reset Password) - 用户必须存在
    // =================================================
    else {
        // 1. 确认用户存在
        $stmt = $pdo->prepare("SELECT User_ID FROM User WHERE User_Email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            // 为了安全，也可以提示发送成功，防止枚举邮箱
            jsonResponse(false, 'User not found.');
        }
        $userId = $user['User_ID'];

        // 2. 限频检查 (60秒内不能重发)
        $stmtCheck = $pdo->prepare("SELECT EV_Created_At FROM Email_Verification WHERE EV_Email = ? AND EV_Purpose = ? ORDER BY EV_Created_At DESC LIMIT 1");
        $stmtCheck->execute([$email, $purpose]);
        $lastEv = $stmtCheck->fetch();

        if ($lastEv && (time() - strtotime($lastEv['EV_Created_At']) < 60)) {
            jsonResponse(false, 'Please wait 60 seconds before resending.');
        }

        // 3. 生成新码
        $code = generateVerificationCode();
        $codeHash = password_hash($code, PASSWORD_BCRYPT);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        // 4. 存入数据库
        $sqlEV = "INSERT INTO Email_Verification (User_ID, EV_Email, EV_Code, EV_Purpose, EV_Expires_At) VALUES (?, ?, ?, ?, ?)";
        $stmtEV = $pdo->prepare($sqlEV);
        $stmtEV->execute([$userId, $email, $codeHash, $purpose, $expiresAt]);

        // 5. 发送邮件
        $subject = "Reset Your Password";
        $body = "<p>Your verification code is: <b style='font-size: 24px;'>$code</b></p><p>Expires in 10 minutes.</p>";

        if (sendEmail($email, $subject, $body)) {
            jsonResponse(true, 'Code sent successfully.');
        } else {
            jsonResponse(false, 'Failed to send email.');
        }
    }

} catch (Exception $e) {
    jsonResponse(false, 'Error: ' . $e->getMessage());
}
?>
