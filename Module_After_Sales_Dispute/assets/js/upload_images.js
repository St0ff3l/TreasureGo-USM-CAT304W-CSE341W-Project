/**
 * TreasureGo Image Uploader Component
 * Logic Only (CSS is loaded via external file)
 */
class TreasureGoUploader {
    constructor(containerSelector, config = {}) {
        this.container = document.querySelector(containerSelector);
        if (!this.container) {
            console.error(`TreasureGoUploader: Container '${containerSelector}' not found.`);
            return;
        }

        this.inputName = config.inputName || 'evidence[]';
        this.dt = new DataTransfer();

        this.render();
        this.bindEvents();
    }

    // 渲染 HTML 结构
    render() {
        this.container.innerHTML = `
            <div class="tg-upload-area">
                <button type="button" class="tg-clear-btn">Clear All</button>
                
                <div class="tg-placeholder">
                    <div class="tg-icon">📷</div>
                    <p style="margin-top: 15px; color: #6B7280; font-weight: 500;">Click or Drag & Drop images</p>
                </div>
                
                <div class="tg-preview"></div>
                
                <button type="button" class="tg-add-btn">+</button>
                <input type="file" name="${this.inputName}" multiple accept="image/*" style="display: none;">
            </div>
        `;

        this.ui = {
            area: this.container.querySelector('.tg-upload-area'),
            placeholder: this.container.querySelector('.tg-placeholder'),
            preview: this.container.querySelector('.tg-preview'),
            input: this.container.querySelector('input[type="file"]'),
            addBtn: this.container.querySelector('.tg-add-btn'),
            clearBtn: this.container.querySelector('.tg-clear-btn') // 必须能找到上面的按钮
        };
    }

    // 绑定事件逻辑
    bindEvents() {
        const { area, input, placeholder, addBtn, clearBtn } = this.ui;

        const trigger = () => input.click();
        placeholder.addEventListener('click', trigger);
        addBtn.addEventListener('click', trigger);

        input.addEventListener('change', (e) => this.handleFiles(e.target.files));

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(evt => {
            area.addEventListener(evt, (e) => { e.preventDefault(); e.stopPropagation(); });
        });
        ['dragenter', 'dragover'].forEach(evt => area.classList.add('drag-over'));
        ['dragleave', 'drop'].forEach(evt => area.classList.remove('drag-over'));

        area.addEventListener('drop', (e) => this.handleFiles(e.dataTransfer.files));

        // 绑定清空按钮事件
        if (clearBtn) {
            clearBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.dt.items.clear();
                this.updateInput();
            });
        }
    }

    // 处理文件核心逻辑
    handleFiles(files) {
        let hasNew = false;
        for (let file of files) {
            if (file.type.startsWith('image/')) {
                this.dt.items.add(file);
                hasNew = true;
            }
        }
        if (hasNew) this.updateInput();
    }

    // 删除单张图片
    removeFile(index) {
        const newDt = new DataTransfer();
        const files = this.dt.files;
        for (let i = 0; i < files.length; i++) {
            if (i !== index) newDt.items.add(files[i]);
        }
        this.dt = newDt;
        this.updateInput();
    }

    // 更新 UI 和 Input 值
    updateInput() {
        this.ui.input.files = this.dt.files;
        this.renderPreview();
    }

    renderPreview() {
        const { preview, placeholder, clearBtn, addBtn } = this.ui;
        preview.innerHTML = '';

        if (this.dt.files.length > 0) {
            placeholder.style.display = 'none';
            if (clearBtn) clearBtn.style.display = 'block'; // 显示清空按钮
            addBtn.style.display = 'flex';
            preview.style.display = 'flex';

            Array.from(this.dt.files).forEach((file, index) => {
                const reader = new FileReader();
                reader.onload = (e) => {
                    const div = document.createElement('div');
                    div.className = 'tg-preview-wrapper';
                    div.innerHTML = `
                        <img src="${e.target.result}" class="tg-preview-item">
                        <div class="tg-del-btn">✕</div>
                    `;
                    div.querySelector('.tg-del-btn').onclick = (ev) => {
                        ev.stopPropagation();
                        this.removeFile(index);
                    };
                    preview.appendChild(div);
                };
                reader.readAsDataURL(file);
            });
        } else {
            placeholder.style.display = 'block';
            if (clearBtn) clearBtn.style.display = 'none'; // 隐藏清空按钮
            addBtn.style.display = 'none';
            preview.style.display = 'none';
        }
    }

    getFiles() {
        return this.ui.input.files;
    }
}