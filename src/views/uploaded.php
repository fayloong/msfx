<?php
require_once __DIR__ . '/layout.php';
layout('上传成功', 'uploaded');
?>

<h4 class="mb-4">上传成功</h4>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-1">
                <label class="form-label small text-muted">单号</label>
                <input type="text" class="form-control" id="djbh" placeholder="单号筛选">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">往来单位</label>
                <input type="text" class="form-control" id="ent-name" placeholder="往来单位筛选">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">响应状态</label>
                <select class="form-select" id="response-status">
                    <option value="">全部</option>
                    <option value="上传成功">上传成功</option>
                    <option value="单据重复">单据重复</option>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label small text-muted">来源</label>
                <select class="form-select" id="filter-source">
                    <option value="">全部</option>
                    <option value="cron">定时采集</option>
                    <option value="manual">手动上传</option>
                    <option value="batch_check">批量核查</option>
                    <option value="batch_retry">批量重传</option>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label small text-muted">任务创建时间</label>
                <input type="text" class="form-control" id="created-range" placeholder="选择日期范围" readonly>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">单据日期</label>
                <input type="text" class="form-control" id="rq-range" placeholder="选择日期范围" readonly>
            </div>
            <div class="col-md-3 d-flex gap-2 align-items-end">
                <button class="btn btn-primary btn-sm" id="btn-refresh" title="刷新">
                    <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2v1z"/><path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466z"/></svg>
                    刷新
                </button>
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
                        <th>单据日期</th>
                        <th>单号</th>
                        <th>单据类型</th>
                        <th>往来单位</th>
                        <th>追溯码</th>
                        <th>关联任务ID</th>
                        <th width="80">来源</th>
                        <th>任务创建时间</th>
                        <th>最后更新时间</th>
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
    const today = new Date();
    const weekAgo = new Date(today);
    weekAgo.setDate(today.getDate() - 6);
    let createdTouched = false;   // 任务创建时间是否被用户手动改过（关键词检索时忽略默认 7 天范围）

    const fpCreated = flatpickr("#created-range", {
        mode: "range",
        dateFormat: "Y-m-d",
        locale: "zh",
        defaultDate: [weekAgo, today],
        onChange: () => { createdTouched = true; },
    });

    const fpRq = flatpickr("#rq-range", {
        mode: "range",
        dateFormat: "Y-m-d",
        locale: "zh",
    });

    const sourceLabels = {
        'cron': '定时采集',
        'manual': '手动上传',
        'batch_check': '批量核查',
        'batch_retry': '批量重传',
    };
    const sourceBadges = {
        'cron': 'bg-primary',
        'manual': 'bg-success',
        'batch_check': 'bg-info',
        'batch_retry': 'bg-warning text-dark',
    };
    const billTypeLabels = {
        '102': '采购入库', '103': '退货入库', '104': '调拨入库', '107': '供应入库', '108': '召回入库',
        '110': '赠品入库', '111': '盘盈入库', '112': '报废入库', '113': '其他入库',
        '201': '销售出库', '202': '退货出库', '203': '调拨出库', '204': '返工出库', '205': '销毁出库',
        '206': '抽检出库', '207': '直调出库', '209': '供应出库', '211': '召回出库', '212': '赠品出库',
        '214': '盘亏出库', '215': '损坏出库', '216': '报废出库', '217': '其他出库', '237': '直调退货',
    };

    function readRange(fp) {
        const d = fp.selectedDates;
        if (d.length === 2) {
            return [fp.formatDate(d[0], 'Y-m-d'), fp.formatDate(d[1], 'Y-m-d')];
        }
        return ['', ''];
    }

    function getPageNumbers(current, total, max) {
        if (total <= max) return Array.from({length: total}, (_, i) => i + 1);
        const half = Math.floor(max / 2);
        let start = Math.max(2, current - half);
        let end = Math.min(total - 1, current + half);
        if (current <= half + 1) { end = Math.min(total - 1, max - 1); }
        if (current >= total - half) { start = Math.max(2, total - max + 2); }
        const pages = [1];
        if (start > 2) pages.push('...');
        for (let i = start; i <= end; i++) pages.push(i);
        if (end < total - 1) pages.push('...');
        pages.push(total);
        return pages;
    }

    async function load() {
        const params = new URLSearchParams({page_num: page});
        const d = document.getElementById('djbh').value.trim();
        const en = document.getElementById('ent-name').value.trim();
        const rs = document.getElementById('response-status').value;
        const src = document.getElementById('filter-source').value;
        const [df, dt] = readRange(fpCreated);
        const [rf, rt] = readRange(fpRq);
        if (d) params.set('djbh', d);
        if (en) params.set('ent_name', en);
        if (rs) params.set('response_status', rs);
        if (src) params.set('source', src);
        // 关键词检索时忽略默认的 7 天日期范围（用户手动改过日期则正常组合）
        const ignoreDefaultRq = (d || en) && !createdTouched;
        if (df && !ignoreDefaultRq) params.set('date_from', df);
        if (dt && !ignoreDefaultRq) params.set('date_to', dt);
        if (rf) params.set('rq_from', rf);
        if (rt) params.set('rq_to', rt);

        try {
            const resp = await fetch('index.php?page=api&action=uploaded&' + params);
            const data = await resp.json();
            render(data);
        } catch (e) {
            document.getElementById('tbody').innerHTML = '<tr><td colspan="11" class="text-center py-5 text-danger">加载失败</td></tr>';
        }
    }

    function render(data) {
        const tbody = document.getElementById('tbody');
        if (!data.data || !data.data.length) {
            tbody.innerHTML = '<tr><td colspan="11" class="text-center py-5 text-muted">暂无数据</td></tr>';
        } else {
            tbody.innerHTML = data.data.map(r => `
                <tr>
                    <td class="text-nowrap">${esc(r.rq || '-')}</td>
                    <td><code>${esc(r.djbh)}</code></td>
                    <td>${billTypeLabels[r.bill_type] || '-'}</td>
                    <td class="text-truncate" style="max-width:220px" title="${esc(r.ent_name || '')}">${esc(r.ent_name) || '-'}</td>
                    <td>
                        ${r.trace_codes
                            ? `<button class="btn btn-sm btn-outline-secondary btn-trace" data-trace="${esc(r.trace_codes)}">查看追溯码</button>`
                            : '<span class="text-muted">-</span>'}
                    </td>
                    <td>${r.task_id || '-'}</td>
                    <td><span class="badge ${sourceBadges[r.source] || 'bg-secondary'}">${esc(sourceLabels[r.source] || r.source || '-')}</span></td>
                    <td class="text-nowrap">${esc(r.created_at)}</td>
                    <td class="text-nowrap">${esc(r.updated_at || '-')}</td>
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
            getPageNumbers(data.page, data.total_pages, 10).forEach(p => {
                if (p === '...') {
                    html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
                } else {
                    html += `<li class="page-item ${p===data.page?'active':''}"><a class="page-link" href="#" data-p="${p}">${p}</a></li>`;
                }
            });
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
    ['djbh','ent-name','response-status','filter-source'].forEach(id => {
        let t;
        document.getElementById(id).addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { page=1; load(); }, 400); });
        document.getElementById(id).addEventListener('change', () => { page=1; load(); });
    });
    fpCreated.config.onChange.push(() => { page=1; load(); });
    fpRq.config.onChange.push(() => { page=1; load(); });
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
