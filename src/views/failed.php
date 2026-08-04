<?php
require_once __DIR__ . '/layout.php';
layout('失败记录', 'failed');
?>

<h4 class="mb-4">失败记录</h4>

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
                    <option value="上传失败">上传失败</option>
                    <option value="信息不存在">信息不存在</option>
                    <option value="往来单位缺失">往来单位缺失</option>
                    <option value="未确定">未确定</option>
                    <option value="请求失败">请求失败</option>
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
                <button class="btn btn-danger btn-sm" id="btn-batch-delete" disabled>
                    <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/><path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3h11V2h-11v1z"/></svg>
                    批量删除
                </button>
                <button class="btn btn-warning btn-sm" id="btn-batch-retry" disabled>
                    <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M11.534 7h3.932a.25.25 0 0 1 .192.41l-1.966 2.36a.25.25 0 0 1-.384 0l-1.966-2.36a.25.25 0 0 1 .192-.41zm-11 2h3.932a.25.25 0 0 0 .192-.41l-1.966-2.36a.25.25 0 0 0-.384 0l-1.966 2.36A.25.25 0 0 0 .534 9z"/><path fill-rule="evenodd" d="M8 3c-1.552 0-2.94.707-3.857 1.818a.5.5 0 1 1-.771-.636A6.002 6.002 0 0 1 13.917 7H12.9A5.002 5.002 0 0 0 8 3zM3.1 9a5.002 5.002 0 0 0 8.757 2.182.5.5 0 1 1 .771.636A6.002 6.002 0 0 1 2.083 9H3.1z"/></svg>
                    批量重传
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
                        <th width="40"><input type="checkbox" class="form-check-input" id="select-all"></th>
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
                        <th width="160">操作</th>
                    </tr>
                </thead>
                <tbody id="tbody"></tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-transparent" id="pagination"></div>
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
					<label class="form-label">单据类型</label>
					<select class="form-select" id="edit-bill-type">
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
    const today = new Date();
    const weekAgo = new Date(today);
    weekAgo.setDate(today.getDate() - 6);

    const fpCreated = flatpickr("#created-range", {
        mode: "range",
        dateFormat: "Y-m-d",
        locale: "zh",
        defaultDate: [weekAgo, today],
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

    let selectedLogIds = new Set();   // upload_logs.id (for delete)
    let selectedTaskIds = new Set();  // upload_tasks.id (for retry — only non-zero)
    let confirmCb = null;

    async function load() {
        const params = new URLSearchParams({page_num: page});
        ['djbh','ent-name','response-status','filter-source'].forEach(id => {
            const v = document.getElementById(id).value.trim();
            if (v) {
                const paramName = id === 'ent-name' ? 'ent_name' : id === 'response-status' ? 'response_status' : id === 'filter-source' ? 'source' : id;
                params.set(paramName, v);
            }
        });
        const [df, dt] = readRange(fpCreated);
        const [rf, rt] = readRange(fpRq);
        if (df) params.set('date_from', df);
        if (dt) params.set('date_to', dt);
        if (rf) params.set('rq_from', rf);
        if (rt) params.set('rq_to', rt);
        try {
            const resp = await fetch('index.php?page=api&action=failed&' + params);
            const data = await resp.json();
            render(data);
        } catch(e) { document.getElementById('tbody').innerHTML = '<tr><td colspan="13" class="text-center py-5 text-danger">加载失败</td></tr>'; }
    }

    function render(data) {
        const tbody = document.getElementById('tbody');
        if (!data.data || !data.data.length) {
            tbody.innerHTML = '<tr><td colspan="13" class="text-center py-5 text-muted">暂无数据</td></tr>';
        } else {
            tbody.innerHTML = data.data.map(r => {
                const logId = r.id;
                const taskId = parseInt(r.task_id) || 0;
                const hasTask = taskId > 0;
                return `
                <tr>
                    <td><input type="checkbox" class="form-check-input row-cb"
                        data-log-id="${logId}" data-task-id="${taskId}"
                        ${selectedLogIds.has(logId) ? 'checked' : ''}></td>
                    <td class="text-nowrap">${esc(r.rq || '-')}</td>
                    <td><code>${esc(r.djbh)}</code></td>
                    <td>${billTypeLabels[r.bill_type] || '-'}</td>
                    <td class="text-truncate" style="max-width:220px" title="${esc(r.ent_name || '')}">${esc(r.ent_name) || '-'}</td>
                    <td>
                        ${r.trace_codes
                            ? `<button class="btn btn-sm btn-outline-secondary btn-trace" data-trace="${esc(r.trace_codes)}">查看追溯码</button>`
                            : '<span class="text-muted">-</span>'}
                    </td>
                    <td>${hasTask ? taskId : '-'}</td>
                    <td><span class="badge ${sourceBadges[r.source] || 'bg-secondary'}">${esc(sourceLabels[r.source] || r.source || '-')}</span></td>
                    <td class="text-nowrap">${esc(r.created_at)}</td>
                    <td class="text-nowrap">${esc(r.updated_at || '-')}</td>
                    <td><span class="badge ${r.request_status === '请求失败' ? 'bg-danger' : 'bg-warning text-dark'}">${esc(r.request_status === '请求失败' ? (r.request_status) : (r.response_status || '失败'))}</span></td>
                    <td><button class="btn btn-sm btn-outline-info btn-detail" data-r="${esc(r.response||'')}">查看详情</button></td>
                    <td class="text-nowrap">
                        <button class="btn btn-sm btn-outline-primary btn-edit" data-task-id="${taskId}" ${hasTask ? '' : 'disabled'}>编辑</button>
                        <button class="btn btn-sm btn-outline-danger btn-del" data-log-id="${logId}">删除</button>
                        <button class="btn btn-sm btn-outline-warning btn-retry" data-task-id="${taskId}" ${hasTask ? '' : 'disabled'}>重传</button>
                    </td>
                </tr>
            `}).join('');

            tbody.querySelectorAll('.btn-trace').forEach(b => b.addEventListener('click', function() {
                const codes = this.dataset.trace.split(',');
                document.getElementById('trace-count').textContent = '共 ' + codes.length + ' 个追溯码';
                document.getElementById('trace-content').textContent = this.dataset.trace;
                new bootstrap.Modal(document.getElementById('traceModal')).show();
            }));
            tbody.querySelectorAll('.btn-detail').forEach(b => b.addEventListener('click', function() {
                let t = this.dataset.r; try { t = JSON.stringify(JSON.parse(t), null, 2); } catch(e) {}
                document.getElementById('detail-content').textContent = t;
                new bootstrap.Modal(document.getElementById('detailModal')).show();
            }));
            tbody.querySelectorAll('.btn-edit').forEach(b => b.addEventListener('click', () => openEdit(parseInt(b.dataset.taskId))));
            tbody.querySelectorAll('.btn-del').forEach(b => b.addEventListener('click', () => deleteOne(parseInt(b.dataset.logId))));
            tbody.querySelectorAll('.btn-retry').forEach(b => b.addEventListener('click', () => retryOne(parseInt(b.dataset.taskId))));
            tbody.querySelectorAll('.row-cb').forEach(cb => cb.addEventListener('change', function() {
                const logId = parseInt(this.dataset.logId);
                const taskId = parseInt(this.dataset.taskId) || 0;
                if (this.checked) {
                    selectedLogIds.add(logId);
                    if (taskId > 0) selectedTaskIds.add(taskId);
                } else {
                    selectedLogIds.delete(logId);
                    selectedTaskIds.delete(taskId);
                }
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
            getPageNumbers(data.page, data.total_pages, 10).forEach(p => {
                if (p === '...') {
                    h += '<li class="page-item disabled"><span class="page-link">...</span></li>';
                } else {
                    h += `<li class="page-item ${p===data.page?'active':''}"><a class="page-link" href="#" data-p="${p}">${p}</a></li>`;
                }
            });
            h += `<li class="page-item ${data.page>=data.total_pages?'disabled':''}"><a class="page-link" href="#" data-p="${data.page+1}">&raquo;</a></li>`;
            h += '</ul></nav>';
            p.innerHTML = h;
            p.querySelectorAll('.page-link').forEach(a => a.addEventListener('click', e => { e.preventDefault(); if(!a.parentElement.classList.contains('disabled')) { page=parseInt(a.dataset.p); load(); } }));
        }
    }

    async function deleteOne(logId) {
        showConfirm('确定要删除该失败记录吗？', async () => {
            await fetch('index.php?page=api&action=logs_delete&id=' + logId);
            selectedLogIds.delete(logId);
            load();
        });
    }

    async function retryOne(taskId) {
        try {
            const resp = await fetch('index.php?page=api&action=tasks_retry', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({id: taskId}) });
            const r = await resp.json();
            alert(r.success ? '重传完成' : '重传失败: ' + (r.error||''));
            load();
        } catch(e) { alert('重传失败: '+e.message); }
    }

    async function openEdit(taskId) {
        try {
            const resp = await fetch('index.php?page=api&action=tasks&id=' + taskId);
            const task = await resp.json();
            if (task.error) { alert('未找到该任务'); return; }
            document.getElementById('edit-id').value = task.id;
            document.getElementById('edit-rq').value = task.rq;
            document.getElementById('edit-djbh').value = task.djbh;
            document.getElementById('edit-ent-name').value = task.ent_name;
            document.getElementById('edit-trace-codes').value = task.trace_codes || '';
            document.getElementById('edit-bill-type').value = task.bill_type || '';
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
            bill_type: document.getElementById('edit-bill-type').value,
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
                load();
            }
        } catch (e) {
            alert('保存失败: ' + e.message);
        }
    }

    function showConfirm(msg, cb) { document.getElementById('confirm-msg').textContent = msg; confirmCb = cb; new bootstrap.Modal(document.getElementById('confirmModal')).show(); }

    function updateBtns() {
        document.getElementById('btn-batch-delete').disabled = selectedLogIds.size === 0;
        document.getElementById('btn-batch-retry').disabled = selectedTaskIds.size === 0;
    }

    function esc(s) { return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    document.getElementById('btn-refresh').addEventListener('click', () => load());
    document.getElementById('select-all').addEventListener('change', function() {
        document.querySelectorAll('.row-cb').forEach(cb => {
            cb.checked = this.checked;
            const logId = parseInt(cb.dataset.logId);
            const taskId = parseInt(cb.dataset.taskId) || 0;
            if (this.checked) {
                selectedLogIds.add(logId);
                if (taskId > 0) selectedTaskIds.add(taskId);
            } else {
                selectedLogIds.delete(logId);
                selectedTaskIds.delete(taskId);
            }
        });
        updateBtns();
    });
    document.getElementById('btn-confirm').addEventListener('click', async () => { if (confirmCb) await confirmCb(); bootstrap.Modal.getInstance(document.getElementById('confirmModal')).hide(); });
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
    document.getElementById('btn-batch-delete').addEventListener('click', () => showConfirm('确定要删除选中的 ' + selectedLogIds.size + ' 条失败记录吗？', async () => {
        await fetch('index.php?page=api&action=logs_batch_delete', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ids: Array.from(selectedLogIds)}) });
        selectedLogIds.clear();
        selectedTaskIds.clear();
        load();
    }));
    document.getElementById('btn-batch-retry').addEventListener('click', () => showConfirm('确定要重传选中的 ' + selectedTaskIds.size + ' 条关联任务吗？', async () => {
        const ids = Array.from(selectedTaskIds);
        await fetch('index.php?page=api&action=tasks_batch_retry', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ids: ids}) });
        selectedLogIds.clear();
        selectedTaskIds.clear();
        load();
    }));

    ['djbh','ent-name','response-status','filter-source'].forEach(id => { let t; document.getElementById(id).addEventListener('input', () => { clearTimeout(t); t=setTimeout(()=>{page=1;load();},400); }); document.getElementById(id).addEventListener('change', ()=>{page=1;load();}); });
    fpCreated.config.onChange.push(() => { page=1; load(); });
    fpRq.config.onChange.push(() => { page=1; load(); });
    load();
})();
</script>

<?php layoutEnd(); ?>
