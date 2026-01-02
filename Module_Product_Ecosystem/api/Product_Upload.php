<?php
// api/Product_Upload.php

// 1. Start Session (must be placed on the first line)
session_start();

// Include database configuration
require_once __DIR__ . '/config/treasurego_db_config.php';

// Include AI service file
require_once __DIR__ . '/config/Gemini_Service.php';

header('Content-Type: application/json');

try {
    // 2. Get database connection
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        throw new Exception("Unable to connect to remote database");
    }

    // 3. Only allow POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed']);
        exit();
    }

    // 4. Security check: Determine if the user is logged in
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Please login before listing a product']);
        exit();
    }

    // Get User_ID from Session
    $user_id = $_SESSION['user_id'];

    // 5. Get form data
    $product_title = trim($_POST['product_name'] ?? '');
    $price = floatval($_POST['price'] ?? 0);
    $condition = trim($_POST['condition'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['address'] ?? 'Online');
    $category_id = intval($_POST['category_id'] ?? 100000005);

    // Get delivery method, default is both
    $delivery_method = $_POST['delivery_method'] ?? 'both';
    // Simple whitelist validation
    if (!in_array($delivery_method, ['meetup', 'shipping', 'both'])) {
        $delivery_method = 'both';
    }

    // Data validation
    if (empty($product_title)) throw new Exception("Product name cannot be empty");
    if ($price <= 0) throw new Exception("Price must be greater than 0");
    if (empty($condition)) throw new Exception("Please select product condition");
    if (empty($description)) throw new Exception("Product description cannot be empty");
    if (empty($location)) throw new Exception("Please provide transaction address");

    // =========================================================
    // 6. Process image files
    // =========================================================
    $image_paths = [];
    $all_physical_image_paths = []; // All local paths to pass to AI

    // Physical storage path
    $upload_base_dir = '../Public_Product_Images/';

    // Database path prefix
    $db_path_prefix = 'Module_Product_Ecosystem/Public_Product_Images/';

    if (!is_dir($upload_base_dir)) {
        if (!mkdir($upload_base_dir, 0777, true)) {
            throw new Exception("Server Error: Unable to create image upload directory, please check folder permissions.");
        }
    }

    if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $file_count = count($_FILES['images']['name']);

        for ($i = 0; $i < $file_count; $i++) {
            $error_code = $_FILES['images']['error'][$i];

            if ($error_code === UPLOAD_ERR_INI_SIZE || $error_code === UPLOAD_ERR_FORM_SIZE) {
                throw new Exception("Upload Failed: Image " . $_FILES['images']['name'][$i] . " is too large and exceeds server limits.");
            }

            if ($error_code === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['images']['tmp_name'][$i];
                $file_name = $_FILES['images']['name'][$i];
                $file_type = $_FILES['images']['type'][$i];

                if (!in_array($file_type, $allowed_types)) {
                    continue;
                }

                $ext = pathinfo($file_name, PATHINFO_EXTENSION);
                $new_filename = 'prod_' . time() . '_' . uniqid() . '.' . $ext;

                $destination = $upload_base_dir . $new_filename;

                if (move_uploaded_file($file_tmp, $destination)) {
                    $image_paths[] = $db_path_prefix . $new_filename;

                    // Record the physical paths of all successfully uploaded images for AI use
                    $all_physical_image_paths[] = $destination;
                } else {
                    throw new Exception("Upload Failed: Unable to save image file. Please contact administrator to check folder write permissions.");
                }
            } else {
                throw new Exception("Upload Error, Error Code: " . $error_code);
            }
        }
    }

    // =========================================================
    // Add AI automatic audit logic
    // =========================================================

    // Default status (if no AI, or AI is down)
    $final_product_status = 'Pending';       // Default pending review
    $final_review_status = 'Pending';
    $ai_audit_comment = NULL;

    try {
        // Call the encapsulated function
        // Note: Passing the physical path array $all_physical_image_paths
        $aiResult = analyzeProductWithAI($product_title, $description, $price, $all_physical_image_paths);

        if ($aiResult) {
            // Strategy: Risk score < 50 and suggestion is Approve -> List directly
            // (Originally < 30, now relaxed to 50 to avoid flagging low-risk products)
            if ($aiResult['suggestion'] === 'Approve' && $aiResult['risk_score'] < 50) {
                $final_product_status = 'Active';      // Direct listing
                $final_review_status = 'approved';     // Review status approved
                $ai_audit_comment = NULL;              // Clear comments
            } else {
                // High risk -> Keep Pending, write reason
                $final_product_status = 'Pending';
                $final_review_status = 'pending';
                $ai_audit_comment = "[AI Auto-Flagged] Risk Score:" . $aiResult['risk_score'] . "%. Reason: " . $aiResult['reason'];
            }
        }
    } catch (Exception $aiEx) {
        // If AI fails, it should not block product listing, but switch to manual review
        // error_log("AI Audit Failed: " . $aiEx->getMessage());
        $final_product_status = 'Pending';
        $ai_audit_comment = "AI Service Unavailable, flagged for manual review.";
    }

    // =========================================================

    // 7. Start transaction
    $pdo->beginTransaction();

    // 8. Insert product
    // SQL statement changes:
    //  - Product_Status is no longer hardcoded 'Active', but becomes ?
    //  - Product_Review_Status is no longer hardcoded 'Pending', but becomes ?
    //  - Added Product_Review_Comment field to store AI rejection reasons
    $sql_product = "INSERT INTO Product (
        Product_Title,
        Product_Description,
        Product_Price,
        Product_Condition,
        Product_Status,
        Product_Created_Time,
        Product_Location,
        Product_Review_Status,
        Product_Review_Comment, 
        Delivery_Method,
        User_ID,
        Category_ID
    ) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)";

    $stmt = $pdo->prepare($sql_product);
    $stmt->execute([
        $product_title,         // 1. Title
        $description,           // 2. Description
        $price,                 // 3. Price
        $condition,             // 4. Condition
        $final_product_status,  // 5. Dynamic Status (Active/Pending)
        $location,              // 6. Location
        $final_review_status,   // 7. Dynamic Review Status (approved/pending)
        $ai_audit_comment,      // 8. AI Review Comment
        $delivery_method,       // 9. Delivery Method
        $user_id,               // 10. User ID
        $category_id            // 11. Category ID
    ]);

    $product_id = $pdo->lastInsertId();

    // 9. Insert image paths
    if (!empty($image_paths)) {
        $sql_image = "INSERT INTO Product_Images (
            Product_ID,
            Image_URL,
            Image_is_primary,
            Image_Upload_Time
        ) VALUES (?, ?, ?, NOW())";

        $stmt_img = $pdo->prepare($sql_image);

        foreach ($image_paths as $index => $path) {
            $is_primary = ($index === 0) ? 1 : 0;
            $stmt_img->execute([$product_id, $path, $is_primary]);
        }
    }

    $pdo->commit();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Product listed successfully!' . ($final_product_status === 'Pending' ? ' (AI detected risk, flagged for manual review)' : ''),
        'product_id' => $product_id,
        'status' => $final_product_status // Return product status (Active or Pending)
    ]);

} catch (Exception $e) {
    // 10. If error occurs, rollback transaction
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (http_response_code() !== 401) {
        http_response_code(400);
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>