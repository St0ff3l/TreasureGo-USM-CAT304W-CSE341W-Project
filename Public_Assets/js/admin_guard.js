/**
 * Admin Guard Script
 * Protects admin pages from unauthorized access.
 * Checks session status and role.
 * Includes a minimum 2s loading delay for UX.
 */

(async function() {
    // 1. Create a loading overlay immediately
    const overlay = document.createElement('div');
    overlay.id = 'admin-guard-overlay';
    overlay.style.cssText = `
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: #F3F6F9; z-index: 99999; display: flex;
        align-items: center; justify-content: center; flex-direction: column;
    `;
    overlay.innerHTML = `
        <div style="font-size: 3rem; margin-bottom: 20px;">🔒</div>
        <div style="font-family: sans-serif; color: #4B5563; font-weight: 600;">Verifying Access...</div>
    `;
    document.documentElement.appendChild(overlay);

    try {
        // --- 核心修改开始 ---

        // 定义两个任务：
        // 1. 强制等待 500 毫秒 (0.5秒)
        const delayPromise = new Promise(resolve => setTimeout(resolve, 500));

        // 2. 发起实际的网络请求
        const fetchPromise = fetch('../../Module_User_Account_Management/api/session_status.php');

        // Promise.all 会等待数组中所有的 Promise 都完成
        // 结果是一个数组: [delayResult, fetchResult]
        const [_, res] = await Promise.all([delayPromise, fetchPromise]);

        // --- 核心修改结束 ---

        const data = await res.json();

        if (data.is_logged_in && data.user && data.user.role === 'admin') {
            // Access granted
            const overlay = document.getElementById('admin-guard-overlay');
            if (overlay) {
                // 增加淡出效果，让消失更平滑
                overlay.style.opacity = '0';
                overlay.style.transition = 'opacity 0.5s ease';
                setTimeout(() => overlay.remove(), 500);
            }
        } else {
            // Access denied
            showAccessDenied();
        }
    } catch (error) {
        console.error('Admin guard error:', error);
        // 如果发生错误（如断网），为了用户体验，建议不需要强制等2秒，直接报错
        // 或者如果你希望错误也等2秒，可以把 Promise.all 放在 try 外面（但通常不需要）
        showAccessDenied();
    }

    function showAccessDenied() {
        const overlay = document.getElementById('admin-guard-overlay');
        if (overlay) {
            overlay.innerHTML = `
                <div style="font-size: 4rem; margin-bottom: 20px;">🚫</div>
                <h1 style="font-family: sans-serif; color: #1F2937; margin-bottom: 10px;">Access Denied</h1>
                <p style="font-family: sans-serif; color: #6B7280; margin-bottom: 30px;">You do not have permission to view this page.</p>
                <button onclick="window.location.href='../../index.html'" 
                        style="padding: 12px 24px; background: #4F46E5; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-family: sans-serif; transition: 0.2s;">
                    Go to Home
                </button>
            `;
            // 给按钮加个简单的 hover 效果（可选）
            const btn = overlay.querySelector('button');
            btn.onmouseover = () => btn.style.background = '#4338CA';
            btn.onmouseout = () => btn.style.background = '#4F46E5';
        } else {
            // Fallback provided previously (usually not needed if overlay exists)
            document.body.innerHTML = 'Access Denied';
            window.location.href = '../../index.html';
        }
    }
})();