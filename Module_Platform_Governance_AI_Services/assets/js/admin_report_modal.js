/**
 * admin_report_modal.js
 * 专门处理举报审核的“大弹窗”逻辑：封号选择、时间胶囊、商品下架联动
 */

// 模态框上下文变量
let modalReportId = null;
let modalActionType = null;

// 打开操作弹窗
function openActionModal(id, actionType) {
    modalReportId = id;
    modalActionType = actionType;

    // 从全局变量 allReports 中查找当前举报信息 (确保主页面定义了 allReports)
    const r = typeof allReports !== 'undefined' ? allReports.find(x => x.id == id) : null;

    const modal = document.getElementById('action-modal');
    const title = document.getElementById('action-title');
    const textSpan = document.getElementById('action-type-text');
    const confirmBtn = document.getElementById('action-confirm-btn');
    const banSection = document.getElementById('ban-section');
    const prodSection = document.getElementById('product-section');

    // 1. 重置表单状态
    document.getElementById('admin-reply').value = '';

    const banCheckbox = document.getElementById('ban-user-checkbox');
    if(banCheckbox) banCheckbox.checked = false;

    const hideCheckbox = document.getElementById('hide-product-checkbox');
    if(hideCheckbox) hideCheckbox.checked = false;

    toggleBanOptions(); // 默认隐藏时间选择器

    // 2. 根据操作类型 (Resolve / Dismiss) 设置 UI
    if (actionType === 'Resolved') {
        title.textContent = '✅ Resolve Report';
        title.style.color = 'var(--success)';
        textSpan.textContent = 'MARK AS RESOLVED';
        confirmBtn.textContent = 'Confirm & Resolve';
        confirmBtn.className = 'btn-confirm resolve';

        // 显示封号模块
        if(banSection) banSection.style.display = 'block';

        // 智能显示：只有举报类型为 'product' 时才显示商品下架选项
        if (r && r.type === 'product' && prodSection) {
            prodSection.style.display = 'block';
        } else if (prodSection) {
            prodSection.style.display = 'none';
        }

    } else {
        // Dismiss 逻辑
        title.textContent = '🗑️ Dismiss Report';
        title.style.color = 'var(--text-gray)';
        textSpan.textContent = 'DISMISS (Reject)';
        confirmBtn.textContent = 'Dismiss Report';
        confirmBtn.className = 'btn-confirm dismiss';

        // 隐藏高级选项
        if(banSection) banSection.style.display = 'none';
        if(prodSection) prodSection.style.display = 'none';
    }

    // 绑定提交事件
    confirmBtn.onclick = submitAction;
    modal.classList.add('active');
}

// 关闭操作弹窗
function closeActionModal() {
    document.getElementById('action-modal').classList.remove('active');
    modalReportId = null;
}

// 切换封号时间选项显示 (Checkbox onChange)
function toggleBanOptions() {
    const checkbox = document.getElementById('ban-user-checkbox');
    const options = document.getElementById('ban-duration-container');
    if (checkbox && options) {
        options.style.display = checkbox.checked ? 'grid' : 'none';
    }
}

// 选择封号时长 (Time Capsule/Chips 点击事件)
function selectDuration(element, value) {
    // 移除其他选中状态
    document.querySelectorAll('.duration-chip').forEach(el => el.classList.remove('active'));
    // 选中当前
    element.classList.add('active');
    // 存值到隐藏 input
    document.getElementById('selected-ban-duration').value = value;
}

// 提交操作 (Submit to Backend)
async function submitAction() {
    if(!modalReportId) return;

    const reply = document.getElementById('admin-reply').value;
    const banCheckbox = document.getElementById('ban-user-checkbox');
    const hideProdCheckbox = document.getElementById('hide-product-checkbox');
    const banDuration = document.getElementById('selected-ban-duration').value;

    const requestData = {
        id: modalReportId,
        status: modalActionType,
        reply: reply,
        // 如果 checkbox 存在且勾选，则为 true
        shouldBan: banCheckbox && banCheckbox.offsetParent !== null ? banCheckbox.checked : false,
        banDuration: banDuration,
        hideProduct: hideProdCheckbox && hideProdCheckbox.offsetParent !== null ? hideProdCheckbox.checked : false
    };

    console.log("Submitting:", requestData);

    try {
        // 调用后端接口
        const response = await fetch('../api/admin_report_update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(requestData)
        });

        const result = await response.json();

        if (result.success) {
            let msg = "Report updated.";
            if (requestData.shouldBan) msg += " User Banned (" + banDuration + ").";
            if (requestData.hideProduct) msg += " Product Hidden.";

            showToast(msg, "success");
            closeActionModal();

            // 调用主页面的刷新函数 (如果存在)
            if (typeof fetchReports === 'function') {
                fetchReports();
            }
        } else {
            showToast("Error: " + result.message, "error");
        }
    } catch (error) {
        console.error(error);
        showToast("Network Error", "error");
    }
}