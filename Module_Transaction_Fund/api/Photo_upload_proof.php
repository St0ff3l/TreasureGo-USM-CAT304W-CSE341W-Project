<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

// Define target directory
$target_dir = "../../Public_Assets/proofs/";
if (!file_exists($target_dir)) {
    mkdir($target_dir, 0777, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['file'];
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);

        // Validate extension
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array(strtolower($ext), $allowed)) {
            echo json_encode(['success' => false, 'message' => 'Invalid file type.']);
            exit;
        }

        $filename = 'proof_' . time() . '_' . uniqid() . '.' . $ext;
        $target_file = $target_dir . $filename;

        if (move_uploaded_file($file['tmp_name'], $target_file)) {
            // Return the relative path that the frontend can use
            // The frontend is in Module_Transaction_Fund/pages/
            // The image is in Public_Assets/proofs/
            // So relative path from page to image is ../../Public_Assets/proofs/filename
            // But usually we store the path relative to root or a full URL.
            // Let's return the path relative to the project root, which seems to be the convention.
            $public_path = "../../Public_Assets/proofs/" . $filename;
            echo json_encode(['success' => true, 'url' => $public_path]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>
