<?php
/**
 * App\RetailManualEntry 单元测试（自包含断言脚本，无框架依赖）
 *
 * 运行: php tests/retail_manual_test.php
 *
 * 测试目标：门店手工建单的三条**错了就把单据报到错误主体**的规则，以及它触网之前的那批拒绝：
 *
 *   1. 哪两种单据要"往来单位名称"（104/203 走 uploadinoutbill，from/toUserId 是平台必填；
 *      321/116 走 uploadretail，接口里根本没有对手方入参）——页面的显隐与后端校验读同一个判据
 *   2. 对手方的 entId 落在 from 还是 to：**按 SDK docblock 的发货/收货语义**（104 本店收货 →
 *      from=对方、to=本店；203 本店发货 → from=本店、to=对方）。采集单是照搬源表、不按语义翻转
 *      （ADR 0010 的待确认项），两者对 203 可能不同——这条不一致写在 ADR 0015 里，本测试钉的是
 *      **手工建单这一侧**的取值
 *   3. prepare() 在落库与平台调用**之前**把不合格的单据全挡掉（校验、凭据三态、2 年下限）
 *
 * 全部使用固化 fixture，取值一律占位符：**不读生产配置、不抄真实门店平台 ID/AppKey、不连数据库、
 * 不调平台接口**。321/116 的 prepare() 不触网（它们没有对手方要查），故成功路径也能离线测；
 * 104/203 的成功路径必然要查平台，本测试只覆盖它"查之前"的部分与纯函数部分（端到端装配另用
 * 构造好的单据走 RetailRequestAssembler）。
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Enterprise;
use App\RetailManualEntry;
use App\RetailRequestAssembler;
use App\RetailRetention;

$failures = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    global $failures;
    if ($cond) {
        echo "PASS  $name\n";
    } else {
        $failures++;
        echo "FAIL  $name  $detail\n";
    }
}

/** 断言调用抛异常，且消息含关键词（needle 为空则只看抛没抛） */
function checkThrows(string $name, callable $fn, string $needle = ''): void
{
    global $failures;
    try {
        $fn();
        $failures++;
        echo "FAIL  $name  未抛异常\n";
    } catch (\Throwable $e) {
        if ($needle === '' || str_contains($e->getMessage(), $needle)) {
            echo "PASS  $name\n";
        } else {
            $failures++;
            echo "FAIL  $name  异常消息不含「{$needle}」: {$e->getMessage()}\n";
        }
    }
}

// ================= fixture：结构（= enterprises.php）与取值（= enterprises.local.php）分离 =================
// 取值全是占位符：真实门店平台 ID / AppKey 属凭据级信息（config/enterprises.local.php 不入 git）
$structure = ['companies' => [
    [
        'key' => 'ws', 'name' => '批发企业', 'type' => 'wholesale',
        'credentials' => ['main' => ['label' => '主主体']],
    ],
    [
        // 形状对齐真实的新江分店：ref_ent_id ≠ ent_id——两个 ID 相同时"对手方落在哪一端"的断言
        // 会被"本店 ID 恰好等于对方 ID"这种巧合蒙混过去
        'key' => 'xj', 'name' => '门店甲', 'type' => 'retail',
        'credentials' => ['main' => ['label' => '主授权']],
    ],
    [
        // 凭据位已声明、密钥还没到手（"待配凭据"是合法状态）：建单必须被拒
        'key' => 's3', 'name' => '门店丙', 'type' => 'retail',
        'credentials' => ['main' => ['label' => '主授权']],
    ],
    [
        // 连凭据位都没声明：与"待配凭据"是两回事（一个是配置缺口、一个在等密钥）
        'key' => 's4', 'name' => '门店丁', 'type' => 'retail',
        'credentials' => [],
    ],
]];

$local = [
    'ids' => [
        'ws' => ['WSREF', 'WSENT'],
        'xj' => ['XJ-REF', 'XJ-ENT'],
        's3' => ['S3-ID'],
        's4' => ['S4-ID'],
    ],
    'credentials' => [
        'ws' => ['main' => ['appkey' => 'ws-appkey', 'secretkey' => 'ws-secret', 'ref_ent_id' => 'WSREF', 'ent_id' => 'WSENT']],
        'xj' => ['main' => ['appkey' => 'xj-appkey', 'secretkey' => 'xj-secret', 'ref_ent_id' => 'XJ-REF', 'ent_id' => 'XJ-ENT']],
    ],
];

Enterprise::reset();
Enterprise::load($structure, $local);

$company = '门店甲';
$credential = Enterprise::credential($company, 'main');
$partnerEntId = 'PARTNER-ENT-ID';

/** 手工建单的输入（六项，prepared 之前的形状） */
function manualBill(string $billType, string $djbh, array $overrides = []): array
{
    return array_merge([
        'company' => '门店甲',
        'rq' => '2026-09-28',
        'djbh' => $djbh,
        'bill_type' => $billType,
        'ent_name' => '对方单位',
        'trace_codes' => 'TEST-CODE-001,TEST-CODE-002',
    ], $overrides);
}

// ---------- 用例 0: fixture 自检 ----------
check('fixture: 门店甲 ref_ent_id ≠ ent_id（落位取错一端才会被抓住）',
    $credential['ref_ent_id'] !== $credential['ent_id'],
    $credential['ref_ent_id'] . ' vs ' . $credential['ent_id']);

// ---------- 用例 1: 哪两种单据要往来单位名称 ----------
check('104 调拨入库要往来单位名称', RetailManualEntry::needsCounterparty('104') === true);
check('203 调拨出库要往来单位名称', RetailManualEntry::needsCounterparty('203') === true);
check('321 使用出库不需要（对手是消费者，接口里没有对手方入参）',
    RetailManualEntry::needsCounterparty('321') === false);
check('116 消费者退货入库不需要', RetailManualEntry::needsCounterparty('116') === false);

// ---------- 用例 2: 对手方落在 from 还是 to ----------
$e104 = RetailManualEntry::endpoints('104', $credential['ent_id'], $partnerEntId);
check('104 调拨入库：from=对方、to=本店', $e104 === ['from_user_id' => $partnerEntId, 'to_user_id' => $credential['ent_id']],
    json_encode($e104, JSON_UNESCAPED_UNICODE));

$e203 = RetailManualEntry::endpoints('203', $credential['ent_id'], $partnerEntId);
check('203 调拨出库：from=本店、to=对方', $e203 === ['from_user_id' => $credential['ent_id'], 'to_user_id' => $partnerEntId],
    json_encode($e203, JSON_UNESCAPED_UNICODE));

checkThrows('321 没有对手方可言——调用 endpoints() 即抛（而不是返回一对没意义的 ID）',
    fn() => RetailManualEntry::endpoints('321', $credential['ent_id'], $partnerEntId));

// ---------- 用例 3: prepare() 的拒绝分支（全在落库与平台调用之前） ----------
checkThrows('批发企业不能建门店单据', fn() => RetailManualEntry::prepare(manualBill('321', 'X', ['company' => '批发企业'])), '不是门店');
checkThrows('不在配置里的企业不能建单', fn() => RetailManualEntry::prepare(manualBill('321', 'X', ['company' => '查无此店'])), '不是门店');
checkThrows('单号不能为空', fn() => RetailManualEntry::prepare(manualBill('321', '')), '单号不能为空');
checkThrows('日期格式必须是 YYYY-MM-DD',
    fn() => RetailManualEntry::prepare(manualBill('321', 'X', ['rq' => '2026/09/28'])), 'YYYY-MM-DD');
checkThrows('超过 2 年保留期的日期直接拒（与采集同一个 RetailRetention）',
    fn() => RetailManualEntry::prepare(manualBill('321', 'X', ['rq' => date('Y-m-d', strtotime(RetailRetention::cutoffDate() . ' -1 day'))])),
    '保留期');
checkThrows('批发类型（201）不是门店单据类型',
    fn() => RetailManualEntry::prepare(manualBill('201', 'X')), '不是门店单据类型');
checkThrows('未知类型（999）也不是', fn() => RetailManualEntry::prepare(manualBill('999', 'X')), '不是门店单据类型');
checkThrows('追溯码不能为空', fn() => RetailManualEntry::prepare(manualBill('321', 'X', ['trace_codes' => ''])), '追溯码不能为空');
checkThrows('104 缺往来单位名称即拒（它的 from/to 由这个名字查出）',
    fn() => RetailManualEntry::prepare(manualBill('104', 'X', ['ent_name' => ''])), '需要往来单位名称');
checkThrows('待配凭据的门店不能建单',
    fn() => RetailManualEntry::prepare(manualBill('321', 'X', ['company' => '门店丙'])), '尚未配齐');
checkThrows('没声明凭据位的门店不能建单',
    fn() => RetailManualEntry::prepare(manualBill('321', 'X', ['company' => '门店丁'])), '没有声明凭据位');

// ---------- 用例 4: prepare() 成功路径——321/116 不触网，可离线验证 ----------
$prepared = RetailManualEntry::prepare(manualBill('321', 'TEST-321-001'));

check('落库主体是所选门店', $prepared['company'] === $company);
check('凭据取该门店那套（不由调用方给）', $prepared['credential_key'] === 'main');
check('单据类型归一为 3 位码', $prepared['bill_type'] === '321');
// 对手方三列对 321 恒为空：接口没有对手方入参，留个值只会让"这条单的对手是谁"出现第二种说法
check('321 的 from/to/physicType 三列为空',
    $prepared['from_user_id'] === '' && $prepared['to_user_id'] === '' && $prepared['physic_type'] === '',
    json_encode([$prepared['from_user_id'], $prepared['to_user_id'], $prepared['physic_type']]));
check('321 不存往来单位名称（采集行也没有）', $prepared['ent_name'] === '');

// 保留期截止日当天仍可建单（"早于它才超期"——与 RetailRetention 同一条边界）
$onCutoff = RetailManualEntry::prepare(manualBill('321', 'TEST-321-CUTOFF', ['rq' => RetailRetention::cutoffDate()]));
check('保留期截止日当天可建单', $onCutoff['rq'] === RetailRetention::cutoffDate());

// ---------- 用例 5: 手工建单的单据能装配成请求（端到端形状，不触网） ----------
// 321：走 retail 接口，只填必填项，不出现任何对手方字段
$assembled = RetailRequestAssembler::assemble($prepared, $company, $prepared['credential']);
check('321 装配成功（check() 通过）', $assembled['limit'] === 3500, 'limit=' . $assembled['limit']);
check('321 的 refUserId 取凭据的 ref_ent_id（不是 ent_id）',
    $assembled['request']->getApiParas()['ref_user_id'] === $credential['ref_ent_id'],
    json_encode($assembled['request']->getApiParas(), JSON_UNESCAPED_UNICODE));
check('321 请求里没有对手方字段',
    !array_key_exists('from_user_id', $assembled['request']->getApiParas()));

// 104：对手方由 endpoints() 落位后交装配——这正是 create() 落库时写进 from/to 两列的值
$endpoints = RetailManualEntry::endpoints('104', $credential['ent_id'], $partnerEntId);
$assembled104 = RetailRequestAssembler::assemble([
    'djbh' => 'TEST-104-001',
    'rq' => '2026-09-28',
    'bill_type' => '104',
    'trace_codes' => 'TEST-CODE-001',
    'from_user_id' => $endpoints['from_user_id'],
    'to_user_id' => $endpoints['to_user_id'],
    'physic_type' => RetailManualEntry::PHYSIC_TYPE,
], $company, $credential);
$paras104 = $assembled104['request']->getApiParas();
check('104 装配成功，码上限取路由（uploadinoutbill = 10000）', $assembled104['limit'] === 10000, 'limit=' . $assembled104['limit']);
check('104 的 fromUserId = 对方 ent_id', $paras104['from_user_id'] === $partnerEntId, json_encode($paras104, JSON_UNESCAPED_UNICODE));
check('104 的 toUserId = 本店 ent_id', $paras104['to_user_id'] === $credential['ent_id'], json_encode($paras104, JSON_UNESCAPED_UNICODE));
// 取值写死 '3' 而不是拿常量自己比自己：后者在常量被改成别的值时照样 PASS，
// 等于没测（变异验证时就是这么发现的）——源表实测全表恒为 3，批发链路也硬编码 "3"
check('物理类型常量为 3（源表 zsm_ls.physic_type 实测恒为 3）', RetailManualEntry::PHYSIC_TYPE === '3');
check('104 的 physicType 落成 3', $paras104['physic_type'] === '3');
check('104 的 clientType 为 2', $paras104['client_type'] === '2');

echo "\n";
if ($failures === 0) {
    echo "全部通过 ✓\n";
    exit(0);
}
echo "失败 $failures 项 ✗\n";
exit(1);
