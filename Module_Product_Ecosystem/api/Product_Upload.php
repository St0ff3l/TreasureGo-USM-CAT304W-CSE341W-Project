<?php
// api/Product_Upload.php

// 1. 开启 Session (必须放在第一行)
session_start();

// 引入数据库配置
require_once 'config/treasurego_db_config.php';

header('Content-Type: application/json');

try {
    // 2. 获取数据库连接
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        throw new Exception("无法连接到远程数据库");
    }

    // 3. 仅允许 POST 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => '仅允许 POST 请求']);
        exit();
    }

    // 4. 安全检查：判断用户是否登录
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => '请先登录后再发布商品']);
        exit();
    }

    // 从 Session 获取 User_ID
    $user_id = $_SESSION['user_id'];

    // 5. 获取表单数据
    $product_title = trim($_POST['product_name'] ?? '');
    $price = floatval($_POST['price'] ?? 0);
    $condition = trim($_POST['condition'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['address'] ?? 'Online');
    $category_id = intval($_POST['category_id'] ?? 100000005);

    // 数据验证
    if (empty($product_title)) throw new Exception("商品名称不能为空");
    if ($price <= 0) throw new Exception("价格必须大于 0");
    if (empty($condition)) throw new Exception("请选择商品条件");
    if (empty($description)) throw new Exception("商品描述不能为空");
    if (empty($location)) throw new Exception("请填写交易地址");

    // =========================================================
    // 6. 处理图片文件 (路径已修改)
    // =========================================================
    $image_paths = [];

    // 【修改点 A】物理存储路径：从 api 文件夹往上一级找 Public_Product_Images
    $upload_base_dir = '../Public_Product_Images/';

    // 【修改点 B】数据库路径前缀：相对于网站根目录的完整路径
    $db_path_prefix = 'Module_Product_Ecosystem/Public_Product_Images/';

    // 自动创建目录
    if (!is_dir($upload_base_dir)) {
        if (!mkdir($upload_base_dir, 0755, true)) {
            throw new Exception("服务器无法创建上传目录: " . $upload_base_dir);
        }
    }

    if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $file_count = count($_FILES['images']['name']);

        for ($i = 0; $i < $file_count; $i++) {
            if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['images']['tmp_name'][$i];
                $file_name = $_FILES['images']['name'][$i];
                $file_type = $_FILES['images']['type'][$i];

                if (!in_array($file_type, $allowed_types)) {
                    continue;
                }

                // 生成唯一文件名
                $ext = pathinfo($file_name, PATHINFO_EXTENSION);
                $new_filename = 'prod_' . time() . '_' . uniqid() . '.' . $ext;

                $destination = $upload_base_dir . $new_filename;

                if (move_uploaded_file($file_tmp, $destination)) {
                    // 成功后，存入数据库使用的是 "前缀 + 文件名"
                    $image_paths[] = $db_path_prefix . $new_filename;
                }
            }
        }
    }
    // =========================================================

    // 7. 开启事务
    $pdo->beginTransaction();

    // 8. 插入商品
    $sql_product = "INSERT INTO Product (
        Product_Title,
        Product_Description,
        Product_Price,
        Product_Condition,
        Product_Status,
        Product_Created_Time,
        Product_Location,
        Product_Review_Status,
        User_ID,
        Category_ID
    ) VALUES (?, ?, ?, ?, 'Active', NOW(), ?, 'Pending', ?, ?)";

    $stmt = $pdo->prepare($sql_product);
    $stmt->execute([
        $product_title,
        $description,
        $price,
        $condition,
        $location,
        $user_id,
        $category_id
    ]);

    $product_id = $pdo->lastInsertId();

    // 9. 插入图片路径
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
        'message' => '商品发布成功！',
        'product_id' => $product_id
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (http_response_code() !== 401) {
        http_response_code(400);
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>