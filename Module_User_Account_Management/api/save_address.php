<?php
// 文件路径: Module_User_Account_Management/api/save_address.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'fail', 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/config/treasurego_db_config.php';

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents("php://input"), true);

$addr_id    = !empty($data['Address_ID']) ? $data['Address_ID'] : null;
$name       = trim($data['Address_Receiver_Name'] ?? '');
$detail     = trim($data['Address_Detail'] ?? '');
$phone      = trim($data['Address_Phone_Number'] ?? '');
// 获取前端传来的默认勾选状态
$is_default = (int)($data['Address_Is_Default'] ?? 0);

if (empty($name) || empty($detail) || empty($phone)) {
    echo json_encode(['status' => 'fail', 'message' => 'All fields are required']);
    exit;
}

try {
    // 🔥 如果当前保存的操作要把地址设为默认，则先把该用户所有其他地址设为非默认(0)
    if ($is_default === 1) {
        $reset_sql = "UPDATE Address SET Address_Is_Default = 0 WHERE Address_User_ID = :uid";
        $reset_stmt = $conn->prepare($reset_sql);
        $reset_stmt->execute([':uid' => $user_id]);
    }

    if ($addr_id) {
        // --- 编辑模式 (UPDATE) ---
        $sql = "UPDATE Address 
                SET Address_Receiver_Name = :name, 
                    Address_Detail = :detail, 
                    Address_Phone_Number = :phone,
                    Address_Is_Default = :is_def
                WHERE Address_ID = :aid AND Address_User_ID = :uid";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':name'   => $name,
            ':detail' => $detail,
            ':phone'  => $phone,
            ':is_def' => $is_default,
            ':aid'    => $addr_id,
            ':uid'    => $user_id
        ]);
        echo json_encode(['status' => 'success', 'message' => 'Address updated successfully']);

    } else {
        // --- 新增模式 (INSERT) ---
        // 自动逻辑：如果该用户之前没有地址，这第一个地址必须是默认的
        $count_stmt = $conn->prepare("SELECT COUNT(*) FROM Address WHERE Address_User_ID = :uid");
        $count_stmt->execute([':uid' => $user_id]);
        if ($count_stmt->fetchColumn() == 0) {
            $is_default = 1;
        }

        $sql = "INSERT INTO Address (Address_User_ID, Address_Receiver_Name, Address_Detail, Address_Phone_Number, Address_Is_Default) 
                VALUES (:uid, :name, :detail, :phone, :is_def)";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':uid'    => $user_id,
            ':name'   => $name,
            ':detail' => $detail,
            ':phone'  => $phone,
            ':is_def' => $is_default
        ]);
        echo json_encode(['status' => 'success', 'message' => 'New address added successfully']);
    }

} catch (PDOException $e) {
    echo json_encode(['status' => 'fail', 'message' => 'Database Error: ' . $e->getMessage()]);
}
?>