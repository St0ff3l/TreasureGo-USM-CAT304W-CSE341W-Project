<?php
// api/Product_Upload.php

// 1. 开启 Session (必须放在第一行)
session_start();

// 引入数据库配置
require_once __DIR__ . '/config/treasurego_db_config.php';

// 【修改点 1】引入 AI 服务文件
require_once __DIR__ . '/config/Gemini_Service.php';

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

    // 新增：获取交易方式，默认为 both
    $delivery_method = $_POST['delivery_method'] ?? 'both';
    // 简单的白名单验证
    if (!in_array($delivery_method, ['meetup', 'shipping', 'both'])) {
        $delivery_method = 'both';
    }

    // 数据验证
    if (empty($product_title)) throw new Exception("商品名称不能为空");
    if ($price <= 0) throw new Exception("价格必须大于 0");
    if (empty($condition)) throw new Exception("请选择商品条件");
    if (empty($description)) throw new Exception("商品描述不能为空");
    if (empty($location)) throw new Exception("请填写交易地址");

    // =========================================================
    // 6. 处理图片文件
    // =========================================================
    $image_paths = [];
    $all_physical_image_paths = []; // 用于传给 AI 的所有本地路径

    // 物理存储路径
    $upload_base_dir = '../Public_Product_Images/';

    // 数据库路径前缀
    $db_path_prefix = 'Module_Product_Ecosystem/Public_Product_Images/';

    if (!is_dir($upload_base_dir)) {
        if (!mkdir($upload_base_dir, 0777, true)) {
            throw new Exception("服务器错误：无法创建图片上传目录，请检查文件夹权限。");
        }
    }

    if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $file_count = count($_FILES['images']['name']);

        for ($i = 0; $i < $file_count; $i++) {
            $error_code = $_FILES['images']['error'][$i];

            if ($error_code === UPLOAD_ERR_INI_SIZE || $error_code === UPLOAD_ERR_FORM_SIZE) {
                throw new Exception("上传失败：图片 " . $_FILES['images']['name'][$i] . " 太大，超过了服务器限制。");
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

                    // 记录所有上传成功的图片的物理路径，给 AI 用
                    $all_physical_image_paths[] = $destination;
                } else {
                    throw new Exception("上传失败：无法保存图片文件。请联系管理员检查文件夹写入权限。");
                }
            } else {
                throw new Exception("上传出错，错误代码: " . $error_code);
            }
        }
    }

    // =========================================================
    // 【修改点 2】 加入 AI 自动审核逻辑
    // =========================================================

    // 默认状态（如果没有 AI，或者 AI 挂了）
    $final_product_status = 'Pending';       // 默认待审核
    $final_review_status = 'Pending';
    $ai_audit_comment = NULL;

    try {
        // 调用我们封装好的函数
        // 注意：传入的是物理路径数组 $all_physical_image_paths
        $aiResult = analyzeProductWithAI($product_title, $description, $price, $all_physical_image_paths);

        if ($aiResult) {
            // 策略：风险分 < 50 且 建议 Approve -> 直接上架
            // (原先是 < 30，现在放宽到 50，避免误伤低风险商品)
            if ($aiResult['suggestion'] === 'Approve' && $aiResult['risk_score'] < 50) {
                $final_product_status = 'Active';      // 直接上架
                $final_review_status = 'approved';     // 审核状态通过
                $ai_audit_comment = NULL;              // 清空备注
            } else {
                // 风险较高 -> 保持 Pending，写入理由
                $final_product_status = 'Pending';
                $final_review_status = 'pending';
                $ai_audit_comment = "[AI Auto-Flagged] Risk Score:" . $aiResult['risk_score'] . "%. Reason: " . $aiResult['reason'];
            }
        }
    } catch (Exception $aiEx) {
        // 如果 AI 报错，不应该阻止商品发布，而是转为人工审核
        // error_log("AI Audit Failed: " . $aiEx->getMessage());
        $final_product_status = 'Pending';
        $ai_audit_comment = "AI Service Unavailable, flagged for manual review.";
    }

    // =========================================================

    // 7. 开启事务
    $pdo->beginTransaction();

    // 8. 插入商品
    // 【修改点 3】 SQL 语句变了：
    //  - Product_Status 不再写死 'Active'，而是变成 ?
    //  - Product_Review_Status 不再写死 'Pending'，而是变成 ?
    //  - 增加了 Product_Review_Comment 字段用来存 AI 的拒绝理由
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
        $product_title,         // 1. 标题
        $description,           // 2. 描述
        $price,                 // 3. 价格
        $condition,             // 4. 成色
        $final_product_status,  // 5. 【动态】状态 (Active/Pending)
        $location,              // 6. 地址
        $final_review_status,   // 7. 【动态】审核状态 (approved/pending)
        $ai_audit_comment,      // 8. 【新增】AI 审核备注
        $delivery_method,       // 9. 配送方式
        $user_id,               // 10. 用户ID
        $category_id            // 11. 分类ID
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
        'message' => '商品发布成功！' . ($final_product_status === 'Pending' ? ' (AI检测到风险，已转入人工审核)' : ''),
        'product_id' => $product_id,
        'status' => $final_product_status // 新增：返回商品状态 (Active 或 Pending)
    ]);

} catch (Exception $e) {
    // 10. 如果出错，回滚事务
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (http_response_code() !== 401) {
        http_response_code(400);
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>