<?php
// Module_User_Account_Management/api/submit_review.php

// ---------------------------------------------------------
// 🔥 修复区域开始：为了让你的逻辑能跑起来，必须添加这些配置代码
// ---------------------------------------------------------

// 1. 设置错误显示，方便调试
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

// 2. 自动寻找配置文件 (不修改原有引用，而是用这个替代)
$configFileName = 'treasurego_db_config.php';
$currentDir = __DIR__;
$foundPath = null;

// 向上查找配置文件
for ($i = 0; $i < 5; $i++) {
    // 尝试常见路径
    if (file_exists($currentDir . '/Config/' . $configFileName)) { $foundPath = $currentDir . '/Config/' . $configFileName; break; }
    if (file_exists($currentDir . '/config/' . $configFileName)) { $foundPath = $currentDir . '/config/' . $configFileName; break; }
    if (file_exists($currentDir . '/../api/config/' . $configFileName)) { $foundPath = $currentDir . '/../api/config/' . $configFileName; break; }
    $currentDir = dirname($currentDir);
}
// 硬编码救命路径 (针对你的项目结构)
if (!$foundPath) {
    $manualPath = __DIR__ . '/../../Module_Product_Ecosystem/api/config/treasurego_db_config.php';
    if (file_exists($manualPath)) $foundPath = $manualPath;
}

if ($foundPath) {
    require_once $foundPath;
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Config file not found']);
    exit;
}

// 3. 🔥 关键修复：添加一个替身函数
// 你的逻辑里用的是 getDBConnection，但配置文件里是 getDatabaseConnection
// 这里加一个“桥梁”，这样就不用改你下面的核心代码了
if (!function_exists('getDBConnection')) {
    function getDBConnection() {
        if (function_exists('getDatabaseConnection')) {
            return getDatabaseConnection();
        }
        throw new Exception("Database connection function missing.");
    }
}

// ---------------------------------------------------------
// 🔥 修复区域结束。以下是你要求的原始内容 (逻辑未动)
// ---------------------------------------------------------

// 注释掉这行，因为上面已经加载了配置，且 auth.php 可能不存在
// require_once __DIR__ . '/config/treasurego_db_config.php';
// require_once __DIR__ . '/../includes/auth.php';

// 1. Check Auth
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$reviewer_id = $_SESSION['user_id'];

try {
    // 2. Get Input
    $input = json_decode(file_get_contents('php://input'), true);

    $order_id = $input['order_id'] ?? null;
    $target_user_id = $input['target_user_id'] ?? null;
    $scores = $input['scores'] ?? []; // Array of 5 integers (0-5)
    $comment = trim($input['comment'] ?? '');

    if (!$order_id || !$target_user_id || !is_array($scores) || count($scores) !== 5) {
        throw new Exception("Invalid input data.");
    }

    // Validate scores (0-5)
    foreach ($scores as $s) {
        if (!is_numeric($s) || $s < 0 || $s > 5) {
            throw new Exception("Scores must be between 0 and 5.");
        }
    }

    $pdo = getDBConnection(); // 这里现在可以正常工作了
    $pdo->beginTransaction();

    // 3. Check if already reviewed (Edit Mode vs Create Mode)
    $stmt = $pdo->prepare("SELECT Reviews_ID, Reviews_Rating FROM Review WHERE Order_ID = ? AND User_ID = ?");
    $stmt->execute([$order_id, $reviewer_id]);
    $existing_review = $stmt->fetch(PDO::FETCH_ASSOC);

    // 4. Calculate Total Score
    $total_score = array_sum($scores); // Max 25

    // Lock User Row
    $stmt = $pdo->prepare("SELECT User_Average_Rating, User_Review_Count FROM User WHERE User_ID = ? FOR UPDATE");
    $stmt->execute([$target_user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception("Target user not found.");
    }

    $current_rating = floatval($user['User_Average_Rating']);
    $count = intval($user['User_Review_Count']);
    $new_rating = $current_rating;
    $new_count = $count;

    if ($existing_review) {
        // --- EDIT MODE ---
        // 1. Revert old impact
        $old_score = intval($existing_review['Reviews_Rating']);

        if ($old_score < 15) {
            // Revert penalty
            $new_rating += 0.1;
        } else {
            // Revert weighted average
            if ($count > 1) {
                $old_rating_5scale = $old_score / 5.0;
                $new_rating = (($current_rating * $count) - $old_rating_5scale) / ($count - 1);
            } else {
                $new_rating = 5.0; // Reset to default
            }
        }

        // Count stays same
        $new_count = $count;

        // 2. Apply new impact
        $current_rating = $new_rating;
        $count = $count - 1;

        // Update Review Record
        $sqlUpdate = "UPDATE Review SET Reviews_Rating = ?, Reviews_Comment = ?, Reviews_Created_At = CURRENT_TIMESTAMP WHERE Reviews_ID = ?";
        $stmt = $pdo->prepare($sqlUpdate);
        $stmt->execute([$total_score, $comment, $existing_review['Reviews_ID']]);

    } else {
        // --- CREATE MODE ---
        // Insert Review
        $sqlInsert = "INSERT INTO Review (Order_ID, User_ID, Target_User_ID, Reviews_Rating, Reviews_Comment) 
                      VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sqlInsert);
        $stmt->execute([$order_id, $reviewer_id, $target_user_id, $total_score, $comment]);
    }

    // 5. Apply New Impact
    if ($total_score < 15) {
        // Penalty Rule: -0.1
        $new_rating = max(0, $current_rating - 0.1);
    } else {
        // Standard Logic
        $this_rating_5scale = $total_score / 5.0;

        if ($count == 0) {
            $new_rating = $this_rating_5scale;
        } else {
            $new_rating = (($current_rating * $count) + $this_rating_5scale) / ($count + 1);
        }
    }

    $new_count = ($existing_review) ? $user['User_Review_Count'] : ($user['User_Review_Count'] + 1);

    // Update User Table
    $stmt = $pdo->prepare("UPDATE User SET User_Average_Rating = ?, User_Review_Count = ? WHERE User_ID = ?");
    $stmt->execute([$new_rating, $new_count, $target_user_id]);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Review submitted successfully.', 'new_rating' => $new_rating]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500); // 改为 500 以便前端捕获
    echo json_encode(['success' => false, 'message' => 'Server Error: ' . $e->getMessage()]);
}
?>