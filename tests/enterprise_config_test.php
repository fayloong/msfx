<?php
/**
 * App\Enterprise 单元测试（自包含断言脚本，无框架依赖）
 *
 * 运行: php tests/enterprise_config_test.php
 *
 * 测试目标：企业配置解析、门店认领（平台 ID 优先 / 名字回退 / 未识别）、接口路由与码上限、配置自检。
 * 本测试全部使用固化 fixture，**不读生产配置、不连任何数据库、不调任何平台接口**。
 * 认领规则的实测依据见 .scratch/retail-chain/probe-findings-2026-09-29.md。
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Enterprise;

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

/** 断言 load() 因配置违反不变量而抛异常，且消息含关键词 */
function checkRejects(string $name, array $structure, array $local, string $needle): void
{
    global $failures;
    Enterprise::reset();
    try {
        Enterprise::load($structure, $local);
        $failures++;
        echo "FAIL  $name  未抛异常\n";
    } catch (\RuntimeException $e) {
        if (str_contains($e->getMessage(), $needle)) {
            echo "PASS  $name\n";
        } else {
            $failures++;
            echo "FAIL  $name  异常消息不含「{$needle}」: " . str_replace("\n", ' / ', $e->getMessage()) . "\n";
        }
    }
}

// ================= fixture：结构（= enterprises.php）与取值（= enterprises.local.php）分离 =================
$structure = ['companies' => [
    [
        'key' => 'ws', 'name' => '批发企业', 'type' => 'wholesale',
        'credentials' => ['main' => ['label' => '主主体', 'primary' => true]],
    ],
    [
        // primary 声明在**结构**里（哪个是默认授权是结构性事实，不随密钥是否到手变化）
        'key' => 's1', 'name' => '门店一', 'type' => 'retail',
        'credentials' => ['main' => ['label' => '主授权', 'primary' => true], 'backup' => ['label' => '备用授权']],
    ],
    [
        'key' => 's2', 'name' => '门店二', 'type' => 'retail',
        'credentials' => ['main' => ['label' => '主授权']],
    ],
    [
        'key' => 's3', 'name' => '门店三', 'type' => 'retail',
        'credentials' => ['main' => ['label' => '主授权']],
    ],
    [
        // 结构里连凭据位都没声明 → 认领时 credential 只能是 null
        'key' => 's4', 'name' => '门店四', 'type' => 'retail',
        'credentials' => [],
    ],
]];

$local = [
    'ids' => [
        'ws' => ['WSREF', 'WSENT'],
        's1' => ['ID1', 'ID1-OLD'],
        's2' => ['ID2'],
        's3' => ['ID3'],
        's4' => ['ID4'],
    ],
    'credentials' => [
        'ws' => ['main' => ['appkey' => 'wsk', 'secretkey' => 'wss', 'ref_ent_id' => 'WSREF', 'ent_id' => 'WSENT']],
        's1' => [
            'main' => ['appkey' => 'k1', 'secretkey' => 's1', 'ref_ent_id' => 'ID1', 'ent_id' => 'ID1'],
            'backup' => ['appkey' => 'k1b', 'secretkey' => 's1b', 'ref_ent_id' => 'ID1', 'ent_id' => 'ID1'],
        ],
        's2' => ['main' => ['appkey' => 'k2', 'secretkey' => 's2', 'ref_ent_id' => 'ID2', 'ent_id' => 'ID2']],
        // s3 故意不给凭据 → 待配凭据（ids 有、凭据空）
    ],
];

Enterprise::reset();
Enterprise::load($structure, $local);

// ---------- 用例 1: 合并与查询 ----------
check('企业数 5', count(Enterprise::all()) === 5, '实际 ' . count(Enterprise::all()));
check('names() 顺序与配置一致', Enterprise::names() === ['批发企业', '门店一', '门店二', '门店三', '门店四'], implode(',', Enterprise::names()));
check('isRetail 判定', Enterprise::isRetail('门店一') === true && Enterprise::isRetail('批发企业') === false);
check('未识别不是配置里的企业', Enterprise::find(Enterprise::UNIDENTIFIED) === null);
check('find() 取到类型', (Enterprise::find('门店二')['type'] ?? '') === 'retail');

// ---------- 用例 2: 凭据配置状态（待配凭据是合法状态） ----------
$s3 = Enterprise::findByKey('s3');
check('s3 有凭据位（结构已声明）', isset($s3['credentials']['main']));
check('s3 凭据未配置 → 待配凭据', Enterprise::credentialConfigured($s3['credentials']['main']) === false);
check('s2 凭据已配置', Enterprise::credentialConfigured(Enterprise::credential('门店二', 'main')) === true);
check('取不存在的凭据返回 null', Enterprise::credential('门店二', 'nope') === null);
check('取不存在企业的凭据返回 null', Enterprise::credential('不存在', 'main') === null);

// ---------- 用例 3: 认领——ID 优先，且按单据类型选列 ----------
$r = Enterprise::claim('321', 'ID1', '', '');
check('321 用 from_user_id 认领', $r['company'] === '门店一' && $r['matched_by'] === 'id', json_encode($r, JSON_UNESCAPED_UNICODE));
check('认领带 primary 凭据键', $r['credential'] === 'main', (string)$r['credential']);

$r = Enterprise::claim('116', 'ID1', '', '');
check('116 用 from_user_id 认领', $r['company'] === '门店一' && $r['matched_by'] === 'id');

$r = Enterprise::claim('104', '', 'ID2', '');
check('104 用 to_user_id 认领', $r['company'] === '门店二' && $r['matched_by'] === 'id');

$r = Enterprise::claim('203', '', 'ID2', '');
check('203 用 to_user_id 认领', $r['company'] === '门店二');

$r = Enterprise::claim('104', 'ID2', '', '');
check('104 不看 from_user_id（选列为 to）', $r['company'] === Enterprise::UNIDENTIFIED && $r['matched_by'] === 'none');

$r = Enterprise::claim('116', 'ID1-OLD', '', '');
check('历史/别名 ID 可认领', $r['company'] === '门店一' && $r['matched_by'] === 'id');

$r = Enterprise::claim('203', '', 'ID3', '');
check('待配凭据门店仍能认领（不因缺凭据而丢单）', $r['company'] === '门店三');
// 采集时 credential 预填 primary 键（表达"默认会用哪套"）；该凭据是否已配齐密钥是另一回事，
// 页面据 credentialConfigured() 决定是否禁用补传
check('待配凭据门店 credential 仍预填 primary 键', $r['credential'] === 'main', (string)$r['credential']);

$r = Enterprise::claim('321', 'ID4', '', '');
check('结构未声明凭据位 → credential 为 null', $r['company'] === '门店四' && $r['credential'] === null, json_encode($r, JSON_UNESCAPED_UNICODE));

// ---------- 用例 4: 认领——名字回退与未识别 ----------
$r = Enterprise::claim('104', '', '', '门店二');
check('ID 为空 → 名字回退', $r['company'] === '门店二' && $r['matched_by'] === 'name', json_encode($r, JSON_UNESCAPED_UNICODE));

$r = Enterprise::claim('321', 'ID1', '', '门店一');
check('ID 与名字都命中 → 无警告', $r['name_unmatched'] === false);

$r = Enterprise::claim('321', 'ID1', '', '源库里的怪名字');
check('ID 命中但名字对不上 → 置警告位（供采集记日志）', $r['company'] === '门店一' && $r['name_unmatched'] === true);

$r = Enterprise::claim('321', 'UNKNOWN-ID', '', '怪名字');
check('都匹配不上 → 未识别', $r['company'] === Enterprise::UNIDENTIFIED && $r['company_key'] === null && $r['credential'] === null);
check('未识别也置警告位（名字非空）', $r['name_unmatched'] === true);

$r = Enterprise::claim('321', '', '', '');
check('全空 → 未识别且不报警告', $r['company'] === Enterprise::UNIDENTIFIED && $r['name_unmatched'] === false);

// ---------- 用例 5: 接口路由与追溯码上限 ----------
$r = Enterprise::route('门店一', '104');
check('零售 104 → lsyd.uploadinoutbill / 10000',
    ($r['class'] ?? '') === 'AlibabaAlihealthDrugtraceTopLsydUploadinoutbillRequest' && ($r['limit'] ?? 0) === 10000,
    json_encode($r, JSON_UNESCAPED_UNICODE));

$r = Enterprise::route('门店一', '203');
check('零售 203 → lsyd.uploadinoutbill / 10000',
    ($r['class'] ?? '') === 'AlibabaAlihealthDrugtraceTopLsydUploadinoutbillRequest' && ($r['limit'] ?? 0) === 10000);

$r = Enterprise::route('门店一', '321');
check('零售 321 → lsyd.uploadretail / 3500',
    ($r['class'] ?? '') === 'AlibabaAlihealthDrugtraceTopLsydUploadretailRequest' && ($r['limit'] ?? 0) === 3500,
    json_encode($r, JSON_UNESCAPED_UNICODE));

$r = Enterprise::route('门店一', '116');
check('零售 116 → lsyd.uploadretail / 3500',
    ($r['class'] ?? '') === 'AlibabaAlihealthDrugtraceTopLsydUploadretailRequest' && ($r['limit'] ?? 0) === 3500);

$r = Enterprise::route('批发企业', '201');
check('批发 201 → kyt.uploadinoutbill / 3500',
    ($r['class'] ?? '') === 'AlibabaAlihealthDrugKytUploadinoutbillRequest' && ($r['limit'] ?? 0) === 3500,
    json_encode($r, JSON_UNESCAPED_UNICODE));

$r = Enterprise::route('批发企业', 'XSO');
check('批发兼容旧字母前缀（归一化）', ($r['class'] ?? '') === 'AlibabaAlihealthDrugKytUploadinoutbillRequest');

check('零售未知单据类型 → null（不猜，猜错就传错主体）', Enterprise::route('门店一', '999') === null);
check('批发未知单据类型 → 走通配', Enterprise::route('批发企业', '999') !== null);
check('未知企业 → null', Enterprise::route('不存在的企业', '104') === null);

// ---------- 用例 6: 配置自检（违反不变量必须抛异常，不能静默错标） ----------
// (a) 凭据的 ent_id 不在该企业 ids 内 → 认领主体与上传主体不一致
$bad = $local;
$bad['credentials']['s2']['main']['ent_id'] = 'ID-OTHER';
checkRejects('凭据 ent_id 不在 ids 内 → 拒绝载入', $structure, $bad, '不在该企业的平台 ID 列表内');

// (b) 同一平台 ID 属于两家企业 → 会认到错误门店
$bad = $local;
$bad['ids']['s2'] = ['ID2', 'ID1'];
checkRejects('平台 ID 重复 → 拒绝载入', $structure, $bad, '平台 ID 重复');

// (c) 多套凭据但没标 primary（primary 声明在结构里）
$badStructure = $structure;
unset($badStructure['companies'][1]['credentials']['main']['primary']);
checkRejects('多套凭据未标 primary → 拒绝载入', $badStructure, $local, '须恰好一套标 primary');

// (d) 凭据字段残缺（填一半）
$bad = $local;
$bad['credentials']['s2']['main'] = ['appkey' => 'k2'];
checkRejects('凭据字段残缺 → 拒绝载入', $structure, $bad, '凭据字段残缺');

// (e) 零售企业没有平台 ID → 采集无法认领
$bad = $local;
$bad['ids']['s3'] = [];
checkRejects('零售企业无 ids → 拒绝载入', $structure, $bad, '没有任何平台 ID');

// (f) 企业名重复（company 列的值重复）
$badStructure = $structure;
$badStructure['companies'][2]['name'] = '门店一';
checkRejects('企业名重复 → 拒绝载入', $badStructure, $local, '企业名重复');

// (g) 企业 key 重复
$badStructure = $structure;
$badStructure['companies'][2]['key'] = 's1';
checkRejects('企业 key 重复 → 拒绝载入', $badStructure, $local, 'key 重复');

// (h) 企业类型非法
$badStructure = $structure;
$badStructure['companies'][2]['type'] = 'retail-chain';
checkRejects('企业类型非法 → 拒绝载入', $badStructure, $local, '企业类型非法');

// (i) 配置为空
checkRejects('配置为空 → 拒绝载入', [], [], '没有任何企业');

// (j) 凭据四字段全空 = 待配凭据，合法（不抛异常）
Enterprise::reset();
$ok = true;
try {
    Enterprise::load($structure, ['ids' => $local['ids'], 'credentials' => []]);
} catch (\RuntimeException $e) {
    $ok = false;
}
check('凭据全空（待配凭据）是合法配置', $ok);

// ---------- 用例 7: 批发主体入口（批发链路取"本项目自动上传主体"的唯一入口） ----------
Enterprise::reset();
Enterprise::load($structure, $local);
$w = Enterprise::wholesaleSubject();
check('批发主体：唯一批发企业', $w['key'] === 'ws' && $w['name'] === '批发企业', json_encode($w, JSON_UNESCAPED_UNICODE));
check('批发主体：带 primary 凭据键', $w['credential_key'] === 'main', (string)$w['credential_key']);

// 凭据位已声明、密钥还没到手（"待配凭据"是合法状态）→ credential 仍预填该凭据位键，
// 由调用方用 credentialConfigured() 判定后拒传（与 claim() 的语义一致，见用例 3）
Enterprise::reset();
Enterprise::load($structure, ['ids' => $local['ids'], 'credentials' => []]);
$w = Enterprise::wholesaleSubject();
$cred = Enterprise::credential($w['name'], $w['credential_key'] ?? '');
check('批发主体：凭据位已声明但密钥未配 → 预填该键、凭据未填齐',
    $w['credential_key'] === 'main' && $cred !== null && !Enterprise::credentialConfigured($cred),
    json_encode($w, JSON_UNESCAPED_UNICODE));

// 结构里连凭据位都没声明 → credential 为 null
$noCredentialSlot = $structure;
$noCredentialSlot['companies'][0]['credentials'] = [];
Enterprise::reset();
Enterprise::load($noCredentialSlot, ['ids' => $local['ids'], 'credentials' => []]);
$w = Enterprise::wholesaleSubject();
check('批发主体：连凭据位都没声明 → credential 为 null', $w['credential_key'] === null, json_encode($w, JSON_UNESCAPED_UNICODE));

// 两家批发企业 → 必须拒绝而不是取第一个：那是"把单据申报到错误主体"的经典路径
$twoWholesale = $structure;
$twoWholesale['companies'][2]['type'] = 'wholesale';
Enterprise::reset();
try {
    Enterprise::load($twoWholesale, $local);
    try {
        Enterprise::wholesaleSubject();
        check('两家批发企业 → 拒绝（不静默取第一家）', false, '未抛异常');
    } catch (\RuntimeException $e) {
        check('两家批发企业 → 拒绝（不静默取第一家）', str_contains($e->getMessage(), '批发主体必须恰好一家'), $e->getMessage());
    }
} catch (\RuntimeException $e) {
    check('两家批发企业 → 拒绝（不静默取第一家）', str_contains($e->getMessage(), '批发主体必须恰好一家'), '载入阶段就抛了别的错: ' . $e->getMessage());
}

// 一家批发企业都没有 → 同样拒绝
$noWholesale = $structure;
$noWholesale['companies'][0]['type'] = 'retail';
Enterprise::reset();
Enterprise::load($noWholesale, $local);
try {
    Enterprise::wholesaleSubject();
    check('没有批发企业 → 拒绝', false, '未抛异常');
} catch (\RuntimeException $e) {
    check('没有批发企业 → 拒绝', str_contains($e->getMessage(), '批发主体必须恰好一家'), $e->getMessage());
}

// ---------- 用例 8: 真实配置文件（部署机上）能通过自检 ----------
Enterprise::reset();
$localPath = __DIR__ . '/../config/enterprises.local.php';
if (is_file($localPath)) {
    try {
        Enterprise::loadFromFiles();
        $companies = Enterprise::all();
        check('真实配置载入通过自检', true);
        check('真实配置企业数 16（1 批发 + 15 门店）', count($companies) === 16, '实际 ' . count($companies));

        // 钉住迁移回填常量（scripts/init_db.php 的 BACKFILL_COMPANY / BACKFILL_CREDENTIAL）与
        // 配置里批发主体的一致性：不一致时历史行的 company/credential 会被 upload_pending 与
        // 三个检查脚本的 company 白名单静默漏掉——批发链路整条停摆。改企业名或换凭据键时，
        // 这两处常量与历史数据要一起动（见 init_db.php 顶部说明）。
        $ws = Enterprise::wholesaleSubject();
        check('配置批发主体名 == 迁移回填常量', $ws['name'] === '河药医药（河源）有限公司', $ws['name']);
        check('配置批发主体凭据键 == 迁移回填常量', $ws['credential_key'] === 'main', (string)$ws['credential_key']);

        $retail = array_filter($companies, fn($c) => $c['type'] === Enterprise::TYPE_RETAIL);
        check('真实配置 15 家零售门店', count($retail) === 15, '实际 ' . count($retail));

        $configured = 0;
        foreach ($retail as $c) {
            foreach ($c['credentials'] as $cred) {
                if (Enterprise::credentialConfigured($cred)) {
                    $configured++;
                }
            }
        }
        echo "  （真实配置中已配凭据的门店数：" . $configured . " / 15）\n";

        // 认领一单真实数据形态：321 行只有 ID 没有名字
        $c = reset($retail);
        $r = Enterprise::claim('321', $c['ids'][0], '', '');
        check('真实配置可按 ID 认领（321 行无名字场景）', $r['company'] === $c['name'], json_encode($r, JSON_UNESCAPED_UNICODE));
    } catch (\RuntimeException $e) {
        $failures++;
        echo "FAIL  真实配置载入失败: " . str_replace("\n", ' / ', $e->getMessage()) . "\n";
    }
} else {
    echo "SKIP  真实配置文件不存在（config/enterprises.local.php），跳过部署配置自检\n";
}

echo "\n";
if ($failures === 0) {
    echo "全部通过 ✓\n";
    exit(0);
}
echo "失败 $failures 项 ✗\n";
exit(1);
