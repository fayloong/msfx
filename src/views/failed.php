<?php
require_once __DIR__ . '/layout.php';
layout('失败记录', 'failed');
?>

<h4 class="mb-4">失败记录</h4>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small text-muted">搜索</label>
                <input type="text" class="form-control" id="search" placeholder="单号 / 返回内容...">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">日期从</label>
                <input type="date" class="form-control" id="date-from">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">日期到</label>
                <input type="date" class="form-control" id="date-to">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">单号</label>
                <input type="text" class="form-control" id="djbh" placeholder="单号筛选">
            </div>
            <div class="col-md-2 d-flex gap-2 align-items-end">
                <button class="btn btn-outline-secondary" id="btn-refresh">刷新</button>
                <button class="btn btn-danger btn-sm" id="btn-batch-delete" disabled>批量删除</button>
                <button class="btn btn-warning btn-sm" id="btn-batch-retry" disabled>批量重传</button>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th width="40"><input type="checkbox" class="form-check-input" id="select-all"></th>
                        <th>时间</th>
                        <th>单号</th>
                        <th>关联任务ID</th>
                        <th>状态</th>
                        <th>API 返回详情</th>
                        <th width="140">操作</th>
                    </tr>
                </thead>
                <tbody id="tbody"></tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-transparent" id="pagination"></div>
</div>

<!-- 详情弹窗 -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">API 返回详情</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre class="bg-light p-3 rounded" style="max-height:500px;overflow:auto" id="detail-content"></pre>
            </div>
        </div>
    </div>
</div>

<!-- 确认弹窗 -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">确认操作</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" id="confirm-msg"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-danger" id="btn-confirm">确认</button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    let page = 1;
    let selected = new Set();
    let confirmCb = null;

    async function load() {
        const params = new URLSearchParams({page_num: page});
        ['search','date-from','date-to','djbh'].forEach(id => {
            const v = document.getElementById(id).value.trim();
            if (v) params.set(id === 'date-from' ? 'date_from' : id === 'date-to' ? 'date_to' : id, v);
        });
        try {
            const resp = await fetch('index.php?page=api&action=failed&' + params);
            const data = await resp.json();
            render(data);
        } catch(e) { document.getElementById('tbody').innerHTML = '<tr><td colspan="7" class="text-center py-5 text-danger">加载失败</td></tr>'; }
    }

    function render(data) {
        const tbody = document.getElementById('tbody');
        if (!data.data || !data.data.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-5 text-muted">暂无数据</td></tr>';
        } else {
            tbody.innerHTML = data.data.map(r => `
                <tr>
                    <td><input type="checkbox" class="form-check-input row-cb" data-id="${r.id}" ${selected.has(r.id)?'checked':''}></td>
                    <td class="text-nowrap">${esc(r.created_at)}</td>
                    <td><code>${esc(r.djbh)}</code></td>
                    <td>${r.task_id || '-'}</td>
                    <td><span class="badge bg-danger">失败</span></td>
                    <td><button class="btn btn-sm btn-outline-info btn-detail" data-r="${esc(r.response||'')}">查看详情</button></td>
                    <td class="text-nowrap">
                        <button class="btn btn-sm btn-outline-danger btn-del" data-id="${r.id}">删除</button>
                        <button class="btn btn-sm btn-outline-warning btn-retry" data-id="${r.id}" data-djbh="${esc(r.djbh)}">重传</button>
                    </td>
                </tr>
            `).join('');

            tbody.querySelectorAll('.btn-detail').forEach(b => b.addEventListener('click', function() {
                let t = this.dataset.r; try { t = JSON.stringify(JSON.parse(t), null, 2); } catch(e) {}
                document.getElementById('detail-content').textContent = t;
                new bootstrap.Modal(document.getElementById('detailModal')).show();
            }));
            tbody.querySelectorAll('.btn-del').forEach(b => b.addEventListener('click', () => deleteOne(b.dataset.id)));
            tbody.querySelectorAll('.btn-retry').forEach(b => b.addEventListener('click', () => retryOne(b.dataset.id, b.dataset.djbh)));
            tbody.querySelectorAll('.row-cb').forEach(cb => cb.addEventListener('change', function() {
                this.checked ? selected.add(parseInt(this.dataset.id)) : selected.delete(parseInt(this.dataset.id));
                updateBtns();
            }));
        }
        updateBtns();
        document.getElementById('select-all').checked = false;

        const p = document.getElementById('pagination');
        if (!data.total_pages || data.total_pages <= 1) {
            p.innerHTML = '<div class="text-center text-muted small py-2">共 ' + data.total + ' 条</div>';
        } else {
            let h = '<nav><ul class="pagination pagination-sm justify-content-center mb-0">';
            h += `<li class="page-item ${data.page<=1?'disabled':''}"><a class="page-link" href="#" data-p="${data.page-1}">&laquo;</a></li>`;
            for (let i=1; i<=data.total_pages; i++) h += `<li class="page-item ${i===data.page?'active':''}"><a class="page-link" href="#" data-p="${i}">${i}</a></li>`;
            h += `<li class="page-item ${data.page>=data.total_pages?'disabled':''}"><a class="page-link" href="#" data-p="${data.page+1}">&raquo;</a></li>`;
            h += '</ul></nav>';
            p.innerHTML = h;
            p.querySelectorAll('.page-link').forEach(a => a.addEventListener('click', e => { e.preventDefault(); if(!a.parentElement.classList.contains('disabled')) { page=parseInt(a.dataset.p); load(); } }));
        }
    }

    function deleteOne(id) { showConfirm('确定要删除该失败记录吗？', async () => { await fetch('index.php?page=api&action=tasks&id='+id, {method:'DELETE'}); load(); }); }

    async function retryOne(id, djbh) {
        try {
            const resp = await fetch('index.php?page=api&action=tasks_retry', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({id:id}) });
            const r = await resp.json();
            alert(r.success ? '重传完成' : '重传失败: ' + (r.error||''));
            load();
        } catch(e) { alert('重传失败: '+e.message); }
    }

    function showConfirm(msg, cb) { document.getElementById('confirm-msg').textContent = msg; confirmCb = cb; new bootstrap.Modal(document.getElementById('confirmModal')).show(); }

    function updateBtns() {
        document.getElementById('btn-batch-delete').disabled = selected.size === 0;
        document.getElementById('btn-batch-retry').disabled = selected.size === 0;
    }

    function esc(s) { return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    document.getElementById('btn-refresh').addEventListener('click', () => load());
    document.getElementById('select-all').addEventListener('change', function() {
        document.querySelectorAll('.row-cb').forEach(cb => { cb.checked = this.checked; this.checked ? selected.add(parseInt(cb.dataset.id)) : selected.delete(parseInt(cb.dataset.id)); });
        updateBtns();
    });
    document.getElementById('btn-confirm').addEventListener('click', async () => { if (confirmCb) await confirmCb(); bootstrap.Modal.getInstance(document.getElementById('confirmModal')).hide(); });
    document.getElementById('btn-batch-delete').addEventListener('click', () => showConfirm('确定要删除选中的 '+selected.size+' 条记录吗？', async () => {
        await fetch('index.php?page=api&action=tasks_batch_delete', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ids:Array.from(selected)}) });
        selected.clear(); load();
    }));
    document.getElementById('btn-batch-retry').addEventListener('click', () => showConfirm('确定要重传选中的 '+selected.size+' 条记录吗？', async () => {
        await fetch('index.php?page=api&action=tasks_batch_retry', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ids:Array.from(selected)}) });
        selected.clear(); load();
    }));

    ['search','date-from','date-to','djbh'].forEach(id => { let t; document.getElementById(id).addEventListener('input', () => { clearTimeout(t); t=setTimeout(()=>{page=1;load();},400); }); document.getElementById(id).addEventListener('change', ()=>{page=1;load();}); });
    load();
})();
</script>

<?php layoutEnd(); ?>
