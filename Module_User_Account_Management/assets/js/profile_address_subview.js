/* Address subview module for Module_User_Account_Management profile shell.
 * Depends on StatusDialog (if present) but falls back to alert/confirm.
 */

(function (global) {
  'use strict';

  function escapeHtml(str) {
    return String(str ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function createAddressSubview() {
    let baseApi = '../api';
    let containerId = 'address-list-container';
    let rootEl = null;

    function getModalEl() {
      return document.getElementById('address-modal');
    }

    // --- Return-to-order logic ---
    function getReturnUrl() {
      try {
        // 1) Prefer querystring: profile.php?return=...#address
        const params = new URLSearchParams(global.location.search || '');
        const qsRet = params.get('return');
        if (qsRet) return String(qsRet);

        // 2) Also support return inside hash: profile.php#address&return=... or #address?return=...
        const rawHash = String(global.location.hash || '').replace(/^#/, '');
        if (!rawHash) return '';

        const idx = rawHash.indexOf('?');
        const idx2 = rawHash.indexOf('&');
        const cut = idx === -1 ? idx2 : (idx2 === -1 ? idx : Math.min(idx, idx2));
        const tail = cut === -1 ? '' : rawHash.slice(cut + 1);
        if (!tail) return '';

        const hashParams = new URLSearchParams(tail);
        const hRet = hashParams.get('return');
        return hRet ? String(hRet) : '';
      } catch (_) {
        return '';
      }
    }

    function isSafeReturnUrl(ret) {
      // Allow only same-origin relative URLs (starts with /) or relative paths (no scheme).
      if (!ret) return false;
      const trimmed = ret.trim();
      if (!trimmed) return false;

      // block javascript: and data:
      const lowered = trimmed.toLowerCase();
      if (lowered.startsWith('javascript:') || lowered.startsWith('data:')) return false;

      // absolute URL? allow only same origin
      try {
        const url = new URL(trimmed, global.location.origin);
        return url.origin === global.location.origin;
      } catch (_) {
        return false;
      }
    }

    function showReturnButtonIfNeeded() {
      const row = document.getElementById('addr-return-row');
      if (!row) return;

      const ret = getReturnUrl();
      const ok = isSafeReturnUrl(ret);
      row.style.display = ok ? 'block' : 'none';

      // lightweight self-check: set tooltip so we can verify what URL was parsed
      const btn = row.querySelector('[data-action="return-to-order"]');
      if (btn) btn.title = ok ? `return: ${ret}` : 'No return parameter';
    }

    function goBackToReturnUrl() {
      const ret = getReturnUrl();
      if (!isSafeReturnUrl(ret)) return;

      // Keep it same-origin
      const url = new URL(ret, global.location.origin);
      global.location.href = url.pathname + url.search + url.hash;
    }

    function ensureStatusDialog() {
      if (typeof global.StatusDialog === 'undefined') {
        console.warn('StatusDialog not found; address subview will fallback to alert().');
      }
    }

    function toastSuccess(title, msg, okText, cb) {
      if (global.StatusDialog?.success) return global.StatusDialog.success(title, msg, okText, cb);
      alert(msg);
      if (typeof cb === 'function') cb();
    }

    function toastFail(title, msg) {
      if (global.StatusDialog?.fail) return global.StatusDialog.fail(title, msg);
      alert(msg);
    }

    function confirmDialog(title, msg, confirmText, cancelText, onConfirm) {
      if (global.StatusDialog?.confirm) {
        return global.StatusDialog.confirm(title, msg, confirmText, cancelText, onConfirm, true);
      }
      if (confirm(msg)) onConfirm();
    }

    async function fetchAddressList() {
      try {
        const res = await fetch(`${baseApi}/get_addresses.php`, { cache: 'no-store' });
        const json = await res.json();
        if (json.status === 'success') {
          renderAddressList(Array.isArray(json.data) ? json.data : []);
        } else {
          toastFail('Error', json.message || 'Failed to load addresses.');
        }
      } catch (err) {
        console.error('Fetch Address Error:', err);
        toastFail('Network Error', 'Check your connection.');
      }
    }

    function renderAddressList(list) {
      const container = document.getElementById(containerId);
      if (!container) return;
      container.innerHTML = '';

      list.forEach((addr) => {
        const div = document.createElement('div');
        div.className = 'address-item';

        const isDefault = (addr.Address_Is_Default == 1);
        const tagHtml = isDefault ? `<span class="addr-tag">Default</span>` : '';

        div.innerHTML = `
          <div class="addr-content">
            <div class="addr-main-text">
              <i class="ri-map-pin-2-line"></i>${escapeHtml(addr.Address_Detail)}
            </div>
            <div class="addr-meta-row">
              <span class="addr-name-bold"><i class="ri-user-line"></i>${escapeHtml(addr.Address_Receiver_Name)}</span>
              <span class="addr-phone-text"><i class="ri-phone-line"></i>${escapeHtml(addr.Address_Phone_Number)}</span>
              ${tagHtml}
            </div>
          </div>

          <div class="addr-actions-right">
            ${isDefault ? '' : `
              <button class="icon-btn" title="Set as Default" data-action="set-default" data-id="${addr.Address_ID}">
                <i class="ri-star-line"></i>
              </button>
            `}
            <button class="icon-btn" title="Edit" data-action="edit" data-id="${addr.Address_ID}">
              <i class="ri-edit-2-line"></i>
            </button>
            <button class="icon-btn delete" title="Delete" data-action="delete" data-id="${addr.Address_ID}">
              <i class="ri-delete-bin-line"></i>
            </button>
          </div>
        `;

        div.dataset.addr = JSON.stringify(addr);
        container.appendChild(div);
      });
    }

    function openAddressModal(data = null) {
      const modal = getModalEl();
      if (!modal) return;

      const title = document.getElementById('modal-title');
      const idIn = document.getElementById('modal_addr_id');
      const nameIn = document.getElementById('modal_addr_name');
      const phoneIn = document.getElementById('modal_addr_phone');
      const detailIn = document.getElementById('modal_addr_detail');
      const defaultCheck = document.getElementById('modal_addr_default');

      if (data) {
        if (title) title.innerText = 'Edit Address';
        if (idIn) idIn.value = data.Address_ID ?? '';
        if (nameIn) nameIn.value = data.Address_Receiver_Name ?? '';
        if (phoneIn) phoneIn.value = data.Address_Phone_Number ?? '';
        if (detailIn) detailIn.value = data.Address_Detail ?? '';
        if (defaultCheck) defaultCheck.checked = (data.Address_Is_Default == 1);
      } else {
        if (title) title.innerText = 'Add New Address';
        if (idIn) idIn.value = '';
        if (nameIn) nameIn.value = '';
        if (phoneIn) phoneIn.value = '';
        if (detailIn) detailIn.value = '';
        if (defaultCheck) defaultCheck.checked = false;
      }

      modal.classList.add('open');
    }

    function closeAddressModal() {
      const modal = getModalEl();
      if (!modal) return;
      modal.classList.remove('open');
    }

    async function setDefaultAddress(addressId) {
      const item = document.querySelector(`.address-item .addr-actions-right button[data-id="${addressId}"]`)?.closest('.address-item');
      if (!item?.dataset?.addr) return;

      let addr;
      try { addr = JSON.parse(item.dataset.addr); } catch { return; }

      const payload = {
        Address_ID: addr.Address_ID,
        Address_Receiver_Name: addr.Address_Receiver_Name,
        Address_Phone_Number: addr.Address_Phone_Number,
        Address_Detail: addr.Address_Detail,
        Address_Is_Default: 1
      };

      try {
        const res = await fetch(`${baseApi}/save_address.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.status === 'success') {
          toastSuccess('Updated', 'Default address updated.', 'OK', fetchAddressList);
        } else {
          toastFail('Error', json.message || 'Failed to set default address.');
        }
      } catch (err) {
        console.error(err);
        toastFail('Network Error', 'Check your connection.');
      }
    }

    async function handleAddressSubmit(e) {
      e.preventDefault();

      const payload = {
        Address_ID: document.getElementById('modal_addr_id')?.value || null,
        Address_Receiver_Name: document.getElementById('modal_addr_name')?.value || '',
        Address_Phone_Number: document.getElementById('modal_addr_phone')?.value || '',
        Address_Detail: document.getElementById('modal_addr_detail')?.value || '',
        Address_Is_Default: document.getElementById('modal_addr_default')?.checked ? 1 : 0
      };

      closeAddressModal();

      try {
        const res = await fetch(`${baseApi}/save_address.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.status === 'success') {
          toastSuccess('Saved', 'Address updated successfully.', 'OK', fetchAddressList);
        } else {
          toastFail('Error', json.message || 'Failed to save address.');
        }
      } catch (err) {
        console.error(err);
        toastFail('Network Error', 'Check your connection.');
      }
    }

    function deleteAddress(id) {
      confirmDialog('Delete Address?', 'This cannot be undone.', 'Delete', 'Cancel', async () => {
        try {
          const res = await fetch(`${baseApi}/delete_address.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ Address_ID: id })
          });
          const json = await res.json();
          if (json.status === 'success') {
            toastSuccess('Deleted', 'Address removed.', 'OK', fetchAddressList);
          } else {
            toastFail('Error', json.message || 'Failed to delete address.');
          }
        } catch (err) {
          console.error(err);
          toastFail('Error', 'Network error.');
        }
      });
    }

    function handleClickDelegation(e) {
      // Return to Order Confirmation
      const retBtn = e.target.closest('[data-action="return-to-order"]');
      if (retBtn) {
        goBackToReturnUrl();
        return;
      }

      const openRow = e.target.closest('[data-action="open-add-address"]');
      if (openRow) {
        openAddressModal(null);
        return;
      }

      const closeBtn = e.target.closest('[data-action="close-address-modal"]');
      if (closeBtn) {
        closeAddressModal();
        return;
      }

      const btn = e.target.closest('button[data-action]');
      if (!btn) return;

      const action = btn.dataset.action;
      const item = btn.closest('.address-item');
      const addrJson = item?.dataset?.addr;

      if (action === 'edit') {
        try {
          const addr = JSON.parse(addrJson);
          openAddressModal(addr);
        } catch (err) {
          console.error('Parse address failed:', err);
          toastFail('Error', 'Failed to open address editor.');
        }
        return;
      }

      if (action === 'delete') {
        const id = Number(btn.dataset.id);
        if (Number.isFinite(id)) deleteAddress(id);
        return;
      }

      if (action === 'set-default') {
        const id = Number(btn.dataset.id);
        if (Number.isFinite(id)) setDefaultAddress(id);
      }
    }

    function bindEvents() {
      document.removeEventListener('click', handleClickDelegation);
      document.addEventListener('click', handleClickDelegation);

      const form = document.getElementById('address-form');
      if (form) {
        form.removeEventListener('submit', handleAddressSubmit);
        form.addEventListener('submit', handleAddressSubmit);
      }

      const modal = getModalEl();
      if (modal) {
        modal.addEventListener('click', (e) => {
          if (e.target === modal) closeAddressModal();
        });
      }
    }

    function mount(opts) {
      baseApi = opts?.baseApi || baseApi;
      containerId = opts?.containerId || containerId;
      rootEl = opts?.rootEl || null;

      ensureStatusDialog();
      bindEvents();
      showReturnButtonIfNeeded();
      fetchAddressList();
    }

    return {
      mount,
      fetchAddressList,
      openAddressModal,
      closeAddressModal
    };
  }

  global.AddressSubview = createAddressSubview();
})(window);
