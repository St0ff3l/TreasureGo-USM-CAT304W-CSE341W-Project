<?php
// 文件位置: api/AI_Check_Product.php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

// 引入数据库配置和 AI 服务
require_once __DIR__ . '/config/treasurego_db_config.php';
require_once __DIR__ . '/config/Gemini_Service.php';

$input = json_decode(file_get_contents('php://input'), true);
$productId = $input['product_id'] ?? null;

if (!$productId) {
    echo json_encode(['success' => false, 'msg' => 'No Product ID provided']);
    exit;
}

try {
    // 1. 获取商品信息
    $stmt = $conn->prepare("
        SELECT Product_Title, Product_Description, Product_Price 
        FROM Product 
        WHERE Product_ID = ?
    ");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        echo json_encode(['success' => false, 'msg' => 'Product not found']);
        exit;
    }

    // 1.1 单独查询该商品的所有图片
    $stmtImg = $conn->prepare("SELECT Image_URL FROM Product_Images WHERE Product_ID = ?");
    $stmtImg->execute([$productId]);
    $images = $stmtImg->fetchAll(PDO::FETCH_COLUMN);

    // 2. 处理图片绝对路径
    $localImagePaths = [];
    $baseDir = __DIR__ . '/../../';

    if (!empty($images)) {
        foreach ($images as $imgUrl) {
            if (!empty($imgUrl)) {
                $cleanPath = str_replace(['../', './'], '', $imgUrl);
                $fullPath = $baseDir . $cleanPath;
                
                if (file_exists($fullPath)) {
                    $localImagePaths[] = $fullPath;
                }
            }
        }
    }

    // 3. 🚀 调用封装好的 AI 服务函数
    // 注意：现在传入的是数组 $localImagePaths
    $aiResult = analyzeProductWithAI(
        $product['Product_Title'],
        $product['Product_Description'],
        $product['Product_Price'],
        $localImagePaths
    );

    // 4. 返回结果给前端
    echo json_encode([
        'success' => true,
        'ai_analysis' => $aiResult
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'msg' => 'Error: ' . $e->getMessage()]);
}
?>