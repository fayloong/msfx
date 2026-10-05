<?php
/**
 * App\RetailPlatformCheck 单元测试（自包含断言脚本，无框架依赖）
 *
 * 运行: php tests/retail_platform_check_test.php
 *
 * 测试目标（票 01）：
 *   ① **跳过的判据**：取不到凭据（`未识别` / 待配凭据 / 不在配置中）与混进来的批发主体
 *      整组跳过并计数——没有 ref_ent_id 这条判据不成立，拿错凭据去查是白烧调用
 *   ② **企业隔离**：甲店查到的单号不翻乙店的同名待办（按企业分别调 `closureActions()`，
 *      而不是把全量集合一次喂进去）——这是平台判据相对源库状态表的**核心增益**
 *   ③ **`--dry-run` 一次平台调用都不发**：注入"被调用就记一笔"的假回调，断言它一次没被调
 *   ④ 逐条隔离、统计口径、限量
 *
 * **辨别力**（改一处跑一遍再还原，见票的验证记录）：
 *   - 把 `actionsByCompany` 改成"一次喂全量 uploadedByCompany"→ 用例 2 的乙店断言红
 *   - 去掉 `run()` 的 `$dryRun` 分支 → 用例 3 的回调计数红
 *   - 跳过的判据只看 `credentialFor() === null`（去掉 isRetail / credentialConfigured）→
 *     用例 1 的批发主体/待配凭据断言红
 *   - 把 `error` 非空的分支并进 `absent`（或反过来并进 uploaded）→ 用例 4 红
 *   - 限量挪到分组之前（截原始清单）→ 用例 6 红（跳过的门店白占额度）
 *
 * 真平台调用与真实响应**不进本测试**（那是 ApiClient 与探测记录的事，见 spec.md）：
 * 本测试全程离线，`$query` 是假回调。
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Enterprise;
use App\RetailPlatformCheck;

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

// ---------- 测试用企业配置（注入，不读生产配置）----------
// 甲店：凭据齐 → 可查；乙店：待配凭据（有凭据位、四字段空）→ 跳过；
// 丙店：结构里连凭据位都没有 → 跳过；批发企业：类型不对 → 跳过；「未识别」不在配置里 → 跳过
$structure = ['companies' => [
    ['key' => 'ws', 'name' => '批发企业', 'type' => 'wholesale',
     'credentials' => ['main' => ['label' => '主授权']]],
    ['key' => 's1', 'name' => '门店甲', 'type' => 'retail',
     'credentials' => ['main' => ['label' => '主授权']]],
    ['key' => 's2', 'name' => '门店乙', 'type' => 'retail',
     'credentials' => ['main' => ['label' => '主授权']]],
    ['key' => 's3', 'name' => '门店丙', 'type' => 'retail', 'credentials' => []],
]];
$local = [
    'ids' => ['ws' => ['WSREF', 'WSENT'], 's1' => ['REF1', 'ENT1'], 's2' => ['REF2'], 's3' => ['REF3']],
    'credentials' => [
        'ws' => ['main' => ['appkey' => 'wsk', 'secretkey' => 'wss', 'ref_ent_id' => 'WSREF', 'ent_id' => 'WSENT']],
        's1' => ['main' => ['appkey' => 'k1', 'secretkey' => 's1', 'ref_ent_id' => 'REF1', 'ent_id' => 'ENT1']],
        // s2 故意不给凭据 → 待配凭据
    ],
];
Enterprise::reset();
Enterprise::load($structure, $local);

/** 一条待办（形状取自 RetailExternalUploads::pendingItems()） */
function item(bool $task = true, bool $failure = false): array
{
    return ['rq' => '2026-10-02', 'trace_codes' => 'c1,c2', 'credential' => 'main', 'task' => $task, 'failure' => $failure];
}

/** 一个"被调用就记一笔"的假查询回调 */
function spyQuery(array &$calls, array $responses = []): callable
{
    return static function (string $djbh, array $credential) use (&$calls, $responses): array {
        $calls[] = [$djbh, $credential['ref_ent_id'] ?? ''];
        return $responses[$djbh] ?? ['found' => false, 'response' => null, 'error' => ''];
    };
}

// ---------- 用例 1: 分组——能查的与该跳过的 ----------
$grouped = RetailPlatformCheck::groupByCompany([
    '门店甲' => ['D001' => item(), 'D002' => item()],
    '门店乙' => ['D003' => item(), 'D004' => item(), 'D005' => item()],
    '门店丙' => ['D006' => item()],
    '批发企业' => ['D007' => item()],
    '未识别' => ['D008' => item()],
]);

check('分组：只有凭据齐的零售门店可查', array_keys($grouped['queryable']) === ['门店甲'],
    json_encode(array_keys($grouped['queryable']), JSON_UNESCAPED_UNICODE));
check('分组：可查的那家带上它自己的凭据（ref_ent_id 对得上）',
    ($grouped['queryable']['门店甲']['credential']['ref_ent_id'] ?? '') === 'REF1');
check('分组：待配凭据按单数计数', ($grouped['skipped']['门店乙'] ?? 0) === 3);
check('分组：没声明凭据位的门店也跳过', ($grouped['skipped']['门店丙'] ?? 0) === 1);
check('分组：混进来的批发主体跳过（fail-closed，不拿河药凭据查门店单）',
    ($grouped['skipped']['批发企业'] ?? 0) === 1);
check('分组：未识别跳过（它不在配置里）', ($grouped['skipped']['未识别'] ?? 0) === 1);

// ---------- 用例 2: 企业隔离——同名单号各判各的 ----------
$pendingSame = [
    '门店甲' => ['DUP001' => item()],
    '门店乙' => ['DUP001' => item()],
];
$actions = RetailPlatformCheck::actionsByCompany(
    $pendingSame,
    ['门店甲' => ['DUP001' => true]],   // 只有甲店查到
    []                                   // 本地都没有成功记录
);
check('企业隔离：甲店被翻正（翻任务 + 追加记录）',
    !empty($actions['门店甲']['DUP001']['turn_task']) && !empty($actions['门店甲']['DUP001']['append_record']));
check('企业隔离：乙店的同名待办**不动**（一次喂全量集合时这条会红）',
    !isset($actions['门店乙']['DUP001']), json_encode($actions, JSON_UNESCAPED_UNICODE));
check('企业隔离：乙店查到、甲店没查到 → 反向同样成立',
    !isset(RetailPlatformCheck::actionsByCompany($pendingSame, ['门店乙' => ['DUP001' => true]], [])['门店甲']));
check('企业隔离：本地已有成功记录时只翻任务行、不追加',
    (static function () use ($pendingSame): bool {
        $a = RetailPlatformCheck::actionsByCompany($pendingSame, ['门店甲' => ['DUP001' => true]], ['门店甲' => ['DUP001' => true]]);
        return !empty($a['门店甲']['DUP001']['turn_task']) && empty($a['门店甲']['DUP001']['append_record']);
    })());

// ---------- 用例 3: --dry-run 一次平台调用都不发 ----------
$calls = [];
$stats = RetailPlatformCheck::run(
    ['门店甲' => ['D001' => item(), 'D002' => item()]],
    spyQuery($calls),
    true,   // dryRun
    null,
    null,
    0
);
check('dry-run：假回调一次都没被调', $calls === [], '实际调用 ' . count($calls) . ' 次');
check('dry-run：统计里没有查询结果（全是 0）',
    $stats['uploaded'] === 0 && $stats['absent'] === 0 && $stats['error'] === 0);
check('dry-run：queued 仍报告"计划查几条"', $stats['queued'] === 2, (string)$stats['queued']);
check('dry-run：uploaded_by_company 为空（脚本据此不落库）', $stats['uploaded_by_company'] === []);

// ---------- 用例 4: 三种结果各自的归类与收集 ----------
$calls = [];
$stats = RetailPlatformCheck::run(
    ['门店甲' => ['D001' => item(), 'D002' => item(), 'D003' => item()]],
    spyQuery($calls, [
        'D001' => ['found' => true, 'response' => ['ok'], 'error' => ''],
        'D002' => ['found' => false, 'response' => ['notfound'], 'error' => ''],
        'D003' => ['found' => false, 'response' => null, 'error' => '网络超时'],
    ]),
    false, null, null, 0
);
check('归类：found → uploaded', $stats['uploaded'] === 1);
check('归类：error 空 + found false → absent', $stats['absent'] === 1);
check('归类：error 非空 → error（**不冒充未上传**）', $stats['error'] === 1);
check('收集：只有 found 的单号进 uploaded_by_company（error 的不能进，否则会被当已上传翻正）',
    array_keys($stats['uploaded_by_company']['门店甲'] ?? []) === ['D001'],
    json_encode($stats['uploaded_by_company'], JSON_UNESCAPED_UNICODE));
check('查询回调收到的是该门店自己的 ref_ent_id',
    ($calls[0][1] ?? '') === 'REF1', json_encode($calls));

// ---------- 用例 5: 逐条隔离——一条抛异常不影响后面的 ----------
$calls = [];
$query = static function (string $djbh, array $credential) use (&$calls): array {
    $calls[] = $djbh;
    if ($djbh === 'D001') {
        throw new \RuntimeException('链接路炸了');
    }
    return ['found' => true, 'response' => null, 'error' => ''];
};
$stats = RetailPlatformCheck::run(
    ['门店甲' => ['D001' => item(), 'D002' => item()]],
    $query,
    false, null, null, 0
);
check('隔离：抛异常的那条算 error、后面的照跑', $stats['error'] === 1 && $stats['uploaded'] === 1
    && $calls === ['D001', 'D002'], json_encode($calls));
check('隔离：异常那条不进 uploaded_by_company', !isset($stats['uploaded_by_company']['门店甲']['D001']));

// ---------- 用例 6: 限量——跳过的门店不占额度 ----------
$calls = [];
$stats = RetailPlatformCheck::run(
    [
        '门店甲' => ['D001' => item(), 'D002' => item(), 'D003' => item()],
        '门店乙' => ['D004' => item(), 'D005' => item(), 'D006' => item()],  // 待配凭据 → 跳过
    ],
    spyQuery($calls),
    false,
    2,      // --limit=2
    null,
    0
);
check('限量：只查 2 条，且都在可查的门店上', count($calls) === 2 && $stats['queued'] === 2);
check('限量：跳过的门店的条数不计入 remaining（它们压根不在队列里）', $stats['remaining'] === 1,
    (string)$stats['remaining']);
check('限量：跳过统计照报', $stats['skipped_companies'] === 1 && $stats['skipped_items'] === 3);

// ---------- 用例 7: 事件回调（脚本打印靠它） ----------
$events = [];
$calls2 = [];
RetailPlatformCheck::run(
    ['门店甲' => ['D001' => item()]],
    spyQuery($calls2, ['D001' => ['found' => true, 'response' => null, 'error' => '']]),
    false, null,
    static function (string $company, string $djbh, array $event) use (&$events): void {
        $events[] = [$company, $djbh, $event['outcome'], $event['error']];
    },
    0
);
check('回调：企业/单号/结果都给全了',
    $events === [['门店甲', 'D001', RetailPlatformCheck::OUTCOME_UPLOADED, null]],
    json_encode($events, JSON_UNESCAPED_UNICODE));

echo "\n" . ($failures === 0 ? '全部通过 ✓' : "{$failures} 条断言失败 ✗") . "\n";
exit($failures === 0 ? 0 : 1);
