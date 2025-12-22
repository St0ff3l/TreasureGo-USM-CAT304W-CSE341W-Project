/*
 * TreasureGO Headerbar Component (Navbar Only)
 * 修复版：添加 display: contents 以解决 Sticky 吸顶失效问题
 */

(function (global) {
    'use strict';

    // --- 1. 配置常量 ---
    const TG_HEADERBAR_STYLE_ID = 'tg-headerbar-style';
    const TG_HEADERBAR_FONTS_LINK_ID = 'tg-headerbar-fonts';

    // --- 2. 样式定义 (保持不变) ---
    const EMBEDDED_HEADERBAR_CSS = `
    /* ================= CSS Variables ================= */
    :root {
        --primary: #4F46E5;
        --primary-hover: #4338CA;
        --text-dark: #1F2937;
        --text-gray: #6B7280;
        --glass-bg: rgba(255, 255, 255, 0.95);
    }

    /* ================= Navbar Styles ================= */
    .navbar {
        background: var(--glass-bg);
        backdrop-filter: blur(12px);
        padding: 1rem 5%;
        display: flex;
        justify-content: space-between;
        align-items: center;
        
        /* Sticky 吸顶设置 */
        position: sticky; 
        top: 0;
        z-index: 1000;
        
        border-bottom: 1px solid rgba(255,255,255,0.5);
    }

    .logo {
        font-weight: 800; font-size: 1.5rem; color: var(--primary);
        display: flex; align-items: center; gap: 10px; text-decoration: none;
    }
    .logo span { color: var(--text-dark); }
    
    .logo-img {
        width: 40px; height: 40px; border-radius: 8px; object-fit: cover;
        animation: glowAnimation 3s infinite alternate;
    }
    @keyframes glowAnimation {
        0% { box-shadow: 0 0 5px rgba(245, 158, 11, 0.2), 0 0 10px rgba(245, 158, 11, 0.1); }
        100% { box-shadow: 0 0 15px rgba(245, 158, 11, 0.8), 0 0 25px rgba(245, 158, 11, 0.5); }
    }

    .nav-actions { display: flex; align-items: center; gap: 20px; }
    
    .nav-btn {
        border: none; background: transparent; font-weight: 600; color: var(--text-gray);
        padding: 0.6rem 0.5rem; cursor: pointer; transition: color 0.2s; font-size: 1rem;
    }
    .nav-btn:hover { color: var(--text-dark); }
    
    .btn-primary {
        border: none; background-color: var(--text-dark); color: white;
        font-weight: 600; padding: 0.7rem 1.8rem; border-radius: 12px;
        cursor: pointer; transition: all 0.2s; font-size: 1rem;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    .btn-primary:hover { transform: translateY(-2px); background-color: #000; }

    /* ================= Dropdown Menu ================= */
    .menu-container { position: relative; display: inline-block; }
    
    .dots-btn {
        width: 40px; height: 40px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 20px; cursor: pointer; color: var(--text-dark);
        font-weight: bold; transition: 0.2s; background: #f3f4f6;
    }
    .dots-btn:hover { background: #eee; }
    
    .dropdown-content {
        display: none; position: absolute; right: 0;
        top: 100%; margin-top: 10px;
        background-color: white; min-width: 160px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.12);
        border-radius: 16px; z-index: 1001; padding: 8px;
        animation: fadeIn 0.2s ease;
    }
    .dropdown-content::before {
        content: ""; position: absolute; top: -20px; left: 0;
        width: 100%; height: 20px; background: transparent;
    }
    .menu-container:hover .dropdown-content { display: block; }
    
    .dropdown-item {
        color: var(--text-dark); padding: 12px 16px; text-decoration: none;
        display: block; font-size: 14px; font-weight: 500; border-radius: 10px;
    }
    .dropdown-item:hover { background-color: #f3f4f6; color: var(--primary); }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }

    /* Mobile Responsive (Navbar only) */
    @media (max-width: 768px) {
        .navbar { padding: 15px; }
        .nav-actions { gap: 10px; }
        .nav-btn { display: none; } 
    }
  `;

    // --- 3. 辅助函数 ---
    function ensureAssets() {
        if(!document.getElementById(TG_HEADERBAR_FONTS_LINK_ID)) {
            const link = document.createElement('link');
            link.id = TG_HEADERBAR_FONTS_LINK_ID; link.rel = 'stylesheet';
            link.href = 'https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@400;700&family=Poppins:wght@400;600;800&display=swap';
            document.head.appendChild(link);
        }
        if(!document.getElementById(TG_HEADERBAR_STYLE_ID)) {
            const style = document.createElement('style');
            style.id = TG_HEADERBAR_STYLE_ID;
            style.textContent = EMBEDDED_HEADERBAR_CSS;
            document.head.appendChild(style);
        }
    }

    function getBasePath(options) {
        const basePath = (options && options.basePath) ? String(options.basePath).replace(/\/$/, '') : '';
        return basePath ? (basePath + '/') : '';
    }

    // --- 4. HTML 构建 ---
    function getNavbarHtml(p) {
        return `
    <nav class="navbar" data-component="tg-headerbar">
      <a href="${p}index.html" class="logo">
        <img src="${p}Public_Assets/images/TreasureGo_Logo.png" alt="Logo" class="logo-img">
        Treasure<span>Go</span>
      </a>

      <div class="nav-actions">
        <button class="nav-btn" onclick="window.location.href='${p}Module_Transaction_Fund/pages/Fund_Request.html'">Top Up</button>
        <button id="nav-admin-btn" class="nav-btn" style="display: none;" onclick="window.location.href='${p}Module_User_Account_Management/pages/admin_dashboard.php'">Admin Dashboard</button>
        <button class="nav-btn" onclick="window.location.href='${p}Module_Transaction_Fund/pages/Orders_Management.html'">Orders</button>

        <button id="nav-login-btn" class="btn-primary" onclick="window.location.href='${p}Module_User_Account_Management/pages/login.php'">Login</button>

        <div id="nav-user-menu" class="menu-container" style="display: none;">
          <div id="nav-avatar" class="dots-btn" onclick="window.location.href='${p}Module_User_Account_Management/pages/profile.php'">👤</div>
          <div class="dropdown-content">
            <a href="${p}Module_User_Account_Management/pages/profile.php" class="dropdown-item">My Profile</a>
            <a href="#" class="dropdown-item">Settings</a>
            <a href="${p}Module_User_Account_Management/api/logout.php" class="dropdown-item" style="color: #ef4444;">Log Out</a>
          </div>
        </div>
      </div>
    </nav>`.trim();
    }

    // --- 5. Session 逻辑 ---
    async function checkSession(p) {
        const apiUrl = `${p}Module_User_Account_Management/api/session_status.php`;

        const loginBtn = document.getElementById('nav-login-btn');
        const userMenu = document.getElementById('nav-user-menu');
        const avatarBtn = document.getElementById('nav-avatar');
        const adminBtn = document.getElementById('nav-admin-btn');

        if (!loginBtn || !userMenu) return;

        try {
            const res = await fetch(apiUrl);
            const data = await res.json();

            if (data.is_logged_in) {
                loginBtn.style.display = 'none';
                userMenu.style.display = 'inline-block';

                if (data.user) {
                    if (avatarBtn) {
                        if (data.user.avatar_url) {
                            avatarBtn.innerHTML = `<img src="${data.user.avatar_url}" style="width:100%; height:100%; border-radius:50%; object-fit:cover;">`;
                            avatarBtn.style.background = 'transparent';
                            avatarBtn.style.border = '2px solid #fff';
                            avatarBtn.style.boxShadow = '0 4px 10px rgba(79, 70, 229, 0.2)';
                        } else if (data.user.username) {
                            avatarBtn.innerText = data.user.username.charAt(0).toUpperCase();
                            avatarBtn.style.background = '#EEF2FF';
                            avatarBtn.style.color = '#4F46E5';
                            avatarBtn.style.border = '2px solid #fff';
                        }
                    }

                    if (adminBtn && data.user.role === 'admin') {
                        adminBtn.style.display = 'inline-block';
                    } else if (adminBtn) {
                        adminBtn.style.display = 'none';
                    }
                }
            } else {
                loginBtn.style.display = 'inline-block';
                userMenu.style.display = 'none';
                if (adminBtn) adminBtn.style.display = 'none';
            }
        } catch (err) {
            console.error("Headerbar: Session check failed", err);
            loginBtn.style.display = 'inline-block';
            userMenu.style.display = 'none';
        }
    }

    // --- 6. 挂载函数 (核心修改处) ---
    function mount(options) {
        ensureAssets();
        const basePath = getBasePath(options);

        // 创建容器
        const wrapper = document.createElement('div');
        wrapper.setAttribute('data-tg-headerbar-mount', '1');
        wrapper.innerHTML = getNavbarHtml(basePath);

        // 🟢 修复：设置 display: contents
        // 这样 wrapper div 在布局树中被“移除”，.navbar 直接作为 body 子元素
        // 从而使 position: sticky 相对于 body/viewport 生效。
        wrapper.style.display = 'contents';

        if (document.body.firstChild) {
            document.body.insertBefore(wrapper, document.body.firstChild);
        } else {
            document.body.appendChild(wrapper);
        }

        checkSession(basePath);
        return wrapper;
    }

    global.TreasureGoHeaderbar = { mount };

})(window);