/*
 * Order Details - Order module
 * Responsibilities:
 * - bootstrap (session + load order)
 * - render order UI (except refund status card)
 * - image gallery
 * - confirm receipt
 */

(function (global) {
  'use strict';

  const state = {
    ORDER_ID: null,
    CURRENT_USER_ID: null,

    API_URL: '../api/Get_User_Orders.php',
    ORDER_ADDRESS_API: '../api/Get_Order_Address.php',

    globalOrderImages: [],
    currentOrderImageIndex: 0,
  };

  function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
  }

  function capitalizeFirst(str) {
    return str ? String(str).charAt(0).toUpperCase() + String(str).slice(1) : '';
  }

  function getStatusClass(status) {
    const key = status ? String(status).toLowerCase() : '';
    const map = {
      paid: 'status-paid',
      completed: 'status-completed',
      processing: 'status-processing',
      cancelled: 'status-cancelled',
      shipped: 'status-processing',
    };
    return map[key] || 'status-processing';
  }

  function parseOrderImages(order) {
    const images = [];

    if (order && order.Main_Image) images.push(`../../${order.Main_Image}`);

    const possibleFields = ['Images', 'images', 'Product_Images', 'gallery', 'all_images', 'All_Images'];
    for (const f of possibleFields) {
      const raw = order ? order[f] : null;
      if (!raw) continue;

      if (Array.isArray(raw)) {
        raw.forEach((x) => {
          if (!x) return;
          const s = String(x);
          images.push(s.startsWith('..') ? s : `../../${s}`);
        });
        continue;
      }

      if (typeof raw === 'string') {
        const s = raw.trim();
        if (!s) continue;

        if ((s.startsWith('[') && s.endsWith(']')) || (s.startsWith('{') && s.endsWith('}'))) {
          try {
            const parsed = JSON.parse(s);
            if (Array.isArray(parsed)) {
              parsed.forEach((x) => x && images.push(String(x).startsWith('..') ? String(x) : `../../${x}`));
            } else if (parsed && typeof parsed === 'object') {
              Object.values(parsed).forEach((x) => x && images.push(String(x).startsWith('..') ? String(x) : `../../${x}`));
            }
          } catch (_) {
            // ignore
          }
          continue;
        }

        if (s.includes(',')) {
          s.split(',')
              .map((x) => x.trim())
              .filter(Boolean)
              .forEach((x) => images.push(x.startsWith('..') ? x : `../../${x}`));
          continue;
        }

        images.push(s.startsWith('..') ? s : `../../${s}`);
      }
    }

    if (images.length === 0) images.push('../../Public_Assets/images/placeholder.png');
    return [...new Set(images)];
  }

  function updateGalleryDisplay() {
    const mainContainer = document.getElementById('mainImageContainer');
    const thumbContainer = document.getElementById('thumbnailContainer');
    if (!mainContainer || !thumbContainer) return;

    if (!Array.isArray(state.globalOrderImages) || state.globalOrderImages.length === 0) {
      state.globalOrderImages = ['../../Public_Assets/images/placeholder.png'];
      state.currentOrderImageIndex = 0;
    }

    const currentSrc = state.globalOrderImages[state.currentOrderImageIndex] || state.globalOrderImages[0];

    mainContainer.innerHTML = `
      <button class="nav-arrow prev-btn" onclick="changeOrderImage(-1)"><i class="ri-arrow-left-s-line"></i></button>
      <img id="mainOrderImage" src="${currentSrc}" alt="Product" />
      <button class="nav-arrow next-btn" onclick="changeOrderImage(1)"><i class="ri-arrow-right-s-line"></i></button>
    `;

    thumbContainer.innerHTML = state.globalOrderImages
        .map((src, idx) => {
          const active = idx === state.currentOrderImageIndex ? 'active' : '';
          return `<div class="thumb ${active}" onclick="setOrderImage(${idx})"><img src="${src}" alt="thumb" /></div>`;
        })
        .join('');

    const mainImg = document.getElementById('mainOrderImage');
    if (mainImg) {
      mainImg.onerror = () => {
        mainImg.src = '../../Public_Assets/images/placeholder.png';
      };
    }

    thumbContainer.querySelectorAll('img').forEach((img) => {
      img.addEventListener(
          'error',
          () => {
            img.src = '../../Public_Assets/images/placeholder.png';
          },
          { once: true },
      );
    });
  }

  function setOrderImage(index) {
    if (!Array.isArray(state.globalOrderImages) || state.globalOrderImages.length === 0) return;
    const max = state.globalOrderImages.length;
    const i = Math.max(0, Math.min(max - 1, Number(index)));
    state.currentOrderImageIndex = i;
    updateGalleryDisplay();

    const mainImg = document.getElementById('mainOrderImage');
    if (mainImg) {
      mainImg.classList.remove('fade-anim');
      void mainImg.offsetWidth;
      mainImg.classList.add('fade-anim');
    }
  }

  function changeOrderImage(delta) {
    if (!Array.isArray(state.globalOrderImages) || state.globalOrderImages.length === 0) return;
    const max = state.globalOrderImages.length;
    state.currentOrderImageIndex = (state.currentOrderImageIndex + Number(delta) + max) % max;
    updateGalleryDisplay();

    const mainImg = document.getElementById('mainOrderImage');
    if (mainImg) {
      mainImg.classList.remove('fade-anim');
      void mainImg.offsetWidth;
      mainImg.classList.add('fade-anim');
    }
  }

  async function init() {
    const urlParams = new URLSearchParams(window.location.search);
    state.ORDER_ID = urlParams.get('id');

    if (!state.ORDER_ID) {
      const el = document.getElementById('detailContent');
      if (el) el.innerHTML = '<h2 style="text-align:center; color:red;">No Order ID provided.</h2>';
      return;
    }

    try {
      console.log('[OrderDetails] init for order_id=', state.ORDER_ID);

      const sessionRes = await fetch('../../Module_User_Account_Management/api/session_status.php', { credentials: 'include' });
      const sessionData = await sessionRes.json();

      if (!sessionData.is_logged_in) {
        window.location.href = '../../Module_User_Account_Management/pages/login.php';
        return;
      }

      state.CURRENT_USER_ID = sessionData.user.user_id;

      const res = await fetch(state.API_URL, { credentials: 'include' });
      const data = await res.json();
      if (!data.success) throw new Error(data.msg || data.message || 'Failed to load orders');

      const allOrders = [
        ...((data.buying || []).map((o) => ({ ...o, type: 'buy' }))),
        ...((data.selling || []).map((o) => ({ ...o, type: 'sell' }))),
      ];

      const targetOrder = allOrders.find((o) => String(o.Orders_Order_ID) === String(state.ORDER_ID));
      if (!targetOrder) {
        const el = document.getElementById('detailContent');
        if (el) el.innerHTML = '<h2 style="text-align:center; color:red;">Order not found.</h2>';
        return;
      }

      if (global.OrderDetailsAddress && typeof global.OrderDetailsAddress.loadOrderAddress === 'function') {
        await global.OrderDetailsAddress.loadOrderAddress(targetOrder, state.ORDER_ADDRESS_API);
      }

      renderOrder(targetOrder);
    } catch (error) {
      const el = document.getElementById('detailContent');
      const msg = error && error.message ? String(error.message) : 'Error loading details.';
      console.error('[OrderDetails] init failed:', error);
      if (el) {
        el.innerHTML = `
          <div style="text-align:center; padding:50px;">
            <h2 style="color:red; margin-bottom:10px;">Error loading order details</h2>
            <div style="color:#6B7280; font-size:0.95rem;">${escapeHtml(msg)}</div>
          </div>
        `;
      }
    }
  }

  function renderOrder(order) {
    const isBuyer = order.type === 'buy';
    const statusClass = getStatusClass(order.Orders_Status);

    // Parse and load product images from order data
    state.globalOrderImages = parseOrderImages(order);
    state.currentOrderImageIndex = 0;

    // Calculate and display auto-confirmation countdown timer
    let autoConfirmHtml = '';
    const orderStatus = String(order.Orders_Status || '').toLowerCase();

    if (['processing', 'shipped'].includes(orderStatus)) {
      let startTime = null;
      let showCountdown = false;
      let msgPrefix = '';

      if (order.Orders_Shipped_At) {
        startTime = new Date(order.Orders_Shipped_At);
        showCountdown = true;
        msgPrefix = 'Shipped! Auto-confirm in';
      } else if (String(order.Delivery_Method || '').toLowerCase() === 'meetup') {
        startTime = new Date(order.Orders_Created_AT);
        showCountdown = true;
        msgPrefix = 'Meet-up Order. Auto-confirm in';
      } else {
        if (isBuyer) {
          autoConfirmHtml =
              '<div class="auto-confirm-text" style="background:#EEF2FF; color:#4F46E5; border-color:#C7D2FE;">Waiting for seller to ship. 7-day timer starts after shipment.</div>';
        }
      }

      if (showCountdown && startTime && !isNaN(startTime.getTime())) {
        const autoConfirmDate = new Date(startTime.getTime() + 7 * 24 * 60 * 60 * 1000);
        const now = new Date();
        const daysLeft = Math.ceil((autoConfirmDate - now) / (1000 * 3600 * 24));

        if (daysLeft > 0) {
          autoConfirmHtml = `<div class="auto-confirm-text"><strong>${daysLeft} days</strong> remaining for auto-confirmation (${autoConfirmDate.toLocaleDateString()}).</div>`;
        } else {
          autoConfirmHtml =
              '<div class="auto-confirm-text" style="color:#EF4444; border-color:#EF4444; background:#FEF2F2;">Time limit exceeded. Please confirm receipt.</div>';
        }
      }
    }

    // Determine and render action buttons based on refund and dispute status
    let actionButtons = '';
    const hasRefundOrDispute = order.Refund_Status || (order.Dispute_Status && order.Dispute_Status !== 'None');

    if (hasRefundOrDispute && global.OrderDetailsRefund && typeof global.OrderDetailsRefund.renderRefundStatusCard === 'function') {
      actionButtons = global.OrderDetailsRefund.renderRefundStatusCard(order, isBuyer);
    } else if (isBuyer && orderStatus !== 'completed' && orderStatus !== 'cancelled') {
      actionButtons = `
        <div class="actions-box">
          <div style="font-weight:700; margin-bottom:10px;">Actions</div>
          <div class="btn-group">
            <button class="btn btn-confirm" onclick="openConfirmDialog(${Number(order.Orders_Order_ID)})">Confirm Receipt</button>
            <button class="btn btn-refund" onclick="if(window.openRefundPreCheck) window.openRefundPreCheck(${Number(order.Orders_Order_ID)}); else alert('Refund module not ready');">Request Refund</button>
          </div>
          ${autoConfirmHtml}
        </div>
      `;
    } else if (orderStatus === 'completed') {
      actionButtons = `<div class="status-badge status-completed" style="margin-top:20px; width:100%; text-align:center;">Order Completed</div>`;
    }

    // Build tracking number or shipment input field for delivery
    const deliveryMethod = String(order.Delivery_Method || '').toLowerCase();
    const trackingNum = order.Tracking_Number || '';

    let deliveryHtml = '';
    if (deliveryMethod === 'meetup') {
      deliveryHtml = '<span style="color:#6B7280; font-size:0.9rem;">Not Required (Meet-up)</span>';
    } else {
      if (trackingNum) {
        deliveryHtml = `<div class="tracking-display">${escapeHtml(trackingNum)}</div>`;
      } else {
        if (isBuyer) {
          deliveryHtml = '<span style="color:#F59E0B; font-size:0.9rem;">Awaiting Shipment</span>';
        } else {
          if (orderStatus !== 'cancelled' && orderStatus !== 'completed') {
            deliveryHtml = `
              <div class="tracking-group">
                <input type="text" id="trackingInput_${Number(order.Orders_Order_ID)}" class="tracking-input" placeholder="Enter No.">
                <button class="btn-small-upload" onclick="submitTracking(${Number(order.Orders_Order_ID)})">Ship</button>
              </div>
            `;
          } else {
            deliveryHtml = '<span style="color:#9CA3AF;">-</span>';
          }
        }
      }
    }

    // Extract and normalize order details for display
    const condition = order.Product_Condition || order.Condition || order.condition || '-';
    const category = order.Category_Name || order.Category || '-';
    const otherPartyLabel = isBuyer ? 'Seller' : 'Buyer';
    const otherPartyName = isBuyer
        ? order.Seller_Username || `ID: ${escapeHtml(order.Orders_Seller_ID)}`
        : order.Buyer_Username || `ID: ${escapeHtml(order.Orders_Buyer_ID)}`;

    // Use numeric IDs for chat routing.
    const otherPartyUserId = isBuyer ? order.Orders_Seller_ID : order.Orders_Buyer_ID;

    const addressBlockHtml =
        global.OrderDetailsAddress && typeof global.OrderDetailsAddress.renderAddressBlock === 'function'
            ? global.OrderDetailsAddress.renderAddressBlock(order)
            : '';

    const createdAt = order.Orders_Created_AT ? new Date(order.Orders_Created_AT).toLocaleString() : '-';
    const amount = order.Orders_Total_Amount ? Number(order.Orders_Total_Amount) : Number(order.Total_Amount || 0);
    const amountText = !isNaN(amount) ? amount.toFixed(2) : escapeHtml(order.Orders_Total_Amount || order.Total_Amount || '-');

    const html = `
      <div class="detail-header">
        <div class="detail-title">
          <h1>Order #${escapeHtml(order.Orders_Order_ID)}</h1>
          <span>Created: ${escapeHtml(createdAt)}</span>
        </div>
        <span class="status-badge ${statusClass}">${escapeHtml(capitalizeFirst(order.Orders_Status))}</span>
      </div>

      <div class="grid-layout">
        <div class="gallery-container">
          <div class="main-image-box" id="mainImageContainer"></div>
          <div class="thumbnails" id="thumbnailContainer"></div>

          <div style="margin-top: 20px;">
            <button class="btn btn-contact" id="btnContactOtherParty">Contact ${escapeHtml(otherPartyLabel)}</button>
          </div>
        </div>

        <div class="order-info">
          <div class="section-label">Product Details</div>
          <h2 class="product-title-large">${escapeHtml(order.Product_Title || '-') }</h2>
          <span class="price-tag">RM${escapeHtml(amountText)}</span>

          <div class="specs-grid">
            <div class="spec-item"><span class="spec-key">Role</span><span class="spec-value">${isBuyer ? 'Buying' : 'Selling'}</span></div>
            <div class="spec-item"><span class="spec-key">${escapeHtml(otherPartyLabel)}</span><span class="spec-value">${escapeHtml(otherPartyName)}</span></div>
            <div class="spec-item"><span class="spec-key">Condition</span><span class="spec-value">${escapeHtml(capitalizeFirst(condition))}</span></div>
            <div class="spec-item"><span class="spec-key">Category</span><span class="spec-value">${escapeHtml(category)}</span></div>
            <div class="spec-item"><span class="spec-key">Tracking No.</span><span class="spec-value">${deliveryHtml}</span></div>
            <div class="spec-item"><span class="spec-key">Product ID</span><span class="spec-value">#${escapeHtml(order.Product_ID)}</span></div>
          </div>

          ${addressBlockHtml}

          <div class="info-section">
            <div class="section-label">Description</div>
            <div class="description-box">${escapeHtml(order.Product_Description || 'No description provided.')}</div>
          </div>

          ${actionButtons}
        </div>
      </div>
    `;

    const el = document.getElementById('detailContent');
    if (el) el.innerHTML = html;

    // Bind Contact Seller/Buyer button (login check + jump).
    try {
      const btn = document.getElementById('btnContactOtherParty');
      if (btn) {
        btn.addEventListener('click', async () => {
          const contactId = otherPartyUserId;
          if (!contactId) {
            alert('Unable to open chat: missing user id');
            return;
          }

          let currentUserId = state.CURRENT_USER_ID;
          if (!currentUserId) {
            try {
              const sessionRes = await fetch('../../Module_User_Account_Management/api/session_status.php', { credentials: 'include' });
              const sessionData = await sessionRes.json();
              if (!sessionData.is_logged_in) {
                window.location.href = '../../Module_User_Account_Management/pages/login.php';
                return;
              }
              currentUserId = sessionData.user.user_id;
            } catch (_) {
              window.location.href = '../../Module_User_Account_Management/pages/login.php';
              return;
            }
          }

          // If user is trying to contact themselves, hide the button.
          if (String(currentUserId) === String(contactId)) {
            btn.style.display = 'none';
            return;
          }

          const chatUrl = `../../Module_User_Account_Management/pages/chat.php?contact_id=${encodeURIComponent(String(contactId))}&product_id=${encodeURIComponent(String(order.Product_ID || ''))}`;
          window.location.href = chatUrl;
        });
      }
    } catch (_) {
      // ignore
    }

    try {
      const hasAddressSection = !!document.querySelector('#detailContent .info-section .address-item');
      const addrObj = global.OrderDetailsAddress?.getCurrentOrderAddress?.();
      if (!hasAddressSection && addrObj) {
        const specGrid = document.querySelector('#detailContent .specs-grid');
        if (specGrid) {
          specGrid.insertAdjacentHTML('afterend', addressBlockHtml);
        }
      }
    } catch (e) {
      // ignore
    }

    updateGalleryDisplay();
  }

  // --- Action Handler Functions ---

  async function submitTracking(orderId) {
    const input = document.getElementById(`trackingInput_${Number(orderId)}`);
    const tracking = input ? String(input.value || '').trim() : '';
    if (!tracking) return alert('Please enter tracking number');

    if (!confirm('Confirm ship this order with the tracking number?')) return;

    try {
      const response = await fetch('../api/Update_Tracking.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ order_id: orderId, tracking }),
      });
      const result = await response.json();
      if (result && result.success) {
        alert('Marked as shipped successfully.');
        location.reload();
      } else {
        alert(`Failed: ${result?.message || 'Unknown error'}`);
      }
    } catch (e) {
      console.error(e);
      alert('Network error occurred.');
    }
  }

  function openConfirmDialog(orderId) {
    const modal = document.getElementById('secondaryConfirmModal');
    if (!modal) return;
    modal.classList.add('active');

    const btn = document.getElementById('btnRealConfirm');
    if (btn) btn.onclick = () => processConfirmReceipt(orderId);
  }

  function closeSecondaryModal() {
    const modal = document.getElementById('secondaryConfirmModal');
    if (modal) modal.classList.remove('active');
  }

  async function processConfirmReceipt(orderId) {
    closeSecondaryModal();
    try {
      const response = await fetch('../api/Orders_Management.php?action=confirm_receipt', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order_id: orderId, buyer_id: state.CURRENT_USER_ID }),
      });
      const result = await response.json();
      if (result.success) location.reload();
      else alert(result.message || 'Confirm failed');
    } catch (_) {
      alert('Network error');
    }
  }

  // Export
  global.OrderDetailsOrder = {
    init,
    renderOrder,
    escapeHtml,
    capitalizeFirst,
    getStatusClass,
    setOrderImage,
    changeOrderImage,
    openConfirmDialog,
    closeSecondaryModal,
    submitTracking,
  };

  // Legacy globals
  global.setOrderImage = setOrderImage;
  global.changeOrderImage = changeOrderImage;
  global.openConfirmDialog = openConfirmDialog;
  global.closeSecondaryModal = closeSecondaryModal;
  global.submitTracking = submitTracking;

})(window);

