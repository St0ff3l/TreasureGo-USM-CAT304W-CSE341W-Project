<?php
// 文件名: report_get_my_list.php
// 路径: Module_Platform_Governance_AI_Services/api/

// 1. 禁止错误直接打印破坏 JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

// 开启缓冲区
ob_start();

// 2. Session 设置
session_set_cookie_params(0, '/');
session_start();

try {
    // 3. 引入数据库配置
    // 你的配置在当前目录下的 config/treasurego_db_config.php
    $config_path = __DIR__ . '/config/treasurego_db_config.php';

    if (file_exists($config_path)) {
        require_once $config_path;
    } else {
        // 尝试上一级目录查找 (备用)
        $fallback = __DIR__ . '/../../config/treasurego_db_config.php';
        if (file_exists($fallback)) {
            require_once $fallback;
        } else {
            throw new Exception("System Error: Config file not found at " . $config_path);
        }
    }

    // 检查连接对象是否存在 (PDO模式)
    if (!isset($conn) || !$conn) {
        throw new Exception("Database connection failed: \$conn object is missing.");
    }

    // 4. 权限检查
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Unauthorized: Please log in.");
    }

    $current_user_id = $_SESSION['user_id'];

    // 5. SQL 查询 (保持不变)
    $sql = "SELECT 
                r.Report_ID,
                r.Report_Reason,
                r.Report_Status,
                r.Report_Creation_Date,
                r.Reported_Item_ID,
                r.Reported_User_ID,
                u.User_Username AS Reported_Username,
                p.Product_Title AS Reported_Product_Name,
                aa.Admin_Action_Final_Resolution AS Admin_Reply
            FROM Report r
            LEFT JOIN User u ON r.Reported_User_ID = u.User_ID
            LEFT JOIN Product p ON r.Reported_Item_ID = p.Product_ID
            LEFT JOIN Administrative_Action aa ON r.Admin_Action_ID = aa.Admin_Action_ID
            WHERE r.Reporting_User_ID = :user_id
            ORDER BY r.Report_Creation_Date DESC";

    // 6. 使用 PDO 方式执行查询
    $stmt = $conn->prepare($sql);

    // PDO 绑定参数 (注意这里不同于 MySQLi)
    $stmt->bindParam(':user_id', $current_user_id, PDO::PARAM_INT);

    $stmt->execute();

    // PDO 获取结果集
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $reports = [];

    foreach ($rows as $row) {
        $type = 'user';
        $targetName = $row['Reported_Username'] ?? ('User #' . $row['Reported_User_ID']);

        if (!empty($row['Reported_Item_ID'])) {
            $type = 'product';
            $targetName = $row['Reported_Product_Name'] ?? ('Product #' . $row['Reported_Item_ID']);
        }

        $reports[] = [
            'id' => $row['Report_ID'],
            'type' => $type,
            'targetName' => $targetName,
            'reason' => $row['Report_Reason'],
            'status' => ucfirst($row['Report_Status']),
            'date' => $row['Report_Creation_Date'],
            'adminReply' => $row['Admin_Reply']
        ];
    }

    // 成功：输出 JSON
    ob_clean();
    echo json_encode(['success' => true, 'data' => $reports]);

} catch (PDOException $e) {
    // 捕获 PDO 数据库错误
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
} catch (Exception $e) {
    // 捕获普通错误
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>