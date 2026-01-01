// ================= Global Configuration =================
const API_GET = '../api/admin_dispute_get.php';
const API_UPDATE = '../api/admin_dispute_update.php';
const params = new URLSearchParams(window.location.search);
const disputeId = params.get('id');
let orderTotalAmount = 0.00;

// ================= DOM Element Listeners & Binding =================
document.addEventListener('DOMContentLoaded', () => {
    // Bind save button
    const btnSave = document.getElementById('btnSaveDispute');
    if(btnSave) btnSave.addEventListener('click', saveDisputeChanges);

    // Bind dropdown change
    const outcomeSelect = document.getElementById('drOutcome');
    if(outcomeSelect) outcomeSelect.addEventListener('change', handleOutcomeChange);

    // Bind amount input (real-time calculation + formatting)
    const amountInput = document.getElementById('drAmount');
    if(amountInput) {
        amountInput.addEventListener('input', updateCalculation);
        amountInput.addEventListener('blur', () => formatDecimal(amountInput));
    }

    // Listen to dropdown to highlight required fields
    const actionSelect = document.getElementById('actionRequiredBy');
    if(actionSelect) {
        actionSelect.addEventListener('change', highlightRequiredFields);
    }

    // Listen to status change for Resolved -> None logic
    const statusSelect = document.getElementById('updateStatus');
    if (statusSelect) statusSelect.addEventListener('change', handleStatusLogic);

    // Initial load
    init();
});

// Visual highlight function
function highlightRequiredFields() {
    const actionSelect = document.getElementById('actionRequiredBy');
    const statusSelect = document.getElementById('updateStatus');
    const boxBuyer = document.getElementById('drReplyBuyer');
    const boxSeller = document.getElementById('drReplySeller');

    if (!boxBuyer || !boxSeller || !actionSelect) return;

    const actionVal = actionSelect.value;
    const statusVal = statusSelect ? statusSelect.value : 'Open';

    // 1. If Resolved, logic is handled by handleStatusLogic
    // (Resolved cases usually require mandatory notifications to both parties)
    if (statusVal === 'Resolved') {
        boxBuyer.disabled = false;
        boxSeller.disabled = false;
        return;
    }

    // 2. Reset all styles and states (default enabled)
    boxBuyer.disabled = false;
    boxSeller.disabled = false;

    boxBuyer.style.border = '';
    boxBuyer.style.background = '';
    boxSeller.style.border = '';
    boxSeller.style.background = '';

    boxBuyer.placeholder = 'Instruction / Message to Buyer...';
    boxSeller.placeholder = 'Instruction / Message to Seller...';

    // 3. Lock the other party based on Action
    // If action required from Buyer -> Disable Seller input
    if (actionVal === 'Buyer') {
        boxSeller.disabled = true;
        boxSeller.value = ''; // Clear content to prevent accidental sending
        boxSeller.style.background = '#F3F4F6';
        boxSeller.placeholder = '🚫 No action required from Seller currently.';

        // Highlight Buyer box
        boxBuyer.style.border = '2px solid #F87171';
        boxBuyer.style.background = '#FEF2F2';
        boxBuyer.placeholder = '⚠️ REQUIRED: Tell the buyer what evidence to upload...';
    }
    // If action required from Seller -> Disable Buyer input
    else if (actionVal === 'Seller') {
        boxBuyer.disabled = true;
        boxBuyer.value = ''; // Clear content to prevent accidental sending
        boxBuyer.style.background = '#F3F4F6';
        boxBuyer.placeholder = '🚫 No action required from Buyer currently.';

        // Highlight Seller box
        boxSeller.style.border = '2px solid #F87171';
        boxSeller.style.background = '#FEF2F2';
        boxSeller.placeholder = '⚠️ REQUIRED: Tell the seller what information is needed...';
    }
    // If action required from both -> Highlight both
    else if (actionVal === 'Both') {
        boxBuyer.style.border = '2px solid #F87171';
        boxBuyer.style.background = '#FEF2F2';

        boxSeller.style.border = '2px solid #F87171';
        boxSeller.style.background = '#FEF2F2';
    }
}

// ================= Initialization =================
async function init() {
    if(!disputeId) { alert('No ID provided'); return; }
    document.getElementById('dID').textContent = disputeId;

    try {
        const res = await fetch(`${API_GET}?dispute_id=${disputeId}`);
        const json = await res.json();
        if(json.status !== 'success') throw new Error(json.message);

        const data = json.data;
        render(data);

        // Automatically update status to In Review
        // Logic: If current status is 'Open', automatically update to 'In Review'
        if (data.Dispute_Status === 'Open') {
            await autoUpdateToInReview(data);
        }

    } catch(e) {
        alert('Error loading dispute: ' + e.message);
        console.error(e);
    }
}

// ================= Page Rendering =================
function render(d) {
    const reasonMap = {
        'damaged': 'Item Damaged / Defective',
        'wrong_item': 'Received Wrong Item',
        'not_described': 'Item Not As Described',
        'missing_parts': 'Missing Parts / Accessories',
        'fake': 'Counterfeit / Fake Item',
        'other': 'Other',
        'seller_wrongly_rejected': 'Seller wrongly rejected request',
        'seller_unresponsive': 'Seller is unresponsive',
        'did_not_receive_refund': 'Did not receive refund after return',
        'goods_rejected_by_buyer': 'Buyer returned damaged/wrong item',
        'buyer_misuse': 'Buyer misused the product'
    };

    orderTotalAmount = parseFloat(d.Orders_Total_Amount || 0);
    document.getElementById('orderTotalHidden').value = orderTotalAmount;

    // Status display
    const st = d.Dispute_Status;
    const stClean = st.replace(/\s+/g, '');
    const stEl = document.getElementById('statusDisplay');
    stEl.textContent = st;
    stEl.className = `status-badge st-${stClean}`;
    document.getElementById('updateStatus').value = st;

    // Display Action Required By
    const actionSelect = document.getElementById('actionRequiredBy');
    if(actionSelect && d.Action_Required_By) {
        actionSelect.value = d.Action_Required_By;
        highlightRequiredFields(); // Trigger highlight
    }

    // Outcome display
    document.getElementById('drOutcome').value = d.Dispute_Resolution_Outcome || '';
    if(d.Dispute_Refund_Amount) document.getElementById('drAmount').value = d.Dispute_Refund_Amount;

    // Refund request detail card
    const typeEl = document.getElementById('rrType');
    const trackBox = document.getElementById('returnTrackingBox');

    if (d.Refund_Type === 'return_refund') {
        typeEl.innerHTML = `<span class="tag tag-blue"><i class="ri-arrow-go-back-line"></i> Return & Refund</span>`;
        trackBox.style.display = 'block';
        document.getElementById('rrTracking').textContent = d.Return_Tracking_Number || 'Not Uploaded Yet';
    } else {
        typeEl.innerHTML = `<span class="tag tag-orange"><i class="ri-hand-coin-line"></i> Refund Only</span>`;
        trackBox.style.display = 'none';
    }

    const recEl = document.getElementById('rrReceived');
    const hasReceived = (d.Refund_Has_Received_Goods == 1);
    recEl.textContent = hasReceived ? 'Yes, Received' : 'No, Not Received';
    recEl.style.color = hasReceived ? '#0F172A' : '#DC2626';

    document.getElementById('rrAmount').textContent = `RM ${parseFloat(d.Refund_Amount || 0).toFixed(2)}`;
    document.getElementById('rrAttempt').textContent = `${d.Request_Attempt || 1}st Try`;

    const refStatus = (d.Refund_Status || 'Unknown').replace(/_/g, ' ').toUpperCase();
    document.getElementById('rrStatusBadge').textContent = refStatus;

    const rawReason = d.Refund_Reason || 'No reason category selected';
    document.getElementById('rrReasonText').textContent = reasonMap[rawReason] || rawReason;
    document.getElementById('rrDesc').textContent = d.Refund_Description || 'No detailed description provided.';

    // Calculation UI
    handleOutcomeChange();

    // User info
    document.getElementById('buyerName').textContent = d.Reporting_Username || 'Unknown';
    document.getElementById('buyerId').textContent = `ID: ${d.Reporting_User_ID}`;
    document.getElementById('sellerName').textContent = d.Reported_Username || 'Unknown';
    document.getElementById('sellerId').textContent = `ID: ${d.Reported_User_ID}`;

    // Smart avatar
    setAvatar('buyerAvatar', d.Reporting_User_Avatar, d.Reporting_Username);
    setAvatar('sellerAvatar', d.Reported_Avatar, d.Reported_Username);

    // Details and replies
    const rawDisputeReason = d.Dispute_Reason || '';
    document.getElementById('dReason').textContent = reasonMap[rawDisputeReason] || rawDisputeReason;

    // Use new Description fields
    // Prioritize Buyer_Description, fallback to Dispute_Details
    document.getElementById('dDetails').textContent = d.Buyer_Description || d.Dispute_Details || '(No statement)';

    // Prioritize Seller_Description, fallback to Dispute_Seller_Response
    document.getElementById('sellerResponse').textContent = d.Seller_Description || d.Dispute_Seller_Response || 'Waiting for seller response...';

    document.getElementById('drReplyBuyer').value = d.Dispute_Admin_Reply_To_Buyer || '';
    document.getElementById('drReplySeller').value = d.Dispute_Admin_Reply_To_Seller || '';

    // Evidence images
    renderImgs(d.Dispute_Evidence_Image, 'buyerEvidence');
    renderImgs(d.Dispute_Seller_Evidence_Image, 'sellerEvidence');

    // Load timeline
    loadTimeline(d.Dispute_ID || disputeId);

    // Initialize UI status logic
    handleStatusLogic();
}

// ================= Timeline Loading Function =================
async function loadTimeline(id) {
    const container = document.getElementById('timelineContainer');
    if(!container) return;
    container.innerHTML = '<div style="text-align:center; padding:20px; color:#94a3b8;">Loading history...</div>';

    try {
        const res = await fetch(`../api/get_dispute_timeline.php?order_id=${params.get('id') || ''}&dispute_id=${id}`);
        const json = await res.json();

        if(!json.success || !json.data || !json.data.timeline) {
            container.innerHTML = '<div style="padding:15px; text-align:center;">No history records found.</div>';
            return;
        }

        const list = json.data.timeline;
        let html = '<div style="display:flex; flex-direction:column; gap:15px;">';

        list.forEach(item => {
            let bg = '#f1f5f9';
            let align = 'flex-start';
            let roleLabel = item.User_Role || item.role || 'User';
            let icon = 'ri-user-line';

            if (roleLabel === 'Admin' || roleLabel === 'System') {
                bg = '#FEFCE8'; align = 'center'; icon = 'ri-customer-service-2-line';
                roleLabel = 'System / Admin';
            } else if (roleLabel === 'Seller') {
                bg = '#EEF2FF'; align = 'flex-end'; icon = 'ri-store-2-line';
            } else {
                bg = '#FFFFFF'; align = 'flex-start'; icon = 'ri-user-smile-line';
            }

            // Image processing
            let imgs = '';
            let imgArr = item.Evidence_Images || item.evidence_images || item.images || [];
            if (typeof imgArr === 'string') {
                 try { imgArr = JSON.parse(imgArr); } catch(e){ imgArr = []; }
            }
            if(Array.isArray(imgArr) && imgArr.length > 0) {
                imgs = `<div style="display:flex; gap:5px; margin-top:5px; flex-wrap:wrap;">` +
                    imgArr.map(u => `<a href="${u.startsWith('http') ? u : '../../' + u}" target="_blank"><img src="${u.startsWith('http') ? u : '../../' + u}" style="width:50px; height:50px; object-fit:cover; border-radius:4px; border:1px solid #ccc;"></a>`).join('') +
                    `</div>`;
            }

            html += `
                <div style="align-self:${align}; max-width:85%; background:${bg}; padding:10px 14px; border-radius:12px; border:1px solid #e2e8f0; font-size:0.9rem;">
                    <div style="font-weight:700; color:#475569; font-size:0.75rem; margin-bottom:4px; display:flex; align-items:center; gap:4px;">
                        <i class="${icon}"></i> ${roleLabel} <span style="font-weight:400; color:#94a3b8;">• ${item.Created_At || item.created_at || ''}</span>
                    </div>
                    <div style="color:#1e293b; line-height:1.5;">${item.Content || item.content || '<i>(Evidence only)</i>'}</div>
                    ${imgs}
                </div>
            `;
        });

        html += '</div>';
        container.innerHTML = html;

    } catch(e) {
        console.error(e);
        container.innerHTML = '<div style="color:red; padding:10px;">Failed to load timeline.</div>';
    }
}

// ================= Helper Functions =================

// Set avatar (supports initial fallback)
function setAvatar(elId, url, username) {
    const el = document.getElementById(elId);
    if (!el) return;

    const name = username || '?';
    const initial = name.charAt(0);

    const showInitials = () => {
        const div = document.createElement('div');
        div.id = elId;
        div.className = 'avatar-initials';
        div.textContent = initial;
        if(el.parentNode) el.parentNode.replaceChild(div, el);
    };

    if (!url) {
        showInitials();
    } else {
        const fullUrl = url.startsWith('http') ? url : `../../${url}`;
        el.src = fullUrl;
        el.onerror = () => {
            console.warn(`Img load failed for ${username}, using initial.`);
            showInitials();
        };
    }
}

// Render image list
function renderImgs(jsonStr, elId) {
    const box = document.getElementById(elId);
    box.innerHTML = '';
    try {
        if(!jsonStr) return;
        const arr = typeof jsonStr === 'string' ? JSON.parse(jsonStr) : jsonStr;
        if(Array.isArray(arr)) {
            arr.forEach(url => {
                const img = document.createElement('img');
                img.src = url.startsWith('http') ? url : `../../${url}`;
                img.className = 'evidence-img';
                img.onclick = () => window.open(img.src);
                box.appendChild(img);
            });
        }
    } catch(e){ }
}

// Format decimal
function formatDecimal(el) {
    if(el.value === '') return;
    let val = parseFloat(el.value);
    if(!isNaN(val)) el.value = val.toFixed(2);
}

// Handle dropdown change
function handleOutcomeChange() {
    const outcome = document.getElementById('drOutcome').value;
    const amountSection = document.getElementById('amountSection');
    const amountInput = document.getElementById('drAmount');

    if (!outcome) {
        amountSection.style.display = 'none';
        return;
    }
    amountSection.style.display = 'block';

    if (outcome === 'refund_buyer') {
        amountInput.value = orderTotalAmount.toFixed(2);
        amountInput.disabled = true;
    } else if (outcome === 'refund_seller') {
        amountInput.value = 0;
        amountInput.disabled = true;
    } else if (outcome === 'partial') {
        amountInput.disabled = false;
        if(parseFloat(amountInput.value) === 0 || parseFloat(amountInput.value) === orderTotalAmount) {
            amountInput.value = '';
        }
    }
    updateCalculation();
}

// Real-time amount calculation
function updateCalculation() {
    const inputEl = document.getElementById('drAmount');
    let inputVal = parseFloat(inputEl.value);

    if (isNaN(inputVal)) inputVal = 0;
    if (inputVal < 0) { inputVal = 0; inputEl.value = 0; }
    if (inputVal > orderTotalAmount) { inputVal = orderTotalAmount; inputEl.value = orderTotalAmount.toFixed(2); }

    const sellerGets = orderTotalAmount - inputVal;

    document.getElementById('calcTotal').innerText = `RM ${orderTotalAmount.toFixed(2)}`;
    const buyerClass = inputVal > 0 ? 'highlight-green' : '';
    document.getElementById('calcBuyer').innerHTML = `<span class="${buyerClass}">RM ${inputVal.toFixed(2)}</span>`;
    document.getElementById('calcSeller').innerText = `RM ${sellerGets.toFixed(2)}`;
}

// ================= Save Logic =================
async function saveDisputeChanges() {
    const btn = document.getElementById('btnSaveDispute');
    const msg = document.getElementById('saveMsg');

    // 1. Get basic values
    let finalRefundAmount = parseFloat(document.getElementById('drAmount').value) || 0;
    const outcome = document.getElementById('drOutcome').value;
    const status = document.getElementById('updateStatus').value;

    // Force: If Resolved, Action must be None; otherwise read dropdown value
    let actionBy = (status === 'Resolved') ? 'None' : document.getElementById('actionRequiredBy').value;

    const replyBuyer = document.getElementById('drReplyBuyer').value.trim();
    const replySeller = document.getElementById('drReplySeller').value.trim();

    // 2. Basic validation
    if (status === 'Resolved') {
        if (!outcome) { alert('Please select an Outcome.'); return; }
        // Resolved must provide final verdict to both parties
        if (!replyBuyer || !replySeller) { alert('For Resolved cases, you MUST provide a final verdict message to BOTH parties.'); return; }
    }

    if (outcome === 'partial' && (finalRefundAmount < 0 || finalRefundAmount > orderTotalAmount)) { alert('Invalid Amount'); return; }

    // Mandatory message validation (only for non-Resolved)
    if (status !== 'Resolved') {
        if (actionBy === 'Buyer' && !replyBuyer) {
            alert('⚠️ Cannot Save:\n\nYou require action from the BUYER, but the message to the buyer is empty.\n\nPlease instruct them what evidence is needed.');
            document.getElementById('drReplyBuyer').focus();
            return;
        }
        if (actionBy === 'Seller' && !replySeller) {
            alert('⚠️ Cannot Save:\n\nYou require action from the SELLER, but the message to the seller is empty.\n\nPlease instruct them what is needed.');
            document.getElementById('drReplySeller').focus();
            return;
        }
        if (actionBy === 'Both') {
            if (!replyBuyer) {
                alert('⚠️ Cannot Save:\n\nPlease enter instructions for the BUYER.');
                document.getElementById('drReplyBuyer').focus();
                return;
            }
            if (!replySeller) {
                alert('⚠️ Cannot Save:\n\nPlease enter instructions for the SELLER.');
                document.getElementById('drReplySeller').focus();
                return;
            }
        }
    }
    // Validation end

    // 3. Start submission
    btn.disabled = true; btn.innerHTML = '<i class="ri-loader-4-line ri-spin"></i> Saving...';
    msg.textContent = '';

    try {
        const payload = {
            Dispute_ID: disputeId,
            Dispute_Status: status,
            Action_Required_By: actionBy,
            Dispute_Resolution_Outcome: outcome,
            Dispute_Refund_Amount: finalRefundAmount,
            Dispute_Admin_Reply_To_Buyer: replyBuyer,
            Dispute_Admin_Reply_To_Seller: replySeller
        };

        const res = await fetch(API_UPDATE, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });
        const json = await res.json();

        if(json.status === 'success') {
            msg.innerHTML = '<span style="color:green">✅ Saved Successfully!</span>';
            // Refresh page
            setTimeout(() => location.reload(), 1500);
        } else {
            throw new Error(json.message);
        }
    } catch(e) {
        msg.innerHTML = `<span style="color:red">❌ ${e.message}</span>`;
    } finally {
        btn.disabled = false; btn.innerHTML = '<i class="ri-save-line"></i> Save Changes';
    }
}

// ================= Status Logic Function =================
function handleStatusLogic() {
    const statusEl = document.getElementById('updateStatus');
    const actionSelect = document.getElementById('actionRequiredBy');
    if (!statusEl || !actionSelect) return;

    const status = statusEl.value;
    const labelBuyer = document.querySelector('label[for="drReplyBuyer"]');
    const labelSeller = document.querySelector('label[for="drReplySeller"]');
    // Try to find outcome parent container to control display
    let outcomeGroup = null;
    const outcomeEl = document.getElementById('drOutcome');
    if (outcomeEl) outcomeGroup = outcomeEl.parentElement;

    if (status === 'Resolved') {
        // Resolved: Force Action to None
        actionSelect.value = 'None';
        actionSelect.disabled = true;
        actionSelect.style.background = '#F3F4F6';

        if (labelBuyer) labelBuyer.innerHTML = 'Final Verdict (to Buyer) <span style="color:red">*</span>';
        if (labelSeller) labelSeller.innerHTML = 'Final Verdict (to Seller) <span style="color:red">*</span>';

        if (outcomeGroup) outcomeGroup.style.display = 'block';
    } else {
        // Restore editable
        actionSelect.disabled = false;
        actionSelect.style.background = '';

        if (labelBuyer) labelBuyer.textContent = 'Instruction / Message to Buyer';
        if (labelSeller) labelSeller.textContent = 'Instruction / Message to Seller';

        // NOTE: Outcome area should remain visible at all times per UX decision.
        // Previous code hid and reset the outcome select when switching away from Resolved.
        // That logic has been intentionally removed so we do NOT change outcomeGroup here.
    }

    // Trigger highlight logic
    highlightRequiredFields();
}

// ================= Auto Update to In Review =================
async function autoUpdateToInReview(data) {
    console.log('Auto-updating status from Open to In Review...');

    try {
        // Build minimal payload, only update status
        // Note: Keep Action_Required_By as is or set to Admin
        const payload = {
            Dispute_ID: data.Dispute_ID,
            Dispute_Status: 'In Review',
            Action_Required_By: 'Admin', // Admin intervened

            // Keep these fields as original or empty to prevent accidental clearing
            Dispute_Resolution_Outcome: data.Dispute_Resolution_Outcome,
            Dispute_Refund_Amount: data.Dispute_Refund_Amount,
            Dispute_Admin_Reply_To_Buyer: data.Dispute_Admin_Reply_To_Buyer,
            Dispute_Admin_Reply_To_Seller: data.Dispute_Admin_Reply_To_Seller
        };

        const res = await fetch(API_UPDATE, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });

        const json = await res.json();

        if (json.status === 'success') {
            // Update UI display
            const stEl = document.getElementById('statusDisplay');
            stEl.textContent = 'In Review';
            stEl.className = 'status-badge st-InReview';
            document.getElementById('updateStatus').value = 'In Review';

            // Optional log
            // console.log('Status automatically updated to In Review');
        }
    } catch (e) {
        console.warn('Auto-update failed:', e);
    }
}
