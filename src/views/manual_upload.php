<?php
require_once __DIR__ . '/layout.php';
layout('手动上传', 'manual-upload');
?>

<h4 class="mb-4">手动上传</h4>

<div class="row g-4">
    <!-- 在线新增 -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent fw-semibold">在线新增</div>
            <div class="card-body">
                <form id="manual-form">
                    <div class="mb-3">
                        <label class="form-label">日期 <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="m-rq" required value="<?= date('Y-m-d') ?>">
                        <div class="invalid-feedback">请输入有效日期</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">单据类型 <span class="text-danger">*</span></label>
                        <select class="form-select" id="m-bill-type" required>
                            <option value="">-- 请选择 --</option>
                            <optgroup label="入库">
                                <option value="102">102, 采购入库</option>
                                <option value="103">103, 退货入库</option>
                                <option value="104">104, 调拨入库</option>
                                <option value="107">107, 供应入库</option>
                                <option value="108">108, 召回入库</option>
                                <option value="110">110, 赠品入库</option>
                                <option value="111">111, 盘盈入库</option>
                                <option value="112">112, 报废入库</option>
                                <option value="113">113, 其他入库</option>
                            </optgroup>
                            <optgroup label="出库">
                                <option value="201">201, 销售出库</option>
                                <option value="202">202, 退货出库</option>
                                <option value="203">203, 调拨出库</option>
                                <option value="204">204, 返工出库</option>
                                <option value="205">205, 销毁出库</option>
                                <option value="206">206, 抽检出库</option>
                                <option value="207">207, 直调出库</option>
                                <option value="209">209, 供应出库</option>
                                <option value="211">211, 召回出库</option>
                                <option value="212">212, 赠品出库</option>
                                <option value="214">214, 盘亏出库</option>
                                <option value="215">215, 损坏出库</option>
                                <option value="216">216, 报废出库</option>
                                <option value="217">217, 其他出库</option>
                                <option value="237">237, 直调退货</option>
                            </optgroup>
                        </select>
                        <div class="invalid-feedback">请选择单据类型</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">单号 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="m-djbh" required placeholder="如: JHGWMS00060001">
                        <div class="invalid-feedback">单号不能为空</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">往来单位名称 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="m-ent-name" required placeholder="企业名称">
                        <div class="invalid-feedback">往来单位不能为空</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">追溯码 <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="m-trace-codes" rows="4" required
                            placeholder="追溯码1&#10;追溯码2&#10;追溯码3...（一行一个）"></textarea>
                        <div class="invalid-feedback">追溯码不能为空</div>
                        <div class="form-text">一行一个追溯码，单次最多 3500 个</div>
                    </div>
                    <button type="submit" class="btn btn-primary" id="btn-submit">
                        <span class="spinner-border spinner-border-sm d-none" id="submit-spinner"></span>
                        提交并上传
                    </button>
                </form>
                <div id="form-result" class="mt-3 d-none"></div>
            </div>
        </div>
    </div>

    <!-- xlsx 导入 -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
                <span>xlsx 批量导入</span>
                <a href="index.php?page=api&action=template_download" class="btn btn-sm btn-outline-secondary">下载模板</a>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">选择 xlsx 文件</label>
                    <input type="file" class="form-control" id="import-file" accept=".xlsx">
                    <div class="form-text">文件格式: 日期 | 单号 | 单据类型 | 往来单位名称 | 追溯码</div>
                </div>
                <button class="btn btn-success" id="btn-import">
                    <span class="spinner-border spinner-border-sm d-none" id="import-spinner"></span>
                    上传并导入
                </button>
                <div id="import-progress" class="mt-3 d-none">
                    <div class="progress" style="height:20px">
                        <div class="progress-bar" id="import-bar" style="width:0%">0%</div>
                    </div>
                </div>
                <div id="import-result" class="mt-3 d-none"></div>
            </div>
        </div>
    </div>
</div>

<!-- 实时上传日志弹窗 -->
<div class="modal fade" id="progressModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="progress-title">上传中...</h5>
                <button type="button" class="btn-close" id="progress-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-dark text-light" style="font-family: monospace; font-size: 0.85rem;">
                <div id="progress-log" style="max-height: 55vh; overflow-y: auto;"></div>
            </div>
            <div class="modal-footer">
                <span class="text-muted small me-auto" id="progress-summary"></span>
                <button type="button" class="btn btn-sm btn-outline-light" id="btn-copy-log">复制日志</button>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">关闭</button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    // 在线新增
    document.getElementById('manual-form').addEventListener('submit', async function(e) {
        e.preventDefault();
        const form = this;

        const rq = document.getElementById('m-rq').value.trim();
        const djbh = document.getElementById('m-djbh').value.trim();
        const entName = document.getElementById('m-ent-name').value.trim();
        const billType = document.getElementById('m-bill-type').value;
        let traceCodes = document.getElementById('m-trace-codes').value.trim();
        traceCodes = traceCodes.replace(/\r\n/g, '\n').replace(/\n+/g, ',').replace(/^,|,$/g, '');

        if (!rq || !djbh || !entName || !billType || !traceCodes) {
            showResult('form-result', 'danger', '请填写所有必填字段');
            return;
        }

        const btn = document.getElementById('btn-submit');
        const spinner = document.getElementById('submit-spinner');
        btn.disabled = true;
        spinner.classList.remove('d-none');

        // 打开实时日志弹窗
        const modal = new bootstrap.Modal(document.getElementById('progressModal'));
        const logEl = document.getElementById('progress-log');
        const titleEl = document.getElementById('progress-title');
        const summaryEl = document.getElementById('progress-summary');
        titleEl.textContent = '手动上传 — ' + djbh;
        summaryEl.textContent = '';
        logEl.innerHTML = '';
        modal.show();

        try {
            await streamFetch('index.php?page=api&action=manual_create', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({rq, djbh, ent_name: entName, bill_type: billType, trace_codes: traceCodes}),
            }, logEl, summaryEl, titleEl);
            form.reset();
            document.getElementById('m-rq').value = '<?= date('Y-m-d') ?>';
        } catch (err) {
            appendLog(logEl, 'error', '请求失败: ' + err.message);
        } finally {
            btn.disabled = false;
            spinner.classList.add('d-none');
        }
    });

    // xlsx 导入
    document.getElementById('btn-import').addEventListener('click', async function() {
        const fileInput = document.getElementById('import-file');
        const file = fileInput.files[0];
        if (!file) {
            showResult('import-result', 'danger', '请先选择 xlsx 文件');
            return;
        }

        const btn = this;
        const spinner = document.getElementById('import-spinner');

        btn.disabled = true;
        spinner.classList.remove('d-none');

        // 打开实时日志弹窗
        const modal = new bootstrap.Modal(document.getElementById('progressModal'));
        const logEl = document.getElementById('progress-log');
        const titleEl = document.getElementById('progress-title');
        const summaryEl = document.getElementById('progress-summary');
        titleEl.textContent = 'xlsx 批量导入 — ' + file.name;
        summaryEl.textContent = '';
        logEl.innerHTML = '';
        modal.show();

        const formData = new FormData();
        formData.append('file', file);

        try {
            await streamFetch('index.php?page=api&action=manual_import', {
                method: 'POST',
                body: formData,
            }, logEl, summaryEl, titleEl);
        } catch (err) {
            appendLog(logEl, 'error', '请求失败: ' + err.message);
        } finally {
            btn.disabled = false;
            spinner.classList.add('d-none');
            fileInput.value = '';
        }
    });

    function showResult(id, type, msg) {
        const el = document.getElementById(id);
        if (!type) { el.classList.add('d-none'); return; }
        el.className = 'mt-3 alert alert-' + type;
        el.innerHTML = msg;
        el.classList.remove('d-none');
    }

    // ---- 流式上传日志 ----

    async function streamFetch(url, options, logEl, summaryEl, titleEl) {
        const resp = await fetch(url, options);
        if (!resp.ok) {
            const text = await resp.text();
            appendLog(logEl, 'error', 'HTTP ' + resp.status + ': ' + text);
            return;
        }

        const reader = resp.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let successCount = 0;
        let failedCount = 0;

        while (true) {
            const {done, value} = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, {stream: true});
            const lines = buffer.split('\n');
            buffer = lines.pop();

            for (const line of lines) {
                if (!line.trim()) continue;
                try {
                    const data = JSON.parse(line);
                    if (data._final) {
                        if (data.success) {
                            successCount = data.result ? data.result.success : data.success_count;
                            failedCount = data.result ? data.result.failed : data.error_count;
                            titleEl.textContent = '上传完成';
                            summaryEl.textContent = '成功 ' + successCount + ' / 失败 ' + failedCount;
                            if (data.errors && data.errors.length) {
                                for (const err of data.errors) {
                                    appendLog(logEl, 'warn', err);
                                }
                            }
                        } else if (data.error) {
                            appendLog(logEl, 'error', '上传失败: ' + data.error);
                            titleEl.textContent = '上传失败';
                        }
                    } else if (data._error) {
                        appendLog(logEl, 'warn', data._error);
                    } else {
                        if (data.success) {
                            successCount++;
                            appendLog(logEl, 'success', formatProgress(data));
                        } else {
                            failedCount++;
                            appendLog(logEl, 'fail', formatProgress(data));
                        }
                        summaryEl.textContent = '成功 ' + successCount + ' / 失败 ' + failedCount;
                    }
                } catch (e) {
                    // 非 JSON 行，忽略
                }
            }
        }
    }

    function appendLog(logEl, type, msg) {
        const colors = {
            success: '#4ade80',
            fail: '#f87171',
            error: '#f87171',
            warn: '#fbbf24',
        };
        const div = document.createElement('div');
        div.style.cssText = 'padding:4px 0;border-bottom:1px solid #374151;color:' + (colors[type] || '#e2e8f0');
        div.innerHTML = msg;
        logEl.appendChild(div);
        logEl.scrollTop = logEl.scrollHeight;
    }

    function formatProgress(data) {
        const statusBadge = data.success
            ? '<span style="color:#4ade80">[成功]</span>'
            : '<span style="color:#f87171">[失败]</span>';
        let respSummary = '';
        if (data.response) {
            try {
                const resp = typeof data.response === 'string' ? JSON.parse(data.response) : data.response;
                if (resp && resp.result && resp.result.model) {
                    respSummary = ' | 返回: ' + esc(String(resp.result.model).substring(0, 100));
                } else if (resp && resp.result && resp.result.msg_info) {
                    respSummary = ' | 返回: ' + esc(resp.result.msg_info);
                } else if (resp && resp.msg) {
                    respSummary = ' | 返回: ' + esc(resp.msg);
                } else if (resp && resp.error) {
                    respSummary = ' | 返回: ' + esc(resp.error);
                }
            } catch (e) {}
        }
        const respStatus = data.response_status ? ' <span style="color:#94a3b8">[' + esc(data.response_status) + ']</span>' : '';
        return statusBadge + ' <span style="color:#e2e8f0">' + esc(data.djbh) + '</span>'
            + ' <span style="color:#94a3b8">' + esc(data.ent_name) + '</span>'
            + respStatus + respSummary;
    }

    function esc(s) { return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    document.getElementById('btn-copy-log').addEventListener('click', () => {
        const text = document.getElementById('progress-log').innerText;
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'absolute';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
    });
})();
</script>

<?php layoutEnd(); ?>
