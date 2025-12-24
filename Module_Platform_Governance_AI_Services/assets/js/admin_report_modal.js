/**
 * admin_report_modal.js
 * 处理举报审核弹窗逻辑：封号（含自定义时长）、下架、双向回复
 * 适配数据库字段: Report_ID, Report_Type
 */

// 模态框上下文变量
let modalReportId = null;
let modalActionType = null;

/**
 * 打开操作弹窗
 * @param {number} id - Report ID
 * @param {string} actionType - 'Resolved' or 'Dismissed'
 */
function openActionModal(id, actionType) {
    modalReportId = id;
    modalActionType = actionType;

    // 1. 获取当前举报对象的详情
    // 兼容处理：有些 API 可能返回 id，有些返回 Report_ID
    const r = typeof allReports !== 'undefined'
        ? allReports.find(x => (x.Report_ID == id) || (x.id == id))
        : null;

    // 2. 获取 DOM 元素
    const modal = document.getElementById('action-modal');
    const title = document.getElementById('action-title');
    const textSpan = document.getElementById('action-type-text');
    const confirmBtn = document.getElementById('action-confirm-btn');

    // 区域 DOM
    const banSection = document.getElementById('ban-section');
    const prodSection = document.getElementById('product-section');

    // 3. 重置表单状态
    // 清空两个回复框
    const replyReporter = document.getElementById('reply-to-reporter');
    const replyReported = document.getElementById('reply-to-reported');
    if (replyReporter) replyReporter.value = '';
    if (replyReported) replyReported.value = '';

    // 重置 Checkbox
    const banCheckbox = document.getElementById('ban-user-checkbox');
    if (banCheckbox) banCheckbox.checked = false;

    const hideCheckbox = document.getElementById('hide-product-checkbox');
    if (hideCheckbox) hideCheckbox.checked = false;

    // 重置封号选项 UI (Checkbox联动)
    toggleBanOptions();

    // 重置封号时长选择器 (含自定义处理)
    document.querySelectorAll('.duration-chip').forEach(el => el.classList.remove('active'));

    // 默认选中 3 Days
    const defaultChip = document.querySelector('.duration-chip[onclick*="3d"]');
    if(defaultChip) defaultChip.classList.add('active');

    // 重置隐藏的提交值
    const durationInput = document.getElementById('selected-ban-duration');
    if(durationInput) durationInput.value = '3d';

    // 隐藏并清空自定义输入框
    const customRow = document.getElementById('custom-ban-row');
    const customInput = document.getElementById('custom-ban-input');
    if(customRow) customRow.style.display = 'none';
    if(customInput) customInput.value = '';


    // 4. 根据操作类型 (Resolved / Dismissed) 设置 UI 界面
    if (actionType === 'Resolved') {
        title.textContent = '✅ Resolve Report';
        title.style.color = '#10B981'; // Success Green
        textSpan.textContent = 'MARK AS RESOLVED';
        confirmBtn.textContent = 'Confirm & Resolve';
        confirmBtn.className = 'btn-confirm resolve';

        // 显示 "封禁用户" 区域
        if (banSection) banSection.style.display = 'block';

        // 智能显示 "下架商品" 区域
        const rType = r ? (r.Report_Type || r.type || '') : '';
        if (rType.toLowerCase() === 'product' && prodSection) {
            prodSection.style.display = 'block';
        } else if (prodSection) {
            prodSection.style.display = 'none';
        }

    } else {
        // Dismiss (驳回举报) 逻辑
        title.textContent = '🗑️ Dismiss Report';
        title.style.color = '#6B7280'; // Gray
        textSpan.textContent = 'DISMISS (Reject)';
        confirmBtn.textContent = 'Dismiss Report';
        confirmBtn.className = 'btn-confirm dismiss';

        // Dismiss 时不需要显示封号或下架选项
        if (banSection) banSection.style.display = 'none';
        if (prodSection) prodSection.style.display = 'none';
    }

    // 绑定提交事件
    confirmBtn.onclick = submitAction;

    // 显示弹窗
    modal.classList.add('active');
}

// 关闭操作弹窗
function closeActionModal() {
    const modal = document.getElementById('action-modal');
    if (modal) modal.classList.remove('active');
    modalReportId = null;
}

// 切换封号时间选项显示 (Checkbox onChange 事件)
function toggleBanOptions() {
    const checkbox = document.getElementById('ban-user-checkbox');
    const options = document.getElementById('ban-duration-container');
    if (checkbox && options) {
        // [关键修复] 设置为 grid 而不是 block，确保网格布局生效
        options.style.display = checkbox.checked ? 'grid' : 'none';
    }
}

// 选择封号时长 (Time Capsule/Chips 点击事件)
function selectDuration(element, value) {
    // 1. UI 样式切换
    document.querySelectorAll('.duration-chip').forEach(el => el.classList.remove('active'));
    element.classList.add('active');

    // 2. 获取相关 DOM
    const customRow = document.getElementById('custom-ban-row');
    const durationInput = document.getElementById('selected-ban-duration');
    const customInput = document.getElementById('custom-ban-input');

    // 3. 逻辑判断
    if (value === 'custom') {
        // 如果选了自定义：显示输入框
        if(customRow) customRow.style.display = 'block';
        if(customInput) {
            customInput.focus();
            // 如果输入框里已有值，就用输入框的值，否则置空等待输入
            durationInput.value = customInput.value ? customInput.value : '';
        }
    } else {
        // 如果选了固定选项：隐藏自定义输入框，直接赋值
        if(customRow) customRow.style.display = 'none';
        durationInput.value = value;
    }
}

// 处理自定义天数输入
function updateCustomDuration(val) {
    const durationInput = document.getElementById('selected-ban-duration');
    // 只有当数字有效时才更新提交值
    if (val && val.length > 0) {
        durationInput.value = val; // 存入纯数字，例如 "15"
    } else {
        durationInput.value = ''; // 输入为空时清空提交值
    }
}

// 提交操作 (Submit to Backend)
async function submitAction() {
    if (!modalReportId) return;

    const confirmBtn = document.getElementById('action-confirm-btn');

    // 获取两个回复框的值
    const replyReporterInput = document.getElementById('reply-to-reporter');
    const replyReportedInput = document.getElementById('reply-to-reported');
    const replyReporter = replyReporterInput ? replyReporterInput.value : '';
    const replyReported = replyReportedInput ? replyReportedInput.value : '';

    // 获取其他表单数据
    const banCheckbox = document.getElementById('ban-user-checkbox');
    const hideProdCheckbox = document.getElementById('hide-product-checkbox');
    const banDurationInput = document.getElementById('selected-ban-duration');

    // 如果是自定义输入且为空，给个默认值防止报错，或者在后端处理
    const banDuration = (banDurationInput && banDurationInput.value) ? banDurationInput.value : '3d';

    // 判断 Checkbox 是否被勾选
    const isBanChecked = (banCheckbox && banCheckbox.offsetParent !== null) ? banCheckbox.checked : false;
    const isHideChecked = (hideProdCheckbox && hideProdCheckbox.offsetParent !== null) ? hideProdCheckbox.checked : false;

    // 构造请求数据
    const requestData = {
        Report_ID: modalReportId,
        status: modalActionType,        // 'Resolved' or 'Dismissed'

        reply_to_reporter: replyReporter, // 给举报人的回复
        reply_to_reported: replyReported, // 给被举报人的回复

        shouldBan: isBanChecked,
        banDuration: banDuration,       // '3d', '365d', 'forever', or '15' (custom)
        hideProduct: isHideChecked      // boolean
    };

    console.log("Submitting:", requestData);

    // UI Loading 状态
    if(confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Processing...';
    }

    try {
        // 调用后端接口
        const response = await fetch('../api/admin_report_update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(requestData)
        });

        const result = await response.json();

        if (result.success) {
            let msg = "Report status updated.";
            if (requestData.status === 'Resolved') {
                msg = "Report Resolved.";
            } else {
                msg = "Report Dismissed.";
            }

            if (typeof showToast === 'function') {
                showToast(msg, "success");
            } else {
                alert(msg);
            }

            closeActionModal();

            // 刷新页面或列表
            if (typeof fetchReports === 'function') {
                fetchReports();
            } else {
                location.reload();
            }
        } else {
            if (typeof showToast === 'function') {
                showToast("Error: " + result.message, "error");
            } else {
                alert("Error: " + result.message);
            }
        }
    } catch (error) {
        console.error(error);
        if (typeof showToast === 'function') {
            showToast("Network Error", "error");
        } else {
            alert("Network Error: " + error.message);
        }
    } finally {
        if(confirmBtn) confirmBtn.disabled = false;
    }
}