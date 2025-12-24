<?php
// 开启错误显示 (调试用)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/utils.php';
// 强制登录
require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TreasureGO - Chat</title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@400;700&family=Poppins:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        /* ========================================= */
        /* 复用 index.html 核心样式                */
        /* ========================================= */
        :root {
            --bg-color: #F3F6F9;
            --primary: #4F46E5;
            --primary-hover: #4338CA;
            --text-dark: #1F2937;
            --text-gray: #6B7280;
            --glass-bg: rgba(255, 255, 255, 0.95);
            --sidebar-radius: 50px;
            --card-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.05);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-dark);
            height: 100vh;
            overflow: hidden; /* 防止整个页面滚动 */
            display: flex;
            flex-direction: column;
        }

        /* Navbar 样式 (简化版) */
        .navbar {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            padding: 1rem 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,0.5);
            flex-shrink: 0;
        }

        .logo {
            font-weight: 800; font-size: 1.5rem; color: var(--primary);
            display: flex; align-items: center; gap: 10px; text-decoration: none;
        }
        .logo span { color: var(--text-dark); }
        .logo-img { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; }

        .nav-actions { display: flex; align-items: center; gap: 20px; }
        .nav-btn {
            border: none; background: transparent; font-weight: 600; color: var(--text-gray);
            padding: 0.6rem 0.5rem; cursor: pointer; transition: color 0.2s; font-size: 1rem;
        }
        .nav-btn:hover { color: var(--text-dark); }

        /* --- 下拉菜单 (修复缝隙版) --- */
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
        /* 修复鼠标滑过缝隙 */
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

        /* ✨ Logo 发光效果 (新添加) ✨ */
        .logo-img {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            object-fit: cover;
            /* 呼吸发光动画 */
            animation: glowAnimation 3s infinite alternate;
        }

        /* 发光动画定义 */
        @keyframes glowAnimation {
            0% {
                box-shadow: 0 0 5px rgba(245, 158, 11, 0.2),
                0 0 10px rgba(245, 158, 11, 0.1);
            }
            100% {
                box-shadow: 0 0 15px rgba(245, 158, 11, 0.8),
                0 0 25px rgba(245, 158, 11, 0.5);
            }
        }

        .btn-primary {
            border: none; background-color: var(--text-dark); color: white;
            font-weight: 600; padding: 0.7rem 1.8rem; border-radius: 12px;
            cursor: pointer; transition: all 0.2s; font-size: 1rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .btn-primary:hover { transform: translateY(-2px); background-color: #000; }

        /* ========================================= */
        /* Chat 布局样式                           */
        /* ========================================= */
        .chat-container {
            flex: 1;
            display: flex;
            max-width: 1400px;
            width: 100%;
            margin: 20px auto;
            padding: 0 20px;
            gap: 20px;
            height: calc(100vh - 100px); /* 减去 Navbar 高度 */
        }

        /* 左侧联系人列表 */
        .contacts-sidebar {
            width: 350px;
            background: white;
            border-radius: 24px;
            box-shadow: var(--card-shadow);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .contacts-header {
            padding: 20px;
            border-bottom: 1px solid #f3f4f6;
        }
        .contacts-header h2 { font-size: 1.2rem; font-weight: 700; }

        .contacts-list {
            flex: 1;
            overflow-y: auto;
            padding: 10px;
        }

        .contact-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border-radius: 16px;
            cursor: pointer;
            transition: background 0.2s;
            gap: 15px;
        }
        .contact-item:hover { background-color: #f9fafb; }
        .contact-item.active { background-color: #EEF2FF; }

        .contact-avatar {
            width: 50px; height: 50px; border-radius: 50%;
            background: #e5e7eb; object-fit: cover;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; color: #6b7280;
        }
        
        .contact-info { flex: 1; min-width: 0; }
        .contact-name { font-weight: 600; font-size: 1rem; margin-bottom: 4px; }
        .contact-last-msg { 
            font-size: 0.85rem; color: #9ca3af; 
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; 
        }
        .contact-time { font-size: 0.75rem; color: #d1d5db; }
        .unread-badge {
            background: #ef4444; color: white; font-size: 0.75rem;
            padding: 2px 8px; border-radius: 10px; font-weight: 600;
        }

        /* 右侧聊天区域 */
        .chat-area {
            flex: 1;
            background: white;
            border-radius: 24px;
            box-shadow: var(--card-shadow);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
        }

        .chat-header {
            padding: 15px 20px;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            flex-direction: column; /* 改为纵向布局以容纳商品卡片 */
            gap: 10px;
        }
        
        .chat-user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            width: 100%;
        }

        .chat-header-avatar { width: 40px; height: 40px; border-radius: 50%; background: #e5e7eb; }
        .chat-header-name { font-weight: 700; font-size: 1.1rem; }

        /* 商品快照卡片样式 */
        .product-context-card {
            display: none; /* 默认隐藏 */
            background: #f9fafb;
            border-radius: 12px;
            padding: 10px;
            margin-top: 5px;
            border: 1px solid #e5e7eb;
            align-items: center;
            gap: 12px;
            width: 100%;
            position: relative; /* 为关闭按钮定位 */
        }
        
        .p-ctx-close {
            position: absolute;
            top: 5px;
            right: 8px;
            cursor: pointer;
            color: #9ca3af;
            font-size: 1.2rem;
            line-height: 1;
            font-weight: bold;
        }
        .p-ctx-close:hover { color: #ef4444; }
        
        .p-ctx-img {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            object-fit: cover;
            background: #eee;
        }
        
        .p-ctx-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            overflow: hidden;
        }
        
        .p-ctx-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-dark);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .p-ctx-price {
            font-size: 0.9rem;
            color: var(--primary);
            font-weight: 700;
        }
        
        .p-ctx-btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.2s;
            white-space: nowrap;
        }
        .p-ctx-btn:hover { background: var(--primary-hover); }

        .messages-container {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 15px;
            background: #f9fafb;
        }

        .message {
            max-width: 70%;
            padding: 12px 16px;
            border-radius: 18px;
            font-size: 0.95rem;
            line-height: 1.5;
            position: relative;
            word-wrap: break-word;
        }

        .message.sent {
            align-self: flex-end;
            background: var(--primary);
            color: white;
            border-bottom-right-radius: 4px;
        }

        .message.received {
            align-self: flex-start;
            background: white;
            color: var(--text-dark);
            border-bottom-left-radius: 4px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .message-time {
            font-size: 0.7rem;
            margin-top: 5px;
            opacity: 0.7;
            text-align: right;
        }

        .chat-input-area {
            padding: 20px;
            background: white;
            border-top: 1px solid #f3f4f6;
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .chat-input {
            flex: 1;
            padding: 12px 20px;
            border: 1px solid #e5e7eb;
            border-radius: 30px;
            outline: none;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        .chat-input:focus { border-color: var(--primary); }

        .send-btn {
            background: var(--primary);
            color: white;
            border: none;
            width: 45px; height: 45px;
            border-radius: 50%;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: transform 0.2s;
        }
        .send-btn:hover { transform: scale(1.05); background: var(--primary-hover); }

        .add-btn {
            background: #e5e7eb;
            color: var(--text-dark);
            border: none;
            width: 40px; height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem;
            font-weight: bold;
            transition: background 0.2s;
        }
        .add-btn:hover { background: #d1d5db; }

        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #9ca3af;
        }
        .empty-state-icon { font-size: 4rem; margin-bottom: 20px; opacity: 0.5; }

        /* 移动端适配 */
        @media (max-width: 768px) {
            .chat-container { margin: 0; padding: 0; height: calc(100vh - 70px); border-radius: 0; }
            .contacts-sidebar { width: 100%; border-radius: 0; }
            .chat-area { 
                position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                z-index: 2000; transform: translateX(100%); transition: transform 0.3s ease;
                border-radius: 0;
            }
            .chat-area.active { transform: translateX(0); }
            .back-btn { display: block !important; margin-right: 10px; cursor: pointer; font-size: 1.2rem; }
        }
        .back-btn { display: none; }

    </style>
</head>
<body>

<nav class="navbar">
    <a href="../../index.html" class="logo">
        <img src="../../Public_Assets/images/TreasureGo_Logo.png" alt="Logo" class="logo-img">
        Treasure<span>Go</span>
    </a>

    <div class="nav-actions">
        <button class="nav-btn" onclick="window.location.href='../../Module_Transaction_Fund/pages/Fund_Request.html'">Top Up</button>
        <button id="nav-admin-btn" class="nav-btn" style="display: none;" onclick="window.location.href='admin_dashboard.php'">Admin Dashboard</button>
        <button class="nav-btn" onclick="window.location.href='../../Module_Transaction_Fund/pages/Orders_Management.html'">Orders</button>

        <button id="nav-login-btn" class="btn-primary" onclick="window.location.href='login.php'">Login</button>

        <div id="nav-user-menu" class="menu-container" style="display: none;">

            <div id="nav-avatar" class="dots-btn" onclick="window.location.href='profile.php'">
                👤
            </div>
            <div class="dropdown-content">
                <a href="profile.php" class="dropdown-item">My Profile</a>
                <a href="#" class="dropdown-item">Settings</a>
                <a href="../api/logout.php" class="dropdown-item" style="color: #ef4444;">Log Out</a>
            </div>
        </div>
    </div>
</nav>

<div class="chat-container">
    <!-- 左侧联系人列表 -->
    <div class="contacts-sidebar">
        <div class="contacts-header">
            <h2>Messages</h2>
        </div>
        <div class="contacts-list" id="contactsList">
            <!-- 动态加载 -->
            <div style="text-align: center; padding: 20px; color: #9ca3af;">Loading...</div>
        </div>
    </div>

    <!-- 右侧聊天区域 -->
    <div class="chat-area" id="chatArea">
        <div class="empty-state" id="emptyState">
            <div class="empty-state-icon">💬</div>
            <h3>Select a conversation to start chatting</h3>
        </div>

        <div class="chat-content" id="chatContent" style="display: none; height: 100%; flex-direction: column;">
            <div class="chat-header">
                <div class="chat-user-info">
                    <div class="back-btn" onclick="closeChat()">←</div>
                    <img src="" alt="" class="chat-header-avatar" id="currentChatAvatar">
                    <div class="chat-header-name" id="currentChatName">User Name</div>
                </div>
                
                <!-- 商品快照区域 -->
                <div class="product-context-card" id="productContextCard">
                    <div class="p-ctx-close" onclick="removeProductContext(event)" title="Remove product context">×</div>
                    <img src="" class="p-ctx-img" id="pCtxImg">
                    <div class="p-ctx-info">
                        <div class="p-ctx-title" id="pCtxTitle">Product Title</div>
                        <div class="p-ctx-price" id="pCtxPrice">$0.00</div>
                    </div>
                    <a href="#" class="p-ctx-btn" id="pCtxBtn">Buy Now</a>
                </div>
            </div>
            
            <div class="messages-container" id="messagesContainer">
                <!-- 消息动态加载 -->
            </div>

            <div class="chat-input-area">
                <button class="add-btn" onclick="document.getElementById('imageInput').click()">+</button>
                <input type="file" id="imageInput" accept="image/*" style="display: none;" onchange="uploadImage(this)">
                <input type="text" class="chat-input" id="messageInput" placeholder="Type a message...">
                <button class="send-btn" onclick="sendMessage()">➤</button>
            </div>
        </div>
    </div>
</div>

<script>
    let currentContactId = null;
    let currentProductId = null; // 新增：当前聊天的商品ID
    let pollingInterval = null;

    // 1. 加载联系人列表
    async function loadConversations() {
        try {
            const res = await fetch('../api/chat/get_conversations.php');
            const json = await res.json();
            
            const listEl = document.getElementById('contactsList');
            listEl.innerHTML = '';

            // 获取 URL 中的 contact_id 参数
            const urlParams = new URLSearchParams(window.location.search);
            const targetContactId = urlParams.get('contact_id');
            const targetProductId = urlParams.get('product_id'); // 获取商品ID
            
            console.log("Target Contact ID:", targetContactId, "Product ID:", targetProductId); // Debug

            let targetUserFound = false;

            if (json.status === 'success') {
                // 渲染现有对话列表
                if (json.data.length > 0) {
                    json.data.forEach(contact => {
                        // 检查是否匹配目标联系人和商品
                        // 如果 URL 有 product_id，必须匹配 product_id
                        // 如果 URL 没有 product_id，匹配 product_id 为 null 的对话 (或者任意? 暂时严格匹配)
                        const isSameUser = contact.User_ID == targetContactId;
                        const isSameProduct = targetProductId ? contact.Product_ID == targetProductId : !contact.Product_ID;

                        if (targetContactId && isSameUser && isSameProduct) {
                            targetUserFound = true;
                        }
                        renderContactItem(contact, listEl);
                    });
                } else if (!targetContactId) {
                    listEl.innerHTML = '<div style="text-align: center; padding: 20px; color: #9ca3af;">No conversations yet.</div>';
                }

                // 如果 URL 指定了联系人，且不在现有列表中，则手动添加
                if (targetContactId && !targetUserFound) {
                    console.log("Target user/product not in list, loading info..."); // Debug
                    await loadTargetUser(targetContactId, targetProductId, listEl);
                } else if (targetContactId && targetUserFound) {
                    // 如果在列表中，直接打开
                    console.log("Target found in list, opening chat..."); // Debug
                    // 找到对应的用户数据
                    let targetUser = json.data.find(u => u.User_ID == targetContactId && (targetProductId ? u.Product_ID == targetProductId : !u.Product_ID));
                    if (targetUser) {
                        // 优先使用商品图片作为头像
                        const avatar = targetUser.Product_Image_Url || targetUser.User_Avatar_Url;
                        openChat(targetUser.User_ID, targetUser.User_Username, avatar, targetUser.Product_ID);
                    }
                }
            } else {
                console.error("API Error:", json.message);
                alert("API Error: " + json.message);
            }
        } catch (err) {
            console.error("Error loading conversations:", err);
            alert("Error loading chats: " + err.message); // 添加用户可见的报错
        }
    }

    // ... renderContactItem 保持不变 ...

    // 加载目标用户信息（当不在现有对话列表中时）
    async function loadTargetUser(userId, productId, container) {
        try {
            console.log("Fetching user info for:", userId); // Debug
            const res = await fetch(`../api/get_user_public_info.php?user_id=${userId}`);
            const json = await res.json();
            console.log("User info response:", json); // Debug

            let productImageUrl = null;
            if (productId) {
                try {
                    const resProd = await fetch(`../../Module_Product_Ecosystem/api/Get_Products.php?product_id=${productId}`);
                    const jsonProd = await resProd.json();
                    if (jsonProd.success && jsonProd.data.length > 0) {
                        const p = jsonProd.data[0];
                        if (p.Main_Image) productImageUrl = '../../' + p.Main_Image;
                    }
                } catch (e) {
                    console.error("Failed to load product image for avatar", e);
                }
            }

            if (json.status === 'success') {
                const user = json.data;
                // 构造一个伪 contact 对象
                const contact = {
                    User_ID: user.User_ID,
                    User_Username: user.User_Username,
                    User_Avatar_Url: user.User_Avatar_Url,
                    Product_ID: productId,
                    Product_Image_Url: productImageUrl,
                    Message_Content: '', // 空消息
                    Created_At: null,
                    Is_Read: 1,
                    Sender_ID: 0
                };
                
                // 移除 "No conversations yet" 提示（如果存在）
                if (container.innerHTML.includes('No conversations yet')) {
                    container.innerHTML = '';
                }

                renderContactItem(contact, container);
                // 自动打开
                const avatar = contact.Product_Image_Url || contact.User_Avatar_Url;
                openChat(contact.User_ID, contact.User_Username, avatar, contact.Product_ID);
            } else {
                console.error("Failed to load user info:", json.message);
                alert("Could not load seller information.");
            }
        } catch (err) {
            console.error("Failed to load target user info", err);
            alert("Error loading user info: " + err.message); // 添加用户可见的报错
        }
    }

    // 渲染单个联系人项
    function renderContactItem(contact, container) {
        const div = document.createElement('div');
        // 只有当 UserID 和 ProductID 都匹配时才激活
        const isActive = currentContactId == contact.User_ID && currentProductId == contact.Product_ID;
        div.className = `contact-item ${isActive ? 'active' : ''}`;
        div.dataset.userId = contact.User_ID; // 方便查找
        div.dataset.productId = contact.Product_ID || ''; // 新增
        
        div.onclick = () => {
            const avatar = contact.Product_Image_Url || contact.User_Avatar_Url;
            openChat(contact.User_ID, contact.User_Username, avatar, contact.Product_ID);
        };
        
        // 头像处理：优先显示商品图片
        let avatarUrl = contact.Product_Image_Url || contact.User_Avatar_Url;
        let avatarHtml = '';
        if (avatarUrl) {
            avatarHtml = `<img src="${avatarUrl}" class="contact-avatar">`;
        } else {
            avatarHtml = `<div class="contact-avatar">${contact.User_Username.charAt(0).toUpperCase()}</div>`;
        }

        // 未读红点
        const unreadHtml = contact.Is_Read == 0 && contact.Sender_ID != <?php echo $_SESSION['user_id']; ?> 
            ? `<span class="unread-badge">NEW</span>` : '';

        div.innerHTML = `
            ${avatarHtml}
            <div class="contact-info">
                <div style="display:flex; justify-content:space-between;">
                    <div class="contact-name">${contact.User_Username}</div>
                    <div class="contact-time">${contact.Created_At ? formatTime(contact.Created_At) : ''}</div>
                </div>
                <div style="display:flex; justify-content:space-between;">
                    <div class="contact-last-msg">${contact.Message_Type === 'image' ? '[Image]' : (contact.Message_Content || 'Start a conversation')}</div>
                    ${unreadHtml}
                </div>
            </div>
        `;
        // 如果是新对话，插入到最前面
        if (!contact.Created_At) {
             container.insertBefore(div, container.firstChild);
        } else {
             container.appendChild(div);
        }
    }



    // 移除商品上下文
    function removeProductContext(e) {
        e.stopPropagation(); // 防止触发其他点击事件
        document.getElementById('productContextCard').style.display = 'none';
        if (currentContactId) {
            localStorage.removeItem('chat_context_' + currentContactId);
        }
    }

    // 加载商品上下文信息
    async function loadProductContext(productId) {
        try {
            // 注意路径：chat.php 在 Module_User_Account_Management/pages/
            // API 在 Module_Product_Ecosystem/api/
            const res = await fetch(`../../Module_Product_Ecosystem/api/Get_Products.php?product_id=${productId}`);
            const json = await res.json();
            
            if (json.success && json.data.length > 0) {
                const product = json.data[0];
                const card = document.getElementById('productContextCard');
                
                // 设置图片
                let imgUrl = '';
                if (product.Main_Image) {
                    // 处理路径：API返回的可能是相对路径，需要调整
                    // 假设 Main_Image 是 "Module_Product_Ecosystem/Public_Product_Images/..."
                    // 我们在 chat.php (Module_User_Account_Management/pages/)
                    // 需要变成 "../../Module_Product_Ecosystem/Public_Product_Images/..."
                    // 或者如果已经是绝对路径则不动
                    imgUrl = '../../' + product.Main_Image;
                }
                document.getElementById('pCtxImg').src = imgUrl;
                
                // 设置标题和价格
                document.getElementById('pCtxTitle').innerText = product.Product_Title;
                document.getElementById('pCtxPrice').innerText = '$' + parseFloat(product.Product_Price).toFixed(2);
                
                // 设置购买链接
                document.getElementById('pCtxBtn').href = `../../Module_Product_Ecosystem/pages/Order_Confirmation.html?id=${product.Product_ID}`;
                
                // 显示卡片
                card.style.display = 'flex';
            }
        } catch (err) {
            console.error("Error loading product context:", err);
        }
    }

    // 2. 打开聊天窗口
    function openChat(userId, username, avatarUrl, productId = null) {
        // 如果已经是当前聊天，就不重复加载（防止循环）
        if (currentContactId == userId && currentProductId == productId) return;

        currentContactId = userId;
        currentProductId = productId;
        
        // UI 切换
        document.getElementById('emptyState').style.display = 'none';
        document.getElementById('chatContent').style.display = 'flex';
        document.getElementById('chatArea').classList.add('active'); // 移动端显示

        // 设置头部信息
        document.getElementById('currentChatName').innerText = username;
        const avatarEl = document.getElementById('currentChatAvatar');
        if (avatarUrl) {
            avatarEl.src = avatarUrl;
        } else {
            avatarEl.src = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" fill="%23e5e7eb"/><text x="50" y="50" font-family="Arial" font-size="40" fill="%236b7280" text-anchor="middle" dy=".3em">' + username.charAt(0).toUpperCase() + '</text></svg>';
        }

        // 加载商品上下文
        if (currentProductId) {
            loadProductContext(currentProductId);
        } else {
            document.getElementById('productContextCard').style.display = 'none';
        }

        // 加载消息
        loadMessages();
        
        // 开启轮询
        if (pollingInterval) clearInterval(pollingInterval);
        pollingInterval = setInterval(loadMessages, 3000);

        // 手动更新列表项的选中状态
        document.querySelectorAll('.contact-item').forEach(item => {
            const isMatch = item.dataset.userId == userId && item.dataset.productId == (productId || '');
            item.classList.toggle('active', isMatch);
            // 如果是当前选中的，清除未读红点（视觉上）
            if (isMatch) {
                const badge = item.querySelector('.unread-badge');
                if (badge) badge.remove();
            }
        });
    }

    // 3. 加载消息记录
    async function loadMessages() {
        if (!currentContactId) return;

        // Product chat requires product_id; don't fall back to support (Product_ID IS NULL).
        if (!currentProductId) {
            console.warn('Missing currentProductId; refusing to load messages without product_id to avoid mixing with support chat.');
            const container = document.getElementById('messagesContainer');
            if (container) {
                container.innerHTML = '<div style="padding:16px;color:#9CA3AF;">This conversation is missing a Product_ID, so messages can\'t be loaded here.</div>';
            }
            return;
        }

        try {
            let url = `../api/chat/get_messages.php?contact_id=${currentContactId}&product_id=${currentProductId}`;

            const res = await fetch(url);
            const json = await res.json();
            
            const container = document.getElementById('messagesContainer');
            // 简单的全量更新（实际生产环境应该做增量更新或 Diff）
            // 为了保持滚动位置，可以先记录 scrollHeight
            const isAtBottom = container.scrollHeight - container.scrollTop === container.clientHeight;

            container.innerHTML = '';

            if (json.status === 'success') {
                const myId = <?php echo $_SESSION['user_id']; ?>;
                
                json.data.forEach(msg => {
                    const div = document.createElement('div');
                    div.className = `message ${msg.Sender_ID == myId ? 'sent' : 'received'}`;
                    
                    let contentHtml = '';
                    if (msg.Message_Type === 'image') {
                        // 处理图片路径
                        // 数据库存的是 ../../Public_Assets/chat_images/xxx.jpg (相对于 api/chat/upload_image.php)
                        // chat.php 在 pages/ 下，所以路径应该是 ../../Public_Assets/chat_images/xxx.jpg
                        // 如果存的是绝对路径或者其他格式，需要调整
                        // 假设存的是 ../../Public_Assets/chat_images/filename.ext
                        contentHtml = `<img src="${msg.Message_Content}" style="max-width: 200px; border-radius: 8px; cursor: pointer;" onclick="window.open(this.src)">`;
                    } else {
                        contentHtml = msg.Message_Content;
                    }

                    div.innerHTML = `
                        ${contentHtml}
                        <div class="message-time">${formatTime(msg.Created_At)}</div>
                    `;
                    container.appendChild(div);
                });

                // 如果之前在底部，或者刚打开，就滚动到底部
                if (isAtBottom || container.children.length === json.data.length) { // 简单判断
                    scrollToBottom();
                }
            }
        } catch (err) {
            console.error(err);
        }
    }

    // 4. 发送消息
    async function sendMessage() {
        const input = document.getElementById('messageInput');
        const content = input.value.trim();
        if (!content || !currentContactId) return;

        try {
            const res = await fetch('../api/chat/send_message.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    receiver_id: currentContactId,
                    product_id: currentProductId,
                    message: content
                })
            });
            const json = await res.json();
            
            if (json.status === 'success') {
                input.value = '';
                loadMessages(); // 立即刷新
                loadConversations(); // 刷新列表以更新最后一条消息
                scrollToBottom();
            }
        } catch (err) {
            alert('Failed to send message');
        }
    }

    // 6. 上传图片
    async function uploadImage(input) {
        if (input.files && input.files[0]) {
            const file = input.files[0];
            if (!currentContactId) {
                alert("Please select a chat first.");
                return;
            }

            const formData = new FormData();
            formData.append('image', file);
            formData.append('receiver_id', currentContactId);
            if (currentProductId) {
                formData.append('product_id', currentProductId);
            }

            try {
                const res = await fetch('../api/chat/upload_image.php', {
                    method: 'POST',
                    body: formData
                });
                const json = await res.json();

                if (json.status === 'success') {
                    loadMessages();
                    loadConversations();
                    scrollToBottom();
                } else {
                    alert('Upload failed: ' + json.message);
                }
            } catch (err) {
                console.error(err);
                alert('Upload error');
            }
            
            // 清空 input，允许重复上传同一张图
            input.value = '';
        }
    }

    // 辅助函数
    function scrollToBottom() {
        const container = document.getElementById('messagesContainer');
        container.scrollTop = container.scrollHeight;
    }

    function formatTime(timestamp) {
        const date = new Date(timestamp);
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function closeChat() {
        document.getElementById('chatArea').classList.remove('active');
        currentContactId = null;
        if (pollingInterval) clearInterval(pollingInterval);
    }

    // 回车发送
    document.getElementById('messageInput').addEventListener('keypress', (e) => {
        if (e.key === 'Enter') sendMessage();
    });

    // 5. 页面加载时检查 Session 状态 (复用 index.html 逻辑)
    document.addEventListener('DOMContentLoaded', async () => {
        const loginBtn = document.getElementById('nav-login-btn');
        const userMenu = document.getElementById('nav-user-menu');
        const avatarBtn = document.getElementById('nav-avatar');
        const adminBtn = document.getElementById('nav-admin-btn');

        // 安全检查：确保所有元素都存在
        if (!loginBtn || !userMenu || !avatarBtn) {
            console.error('Navigation elements not found');
            return;
        }

        try {
            const res = await fetch('../api/session_status.php');
            const data = await res.json();

            if (data.is_logged_in) {
                // 更新全局变量
                // isUserLoggedIn = true; // chat.php 本身就是强制登录的，所以这里不需要这个变量

                // UI 更新
                loginBtn.style.display = 'none';
                userMenu.style.display = 'inline-block';

                if (data.user) {
                    // 设置头像
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

                    // 如果是管理员，显示 Admin Dashboard 按钮
                    if (adminBtn && data.user.role === 'admin') {
                        adminBtn.style.display = 'inline-block';
                    } else if (adminBtn) {
                        adminBtn.style.display = 'none';
                    }
                }
            } else {
                // 未登录 (理论上 chat.php 会被 require_login() 拦截，但为了保险起见)
                loginBtn.style.display = 'inline-block';
                userMenu.style.display = 'none';
                if (adminBtn) {
                    adminBtn.style.display = 'none';
                }
            }
        } catch (err) {
            console.error("Session check failed:", err);
            loginBtn.style.display = 'inline-block';
            userMenu.style.display = 'none';
            if (adminBtn) {
                adminBtn.style.display = 'none';
            }
        }
    });

    // 初始化
    loadConversations();

</script>
</body>
</html>
