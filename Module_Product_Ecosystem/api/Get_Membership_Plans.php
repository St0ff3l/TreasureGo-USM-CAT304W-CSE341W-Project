<?php
// 1. 引入数据库配置 (请确保路径正确，根据你的截图调整)
require_once 'config/treasurego_db_config.php';

header('Content-Type: application/json');

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        throw new Exception("Database connection failed");
    }

    // 2. 查询所有会员方案
    $sql = "SELECT * FROM Membership_Plans";
    $stmt = $pdo->query($sql);
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. 准备前端需要的数据结构
    // 前端期望的格式:
    // {
    //   'monthly': { vip: 9.9, svip: 29.9, label: '/ month' },
    //   'quarterly': { vip: 26.73, svip: 80.73, label: '/ quarter' },
    //   ...
    // }

    $response = [
        'monthly'   => ['label' => '/ month'],
        'quarterly' => ['label' => '/ quarter'],
        'yearly'    => ['label' => '/ year']
    ];

    foreach ($plans as $plan) {
        $days = $plan['Membership_Duration_Days'];
        $tier = strtolower($plan['Membership_Tier']); // 'vip' or 'svip'
        $price = floatval($plan['Membership_Price']); // 确保是数字

        // 根据天数映射到对应的周期 key
        if ($days == 30) {
            $response['monthly'][$tier] = $price;
        } elseif ($days == 90) {
            $response['quarterly'][$tier] = $price;
        } elseif ($days == 365) {
            $response['yearly'][$tier] = $price;
        }
    }

    // 4. 返回 JSON
    echo json_encode([
        'success' => true,
        'data' => $response
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>