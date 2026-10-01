<?php
require_once __DIR__ . '/layout.php';

// 零售补传要用的门店凭据可用性：门店名 => 凭据是否已配齐（**不含任何密钥**，页面也不需要——
// 用哪套凭据由服务端按门店取，见 docs/adr/0012）。页面据此把"未识别 / 待配凭据"的行禁用并写明原因；
// 真正的校验在 src/api/tasks_retry_retail.php——页面是显示层，不是可信边界
$retailStores = [];
$configError = '';
try {
    $retailStores = App\Enterprise::retailCredentialReady();
} catch (\Throwable $e) {
    // 企业配置坏了不该让整页打不开：零售补传降级为不可用并写明原因，
    // 页面其余部分与批发链路（不读企业配置）不受影响
    $configError = $e->getMessage();
    $retailStores = [];
}
// "所属企业"筛选下拉与编辑弹窗的选项（企业枚举 + `未识别`）由 Enterprise::selectableNames()
// 给出单一一份，三数据页共用；上面的 try 已经证明配置可载入，这里不再重复降级分支
$companyOptions = $configError === ''
    ? App\Enterprise::selectableNames()
    : [App\Enterprise::UNIDENTIFIED];

layout('上传任务', 'upload-tasks');
?>

<h4 class="mb-4">上传任务</h4>

<?php if ($configError !== ''): ?>
<div class="alert alert-danger">
    <strong>企业配置载入失败，零售补传不可用：</strong><?= htmlspecialchars($configError) ?>
</div>
<?php endif; ?>

<!-- 搜索和操作栏 -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-1">
                <label class="form-label small text-muted">单号</label>
                <input type="text" class="form-control" id="filter-djbh" placeholder="单号筛选">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">往来单位</label>
                <input type="text" class="form-control" id="filter-ent-name" placeholder="往来单位筛选">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">所属企业</label>
                <select class="form-select" id="filter-company">
                    <option value="">全部</option>
                    <?php foreach ($companyOptions as $name): ?>
                        <option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label small text-muted">任务状态</label>
                <select class="form-select" id="filter-task-status">
                    <option value="等待上传" selected>等待上传</option>
                    <option value="已处理">已处理</option>
                    <option value="">全部</option>
                </select>
            </div>
            <div class="col-md-1">
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
            <div class="col-md-1">
                <label class="form-label small text-muted">来源</label>
                <select class="form-select" id="filter-source">
                    <option value="">全部</option>
                    <option value="cron">定时采集</option>
                    <option value="manual">手动上传</option>
                    <option value="batch_check">批量核查</option>
                    <option value="batch_retry">批量重传</option>
                    <option value="retail">零售采集</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">单据日期</label>
                <input type="text" class="form-control" id="filter-rq-range" placeholder="选择日期范围" readonly>
            </div>
            <div class="col-md-1">
                <label class="form-label small text-muted">任务创建时间</label>
                <input type="text" class="form-control" id="filter-created-range" placeholder="选择日期范围" readonly>
            </div>
            <div class="col-md-3 d-flex gap-2 align-items-end">
                <button class="btn btn-outline-primary btn-sm" id="btn-export" title="按当前筛选条件导出 xlsx">
                    <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708z"/></svg>
                    导出 xlsx
                </button>
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

<!-- 表格 -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="tasks-table">
                <thead class="table-light">
                    <tr>
                        <th width="40"><input type="checkbox" class="form-check-input" id="select-all"></th>
                        <th width="100">单据日期</th>
                        <th>单号</th>
                        <th width="110">单据类型</th>
                        <th width="200">所属企业</th>
                        <th>往来单位</th>
                        <th>追溯码</th>
                        <th width="80">来源</th>
                        <th width="170">任务创建时间</th>
                        <th width="170">最后更新时间</th>
                        <th width="150">状态</th>
                        <th width="120">操作</th>
                    </tr>
                </thead>
                <tbody id="tasks-tbody">
                    <tr><td colspan="12" class="text-center py-5 text-muted">加载中...</td></tr>
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
                    <label class="form-label">所属企业</label>
                    <select class="form-select" id="edit-company">
                        <?php foreach ($companyOptions as $name): ?>
                            <option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">改动只作用于该任务：凭据同时重设为该企业的主授权，重传即按新主体申报；日志里已记下的那次申报主体不受影响。</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">单号</label>
                    <input type="text" class="form-control" id="edit-djbh">
                </div>
                <!-- 门店行不显示这一格：零售单的对手方是平台 ID（采集的取自源表、手工建的由名称查出并当场落库），
                     补传直接读那两列。留着这个框等于留一处"改了不生效"的静默陷阱——名称改了，ID 不会重解析 -->
                <div class="mb-3" id="edit-ent-name-group">
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
            <!-- white-space: pre-line：消息里用 \n 分段（批量重传的三处点名就是多段），
                 默认的 HTML 空白折叠会把它们挤成一整行 -->
            <div class="modal-body" id="confirm-message" style="white-space: pre-line">确定要删除吗？</div>
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

<!-- 零售补传弹窗：单据元数据 + 二次确认（补传是对平台的真实申报） -->
<div class="modal fade" id="retailRetryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">零售补传</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning small mb-3">
                    补传是<strong>向码上放心平台的真实申报，不可逆</strong>。请确认该单尚未被外部系统上传——
                    若外部系统已用另一套 AppKey 传过同一张单，补传会在平台上造成重复申报。
                </div>
                <dl class="row small mb-0">
                    <dt class="col-3">单号</dt>
                    <dd class="col-9"><code id="rr-djbh"></code></dd>
                    <dt class="col-3">单据日期</dt>
                    <dd class="col-9" id="rr-rq"></dd>
                    <dt class="col-3">单据类型</dt>
                    <dd class="col-9" id="rr-bill-type"></dd>
                    <dt class="col-3">门店</dt>
                    <dd class="col-9" id="rr-company"></dd>
                    <dt class="col-3">追溯码</dt>
                    <dd class="col-9" id="rr-codes"></dd>
                </dl>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-warning" id="btn-retail-retry-confirm">确认补传</button>
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
// 服务端注入的门店补传可用性：门店名 => 'ready' | 'pending' | 'no_slot'（门店名缺席 = 不在配置里），
// **不含任何密钥**。三种"不能补传"的原因由 Enterprise::retailCredentialReady() 分开给，见 docs/adr/0012
const retailStores = <?= json_encode($retailStores, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT) ?>;
const retailConfigError = <?= json_encode($configError, JSON_UNESCAPED_UNICODE) ?>;

(function() {
    let currentPage = 1;
    let selectedIds = new Set();
    let total = 0;
    let lastRows = [];          // 当前页数据（零售补传弹窗按 id 回查单据元数据）
    // id => 行，**跨页累积**：批量重传的确认框要按 id 回查被选中行的单据类型与响应状态，
    // 而勾选集（selectedIds）是跨页保持的，被选中的行不一定在当页的 lastRows 里。
    // 与 selectedIds 同生命周期（都不清），刷新时同名 id 被新数据覆盖
    const rowIndex = new Map();
    let retailRetryTaskId = null;

    const today = new Date();
    const weekAgo = new Date(today);
    weekAgo.setDate(today.getDate() - 6);
    let rqTouched = false;   // 单据日期是否被用户手动改过（关键词检索时忽略默认 7 天范围）

    const fpRq = flatpickr("#filter-rq-range", {
        mode: "range",
        dateFormat: "Y-m-d",
        locale: "zh",
        defaultDate: [weekAgo, today],
        onChange: () => { rqTouched = true; },
    });

    const fpCreated = flatpickr("#filter-created-range", {
        mode: "range",
        dateFormat: "Y-m-d",
        locale: "zh",
    });

    function readRange(fp) {
        const d = fp.selectedDates;
        if (d.length === 2) {
            return [fp.formatDate(d[0], 'Y-m-d'), fp.formatDate(d[1], 'Y-m-d')];
        }
        return ['', ''];
    }
    let confirmCallback = null;

    // 门店采集单与批发共用"等待上传"（2026-10-01 统一，见 docs/adr/0014）：
    // 区分靠"所属企业"列与来源列，不再有单独的状态色
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
    const sourceLabels = {
        'cron': '定时采集',
        'manual': '手动上传',
        'batch_check': '批量核查',
        'batch_retry': '批量重传',
        'retail': '零售采集',
    };
    const sourceBadges = {
        'cron': 'bg-primary',
        'manual': 'bg-success',
        'batch_check': 'bg-info',
        'batch_retry': 'bg-warning text-dark',
        'retail': 'bg-dark',
    };
    const billTypeLabels = {
        '102': '采购入库', '103': '退货入库', '104': '调拨入库', '107': '供应入库', '108': '召回入库',
        '110': '赠品入库', '111': '盘盈入库', '112': '报废入库', '113': '其他入库',
        '201': '销售出库', '202': '退货出库', '203': '调拨出库', '204': '返工出库', '205': '销毁出库',
        '206': '抽检出库', '207': '直调出库', '209': '供应出库', '211': '召回出库', '212': '赠品出库',
        '214': '盘亏出库', '215': '损坏出库', '216': '报废出库', '217': '其他出库', '237': '直调退货',
        // 零售门店单据独有的两类（消费者级）：工单 03 起会采集入库，缺了标签它们在这页显示成 '-'
        '321': '使用出库', '116': '消费者退货入库',
    };

    function getFilters() {
        const params = new URLSearchParams();
        const djbh = document.getElementById('filter-djbh').value.trim();
        const entName = document.getElementById('filter-ent-name').value.trim();
        const taskStatus = document.getElementById('filter-task-status').value;
        const responseStatus = document.getElementById('filter-response-status').value;
        const source = document.getElementById('filter-source').value;
        const company = document.getElementById('filter-company').value;
        const [dateFrom, dateTo] = readRange(fpRq);
        const [createdFrom, createdTo] = readRange(fpCreated);
        if (djbh) params.set('djbh', djbh);
        if (entName) params.set('ent_name', entName);
        if (taskStatus) params.set('task_status', taskStatus);
        if (responseStatus) params.set('response_status', responseStatus);
        if (source) params.set('source', source);
        if (company) params.set('company', company);
        // 关键词检索时忽略默认的 7 天日期范围（用户手动改过日期则正常组合）。
        // "关键词"只算单号与往来单位，**所属企业下拉不算**：它是筛选维度，
        // 算进来会让"选了企业"顺手把默认的单据日期范围也丢掉，日期行为被无声改变。
        const ignoreDefaultRq = (djbh || entName) && !rqTouched;
        if (dateFrom && !ignoreDefaultRq) params.set('date_from', dateFrom);
        if (dateTo && !ignoreDefaultRq) params.set('date_to', dateTo);
        if (createdFrom) params.set('created_from', createdFrom);
        if (createdTo) params.set('created_to', createdTo);
        params.set('page_num', currentPage);
        return params;
    }

    async function loadData() {
        const params = getFilters();
        try {
            const resp = await fetch('index.php?page=api&action=tasks&' + params.toString());
            const data = await resp.json();
            total = data.total || 0;
            renderTable(data.data || []);
            renderPagination(data);
        } catch (e) {
            document.getElementById('tasks-tbody').innerHTML =
                '<tr><td colspan="12" class="text-center py-5 text-danger">加载失败: ' + e.message + '</td></tr>';
        }
    }

    // 所属企业列末尾的状态徽标：两种"不能补传"的原因要**看得见**，不能只藏在 hover 提示里——
    // "未识别 / 门店不在配置中"是真异常（该去查配置或源库），"待配凭据"是预期内的正常状态
    // （只是在等 AppKey/SECRETKEY），两者必须能一眼分开。返回 [badgeClass, 文案] 或 null。
    function companyBadge(r) {
        if (r.company === '未识别') {
            return ['bg-danger', '未识别'];
        }
        if (r.source !== 'retail') {
            return null;
        }
        if (!(r.company in retailStores)) {
            return ['bg-danger', '门店不在配置中'];
        }
        if (retailStores[r.company] === 'no_slot') {
            return ['bg-danger', '未声明凭据位'];   // 配置缺口，与"待配凭据"（等密钥）不是一回事
        }
        if (retailStores[r.company] !== 'ready') {
            return ['bg-secondary', '待配凭据'];
        }
        return null;
    }

    // 所属企业格：认不到门店时整格只显示徽标（与工单 03 的行为一致），否则"门店名 + 状态徽标"
    function companyCell(r, unidentified) {
        const badge = companyBadge(r);
        if (!badge) {
            return esc(r.company || '-');
        }
        const span = `<span class="badge ${badge[0]}" title="${esc(companyBadgeHint(badge[1]))}">${esc(badge[1])}</span>`;
        return unidentified ? span : `${esc(r.company)} ${span}`;
    }

    function companyBadgeHint(text) {
        if (text === '未识别') {
            return '门店认领失败：企业配置里没有这家门店，或源库改了名——真异常信号，需人工核查';
        }
        if (text === '待配凭据') {
            return 'AppKey/SECRETKEY 尚未到手，暂时不能补传（预期内的正常状态，不是异常）';
        }
        if (text === '未声明凭据位') {
            return '企业配置里这家门店没有声明凭据位（config/enterprises.php 缺这一项）——配置缺口，补上凭据位才能补传';
        }
        return '该门店不在企业配置中，无法补传';
    }

    // 零售行的补传入口：禁用态必须写明**是哪种原因**——"未识别"（该去查源库/配置）
    // 与"待配凭据"（等密钥到手，预期内的正常状态）含义完全不同，混成一句"不可用"会误导人
    function retailRetryButton(r) {
        const disabled = (reason) => `<span class="d-inline-block" tabindex="0" title="${esc(reason)}">
                <button class="btn btn-sm btn-outline-secondary" disabled style="pointer-events:none">补传</button>
            </span>`;

        if (retailConfigError) {
            return disabled('企业配置载入失败，零售补传不可用');
        }
        if (r.company === '未识别') {
            return disabled('未识别：门店认领失败（配置漏了门店，或源库改了名），需人工核查后才能补传');
        }
        if (!(r.company in retailStores)) {
            return disabled('该门店不在企业配置中，无法补传');
        }
        if (retailStores[r.company] === 'no_slot') {
            return disabled('未声明凭据位：企业配置里这家门店没有凭据位（config/enterprises.php 缺这一项），补上才能补传');
        }
        if (retailStores[r.company] !== 'ready') {
            return disabled('待配凭据：AppKey/SECRETKEY 尚未到手，暂时不能补传（预期内的正常状态，不是异常）');
        }
        return `<button class="btn btn-sm btn-outline-warning btn-retail-retry" data-id="${r.id}">补传</button>`;
    }

    function renderTable(rows) {
        const tbody = document.getElementById('tasks-tbody');
        lastRows = rows;
        rows.forEach(r => rowIndex.set(r.id, r));
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="12" class="text-center py-5 text-muted">暂无数据</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(r => {
            // 未识别 = 认领不到门店（配置漏了门店或源库改了名），真异常信号，整行标红提醒人去查
            const unidentified = r.company === '未识别';
            // 零售行走补传入口（凭据由服务端按门店取）；批发行保持原样的重传
            const isRetail = r.source === 'retail';
            return `
            <tr${unidentified ? ' class="table-danger"' : ''}>
                <td><input type="checkbox" class="form-check-input row-checkbox" data-id="${r.id}" ${selectedIds.has(r.id) ? 'checked' : ''}></td>
                <td class="text-nowrap">${esc(r.rq)}</td>
                <td><code>${esc(r.djbh)}</code></td>
                <td>${billTypeLabels[r.bill_type] || '-'}</td>
                <td class="text-truncate" style="max-width:200px" title="${esc(r.company || '')}">${companyCell(r, unidentified)}</td>
                <td class="text-truncate" style="max-width:220px" title="${esc(r.ent_name || '')}">${esc(r.ent_name)}</td>
                <td>
                    ${r.trace_codes
                        ? `<button class="btn btn-sm btn-outline-secondary btn-trace" data-trace="${esc(r.trace_codes)}">查看追溯码</button>`
                        : '<span class="text-muted">-</span>'}
                </td>
                <td><span class="badge ${sourceBadges[r.source] || 'bg-secondary'}">${esc(sourceLabels[r.source] || r.source || '-')}</span></td>
                <td class="text-nowrap">${esc(r.created_at || '-')}</td>
                <td class="text-nowrap">${esc(r.updated_at || '-')}</td>
                <td>
                    <span class="badge ${taskStatusBadges[r.task_status] || 'bg-secondary'}">${esc(r.task_status || '-')}</span>
                    ${r.response_status ? `<span class="badge ${responseStatusBadges[r.response_status] || 'bg-secondary'} ms-1">${esc(r.response_status)}</span>` : ''}
                </td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-primary btn-edit" data-id="${r.id}">编辑</button>
                    <button class="btn btn-sm btn-outline-danger btn-delete" data-id="${r.id}">删除</button>
                    ${isRetail
                        ? retailRetryButton(r)
                        : `<button class="btn btn-sm btn-outline-warning btn-retry" data-id="${r.id}">重传</button>`}
                </td>
            </tr>
        `;
        }).join('');

        // 绑定事件
        tbody.querySelectorAll('.btn-edit').forEach(btn => btn.addEventListener('click', () => openEdit(btn.dataset.id)));
        tbody.querySelectorAll('.btn-delete').forEach(btn => btn.addEventListener('click', () => deleteSingle(btn.dataset.id)));
        tbody.querySelectorAll('.btn-retry').forEach(btn => btn.addEventListener('click', () => retrySingle(btn.dataset.id)));
        tbody.querySelectorAll('.btn-retail-retry').forEach(btn => btn.addEventListener('click', () => openRetailRetry(parseInt(btn.dataset.id))));
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

    function renderPagination(data) {
        const container = document.getElementById('pagination-container');
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
                currentPage = parseInt(this.dataset.page);
                loadData();
            });
        });
    }

    // 编辑弹窗里"所属企业"的原始值：保存时与之比对，变了才走二次确认
    let editOriginalCompany = '';

    // 该行上的企业可能不在配置枚举里（配置改过、门店被删）——补一个选项出来。
    // 否则 <select> 赋值失败会静默落回第一个选项，保存时把企业改错还没人察觉。
    function ensureCompanyOption(select, company) {
        if (!company) return;
        if (Array.from(select.options).some(o => o.value === company)) return;
        const opt = document.createElement('option');
        opt.value = company;
        opt.textContent = company + '（不在当前企业配置中）';
        select.appendChild(opt);
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
            // 门店行（采集的与手工建的都算）隐藏"往来单位"：见弹窗标记处的说明。
            // 值是原样回填、保存时原样送回，不改动它——对零售行而言这一列只是显示用的留痕
            document.getElementById('edit-ent-name-group')
                .classList.toggle('d-none', task.source === 'retail');
            document.getElementById('edit-trace-codes').value = task.trace_codes || '';
            document.getElementById('edit-bill-type').value = task.bill_type || '';
            const companySelect = document.getElementById('edit-company');
            ensureCompanyOption(companySelect, task.company);
            companySelect.value = task.company || '';
            editOriginalCompany = task.company || '';
            new bootstrap.Modal(document.getElementById('editModal')).show();
        } catch (e) {
            alert('加载失败: ' + e.message);
        }
    }

    // 改"所属企业"意味着这张单重传时改走另一套凭据（服务端会连带重设 credential），
    // 那正是"单据申报到哪个主体"的开关，故二次确认；其余字段照原样直接保存
    function saveEdit() {
        const company = document.getElementById('edit-company').value;
        if (company !== editOriginalCompany) {
            showConfirm('确定把该任务的"所属企业"由「' + (editOriginalCompany || '（空）') + '」改为「' + company
                + '」吗？重传将按该企业的主授权凭据申报，改动即时落库。', doSaveEdit);
            return;
        }
        doSaveEdit();
    }

    async function doSaveEdit() {
        const company = document.getElementById('edit-company').value;
        const payload = {
            id: document.getElementById('edit-id').value,
            rq: document.getElementById('edit-rq').value,
            djbh: document.getElementById('edit-djbh').value,
            ent_name: document.getElementById('edit-ent-name').value,
            trace_codes: document.getElementById('edit-trace-codes').value,
            bill_type: document.getElementById('edit-bill-type').value,
        };
        // 只在**企业真的改了**时才把 company 送上去：服务端见到该键就会把 credential 重设为该企业的凭据键，
        // 而"这次实际用了哪套凭据"是审计值（补传流程写回的那套）——
        // 改个日期顺手把它重设一遍，是在动一个与本次编辑无关的字段。
        // 顺带：company 为空串的行（理论上不该有）也因此能正常保存其余字段，不会被 400 卡住。
        if (company !== editOriginalCompany) payload.company = company;
        const body = JSON.stringify(payload);
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

    // ── 零售补传：单据元数据全部来自落库行，操作者什么都不用填 ──
    function openRetailRetry(id) {
        const task = lastRows.find(t => t.id === id);
        if (!task) { alert('未找到该任务，请刷新后重试'); return; }
        // 按钮本身在不可补传时已是禁用态，这里再拦一次：绕开按钮直接调用的路径也该被挡住
        if (retailStores[task.company] !== 'ready') {
            alert('该门店的凭据尚未配齐（或不在企业配置中），无法补传');
            return;
        }

        document.getElementById('rr-djbh').textContent = task.djbh;
        document.getElementById('rr-rq').textContent = task.rq || '-';
        document.getElementById('rr-bill-type').textContent = billTypeLabels[task.bill_type] || task.bill_type || '-';
        document.getElementById('rr-company').textContent = task.company;
        document.getElementById('rr-codes').textContent =
            (task.trace_codes || '').split(',').filter(Boolean).length + ' 个';

        retailRetryTaskId = id;
        new bootstrap.Modal(document.getElementById('retailRetryModal')).show();
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

    // 导出 xlsx：按当前筛选条件全量导出（无数据时不发请求）
    async function exportXlsx() {
        if (!total) { alert('当前筛选条件下无数据可导出'); return; }
        const btn = document.getElementById('btn-export');
        const params = getFilters();
        params.delete('page_num');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '导出中...';
        try {
            const resp = await fetch('index.php?page=api&action=export&type=tasks&' + params.toString());
            if (!resp.ok) {
                const err = await resp.json().catch(() => null);
                alert('导出失败: ' + (err && err.error ? err.error : 'HTTP ' + resp.status));
                return;
            }
            const blob = await resp.blob();
            const cd = resp.headers.get('Content-Disposition') || '';
            const m = cd.match(/filename\*=UTF-8''([^;]+)/i);
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = m ? decodeURIComponent(m[1]) : 'export.xlsx';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        } catch (e) {
            alert('导出失败: ' + e.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    }
    document.getElementById('btn-export').addEventListener('click', exportXlsx);
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

    // 批量重传的二次确认：三处点名（补传不可逆 / 含 104·203 门店单 / 含已申报成功的行）。
    // 选中集跨页保持，故一律按 id 回查跨页累积的 rowIndex，而不是当页的 lastRows——
    // 查不到的行（页面加载后被删）只按数量计，不点名，端点那边它们也只会影响自己
    function batchRetryConfirmMessage() {
        const ids = Array.from(selectedIds);
        let msg = '确认重传选中的 ' + ids.length + ' 条任务？\n\n'
            + '重传是向码上放心平台的真实申报，不可逆。批里可能既有批发单据，也有门店单据。';
        // 已经申报成功的行（切到"已处理"才选得到）：重传不会改变平台上的结果，但确实是一次真实调用，
        // 混在批量里容易被顺手带过——点名，不拦（与单条入口的口径一致）
        const done = ids.filter(id => ['上传成功', '单据重复']
            .includes((rowIndex.get(id) || {}).response_status));
        if (done.length) {
            msg += '\n\n⚠️ 本批含 ' + done.length + ' 条已申报成功的单据：'
                + '重传会在平台上再次申报（平台多半回"单据重复"）。若非刻意为之，请先把它们从勾选里去掉。';
        }
        // 104/203（调拨）的 fromUserId/toUserId 语义仍待外部系统工程师确认（ADR 0010）；
        // 单条入口同样暴露该风险，但批量会一次放大成一批，故在确认框里点名。
        // **只算门店行**：批发链路的 104/203 走 kyt 接口 + 往来单位名录，与那条待确认项无关，
        // 把它们也算进来只会让人按错误的理由慌一下（两条链路的判据见 docs/adr/0010）
        const ambiguous = ids.filter(id => {
            const r = rowIndex.get(id) || {};
            return r.source === 'retail' && ['104', '203'].includes(r.bill_type);
        });
        if (ambiguous.length) {
            msg += '\n\n⚠️ 本批含 ' + ambiguous.length + ' 张 104/203（调拨）门店单据：'
                + '其 fromUserId/toUserId 照搬源表同名列，但发货/收货语义仍待外部系统工程师确认（ADR 0010）——'
                + '方向若反，平台上会留下错误申报。请确认后再传。';
        }
        return msg;
    }

    document.getElementById('btn-batch-retry').addEventListener('click', () => {
        showConfirm(batchRetryConfirmMessage(), async () => {
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

    document.getElementById('btn-retail-retry-confirm').addEventListener('click', async () => {
        if (!retailRetryTaskId) return;
        const id = retailRetryTaskId;
        bootstrap.Modal.getInstance(document.getElementById('retailRetryModal')).hide();

        const modal = new bootstrap.Modal(document.getElementById('progressModal'));
        const logEl = document.getElementById('progress-log');
        const titleEl = document.getElementById('progress-title');
        const summaryEl = document.getElementById('progress-summary');
        titleEl.textContent = '零售补传 — 任务 #' + id;
        summaryEl.textContent = '';
        logEl.innerHTML = '';
        modal.show();

        try {
            await streamFetch('index.php?page=api&action=tasks_retry_retail', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id}),
            }, logEl, summaryEl, titleEl);
            loadData();
        } catch (e) {
            appendLog(logEl, 'error', '请求失败: ' + e.message);
        }
    });

    // 筛选实时搜索（防抖）
    let searchTimeout;
    ['filter-djbh', 'filter-ent-name', 'filter-task-status', 'filter-response-status', 'filter-source', 'filter-company'].forEach(id => {
        document.getElementById(id).addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => { currentPage = 1; loadData(); }, 400);
        });
        document.getElementById(id).addEventListener('change', () => { currentPage = 1; loadData(); });
    });
    fpRq.config.onChange.push(() => { currentPage = 1; loadData(); });
    fpCreated.config.onChange.push(() => { currentPage = 1; loadData(); });

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
        // 批发链路给 ent_name（往来单位），零售补传给 company（门店）——零售没有往来单位
        const who = data.ent_name || data.company || '';
        return statusBadge + ' <span style="color:#e2e8f0">' + esc(data.djbh) + '</span>'
            + (who ? ' <span style="color:#94a3b8">' + esc(who) + '</span>' : '')
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
