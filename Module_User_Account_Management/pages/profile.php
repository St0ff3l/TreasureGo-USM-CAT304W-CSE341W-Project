<?php
// pages/profile.php
require_once '../includes/auth.php';

// 🔒 门卫拦截：必须登录才能看
require_login();

// 引入 View
require_once '../views/profile.html';
?>

<script>
  // If the view didn't include the address subview module for some reason, load it dynamically.
  (function ensureAddressSubviewScript() {
    if (typeof window.AddressSubview !== 'undefined') return;
    var s = document.createElement('script');
    s.src = '../assets/js/profile_address_subview.js';
    s.defer = true;
    document.head.appendChild(s);
  })();
</script>
