<?php
require_once __DIR__ . '/layout.php';
layout('已上传', 'uploaded');
?>

<h4 class="mb-4">已上传记录</h4>

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
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-outline-secondary" id="btn-refresh">刷新</button>
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
                        <th>时间</th>
                        <th>单号</th>
                        <th>往来单位</th>
                        <th>追溯码</th>
                        <th>关联任务ID</th>
                        <th>状态</th>
                        <th>API 返回详情</th>
                    </tr>
                </thead>
                <tbody id="tbody"></tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-transparent" id="pagination"></div>
</div>

<script>
(function() {
    let page = 1;

    async function load() {
        const params = new URLSearchParams({page_num: page});
        const s = document.getElementById('search').value.trim();
        const df = document.getElementById('date-from').value;
        const dt = document.getElementById('date-to').value;
        const d = document.getElementById('djbh').value.trim();
        if (s) params.set('search', s);
        if (df) params.set('date_from', df);
        if (dt) params.set('date_to', dt);
        if (d) params.set('djbh', d);

        try {
            const resp = await fetch('index.php?page=api&action=uploaded&' + params);
            const data = await resp.json();
            render(data);
        } catch (e) {
            document.getElementById('tbody').innerHTML = '<tr><td colspan="7" class="text-center py-5 text-danger">加载失败</td></tr>';
        }
    }

    function render(data) {
        const tbody = document.getElementById('tbody');
        if (!data.data || !data.data.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-5 text-muted">暂无数据</td></tr>';
        } else {
            tbody.innerHTML = data.data.map(r => `
                <tr>
                    <td class="text-nowrap">${esc(r.created_at)}</td>
                    <td><code>${esc(r.djbh)}</code></td>
                    <td>${esc(r.ent_name) || '-'}</td>
                    <td>
                        ${r.trace_codes
                            ? `<button class="btn btn-sm btn-outline-secondary btn-trace" data-trace="${esc(r.trace_codes)}">查看追溯码</button>`
                            : '<span class="text-muted">-</span>'}
                    </td>
                    <td>${r.task_id || '-'}</td>
                    <td><span class="badge bg-success">${esc(r.response_status || '成功')}</span></td>
                    <td>
                        <button class="btn btn-sm btn-outline-info btn-detail" data-response="${esc(r.response || '')}">查看详情</button>
                    </td>
                </tr>
            `).join('');

            tbody.querySelectorAll('.btn-trace').forEach(btn => {
                btn.addEventListener('click', () => {
                    const codes = btn.dataset.trace.split(',');
                    document.getElementById('trace-count').textContent = '共 ' + codes.length + ' 个追溯码';
                    document.getElementById('trace-content').textContent = btn.dataset.trace;
                    new bootstrap.Modal(document.getElementById('traceModal')).show();
                });
            });
            tbody.querySelectorAll('.btn-detail').forEach(btn => {
                btn.addEventListener('click', () => {
                    let respText = btn.dataset.response;
                    try { respText = JSON.stringify(JSON.parse(respText), null, 2); } catch(e) {}
                    const modal = new bootstrap.Modal(document.getElementById('detailModal'));
                    document.getElementById('detail-content').textContent = respText;
                    modal.show();
                });
            });
        }

        const pager = document.getElementById('pagination');
        if (!data.total_pages || data.total_pages <= 1) {
            pager.innerHTML = '<div class="text-center text-muted small py-2">共 ' + data.total + ' 条</div>';
        } else {
            let html = '<nav><ul class="pagination pagination-sm justify-content-center mb-0">';
            html += `<li class="page-item ${data.page<=1?'disabled':''}"><a class="page-link" href="#" data-p="${data.page-1}">&laquo;</a></li>`;
            for (let i=1; i<=data.total_pages; i++) {
                html += `<li class="page-item ${i===data.page?'active':''}"><a class="page-link" href="#" data-p="${i}">${i}</a></li>`;
            }
            html += `<li class="page-item ${data.page>=data.total_pages?'disabled':''}"><a class="page-link" href="#" data-p="${data.page+1}">&raquo;</a></li>`;
            html += '</ul></nav>';
            pager.innerHTML = html;
            pager.querySelectorAll('.page-link').forEach(a => a.addEventListener('click', e => { e.preventDefault(); if(!a.parentElement.classList.contains('disabled')) { page=parseInt(a.dataset.p); load(); } }));
        }
    }

    function esc(s) { return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.btn-copy-trace')) return;
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
    document.getElementById('btn-refresh').addEventListener('click', () => { page=1; load(); });
    ['search','date-from','date-to','djbh'].forEach(id => {
        let t;
        document.getElementById(id).addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { page=1; load(); }, 400); });
        document.getElementById(id).addEventListener('change', () => { page=1; load(); });
    });
    load();
})();
</script>

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

<?php layoutEnd(); ?>
