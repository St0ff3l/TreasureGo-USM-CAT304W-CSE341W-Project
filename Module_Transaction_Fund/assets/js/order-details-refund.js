/*
 * Order Details - Refund/After-sales module
 * Responsibilities:
 * - render refund status card HTML
 * - seller actions (approve/reject, confirm return received, refuse and dispute)
 * - buyer return tracking / meetup handover confirmations
 * - seller dispute statement modal submit
 */

(function (global) {
  'use strict';

  const API_SELLER_DISPUTE_SUBMIT = '../../Module_After_Sales_Dispute/api/dispute_seller_submit.php';

  let __sellerDisputeOrderId = null;

  function escapeHtml(value) {
    return global.OrderDetailsOrder?.escapeHtml ? global.OrderDetailsOrder.escapeHtml(value) : String(value ?? '');
  }

  function goToRefundDetail(orderId) {
    window.location.href = `../../Module_After_Sales_Dispute/pages/Refund_Details.html?order_id=${encodeURIComponent(orderId)}`;
  }

  function renderRefundStatusCard(order, isBuyer) {
    const status = order.Refund_Status;
    const type = order.Refund_Type;
    const deliveryMethod = String(order.Delivery_Method || 'shipping').toLowerCase().trim();
    if (!status) return '';

    const typeText = type === 'refund_only' ? 'Refund Only' : 'Return & Refund';

    // 1. Pending Approval (Seller needs to approve)
    if (status === 'pending_approval') {
      const reasonMap = {
        damaged: 'Item Damaged / Defective',
        wrong_item: 'Received Wrong Item',
        not_described: 'Item Not As Described',
        missing_parts: 'Missing Parts / Accessories',
        fake: 'Counterfeit / Fake Item',
        other: 'Other',
      };
      const readableReason = reasonMap[order.Refund_Reason] || order.Refund_Reason || '-';
      const description = order.Refund_Description || 'No description provided.';

      if (isBuyer) {
        return `
          <div class="refund-status-card status-pending">
            <div class="refund-status-header">
              <div class="refund-status-label"><i class="ri-time-line"></i> Refund Request Pending</div>
              <a class="btn-view-details" href="javascript:void(0)" onclick="goToRefundDetail(${Number(order.Orders_Order_ID)})">View</a>
            </div>
            <div class="refund-status-body">
              <div class="refund-info-text">
                <h4>${typeText}</h4>
                <p><strong>Reason:</strong> ${escapeHtml(readableReason)}</p>
                <p>${escapeHtml(description)}</p>
              </div>
            </div>
          </div>
        `;
      }

      // Seller view
      return `
        <div class="refund-status-card status-pending">
          <div class="refund-status-header">
            <div class="refund-status-label"><i class="ri-time-line"></i> Buyer Requested Refund</div>
            <a class="btn-view-details" href="javascript:void(0)" onclick="goToRefundDetail(${Number(order.Orders_Order_ID)})">View</a>
          </div>
          <div class="refund-status-body">
            <div class="refund-info-text">
              <h4>${typeText}</h4>
              <p><strong>Reason:</strong> ${escapeHtml(readableReason)}</p>
            </div>

            <div class="seller-actions-row" id="action-btns-${Number(order.Orders_Order_ID)}">
              <button class="btn btn-confirm" onclick="sellerProcessRefund(${Number(order.Orders_Order_ID)}, 'approve', '${escapeHtml(type)}')">Approve</button>
              <button class="btn btn-warn" onclick="sellerProcessRefund(${Number(order.Orders_Order_ID)}, 'reject', '${escapeHtml(type)}')">Reject</button>
            </div>

            <div id="addr-container-${Number(order.Orders_Order_ID)}" class="inline-addr-box" style="display:none;"></div>
          </div>
        </div>
      `;
    }

    // 2. Awaiting Return / Confirm (Return Process)
    if (status === 'awaiting_return' || status === 'awaiting_confirm') {

      // 🔥 FIX: Check if tracking number exists in DB response
      const returnTracking = order.Return_Tracking_Number || order.return_tracking_number || '';

      // A) Meet-up Logic
      if (deliveryMethod === 'meetup') {
        return `
          <div class="refund-status-card status-return">
            <div class="refund-status-header">
              <div class="refund-status-label"><i class="ri-exchange-line"></i> Return in Progress</div>
              <a class="btn-view-details" href="javascript:void(0)" onclick="goToRefundDetail(${Number(order.Orders_Order_ID)})">View</a>
            </div>
            <div class="refund-status-body">
              <div class="refund-info-text">
                <h4>Meet-up Return</h4>
                <p>Please arrange a meet-up handover.</p>
              </div>
              <div class="btn-group">
                ${
            isBuyer
                ? `<button class="btn btn-confirm" onclick="confirmReturnHandover(${Number(order.Orders_Order_ID)})">I Handed Over</button>`
                : ''
        }
                ${
            !isBuyer
                ? `<button class="btn btn-confirm" onclick="sellerConfirmReturnReceived(${Number(order.Orders_Order_ID)})">Received Item</button>`
                : ''
        }
              </div>
            </div>
          </div>
        `;
      }

      // B) Shipping Logic (Buyer Side)
      if (isBuyer) {
        // If tracking already submitted -> Show info instead of input
        if (returnTracking) {
          return `
            <div class="refund-status-card status-return">
              <div class="refund-status-header">
                <div class="refund-status-label"><i class="ri-truck-line"></i> Return Shipped</div>
                <a class="btn-view-details" href="javascript:void(0)" onclick="goToRefundDetail(${Number(order.Orders_Order_ID)})">View</a>
              </div>
              <div class="refund-status-body">
                <div class="refund-info-text" style="width:100%;">
                  <h4>Waiting for Seller</h4>
                  <p>You have submitted the return tracking.</p>
                  
                  <div style="margin-top:15px; padding:12px; background:#F3F4F6; border-radius:8px; display:flex; align-items:center; gap:10px;">
                    <i class="ri-barcode-box-line" style="color:#6B7280; font-size:1.2rem;"></i>
                    <div>
                      <div style="font-size:0.75rem; color:#9CA3AF; text-transform:uppercase;">Tracking Number</div>
                      <div style="font-size:1.1rem; font-weight:700; color:#374151; font-family:monospace;">${escapeHtml(returnTracking)}</div>
                    </div>
                  </div>
                  
                  <div style="margin-top:10px; color:#F59E0B; font-size:0.9rem; font-weight:600;">
                    ⏳ Seller will confirm receipt shortly.
                  </div>
                </div>
              </div>
            </div>
          `;
        }
        // If no tracking yet -> Show input
        else {
          return `
            <div class="refund-status-card status-return">
              <div class="refund-status-header">
                <div class="refund-status-label"><i class="ri-truck-line"></i> Return Shipping</div>
                <a class="btn-view-details" href="javascript:void(0)" onclick="goToRefundDetail(${Number(order.Orders_Order_ID)})">View</a>
              </div>
              <div class="refund-status-body">
                <div class="refund-info-text">
                  <h4>Awaiting Return</h4>
                  <p>Please ship the item back and upload tracking no.</p>
                </div>
                <div class="btn-group">
                  <div class="tracking-group">
                    <input id="returnTrackingInput" class="tracking-input" placeholder="Tracking Number e.g. JNT..." />
                    <button class="btn-small-upload" onclick="submitReturnTracking(${Number(order.Orders_Order_ID)})">Upload</button>
                  </div>
                </div>
              </div>
            </div>
          `;
        }
      }

      // C) Shipping Logic (Seller Side)
      return `
        <div class="refund-status-card status-return">
          <div class="refund-status-header">
            <div class="refund-status-label"><i class="ri-truck-line"></i> Return In Progress</div>
            <a class="btn-view-details" href="javascript:void(0)" onclick="goToRefundDetail(${Number(order.Orders_Order_ID)})">View</a>
          </div>
          <div class="refund-status-body">
            <div class="refund-info-text">
              <h4>Awaiting Return</h4>
              <p>Buyer is returning the item.</p>
              ${returnTracking ? `<p style="margin-top:5px;"><strong>Tracking:</strong> ${escapeHtml(returnTracking)}</p>` : ''}
            </div>
            <div class="btn-group">
              <button class="btn btn-confirm" onclick="sellerConfirmReturnReceived(${Number(order.Orders_Order_ID)})">Confirm Received</button>
              <button class="btn btn-warn" onclick="sellerRefuseReturnReceived(${Number(order.Orders_Order_ID)})">Refuse & Dispute</button>
            </div>
          </div>
        </div>
      `;
    }

    // 3. Completed
    if (status === 'completed') {
      const msg = isBuyer ? 'Refund returned to wallet.' : 'Refund returned to buyer.';
      return `
        <div class="refund-success-card">
          <div class="refund-success-icon"><i class="ri-check-line"></i></div>
          <div class="refund-success-content">
            <h3>Refund Completed</h3>
            <p>${escapeHtml(msg)}</p>
          </div>
        </div>
      `;
    }

    // 4. Rejected
    if (status === 'rejected') {
      const attempt = parseInt(order.Request_Attempt || '1', 10);
      const canResubmit = isBuyer && attempt < 2;

      const rejectMsg =
          order.Seller_Reject_Reason_Text || order.Seller_Reject_Reason_Code
              ? `<div class="reason-box"><strong>Seller Rejection Reason:</strong><br>${escapeHtml(
                  order.Seller_Reject_Reason_Text || order.Seller_Reject_Reason_Code,
              )}</div>`
              : '';

      return `
        <div class="refund-status-card status-closed">
          <div class="refund-status-header">
            <div class="refund-status-label"><i class="ri-close-circle-line"></i> Refund Rejected</div>
            <a class="btn-view-details" href="javascript:void(0)" onclick="goToRefundDetail(${Number(order.Orders_Order_ID)})">View</a>
          </div>
          <div class="refund-status-body">
            <div class="refund-info-text">
              <h4>${typeText}</h4>
              <p>The seller rejected your request.</p>
              ${rejectMsg}
            </div>
            <div class="btn-group" style="margin-top:15px;">
              ${canResubmit ? `<button class="btn btn-refund" onclick="openRefundModal(${Number(order.Orders_Order_ID)})">Resubmit</button>` : ''}
              <button class="btn btn-warn" onclick="goToRefundDetail(${Number(order.Orders_Order_ID)})">Open Dispute</button>
            </div>
          </div>
        </div>
      `;
    }

    // 5. Dispute
    if (status === 'dispute_in_progress') {
      // We keep users on Order Details. Buyer and Seller enter different dispute flows.

      const hasBuyerReturnTracking = !!(order.Return_Tracking_Number || order.return_tracking_number);

      const disputeBtn = isBuyer
        ? `<button class="btn" style="background:#111827; color:white; justify-content:center;" onclick="goToBuyerDispute(${Number(
            order.Orders_Order_ID,
        )}, ${Number(hasBuyerReturnTracking)})">Dispute to Platform Support</button>`
        : `<button class="btn" style="background:#111827; color:white; justify-content:center;" onclick="goToSellerStatement(${Number(
            order.Orders_Order_ID,
        )})">Dispute to Platform Support</button>`;

      return `
        <div class="refund-status-card status-closed">
          <div class="refund-status-header">
            <div class="refund-status-label"><i class="ri-alert-line"></i> Dispute In Progress</div>
            <a class="btn-view-details" href="javascript:void(0)" onclick="goToRefundDetail(${Number(order.Orders_Order_ID)})">View Details</a>
          </div>
          <div class="refund-status-body">
            <div class="refund-info-text">
              <h4>Under Review</h4>
              <p>Please wait for the dispute decision.</p>
            </div>
            <div class="btn-group">
              ${disputeBtn}
            </div>
          </div>
        </div>
      `;
    }

    return '';
  }

  function goToBuyerDispute(orderId, hasBuyerReturnTracking) {
    const oid = encodeURIComponent(orderId);
    // If buyer has uploaded a return tracking number, it means goods were shipped back.
    // Dispute page for "after receive return" stage should be used.
    const url = Number(hasBuyerReturnTracking)
      ? `../../Module_After_Sales_Dispute/pages/Dispute_Reject_After_Receive_Return.html?order_id=${oid}`
      : `../../Module_After_Sales_Dispute/pages/Dispute_Reject_Return.html?order_id=${oid}`;
    window.location.href = url;
  }

  function goToSellerStatement(orderId) {
    window.location.href = `../../Module_After_Sales_Dispute/pages/Dispute_Seller_Statement.html?order_id=${encodeURIComponent(orderId)}`;
  }

  // =========================================
  // Seller Actions
  // =========================================

  async function sellerProcessRefund(orderId, action, type) {
    let confirmMsg = '';
    if (action === 'approve') {
      confirmMsg =
          type === 'refund_only'
              ? '⚠️ Approve Refund Only?\nMoney will be refunded to buyer immediately.'
              : '⚠️ Accept Return?\nBuyer will be notified to return the item.';
    } else {
      confirmMsg = '❌ Reject this refund request?';
    }

    if (!confirm(confirmMsg)) return;

    let reject_reason_code = null;
    let reject_reason_text = null;

    if (action === 'reject') {
      reject_reason_code = prompt('Reject reason (short code):', 'other');
      if (reject_reason_code === null) return;
      reject_reason_text = prompt('Reject reason details (optional):', '');
      if (reject_reason_text === null) return;
    }

    if (action === 'approve' && type !== 'refund_only') {
      if (global.toggleAddressSelection) {
        await global.toggleAddressSelection(orderId);
      } else {
        alert('Address module not loaded.');
      }
      return;
    }

    try {
      const response = await fetch('../api/Refund_Actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({
          action: 'seller_decision',
          order_id: orderId,
          decision: action,
          reject_reason_code,
          reject_reason_text,
        }),
      });
      const result = await response.json();
      if (result && result.success) location.reload();
      else alert(result?.message || 'Failed');
    } catch (_) {
      alert('Network error');
    }
  }

  async function sellerConfirmReturnReceived(orderId) {
    if (!confirm('⚠️ Confirm received the returned item?\n\nThis will release the refund immediately.')) return;

    try {
      const response = await fetch('../api/Refund_Actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ action: 'seller_confirm_return_received', order_id: orderId }),
      });
      const res = await response.json();
      if (res && res.success) location.reload();
      else alert(res?.message || 'Failed');
    } catch (_) {
      alert('Network error');
    }
  }

  async function sellerRefuseReturnReceived(orderId) {
    const preset = prompt('Refuse reason (short code):', 'other');
    if (preset === null) return;
    const detail = prompt('Please describe why you refuse:', '');
    if (detail === null) return;
    if (!confirm('Confirm refuse and open dispute?')) return;

    try {
      const response = await fetch('../api/Refund_Actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({
          action: 'seller_refuse_return_received',
          order_id: orderId,
          reason_code: preset,
          reason_text: detail,
        }),
      });
      const result = await response.json();
      if (result && result.success) location.reload();
      else alert(result?.message || 'Failed');
    } catch (_) {
      alert('Network error');
    }
  }

  async function submitReturnTracking(orderId) {
    const tracking = document.getElementById('returnTrackingInput')?.value;
    if (!tracking) return alert('Please enter tracking number');
    if (!confirm('Submit return tracking number?')) return;

    try {
      const res = await fetch('../api/Refund_Actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ action: 'submit_return_tracking', order_id: orderId, tracking }),
      });
      const data = await res.json();
      if (data && data.success) location.reload();
      else alert(data?.message || 'Failed');
    } catch (_) {
      alert('Network error');
    }
  }

  async function confirmReturnHandover(orderId) {
    if (!confirm('Have you handed over the item?')) return;

    try {
      const res = await fetch('../api/Refund_Actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ action: 'confirm_return_handover', order_id: orderId }),
      });
      const data = await res.json();
      if (data && data.success) location.reload();
      else alert(data?.message || 'Failed');
    } catch (_) {
      alert('Network error');
    }
  }

  // =========================================
  // Seller Dispute Statement
  // =========================================
  // (Moved to a dedicated page: Module_After_Sales_Dispute/pages/Dispute_Seller_Statement.html)

  global.OrderDetailsRefund = {
    renderRefundStatusCard,
    goToRefundDetail,
    goToSellerStatement,
    goToBuyerDispute,

    sellerProcessRefund,
    sellerConfirmReturnReceived,
    sellerRefuseReturnReceived,
    submitReturnTracking,
    confirmReturnHandover,
  };

  // legacy globals
  global.goToRefundDetail = goToRefundDetail;
  global.sellerProcessRefund = sellerProcessRefund;
  global.sellerConfirmReturnReceived = sellerConfirmReturnReceived;
  global.sellerRefuseReturnReceived = sellerRefuseReturnReceived;
  global.submitReturnTracking = submitReturnTracking;
  global.confirmReturnHandover = confirmReturnHandover;
  global.goToSellerStatement = goToSellerStatement;
  global.goToBuyerDispute = goToBuyerDispute;

})(window);
