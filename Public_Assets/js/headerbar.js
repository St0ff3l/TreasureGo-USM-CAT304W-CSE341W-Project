/*
 * TreasureGO Headerbar Component (Navbar Only)
 * 更新说明：
 * 1. 修复了 navigateWithAuth 未导出的问题，确保按钮点击有效。
 * 2. 集成了 AuthModal 二次确认弹窗逻辑。
 */

(function (global) {
    'use strict';

    // --- 1. 配置常量 ---
    const TG_HEADERBAR_STYLE_ID = 'tg-headerbar-style';
    const TG_HEADERBAR_FONTS_LINK_ID = 'tg-headerbar-fonts';

    // --- 自动加载 AuthModal (确保弹窗组件存在) ---
    // 假设 auth_modal.js 在 Public_Assets/js/ 目录下
    function loadAuthModal(basePath) {
        if (!global.AuthModal && !document.querySelector('script[src*="auth_modal.js"]')) {
            const script = document.createElement('script');
            script.src = basePath + 'Public_Assets/js/auth_modal.js';
            document.head.appendChild(script);
        }
    }

    // --- 2. 样式定义 (保持原样) ---
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
        font-family: 'Poppins', 'Noto Sans SC', sans-serif;
        background: var(--glass-bg);
        backdrop-filter: blur(12px);
        padding: 1rem 5%;
        display: flex;
        justify-content: space-between;
        align-items: center;
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

    /* ================= Dropdown Menu & Avatar Styles ================= */
    .menu-container { position: relative; display: inline-block; }
    
    .dots-btn {
        width: 40px; height: 40px; 
        background: #EEF2FF;      
        color: var(--primary);    
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 1rem; 
        font-weight: bold; 
        cursor: pointer; 
        border: 2px solid white;  
        box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2); 
        transition: 0.2s; 
    }
    
    .dots-btn:hover { transform: scale(1.05); }
    
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

    /* Mobile Responsive */
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

    // --- 关键：登录检查并处理弹窗的函数 ---
    async function navigateWithAuth(url, basePath) {
        try {
            const apiUrl = `${basePath}Module_User_Account_Management/api/session_status.php`;

            // 1. 发起请求检查 Session
            const res = await fetch(apiUrl, {
                method: 'GET',
                credentials: 'include', // 携带 Cookie
                headers: { 'Accept': 'application/json' },
                cache: 'no-cache'
            });

            if (!res.ok) throw new Error('Session check failed');

            // 确保返回 JSON
            const contentType = res.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error('Invalid response type');
            }

            const data = await res.json();

            // 2. 根据登录状态决定动作
            if (data.is_logged_in) {
                // 已登录 -> 跳转到目标页面
                window.location.href = url;
            } else {
                // 未登录 -> 弹出 AuthModal (二次确认)
                if (global.AuthModal) {
                    global.AuthModal.show();
                } else {
                    console.error('AuthModal not loaded, redirecting to login as fallback.');
                    window.location.href = `${basePath}Module_User_Account_Management/pages/login.php`;
                }
            }
        } catch (err) {
            console.error('[Headerbar] Auth check error:', err);
            // 接口报错时，作为兜底也显示弹窗（如果能显示的话），或者去登录页
            if (global.AuthModal) {
                global.AuthModal.show();
            } else {
                window.location.href = `${basePath}Module_User_Account_Management/pages/login.php`;
            }
        }
    }

    // --- 4. HTML 构建 ---
    function getNavbarHtml(p) {
        // 注意：onclick 中调用了 TreasureGoHeaderbar.navigateWithAuth
        return `
    <nav class="navbar" data-component="tg-headerbar">
      <a href="${p}index.html" class="logo">
        <img src="${p}Public_Assets/images/TreasureGo_Logo.png" alt="Logo" class="logo-img">
        Treasure<span>Go</span>
      </a>

      <div class="nav-actions">
        <button class="nav-btn" onclick="TreasureGoHeaderbar.navigateWithAuth('${p}Module_Transaction_Fund/pages/Fund_Request.html', '${p}')">Top Up</button>
        
        <button id="nav-admin-btn" class="nav-btn" style="display: none;" onclick="window.location.href='${p}Module_User_Account_Management/pages/admin_dashboard.php'">Admin Dashboard</button>
        
        <button class="nav-btn" onclick="TreasureGoHeaderbar.navigateWithAuth('${p}Module_Transaction_Fund/pages/Orders_Management.html', '${p}')">Orders</button>

        <button id="nav-login-btn" class="btn-primary" onclick="window.location.href='${p}Module_User_Account_Management/pages/login.php'">Login</button>

        <div id="nav-user-menu" class="menu-container" style="display: none;">
          <div id="nav-avatar" class="dots-btn" onclick="window.location.href='${p}Module_User_Account_Management/pages/profile.php'">👤</div>
          <div class="dropdown-content">
            <a href="${p}Module_User_Account_Management/api/logout.php" class="dropdown-item" style="color: #ef4444;">Log Out</a>
          </div>
        </div>
      </div>
    </nav>`.trim();
    }

    // --- 5. Session 逻辑 (用于页面加载时 UI 状态) ---
    async function checkSession(p) {
        const apiUrl = `${p}Module_User_Account_Management/api/session_status.php`;
        const loginBtn = document.getElementById('nav-login-btn');
        const userMenu = document.getElementById('nav-user-menu');
        const avatarBtn = document.getElementById('nav-avatar');
        const adminBtn = document.getElementById('nav-admin-btn');

        if (!loginBtn || !userMenu) return;

        try {
            const res = await fetch(apiUrl, {
                method: 'GET',
                credentials: 'include',
                headers: { 'Accept': 'application/json' },
                cache: 'no-cache'
            });

            if (res.redirected) throw new Error('Redirected');
            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            const data = await res.json();

            if (data.is_logged_in) {
                loginBtn.style.display = 'none';
                userMenu.style.display = 'inline-block';

                if (data.user) {
                    // 头像处理
                    if (avatarBtn) {
                        if (data.user.avatar_url) {
                            avatarBtn.innerHTML = `<img src="${data.user.avatar_url}" style="width:100%; height:100%; border-radius:50%; object-fit:cover;">`;
                            avatarBtn.style.background = 'transparent';
                        } else if (data.user.username) {
                            avatarBtn.innerText = data.user.username.charAt(0).toUpperCase();
                        }
                    }
                    // 管理员按钮处理
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
            console.error('[Headerbar] Session check error:', err);
            loginBtn.style.display = 'inline-block';
            userMenu.style.display = 'none';
            if (adminBtn) adminBtn.style.display = 'none';
        }
    }

    // --- 6. 挂载函数 ---
    function mount(options) {
        ensureAssets();
        const basePath = getBasePath(options);

        // 尝试预加载 AuthModal，以便点击时可用
        loadAuthModal(basePath);

        const wrapper = document.createElement('div');
        wrapper.setAttribute('data-tg-headerbar-mount', '1');
        wrapper.innerHTML = getNavbarHtml(basePath);
        wrapper.style.display = 'contents';

        if (document.body.firstChild) {
            document.body.insertBefore(wrapper, document.body.firstChild);
        } else {
            document.body.appendChild(wrapper);
        }

        checkSession(basePath);
        return wrapper;
    }

    // --- 7. 导出 (重要修改) ---
    // 必须导出 navigateWithAuth，否则 HTML 中的 onclick 会报错
    global.TreasureGoHeaderbar = {
        mount,
        navigateWithAuth
    };

})(window);