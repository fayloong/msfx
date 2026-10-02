<?php
/**
 * App\RetailExternalUploads 单元测试（自包含断言脚本，无框架依赖）
 *
 * 运行: php tests/retail_external_uploads_test.php
 *
 * 测试目标: 采集分流的两个纯逻辑 ＋ 状态闭环的动作分类——
 *   ① `decide()` 把「源库说已上传没有 × 本地已有哪种痕迹」翻译成落库动作；
 *   ② `buildRecord()` 把一条已上传的单落成什么形状的记录；
 *   ③ `closureActions()` 把「本地待办 × 源库判据 × 本地已有成功记录」翻译成翻正动作（票 03）。
 *
 *   本文件真正钉的是三条**漏了就会出事**的性质：
 *   - **幂等**（用例 3）：同一 `(company, djbh)` 已有成功记录时必须 SKIP——否则每跑一轮采集
 *     就多一条成功记录，「同一张单只出现一行」这个统计口径随采集次数漂移
 *   - **不建任务**（用例 2）：已上传的单走 RECORD、落在 upload_tasks 之外——否则它会以
 *     「等待上传」出现在补传队列里，人对着一条已经传过的单再点一次补传（平台申报不可逆）
 *   - **按 (company, djbh) 判据**（用例 7）：闭环的每一条判据都不能退化成裸单号——生产库里
 *     单号并不唯一，乙店的成功记录会把甲店的待办整条吞掉（对外表现为"这张单凭空消失"）
 *   其余用例钉形状：列取值、来源常量与词表的对应、说明出处的 JSON、表名常量。
 *
 * **辨别力**（去掉关键行为必须变红）：
 *   - 把 `decide()` 里已上传分支的 `$hasSuccess` 检查去掉 → 用例 3 红
 *   - 把 `RESPONSE_STATUS` 改成「上传失败」→ 用例 4 红（记录会从已上传页消失、跑进失败页）
 *   - 把 `SOURCE` 改成词表没登记的取值 → 用例 5 红（页面上直出机器值）
 *   - 把 `task_id` 从 0 改成别的 → 用例 4 红
 *   - 把 `closureActions()` 里 `$uploadedCodes` 那道检查去掉 → 用例 7 的"源库没标已上传"红
 *   - 把 `closureActions()` 里 `$success` 的判据从 `(company, djbh)` 降成裸单号 → 用例 7 的
 *     "同名单号两家各自判"红
 *   - 把 `append_record` 的 `$hasSuccess` 检查去掉 → 用例 7 的"已有成功记录不追加"红
 *
 * **闭环的编排（`closeLoop()`）不进本测试**：它读本地库、查源库、写本地库，三样都是真环境，
 * 断言得起劲也只是在测"SQLite 能不能写"。可测的判据全在 `closureActions()` 里，编排只负责
 * 把三类本地痕迹与一份源库答案凑齐（凑法见 tests 上方的 .scratch/retail-collection-split/
 * spec.md §3，实测见票 03 的交付记录）。采集脚本本身同样不进（它只做取数、认领与流式落库）。
 */

require __DIR__ . '/../vendor/autoload.php';

use App\LogSource;
use App\RetailExternalUploads;

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

// ---------- 用例 1: 表名常量（读侧与写侧的唯一来源） ----------
// 断言**字面量**而不是"常量等于自己"：这个值是源库那张表的名字，是外部契约不是实现细节。
// 采集 SQL、回写（UpdateStateWriter）、票 04 的门卫都引用它——改错一个字符，读侧与写侧会
// 静默读写不同的表，且不会有任何报错。
check(
    '表名是源库那张 4 段式链接服务器表',
    RetailExternalUploads::TABLE === 'dyt.bs_msfx.dbo.update_state',
    RetailExternalUploads::TABLE
);

// ---------- 用例 2: 两条分支的判定（decide 的完整真值表） ----------
$R = RetailExternalUploads::ACTION_RECORD;
$T = RetailExternalUploads::ACTION_TASK;
$S = RetailExternalUploads::ACTION_SKIP;

check('未上传 + 本地无痕 → 建任务', RetailExternalUploads::decide(false, false, false) === $T);
check('未上传 + 已有任务行 → 跳过（重采集不重复建）', RetailExternalUploads::decide(false, true, false) === $S);
check('未上传 + 已有成功记录 → 跳过（已传成过，不再入队）', RetailExternalUploads::decide(false, false, true) === $S);
check('未上传 + 两种痕迹都有 → 跳过', RetailExternalUploads::decide(false, true, true) === $S);

check('已上传 + 本地无痕 → 写外部上传记录', RetailExternalUploads::decide(true, false, false) === $R);
// 这条是本票最容易做错的一格：那条任务行是本地待办痕迹，翻正是闭环（票 03）的事——
// 采集**不**顺手把它翻掉、也**不**因为它而整条跳过（跳过就等于这张单在本系统里凭空消失）
check('已上传 + 只有任务行 → 仍写记录（任务行留给闭环翻正）', RetailExternalUploads::decide(true, true, false) === $R);

// ---------- 用例 3: 幂等（成功记录不变量） ----------
check('已上传 + 已有成功记录 → 跳过（不产生第二条成功记录）', RetailExternalUploads::decide(true, false, true) === $S);
check('已上传 + 两种痕迹都有 → 跳过', RetailExternalUploads::decide(true, true, true) === $S);

// 动作常量本身：三个值互不相同（否则两个分支会被同一段 if 吃掉）
check('三个动作取值互不相同', count(array_unique([$R, $T, $S])) === 3, "{$R}/{$T}/{$S}");

// ---------- 用例 4: 记录形状（逐列取值） ----------
$record = RetailExternalUploads::buildRecord([
    'djbh'        => 'XSKWMS00012345',
    'rq'          => '2026-10-02',
    'trace_codes' => '81111111111111,82222222222222',
    'company'     => '某某大药房（河源）有限公司新江分店',
    'credential'  => 'xinjiang',
]);

check('来源 = retail_external', $record['source'] === 'retail_external', (string)$record['source']);
check('响应状态 = 上传成功', $record['response_status'] === '上传成功', (string)$record['response_status']);
// 它必须落进「已上传记录」页的收口径（RecordQuery 的 TYPE_UPLOADED：response_status IN
// ('上传成功','单据重复')）而不是失败页——改错一个词，这条记录就从已上传页消失、跑进失败页
check(
    '响应状态落在已上传页的收口径里',
    in_array($record['response_status'], ['上传成功', '单据重复'], true),
    (string)$record['response_status']
);
check('请求状态留 null（本项目没有发起请求，写"请求成功"是失真）', $record['request_status'] === null, var_export($record['request_status'], true));
check('task_id = 0（无关联任务；这张单根本没建任务）', $record['task_id'] === 0, var_export($record['task_id'], true));
check('ent_name 留空（零售的对手方是 from/to 两个平台 ID，不写名字）', $record['ent_name'] === '');
check('追溯码照写（逗号分隔原样带过）', $record['trace_codes'] === '81111111111111,82222222222222', $record['trace_codes']);
check('单据日期取源表', $record['rq'] === '2026-10-02', $record['rq']);
check('所属企业取认领结果', $record['company'] === '某某大药房（河源）有限公司新江分店', $record['company']);
check('凭据取认领结果（该门店那套的键）', $record['credential'] === 'xinjiang', (string)$record['credential']);
check('单号原样带过', $record['djbh'] === 'XSKWMS00012345', $record['djbh']);

// 未识别门店：认领不到企业时 company='未识别'、credential 为 null，**照写记录**（丢单比错标更危险）
$unidentified = RetailExternalUploads::buildRecord([
    'djbh' => 'XSKWMS00099999', 'rq' => '2026-10-02', 'trace_codes' => '',
    'company' => '未识别', 'credential' => null,
]);
check('未识别的单照写记录，company = 未识别', $unidentified['company'] === '未识别', $unidentified['company']);
check('未识别的单单号仍在', $unidentified['djbh'] === 'XSKWMS00099999');
check('credential 为 null 时原样下传（落库由 LogWriter 决定）',
    array_key_exists('credential', $unidentified) && $unidentified['credential'] === null);
check('没码的单照写（LEFT JOIN 放行的行也要看得见）', $unidentified['trace_codes'] === '');

// ---------- 用例 5: response 里的出处说明（详情弹窗靠它解释"为什么在这儿"） ----------
$decoded = json_decode($record['response'], true);
check('response 是合法 JSON（详情弹窗直接展示它）', is_array($decoded), (string)$record['response']);
check('说明里点了状态表（判据来自源库）',
    is_array($decoded) && ($decoded['judged_by'] ?? null) === RetailExternalUploads::TABLE,
    (string)$record['response']);
check('说明里写了"外部系统已上传"（与"本项目调了一次平台"区分开）',
    is_array($decoded) && str_contains((string)($decoded['reason'] ?? ''), '外部系统已上传'),
    (string)$record['response']);

// ---------- 用例 6: 来源常量必须落在词表里（页面/导出显示中文标签的前提） ----------
$labels = LogSource::labels();
check('SOURCE 在来源词表里（否则页面直出机器值）', isset($labels[RetailExternalUploads::SOURCE]), RetailExternalUploads::SOURCE);
check('SOURCE 的中文标签是「外部上传」', ($labels[RetailExternalUploads::SOURCE] ?? null) === '外部上传', (string)($labels[RetailExternalUploads::SOURCE] ?? '(缺失)'));

// ---------- 用例 7: 状态闭环的动作分类（票 03 的 closureActions） ----------
// 待办清单的形状是 企业 => 单号 => 痕迹。两类痕迹分开给，因为闭环对它们的动作不同：
// 任务行是**翻状态**，补传失败记录是**追加**一条外部上传记录（不改写那条历史）。
$storeA = '某某大药房（河源）有限公司新江分店';
$storeB = '某某大药房（河源）有限公司文明分店';
$trace = fn(bool $task, bool $failure): array => ['task' => $task, 'failure' => $failure];
$UP = 'XSKWMS00012345';   // 源库状态表说"这张传过了"
$OTHER = 'XSKWMS00099999';

// ① 已上传 + 还挂着的任务 + 本地无成功记录 → 翻任务行 ＋ 追加记录（两条都做）
$actions = RetailExternalUploads::closureActions(
    pending: [$storeA => [$UP => $trace(true, false)]],
    success: [],
    uploadedCodes: [$UP => true]
);
check('闭环：已上传 + 待办任务 → 翻任务行（任务不再挂在补传队列里）',
    ($actions[$storeA][$UP]['turn_task'] ?? null) === true);
check('闭环：已上传 + 待办任务 + 无成功记录 → 追加一条外部上传记录',
    ($actions[$storeA][$UP]['append_record'] ?? null) === true);

// ② 已有成功记录 → 只翻任务行，**不追加**（不变量：同一 (company, djbh) 最多一条成功记录）
$actions = RetailExternalUploads::closureActions(
    pending: [$storeA => [$UP => $trace(true, false)]],
    success: [$storeA => [$UP => true]],
    uploadedCodes: [$UP => true]
);
check('闭环：已有成功记录 → 仍翻任务行（两条痕迹各自独立判）',
    ($actions[$storeA][$UP]['turn_task'] ?? null) === true);
check('闭环：已有成功记录 → 不追加第二条成功记录',
    ($actions[$storeA][$UP]['append_record'] ?? null) === false);

// ③ 只有补传失败记录（没有任务行）→ 追加记录让它从失败页消失
$actions = RetailExternalUploads::closureActions(
    pending: [$storeA => [$OTHER => $trace(false, true)]],
    success: [],
    uploadedCodes: [$OTHER => true]
);
check('闭环：只有补传失败记录 → 追加外部上传记录（失败页那条随后被同单号判重隐藏）',
    ($actions[$storeA][$OTHER]['append_record'] ?? null) === true);
check('闭环：只有失败记录 → 没有任务行可翻', ($actions[$storeA][$OTHER]['turn_task'] ?? null) === false);

// ④ 失败记录 + 已有成功记录 → 两条动作都不做：这键没有待翻的痕迹（失败页本就不显示它）
$actions = RetailExternalUploads::closureActions(
    pending: [$storeA => [$OTHER => $trace(false, true)]],
    success: [$storeA => [$OTHER => true]],
    uploadedCodes: [$OTHER => true]
);
check('闭环：失败记录 + 已有成功记录 → 不产生任何动作（键不出现）', !isset($actions[$storeA][$OTHER]));

// ⑤ 源库没标已上传 → 痕迹一个字段都不动。"没查到"只是没有证据，不是"没上传"
$actions = RetailExternalUploads::closureActions(
    pending: [$storeA => [$UP => $trace(true, true)]],
    success: [],
    uploadedCodes: [$OTHER => true]   // 查回来的全是别的单号
);
check('闭环：源库没标已上传 → 一个动作都不产生', $actions === []);

// ⑥ **按企业不串号**：同名单号属于两家门店时，各判各的（生产库里裸 djbh 并不唯一，
//    这条判据一旦退化成裸单号，甲店那条待办会被乙店的成功记录整条吞掉）
$actions = RetailExternalUploads::closureActions(
    pending: [
        $storeA => [$UP => $trace(true, false)],
        $storeB => [$UP => $trace(true, false)],
    ],
    success: [$storeB => [$UP => true]],
    uploadedCodes: [$UP => true]
);
check('闭环：同名单号两家都待办 → 两家各自判（甲店追加、乙店不追加）',
    ($actions[$storeA][$UP]['append_record'] ?? null) === true
    && ($actions[$storeB][$UP]['append_record'] ?? null) === false);
check('闭环：乙店的成功记录不算甲店的（判据按 (company, djbh)，不按裸单号）',
    ($actions[$storeA][$UP]['append_record'] ?? null) === true);
check('闭环：同名单号两家的任务行都翻（`$uploadedCodes` 里没有企业维度，翻正一条都不落下）',
    ($actions[$storeA][$UP]['turn_task'] ?? null) === true
    && ($actions[$storeB][$UP]['turn_task'] ?? null) === true);

// ⑦ 大小写：SQL Server 的比较不区分大小写，回传的是**表里**的写法，与本地清单未必逐字相同
$actions = RetailExternalUploads::closureActions(
    pending: [$storeA => [strtoupper($UP) => $trace(true, false)]],
    success: [],
    uploadedCodes: [strtolower($UP) => true]
);
check('闭环：源库回小写、清单是大写 → 仍命中（不因大小写差异"查到了却没翻"）',
    isset($actions[$storeA][strtoupper($UP)]));

// ⑧ 清单为空 → 无动作（closeLoop 据此秒退，连源库都不连）
check('闭环：清单为空 → 无动作',
    RetailExternalUploads::closureActions(pending: [], success: [], uploadedCodes: []) === []);

echo "\n";
if ($failures === 0) {
    echo "全部通过 ✓\n";
    exit(0);
}
echo "失败 $failures 项 ✗\n";
exit(1);
