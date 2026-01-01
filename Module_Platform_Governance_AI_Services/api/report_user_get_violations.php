<?php
// Module_Platform_Governance_AI_Services/api/report_user_get_violations.php

ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

ob_start();
session_set_cookie_params(0, '/');
session_start();

try {
    // Load database configuration and initialize the PDO connection.
    $config_path = __DIR__ . '/config/treasurego_db_config.php';
    if (file_exists($config_path)) {
        require_once $config_path;
    } else {
        throw new Exception("System Error: Config file not found at: " . $config_path);
    }

    // Validate that a database connection is available.
    if (!isset($conn) || !$conn) {
        throw new Exception("Database connection failed.");
    }

    // Require an authenticated session.
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Unauthorized: Please log in.");
    }

    $current_user_id = $_SESSION['user_id'];

    // Fetch reports where the current user is the reported party and an admin reply exists.
    // Also include a product snapshot (title/status) and the primary image when available.
    $sql = "SELECT 
                r.Report_ID,
                r.Report_Type,
                r.Report_Reason,
                r.Report_Reply_To_Reported,
                r.Report_Creation_Date,
                r.Report_Updated_At,
                r.Reported_Item_ID,
                p.Product_Title,
                p.Product_Status,
                pi.Image_URL
            FROM Report r
            LEFT JOIN Product p ON r.Reported_Item_ID = p.Product_ID
            -- Primary product image (may be NULL if none is marked as primary)
            LEFT JOIN Product_Images pi ON p.Product_ID = pi.Product_ID AND pi.Image_is_primary = 1
            WHERE 
                r.Reported_User_ID = :user_id 
                AND r.Report_Reply_To_Reported IS NOT NULL 
                AND r.Report_Reply_To_Reported != ''
            ORDER BY r.Report_Updated_At DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':user_id', $current_user_id, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $violations = [];

    foreach ($rows as $row) {
        // Normalize the report type into a UI-friendly category.
        $type = 'info';
        if ($row['Report_Type'] === 'product') $type = 'violation';
        elseif ($row['Report_Type'] === 'user') $type = 'warning';

        $affectedProduct = null;
        if (!empty($row['Reported_Item_ID'])) {

            $rawImage = $row['Image_URL'];
            // Use an empty string when no image is available so the frontend can handle it.
            $finalImageUrl = '';

            if (!empty($rawImage)) {
                // Use absolute URLs as-is.
                if (strpos($rawImage, 'http') === 0) {
                    $finalImageUrl = $rawImage;
                }
                // Treat non-absolute values as site-relative paths.
                else {
                    // Example DB value: Module_Product/...
                    // Output URL:       /Module_Product/...
                    $finalImageUrl = '../../' . $rawImage;
                }
            }

            // Product information shown alongside the admin message.
            $affectedProduct = [
                'name' => $row['Product_Title'] ?? 'Unknown Item',
                'image' => $finalImageUrl,
                'status' => $row['Product_Status'] ?? 'Reported'
            ];
        }

        // Choose the most relevant timestamp for display.
        $displayDate = $row['Report_Updated_At'] ? $row['Report_Updated_At'] : $row['Report_Creation_Date'];

        $violations[] = [
            'id' => $row['Report_ID'],
            'type' => $type,
            'title' => 'Report: ' . $row['Report_Reason'],
            'date' => $displayDate,
            'adminMessage' => $row['Report_Reply_To_Reported'],
            'affectedProduct' => $affectedProduct
        ];
    }

    ob_clean();
    echo json_encode(['success' => true, 'data' => $violations]);

} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>