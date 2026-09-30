<?php
require_once __DIR__ . '/layout.php';

// 顶部"所属企业"下拉与零售分支要用的企业/凭据清单：**只含企业名、类型、凭据位键与 label**，
// 不含任何密钥（密钥留在服务端）。页面据此切换分支、渲染凭据下拉、把"待配凭据"写成禁用原因；
// 真正的校验在 src/api/tasks_batch_retry_retail.php 与 App\RetailRetransmit——页面是显示层，不是可信边界
$companies = [];      // [{name, type}] 顶部下拉
$retailStores = [];   // 门店名 => [{key, label, configured}]
$defaultCompany = ''; // 默认选中批发主体：页面进来就是现在这套批发表单
$configError = '';
$wholesaleWarning = '';
try {
    foreach (App\Enterprise::all() as $company) {
        $companies[] = ['name' => $company['name'], 'type' => $company['type']];
        if ($company['type'] !== App\Enterprise::TYPE_RETAIL) {
            continue;
        }
        $credentials = [];
        foreach ($company['credentials'] as $key => $credential) {
            $credentials[] = [
                'key' => (string)$key,
                'label' => (string)($credential['label'] ?? ''),
                'configured' => App\Enterprise::credentialConfigured($credential),
            ];
        }
        $retailStores[$company['name']] = $credentials;
    }
} catch (\Throwable $e) {
    // 企业配置坏了不该让整页打不开：零售分支降级为不可用并写明原因；批发表单照常渲染
    // （批发上传届时会由服务端的守卫拒绝，那是服务端的事，页面先能用）
    $configError = $e->getMessage();
    $companies = [];
    $retailStores = [];
}

// 默认选中的批发主体走 Enterprise::wholesaleSubject() 这个唯一入口，**不是"取第一家批发企业"**：
// 后者在配置里出现两家批发企业时会让页面显示的主体与 manual_create 实际落库的主体不一致。
// 它不是恰好一家时抛异常（那正是"把单据申报到错误主体"的经典路径）——页面不替它猜，只把话说清楚。
try {
    $defaultCompany = App\Enterprise::wholesaleSubject()['name'];
} catch (\Throwable $e) {
    $wholesaleWarning = $e->getMessage();
}

layout('手动上传', 'manual-upload');
?>

<h4 class="mb-4">手动上传</h4>

<!-- 所属企业：选定后显示该企业对应的表单/清单（批发与零售的字段、接口、凭据完全不同） -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-6 col-lg-4">
                <label class="form-label fw-semibold">所属企业 <span class="text-danger">*</span></label>
                <select class="form-select" id="company-select">
                    <?php if (empty($companies)): ?>
                        <option value="" data-type="wholesale" selected>批发（企业配置载入失败）</option>
                    <?php else: ?>
                        <?php foreach ($companies as $c): ?>
                            <option value="<?= htmlspecialchars($c['name']) ?>"
                                    data-type="<?= htmlspecialchars($c['type']) ?>"
                                    <?= $c['name'] === $defaultCompany ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>（<?= $c['type'] === App\Enterprise::TYPE_RETAIL ? '门店' : '批发' ?>）
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <div class="form-text" id="company-hint">批发：手工在线新增或 xlsx 导入；门店：从已采集的待补传单据里勾选批量补传。</div>
            </div>
        </div>
    </div>
</div>

<?php if ($configError !== ''): ?>
<div class="alert alert-danger">
    <strong>企业配置载入失败，零售分支不可用：</strong><?= htmlspecialchars($configError) ?>
</div>
<?php elseif ($wholesaleWarning !== ''): ?>
<div class="alert alert-warning">
    <strong>批发主体无法唯一确定，请先修配置再用批发表单：</strong><?= htmlspecialchars($wholesaleWarning) ?>
    （下拉里的批发企业是配置顺序的第一个，未必是上传实际会用的主体——服务端会拒绝无主体的上传）
</div>
<?php endif; ?>

<!-- ── 批发分支：与改造前完全一致（在线新增 + xlsx 导入/模板下载） ──
     不带 d-none：默认选中批发主体，页面不用等 JS 就该显示它（脚本只在切到门店时才需要动 DOM） -->
<div id="branch-wholesale">
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
</div>

<!-- ── 零售分支：不提供从零手工录入，只列该门店已采集的待补传单据 ── -->
<div id="branch-retail" class="d-none">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
            <span>待补传单据</span>
            <span class="small text-muted" id="retail-count-hint"></span>
        </div>
        <div class="card-body">
            <div class="row g-2 align-items-end mb-3">
                <div class="col-md-6 col-lg-4">
                    <label class="form-label fw-semibold">凭据 <span class="text-danger">*</span></label>
                    <select class="form-select" id="retail-credential"></select>
                    <div class="form-text" id="retail-credential-hint"></div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <button class="btn btn-warning" id="btn-retail-batch" disabled>
                        <span class="spinner-border spinner-border-sm d-none" id="retail-batch-spinner"></span>
                        批量补传（已选 <span id="retail-selected-count">0</span> 条）
                    </button>
                </div>
            </div>

            <div class="alert alert-warning py-2 small mb-3">
                补传是<strong>向码上放心平台的真实申报，不可逆</strong>。请确认所选单据尚未被外部系统上传——
                若外部系统已用另一套 AppKey 传过同一张单，补传会在平台上造成重复申报。
                单据元数据（日期 / 类型 / 追溯码 / fromUserId / toUserId / physicType）全部取自采集时落库的记录，
                不接受手工填写。
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th style="width:36px"><input type="checkbox" class="form-check-input" id="retail-check-all"></th>
                        <th>单号</th>
                        <th>单据日期</th>
                        <th>单据类型</th>
                        <th>追溯码</th>
                        <th class="text-end">码数</th>
                    </tr>
                    </thead>
                    <tbody id="retail-tbody"></tbody>
                </table>
            </div>
            <div id="retail-empty" class="text-center text-muted py-4 d-none"></div>
        </div>
        <!-- 分页条：渲染逻辑照着上传任务页那份搬（两个页面各自内联脚本，没有共享 JS 文件） -->
        <div class="card-footer bg-transparent" id="retail-pagination"></div>
    </div>
</div>

<!-- 追溯码弹窗（门店分支的待补传清单用；与上传任务页那份同一套交互：查看 + 复制） -->
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

<!-- 实时上传日志弹窗（两个分支共用） -->
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
    const retailStores = <?= json_encode($retailStores, JSON_UNESCAPED_UNICODE) ?>;
    const retailConfigError = <?= json_encode($configError, JSON_UNESCAPED_UNICODE) ?>;

    const billTypeLabels = {
        '102': '采购入库', '103': '退货入库', '104': '调拨入库', '107': '供应入库', '108': '召回入库',
        '110': '赠品入库', '111': '盘盈入库', '112': '报废入库', '113': '其他入库',
        '201': '销售出库', '202': '退货出库', '203': '调拨出库', '204': '返工出库', '205': '销毁出库',
        '206': '抽检出库', '207': '直调出库', '209': '供应出库', '211': '召回出库', '212': '赠品出库',
        '214': '盘亏出库', '215': '损坏出库', '216': '报废出库', '217': '其他出库', '237': '直调退货',
        '321': '使用出库', '116': '消费者退货入库',
    };

    // ── 所属企业切换 ──

    const companySelect = document.getElementById('company-select');
    const branchWholesale = document.getElementById('branch-wholesale');
    const branchRetail = document.getElementById('branch-retail');

    function currentCompany() { return companySelect.value; }
    function currentType() {
        const opt = companySelect.options[companySelect.selectedIndex];
        return opt ? (opt.dataset.type || 'wholesale') : 'wholesale';
    }

    function onCompanyChange() {
        const isRetail = currentType() === 'retail';
        branchWholesale.classList.toggle('d-none', isRetail);
        branchRetail.classList.toggle('d-none', !isRetail);
        if (isRetail) {
            // 换门店＝换一份清单：上一家的页码与勾选对新门店没有意义，先清干净再拉
            resetRetailListState();
            loadRetailTasks(currentCompany());
        }
    }

    companySelect.addEventListener('change', onCompanyChange);

    // ── 零售分支：待补传清单（分页）+ 批量补传 ──

    let retailRowIndex = new Map();   // id => 该行，**跨页累积**：补传二次确认要按 id 回查单据类型，而被选中的行不一定在当页
    let retailSelectedIds = new Set();// 勾选集**跨页保留**——翻一页就丢勾选的话，批量补传根本没法用
    let currentRetailPage = 1;

    function resetRetailListState() {
        retailRowIndex = new Map();
        retailSelectedIds = new Set();
        currentRetailPage = 1;
    }

    async function loadRetailTasks(company) {
        const tbody = document.getElementById('retail-tbody');
        const emptyEl = document.getElementById('retail-empty');
        const countHint = document.getElementById('retail-count-hint');
        const credSelect = document.getElementById('retail-credential');
        const credHint = document.getElementById('retail-credential-hint');
        const batchBtn = document.getElementById('btn-retail-batch');

        tbody.innerHTML = '';
        emptyEl.classList.add('d-none');
        countHint.textContent = '加载中...';
        document.getElementById('retail-check-all').checked = false;
        document.getElementById('retail-pagination').innerHTML = '';
        updateSelection();

        // 凭据下拉：只列已配齐的（待配凭据的门店清单照常可见，但不能补传并写明原因）。
        // 重建 options 前先记下当前选择——**翻页也会走这个函数**，无条件重建会把用户选的凭据
        // 静默退回第一项；门店有多套凭据时，那等于"翻一页换一套申报主体"（当前配置每家只有
        // 一个凭据位，所以看不出来，但那是配置的巧合，不是这段代码的性质）
        const prevCredential = credSelect.value;
        const all = retailStores[company] || [];
        const usable = all.filter(c => c.configured);
        const multi = all.length > 1;
        if (retailConfigError || !all.length) {
            credSelect.innerHTML = '<option value="">（无可用凭据）</option>';
            credSelect.disabled = true;
        } else if (!usable.length) {
            credSelect.innerHTML = '<option value="">（待配凭据）</option>';
            credSelect.disabled = true;
        } else {
            credSelect.disabled = false;
            credSelect.innerHTML = usable.map(c =>
                `<option value="${esc(c.key)}">${esc(multi ? company + '（' + c.label + '）' : company)}</option>`
            ).join('');
        }
        credSelect.dataset.usable = usable.length ? '1' : '';
        // 旧选择在新门店/新列表里仍可用就留着（换门店时同名凭据位保留也不违和：选的是"哪套授权"）
        if (prevCredential && usable.some(c => c.key === prevCredential)) {
            credSelect.value = prevCredential;
        }

        if (retailConfigError) {
            credHint.textContent = '企业配置载入失败，零售补传不可用。';
        } else if (!all.length) {
            credHint.textContent = '该门店不在企业配置中，无法补传。';
        } else if (!usable.length) {
            credHint.textContent = '待配凭据：该门店的 AppKey/SECRETKEY 尚未到手（预期内的正常状态，不是异常），补齐前不能补传。清单照常可见。';
        } else {
            credHint.textContent = '补传用的授权由此处显式选择（本轮不做多套凭据的自动分发规则）。';
        }

        try {
            const resp = await fetch('index.php?page=api&action=manual_retail_tasks&company=' + encodeURIComponent(company)
                + '&page_num=' + currentRetailPage);
            const data = await resp.json();
            if (!resp.ok) throw new Error(data.error || ('HTTP ' + resp.status));

            // 当前页越界（多半是本页刚被补传传空）：退到最后一页重拉，别撂一片空白——
            // 补传成功后行从队列消失是本页最常见的状态变化，不是罕见边界
            if (data.total > 0 && data.page > data.total_pages) {
                currentRetailPage = Math.max(1, data.total_pages);
                return loadRetailTasks(company);
            }
            currentRetailPage = data.page;

            const retailRows = data.data || [];   // 当前页数据：只在本函数里用，不必是模块级状态
            retailRows.forEach(r => retailRowIndex.set(r.id, r));
            const total = data.total || 0;
            countHint.textContent = total ? ('共 ' + total + ' 条待补传') : '';

            if (!retailRows.length) {
                emptyEl.textContent = '该门店暂无待补传单据。';
                emptyEl.classList.remove('d-none');
            } else {
                tbody.innerHTML = retailRows.map(r => `
                    <tr>
                        <td><input type="checkbox" class="form-check-input retail-row-check" value="${r.id}" ${retailSelectedIds.has(r.id) ? 'checked' : ''}></td>
                        <td>${esc(r.djbh)}</td>
                        <td class="text-nowrap">${esc(r.rq || '-')}</td>
                        <td>${esc(billTypeLabels[r.bill_type] || r.bill_type || '-')}</td>
                        <td>
                            ${r.trace_codes
                                ? `<button class="btn btn-sm btn-outline-secondary btn-retail-trace" data-trace="${esc(r.trace_codes)}">查看追溯码</button>`
                                : '<span class="text-muted">-</span>'}
                        </td>
                        <td class="text-end">${r.code_count}</td>
                    </tr>
                `).join('');
                tbody.querySelectorAll('.retail-row-check').forEach(cb => cb.addEventListener('change', function() {
                    const id = parseInt(this.value);
                    if (this.checked) retailSelectedIds.add(id);
                    else retailSelectedIds.delete(id);
                    updateSelection();
                }));
                tbody.querySelectorAll('.btn-retail-trace').forEach(btn => {
                    btn.addEventListener('click', () => showTrace(btn.dataset.trace));
                });
            }

            renderRetailPagination(data);
        } catch (e) {
            countHint.textContent = '';
            emptyEl.textContent = '清单加载失败：' + e.message;
            emptyEl.classList.remove('d-none');
        }

        updateSelection();
    }

    // 计数取自勾选集而非 DOM——DOM 里只有当前页的行，翻页后仍在勾选集里的行数会少算
    function updateSelection() {
        document.getElementById('retail-selected-count').textContent = retailSelectedIds.size;
        const credSelect = document.getElementById('retail-credential');
        document.getElementById('btn-retail-batch').disabled = !retailSelectedIds.size || !credSelect.dataset.usable;
    }

    // 追溯码弹窗：与上传任务页的"查看追溯码"同一套（全量列出 + 一键复制）。
    // 计数用裸 split，不加 filter——参照实现就是这么数的，且这样与列表"码数"列
    // （SQL 数逗号 +1）口径一致，同一行的两处数字不会打架
    function showTrace(traceCodes) {
        document.getElementById('trace-count').textContent =
            '共 ' + traceCodes.split(',').length + ' 个追溯码';
        document.getElementById('trace-content').textContent = traceCodes;
        new bootstrap.Modal(document.getElementById('traceModal')).show();
    }

    // 分页条：整段照搬上传任务页（那里也是 IIFE 内的私有函数，两个页面没有共享 JS 文件）
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

    function renderRetailPagination(data) {
        const container = document.getElementById('retail-pagination');
        if (!data.total_pages || data.total_pages <= 1) {
            container.innerHTML = '<div class="text-center text-muted small py-2">共 ' + data.total + ' 条</div>';
            return;
        }
        let html = '<nav><ul class="pagination pagination-sm justify-content-center mb-0">';
        html += `<li class="page-item ${data.page <= 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${data.page - 1}">&laquo;</a></li>`;
        getPageNumbers(data.page, data.total_pages, 10).forEach(p => {
            if (p === '...') {
                html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
            } else {
                html += `<li class="page-item ${p === data.page ? 'active' : ''}"><a class="page-link" href="#" data-page="${p}">${p}</a></li>`;
            }
        });
        html += `<li class="page-item ${data.page >= data.total_pages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${data.page + 1}">&raquo;</a></li>`;
        html += '</ul><div class="text-center text-muted small mt-1">共 ' + data.total + ' 条，第 ' + data.page + '/' + data.total_pages + ' 页</div></nav>';
        container.innerHTML = html;

        container.querySelectorAll('.page-link').forEach(a => {
            a.addEventListener('click', function(e) {
                e.preventDefault();
                if (this.parentElement.classList.contains('disabled')) return;
                currentRetailPage = parseInt(this.dataset.page);
                loadRetailTasks(currentCompany());
            });
        });
    }

    // 全选只作用于**当前页**（跨页的选择由用户逐行勾）——与上传任务页的取舍一致
    document.getElementById('retail-check-all').addEventListener('change', function() {
        document.querySelectorAll('.retail-row-check').forEach(cb => {
            cb.checked = this.checked;
            const id = parseInt(cb.value);
            if (this.checked) retailSelectedIds.add(id);
            else retailSelectedIds.delete(id);
        });
        updateSelection();
    });

    document.getElementById('btn-retail-batch').addEventListener('click', async function() {
        const ids = Array.from(retailSelectedIds);
        const company = currentCompany();
        const credential = document.getElementById('retail-credential').value;
        if (!ids.length) { alert('请先勾选要补传的单据'); return; }
        if (!credential) { alert('该门店没有可用凭据，无法补传'); return; }

        // 二次确认：补传是对平台的真实申报，不可逆
        let confirmMsg = '确认对门店「' + company + '」的 ' + ids.length + ' 条单据发起补传？\n\n'
            + '补传是向码上放心平台的真实申报，不可逆。';
        // 104/203（调拨）的 fromUserId/toUserId 方向仍待外部系统工程师确认（ADR 0010）；
        // 单条入口同样暴露该风险，但批量会一次放大成一批，故在确认框里点名。
        // 单据类型从跨页累积的索引里查：被选中的行不一定在当页的数据里
        const ambiguous = ids.filter(id => ['104', '203'].includes((retailRowIndex.get(id) || {}).bill_type));
        if (ambiguous.length) {
            confirmMsg += '\n\n⚠️ 本批含 ' + ambiguous.length + ' 张 104/203（调拨）单据：'
                + '其 fromUserId/toUserId 照搬源表同名列，但发货/收货语义仍待外部系统工程师确认（ADR 0010）——'
                + '方向若反，平台上会留下错误申报。请确认后再传。';
        }
        if (!confirm(confirmMsg)) return;

        const btn = this;
        const spinner = document.getElementById('retail-batch-spinner');
        btn.disabled = true;
        spinner.classList.remove('d-none');

        const modal = new bootstrap.Modal(document.getElementById('progressModal'));
        const logEl = document.getElementById('progress-log');
        const titleEl = document.getElementById('progress-title');
        const summaryEl = document.getElementById('progress-summary');
        titleEl.textContent = '零售批量补传 — ' + company;
        summaryEl.textContent = '';
        logEl.innerHTML = '';
        modal.show();

        try {
            await streamFetch('index.php?page=api&action=tasks_batch_retry_retail', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ids, company, credential}),
            }, logEl, summaryEl, titleEl, {unit: '单据', doneTitle: '补传完成'});
        } catch (err) {
            appendLog(logEl, 'error', '请求失败: ' + err.message);
        } finally {
            spinner.classList.add('d-none');
            // 传完刷新清单：已处理的单据不再出现在"待补传"里（失败的也不在了——它的出口是失败记录页）。
            // 这批单据都已处置过，勾选集清空；页码保持不动，本页被传空时 loadRetailTasks 会自己回退到最后一页
            retailSelectedIds.clear();
            loadRetailTasks(company);
        }
    });

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

    async function streamFetch(url, options, logEl, summaryEl, titleEl, opts) {
        opts = opts || {};
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
                            titleEl.textContent = opts.doneTitle || '上传完成';
                            summaryEl.textContent = (opts.unit ? opts.unit + '：' : '') + '成功 ' + successCount + ' / 失败 ' + failedCount;
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
        // 零售的进度行没有往来单位（门店单据的对手方在源表里），回落到所属企业名
        const who = data.ent_name || data.company || '';
        const respStatus = data.response_status ? ' <span style="color:#94a3b8">[' + esc(data.response_status) + ']</span>' : '';
        return statusBadge + ' <span style="color:#e2e8f0">' + esc(data.djbh) + '</span>'
            + ' <span style="color:#94a3b8">' + esc(who) + '</span>'
            + respStatus + respSummary;
    }

    function esc(s) { return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    // 追溯码弹窗的复制：逗号换成换行（粘到 Excel/记事本就是一行一个码）——与上传任务页同一套
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

    // 初始分支：默认选中批发（`$defaultCompany` 未命中时也走批发，表单照常可用）
    onCompanyChange();
})();
</script>

<?php layoutEnd(); ?>
