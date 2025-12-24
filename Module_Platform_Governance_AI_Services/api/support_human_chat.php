<?php
// 文件位置: Module_Platform_Governance_AI_Services/pages/support_human_chat.php
require_once __DIR__ . '/../../Module_User_Account_Management/includes/auth.php';
require_login();

// Unified entrypoint: use the new admin_support_dashboard-style UI
header('Location: /Module_Platform_Governance_AI_Services/pages/support_human_chat.html');
exit;
?>
