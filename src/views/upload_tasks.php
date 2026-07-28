<?php
require_once __DIR__ . '/layout.php';
layout('上传任务', 'upload-tasks');
?>

<h4 class="mb-4">上传任务</h4>

<!-- 搜索和操作栏 -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small text-muted">全局搜索</label>
                <input type="text" class="form-control" id="global-search" placeholder="单号 / 往来单位 / 追溯码...">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">状态筛选</label>
                <select class="form-select" id="filter-status">
                    <option value="">全部</option>
                    <option value="等待上传">等待上传</option>
                    <option value="上传中">上传中</option>
                    <option value="已上传">已上传</option>
                    <option value="任务失败">任务失败</option>
                    <option value="部分上传成功">部分上传成功</option>
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
            <div class="col-md-2 d-flex gap-2 align-items-end">
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
                        <th width="110">任务状态</th>
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

<script>
(function() {
    let currentPage = 1;
    let selectedIds = new Set();
    let confirmCallback = null;

    const statusBadges = {
        '等待上传': 'bg-secondary',
        '上传中': 'bg-primary',
        '已上传': 'bg-success',
        '任务失败': 'bg-danger',
        '部分上传成功': 'bg-warning text-dark',
    };

    function getFilters() {
        const params = new URLSearchParams();
        const search = document.getElementById('global-search').value.trim();
        const status = document.getElementById('filter-status').value;
        const dateFrom = document.getElementById('filter-date-from').value;
        const dateTo = document.getElementById('filter-date-to').value;
        if (search) params.set('search', search);
        if (status) params.set('status', status);
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
                <td class="text-truncate" style="max-width:200px" title="${esc(r.trace_codes || '')}">${esc((r.trace_codes || '').substring(0, 40))}${(r.trace_codes || '').length > 40 ? '...' : ''}</td>
                <td><span class="badge ${statusBadges[r.status] || 'bg-secondary'}">${esc(r.status)}</span></td>
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
            const resp = await fetch('index.php?page=api&action=tasks&page_num=1');
            const data = await resp.json();
            const task = (data.data || []).find(t => t.id == id);
            if (!task) { alert('未找到该任务'); return; }
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
        try {
            const resp = await fetch('index.php?page=api&action=tasks_retry', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id}),
            });
            const result = await resp.json();
            if (result.success) {
                alert('重传完成: 成功 ' + result.result.success + ', 失败 ' + result.result.failed);
                loadData();
            } else {
                alert('重传失败: ' + (result.error || '未知错误'));
            }
        } catch (e) {
            alert('重传失败: ' + e.message);
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
            try {
                const resp = await fetch('index.php?page=api&action=tasks_batch_retry', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ids: Array.from(selectedIds)}),
                });
                const result = await resp.json();
                if (result.success) {
                    alert('批量重传完成: 成功 ' + result.result.success + ', 失败 ' + result.result.failed);
                    selectedIds.clear();
                    document.getElementById('select-all').checked = false;
                    loadData();
                }
            } catch (e) {
                alert('批量重传失败: ' + e.message);
            }
        });
    });

    // 筛选实时搜索（防抖）
    let searchTimeout;
    ['global-search', 'filter-status', 'filter-date-from', 'filter-date-to'].forEach(id => {
        document.getElementById(id).addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => { currentPage = 1; loadData(); }, 400);
        });
        document.getElementById(id).addEventListener('change', () => { currentPage = 1; loadData(); });
    });

    // 初始加载
    loadData();
})();
</script>

<?php layoutEnd(); ?>
