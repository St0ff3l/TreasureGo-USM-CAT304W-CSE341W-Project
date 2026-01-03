<?php
// Enable error display (for debugging)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/utils.php';
// Enforce login
require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>TreasureGO - Chat</title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@400;700&family=Poppins:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../Public_Assets/css/headerbar.css">
    <style>
        /* ========================================= */
        /* Reuse core styles from index.html         */
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

        /* ========================================= */
        /* Disable pinch zoom and overscroll behavior */
        /* ========================================= */
        html, body {
            height: 100%;
            width: 100%;
            margin: 0;
            padding: 0;
            overflow: hidden;
            touch-action: pan-x pan-y;
            -webkit-text-size-adjust: 100%;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-dark);
            height: 100vh;
            overflow: hidden; /* Prevent entire page scrolling */
            display: flex;
            flex-direction: column;
        }

        /* Remove old Navbar styles, use styles provided by headerbar.js */
        /* However, fine-tuning might be needed to compatible with chat.php specific layout */

        /* ========================================= */
        /* Chat Layout Styles                        */
        /* ========================================= */
        .chat-container {
            flex: 1;
            display: flex;
            max-width: 1400px;
            width: 100%;
            margin: 20px auto;
            padding: 0 20px;
            gap: 20px;
            height: calc(100vh - 100px); /* Subtract Navbar height */
        }

        /* Left Sidebar (Contact List) */
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

        /* Right Chat Area */
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
            flex-direction: column; /* Change to vertical layout to accommodate product card */
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

        /* Product Snapshot Card Styles */
        .product-context-card {
            display: none; /* Hidden by default */
            background: #f9fafb;
            border-radius: 12px;
            padding: 10px;
            margin-top: 5px;
            border: 1px solid #e5e7eb;
            align-items: center;
            gap: 12px;
            width: 100%;
            position: relative; /* For positioning the close button */
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

        /* Mobile Adaptation */
        @media (max-width: 768px) {
            body {
                height: 100dvh; /* Use dynamic height to adapt to mobile browser address bar */
                overflow: hidden;
            }

            .navbar {
                padding: 0.8rem 1rem;
            }
            .logo { font-size: 1.2rem; }
            .logo-img { width: 32px; height: 32px; }

            /* Hide some nav buttons on mobile, only keep avatar/login */
            .nav-actions { gap: 10px; }
            .nav-actions .nav-btn { display: none; }

            .chat-container {
                margin: 0;
                padding: 0;
                height: calc(100dvh - 65px); /* Subtract Navbar height */
                border-radius: 0;
            }

            .contacts-sidebar {
                width: 100%;
                border-radius: 0;
                box-shadow: none;
            }

            .contacts-sidebar.hidden {
                display: none;
            }

            .chat-area {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 2000;
                transform: translateX(100%);
                transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                border-radius: 0;
                background: #fff;
                display: flex;
                flex-direction: column;
            }

            .chat-area.active { transform: translateX(0); }

            .back-btn {
                display: flex !important;
                align-items: center;
                justify-content: center;
                width: 36px;
                height: 36px;
                margin-right: 5px;
                cursor: pointer;
                font-size: 1.2rem;
                border-radius: 50%;
            }

            .product-context-card { padding: 8px; }
            .p-ctx-img { width: 40px; height: 40px; }
            .p-ctx-title { font-size: 0.85rem; }

            .chat-input-area { padding: 10px; gap: 8px; }
            .add-btn { width: 36px; height: 36px; font-size: 1.2rem; }
            .send-btn { width: 36px; height: 36px; }
            .chat-input { padding: 8px 15px; }
        }
        .back-btn { display: none; }

    </style>
</head>
<body>

<div class="chat-container">
    <div class="contacts-sidebar">
        <div class="contacts-header">
            <h2>Messages</h2>
        </div>
        <div class="contacts-list" id="contactsList">
            <div style="text-align: center; padding: 20px; color: #9ca3af;">Loading...</div>
        </div>
    </div>

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

                <div class="product-context-card" id="productContextCard">
                    <!-- Close button removed to keep the product context persistent -->
                    <img src="" class="p-ctx-img" id="pCtxImg">
                    <div class="p-ctx-info">
                        <div class="p-ctx-title" id="pCtxTitle">Product Title</div>
                        <div class="p-ctx-price" id="pCtxPrice">$0.00</div>
                    </div>
                    <a href="#" class="p-ctx-btn" id="pCtxBtn">Buy Now</a>
                </div>
            </div>

            <div class="messages-container" id="messagesContainer">
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
    let currentProductId = null; // New: Current chat Product ID
    let pollingInterval = null;

    function setMobileView(mode) {
        if (window.innerWidth > 768) return;
        const sidebar = document.querySelector('.contacts-sidebar');
        const chatArea = document.getElementById('chatArea');
        if (!sidebar || !chatArea) return;

        if (mode === 'chat') {
            sidebar.classList.add('hidden');
            chatArea.classList.add('active');
        } else {
            sidebar.classList.remove('hidden');
            chatArea.classList.remove('active');
        }
    }

    // 1. Load contact list
    async function loadConversations() {
        try {
            const res = await fetch('../api/chat/get_conversations.php');
            const json = await res.json();

            const listEl = document.getElementById('contactsList');
            listEl.innerHTML = '';

            // Get contact_id parameter from URL
            const urlParams = new URLSearchParams(window.location.search);
            const targetContactId = urlParams.get('contact_id');
            const targetProductId = urlParams.get('product_id'); // Get Product ID

            console.log("Target Contact ID:", targetContactId, "Product ID:", targetProductId); // Debug

            let targetUserFound = false;

            if (json.status === 'success') {
                // Render existing conversation list
                if (json.data.length > 0) {
                    json.data.forEach(contact => {
                        // Check if matches target contact and product
                        // If URL has product_id, it must match product_id
                        // If URL has no product_id, match conversation where product_id is null (or any? strictly match for now)
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

                // If URL specifies a contact and it's not in the existing list, add manually
                if (targetContactId && !targetUserFound) {
                    console.log("Target user/product not in list, loading info..."); // Debug
                    await loadTargetUser(targetContactId, targetProductId, listEl);
                } else if (targetContactId && targetUserFound) {
                    // If in the list, open directly
                    console.log("Target found in list, opening chat..."); // Debug
                    // Find corresponding user data
                    let targetUser = json.data.find(u => u.User_ID == targetContactId && (targetProductId ? u.Product_ID == targetProductId : !u.Product_ID));
                    if (targetUser) {
                        // Prioritize product image as avatar
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
            alert("Error loading chats: " + err.message); // Add user-visible error alert
        }
    }

    // ... renderContactItem remains unchanged ...

    // Load target user info (when not in existing conversation list)
    async function loadTargetUser(userId, productId, container) {
        try {
            console.log("Fetching user info for:", userId);
            const res = await fetch(`../api/get_user_public_info.php?user_id=${userId}`);
            const json = await res.json();

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

                // Construct a pseudo contact object
                const contact = {
                    User_ID: user.User_ID,
                    User_Username: user.User_Username,
                    // ✅ Fix: Use correct database field name User_Profile_Image
                    User_Profile_Image: user.User_Profile_Image,
                    Product_ID: productId,
                    Product_Image_Url: productImageUrl,
                    Message_Content: '',
                    Created_At: null,
                    Is_Read: 1,
                    Sender_ID: 0
                };

                if (container.innerHTML.includes('No conversations yet')) {
                    container.innerHTML = '';
                }

                renderContactItem(contact, container);

                // ✅ Fix: Avatar retrieval logic
                let avatar = contact.Product_Image_Url || contact.User_Profile_Image;
                // Simple path fix: If avatar exists and doesn't start with http or relative path, add ../../
                if (avatar && !avatar.startsWith('http') && !avatar.startsWith('../')) {
                    avatar = '../../' + avatar;
                }

                openChat(contact.User_ID, contact.User_Username, avatar, contact.Product_ID);
            } else {
                console.error("Failed to load user info:", json.message);
                alert("Could not load seller information.");
            }
        } catch (err) {
            console.error("Failed to load target user info", err);
            alert("Error loading user info: " + err.message);
        }
    }

    // Render individual contact item
    function renderContactItem(contact, container) {
        const div = document.createElement('div');
        const isActive = currentContactId == contact.User_ID && currentProductId == contact.Product_ID;
        div.className = `contact-item ${isActive ? 'active' : ''}`;
        div.dataset.userId = contact.User_ID;
        div.dataset.productId = contact.Product_ID || '';

        // ===============================================
        // 🛠️ Core Logic: Left list prioritizes "Product Image", but passes "User Avatar" to chat header
        // ===============================================

        // 1. Define two avatar paths
        //    A. Displayed in List (List Image): Prioritize product image -> Show user image if none
        let listImg = contact.Product_Image_Url || contact.User_Profile_Image;

        //    B. Displayed in Chat Header (Header Image): Always show user avatar
        let headerImg = contact.User_Profile_Image;

        // 2. Path fix helper function (uniformly add ../../)
        const fixPath = (p) => {
            if (p) {
                if (!p.startsWith('http') && !p.startsWith('/') && !p.startsWith('../')) {
                    return '../../' + p;
                }
                // If starts with assets/, might need to complete Public_Assets
                if (p.startsWith('assets/')) {
                    return '../../Public_Assets/' + p;
                }
            }
            return p;
        };

        // Fix paths
        listImg = fixPath(listImg);
        headerImg = fixPath(headerImg);
        // ===============================================

        // 3. Click event: Pass headerImg (User Avatar) to openChat
        div.onclick = () => {
            openChat(contact.User_ID, contact.User_Username, headerImg, contact.Product_ID);
        };

        // 4. List render: Use listImg (Product Image)
        let avatarHtml = '';
        if (listImg) {
            avatarHtml = `<img src="${listImg}" class="contact-avatar" onerror="this.onerror=null;this.parentNode.innerHTML='<div class=\'contact-avatar\'>${(contact.User_Username || '?').charAt(0).toUpperCase()}</div>'">`;
        } else {
            avatarHtml = `<div class="contact-avatar">${(contact.User_Username || '?').charAt(0).toUpperCase()}</div>`;
        }

        // Unread message red dot
        const myId = <?php echo isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'null'; ?>;
        const unreadHtml = contact.Is_Read == 0 && contact.Sender_ID != myId
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

        // Insert into list
        if (!contact.Created_At) {
            container.insertBefore(div, container.firstChild);
        } else {
            container.appendChild(div);
        }
    }


    // Remove product context
    function removeProductContext(e) {
        e.stopPropagation(); // Prevent triggering other click events
        document.getElementById('productContextCard').style.display = 'none';
        if (currentContactId) {
            localStorage.removeItem('chat_context_' + currentContactId);
        }
    }

    // Load product context information
    async function loadProductContext(productId) {
        try {
            // Note path: chat.php is in Module_User_Account_Management/pages/
            // API is in Module_Product_Ecosystem/api/
            const res = await fetch(`../../Module_Product_Ecosystem/api/Get_Products.php?product_id=${productId}`);
            const json = await res.json();

            if (json.success && json.data.length > 0) {
                const product = json.data[0];
                const card = document.getElementById('productContextCard');

                // Set image
                let imgUrl = '';
                if (product.Main_Image) {
                    // Handle path: API might return relative path, needs adjustment
                    // Assuming Main_Image is "Module_Product_Ecosystem/Public_Product_Images/..."
                    // We are in chat.php (Module_User_Account_Management/pages/)
                    // Needs to become "../../Module_Product_Ecosystem/Public_Product_Images/..."
                    // Or leave it if it's already an absolute path
                    imgUrl = '../../' + product.Main_Image;
                }
                document.getElementById('pCtxImg').src = imgUrl;

                // Set title and price
                document.getElementById('pCtxTitle').innerText = product.Product_Title;
                document.getElementById('pCtxPrice').innerText = 'RM' + parseFloat(product.Product_Price).toFixed(2);

                // Set buy link
                const buyBtn = document.getElementById('pCtxBtn');
                buyBtn.href = `../../Module_Product_Ecosystem/pages/Order_Confirmation.html?id=${product.Product_ID}`;
                buyBtn.onclick = (e) => e.stopPropagation(); // Prevent triggering card click

                // Set card click to jump to detail page
                card.style.cursor = 'pointer';
                card.onclick = () => {
                    window.location.href = `../../Module_Product_Ecosystem/pages/Product_Detail.html?id=${product.Product_ID}`;
                };

                // Show card
                card.style.display = 'flex';
            }
        } catch (err) {
            console.error("Error loading product context:", err);
        }
    }

    // 2. Open chat window
    function openChat(userId, username, avatarUrl, productId = null) {
        // If already current chat, do not reload (prevent loop)
        if (currentContactId == userId && currentProductId == productId) return;

        currentContactId = userId;
        currentProductId = productId;

        // UI Switch
        document.getElementById('emptyState').style.display = 'none';
        document.getElementById('chatContent').style.display = 'flex';
        document.getElementById('chatArea').classList.add('active'); // Show on mobile
        setMobileView('chat');

        // Set header info
        document.getElementById('currentChatName').innerText = username;
        const avatarEl = document.getElementById('currentChatAvatar');
        if (avatarUrl) {
            avatarEl.src = avatarUrl;
        } else {
            avatarEl.src = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" fill="%23e5e7eb"/><text x="50" y="50" font-family="Arial" font-size="40" fill="%236b7280" text-anchor="middle" dy=".3em">' + username.charAt(0).toUpperCase() + '</text></svg>';
        }

        // Load product context
        if (currentProductId) {
            loadProductContext(currentProductId);
        } else {
            document.getElementById('productContextCard').style.display = 'none';
        }

        // Load messages
        loadMessages();

        // Start polling
        if (pollingInterval) clearInterval(pollingInterval);
        pollingInterval = setInterval(loadMessages, 3000);

        // Manually update selection status of list items
        document.querySelectorAll('.contact-item').forEach(item => {
            const isMatch = item.dataset.userId == userId && item.dataset.productId == (productId || '');
            item.classList.toggle('active', isMatch);
            // If currently selected, clear unread red dot (visually)
            if (isMatch) {
                const badge = item.querySelector('.unread-badge');
                if (badge) badge.remove();
            }
        });
    }

    // 3. Load message history
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
            // Simple full update (production environment should do incremental update or Diff)
            // To maintain scroll position, record scrollHeight first
            const isAtBottom = container.scrollHeight - container.scrollTop === container.clientHeight;

            container.innerHTML = '';

            if (json.status === 'success') {
                const myId = <?php echo $_SESSION['user_id']; ?>;

                json.data.forEach(msg => {
                    const div = document.createElement('div');
                    div.className = `message ${msg.Sender_ID == myId ? 'sent' : 'received'}`;

                    let contentHtml = '';
                    if (msg.Message_Type === 'image') {
                        // Handle image path
                        // Database stores ../../Public_Assets/chat_images/xxx.jpg (relative to api/chat/upload_image.php)
                        // chat.php is under pages/, so path should be ../../Public_Assets/chat_images/xxx.jpg
                        // If stored as absolute path or other format, needs adjustment
                        // Assuming stored as ../../Public_Assets/chat_images/filename.ext
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

                // If previously at bottom, or just opened, scroll to bottom
                if (isAtBottom || container.children.length === json.data.length) { // Simple check
                    scrollToBottom();
                }
            }
        } catch (err) {
            console.error(err);
        }
    }

    // 4. Send message
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
                loadMessages(); // Refresh immediately
                loadConversations(); // Refresh list to update last message
                scrollToBottom();
            }
        } catch (err) {
            alert('Failed to send message');
        }
    }

    // 6. Upload image
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

            // Clear input, allow uploading the same image again
            input.value = '';
        }
    }

    // Helper functions
    function scrollToBottom() {
        const container = document.getElementById('messagesContainer');
        container.scrollTop = container.scrollHeight;
    }

    function formatTime(timestamp) {
        const date = new Date(timestamp);
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function closeChat() {
        setMobileView('list');
        currentContactId = null;
        currentProductId = null;
        if (pollingInterval) clearInterval(pollingInterval);
    }

    // Send on Enter
    document.getElementById('messageInput').addEventListener('keypress', (e) => {
        if (e.key === 'Enter') sendMessage();
    });

    // Import headerbar.js and initialize
    const script = document.createElement('script');
    script.src = '../../Public_Assets/js/headerbar.js';
    script.onload = () => {
        if (window.TreasureGoHeaderbar) {
            window.TreasureGoHeaderbar.mount({ basePath: '../../' });
        }
    };
    document.body.appendChild(script);

    // ==========================================
    // 📱 Mobile keyboard handling (Visual Viewport)
    // ==========================================
    if (window.visualViewport) {
        function resizeHandler() {
            const chatArea = document.getElementById('chatArea');
            if (!chatArea) return;

            if (window.innerWidth <= 768 && chatArea.classList.contains('active')) {
                const vv = window.visualViewport;
                chatArea.style.height = vv.height + 'px';
                chatArea.style.transform = `translateY(${vv.offsetTop}px)`;
                scrollToBottom();
            }
        }

        window.visualViewport.addEventListener('resize', resizeHandler);
        window.visualViewport.addEventListener('scroll', resizeHandler);
    }

    const messageInput = document.getElementById('messageInput');
    messageInput.addEventListener('focus', function() {
        setTimeout(() => {
            scrollToBottom();
        }, 300);
    });

    const originalCloseChat = closeChat;
    closeChat = function() {
        const chatArea = document.getElementById('chatArea');
        if (chatArea) {
            chatArea.style.height = '100%';
            chatArea.style.transform = '';
        }
        originalCloseChat();
    };

    // Initialize
    loadConversations();

</script>
</body>
</html>
