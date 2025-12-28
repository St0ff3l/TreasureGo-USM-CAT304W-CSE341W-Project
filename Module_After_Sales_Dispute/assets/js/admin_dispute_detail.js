// ================= 全局配置 =================
const API_GET = '../api/admin_dispute_get.php';
const API_UPDATE = '../api/admin_dispute_update.php';
const params = new URLSearchParams(window.location.search);
const disputeId = params.get('id');
let orderTotalAmount = 0.00;

// ================= DOM 元素监听与绑定 =================
document.addEventListener('DOMContentLoaded', () => {
    // 绑定保存按钮
    const btnSave = document.getElementById('btnSaveDispute');
    if(btnSave) btnSave.addEventListener('click', saveDisputeChanges);

    // 绑定下拉菜单变化
    const outcomeSelect = document.getElementById('drOutcome');
    if(outcomeSelect) outcomeSelect.addEventListener('change', handleOutcomeChange);

    // 绑定金额输入框 (实时计算 + 格式化)
    const amountInput = document.getElementById('drAmount');
    if(amountInput) {
        amountInput.addEventListener('input', updateCalculation);
        amountInput.addEventListener('blur', () => formatDecimal(amountInput));
    }

    // 初始化加载
    init();
});

// ================= 初始化 =================
async function init() {
    if(!disputeId) { alert('No ID provided'); return; }
    document.getElementById('dID').textContent = disputeId;

    try {
        const res = await fetch(`${API_GET}?dispute_id=${disputeId}`);
        const json = await res.json();
        if(json.status !== 'success') throw new Error(json.message);
        render(json.data);
    } catch(e) {
        alert('Error loading dispute: ' + e.message);
        console.error(e);
    }
}

// ================= 页面渲染 =================
function render(d) {
    // 🔥🔥🔥 1. 定义翻译字典 (新增) 🔥🔥🔥
    const reasonMap = {
        // --- 退款理由 ---
        'damaged': 'Item Damaged / Defective',
        'wrong_item': 'Received Wrong Item',
        'not_described': 'Item Not As Described',
        'missing_parts': 'Missing Parts / Accessories',
        'fake': 'Counterfeit / Fake Item',
        'other': 'Other',

        // --- 争议理由 (根据实际情况扩展) ---
        'seller_wrongly_rejected': 'Seller wrongly rejected request',
        'seller_unresponsive': 'Seller is unresponsive',
        'did_not_receive_refund': 'Did not receive refund after return',
        'goods_rejected_by_buyer': 'Buyer returned damaged/wrong item',
        'buyer_misuse': 'Buyer misused the product'
    };

    // 2. 基础数据
    orderTotalAmount = parseFloat(d.Orders_Total_Amount || 0);
    document.getElementById('orderTotalHidden').value = orderTotalAmount;

    // 3. 状态回显
    const st = d.Dispute_Status;
    const stClean = st.replace(/\s+/g, '');
    const stEl = document.getElementById('statusDisplay');
    stEl.textContent = st;
    stEl.className = `status-badge st-${stClean}`;
    document.getElementById('updateStatus').value = st;

    // 4. 判决回显
    document.getElementById('drOutcome').value = d.Dispute_Resolution_Outcome || '';
    if(d.Dispute_Refund_Amount) document.getElementById('drAmount').value = d.Dispute_Refund_Amount;

    // 5. 填充退款申请详情卡片
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

    // 🔥🔥🔥 修改点：使用字典翻译 Refund Reason 🔥🔥🔥
    const rawReason = d.Refund_Reason || 'No reason category selected';
    document.getElementById('rrReasonText').textContent = reasonMap[rawReason] || rawReason;

    document.getElementById('rrDesc').textContent = d.Refund_Description || 'No detailed description provided.';

    // 6. 更新计算UI
    handleOutcomeChange();

    // 7. 用户信息
    document.getElementById('buyerName').textContent = d.Reporting_Username || 'Unknown';
    document.getElementById('buyerId').textContent = `ID: ${d.Reporting_User_ID}`;
    document.getElementById('sellerName').textContent = d.Reported_Username || 'Unknown';
    document.getElementById('sellerId').textContent = `ID: ${d.Reported_User_ID}`;

    // 8. 设置智能头像
    setAvatar('buyerAvatar', d.Reporting_User_Avatar, d.Reporting_Username);
    setAvatar('sellerAvatar', d.Reported_User_Avatar, d.Reported_Username);

    // 9. 详情与回复
    // 🔥🔥🔥 修改点：使用字典翻译 Dispute Reason 🔥🔥🔥
    const rawDisputeReason = d.Dispute_Reason || '';
    document.getElementById('dReason').textContent = reasonMap[rawDisputeReason] || rawDisputeReason;

    document.getElementById('dDetails').textContent = d.Dispute_Details;
    if(d.Dispute_Seller_Response) document.getElementById('sellerResponse').textContent = d.Dispute_Seller_Response;

    document.getElementById('drReplyBuyer').value = d.Dispute_Admin_Reply_To_Buyer || '';
    document.getElementById('drReplySeller').value = d.Dispute_Admin_Reply_To_Seller || '';

    // 10. 证据图片
    renderImgs(d.Dispute_Evidence_Image, 'buyerEvidence');
    renderImgs(d.Dispute_Seller_Evidence_Image, 'sellerEvidence');

    // --- 新增：回显 Action Required By ---
    const actionSelect = document.getElementById('actionRequiredBy');
    if(actionSelect && d.Action_Required_By) {
        actionSelect.value = d.Action_Required_By;
    }

    // --- 新增：加载时间线 ---
    // 如果后端字段名字不同，请调整 d.Dispute_ID 或使用 disputeId
    loadTimeline(d.Dispute_ID || disputeId);
}

// ================= Timeline 加载函数 =================
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

            // 图片处理
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

// ================= 辅助函数 =================

// 设置头像 (支持首字母回退)
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

// 渲染图片列表
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

// 格式化小数
function formatDecimal(el) {
    if(el.value === '') return;
    let val = parseFloat(el.value);
    if(!isNaN(val)) el.value = val.toFixed(2);
}

// 处理下拉菜单变化
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

// 实时计算金额
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

// ================= 保存逻辑 =================
async function saveDisputeChanges() {
    const btn = document.getElementById('btnSaveDispute');
    const msg = document.getElementById('saveMsg');

    let finalRefundAmount = parseFloat(document.getElementById('drAmount').value) || 0;
    const outcome = document.getElementById('drOutcome').value;
    const status = document.getElementById('updateStatus').value;

    if (status === 'Resolved' && !outcome) { alert('Please select an Outcome.'); return; }
    if (outcome === 'partial' && (finalRefundAmount < 0 || finalRefundAmount > orderTotalAmount)) { alert('Invalid Amount'); return; }

    btn.disabled = true; btn.innerHTML = '<i class="ri-loader-4-line ri-spin"></i> Saving...';
    msg.textContent = '';

    try {
        const payload = {
            Dispute_ID: disputeId,
            Dispute_Status: status,
            // --- 新增：Action Required By ---
            Action_Required_By: (document.getElementById('actionRequiredBy') ? document.getElementById('actionRequiredBy').value : null),
            Dispute_Resolution_Outcome: outcome,
            Dispute_Refund_Amount: finalRefundAmount,
            Dispute_Admin_Reply_To_Buyer: document.getElementById('drReplyBuyer').value,
            Dispute_Admin_Reply_To_Seller: document.getElementById('drReplySeller').value
        };

        const res = await fetch(API_UPDATE, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });
        const json = await res.json();

        if(json.status === 'success') {
            msg.innerHTML = '<span style="color:green">✅ Saved Successfully!</span>';
            const stClean = status.replace(/\s+/g, '');
            const stEl = document.getElementById('statusDisplay');
            stEl.textContent = status;
            stEl.className = `status-badge st-${stClean}`;
        } else {
            throw new Error(json.message);
        }
    } catch(e) {
        msg.innerHTML = `<span style="color:red">❌ ${e.message}</span>`;
    } finally {
        btn.disabled = false; btn.innerHTML = '<i class="ri-save-line"></i> Save Changes';
    }
}