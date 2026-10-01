<?php
require_once __DIR__ . '/layout.php';

// 顶部"所属企业"下拉与门店分支要用的企业清单：**只含企业名、类型与"该店凭据是否配齐"**，
// 不含任何密钥（密钥留在服务端）。页面据此切换分支、把"待配凭据"写成按钮的禁用原因；
// 用哪套凭据不由页面选（门店与凭据 1:1，见 docs/adr/0012），真正的校验在
// src/api/manual_create_retail.php、src/api/manual_import_retail.php 与 App\RetailManualEntry
// ——页面是显示层，不是可信边界
$companies = [];      // [{name, type}] 顶部下拉
$retailStores = [];   // 门店名 => 'ready'|'pending'|'no_slot'（门店名缺席 = 该店不在配置里）
$defaultCompany = ''; // 默认选中批发主体：页面进来就是现在这套批发表单
$configError = '';
$wholesaleWarning = '';
try {
    foreach (App\Enterprise::all() as $company) {
        $companies[] = ['name' => $company['name'], 'type' => $company['type']];
    }
    $retailStores = App\Enterprise::retailCredentialReady();
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

<!-- 所属企业：选定后显示该企业对应的表单（批发与门店的字段、接口、凭据完全不同） -->
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
                <div class="form-text" id="company-hint">批发与门店都是手工在线新增或 xlsx 导入，单据都落到所选主体名下；
                    门店的已采集单据（补传）在上传任务页按所属企业筛。</div>
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
                <div id="import-result" class="mt-3 d-none"></div>
            </div>
        </div>
    </div>
</div>
</div>

<!-- ── 门店分支：与批发**同构**（在线新增 + xlsx 导入），差别只有两处：
     单据类型只有门店那四种、"往来单位名称"按类型显隐（321/116 的对手是消费者，接口里也没有对手方入参）。
     门店单据的**补传清单不在这一页**：那份清单与上传任务页重复，补传统一在上传任务页按门店筛
     （2026-10-01 用户定；见 .scratch/retail-chain/issues/14-retail-manual-entry.md） -->
<div id="branch-retail" class="d-none">
    <!-- 这家门店能不能建单的说明：正常门店为空，待配凭据/未声明凭据位/不在配置/配置载入失败时写明原因 -->
    <div class="form-text mb-3" id="retail-store-hint"></div>
    <div class="row g-4">
        <!-- 在线新增 -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent fw-semibold">在线新增</div>
                <div class="card-body">
                    <form id="retail-manual-form">
                        <div class="mb-3">
                            <label class="form-label">日期 <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="rm-rq" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">单据类型 <span class="text-danger">*</span></label>
                            <select class="form-select" id="rm-bill-type" required>
                                <option value="">-- 请选择 --</option>
                                <optgroup label="调拨">
                                    <option value="104">104, 调拨入库</option>
                                    <option value="203">203, 调拨出库</option>
                                </optgroup>
                                <optgroup label="门店（消费者级）">
                                    <option value="321">321, 使用出库</option>
                                    <option value="116">116, 消费者退货入库</option>
                                </optgroup>
                            </select>
                            <div class="form-text">门店单据只认这四种（采集口径与接口路由都是这四种）。
                                104/203 走调拨接口、要填往来单位；321/116 走零售接口，对手是消费者，不需要。</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">单号 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="rm-djbh" required placeholder="如: 321WMS00060001">
                        </div>
                        <!-- 只在调拨两类显示（JS 按单据类型切换）：对手方的 entId 由这个名字查出来，
                             服务端拿它填 fromUserId/toUserId——人手上没有"直接填平台 ID"的入口 -->
                        <div class="mb-3" id="rm-ent-name-group">
                            <label class="form-label">往来单位名称 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="rm-ent-name" placeholder="调拨对方（总部或门店）在平台上的名称">
                            <div class="form-text">按这个名字在平台上查出对方的 entId：调拨入库记发货方、调拨出库记收货方，本店记另一端。
                                查不到会直接拒绝建单，不会带着空 ID 上平台。</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">追溯码 <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="rm-trace-codes" rows="4" required
                                placeholder="追溯码1&#10;追溯码2&#10;追溯码3...（一行一个）"></textarea>
                            <div class="form-text">一行一个追溯码。超过接口上限会自动拆单（104/203 上限 10000、321/116 上限 3500）。</div>
                        </div>
                        <button type="submit" class="btn btn-primary" id="rm-submit">
                            <span class="spinner-border spinner-border-sm d-none" id="rm-spinner"></span>
                            提交并上传
                        </button>
                    </form>
                    <div id="rm-result" class="mt-3 d-none"></div>
                </div>
            </div>
        </div>

        <!-- xlsx 导入 -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
                    <span>xlsx 批量导入</span>
                    <a href="index.php?page=api&action=template_download&type=retail" class="btn btn-sm btn-outline-secondary">下载模板</a>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">选择 xlsx 文件</label>
                        <input type="file" class="form-control" id="rm-import-file" accept=".xlsx">
                        <div class="form-text">文件格式: 日期 | 单号 | 单据类型 | 往来单位名称 | 追溯码
                            （321/116 的往来单位留空）。同单号多行会自动合并为一条。</div>
                    </div>
                    <button class="btn btn-success" id="rm-btn-import">
                        <span class="spinner-border spinner-border-sm d-none" id="rm-import-spinner"></span>
                        上传并导入
                    </button>
                    <div id="rm-import-result" class="mt-3 d-none"></div>
                </div>
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
    // JSON_FORCE_OBJECT：这是"门店名 => 凭据状态"的映射（'ready'|'pending'|'no_slot'，门店名缺席 = 不在配置里），
    // 配置为空时也要出 {} 而不是 []（后者会被当成数组）
    const retailStores = <?= json_encode($retailStores, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT) ?>;
    const retailConfigError = <?= json_encode($configError, JSON_UNESCAPED_UNICODE) ?>;

    // ── 门店分支：能不能建单 ──
    //
    // 三态口径不退化（ADR 0012）：三种"不能建单"要不同的人做不同的事（等授权 / 补配置 / 查配置），
    // 混成一句"不可用"会把人引向错误的处置。返回 null = 可以建单。
    // 按钮的禁用说明与提交前的兜底拦截读的是**同一份说法**，免得两处各写一句。
    function retailBlockReason(company) {
        if (retailConfigError) {
            return '企业配置载入失败，门店手工新增不可用（服务端同样会拒）。';
        }
        if (!(company in retailStores)) {
            return '该门店不在企业配置中，无法为它建单。';
        }
        if (retailStores[company] === 'no_slot') {
            return '未声明凭据位：企业配置里这家门店没有凭据位（config/enterprises.php 缺这一项），'
                + '补上才能建单——这是配置缺口，不是"等密钥到手"那种正常状态。';
        }
        if (retailStores[company] !== 'ready') {
            return '待配凭据：该门店的 AppKey/SECRETKEY 尚未到手（预期内的正常状态，不是异常），补齐前不能建单。';
        }
        return null;   // 正常态不提示：能建单就没什么要说的，多一句话只是噪声
    }

    // ── 门店分支："往来单位名称"按单据类型显隐 ──
    //
    // 判据与后端 `RetailManualEntry::needsCounterparty()` 同一套：调拨两类（104/203）走
    // lsyd.uploadinoutbill，其 fromUserId/toUserId 是平台必填、由这个名字去平台查出；
    // 321/116 走 lsyd.uploadretail，接口里根本没有对手方入参（对手是消费者）。
    // 隐藏时**同时清空值并摘掉 required**——只藏起来的话，浏览器仍会拦"必填项为空"，
    // 用户会看到一个看不见的输入框在报错。
    // 需要"往来单位名称"的类型——**从后端注入**，不在这里手抄一份：判据在
    // App\RetailManualEntry::TYPES_WITH_COUNTERPARTY（`needsCounterparty()` 读它），
    // 前端另写一份的话，改后端而漏改这里就是"框藏了、服务端却要"（或反过来）
    const RETAIL_TYPES_WITH_PARTNER = <?= json_encode(App\RetailManualEntry::TYPES_WITH_COUNTERPARTY, JSON_UNESCAPED_UNICODE) ?>;
    const retailBillTypeSelect = document.getElementById('rm-bill-type');
    const retailEntNameGroup = document.getElementById('rm-ent-name-group');
    const retailEntNameInput = document.getElementById('rm-ent-name');

    function syncRetailEntNameField() {
        const needs = RETAIL_TYPES_WITH_PARTNER.includes(retailBillTypeSelect.value);
        retailEntNameGroup.classList.toggle('d-none', !needs);
        if (needs) {
            retailEntNameInput.setAttribute('required', 'required');
        } else {
            retailEntNameInput.removeAttribute('required');
            retailEntNameInput.value = '';
        }
    }
    retailBillTypeSelect.addEventListener('change', syncRetailEntNameField);

    // ── 所属企业切换 ──

    const companySelect = document.getElementById('company-select');
    const branchWholesale = document.getElementById('branch-wholesale');
    const branchRetail = document.getElementById('branch-retail');

    function currentCompany() { return companySelect.value; }
    function currentType() {
        const opt = companySelect.options[companySelect.selectedIndex];
        return opt ? (opt.dataset.type || 'wholesale') : 'wholesale';
    }

    // 门店分支的可用性：原因写在分支顶部，两个提交按钮随之禁用。
    // 页面只是显示层——真正的关口在服务端（RetailManualEntry::prepare 的 fail-closed），
    // 绕开按钮直接调端点的路径照样被拦
    function applyRetailAvailability() {
        const reason = retailBlockReason(currentCompany());
        document.getElementById('retail-store-hint').textContent = reason === null ? '' : reason;
        document.getElementById('rm-submit').disabled = reason !== null;
        document.getElementById('rm-btn-import').disabled = reason !== null;
    }

    function onCompanyChange() {
        const isRetail = currentType() === 'retail';
        branchWholesale.classList.toggle('d-none', isRetail);
        branchRetail.classList.toggle('d-none', !isRetail);
        if (isRetail) {
            // 换门店＝换一套可用性（凭据状态随门店变）：重算提示与按钮。
            // 表单里已填的内容保持不动——操作者在两三家店之间来回核对是常见操作
            syncRetailEntNameField();
            applyRetailAvailability();
        }
    }

    companySelect.addEventListener('change', onCompanyChange);

    // ── 在线新增（批发 / 门店共用这一份处理逻辑） ──
    //
    // 两个分支的差别只有三处，都从 opts 来：提交到哪个端点、要不要带 company、
    // "往来单位名称"是否必填。各写一份的话，"提交期间防重入""失败后表单要不要清"
    // 这类细节迟早只在一边改。
    function bindCreateForm(opts) {
        document.getElementById(opts.formId).addEventListener('submit', async function(e) {
            e.preventDefault();
            const form = this;

            const rq = document.getElementById(opts.rqId).value.trim();
            const djbh = document.getElementById(opts.djbhId).value.trim();
            const entName = document.getElementById(opts.entNameId).value.trim();
            const billType = document.getElementById(opts.billTypeId).value;
            let traceCodes = document.getElementById(opts.traceCodesId).value.trim();
            traceCodes = traceCodes.replace(/\r\n/g, '\n').replace(/\n+/g, ',').replace(/^,|,$/g, '');

            // 按钮在不可建单时已是禁用态，这里再拦一次（绕开按钮的路径也该被挡住），
            // 用的还是 retailBlockReason 那一句话
            if (opts.blockReason) {
                const reason = opts.blockReason();
                if (reason !== null) { showResult(opts.resultId, 'danger', reason); return; }
            }
            const needsEntName = opts.needsEntName ? opts.needsEntName() : true;
            if (!rq || !djbh || !billType || !traceCodes || (needsEntName && !entName)) {
                showResult(opts.resultId, 'danger', '请填写所有必填字段');
                return;
            }

            const btn = document.getElementById(opts.buttonId);
            const spinner = document.getElementById(opts.spinnerId);
            btn.disabled = true;
            spinner.classList.remove('d-none');

            // 打开实时日志弹窗
            const modal = new bootstrap.Modal(document.getElementById('progressModal'));
            const logEl = document.getElementById('progress-log');
            const titleEl = document.getElementById('progress-title');
            const summaryEl = document.getElementById('progress-summary');
            titleEl.textContent = opts.titlePrefix + ' — ' + djbh;
            summaryEl.textContent = '';
            logEl.innerHTML = '';
            modal.show();

            const payload = {rq, djbh, ent_name: entName, bill_type: billType, trace_codes: traceCodes};
            if (opts.company) { payload.company = opts.company(); }

            try {
                // 只有**确实提交成功**才清表单：被 400 拒（校验不过/门店没凭据/往来单位查不到/超期）时，
                // 清了就把用户粘好的整段追溯码连单号一起丢掉，而他正需要改一处重试
                const ok = await streamFetch('index.php?page=api&action=' + opts.action, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload),
                }, logEl, summaryEl, titleEl);
                if (ok) {
                    form.reset();
                    document.getElementById(opts.rqId).value = opts.defaultRq;
                    if (opts.afterReset) { opts.afterReset(); }
                }
            } catch (err) {
                appendLog(logEl, 'error', '请求失败: ' + err.message);
            } finally {
                btn.disabled = false;
                spinner.classList.add('d-none');
            }
        });
    }

    // ── xlsx 导入（两个分支共用同一份处理逻辑） ──

    function bindImportCard(opts) {
        document.getElementById(opts.buttonId).addEventListener('click', async function() {
            const fileInput = document.getElementById(opts.fileId);
            const file = fileInput.files[0];
            if (!file) {
                showResult(opts.resultId, 'danger', '请先选择 xlsx 文件');
                return;
            }
            if (opts.blockReason) {
                const reason = opts.blockReason();
                if (reason !== null) { showResult(opts.resultId, 'danger', reason); return; }
            }

            const btn = this;
            const spinner = document.getElementById(opts.spinnerId);
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
            if (opts.company) { formData.append('company', opts.company()); }

            let ok = false;
            try {
                ok = await streamFetch('index.php?page=api&action=' + opts.action, {
                    method: 'POST',
                    body: formData,
                }, logEl, summaryEl, titleEl);
            } catch (err) {
                appendLog(logEl, 'error', '请求失败: ' + err.message);
            } finally {
                btn.disabled = false;
                spinner.classList.add('d-none');
                // 失败（含被 400 拒）时留着已选的文件：多半是"这家店不能建单"这类要改前置条件的事，
                // 清掉选择只会逼用户重新选一遍同一个文件
                if (ok) { fileInput.value = ''; }
            }
        });
    }

    // 批发分支：落库主体由服务端取批发主体（wholesaleSubject），页面不传 company
    bindCreateForm({
        formId: 'manual-form', rqId: 'm-rq', djbhId: 'm-djbh', billTypeId: 'm-bill-type',
        entNameId: 'm-ent-name', traceCodesId: 'm-trace-codes', resultId: 'form-result',
        buttonId: 'btn-submit', spinnerId: 'submit-spinner',
        action: 'manual_create', titlePrefix: '手动上传', defaultRq: '<?= date('Y-m-d') ?>',
    });
    bindImportCard({
        buttonId: 'btn-import', fileId: 'import-file', spinnerId: 'import-spinner',
        resultId: 'import-result', action: 'manual_import',
    });

    // 门店分支：落库主体是页面上选定的那家门店，服务端按它取凭据（ADR 0012）
    bindCreateForm({
        formId: 'retail-manual-form', rqId: 'rm-rq', djbhId: 'rm-djbh', billTypeId: 'rm-bill-type',
        entNameId: 'rm-ent-name', traceCodesId: 'rm-trace-codes', resultId: 'rm-result',
        buttonId: 'rm-submit', spinnerId: 'rm-spinner',
        action: 'manual_create_retail', titlePrefix: '门店新增', defaultRq: '<?= date('Y-m-d') ?>',
        company: currentCompany,
        needsEntName: () => RETAIL_TYPES_WITH_PARTNER.includes(retailBillTypeSelect.value),
        blockReason: () => retailBlockReason(currentCompany()),
        // 表单重置会把"往来单位名称"的显隐与 required 一起复位（reset 只清值、不改这两样，
        // 但下拉已经回到空值、分组仍显示着），故重跑一次同步
        afterReset: syncRetailEntNameField,
    });
    bindImportCard({
        buttonId: 'rm-btn-import', fileId: 'rm-import-file', spinnerId: 'rm-import-spinner',
        resultId: 'rm-import-result', action: 'manual_import_retail',
        company: currentCompany,
        blockReason: () => retailBlockReason(currentCompany()),
    });

    function showResult(id, type, msg) {
        const el = document.getElementById(id);
        if (!type) { el.classList.add('d-none'); return; }
        el.className = 'mt-3 alert alert-' + type;
        el.innerHTML = msg;
        el.classList.remove('d-none');
    }

    // ---- 流式上传日志 ----

    /**
     * 流式读 NDJSON 进度，就地渲染。
     *
     * @return {Promise<boolean>} 这次请求**是不是成功走完**（HTTP ok 且 `_final` 没报错）——
     *         调用方据此决定要不要清表单/清文件选择：被 400 拒时不清，用户还能改一处重试
     */
    async function streamFetch(url, options, logEl, summaryEl, titleEl, opts) {
        opts = opts || {};
        const resp = await fetch(url, options);
        if (!resp.ok) {
            // 端点"开流之前"拒绝时（校验不过、门店没凭据、单据超期…）回的是 400 + 一段 JSON，
            // 里面那句 error 才是给人看的：把原始 JSON 整个糊进日志没人读得懂
            const text = await resp.text();
            let msg = text;
            try {
                const parsed = JSON.parse(text);
                if (parsed && parsed.error) { msg = parsed.error; }
            } catch (e) { /* 不是 JSON 就原样显示 */ }
            appendLog(logEl, 'error', 'HTTP ' + resp.status + ': ' + msg);
            return false;
        }

        const reader = resp.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let successCount = 0;
        let failedCount = 0;
        // 只有**收到了成功的 `_final`** 才算这次请求走完（流被截断时保持 false，调用方不清表单）
        let finalOk = false;

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
                            finalOk = true;
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

        return finalOk;
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
