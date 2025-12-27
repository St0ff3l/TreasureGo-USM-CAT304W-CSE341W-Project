<?php
// Module_User_Account_Management/api/submit_review.php

require_once __DIR__ . '/config/treasurego_db_config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

// 1. Check Auth
session_start();
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

    $pdo = getDBConnection();
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
            // Formula: OldTotal = Current * Count
            // NewTotal = OldTotal - (OldScore/5)
            // NewRating = NewTotal / (Count - 1)
            // Note: If count is 1, rating becomes 5.0 (default) or 0?
            if ($count > 1) {
                $old_rating_5scale = $old_score / 5.0;
                $new_rating = (($current_rating * $count) - $old_rating_5scale) / ($count - 1);
            } else {
                $new_rating = 5.0; // Reset to default
            }
        }
        
        // Count stays same (we are just updating)
        $new_count = $count; 

        // 2. Apply new impact (will be done below)
        // We update $current_rating to the "reverted" state for the next step
        $current_rating = $new_rating;
        // For the "Apply" step, we treat it as adding a new review to a set of (Count-1) reviews
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
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
