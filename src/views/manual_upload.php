<?php
require_once __DIR__ . '/layout.php';

// 顶部"所属企业"下拉与零售分支要用的企业清单：**只含企业名、类型与"该店凭据是否配齐"**，
// 不含任何密钥（密钥留在服务端）。页面据此切换分支、把"待配凭据"写成禁用原因；
// 用哪套凭据不由页面选（门店与凭据 1:1，见 docs/adr/0012），真正的校验在
// src/api/tasks_batch_retry_retail.php 与 App\RetailRetransmit——页面是显示层，不是可信边界
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

<!-- ── 零售分支：不提供从零手工录入，只列该门店已采集的单据（可补传、可编辑、可删除） ── -->
<div id="branch-retail" class="d-none">
    <!-- 筛选栏：单号 / 任务状态 / 响应状态 / 单据日期 / 补传任务创建时间。
         两个日期选择器**都没有默认值**——待补传是积压队列，默认藏起 7 天前的单会被读成"这家店没单了" -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small text-muted">单号</label>
                    <input type="text" class="form-control" id="retail-filter-djbh" placeholder="单号筛选">
                </div>
                <div class="col-md-1">
                    <label class="form-label small text-muted">任务状态</label>
                    <select class="form-select" id="retail-filter-task-status">
                        <option value="待补传" selected>待补传</option>
                        <option value="已处理">已处理</option>
                        <option value="">全部</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small text-muted">响应状态</label>
                    <select class="form-select" id="retail-filter-response-status">
                        <option value="">全部</option>
                        <option value="上传成功">上传成功</option>
                        <option value="单据重复">单据重复</option>
                        <option value="上传失败">上传失败</option>
                        <option value="信息不存在">信息不存在</option>
                        <option value="未确定">未确定</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">单据日期</label>
                    <input type="text" class="form-control" id="retail-filter-rq-range" placeholder="选择日期范围" readonly>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">补传任务创建时间</label>
                    <input type="text" class="form-control" id="retail-filter-created-range" placeholder="选择日期范围" readonly>
                </div>
                <div class="col-md-3 d-flex gap-2 align-items-end">
                    <button class="btn btn-outline-primary btn-sm" id="btn-retail-export" title="按当前筛选条件导出 xlsx">
                        <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708z"/></svg>
                        导出 xlsx
                    </button>
                    <button class="btn btn-primary btn-sm" id="btn-retail-refresh" title="刷新">
                        <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2v1z"/><path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466z"/></svg>
                        刷新
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
            <span>门店单据</span>
            <span class="small text-muted" id="retail-count-hint"></span>
        </div>
        <div class="card-body">
            <div class="mb-2 d-flex gap-2">
                <button class="btn btn-warning" id="btn-retail-batch" disabled>
                    <span class="spinner-border spinner-border-sm d-none" id="retail-batch-spinner"></span>
                    批量补传（已选 <span id="retail-selected-count">0</span> 条）
                </button>
                <button class="btn btn-danger" id="btn-retail-batch-delete" disabled>
                    <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/><path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3h11V2h-11v1z"/></svg>
                    批量删除
                </button>
            </div>
            <!-- 该门店能不能补传的说明：正常门店为空，待配凭据/没声明凭据位/不在配置/配置载入失败时写明原因 -->
            <div class="form-text mb-3" id="retail-store-hint"></div>

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
                        <th>补传任务创建时间</th>
                        <th>状态</th>
                        <th style="width:170px">操作</th>
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

<!-- 门店单据编辑弹窗：**只有这四个字段**。门店单的对手方是源表里的平台 ID（fromUserId/toUserId/
     physicType），按 ADR 0011 不接受人工录入——手工录平台 ID 几乎必然出错，而录错了当时察觉不了；
     "所属企业"也不给改：在本分支里改它会把这一行挪出当前门店的清单。 -->
<div class="modal fade" id="retailEditModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">编辑门店单据</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="re-id">
                <div class="mb-3">
                    <label class="form-label">日期</label>
                    <input type="date" class="form-control" id="re-rq">
                </div>
                <div class="mb-3">
                    <label class="form-label">单据类型</label>
                    <select class="form-select" id="re-bill-type">
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
                    <div class="form-text">只列门店单据的这四种（采集口径写死这四种，接口路由也只认这四种）——
                        改成别的类型没有路由，补传会被服务端拒绝且页面不会有提示。104/203 → 调拨接口，321/116 → 零售接口。</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">单号</label>
                    <input type="text" class="form-control" id="re-djbh">
                </div>
                <div class="mb-3">
                    <label class="form-label">追溯码</label>
                    <textarea class="form-control" id="re-trace-codes" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="btn-save-retail-edit">保存</button>
            </div>
        </div>
    </div>
</div>

<!-- 零售补传弹窗：单据元数据 + 二次确认（补传是对平台的真实申报，不可逆）。
     与上传任务页那份同款——操作前要看清是哪张单 -->
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

<!-- 确认弹窗（删除 / 批量删除） -->
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

    const billTypeLabels = {
        '102': '采购入库', '103': '退货入库', '104': '调拨入库', '107': '供应入库', '108': '召回入库',
        '110': '赠品入库', '111': '盘盈入库', '112': '报废入库', '113': '其他入库',
        '201': '销售出库', '202': '退货出库', '203': '调拨出库', '204': '返工出库', '205': '销毁出库',
        '206': '抽检出库', '207': '直调出库', '209': '供应出库', '211': '召回出库', '212': '赠品出库',
        '214': '盘亏出库', '215': '损坏出库', '216': '报废出库', '217': '其他出库', '237': '直调退货',
        '321': '使用出库', '116': '消费者退货入库',
    };

    // 状态徽标配色与上传任务页同一套（两个页面的状态列看起来要一致）
    const taskStatusBadges = {
        '等待上传': 'bg-secondary',
        '待补传': 'bg-warning text-dark',
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

    // ── 零售分支：门店补传清单（筛选 + 分页）+ 批量补传/删除 + 行操作 ──

    let retailRowIndex = new Map();   // id => 该行，**跨页累积**：补传二次确认与编辑弹窗要按 id 回查，而被选中的行不一定在当页
    let retailSelectedIds = new Set();// 勾选集**跨页保留**——翻一页就丢勾选的话，批量补传/批删根本没法用
    let currentRetailPage = 1;
    let retailTotal = 0;              // 当前筛选条件下的总条数（导出前判断"有没有数据"看它）

    // 两个日期范围选择器都**不设默认值**：待补传是积压队列，默认藏起 7 天前的单会被读成
    // "这家店没单了"。上传任务页那套"输入关键词时忽略默认 7 天范围"的补偿逻辑因此不需要
    const fpRetailRq = flatpickr('#retail-filter-rq-range', {mode: 'range', dateFormat: 'Y-m-d', locale: 'zh'});
    const fpRetailCreated = flatpickr('#retail-filter-created-range', {mode: 'range', dateFormat: 'Y-m-d', locale: 'zh'});

    function readRange(fp) {
        const d = fp.selectedDates;
        if (d.length === 2) {
            return [fp.formatDate(d[0], 'Y-m-d'), fp.formatDate(d[1], 'Y-m-d')];
        }
        return ['', ''];
    }

    // 筛选参数：**列表与导出共用这一个构造函数**。各写一份的话，"页面上筛出来的行"与
    // "导出的行"迟早对不上（08 票就是这么漂的）。参数名与上传任务页一致：
    // date_from/to 指单据日期，created_from/to 指任务创建时间
    function retailFilterParams() {
        const params = new URLSearchParams();
        params.set('company', currentCompany());
        const djbh = document.getElementById('retail-filter-djbh').value.trim();
        const taskStatus = document.getElementById('retail-filter-task-status').value;
        const responseStatus = document.getElementById('retail-filter-response-status').value;
        if (djbh) params.set('djbh', djbh);
        if (taskStatus) params.set('task_status', taskStatus);
        if (responseStatus) params.set('response_status', responseStatus);
        const [rqFrom, rqTo] = readRange(fpRetailRq);
        const [createdFrom, createdTo] = readRange(fpRetailCreated);
        if (rqFrom) params.set('date_from', rqFrom);
        if (rqTo) params.set('date_to', rqTo);
        if (createdFrom) params.set('created_from', createdFrom);
        if (createdTo) params.set('created_to', createdTo);
        return params;
    }

    function resetRetailListState() {
        retailRowIndex = new Map();
        retailSelectedIds = new Set();
        currentRetailPage = 1;
        // 筛选条件**不重置**：换门店看同一张单是常见操作（单号筛选跨店保留才有用），
        // 而筛选栏就在眼前，留着的条件看得见，不算是被藏起来的状态
    }

    async function loadRetailTasks(company) {
        const tbody = document.getElementById('retail-tbody');
        const emptyEl = document.getElementById('retail-empty');
        const countHint = document.getElementById('retail-count-hint');
        const storeHint = document.getElementById('retail-store-hint');
        const batchBtn = document.getElementById('btn-retail-batch');

        tbody.innerHTML = '';
        emptyEl.classList.add('d-none');
        countHint.textContent = '加载中...';
        document.getElementById('retail-check-all').checked = false;
        document.getElementById('retail-pagination').innerHTML = '';
        updateSelection();

        // 该门店能不能补传——取消人工选凭据后，页面只剩这一个判断（用哪套凭据由服务端按门店取，
        // 见 docs/adr/0012）。状态值见 Enterprise::retailCredentialReady()：
        // 门店名缺席 = 不在配置里；'no_slot' = 配置里没声明凭据位（配置缺口）；
        // 'pending' = 待配凭据（等密钥，预期内的正常状态）；'ready' = 可补传
        const storeState = retailStores[company];
        batchBtn.dataset.ready = storeState === 'ready' ? '1' : '';

        if (retailConfigError) {
            storeHint.textContent = '企业配置载入失败，零售补传不可用。';
        } else if (!(company in retailStores)) {
            storeHint.textContent = '该门店不在企业配置中，无法补传。';
        } else if (storeState === 'no_slot') {
            storeHint.textContent = '该门店在配置里没有声明凭据位（config/enterprises.php 缺这一项），无法补传——'
                + '这是配置缺口，不是"等密钥到手"那种正常状态。清单照常可见。';
        } else if (storeState === 'pending') {
            storeHint.textContent = '待配凭据：该门店的 AppKey/SECRETKEY 尚未到手（预期内的正常状态，不是异常），补齐前不能补传。清单照常可见。';
        } else {
            storeHint.textContent = '';   // 正常态不提示：能补传就没什么要说的，多一句话只是噪声
        }

        try {
            // 筛选参数与导出共用 retailFilterParams()，这里只多一个 page_num
            const params = retailFilterParams();
            params.set('page_num', currentRetailPage);
            const resp = await fetch('index.php?page=api&action=manual_retail_tasks&' + params.toString());
            const data = await resp.json();
            if (!resp.ok) throw new Error(data.error || ('HTTP ' + resp.status));

            // 当前页越界（多半是本页刚被补传传空、或被批删删空）：退到最后一页重拉，别撂一片空白——
            // 行从当前页消失是本页最常见的状态变化，不是罕见边界
            if (data.total > 0 && data.page > data.total_pages) {
                currentRetailPage = Math.max(1, data.total_pages);
                return loadRetailTasks(company);
            }
            currentRetailPage = data.page;

            const retailRows = data.data || [];   // 当前页数据：只在本函数里用，不必是模块级状态
            retailRows.forEach(r => retailRowIndex.set(r.id, r));
            retailTotal = data.total || 0;
            countHint.textContent = retailTotal ? ('共 ' + retailTotal + ' 条') : '';

            if (!retailRows.length) {
                emptyEl.textContent = '当前筛选条件下暂无单据。';
                emptyEl.classList.remove('d-none');
            } else {
                tbody.innerHTML = retailRows.map(r => `
                    <tr>
                        <td><input type="checkbox" class="form-check-input retail-row-check" value="${r.id}" ${retailSelectedIds.has(r.id) ? 'checked' : ''}></td>
                        <td><code>${esc(r.djbh)}</code></td>
                        <td class="text-nowrap">${esc(r.rq || '-')}</td>
                        <td>${esc(billTypeLabels[r.bill_type] || r.bill_type || '-')}</td>
                        <td>
                            ${r.trace_codes
                                ? `<button class="btn btn-sm btn-outline-secondary btn-retail-trace" data-trace="${esc(r.trace_codes)}">查看追溯码</button>`
                                : '<span class="text-muted">-</span>'}
                        </td>
                        <td class="text-end">${r.code_count}</td>
                        <td class="text-nowrap">${esc(r.created_at || '-')}</td>
                        <td>
                            <span class="badge ${taskStatusBadges[r.task_status] || 'bg-secondary'}">${esc(r.task_status || '-')}</span>
                            ${r.response_status ? `<span class="badge ${responseStatusBadges[r.response_status] || 'bg-secondary'} ms-1">${esc(r.response_status)}</span>` : ''}
                        </td>
                        <td class="text-nowrap">
                            <button class="btn btn-sm btn-outline-primary btn-retail-edit" data-id="${r.id}">编辑</button>
                            <button class="btn btn-sm btn-outline-danger btn-retail-delete" data-id="${r.id}">删除</button>
                            ${retailRetryButton(r)}
                        </td>
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
                tbody.querySelectorAll('.btn-retail-edit').forEach(btn => {
                    btn.addEventListener('click', () => openRetailEdit(parseInt(btn.dataset.id)));
                });
                tbody.querySelectorAll('.btn-retail-delete').forEach(btn => {
                    btn.addEventListener('click', () => deleteRetailSingle(parseInt(btn.dataset.id)));
                });
                tbody.querySelectorAll('.btn-retail-retry').forEach(btn => {
                    btn.addEventListener('click', () => openRetailRetry(parseInt(btn.dataset.id)));
                });
            }

            renderRetailPagination(data);
        } catch (e) {
            retailTotal = 0;
            countHint.textContent = '';
            emptyEl.textContent = '清单加载失败：' + e.message;
            emptyEl.classList.remove('d-none');
        }

        updateSelection();
    }

    // "这家门店为什么不能补传"的**唯一一份说法**：按钮的禁用原因与补传弹窗的兜底拦截都读它，
    // 免得两处各写一句。原因必须**分开**（ADR 0012）："门店不在配置中 / 未声明凭据位"是配置缺口
    // （要人去查），"待配凭据"是预期内的正常状态（只是在等密钥）——混成一句"不可用"
    // 会把人引向错误的处置。返回值 null = 可以补传。
    function retailRetryBlockReason(row) {
        if (retailConfigError) {
            return '企业配置载入失败，零售补传不可用';
        }
        if (!(row.company in retailStores)) {
            return '该门店不在企业配置中，无法补传';
        }
        if (retailStores[row.company] === 'no_slot') {
            return '未声明凭据位：企业配置里这家门店没有凭据位（config/enterprises.php 缺这一项），补上才能补传';
        }
        if (retailStores[row.company] !== 'ready') {
            return '待配凭据：AppKey/SECRETKEY 尚未到手，暂时不能补传（预期内的正常状态，不是异常）';
        }
        return null;
    }

    // 行内补传/重传按钮：不可补传时禁用并把上面那句原因放进 title
    function retailRetryButton(r) {
        // 待补传的行按"补传"叫，已处理的按"重传"叫：同一件事，但后者是再来一次
        const label = r.task_status === '待补传' ? '补传' : '重传';
        const reason = retailRetryBlockReason(r);
        if (reason !== null) {
            return `<span class="d-inline-block" tabindex="0" title="${esc(reason)}">
                <button class="btn btn-sm btn-outline-secondary" disabled style="pointer-events:none">${label}</button>
            </span>`;
        }
        return `<button class="btn btn-sm btn-outline-warning btn-retail-retry" data-id="${r.id}">${label}</button>`;
    }

    // 计数取自勾选集而非 DOM——DOM 里只有当前页的行，翻页后仍在勾选集里的行数会少算
    function updateSelection() {
        document.getElementById('retail-selected-count').textContent = retailSelectedIds.size;
        const batchBtn = document.getElementById('btn-retail-batch');
        batchBtn.disabled = !retailSelectedIds.size || !batchBtn.dataset.ready;
        // 批量删除与"能不能补传"无关：未配凭据的门店照样可以清理不想要的单据
        document.getElementById('btn-retail-batch-delete').disabled = !retailSelectedIds.size;
    }

    // 通用确认弹窗（删除 / 批量删除）：与上传任务页同款
    let confirmCallback = null;

    function showConfirm(message, callback) {
        document.getElementById('confirm-message').textContent = message;
        confirmCallback = callback;
        new bootstrap.Modal(document.getElementById('confirmModal')).show();
    }

    document.getElementById('btn-confirm').addEventListener('click', async () => {
        if (confirmCallback) await confirmCallback();
        bootstrap.Modal.getInstance(document.getElementById('confirmModal')).hide();
    });

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
        if (!ids.length) { alert('请先勾选要补传的单据'); return; }

        // 二次确认：补传是对平台的真实申报，不可逆
        let confirmMsg = '确认对门店「' + company + '」的 ' + ids.length + ' 条单据发起补传？\n\n'
            + '补传是向码上放心平台的真实申报，不可逆。';
        // 已经申报成功的行（清单切到"已处理/全部"时才选得到）：重传不会改变平台上的结果，
        // 但确实是一次真实调用，混在批量里容易被顺手带过——点名，不拦（与上传任务页口径一致）
        const done = ids.filter(id => ['上传成功', '单据重复']
            .includes((retailRowIndex.get(id) || {}).response_status));
        if (done.length) {
            confirmMsg += '\n\n⚠️ 本批含 ' + done.length + ' 条已申报成功的单据：'
                + '重传会在平台上再次申报（平台多半回"单据重复"）。若非刻意为之，请先把它们从勾选里去掉。';
        }
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
                body: JSON.stringify({ids, company}),
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

    // ── 行操作：编辑 / 删除 / 补传（重传） ──

    // 行上的类型若不在下拉的那四个里（理论上不该有：采集口径只写 104/203/321/116），补一个选项出来。
    // 否则 <select> 赋值失败会静默落回空值，保存时把单据类型抹掉还没人察觉
    // （上传任务页的编辑弹窗对"不在配置中的企业"用的是同一手法）
    function ensureRetailBillTypeOption(select, billType) {
        if (!billType) return;
        if (Array.from(select.options).some(o => o.value === billType)) return;
        const opt = document.createElement('option');
        opt.value = billType;
        opt.textContent = billType + '（不在门店单据类型里）';
        select.appendChild(opt);
    }

    function openRetailEdit(id) {
        const row = retailRowIndex.get(id);   // 行数据来自列表（**跨页索引**），不必再拉一次单条
        if (!row) { alert('未找到该任务，请刷新后重试'); return; }
        document.getElementById('re-id').value = row.id;
        document.getElementById('re-rq').value = row.rq || '';
        document.getElementById('re-djbh').value = row.djbh || '';
        document.getElementById('re-trace-codes').value = row.trace_codes || '';
        const typeSelect = document.getElementById('re-bill-type');
        ensureRetailBillTypeOption(typeSelect, row.bill_type);
        typeSelect.value = row.bill_type || '';
        new bootstrap.Modal(document.getElementById('retailEditModal')).show();
    }

    document.getElementById('btn-save-retail-edit').addEventListener('click', async function() {
        // 只送这四个字段。**不送 ent_name**：门店单的往来单位本就是空（对手方是源表里的平台 ID），
        // 服务端 PUT 会把没送的 ent_name 写成空串——对零售行等于原值，不会改坏东西。
        // **不送 company**：本分支里改它会把这一行挪出当前门店的清单，那不是"编辑这张单"的语义。
        const payload = {
            id: document.getElementById('re-id').value,
            rq: document.getElementById('re-rq').value,
            djbh: document.getElementById('re-djbh').value,
            trace_codes: document.getElementById('re-trace-codes').value,
            bill_type: document.getElementById('re-bill-type').value,
        };
        const btn = this;
        btn.disabled = true;
        try {
            const resp = await fetch('index.php?page=api&action=tasks', {
                method: 'PUT',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload),
            });
            const result = await resp.json();
            if (!result.success) { alert('保存失败: ' + (result.error || '未知错误')); return; }
            bootstrap.Modal.getInstance(document.getElementById('retailEditModal')).hide();
            loadRetailTasks(currentCompany());
        } catch (e) {
            alert('保存失败: ' + e.message);
        } finally {
            btn.disabled = false;
        }
    });

    // 删除类请求的统一出口：fetch 只在网络层失败时 reject，401/500 也会正常返回，
    // 故**必须**自己看状态码与 success 字段——不查的话失败会被当成成功，勾选集随即被清掉、
    // 清单也重拉了（行还在，人只会以为是自己没刷新）
    async function deleteRequest(url, options) {
        const resp = await fetch(url, options);
        const result = await resp.json().catch(() => null);
        if (!resp.ok || !(result && result.success)) {
            throw new Error((result && result.error) || ('HTTP ' + resp.status));
        }
        return result;
    }

    function deleteRetailSingle(id) {
        showConfirm('确定要删除该补传任务吗？', async () => {
            try {
                await deleteRequest('index.php?page=api&action=tasks&id=' + id, { method: 'DELETE' });
                retailSelectedIds.delete(id);   // 勾选集里也要撤掉，否则它会一直占着"已选 N 条"
                retailRowIndex.delete(id);
                loadRetailTasks(currentCompany());
            } catch (e) {
                alert('删除失败: ' + e.message);
            }
        });
    }

    let retailRetryTaskId = null;

    // 单条补传：先摆出这张单的元数据再确认——补传不可逆，操作者要看清是哪张单
    function openRetailRetry(id) {
        const row = retailRowIndex.get(id);
        if (!row) { alert('未找到该任务，请刷新后重试'); return; }
        // 按钮本身在不可补传时已是禁用态，这里再拦一次：绕开按钮直接调用的路径也该被挡住。
        // 原因用与按钮**同一份**说法，不再另写一句合并版（ADR 0012 要求三态分开）
        const reason = retailRetryBlockReason(row);
        if (reason !== null) { alert(reason); return; }
        document.getElementById('rr-djbh').textContent = row.djbh;
        document.getElementById('rr-rq').textContent = row.rq || '-';
        document.getElementById('rr-bill-type').textContent = billTypeLabels[row.bill_type] || row.bill_type || '-';
        document.getElementById('rr-company').textContent = row.company;
        // 码数直接读服务端的 code_count：与"码数"列同一个数（这里曾用 split(',').length 另算一遍，
        // 两处会在边界上各说各话），也不再受"空串 split 出 1"的影响
        document.getElementById('rr-codes').textContent = row.code_count + ' 个';
        retailRetryTaskId = id;
        new bootstrap.Modal(document.getElementById('retailRetryModal')).show();
    }

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
            // 入参只有 id：单据元数据一律取自落库行，凭据由服务端按门店取（ADR 0011 / 0012）
            await streamFetch('index.php?page=api&action=tasks_retry_retail', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id}),
            }, logEl, summaryEl, titleEl, {doneTitle: '补传完成'});
        } catch (e) {
            appendLog(logEl, 'error', '请求失败: ' + e.message);
        } finally {
            retailSelectedIds.delete(id);
            loadRetailTasks(currentCompany());
        }
    });

    // ── 工具栏：导出 / 刷新 / 批量删除 / 筛选 ──

    // 导出 xlsx：按当前筛选条件**全量**导出（不是当页），无数据时不发请求
    async function exportRetailXlsx() {
        if (!retailTotal) { alert('当前筛选条件下无数据可导出'); return; }
        const btn = document.getElementById('btn-retail-export');
        const params = retailFilterParams();   // 与列表同一套筛选参数，只多一个 type
        params.set('type', 'retail_tasks');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '导出中...';
        try {
            const resp = await fetch('index.php?page=api&action=export&' + params.toString());
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
    document.getElementById('btn-retail-export').addEventListener('click', exportRetailXlsx);

    document.getElementById('btn-retail-refresh').addEventListener('click', () => loadRetailTasks(currentCompany()));

    // 批量删除：作用于**跨页**勾选集（与批量补传同一个集合）
    document.getElementById('btn-retail-batch-delete').addEventListener('click', () => {
        const ids = Array.from(retailSelectedIds);
        if (!ids.length) return;
        showConfirm('确定要删除选中的 ' + ids.length + ' 条任务吗？\n\n'
            + '删除只作用于本地的任务行：该单据若仍未被外部系统上传，且覆盖它那个日期的采集再次运行，'
            + '它会被重新采集入库。', async () => {
            try {
                await deleteRequest('index.php?page=api&action=tasks_batch_delete', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ids: ids}),
                });
                retailSelectedIds.clear();
                ids.forEach(id => retailRowIndex.delete(id));
                document.getElementById('retail-check-all').checked = false;
                loadRetailTasks(currentCompany());
            } catch (e) {
                alert('批量删除失败: ' + e.message);
            }
        });
    });

    // 筛选：输入防抖、下拉与日期即改即查，任一变化都回到第 1 页
    let retailSearchTimeout;
    ['retail-filter-djbh', 'retail-filter-task-status', 'retail-filter-response-status'].forEach(id => {
        document.getElementById(id).addEventListener('input', () => {
            clearTimeout(retailSearchTimeout);
            retailSearchTimeout = setTimeout(() => { currentRetailPage = 1; loadRetailTasks(currentCompany()); }, 400);
        });
        document.getElementById(id).addEventListener('change', () => { currentRetailPage = 1; loadRetailTasks(currentCompany()); });
    });
    fpRetailRq.config.onChange.push(() => { currentRetailPage = 1; loadRetailTasks(currentCompany()); });
    fpRetailCreated.config.onChange.push(() => { currentRetailPage = 1; loadRetailTasks(currentCompany()); });

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
