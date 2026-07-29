<?php
require_once __DIR__ . '/layout.php';
layout('上传任务', 'upload-tasks');
?>

<h4 class="mb-4">上传任务</h4>

<!-- 搜索和操作栏 -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small text-muted">单号</label>
                <input type="text" class="form-control" id="filter-djbh" placeholder="单号筛选">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">往来单位</label>
                <input type="text" class="form-control" id="filter-ent-name" placeholder="往来单位筛选">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">任务状态</label>
                <select class="form-select" id="filter-task-status">
                    <option value="等待上传" selected>等待上传</option>
                    <option value="已处理">已处理</option>
                    <option value="">全部</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">响应状态</label>
                <select class="form-select" id="filter-response-status">
                    <option value="">全部</option>
                    <option value="上传成功">上传成功</option>
                    <option value="单据重复">单据重复</option>
                    <option value="上传失败">上传失败</option>
                    <option value="信息不存在">信息不存在</option>
                    <option value="往来单位缺失">往来单位缺失</option>
                    <option value="未确定">未确定</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">日期从</label>
                <input type="date" class="form-control" id="filter-date-from">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">日期到</label>
                <input type="date" class="form-control" id="filter-date-to">
            </div>
        </div>
        <div class="row g-2 mt-2">
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-outline-secondary" id="btn-refresh" title="刷新">
                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2v1z"/><path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466z"/></svg>
                </button>
                <button class="btn btn-danger btn-sm" id="btn-batch-delete" disabled>批量删除</button>
                <button class="btn btn-warning btn-sm" id="btn-batch-retry" disabled>批量重传</button>
            </div>
        </div>
    </div>
</div>

<!-- 表格 -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="tasks-table">
                <thead class="table-light">
                    <tr>
                        <th width="40"><input type="checkbox" class="form-check-input" id="select-all"></th>
                        <th width="100">日期</th>
                        <th>单号</th>
                        <th>往来单位</th>
                        <th>追溯码</th>
                        <th width="150">状态</th>
                        <th width="120">操作</th>
                    </tr>
                </thead>
                <tbody id="tasks-tbody">
                    <tr><td colspan="7" class="text-center py-5 text-muted">加载中...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-transparent" id="pagination-container"></div>
</div>

<!-- 编辑弹窗 -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">编辑上传任务</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="edit-id">
                <div class="mb-3">
                    <label class="form-label">日期</label>
                    <input type="date" class="form-control" id="edit-rq">
                </div>
                <div class="mb-3">
                    <label class="form-label">单号</label>
                    <input type="text" class="form-control" id="edit-djbh">
                </div>
                <div class="mb-3">
                    <label class="form-label">往来单位</label>
                    <input type="text" class="form-control" id="edit-ent-name">
                </div>
                <div class="mb-3">
                    <label class="form-label">追溯码</label>
                    <textarea class="form-control" id="edit-trace-codes" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="btn-save-edit">保存</button>
            </div>
        </div>
    </div>
</div>

<!-- 确认删除弹窗 -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">确认操作</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="confirm-message">确定要删除吗？</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-danger" id="btn-confirm">确认</button>
            </div>
        </div>
    </div>
</div>

<!-- 追溯码弹窗 -->
<div class="modal fade" id="traceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">追溯码</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small" id="trace-count"></p>
                <div class="bg-light p-3 rounded" style="max-height:400px;overflow:auto;word-break:break-all;font-size:0.85rem" id="trace-content"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary btn-copy-trace">复制</button>
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
    let currentPage = 1;
    let selectedIds = new Set();
    let confirmCallback = null;

    const taskStatusBadges = {
        '等待上传': 'bg-secondary',
        '已处理': 'bg-primary',
    };
    const responseStatusBadges = {
        '上传成功': 'bg-success',
        '单据重复': 'bg-warning text-dark',
        '上传失败': 'bg-danger',
        '信息不存在': 'bg-info',
        '往来单位缺失': 'bg-dark',
        '未确定': 'bg-secondary',
    };

    function getFilters() {
        const params = new URLSearchParams();
        const djbh = document.getElementById('filter-djbh').value.trim();
        const entName = document.getElementById('filter-ent-name').value.trim();
        const taskStatus = document.getElementById('filter-task-status').value;
        const responseStatus = document.getElementById('filter-response-status').value;
        const dateFrom = document.getElementById('filter-date-from').value;
        const dateTo = document.getElementById('filter-date-to').value;
        if (djbh) params.set('djbh', djbh);
        if (entName) params.set('ent_name', entName);
        if (taskStatus) params.set('task_status', taskStatus);
        if (responseStatus) params.set('response_status', responseStatus);
        if (dateFrom) params.set('date_from', dateFrom);
        if (dateTo) params.set('date_to', dateTo);
        params.set('page_num', currentPage);
        return params;
    }

    async function loadData() {
        const params = getFilters();
        try {
            const resp = await fetch('index.php?page=api&action=tasks&' + params.toString());
            const data = await resp.json();
            renderTable(data.data || []);
            renderPagination(data);
        } catch (e) {
            document.getElementById('tasks-tbody').innerHTML =
                '<tr><td colspan="7" class="text-center py-5 text-danger">加载失败: ' + e.message + '</td></tr>';
        }
    }

    function renderTable(rows) {
        const tbody = document.getElementById('tasks-tbody');
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-5 text-muted">暂无数据</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(r => `
            <tr>
                <td><input type="checkbox" class="form-check-input row-checkbox" data-id="${r.id}" ${selectedIds.has(r.id) ? 'checked' : ''}></td>
                <td class="text-nowrap">${esc(r.rq)}</td>
                <td><code>${esc(r.djbh)}</code></td>
                <td>${esc(r.ent_name)}</td>
                <td>
                    ${r.trace_codes
                        ? `<button class="btn btn-sm btn-outline-secondary btn-trace" data-trace="${esc(r.trace_codes)}">查看追溯码</button>`
                        : '<span class="text-muted">-</span>'}
                </td>
                <td>
                    <span class="badge ${taskStatusBadges[r.task_status] || 'bg-secondary'}">${esc(r.task_status || '-')}</span>
                    ${r.response_status ? `<span class="badge ${responseStatusBadges[r.response_status] || 'bg-secondary'} ms-1">${esc(r.response_status)}</span>` : ''}
                </td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-primary btn-edit" data-id="${r.id}">编辑</button>
                    <button class="btn btn-sm btn-outline-danger btn-delete" data-id="${r.id}">删除</button>
                    <button class="btn btn-sm btn-outline-warning btn-retry" data-id="${r.id}">重传</button>
                </td>
            </tr>
        `).join('');

        // 绑定事件
        tbody.querySelectorAll('.btn-edit').forEach(btn => btn.addEventListener('click', () => openEdit(btn.dataset.id)));
        tbody.querySelectorAll('.btn-delete').forEach(btn => btn.addEventListener('click', () => deleteSingle(btn.dataset.id)));
        tbody.querySelectorAll('.btn-retry').forEach(btn => btn.addEventListener('click', () => retrySingle(btn.dataset.id)));
        tbody.querySelectorAll('.btn-trace').forEach(btn => {
            btn.addEventListener('click', () => {
                const codes = btn.dataset.trace.split(',');
                document.getElementById('trace-count').textContent = '共 ' + codes.length + ' 个追溯码';
                document.getElementById('trace-content').textContent = btn.dataset.trace;
                new bootstrap.Modal(document.getElementById('traceModal')).show();
            });
        });
        tbody.querySelectorAll('.row-checkbox').forEach(cb => {
            cb.addEventListener('change', function() {
                if (this.checked) selectedIds.add(parseInt(this.dataset.id));
                else selectedIds.delete(parseInt(this.dataset.id));
                updateBatchButtons();
            });
        });
        updateBatchButtons();
    }

    function renderPagination(data) {
        const container = document.getElementById('pagination-container');
        if (!data.total_pages || data.total_pages <= 1) {
            container.innerHTML = '<div class="text-center text-muted small py-2">共 ' + data.total + ' 条</div>';
            return;
        }
        let html = '<nav><ul class="pagination pagination-sm justify-content-center mb-0">';
        html += `<li class="page-item ${data.page <= 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${data.page - 1}">&laquo;</a></li>`;
        for (let i = 1; i <= data.total_pages; i++) {
            html += `<li class="page-item ${i === data.page ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
        }
        html += `<li class="page-item ${data.page >= data.total_pages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${data.page + 1}">&raquo;</a></li>`;
        html += '</ul><div class="text-center text-muted small mt-1">共 ' + data.total + ' 条，第 ' + data.page + '/' + data.total_pages + ' 页</div></nav>';
        container.innerHTML = html;

        container.querySelectorAll('.page-link').forEach(a => {
            a.addEventListener('click', function(e) {
                e.preventDefault();
                if (this.parentElement.classList.contains('disabled')) return;
                currentPage = parseInt(this.dataset.page);
                loadData();
            });
        });
    }

    async function openEdit(id) {
        try {
            const resp = await fetch('index.php?page=api&action=tasks&id=' + id);
            const task = await resp.json();
            if (task.error) { alert('未找到该任务'); return; }
            document.getElementById('edit-id').value = task.id;
            document.getElementById('edit-rq').value = task.rq;
            document.getElementById('edit-djbh').value = task.djbh;
            document.getElementById('edit-ent-name').value = task.ent_name;
            document.getElementById('edit-trace-codes').value = task.trace_codes || '';
            new bootstrap.Modal(document.getElementById('editModal')).show();
        } catch (e) {
            alert('加载失败: ' + e.message);
        }
    }

    async function saveEdit() {
        const body = JSON.stringify({
            id: document.getElementById('edit-id').value,
            rq: document.getElementById('edit-rq').value,
            djbh: document.getElementById('edit-djbh').value,
            ent_name: document.getElementById('edit-ent-name').value,
            trace_codes: document.getElementById('edit-trace-codes').value,
        });
        try {
            const resp = await fetch('index.php?page=api&action=tasks', {
                method: 'PUT',
                headers: {'Content-Type': 'application/json'},
                body: body,
            });
            const result = await resp.json();
            if (result.success) {
                bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
                loadData();
            }
        } catch (e) {
            alert('保存失败: ' + e.message);
        }
    }

    function deleteSingle(id) {
        showConfirm('确定要删除该上传任务吗？', async () => {
            try {
                await fetch('index.php?page=api&action=tasks&id=' + id, { method: 'DELETE' });
                loadData();
            } catch (e) {
                alert('删除失败: ' + e.message);
            }
        });
    }

    async function retrySingle(id) {
        const modal = new bootstrap.Modal(document.getElementById('progressModal'));
        const logEl = document.getElementById('progress-log');
        const titleEl = document.getElementById('progress-title');
        const summaryEl = document.getElementById('progress-summary');
        titleEl.textContent = '重传 — 单号 #' + id;
        summaryEl.textContent = '';
        logEl.innerHTML = '';
        modal.show();

        try {
            await streamFetch('index.php?page=api&action=tasks_retry', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id}),
            }, logEl, summaryEl, titleEl);
            loadData();
        } catch (e) {
            appendLog(logEl, 'error', '请求失败: ' + e.message);
        }
    }

    function showConfirm(message, callback) {
        document.getElementById('confirm-message').textContent = message;
        confirmCallback = callback;
        new bootstrap.Modal(document.getElementById('confirmModal')).show();
    }

    function updateBatchButtons() {
        const hasSelection = selectedIds.size > 0;
        document.getElementById('btn-batch-delete').disabled = !hasSelection;
        document.getElementById('btn-batch-retry').disabled = !hasSelection;
    }

    function esc(s) { return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    // 事件绑定
    document.getElementById('btn-refresh').addEventListener('click', () => loadData());
    document.getElementById('select-all').addEventListener('change', function() {
        document.querySelectorAll('.row-checkbox').forEach(cb => {
            cb.checked = this.checked;
            if (this.checked) selectedIds.add(parseInt(cb.dataset.id));
            else selectedIds.delete(parseInt(cb.dataset.id));
        });
        updateBatchButtons();
    });
    document.getElementById('btn-save-edit').addEventListener('click', saveEdit);
    document.querySelector('.btn-copy-trace').addEventListener('click', () => {
        const text = document.getElementById('trace-content').textContent.replace(/,/g, '\n');
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'absolute';
        ta.style.left = '-9999px';
        ta.style.top = '0';
        document.querySelector('#traceModal .modal-body').appendChild(ta);
        ta.focus();
        ta.select();
        document.execCommand('copy');
        ta.remove();
        alert('已复制到剪贴板');
    });
    document.getElementById('btn-confirm').addEventListener('click', async () => {
        if (confirmCallback) await confirmCallback();
        bootstrap.Modal.getInstance(document.getElementById('confirmModal')).hide();
    });

    document.getElementById('btn-batch-delete').addEventListener('click', () => {
        showConfirm('确定要删除选中的 ' + selectedIds.size + ' 条任务吗？', async () => {
            try {
                await fetch('index.php?page=api&action=tasks_batch_delete', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ids: Array.from(selectedIds)}),
                });
                selectedIds.clear();
                document.getElementById('select-all').checked = false;
                loadData();
            } catch (e) {
                alert('批量删除失败: ' + e.message);
            }
        });
    });

    document.getElementById('btn-batch-retry').addEventListener('click', () => {
        showConfirm('确定要重传选中的 ' + selectedIds.size + ' 条任务吗？', async () => {
            const modal = new bootstrap.Modal(document.getElementById('progressModal'));
            const logEl = document.getElementById('progress-log');
            const titleEl = document.getElementById('progress-title');
            const summaryEl = document.getElementById('progress-summary');
            titleEl.textContent = '批量重传 — ' + selectedIds.size + ' 条任务';
            summaryEl.textContent = '';
            logEl.innerHTML = '';
            modal.show();

            try {
                await streamFetch('index.php?page=api&action=tasks_batch_retry', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ids: Array.from(selectedIds)}),
                }, logEl, summaryEl, titleEl);
                selectedIds.clear();
                document.getElementById('select-all').checked = false;
                loadData();
            } catch (e) {
                appendLog(logEl, 'error', '请求失败: ' + e.message);
            }
        });
    });

    // 筛选实时搜索（防抖）
    let searchTimeout;
    ['filter-djbh', 'filter-ent-name', 'filter-task-status', 'filter-response-status', 'filter-date-from', 'filter-date-to'].forEach(id => {
        document.getElementById(id).addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => { currentPage = 1; loadData(); }, 400);
        });
        document.getElementById(id).addEventListener('change', () => { currentPage = 1; loadData(); });
    });

    // 初始加载
    loadData();

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
                        // 最终结果
                        if (data.success) {
                            successCount = data.result.success;
                            failedCount = data.result.failed;
                            titleEl.textContent = '上传完成';
                            summaryEl.textContent = '成功 ' + successCount + ' / 失败 ' + failedCount;
                        } else if (data.error) {
                            appendLog(logEl, 'error', '上传失败: ' + data.error);
                            titleEl.textContent = '上传失败';
                        }
                    } else if (data._error) {
                        appendLog(logEl, 'warn', data._error);
                    } else {
                        // 进度条目
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
