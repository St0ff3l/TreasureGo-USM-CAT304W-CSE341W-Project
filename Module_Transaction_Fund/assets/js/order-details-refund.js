/*
 * Order Details - Refund/After-sales module
 * Updated: Supports Bi-directional Dispute Initiation & Progress Timeline
 * Fixes: Full logic restoration, Correct "Check" link, Enhanced Participation Logic
 */

(function (global) {
  'use strict';

  // --- 🆕 弹窗逻辑变量 ---
  let currentRefundOrderId = null;
  let hasReceivedGoods = 0; // 0=No, 1=Yes

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

  function escapeHtml(value) {
    return global.OrderDetailsOrder?.escapeHtml ? global.OrderDetailsOrder.escapeHtml(value) : String(value ?? '');
  }

  // ✅ 0. Check 按钮的目标地址 (查看退款详情)
  function goToRefundDetail(orderId) {
    window.location.href = `../../Module_After_Sales_Dispute/pages/Refund_Details.html?order_id=${encodeURIComponent(orderId)}`;
  }

  // ✅ 1. 填表页：买家发起
  function goToBuyerDispute(orderId, hasBuyerReturnTracking) {
    const oid = encodeURIComponent(orderId);
    const url = Number(hasBuyerReturnTracking)
        ? `../../Module_After_Sales_Dispute/pages/Dispute_Reject_After_Receive_Return.html?order_id=${oid}`
        : `../../Module_After_Sales_Dispute/pages/Dispute_Reject_Return.html?order_id=${oid}`;
    window.location.href = url;
  }

  // ✅ 2. 填表页：卖家发起
  function goToSellerStatement(orderId) {
    window.location.href = `../../Module_After_Sales_Dispute/pages/Dispute_Seller_Statement.html?order_id=${encodeURIComponent(orderId)}`;
  }

  // ✅ 3. 进度页：查看/聊天 (双方共用)
  function goToDisputeProgress(orderId) {
    window.location.href = `../../Module_After_Sales_Dispute/pages/Dispute_Progress.html?order_id=${encodeURIComponent(orderId)}`;
  }

  // ============================================================
  // 🔥 核心函数：渲染退款/争议状态卡片
  // ============================================================
  function renderRefundStatusCard(order, isBuyer) {
    let status = order.Refund_Status; // 可能为空
    const type = order.Refund_Type;
    const disputeStatus = order.Dispute_Status;

    // 🔥 如果 Refund_Status 为空，但有 Dispute_Status，强制视为 'dispute_in_progress'
    if (!status && disputeStatus && disputeStatus !== 'Closed' && disputeStatus !== 'None') {
      status = 'dispute_in_progress';
    }

    if (!status) return '';

    const typeText = type === 'refund_only' ? 'Refund Only' : 'Return & Refund';

    // -------------------------------------------------------------
    // 1. Pending Approval (等待卖家处理)
    // -------------------------------------------------------------
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
            <div class="seller-actions-row">
              <button class="btn btn-confirm" onclick="sellerProcessRefund(${Number(order.Orders_Order_ID)}, 'approve', '${escapeHtml(type)}')">Approve</button>
              <button class="btn btn-warn" onclick="sellerProcessRefund(${Number(order.Orders_Order_ID)}, 'reject', '${escapeHtml(type)}')">Reject</button>
            </div>
            <div id="addr-container-${Number(order.Orders_Order_ID)}" class="inline-addr-box" style="display:none;"></div>
          </div>
        </div>
      `;
    }

    // -------------------------------------------------------------
    // 2. Awaiting Return (退货中)
    // -------------------------------------------------------------
    if (status === 'awaiting_return' || status === 'awaiting_confirm') {
      const returnTracking = order.Return_Tracking_Number || order.return_tracking_number || '';
      const deliveryMethod = String(order.Delivery_Method || 'shipping').toLowerCase().trim();

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
              <button class="btn btn-warn" onclick="sellerRefuseReturnReceived(${Number(order.Orders_Order_ID)})">Refuse & Dispute</button>
            </div>
          </div>
        </div>
      `;
    }

    // -------------------------------------------------------------
    // 🔥 3. Completed (Refund Successful or Dispute Won by Buyer)
    // -------------------------------------------------------------
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

    // -------------------------------------------------------------
    // 🔥 4. Rejected / Closed / Cancelled
    // -------------------------------------------------------------
    if (status === 'rejected' || status === 'closed' || status === 'goods_rejected' || status === 'cancelled') {
      const attempt = parseInt(order.Request_Attempt || '1', 10);
      const canResubmit = isBuyer && attempt < 2 && status !== 'closed' && status !== 'cancelled';
      const disputeOutcome = order.Dispute_Resolution_Outcome;
      const adminReply = isBuyer ? order.Dispute_Admin_Reply_To_Buyer : order.Dispute_Admin_Reply_To_Seller;
      const hasDispute = (order.Dispute_ID && Number(order.Dispute_ID) > 0);

      // 🔥 核心判断：如果买家被拒第二次，或者虽然第一次被拒但卖家已经发起了争议
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

      // 🔥 改为调用 openRefundPreCheck
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

    // -------------------------------------------------------------
    // 🔥 5. Dispute In Progress (争议状态)
    // -------------------------------------------------------------
    if (status === 'dispute_in_progress') {
      return renderDisputeCard(order, isBuyer);
    }

    return '';
  }

  // ============================================================
  // 🔥🔥🔥 核心逻辑：智能路由判断 (Dispute Card) 🔥🔥🔥
  // ============================================================
  function renderDisputeCard(order, isBuyer, overrideTitle, overrideDesc) {
    const disputeId = Number(order.Dispute_ID || 0);
    const hasDisputeRecord = (disputeId > 0);
    const hasBuyerReturnTracking = !!(order.Return_Tracking_Number || order.return_tracking_number);

    // ⬇️⬇️⬇️ 关键判断逻辑 ⬇️⬇️⬇️
    let jumpFunc = '';
    let hasParticipated = false;

    // 1. 判断我（当前用户）是否已经提交过证据
    // 现在检查新的 _Description 字段和图片字段
    if (isBuyer) {
      const desc = order.Buyer_Description || '';
      const imgs = order.Dispute_Buyer_Evidence || '[]';

      // 如果有文字描述，或者有图片
      if (desc.length > 0 || (imgs.length > 5 && imgs !== '[]')) {
        hasParticipated = true;
      }
    } else {
      const desc = order.Seller_Description || '';
      const imgs = order.Dispute_Seller_Evidence || '[]';

      // 如果有文字描述，或者有图片
      if (desc.length > 0 || (imgs.length > 5 && imgs !== '[]')) {
        hasParticipated = true;
      }
    }

    // 2. 路由决策
    if (!hasDisputeRecord) {
      // 还没立案 -> 肯定去填表
      jumpFunc = isBuyer
          ? `goToBuyerDispute(${Number(order.Orders_Order_ID)}, ${Number(hasBuyerReturnTracking)})`
          : `goToSellerStatement(${Number(order.Orders_Order_ID)})`;
    } else {
      // 已经立案 -> 检查我是否参与过
      if (hasParticipated) {
        // 我参与过 -> 去聊天页
        jumpFunc = `goToDisputeProgress(${Number(order.Orders_Order_ID)})`;
      } else {
        // 立案了但我没交过证据 (我是被告且第一次来) -> 去填表页
        jumpFunc = isBuyer
            ? `goToBuyerDispute(${Number(order.Orders_Order_ID)}, ${Number(hasBuyerReturnTracking)})`
            : `goToSellerStatement(${Number(order.Orders_Order_ID)})`;
      }
    }

    // UI 渲染逻辑
    const actionRequired = order.Action_Required_By || 'None';
    const myRole = isBuyer ? 'Buyer' : 'Seller';
    const isActionNeeded = (actionRequired === myRole) || (actionRequired === 'Both');
    const step = order.Dispute_Status || 'Open';

    let displayStatus = overrideTitle || "Dispute Submitted";
    let displayDesc = overrideDesc || "Waiting for admin assignment.";
    let statusIcon = "ri-send-plane-fill";
    let headerColorClass = "status-pending"; // 默认黄/灰

    if (isActionNeeded) {
      displayStatus = "Action Required";
      displayDesc = "Please submit your evidence/response immediately.";
      statusIcon = "ri-alarm-warning-fill";
      headerColorClass = "status-closed"; // 红色背景
    } else if (!overrideTitle) {
      // 根据状态显示不同文案
      switch (step) {
        case 'In Review':
          displayStatus = "Under Review";
          displayDesc = "Admin is investigating the case.";
          statusIcon = "ri-search-eye-line";
          headerColorClass = "status-return"; // 蓝色
          break;
        case 'Resolved':
          displayStatus = "Dispute Resolved";
          displayDesc = "Verdict reached.";
          statusIcon = "ri-check-double-line";
          headerColorClass = "status-success"; // 绿色
          break;
      }
    }

    // 按钮文字逻辑：没立案/没参与 -> 填表；否则 -> 看详情
    const btnText = (!hasDisputeRecord || !hasParticipated) ? "Respond / File Dispute" : (isActionNeeded ? "Respond Now" : "View Details");
    const btnStyle = isActionNeeded
        ? "background:#DC2626; color:white; border:none;" // 红色紧急
        : "background:#1F2937; color:white;";             // 黑色普通

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
    let confirmMsg = '';
    if (action === 'approve') {
      confirmMsg = type === 'refund_only'
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
          reason_code: 'other',
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
  // 🆕🆕🆕 弹窗逻辑实现 (Pre-Check Modal)
  // =========================================

  function openRefundPreCheck(orderId) {
    currentRefundOrderId = orderId;
    // 重置状态
    hasReceivedGoods = 0;
    // 重置显示
    document.getElementById('step1_received').style.display = 'block';
    document.getElementById('step2_reason').style.display = 'none';
    // 打开弹窗
    document.getElementById('refundPreCheckModal').style.display = 'flex';
  }

  function handlePreCheckStep1(status) {
    hasReceivedGoods = status; // 0 或 1
    const select = document.getElementById('preSelectReason');
    select.innerHTML = '<option value="" disabled selected>-- Select a Reason --</option>';

    // 根据选择填充原因
    const reasons = (status === 1) ? reasonsReceived : reasonsNotReceived;
    reasons.forEach(r => {
      const opt = document.createElement('option');
      opt.value = r.val;
      opt.innerText = r.txt;
      select.appendChild(opt);
    });

    // 切换到第二步
    document.getElementById('step1_received').style.display = 'none';
    document.getElementById('step2_reason').style.display = 'block';
  }

  function resetRefundModal() {
    document.getElementById('step1_received').style.display = 'block';
    document.getElementById('step2_reason').style.display = 'none';
  }

  function submitPreCheck() {
    const reason = document.getElementById('preSelectReason').value;
    if (!reason) {
      alert("Please select a reason first.");
      return;
    }
    // 跳转到填写页面，带上参数
    const url = `../../Module_After_Sales_Dispute/pages/Refund_Request.html?order_id=${currentRefundOrderId}&received=${hasReceivedGoods}&reason=${reason}`;
    window.location.href = url;
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
    openRefundPreCheck // 🆕 导出新函数
  };

  // 绑定全局以便 HTML onclick 调用
  global.openRefundPreCheck = openRefundPreCheck;
  global.handlePreCheckStep1 = handlePreCheckStep1;
  global.resetRefundModal = resetRefundModal;
  global.submitPreCheck = submitPreCheck;
  global.closeRefundModal = closeRefundModal;

  // legacy globals support
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