/*
 * Order Details - Address module
 * Responsibilities:
 * - load and render order shipping address (or meetup location)
 * - seller inline return-address selection for approving return&refund
 * - add new address modal (local only)
 */

(function (global) {
  'use strict';

  const state = {
    currentOrderAddress: null,

    // inline return address selection
    selectedReturnAddressStr: null,
    fetchedAddressList: [],
    currentOrderIdForAddr: null,
  };

  // =========================================================
  // 1. Load & Render Buyer's Shipping Address (Top Section)
  // =========================================================

  async function loadOrderAddress(order, ORDER_ADDRESS_API) {
    state.currentOrderAddress = null;
    try {
      const res = await fetch(`${ORDER_ADDRESS_API}?order_id=${encodeURIComponent(order.Orders_Order_ID)}`, {
        credentials: 'include',
      });
      const json = await res.json();

      if (!json || !json.success) {
        state.currentOrderAddress = null;
        return;
      }

      // Normalize possible shapes
      const payload =
          (json.data && Array.isArray(json.data) ? json.data[0] : json.data) ||
          (json.address && Array.isArray(json.address) ? json.address[0] : json.address) ||
          json.result ||
          null;

      state.currentOrderAddress = payload && typeof payload === 'object' ? payload : null;
    } catch (_) {
      state.currentOrderAddress = null;
    }
  }

  function renderAddressBlock(order) {
    const escapeHtml = global.OrderDetailsOrder?.escapeHtml || ((s) => String(s ?? ''));

    const deliveryMethod = String(order.Delivery_Method || '').toLowerCase();
    const addrIdFromOrder =
        order.Address_ID ??
        order.address_id ??
        order.Orders_Address_ID ??
        order.orders_address_id ??
        order.Shipping_Address_ID ??
        order.shipping_address_id ??
        null;

    // Meetup: show product location card
    if (deliveryMethod === 'meetup') {
      const location = order.Product_Location || 'Location not specified';
      return `
        <div class="info-section">
          <div class="section-label">Meet-up Location (Product Location)</div>
          <div class="address-item">
            <div class="addr-content">
              <div class="addr-main-text"><i class="ri-map-pin-user-line"></i>${escapeHtml(location)}</div>
              <div style="font-size:0.85rem; color:#6B7280; margin-top:4px;">Please arrange a meeting place via chat.</div>
            </div>
          </div>
        </div>
      `;
    }

    // Shipping: render address object if fetched
    if (state.currentOrderAddress && typeof state.currentOrderAddress === 'object') {
      const a = state.currentOrderAddress;
      const receiverName = a.Address_Receiver_Name ?? a.receiver_name ?? a.name ?? '-';
      const phone = a.Address_Phone_Number ?? a.phone ?? a.phone_number ?? '-';
      const detail = a.Address_Detail ?? a.address_detail ?? a.detail ?? '-';
      const addressId = a.Address_ID ?? a.address_id ?? addrIdFromOrder;
      const tagHtml = String(a.Address_Is_Default) === '1' ? `<span class="addr-tag"><i class="ri-star-line"></i>Default</span>` : '';

      return `
        <div class="info-section">
          <div class="section-label">Shipping Address (Buyer)</div>
          <div class="address-item">
            <div class="addr-content">
              <div class="addr-main-text"><i class="ri-map-pin-2-line"></i>${escapeHtml(detail)}${tagHtml}</div>
              <div class="addr-meta-row">
                <span class="addr-name-bold"><i class="ri-user-line"></i>${escapeHtml(receiverName)}</span>
                <span class="addr-phone-text"><i class="ri-phone-line"></i>${escapeHtml(phone)}</span>
                ${addressId ? `<span class="addr-phone-text" style="opacity:0.75;"><i class="ri-hashtag"></i>ID: #${escapeHtml(addressId)}</span>` : ''}
              </div>
            </div>
          </div>
        </div>
      `;
    }

    // Fallback if no address loaded
    if (!addrIdFromOrder) {
      return `
        <div class="info-section">
          <div class="section-label">Shipping Address (Buyer)</div>
          <div class="address-item">
            <div class="addr-content">
              <div class="addr-main-text"><i class="ri-handshake-line"></i>Meet-up order (no shipping address)</div>
            </div>
          </div>
        </div>
      `;
    }

    return `
      <div class="info-section">
        <div class="section-label">Shipping Address (Buyer)</div>
        <div class="address-item">
          <div class="addr-content">
            <div class="addr-main-text"><i class="ri-map-pin-2-line"></i>Address ID: #${escapeHtml(addrIdFromOrder)} (unable to load details)</div>
          </div>
        </div>
      </div>
    `;
  }

  // =========================================================
  // 2. Seller Return Address Selection (Bottom Logic)
  // =========================================================

  async function toggleAddressSelection(orderId) {
    state.currentOrderIdForAddr = orderId;

    const container = document.getElementById(`addr-container-${orderId}`);
    const btnGroup = document.getElementById(`action-btns-${orderId}`);
    if (!container || !btnGroup) return;

    // Toggle visibility
    if (container.style.display === 'block') {
      container.style.display = 'none';
      btnGroup.style.display = 'flex';
      return;
    }

    btnGroup.style.display = 'none';
    container.style.display = 'block';
    container.innerHTML = '<div style="text-align:center; padding:20px; color:#6B7280;">Loading addresses...</div>';

    try {
      const res = await fetch('../api/Refund_Actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ action: 'get_seller_addresses' }),
      });
      const data = await res.json();

      if (data && data.success) {
        state.fetchedAddressList = Array.isArray(data.addresses) ? data.addresses : [];
        renderInlineAddressList(orderId);
      } else {
        const msg = data?.message || 'Failed to load addresses';
        container.innerHTML = `<div style="color:#EF4444; padding:15px; text-align:center;">${global.OrderDetailsOrder?.escapeHtml ? global.OrderDetailsOrder.escapeHtml(msg) : msg}</div>`;
      }
    } catch (_) {
      container.innerHTML = '<div style="color:#EF4444; padding:15px; text-align:center;">Error loading addresses (Network).</div>';
    }
  }

  /**
   * Updated: Renders the Modern Card Style UI
   */
  function renderInlineAddressList(orderId) {
    const container = document.getElementById(`addr-container-${orderId}`);
    if (!container) return;

    const escapeHtml = global.OrderDetailsOrder?.escapeHtml || ((s) => String(s ?? ''));

    // 1. Header & Scroll Container Start
    let html = `
      <div class="inline-addr-header">
        <i class="ri-map-pin-range-line"></i> Select Return Address
      </div>
      <div class="addr-list-scroll">
    `;

    state.selectedReturnAddressStr = null;

    if (state.fetchedAddressList.length === 0) {
      html += `<div style="text-align:center; color:#94A3B8; padding:20px;">No addresses found. Please add one.</div>`;
    }

    // 2. Loop through addresses
    state.fetchedAddressList.forEach((addr, index) => {
      const isDefault = index === 0;

      const fullDetail = addr.Address_Detail || '';
      const name = addr.Address_Receiver_Name || 'No Name';
      const phone = addr.Address_Phone_Number || 'No Phone';

      // Auto-select the first one
      if (isDefault) {
        state.selectedReturnAddressStr = `${fullDetail}, ${name}, ${phone}`;
      }

      html += `
        <div class="addr-option ${isDefault ? 'selected' : ''}" onclick="selectInlineAddress(this, ${index})">
          <input type="radio" name="inline-addr-${orderId}" ${isDefault ? 'checked' : ''} style="pointer-events:none;" />
          
          <div class="addr-content-inline">
            <div class="addr-title">${escapeHtml(fullDetail)}</div>
            <div class="addr-meta">
              <span><i class="ri-user-smile-line"></i> ${escapeHtml(name)}</span>
              <span style="opacity:0.3">|</span>
              <span><i class="ri-phone-line"></i> ${escapeHtml(phone)}</span>
            </div>
          </div>
        </div>
      `;
    });

    html += `</div>`; // Close scroll container

    // 3. "Use New Address" Button
    html += `
      <button class="btn-new-addr" onclick="openNewAddressModal()">
        <i class="ri-add-circle-line"></i> Use a New Address
      </button>
    `;

    // 4. Footer Actions
    html += `
      <div class="addr-actions-footer">
        <button class="btn" style="background:#F1F5F9; color:#475569; border:1px solid #E2E8F0;" onclick="cancelInlineSelection(${orderId})">
          Cancel
        </button>
        <button class="btn" style="background:#10B981; color:white; box-shadow:0 4px 10px rgba(16, 185, 129, 0.25);" onclick="submitInlineApproval(${orderId})">
          Confirm & Approve
        </button>
      </div>
    `;

    container.innerHTML = html;
  }

  function selectInlineAddress(el, index) {
    // Find the container (parent of the card) to scope the selection
    const container = el.closest('.addr-list-scroll') || el.parentNode;
    const siblings = container.querySelectorAll('.addr-option');

    // Deselect all
    siblings.forEach((sib) => {
      sib.classList.remove('selected');
      const radio = sib.querySelector('input[type="radio"]');
      if (radio) radio.checked = false;
    });

    // Select clicked
    el.classList.add('selected');
    const currentRadio = el.querySelector('input[type="radio"]');
    if (currentRadio) currentRadio.checked = true;

    // Update state
    const addr = state.fetchedAddressList[index];
    if (addr) {
      state.selectedReturnAddressStr = `${addr.Address_Detail}, ${addr.Address_Receiver_Name}, ${addr.Address_Phone_Number}`;
    }
  }

  function cancelInlineSelection(orderId) {
    const container = document.getElementById(`addr-container-${orderId}`);
    const btnGroup = document.getElementById(`action-btns-${orderId}`);

    if (container) container.style.display = 'none';
    if (btnGroup) btnGroup.style.display = 'flex';
  }

  async function submitInlineApproval(orderId) {
    if (!state.selectedReturnAddressStr) return alert('Please select a return address.');

    const confirmMsg = `Approve return request?\n\nBuyer will be instructed to ship to:\n${state.selectedReturnAddressStr}`;
    if (!confirm(confirmMsg)) return;

    try {
      const response = await fetch('../api/Refund_Actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({
          action: 'seller_decision',
          order_id: orderId,
          decision: 'approve',
          refund_type: 'return_refund', // Ensure type is sent
          return_address: state.selectedReturnAddressStr,
        }),
      });
      const result = await response.json();
      if (result && result.success) {
        location.reload();
      } else {
        alert(result?.message || 'Failed to process request.');
      }
    } catch (_) {
      alert('Network error occurred.');
    }
  }

  // =========================================================
  // 3. New Address Modal Logic
  // =========================================================

  function openNewAddressModal() {
    const modal = document.getElementById('addNewAddressModal');
    if (modal) modal.classList.add('active');
  }

  function saveNewAddressLocal() {
    const name = document.getElementById('newAddrName')?.value?.trim();
    const phone = document.getElementById('newAddrPhone')?.value?.trim();
    const detail = document.getElementById('newAddrDetail')?.value?.trim();

    if (!name || !phone || !detail) return alert('Please fill in all fields.');

    // Prepend to local list so it appears first
    state.fetchedAddressList.unshift({
      Address_Receiver_Name: name,
      Address_Phone_Number: phone,
      Address_Detail: detail,
      Address_Is_Default: 0,
    });

    // Close modal & Clear inputs
    const modal = document.getElementById('addNewAddressModal');
    if (modal) modal.classList.remove('active');

    const n = document.getElementById('newAddrName');
    const p = document.getElementById('newAddrPhone');
    const d = document.getElementById('newAddrDetail');
    if (n) n.value = '';
    if (p) p.value = '';
    if (d) d.value = '';

    // Re-render the list to show new item as selected
    if (state.currentOrderIdForAddr !== null && state.currentOrderIdForAddr !== undefined) {
      renderInlineAddressList(state.currentOrderIdForAddr);
    }
  }

  // =========================================================
  // 4. Exports
  // =========================================================

  global.OrderDetailsAddress = {
    loadOrderAddress,
    renderAddressBlock,
    getCurrentOrderAddress: () => state.currentOrderAddress,
    toggleAddressSelection,
    renderInlineAddressList,
    selectInlineAddress,
    cancelInlineSelection,
    submitInlineApproval,
    openNewAddressModal,
    saveNewAddressLocal,
  };

  // Global bindings for inline onclick events
  global.toggleAddressSelection = toggleAddressSelection;
  global.selectInlineAddress = selectInlineAddress;
  global.cancelInlineSelection = cancelInlineSelection;
  global.submitInlineApproval = submitInlineApproval;
  global.openNewAddressModal = openNewAddressModal;
  global.saveNewAddressLocal = saveNewAddressLocal;

})(window);