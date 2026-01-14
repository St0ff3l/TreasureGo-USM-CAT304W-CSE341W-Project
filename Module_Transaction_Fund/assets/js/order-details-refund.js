/*
 * Order Details - Refund/After-sales module
 * Responsibilities:
 * - Render refund and dispute status cards
 * - Handle buyer refund request flow
 * - Handle seller refund decision and dispute response
 * - Navigate to refund detail and dispute pages
 */

(function (global) {
  'use strict';

  // State variables for refund modal
  let currentRefundOrderId = null;
  let hasReceivedGoods = 0; // 0=Not received, 1=Received
  let modalMode = 'buyer'; // Values: 'buyer' or 'seller'

  // Refund reason options when buyer has not received goods
  const reasonsNotReceived = [
    {val: 'logistics_stuck', txt: 'Logistics stuck / Not moving'},
    {val: 'not_received', txt: 'Did not receive package (Lost)'},
    {val: 'wrong_address', txt: 'Seller sent to wrong address'},
    {val: 'other', txt: 'Other'}
  ];

  const reasonsReceived = [
    {val: 'damaged', txt: 'Item Damaged / Defective'},
    {val: 'wrong_item', txt: 'Received Wrong Item'},
    {val: 'not_described', txt: 'Item Not As Described'},
    {val: 'missing_parts', txt: 'Missing Parts / Accessories'},
    {val: 'fake', txt: 'Counterfeit / Fake Item'},
    {val: 'other', txt: 'Other'}
  ];

  // Seller reason options for refusing refund (when goods not received)
  const sellerReasonsNotReceived = [
    {val: 'fake_tracking', txt: 'Fake Tracking Number / Invalid'},
    {val: 'empty_package', txt: 'Received Empty Package'},
    {val: 'not_received', txt: 'Did Not Receive Anything'},
    {val: 'other', txt: 'Other'}
  ];

  const sellerReasonsReceived = [
    {val: 'returned_wrong_item', txt: 'Buyer Returned Wrong Item'},
    {val: 'damaged_by_buyer', txt: 'Item Damaged by Buyer'},
    {val: 'parts_missing', txt: 'Returned Item Incomplete'},
    {val: 'other', txt: 'Other'}
  ];

  function escapeHtml(value) {
    return global.OrderDetailsOrder?.escapeHtml ? global.OrderDetailsOrder.escapeHtml(value) : String(value ?? '');
  }

  // Try to format seller's return address for buyers during return shipping.
  // Source priority:
  // 1) Address module current address object (selected by seller on approve)
  // 2) Common order fields (best-effort)
  function getReturnAddressHtml() {
    try {
      const addr = global.OrderDetailsAddress?.getCurrentOrderAddress?.();
      if (addr) {
        const name = addr.Address_Receiver_Name || addr.receiver_name || addr.Receiver_Name || addr.name || '';
        const phone = addr.Address_Phone_Number || addr.phone || addr.Phone_Number || addr.phone_number || '';
        const detail = addr.Address_Detail || addr.full_address || addr.address_detail || addr.detail || addr.address || '';
        if (String(name + phone + detail).trim()) {
          return `
            <div style="margin-top:10px; padding:10px; background:#FFF7ED; border:1px solid #FED7AA; border-radius:8px;">
              <div style="font-size:0.75rem; color:#9A3412; font-weight:700; margin-bottom:6px;">RETURN ADDRESS</div>
              ${name ? `<div style="font-weight:700; color:#7C2D12;">${escapeHtml(name)}</div>` : ''}
              ${phone ? `<div style="color:#7C2D12;">${escapeHtml(phone)}</div>` : ''}
              ${detail ? `<div style="color:#7C2D12; margin-top:6px; white-space:pre-line;">${escapeHtml(detail)}</div>` : ''}
            </div>
          `;
        }
      }
    } catch (_) {
      // ignore
    }
    return '';
  }

  // Navigation helper functions for different refund and dispute pages
  function goToRefundDetail(orderId) {
    window.location.href = `../../Module_After_Sales_Dispute/pages/Refund_Details.html?order_id=${encodeURIComponent(orderId)}`;
  }

  function goToBuyerDispute(orderId, hasBuyerReturnTracking) {
    const oid = encodeURIComponent(orderId);
    const url = Number(hasBuyerReturnTracking)
        ? `../../Module_After_Sales_Dispute/pages/Dispute_Reject_After_Receive_Return.html?order_id=${oid}`
        : `../../Module_After_Sales_Dispute/pages/Dispute_Reject_Return.html?order_id=${oid}`;
    window.location.href = url;
  }

  function goToSellerStatement(orderId) {
    window.location.href = `../../Module_After_Sales_Dispute/pages/Dispute_Seller_Statement.html?order_id=${encodeURIComponent(orderId)}`;
  }

  function goToDisputeProgress(orderId) {
    window.location.href = `../../Module_After_Sales_Dispute/pages/Dispute_Progress.html?order_id=${encodeURIComponent(orderId)}`;
  }

  // ============================================================
  // Render refund and dispute status card based on order status
  // ============================================================
  function renderRefundStatusCard(order, isBuyer) {
    let status = order.Refund_Status;
    const type = order.Refund_Type;
    const disputeStatus = order.Dispute_Status;

    if (!status && disputeStatus && disputeStatus !== 'Closed' && disputeStatus !== 'None') {
      status = 'dispute_in_progress';
    }

    if (!status) return '';

    const typeText = type === 'refund_only' ? 'Refund Only' : 'Return & Refund';

    // Status: Pending Approval
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
              <a class="btn-view-details" href="javascript:void(0)" onclick="goToRefundDetail(${Number(order.Orders_Order_ID)})">Check</a>
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
            <a class="btn-view-details" href="javascript:void(0)" onclick="goToRefundDetail(${Number(order.Orders_Order_ID)})">Check</a>
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

    // Status: Awaiting Return
    if (status === 'awaiting_return' || status === 'awaiting_confirm') {
      const returnTracking = order.Return_Tracking_Number || order.return_tracking_number || '';
      const deliveryMethod = String(order.Delivery_Method || 'shipping').toLowerCase().trim();

      const returnAddressHtml = isBuyer ? getReturnAddressHtml() : '';

      // Meet-up Logic
      if (deliveryMethod === 'meetup') {
        return `
          <div class="refund-status-card status-return">
            <div class="refund-status-header">
              <div class="refund-status-label"><i class="ri-exchange-line"></i> Return in Progress (Meet-up)</div>
              <a class="btn-view-details" href="javascript:void(0)" onclick="goToRefundDetail(${Number(order.Orders_Order_ID)})">Check</a>
            </div>
            <div class="refund-status-body">
              <div class="refund-info-text">
                <h4>Meet-up Return</h4>
                <p>Please arrange a meet-up handover.</p>
              </div>
              <div class="btn-group">
                ${isBuyer ? `<button class="btn btn-confirm" onclick="confirmReturnHandover(${Number(order.Orders_Order_ID)})">I Handed Over</button>` : ''}
                ${!isBuyer ? `<button class="btn btn-confirm" onclick="sellerConfirmReturnReceived(${Number(order.Orders_Order_ID)})">Received Item</button>` : ''}
              </div>
            </div>
          </div>
        `;
      }

      // Shipping Logic (Buyer)
      if (isBuyer) {
        if (returnTracking) {
          return `
            <div class="refund-status-card status-return">
              <div class="refund-status-header">
                <div class="refund-status-label"><i class="ri-truck-line"></i> Return Shipped</div>
                <a class="btn-view-details" href="javascript:void(0)" onclick="goToRefundDetail(${Number(order.Orders_Order_ID)})">Check</a>
              </div>
              <div class="refund-status-body">
                <div class="refund-info-text" style="width:100%;">
                  <h4>Waiting for Seller</h4>
                  ${returnAddressHtml}
                  <div style="margin-top:10px; padding:10px; background:#F3F4F6; border-radius:8px;">
                    <div style="font-size:0.75rem; color:#9CA3AF;">TRACKING NUMBER</div>
                    <div style="font-weight:700; color:#374151;">${escapeHtml(returnTracking)}</div>
                  </div>
                </div>
              </div>
            </div>
          `;
        } else {
          return `
            <div class="refund-status-card status-return">
              <div class="refund-status-header">
                <div class="refund-status-label"><i class="ri-truck-line"></i> Return Shipping</div>
                <a class="btn-view-details" href="javascript:void(0)" onclick="goToRefundDetail(${Number(order.Orders_Order_ID)})">Check</a>
              </div>
              <div class="refund-status-body">
                <div class="refund-info-text">
                  <h4>Awaiting Return</h4>
                  <p>Please ship back and upload tracking.</p>
                  ${returnAddressHtml}
                </div>
                <div class="btn-group">
                  <div class="tracking-group">
                    <input id="returnTrackingInput" class="tracking-input" placeholder="Tracking Number..." />
                    <button class="btn-small-upload" onclick="submitReturnTracking(${Number(order.Orders_Order_ID)})">Upload</button>
                  </div>
                </div>
              </div>
            </div>
          `;
        }
      }

      // Shipping Logic (Seller)
      // Use openSellerRefusalModal instead of sellerRefuseReturnReceived
      return `
        <div class="refund-status-card status-return">
          <div class="refund-status-header">
            <div class="refund-status-label"><i class="ri-truck-line"></i> Return In Progress</div>
            <a class="btn-view-details" href="javascript:void(0)" onclick="goToRefundDetail(${Number(order.Orders_Order_ID)})">Check</a>
          </div>
          <div class="refund-status-body">
            <div class="refund-info-text">
              <h4>Awaiting Return</h4>
              ${returnTracking ? `<p>Tracking: <strong>${escapeHtml(returnTracking)}</strong></p>` : '<p>Buyer has not shipped yet.</p>'}
            </div>
            <div class="btn-group">
              <button class="btn btn-confirm" onclick="sellerConfirmReturnReceived(${Number(order.Orders_Order_ID)})">Confirm Received</button>
              <button class="btn btn-warn" onclick="openSellerRefusalModal(${Number(order.Orders_Order_ID)})">Refuse & Dispute</button>
            </div>
          </div>
        </div>
      `;
    }

    // Status: Completed
    if (status === 'completed') {
      let title = 'Refund Completed';
      let msg = isBuyer ? 'Refund returned to wallet.' : 'Refund deducted from earnings.';
      let adminHtml = '';
      const disputeOutcome = order.Dispute_Resolution_Outcome;
      const adminReply = isBuyer ? order.Dispute_Admin_Reply_To_Buyer : order.Dispute_Admin_Reply_To_Seller;

      if (disputeOutcome === 'refund_buyer' || disputeOutcome === 'partial') {
        title = 'Dispute Resolved: Refund Approved';
        msg = `Platform decided to refund the buyer (Amount: RM ${order.Refund_Amount || '?'}).`;
        if (adminReply) {
          adminHtml = `
            <div class="reason-box" style="margin-top:12px; background:#F0FDF4; border-color:#86EFAC; color:#166534;">
              <div style="display:flex; gap:6px; align-items:center; margin-bottom:4px; font-weight:700;">
                <i class="ri-admin-line"></i> Admin Message:
              </div>
              ${escapeHtml(adminReply)}
            </div>`;
        }
      }

      return `
        <div class="refund-success-card">
          <div class="refund-success-icon"><i class="ri-check-double-line"></i></div>
          <div class="refund-success-content" style="flex:1;">
            <h3>${title}</h3>
            <p>${escapeHtml(msg)}</p>
            ${adminHtml}
          </div>
        </div>
      `;
    }

    // Status: Rejected / Closed / Cancelled
    if (status === 'rejected' || status === 'closed' || status === 'goods_rejected' || status === 'cancelled') {
      const attempt = parseInt(order.Request_Attempt || '1', 10);
      const canResubmit = isBuyer && attempt < 2 && status !== 'closed' && status !== 'cancelled';
      const disputeOutcome = order.Dispute_Resolution_Outcome;
      const adminReply = isBuyer ? order.Dispute_Admin_Reply_To_Buyer : order.Dispute_Admin_Reply_To_Seller;
      const hasDispute = (order.Dispute_ID && Number(order.Dispute_ID) > 0);

      if ((isBuyer && !canResubmit && status === 'rejected') || hasDispute) {
        return renderDisputeCard(order, isBuyer, 'Platform Intervention', 'Request rejected. Platform support team involved.');
      }

      let title = 'Refund Request Rejected';
      let subMsg = 'The request was rejected.';
      let reasonHtml = '';

      if (status === 'cancelled') {
        title = 'Refund Cancelled';
        subMsg = 'You cancelled this request.';
      } else if (disputeOutcome === 'refund_seller') {
        title = 'Dispute Resolved: Refund Denied';
        subMsg = 'Platform decided to release funds to seller.';
        if (adminReply) {
          reasonHtml = `
            <div class="reason-box" style="margin-top:12px; background:#EFF6FF; border-color:#BFDBFE; color:#1E40AF;">
              <div style="display:flex; gap:6px; align-items:center; margin-bottom:4px; font-weight:700;">
                <i class="ri-admin-line"></i> Admin Message:
              </div>
              ${escapeHtml(adminReply)}
            </div>`;
        }
      } else {
        if (order.Seller_Reject_Reason_Text || order.Seller_Reject_Reason_Code) {
          reasonHtml = `<div class="reason-box"><strong>Seller Reason:</strong><br>${escapeHtml(
              order.Seller_Reject_Reason_Text || order.Seller_Reject_Reason_Code
          )}</div>`;
        }
      }

      return `
        <div class="refund-status-card status-closed">
          <div class="refund-status-header">
            <div class="refund-status-label"><i class="ri-close-circle-line"></i> ${title}</div>
            <a class="btn-view-details" href="javascript:void(0)" onclick="goToRefundDetail(${Number(order.Orders_Order_ID)})">Check</a>
          </div>
          <div class="refund-status-body">
            <div class="refund-info-text" style="width:100%">
              <h4>${typeText}</h4>
              <p>${subMsg}</p>
              ${reasonHtml}
            </div>
            ${canResubmit ? `
            <div class="btn-group" style="margin-top:15px;">
              <button class="btn btn-refund" onclick="openRefundPreCheck(${Number(order.Orders_Order_ID)})">Resubmit</button>
            </div>` : ''}
          </div>
        </div>
      `;
    }

    // Status: Dispute In Progress
    if (status === 'dispute_in_progress') {
      return renderDisputeCard(order, isBuyer);
    }

    return '';
  }

  // ============================================================
  // Render dispute status card with dynamic messaging
  // ============================================================
  function renderDisputeCard(order, isBuyer, overrideTitle, overrideDesc) {
    const disputeId = Number(order.Dispute_ID || 0);
    const hasDisputeRecord = (disputeId > 0);
    const hasBuyerReturnTracking = !!(order.Return_Tracking_Number || order.return_tracking_number);

    let jumpFunc = '';
    let hasParticipated = false;

    if (isBuyer) {
      const desc = order.Buyer_Description || '';
      const imgs = order.Dispute_Buyer_Evidence || '[]';
      if (desc.length > 0 || (imgs.length > 5 && imgs !== '[]')) hasParticipated = true;
    } else {
      const desc = order.Seller_Description || '';
      const imgs = order.Dispute_Seller_Evidence || '[]';
      if (desc.length > 0 || (imgs.length > 5 && imgs !== '[]')) hasParticipated = true;
    }

    if (!hasDisputeRecord) {
      jumpFunc = isBuyer
          ? `goToBuyerDispute(${Number(order.Orders_Order_ID)}, ${Number(hasBuyerReturnTracking)})`
          : `goToSellerStatement(${Number(order.Orders_Order_ID)})`;
    } else {
      if (hasParticipated) {
        jumpFunc = `goToDisputeProgress(${Number(order.Orders_Order_ID)})`;
      } else {
        jumpFunc = isBuyer
            ? `goToBuyerDispute(${Number(order.Orders_Order_ID)}, ${Number(hasBuyerReturnTracking)})`
            : `goToSellerStatement(${Number(order.Orders_Order_ID)})`;
      }
    }

    const actionRequired = order.Action_Required_By || 'None';
    const myRole = isBuyer ? 'Buyer' : 'Seller';
    const step = order.Dispute_Status || 'Open';

    let displayStatus = overrideTitle || "Dispute Started";
    let displayDesc = overrideDesc || "Waiting for platform admin to review the case.";
    let statusIcon = "ri-hourglass-2-fill";
    let headerColorClass = "status-pending";

    const isActionNeeded = (actionRequired === myRole) || (actionRequired === 'Both');

    if (isActionNeeded) {
      displayStatus = "Action Required";
      const adminMsg = isBuyer ? order.Dispute_Admin_Reply_To_Buyer : order.Dispute_Admin_Reply_To_Seller;
      displayDesc = adminMsg ? `Admin Instruction: "${escapeHtml(adminMsg)}"` : "Please submit additional evidence immediately.";
      statusIcon = "ri-alarm-warning-fill";
      headerColorClass = "status-closed";
    }
    else if (step === 'In Review') {
      displayStatus = "Under Investigation";
      displayDesc = "Admin is currently reviewing evidence from both parties.";
      statusIcon = "ri-search-eye-line";
      headerColorClass = "status-return";
    }
    else if (step === 'Resolved') {
      displayStatus = "Dispute Resolved";
      const adminMsg = isBuyer ? order.Dispute_Admin_Reply_To_Buyer : order.Dispute_Admin_Reply_To_Seller;
      displayDesc = adminMsg ? `Verdict: "${escapeHtml(adminMsg)}"` : "A final decision has been made.";
      statusIcon = "ri-check-double-line";
      headerColorClass = "status-success";
    }

    const btnText = (!hasDisputeRecord || !hasParticipated) ? "Respond / File Dispute" : (isActionNeeded ? "Respond Now" : "View Progress");
    const btnStyle = isActionNeeded
        ? "background:#DC2626; color:white; border:none;"
        : "background:#1F2937; color:white;";

    return `
        <div class="refund-status-card ${headerColorClass}">
          <div class="refund-status-header">
            <div class="refund-status-label">
                <i class="${statusIcon}"></i> ${displayStatus}
                ${isActionNeeded ? '<span style="background:red; color:white; font-size:10px; padding:2px 6px; border-radius:4px; margin-left:5px;">URGENT</span>' : ''}
            </div>
            <a class="btn-view-details" href="javascript:void(0)" onclick="goToRefundDetail(${Number(order.Orders_Order_ID)})">Check</a>
          </div>
          <div class="refund-status-body">
            <div class="refund-info-text">
              <h4>Platform Intervention</h4>
              <p>${displayDesc}</p>
              <div style="margin-top:8px;">
                 <span style="font-size:0.75rem; font-weight:700; color:#6B7280; background:#F3F4F6; padding:4px 8px; border-radius:4px; text-transform:uppercase;">
                   STATUS: ${step}
                 </span>
              </div>
            </div>
            <div class="btn-group">
                <button class="btn" style="${btnStyle}" onclick="${jumpFunc}">
                    ${btnText}
                </button>
            </div>
          </div>
        </div>
      `;
  }

  // =========================================
  // Seller Actions & Helpers
  // =========================================

  async function sellerProcessRefund(orderId, action, type) {
    console.log(`[Debug] Initiating Refund Action: ${action}, Type: ${type}, ID: ${orderId}`);

    let confirmMsg = '';

    // Set initial confirmation message
    if (action === 'approve') {
      confirmMsg = type === 'refund_only'
          ? 'Approve Refund Only?\nMoney will be refunded to buyer immediately.'
          : 'Accept Return?\nBuyer will be notified to return the item.';
    } else {
      confirmMsg = 'Ready to reject this refund request?';
    }

    // First step: basic confirmation
    if (!confirm(confirmMsg)) {
      return;
    }

    let reject_reason_code = null;
    let reject_reason_text = null;

    // Second step: rejection logic
    if (action === 'reject') {
      reject_reason_text = prompt('Please enter the rejection reason (Required):');
      if (reject_reason_text === null) return;
      if (reject_reason_text.trim() === '') {
        alert('Rejection reason cannot be empty.');
        return;
      }
      reject_reason_code = 'other';
    }

    // Special case: approve return (Return & Refund) requires address selection
    if (action === 'approve' && type !== 'refund_only') {
      console.log('[Debug] Triggering address selection...');
      if (global.toggleAddressSelection) {
        await global.toggleAddressSelection(orderId);
      } else {
        alert('Address module not loaded. Cannot select return address.');
      }
      return; // Stop execution and wait for address selection modal to handle
    }

    // Third step: submit API (only for "refund only" or "reject")
    console.log('[Debug] Sending API request...');
    try {
      const apiUrl = '../../Module_Transaction_Fund/api/Refund_Actions.php';

      const response = await fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({
          action: 'seller_decision',
          order_id: orderId,
          decision: action,

          // 🔥🔥🔥 关键修复：必须把退款类型传给后端 🔥🔥🔥
          refund_type: type,

          reject_reason_code,
          reject_reason_text,
        }),
      });

      const result = await response.json();

      if (result && result.success) {
        alert('Success: ' + (result.message || 'Operation completed.'));
        location.reload();
      } else {
        alert('Failed: ' + (result?.message || 'Unknown error'));
      }

    } catch (err) {
      console.error('[Error Details]', err);
      alert('Error occurred: ' + err.message);
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

  // Legacy prompt-based function (kept for fallback compatibility, though now replaced by modal)
  async function sellerRefuseReturnReceived(orderId) {
    // Forward to new modal logic
    openSellerRefusalModal(orderId);
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
  // 🆕🆕🆕 Updated Modal Logic (Pre-Check)
  // =========================================

  function openRefundPreCheck(orderId) {
    currentRefundOrderId = orderId;
    modalMode = 'buyer'; // Set to Buyer Mode
    hasReceivedGoods = 0;

    // Update Title
    const titleEl = document.getElementById('refundModalTitle');
    if(titleEl) titleEl.innerText = "Request Refund";

    resetRefundModal();
    document.getElementById('refundPreCheckModal').style.display = 'flex';
  }

  function openSellerRefusalModal(orderId) {
    currentRefundOrderId = orderId;
    modalMode = 'seller'; // Set to Seller Mode
    hasReceivedGoods = 0;

    // Update Title
    const titleEl = document.getElementById('refundModalTitle');
    if(titleEl) titleEl.innerText = "Refuse Return & Dispute";

    resetRefundModal();
    document.getElementById('refundPreCheckModal').style.display = 'flex';
  }

  function handlePreCheckStep1(status) {
    hasReceivedGoods = status; // 0 or 1
    const select = document.getElementById('preSelectReason');
    select.innerHTML = '<option value="" disabled selected>-- Select a Reason --</option>';

    // 🔥 Switch Reasons based on Modal Mode
    let reasons = [];
    if (modalMode === 'seller') {
      reasons = (status === 1) ? sellerReasonsReceived : sellerReasonsNotReceived;
    } else {
      reasons = (status === 1) ? reasonsReceived : reasonsNotReceived;
    }

    reasons.forEach(r => {
      const opt = document.createElement('option');
      opt.value = r.val;
      opt.innerText = r.txt;
      select.appendChild(opt);
    });

    document.getElementById('step1_received').style.display = 'none';
    document.getElementById('step2_reason').style.display = 'block';
  }

  function resetRefundModal() {
    document.getElementById('step1_received').style.display = 'block';
    document.getElementById('step2_reason').style.display = 'none';
  }

  function submitPreCheck() {
    const reasonCode = document.getElementById('preSelectReason').value;
    // 获取选中的文本，虽然跳转后主要用 reasonCode，但保留逻辑以防万一
    const selectEl = document.getElementById('preSelectReason');
    const reasonText = selectEl.options[selectEl.selectedIndex].text;

    if (!reasonCode) {
      alert("Please select a reason first.");
      return;
    }

    // 🅰️ 买家模式：跳转到退款申请页 (保持原有逻辑)
    if (modalMode === 'buyer') {
      const url = `../../Module_After_Sales_Dispute/pages/Refund_Request.html?order_id=${currentRefundOrderId}&received=${hasReceivedGoods}&reason=${reasonCode}`;
      window.location.href = url;
    }

    // 🅱️ 🆕 卖家模式：跳转到拒绝退货详情页
    else {
      // 不再直接调用 API，而是跳转到你指定的页面
      // 带上 order_id, received (0或1), reason (原因代码)
      const url = `../../Module_After_Sales_Dispute/pages/Dispute_Seller_Statement.html?order_id=${currentRefundOrderId}&reason=${reasonCode}`;
      window.location.href = url;
    }
  }


  function closeRefundModal() {
    document.getElementById('refundPreCheckModal').style.display = 'none';
  }

  // Exports
  global.OrderDetailsRefund = {
    renderRefundStatusCard,
    goToRefundDetail,
    goToSellerStatement,
    goToBuyerDispute,
    goToDisputeProgress,
    sellerProcessRefund,
    sellerConfirmReturnReceived,
    sellerRefuseReturnReceived,
    submitReturnTracking,
    confirmReturnHandover,
    openRefundPreCheck,
    openSellerRefusalModal // 🆕 Exported
  };

  // Global bindings for HTML onclick
  global.openRefundPreCheck = openRefundPreCheck;
  global.openSellerRefusalModal = openSellerRefusalModal; // 🆕
  global.handlePreCheckStep1 = handlePreCheckStep1;
  global.resetRefundModal = resetRefundModal;
  global.submitPreCheck = submitPreCheck;
  global.closeRefundModal = closeRefundModal;

  // Legacy globals support
  global.goToRefundDetail = goToRefundDetail;
  global.sellerProcessRefund = sellerProcessRefund;
  global.sellerConfirmReturnReceived = sellerConfirmReturnReceived;
  global.sellerRefuseReturnReceived = sellerRefuseReturnReceived;
  global.submitReturnTracking = submitReturnTracking;
  global.confirmReturnHandover = confirmReturnHandover;
  global.goToSellerStatement = goToSellerStatement;
  global.goToBuyerDispute = goToBuyerDispute;
  global.goToDisputeProgress = goToDisputeProgress;

})(window);