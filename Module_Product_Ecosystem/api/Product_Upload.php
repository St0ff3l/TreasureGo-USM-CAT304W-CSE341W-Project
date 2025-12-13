<?php
// Product_Upload.php - 商品上传处理 (PDO版)

// 引入数据库配置
require_once 'config/treasurego_db_config.php';

header('Content-Type: application/json');

try {
    // 1. 获取数据库连接
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        throw new Exception("无法连接到远程数据库");
    }

    // 2. 仅允许 POST 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => '仅允许 POST 请求']);
        exit();
    }

    // 3. 获取并清理表单数据
    $product_title = trim($_POST['product_name'] ?? ''); // 前端叫product_name，数据库叫Product_Title
    $price = floatval($_POST['price'] ?? 0);
    $condition = trim($_POST['condition'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $user_id = intval($_POST['user_id'] ?? 1); // 默认ID为1，防止报错

    // 补充数据库必填但前端没传的字段 (设置默认值)
    $category_id = 1; // 默认为电子产品或其他，建议前端加个下拉框
    $location = 'Online';
    $review_status = 'Pending';
    $product_status = 'Active';

    // 4. 数据验证
    if (empty($product_title)) throw new Exception("商品名称不能为空");
    if ($price <= 0) throw new Exception("价格必须大于 0");
    if (empty($condition)) throw new Exception("请选择商品条件");
    if (empty($description)) throw new Exception("商品描述不能为空");

    // 5. 处理图片文件
    $image_paths = [];
    if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
        // 注意：确保服务器上这个目录存在且有写入权限
        $upload_dir = '../../Public_Assets/images/products/';

        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                throw new Exception("服务器无法创建上传目录");
            }
        }

        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        $file_count = count($_FILES['images']['name']);
        for ($i = 0; $i < $file_count; $i++) {
            if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['images']['tmp_name'][$i];
                $file_name = $_FILES['images']['name'][$i];
                $file_type = $_FILES['images']['type'][$i];

                if (!in_array($file_type, $allowed_types)) {
                    continue; // 跳过不支持的格式
                }

                // 生成唯一文件名
                $ext = pathinfo($file_name, PATHINFO_EXTENSION);
                $new_filename = 'prod_' . time() . '_' . uniqid() . '.' . $ext;
                $destination = $upload_dir . $new_filename;

                if (move_uploaded_file($file_tmp, $destination)) {
                    // 存入数据库的相对路径
                    $image_paths[] = 'Public_Assets/images/products/' . $new_filename;
                }
            }
        }
    }

    // 6. 开启事务 (确保商品和图片同时成功或失败)
    $pdo->beginTransaction();

    // 7. 插入商品 (对应你的 Product 表结构)
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
    ) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)";

    $stmt = $pdo->prepare($sql_product);
    $stmt->execute([
        $product_title,
        $description,
        $price,
        $condition,
        $product_status,
        $location,
        $review_status,
        $user_id,
        $category_id
    ]);

    // 获取刚插入的 Product_ID (数据库自增生成的 100000000+)
    $product_id = $pdo->lastInsertId();

    // 8. 插入图片 (对应你的 Product_Images 表结构)
    if (!empty($image_paths)) {
        $sql_image = "INSERT INTO Product_Images (
            Product_ID,
            Image_URL,
            Image_is_primary,
            Image_Upload_Time
        ) VALUES (?, ?, ?, NOW())";

        $stmt_img = $pdo->prepare($sql_image);

        foreach ($image_paths as $index => $path) {
            // 第一张图为主图 (1)，其他为 (0)
            $is_primary = ($index === 0) ? 1 : 0;
            $stmt_img->execute([$product_id, $path, $is_primary]);
        }
    }

    // 提交事务
    $pdo->commit();

    // 返回成功
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => '商品发布成功！',
        'product_id' => $product_id
    ]);

} catch (Exception $e) {
    // 发生错误回滚事务
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>