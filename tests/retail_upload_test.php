<?php
/**
 * App\RetailRequestAssembler 单元测试（自包含断言脚本，无框架依赖）
 *
 * 运行: php tests/retail_upload_test.php
 *
 * 测试目标：零售补传的请求装配——给定一条零售单据的数据 + 一套门店凭据，装配出的 lsyd 请求对象
 * 参数完全正确，且**不发起任何网络调用**（纯函数）。
 *
 * 判据取自请求类自己的 check()（由平台"是否必填"元数据生成），不在本测试里手抄必填清单：
 *   - 装配后 check() 不抛 → 必填项齐备
 *   - 把 getApiParas() 里任一项清空后 check() 必抛 → 装配只填了必填项，没有多填可选字段
 *     （多填一个可选项时它清空后 check() 不抛，本测试即报 FAIL；少填比填错安全）
 *
 * 全部使用固化 fixture，取值一律占位符：**不读生产配置、不抄真实门店平台 ID/AppKey、不连数据库、不调平台接口**。
 * 映射规则与那条待确认项见 docs/adr/0010-retail-request-parameter-mapping.md。
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Enterprise;
use App\RetailRequestAssembler;

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

/** 断言调用不抛异常 */
function checkNoThrow(string $name, callable $fn): void
{
    global $failures;
    try {
        $fn();
        echo "PASS  $name\n";
    } catch (\Throwable $e) {
        $failures++;
        echo "FAIL  $name  抛了异常: {$e->getMessage()}\n";
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

/**
 * 装配后填进 apiParas 的每一项，清空后 check() 必须抛异常。
 *
 * 必填项清单来自请求对象自己（getApiParas()），不手抄，因而不会随 SDK 升级失真；
 * 顺带钉住"只填必填项、不多填可选字段"——多填的项清空后 check() 不会抛，这里就报 FAIL。
 */
function checkOnlyRequiredParas(string $label, $req): void
{
    $paras = $req->getApiParas();
    check("{$label}: 装配确实填了参数", count($paras) > 0, 'apiParas 为空');

    foreach ($paras as $key => $value) {
        // apiParas 键名 → setter：client_type → setClientType（SDK 生成器规则）
        $setter = 'set' . str_replace('_', '', ucwords($key, '_'));
        if (!method_exists($req, $setter)) {
            check("{$label}: {$key} 有对应 setter", false, "找不到 {$setter}");
            continue;
        }
        $req->$setter('');
        try {
            $req->check();
            check("{$label}: {$key} 清空后 check() 抛异常", false, '未抛——它不是必填项，装配不该填它');
        } catch (\Exception $e) {
            check("{$label}: {$key} 清空后 check() 抛异常", true);
        }
        $req->$setter($value); // 恢复原值
    }
}

// ================= fixture：结构（= enterprises.php）与取值（= enterprises.local.php）分离 =================
// 取值全是占位符：真实门店平台 ID / AppKey 属凭据级信息（config/enterprises.local.php 不入 git）
$structure = ['companies' => [
    [
        'key' => 'ws', 'name' => '批发企业', 'type' => 'wholesale',
        'credentials' => ['main' => ['label' => '主主体', 'primary' => true]],
    ],
    [
        // 形状对齐真实的新江分店：15 家里唯一 ref_ent_id ≠ ent_id 的门店（旧 entId 用到 2025-07、
        // 新 refEntId 自 2024-03 起，两个 ID 都在源库出现）。取值仍是占位符——
        // 用两值相同的门店做用例，refUserId 取错成 ent_id 也测不出来，等于没测。
        'key' => 'xj', 'name' => '门店甲', 'type' => 'retail',
        'credentials' => ['main' => ['label' => '主授权', 'primary' => true]],
    ],
    [
        'key' => 's2', 'name' => '门店乙', 'type' => 'retail',
        'credentials' => ['main' => ['label' => '主授权']],
    ],
    [
        // 凭据位已声明、密钥还没到手（"待配凭据"是合法状态）：单据能认领、装配必须拒
        'key' => 's3', 'name' => '门店丙', 'type' => 'retail',
        'credentials' => ['main' => ['label' => '主授权']],
    ],
]];

$local = [
    'ids' => [
        'ws' => ['WSREF', 'WSENT'],
        'xj' => ['XJ-REF', 'XJ-ENT'],
        's2' => ['S2-SAME'],
        's3' => ['S3-ID'],
    ],
    'credentials' => [
        'ws' => ['main' => ['appkey' => 'ws-appkey', 'secretkey' => 'ws-secret', 'ref_ent_id' => 'WSREF', 'ent_id' => 'WSENT']],
        'xj' => ['main' => ['appkey' => 'xj-appkey', 'secretkey' => 'xj-secret', 'ref_ent_id' => 'XJ-REF', 'ent_id' => 'XJ-ENT']],
        's2' => ['main' => ['appkey' => 's2-appkey', 'secretkey' => 's2-secret', 'ref_ent_id' => 'S2-SAME', 'ent_id' => 'S2-SAME']],
    ],
];

Enterprise::reset();
Enterprise::load($structure, $local);

$company = '门店甲';
$credential = Enterprise::credential($company, 'main');
$credentialS2 = Enterprise::credential('门店乙', 'main');

/**
 * 单据 fixture：键名沿用落库任务列 + 源表同名列。
 *
 * from_user_id / to_user_id 取**不同值**：两列若被互换实现也能通过测试就白测了
 * （票面明确这两列照搬源表，不随调拨方向翻转——那条待确认项见 ADR 0010）。
 */
function bill(string $billType, string $djbh, string $traceCodes = 'TEST-CODE-001,TEST-CODE-002'): array
{
    return [
        'djbh' => $djbh,
        'rq' => '2026-09-28',
        'bill_type' => $billType,
        'trace_codes' => $traceCodes,
        'from_user_id' => 'TEST-HQ-ID',
        'to_user_id' => 'XJ-ENT',
        'physic_type' => '3',
    ];
}

// ---------- 用例 0: fixture 自检（辨别力保障） ----------
check('fixture: 门店甲 ref_ent_id ≠ ent_id（refUserId 取错成 ent_id 才会被抓住）',
    $credential['ref_ent_id'] !== $credential['ent_id'],
    $credential['ref_ent_id'] . ' vs ' . $credential['ent_id']);
check('fixture: 单据 from_user_id ≠ to_user_id（两列互换才会被抓住）',
    bill('104', 'X')['from_user_id'] !== bill('104', 'X')['to_user_id']);

// ---------- 用例 1: uploadinoutbill（104 调拨入库） ----------
$r = RetailRequestAssembler::assemble(bill('104', 'TEST-DJBH-104'), $company, $credential);
$req = $r['request'];
$paras = $req->getApiParas();

check('104 装配出 lsyd.uploadinoutbill 请求对象',
    $req instanceof \AlibabaAlihealthDrugtraceTopLsydUploadinoutbillRequest,
    get_class($req));
check('104: bill_code = 单号', ($paras['bill_code'] ?? '') === 'TEST-DJBH-104', (string)($paras['bill_code'] ?? ''));
check('104: bill_time = 单据日期', ($paras['bill_time'] ?? '') === '2026-09-28');
check('104: bill_type = 104（数字码直通）', ($paras['bill_type'] ?? '') === '104');
check('104: client_type = "2"', ($paras['client_type'] ?? '') === '2', var_export($paras['client_type'] ?? null, true));
check('104: physic_type = 源表 physic_type 列', ($paras['physic_type'] ?? '') === '3');
check('104: ref_user_id = 凭据 ref_ent_id（不是源表 ref_ent_id，也不是 ent_id）',
    ($paras['ref_user_id'] ?? '') === $credential['ref_ent_id'], (string)($paras['ref_user_id'] ?? ''));
check('104: ref_user_id ≠ 凭据 ent_id（新江形状下取错即红）',
    ($paras['ref_user_id'] ?? '') !== $credential['ent_id']);
check('104: from_user_id 照搬源表 from_user_id', ($paras['from_user_id'] ?? '') === 'TEST-HQ-ID');
check('104: to_user_id 照搬源表 to_user_id', ($paras['to_user_id'] ?? '') === 'XJ-ENT');
check('104: trace_codes 原样传入', ($paras['trace_codes'] ?? '') === 'TEST-CODE-001,TEST-CODE-002');
check('104: 码上限取自路由 = 10000（不是全局常量 3500）', $r['limit'] === 10000, (string)$r['limit']);
checkNoThrow('104: 必填项齐备，check() 通过', fn() => $req->check());
checkOnlyRequiredParas('104', $req);

// ---------- 用例 2: uploadinoutbill（203 调拨出库） ----------
// ⚠️ 本条断言对应 ADR 0010 里那条**待确认项**：SDK docblock 把 fromUserId/toUserId 写作
// "发货企业 entId"/"收货企业 entId"（发货/收货语义），而源表 104/203 的 from_user_id 都只有总部一个值、
// to_user_id 才是门店（不随调拨方向翻转）。当前实现按用户 2026-09-29 定案**照搬源表同名列**；
// 若外部系统工程师确认 203 应反向，只改本用例与装配里那两行的取值来源。
$r = RetailRequestAssembler::assemble(bill('203', 'TEST-DJBH-203'), $company, $credential);
$paras = $r['request']->getApiParas();

check('203 装配出 lsyd.uploadinoutbill 请求对象',
    $r['request'] instanceof \AlibabaAlihealthDrugtraceTopLsydUploadinoutbillRequest);
check('203: bill_type = 203', ($paras['bill_type'] ?? '') === '203');
check('203: client_type = "2" / physic_type 非空',
    ($paras['client_type'] ?? '') === '2' && ($paras['physic_type'] ?? '') !== '');
check('203: ref_user_id = 凭据 ref_ent_id', ($paras['ref_user_id'] ?? '') === $credential['ref_ent_id']);
check('203: from_user_id / to_user_id 照搬源表列（暂定：不按发货/收货语义反向）',
    ($paras['from_user_id'] ?? '') === 'TEST-HQ-ID' && ($paras['to_user_id'] ?? '') === 'XJ-ENT');
check('203: 码上限取自路由 = 10000', $r['limit'] === 10000, (string)$r['limit']);
checkNoThrow('203: 必填项齐备，check() 通过', fn() => $r['request']->check());
checkOnlyRequiredParas('203', $r['request']);

// ---------- 用例 3: uploadretail（321 使用出库） ----------
$r = RetailRequestAssembler::assemble(bill('321', 'TEST-DJBH-321'), $company, $credential);
$req = $r['request'];
$paras = $req->getApiParas();

check('321 装配出 lsyd.uploadretail 请求对象',
    $req instanceof \AlibabaAlihealthDrugtraceTopLsydUploadretailRequest,
    get_class($req));
check('321: bill_code / bill_time / bill_type 齐备',
    ($paras['bill_code'] ?? '') === 'TEST-DJBH-321'
    && ($paras['bill_time'] ?? '') === '2026-09-28'
    && ($paras['bill_type'] ?? '') === '321');
check('321: ref_user_id = 凭据 ref_ent_id', ($paras['ref_user_id'] ?? '') === $credential['ref_ent_id']);
check('321: trace_codes 原样传入', ($paras['trace_codes'] ?? '') === 'TEST-CODE-001,TEST-CODE-002');
check('321: **不含** client_type（该类里根本没有这个字段）', !array_key_exists('client_type', $paras));
check('321: **不含** from_user_id（docblock 写明可为空，本轮不填）', !array_key_exists('from_user_id', $paras));
check('321: 不含 to_user_id（该类无此字段）', !array_key_exists('to_user_id', $paras));
check('321: 不含 physic_type（不填，docblock：上传后以实际为准）', !array_key_exists('physic_type', $paras));
check('321: 码上限取自路由 = 3500', $r['limit'] === 3500, (string)$r['limit']);
checkNoThrow('321: 必填项齐备，check() 通过', fn() => $req->check());
checkOnlyRequiredParas('321', $req);

// ---------- 用例 4: uploadretail（116 消费者退货入库） ----------
$r = RetailRequestAssembler::assemble(bill('116', 'TEST-DJBH-116'), $company, $credential);
$paras = $r['request']->getApiParas();

check('116 装配出 lsyd.uploadretail 请求对象',
    $r['request'] instanceof \AlibabaAlihealthDrugtraceTopLsydUploadretailRequest);
check('116: bill_type = 116 且不含 client_type',
    ($paras['bill_type'] ?? '') === '116' && !array_key_exists('client_type', $paras));
check('116: ref_user_id = 凭据 ref_ent_id', ($paras['ref_user_id'] ?? '') === $credential['ref_ent_id']);
check('116: 码上限取自路由 = 3500', $r['limit'] === 3500, (string)$r['limit']);
checkNoThrow('116: 必填项齐备，check() 通过', fn() => $r['request']->check());
checkOnlyRequiredParas('116', $r['request']);

// ---------- 用例 5: 装配取的就是凭据 ref_ent_id（不是"两值里挑不同的那个"） ----------
// 门店乙的 ref_ent_id == ent_id：装配必须照样取 ref_ent_id，实现若靠"猜哪个不同"就会露馅
$r = RetailRequestAssembler::assemble(bill('321', 'TEST-DJBH-S2'), '门店乙', $credentialS2);
$paras = $r['request']->getApiParas();
check('门店乙（ref_ent_id == ent_id）同样按 ref_ent_id 取',
    ($paras['ref_user_id'] ?? '') === $credentialS2['ref_ent_id']
    && $credentialS2['ref_ent_id'] === $credentialS2['ent_id']);

// ---------- 用例 6: 路由按 (企业类型, 单据类型) 走，不是硬编码 ----------
foreach ([
    '104' => \AlibabaAlihealthDrugtraceTopLsydUploadinoutbillRequest::class,
    '203' => \AlibabaAlihealthDrugtraceTopLsydUploadinoutbillRequest::class,
    '321' => \AlibabaAlihealthDrugtraceTopLsydUploadretailRequest::class,
    '116' => \AlibabaAlihealthDrugtraceTopLsydUploadretailRequest::class,
] as $billType => $expectedClass) {
    $route = Enterprise::route($company, $billType);
    check("路由 {$billType} → " . basename(str_replace('\\', '/', $expectedClass)),
        ($route['class'] ?? '') === $expectedClass, (string)($route['class'] ?? 'null'));
    $assembled = RetailRequestAssembler::assemble(bill((string)$billType, "TEST-DJBH-{$billType}"), $company, $credential);
    check("装配 {$billType} 用的就是路由给的类", get_class($assembled['request']) === $expectedClass,
        get_class($assembled['request']));
}

// ---------- 用例 7: 码上限与 SDK 自己的 checkMaxListSize 一致（不硬编码 SDK 侧上限） ----------
// 路由表里的上限若与 SDK 的 checkMaxListSize 不一致，装配会把平台必定拒绝的单据送出去
foreach ([
    ['104', 10000],
    ['321', 3500],
] as [$billType, $limit]) {
    $atLimit = implode(',', array_fill(0, $limit, 'TEST-CODE'));
    $overLimit = $atLimit . ',TEST-CODE-EXTRA';

    checkNoThrow("{$billType}: 恰好 {$limit} 个码 → 装配通过（上限取自路由且与 SDK 一致）",
        fn() => RetailRequestAssembler::assemble(bill($billType, "TEST-DJBH-{$billType}-FULL", $atLimit), $company, $credential));
    checkThrows("{$billType}: " . ($limit + 1) . " 个码 → 装配拒绝",
        fn() => RetailRequestAssembler::assemble(bill($billType, "TEST-DJBH-{$billType}-OVER", $overLimit), $company, $credential),
        '未通过 SDK 校验');
}

// ---------- 用例 8: fail-closed（缺项/越界一律拒绝，不猜） ----------
$wholesaleCredential = Enterprise::credential('批发企业', 'main');

checkThrows('批发企业 → 拒绝（本装配只做零售 lsyd）',
    fn() => RetailRequestAssembler::assemble(bill('201', 'TEST-DJBH-201'), '批发企业', $wholesaleCredential),
    '不是零售企业');

checkThrows('未识别企业 → 拒绝（不等同于"随便挑个主体"）',
    fn() => RetailRequestAssembler::assemble(bill('321', 'TEST-DJBH-UNKNOWN'), Enterprise::UNIDENTIFIED, $credential),
    '不是零售企业');

checkThrows('零售未知单据类型 999 → 拒绝（无路由不猜）',
    fn() => RetailRequestAssembler::assemble(bill('999', 'TEST-DJBH-999'), $company, $credential),
    '没有接口路由');

checkThrows('缺 from_user_id → 拒绝（uploadinoutbill 必填）',
    fn() => RetailRequestAssembler::assemble(
        ['djbh' => 'TEST-DJBH-NOFROM', 'rq' => '2026-09-28', 'bill_type' => '104', 'trace_codes' => 'TEST-CODE-001',
         'to_user_id' => 'XJ-ENT', 'physic_type' => '3'],
        $company, $credential
    ),
    '未通过 SDK 校验');

checkThrows('缺 physic_type → 拒绝（docblock 说可不填，check() 强制非空——以 check() 为准）',
    fn() => RetailRequestAssembler::assemble(
        ['djbh' => 'TEST-DJBH-NOPHYSIC', 'rq' => '2026-09-28', 'bill_type' => '104', 'trace_codes' => 'TEST-CODE-001',
         'from_user_id' => 'TEST-HQ-ID', 'to_user_id' => 'XJ-ENT'],
        $company, $credential
    ),
    '未通过 SDK 校验');

checkThrows('缺 trace_codes → 拒绝',
    fn() => RetailRequestAssembler::assemble(
        ['djbh' => 'TEST-DJBH-NOCODE', 'rq' => '2026-09-28', 'bill_type' => '321', 'trace_codes' => ''],
        $company, $credential
    ),
    '未通过 SDK 校验');

$pendingCredential = Enterprise::credential('门店丙', 'main');
check('fixture: 门店丙是待配凭据（有凭据位、四字段未填）',
    $pendingCredential !== null && !Enterprise::credentialConfigured($pendingCredential));

checkThrows('凭据未配（待配凭据门店）→ 拒绝（refUserId 取不到值，不猜）',
    fn() => RetailRequestAssembler::assemble(
        bill('321', 'TEST-DJBH-NOCRED'),
        '门店丙',
        $pendingCredential
    ),
    '未通过 SDK 校验');

// 凭据归属：(企业, 凭据) 是调用方给的两个独立参数，配错即错主体（refUserId 取的是凭据的 ref_ent_id）
checkThrows('拿批发企业的凭据装配门店单据 → 拒绝（否则 refUserId 会是总部主体）',
    fn() => RetailRequestAssembler::assemble(bill('321', 'TEST-DJBH-WRONGCRED'), $company, $wholesaleCredential),
    '不属于企业');

checkThrows('同企业另一套凭据（凭据位存在但 ref_ent_id 不符）→ 拒绝',
    fn() => RetailRequestAssembler::assemble(bill('321', 'TEST-DJBH-SWAPCRED'), $company, $credentialS2),
    '不属于企业');

checkThrows('凭据位不存在（伪造 key）→ 拒绝',
    fn() => RetailRequestAssembler::assemble(
        bill('321', 'TEST-DJBH-FAKEKEY'),
        $company,
        ['key' => 'no-such-credential', 'appkey' => 'x', 'secretkey' => 'y', 'ref_ent_id' => 'XJ-REF', 'ent_id' => 'XJ-ENT']
    ),
    '不属于企业');

checkThrows('凭据未标 key → 拒绝（无法确认它属于该企业）',
    fn() => RetailRequestAssembler::assemble(
        bill('321', 'TEST-DJBH-NOKEY'),
        $company,
        ['appkey' => 'x', 'secretkey' => 'y', 'ref_ent_id' => 'XJ-REF', 'ent_id' => 'XJ-ENT']
    ),
    '不属于企业');

checkThrows('缺单号 → 拒绝',
    fn() => RetailRequestAssembler::assemble(
        ['rq' => '2026-09-28', 'bill_type' => '321', 'trace_codes' => 'TEST-CODE-001'],
        $company, $credential
    ),
    '未通过 SDK 校验');

echo "\n";
if ($failures === 0) {
    echo "全部通过 ✓\n";
    exit(0);
}
echo "失败 $failures 项 ✗\n";
exit(1);
