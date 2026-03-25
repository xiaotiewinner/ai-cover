/**
 * AICover Plugin - 编辑器面板交互逻辑
 * 
 * @author 小铁
 * @version 1.0.0
 * @link https://www.xiaotiewinner.com/ai-cover
 */
(function () {
    'use strict';

    // ─── 工具函数 ───────────────────────────────────────────────────────

    function $(sel, ctx) { return (ctx || document).querySelector(sel); }
    function $$(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }

    function setStatus(el, type, html) {
        el.innerHTML = html;
        el.className = 'aicover-status aicover-status--' + type;
    }

    function loadingHtml(msg) {
        return `<div class="aicover-status--loading">
            <div class="aicover-spinner"></div>
            <span>${msg}</span>
        </div>
        <div class="aicover-progress"><div class="aicover-progress-bar"></div></div>`;
    }

    async function apiPost(actionUrl, params) {
        const qs = new URLSearchParams(params);
        const res = await fetch(actionUrl + '?' + qs.toString(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ─── 初始化面板 ─────────────────────────────────────────────────────

    const panel = document.getElementById('aicover-panel');
    if (!panel) return;

    const statusEl  = document.getElementById('aicover-status');
    const promptEl  = document.getElementById('aicover-custom-prompt');
    const previewRow = document.querySelector('.aicover-preview-row');
    const coverBox  = document.getElementById('aicover-cover-box');
    const currentImg = document.getElementById('aicover-current-img');
    const panelNonce =
        document.getElementById('aicover-btn-cover')?.dataset.nonce
        || document.getElementById('aicover-btn-summary')?.dataset.nonce
        || document.getElementById('aicover-btn-title')?.dataset.nonce
        || document.getElementById('aicover-btn-prompt')?.dataset.nonce
        || '';

    // ─── 生成封面 ───────────────────────────────────────────────────────

    const btnCover = document.getElementById('aicover-btn-cover');
    if (btnCover) {
        btnCover.addEventListener('click', async function () {
            const cid    = this.dataset.cid;
            const action = this.dataset.action;

            if (!cid || cid === '0') {
                setStatus(statusEl, 'error', '⚠️ 请先保存草稿后再生成封面（需要文章 ID）');
                return;
            }

            if (!confirm('确认生成新封面？若已有封面将被替换。')) return;

            const customPrompt = promptEl ? promptEl.value.trim() : '';

            this.disabled = true;
            setStatus(statusEl, 'loading', loadingHtml('正在调用 AI 生成封面，请稍候（约 15-30 秒）…'));

            try {
                const data = await apiPost(action, {
                    do: 'cover', cid, prompt: customPrompt, force: 1, nonce: panelNonce
                });

                if (data.success) {
                    // 更新封面预览
                    const ts = '?t=' + Date.now();
                    if (currentImg) {
                        currentImg.src = data.coverUrl + ts;
                    } else if (coverBox) {
                        coverBox.innerHTML = `
                            <img src="${escapeHtml(data.coverUrl + ts)}" alt="封面" id="aicover-current-img">
                            <span class="aicover-preview-label">当前封面</span>`;
                    }

                    // 更新 OG 图预览
                    if (data.ogUrl) {
                        const ogBox = document.getElementById('aicover-og-img');
                        if (ogBox) {
                            ogBox.src = data.ogUrl + ts;
                        }
                    }

                    // 添加到历史
                    addHistoryItem({
                        coverUrl: data.coverUrl,
                        coverPath: data.coverPath,
                        prompt: data.prompt,
                        cid
                    }, action);

                    setStatus(statusEl, 'success',
                        `✅ 封面生成成功！<br>
                        <div class="aicover-prompt-preview" style="margin-top:8px;">
                            <strong>使用的 Prompt：</strong><br>${escapeHtml(data.prompt)}
                        </div>`
                    );
                } else {
                    setStatus(statusEl, 'error', '❌ 生成失败：' + escapeHtml(data.error || '未知错误'));
                }
            } catch (e) {
                setStatus(statusEl, 'error', '❌ 请求失败：' + escapeHtml(e.message));
            } finally {
                this.disabled = false;
            }
        });
    }

    // ─── 生成摘要 ───────────────────────────────────────────────────────

    const btnSummary = document.getElementById('aicover-btn-summary');
    if (btnSummary) {
        btnSummary.addEventListener('click', async function () {
            const cid    = this.dataset.cid;
            const action = this.dataset.action;

            if (!cid || cid === '0') {
                setStatus(statusEl, 'error', '⚠️ 请先保存草稿');
                return;
            }

            this.disabled = true;
            setStatus(statusEl, 'loading', loadingHtml('正在生成摘要…'));

            try {
                const data = await apiPost(action, { do: 'summary', cid, nonce: panelNonce });

                if (data.success) {
                    setStatus(statusEl, 'success',
                        `✅ 摘要已生成并保存：<br>
                        <div style="margin-top:8px;padding:10px 12px;background:#fff;border-radius:5px;border:1px solid #d1fae5;font-size:13px;color:#374151;line-height:1.7;">
                            ${escapeHtml(data.summary)}
                        </div>`
                    );

                    // 尝试更新页面上的摘要输入框
                    const descField = document.querySelector('[name="fields[customSummary]"]')
                        || document.getElementById('description')
                        || document.querySelector('textarea[name="description"]')
                        || document.querySelector('[data-field="description"]');
                    if (descField) {
                        descField.value = data.summary;
                        // 触发 change 事件（React/Vue 兼容）
                        const evt = new Event('input', { bubbles: true });
                        descField.dispatchEvent(evt);
                    }
                } else {
                    setStatus(statusEl, 'error', '❌ 摘要生成失败：' + escapeHtml(data.error || ''));
                }
            } catch (e) {
                setStatus(statusEl, 'error', '❌ 请求失败：' + escapeHtml(e.message));
            } finally {
                this.disabled = false;
            }
        });
    }

    // ─── 标题建议 ───────────────────────────────────────────────────────

    const btnTitle = document.getElementById('aicover-btn-title');
    if (btnTitle) {
        btnTitle.addEventListener('click', async function () {
            const cid    = this.dataset.cid;
            const action = this.dataset.action;

            if (!cid || cid === '0') {
                setStatus(statusEl, 'error', '⚠️ 请先保存草稿');
                return;
            }

            this.disabled = true;
            setStatus(statusEl, 'loading', loadingHtml('正在生成标题建议…'));

            try {
                const data = await apiPost(action, { do: 'title', cid, nonce: panelNonce });

                if (data.success && data.titles && data.titles.length) {
                    const items = data.titles.map((t, i) => `
                        <li>
                            <span>${escapeHtml(t)}</span>
                            <button type="button" class="aicover-use-title" data-title="${escapeHtml(t)}">使用</button>
                        </li>
                    `).join('');

                    setStatus(statusEl, 'info',
                        `💡 标题建议（点击「使用」填入标题框）：
                        <ul class="aicover-title-list" style="margin-top:10px;">${items}</ul>`
                    );

                    // 绑定使用按钮
                    $$('.aicover-use-title', statusEl).forEach(btn => {
                        btn.addEventListener('click', function () {
                            const title = this.dataset.title;
                            // 兼容多种标题选择器
                            const titleField =
                                document.getElementById('title') ||
                                document.querySelector('input[name="title"]') ||
                                document.querySelector('.typecho-post-title input');
                            if (titleField) {
                                titleField.value = title;
                                titleField.focus();
                                const evt = new Event('input', { bubbles: true });
                                titleField.dispatchEvent(evt);
                            }
                        });
                    });
                } else {
                    setStatus(statusEl, 'error', '❌ 标题生成失败：' + escapeHtml(data.error || ''));
                }
            } catch (e) {
                setStatus(statusEl, 'error', '❌ 请求失败：' + escapeHtml(e.message));
            } finally {
                this.disabled = false;
            }
        });
    }

    // ─── 生成 OG 图 ─────────────────────────────────────────────────────

    const btnOg = document.getElementById('aicover-btn-og');
    if (btnOg) {
        btnOg.addEventListener('click', async function () {
            const cid    = this.dataset.cid;
            const action = this.dataset.action;

            if (!cid || cid === '0') {
                setStatus(statusEl, 'error', '⚠️ 请先保存草稿');
                return;
            }

            this.disabled = true;
            setStatus(statusEl, 'loading', loadingHtml('正在生成 OG 分享图…'));

            try {
                const data = await apiPost(action, { do: 'og', cid, nonce: panelNonce });
                if (data.success) {
                    const ts = '?t=' + Date.now();
                    const ogImg = ensureOgPreviewBox();
                    if (ogImg) {
                        ogImg.src = data.ogUrl + ts;
                    }
                    setStatus(statusEl, 'success', '✅ ' + escapeHtml(data.message));
                } else {
                    setStatus(statusEl, 'error', '❌ ' + escapeHtml(data.error || ''));
                }
            } catch (e) {
                setStatus(statusEl, 'error', '❌ 请求失败：' + escapeHtml(e.message));
            } finally {
                this.disabled = false;
            }
        });
    }

    // ─── 预览 Prompt ────────────────────────────────────────────────────

    const btnPrompt = document.getElementById('aicover-btn-prompt');
    if (btnPrompt) {
        btnPrompt.addEventListener('click', async function () {
            const cid    = this.dataset.cid;
            const action = this.dataset.action;

            if (!cid || cid === '0') {
                setStatus(statusEl, 'error', '⚠️ 请先保存草稿');
                return;
            }

            const customPrompt = promptEl ? promptEl.value.trim() : '';
            this.disabled = true;
            setStatus(statusEl, 'loading', loadingHtml('正在生成 Prompt 预览…'));

            try {
                const data = await apiPost(action, { do: 'prompt', cid, prompt: customPrompt, nonce: panelNonce });

                if (data.success) {
                    setStatus(statusEl, 'info',
                        `🔍 将使用以下 Prompt 生成图像：
                        <div class="aicover-prompt-preview" style="margin-top:8px;">
                            ${escapeHtml(data.prompt)}
                        </div>
                        <div style="margin-top:8px;font-size:12px;color:#64748b;">
                            字符数：${data.prompt.length} / 建议不超过 1000
                        </div>`
                    );
                } else {
                    setStatus(statusEl, 'error', '❌ ' + escapeHtml(data.error || ''));
                }
            } catch (e) {
                setStatus(statusEl, 'error', '❌ 请求失败：' + escapeHtml(e.message));
            } finally {
                this.disabled = false;
            }
        });
    }

    // ─── 使用历史封面 ───────────────────────────────────────────────────

    $$('.aicover-history__use').forEach(btn => {
        btn.addEventListener('click', async function () {
            if (!confirm('确认切换到此历史封面？')) return;

            const cid    = this.dataset.cid;
            const path   = this.dataset.path;
            const action = this.dataset.action;
            const nonce  = this.dataset.nonce || panelNonce;

            this.disabled = true;
            this.textContent = '切换中…';

            try {
                const data = await apiPost(action, { do: 'use', cid, path, nonce });

                if (data.success) {
                    const ts = '?t=' + Date.now();
                    const imgEl = document.getElementById('aicover-current-img');
                    if (imgEl) {
                        imgEl.src = data.coverUrl + ts;
                    } else if (coverBox) {
                        coverBox.innerHTML = `
                            <img src="${escapeHtml(data.coverUrl + ts)}" alt="封面" id="aicover-current-img">
                            <span class="aicover-preview-label">当前封面</span>`;
                    }
                    setStatus(statusEl, 'success', '✅ ' + escapeHtml(data.message));
                } else {
                    setStatus(statusEl, 'error', '❌ ' + escapeHtml(data.error || ''));
                }
            } catch (e) {
                setStatus(statusEl, 'error', '❌ 请求失败：' + escapeHtml(e.message));
            } finally {
                this.disabled = false;
                this.textContent = '使用此封面';
            }
        });
    });

    // ─── 动态添加历史记录 ────────────────────────────────────────────────

    function addHistoryItem({ coverUrl, coverPath, prompt, cid }, action) {
        const historyList = document.getElementById('aicover-history-list');
        if (!historyList) return;

        const now = new Date();
        const timeStr = (now.getMonth()+1).toString().padStart(2,'0') + '-' +
                        now.getDate().toString().padStart(2,'0') + ' ' +
                        now.getHours().toString().padStart(2,'0') + ':' +
                        now.getMinutes().toString().padStart(2,'0');

        const promptShort = prompt.length > 60 ? prompt.slice(0, 60) + '…' : prompt;

        const div = document.createElement('div');
        div.className = 'aicover-history__item';
        div.dataset.path = coverPath;
        div.innerHTML = `
            <img src="${escapeHtml(coverUrl + '?t=' + Date.now())}" alt="历史封面">
            <div class="aicover-history__meta">
                <div class="aicover-history__time">${timeStr}</div>
                <div class="aicover-history__prompt">${escapeHtml(promptShort)}</div>
                <button type="button" class="aicover-btn aicover-btn--xs aicover-history__use"
                    data-path="${escapeHtml(coverPath)}"
                    data-cid="${escapeHtml(cid)}"
                    data-nonce="${escapeHtml(panelNonce)}"
                    data-action="${escapeHtml(action)}">
                    使用此封面
                </button>
            </div>`;

        historyList.prepend(div);

        // 绑定使用按钮
        div.querySelector('.aicover-history__use').addEventListener('click', async function () {
            if (!confirm('确认切换到此历史封面？')) return;
            this.disabled = true;
            try {
                const data = await apiPost(this.dataset.action, {
                    do: 'use', cid: this.dataset.cid, path: this.dataset.path, nonce: (this.dataset.nonce || panelNonce)
                });
                if (data.success) {
                    const img = document.getElementById('aicover-current-img');
                    if (img) img.src = data.coverUrl + '?t=' + Date.now();
                    setStatus(statusEl, 'success', '✅ ' + escapeHtml(data.message));
                } else {
                    setStatus(statusEl, 'error', '❌ ' + escapeHtml(data.error || ''));
                }
            } catch(e) {
                setStatus(statusEl, 'error', '❌ 请求失败');
            } finally {
                this.disabled = false;
            }
        });
    }

    function ensureOgPreviewBox() {
        let ogImg = document.getElementById('aicover-og-img');
        if (ogImg) return ogImg;
        if (!previewRow) return null;

        const box = document.createElement('div');
        box.className = 'aicover-preview-box';
        box.innerHTML = `
            <img src="" alt="OG 图" id="aicover-og-img">
            <span class="aicover-preview-label">OG 分享图</span>
        `;
        previewRow.appendChild(box);
        ogImg = box.querySelector('#aicover-og-img');
        return ogImg;
    }

    // ─── 键盘快捷键 ─────────────────────────────────────────────────────
    // Ctrl+Shift+G = 快速生成封面
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'G') {
            e.preventDefault();
            const btn = document.getElementById('aicover-btn-cover');
            if (btn && !btn.disabled) btn.click();
        }
    });

})();
