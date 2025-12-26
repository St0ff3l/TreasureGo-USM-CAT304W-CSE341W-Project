<?php
// api/get_profile.php
header('Content-Type: application/json');
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
header("Pragma: no-cache"); // HTTP 1.0.
header("Expires: 0"); // Proxies.

require_once '../api/config/treasurego_db_config.php';
require_once '../includes/auth.php';

start_session_safe();

// 1. 严格校验 Session
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$user_id = get_current_user_id();

try {
    $pdo = getDBConnection();
    // 2. 查询数据 (不查密码!)
    $stmt = $pdo->prepare("SELECT User_ID, User_Username, User_Email, User_Role, User_Created_At, User_Profile_Image AS User_Profile_image, User_Average_Rating FROM User WHERE User_ID = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if ($user) {
        // 3. 计算统计数据 (分开处理，防止一个失败影响其他)

        // A. Published Count
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM Product WHERE User_ID = ?");
            $stmt->execute([$user_id]);
            $user['posted_count'] = $stmt->fetchColumn();
        } catch (Exception $e) {
            $user['posted_count'] = 0;
        }

        // B. Sold Count
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM Product WHERE User_ID = ? AND Product_Status = 'Sold'");
            $stmt->execute([$user_id]);
            $user['sold_count'] = $stmt->fetchColumn();
        } catch (Exception $e) {
            $user['sold_count'] = 0;
        }

        // C. Membership Tier
        try {
            // 1. Fetch all valid memberships (active or future)
            $stmt = $pdo->prepare("
                SELECT 
                    mp.Membership_Tier,
                    mp.Membership_Price,
                    mp.Membership_Description,
                    m.Memberships_Start_Date,
                    m.Memberships_End_Date
                FROM Memberships m 
                JOIN Membership_Plans mp ON m.Plan_ID = mp.Plan_ID 
                WHERE m.User_ID = ? 
                  AND m.Memberships_End_Date > NOW() 
                ORDER BY mp.Membership_Price DESC, m.Memberships_Start_Date ASC
            ");
            $stmt->execute([$user_id]);
            $allMemberships = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $currentDate = date('Y-m-d H:i:s');
            $highestActiveTier = null;
            $highestPrice = -1;
            $selectedDescription = '';

            // 2. Identify Highest Active Tier (must be active NOW)
            foreach ($allMemberships as $m) {
                if ($m['Memberships_Start_Date'] <= $currentDate && $m['Memberships_End_Date'] > $currentDate) {
                    if ($m['Membership_Price'] > $highestPrice) {
                        $highestPrice = $m['Membership_Price'];
                        $highestActiveTier = $m['Membership_Tier'];
                        $selectedDescription = $m['Membership_Description'];
                    }
                }
            }

            if ($highestActiveTier) {
                // 3. Filter records for this specific tier
                $tierRecords = array_filter($allMemberships, function($m) use ($highestActiveTier) {
                    return $m['Membership_Tier'] === $highestActiveTier;
                });

                // Sort by Start Date (crucial for merging)
                usort($tierRecords, function($a, $b) {
                    return strcmp($a['Memberships_Start_Date'], $b['Memberships_Start_Date']);
                });

                // 4. Merge overlapping/continuous intervals
                $mergedIntervals = [];
                foreach ($tierRecords as $rec) {
                    if (empty($mergedIntervals)) {
                        $mergedIntervals[] = [
                            'start' => $rec['Memberships_Start_Date'],
                            'end' => $rec['Memberships_End_Date']
                        ];
                    } else {
                        $lastIndex = count($mergedIntervals) - 1;
                        $last = &$mergedIntervals[$lastIndex];

                        // Check for overlap or adjacency (within 1 second/day? Let's assume strict overlap or abutment)
                        // If current start <= last end, merge.
                        if ($rec['Memberships_Start_Date'] <= $last['end']) {
                            if ($rec['Memberships_End_Date'] > $last['end']) {
                                $last['end'] = $rec['Memberships_End_Date'];
                            }
                        } else {
                            $mergedIntervals[] = [
                                'start' => $rec['Memberships_Start_Date'],
                                'end' => $rec['Memberships_End_Date']
                            ];
                        }
                    }
                }

                // 5. Find the interval that covers NOW
                $finalStart = null;
                $finalEnd = null;
                foreach ($mergedIntervals as $interval) {
                    if ($interval['start'] <= $currentDate && $interval['end'] > $currentDate) {
                        $finalStart = $interval['start'];
                        $finalEnd = $interval['end'];
                        break;
                    }
                }

                if ($finalStart && $finalEnd) {
                    $user['Memberships_tier'] = $highestActiveTier;
                    $user['Memberships_Start_Date'] = $finalStart;
                    $user['Memberships_End_Date'] = $finalEnd;
                    $user['Membership_Description'] = $selectedDescription;
                } else {
                    // Fallback (Shouldn't happen if logic is correct, but safe fallback)
                    $user['Memberships_tier'] = 'Free';
                }

            } else {
                $user['Memberships_tier'] = 'Free';
                $user['Memberships_Start_Date'] = null;
                $user['Memberships_End_Date'] = null;
                $user['Membership_Description'] = 'Standard free account.';
            }

        } catch (Exception $e) {
            $user['Memberships_tier'] = 'Free';
            $user['Membership_Description'] = 'Error retrieving membership details.';
        }

        // =================================================================
        // D. 新增：Privileges (特权计算)
        // =================================================================

        // 1. 获取当前计算出的等级，如果没有则默认为 Free
        $current_tier = $user['Memberships_tier'] ?? 'Free';

        // 2. 定义哪些等级可以免除平台费 (根据你的需求修改这里的字符串，大小写必须和数据库一致)
        $waive_platform_fee_tiers = ['VIP', 'SVIP'];

        // 3. 定义哪些等级可以免除提现费 (如果以后需要)
        $waive_withdrawal_fee_tiers = ['SVIP'];

        // 4. 将特权状态注入到 user 数组中
        $user['privileges'] = [
            'waive_platform_fee' => in_array($current_tier, $waive_platform_fee_tiers),
            'waive_withdrawal_fee' => in_array($current_tier, $waive_withdrawal_fee_tiers)
        ];
        // =================================================================

        // 4. 返回 JSON
        echo json_encode(['status' => 'success', 'data' => $user]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'User not found']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>