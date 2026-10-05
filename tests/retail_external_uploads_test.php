<?php
/**
 * App\RetailExternalUploads 单元测试（自包含断言脚本，无框架依赖）
 *
 * 运行: php tests/retail_external_uploads_test.php
 *
 * 测试目标: 采集分流的两个纯逻辑 ＋ 状态闭环的动作分类 ＋ 快照口径与统计口径 ＋ 批量 touch 的语句形状——
 *   ① `decide()` 把「源库说已上传没有 × 本地已有哪种痕迹」翻译成落库动作；
 *   ② `buildRecord()` 把一条已上传的单落成什么形状的记录；
 *   ③ `closureActions()` 把「本地待办 × 源库判据 × 本地已有成功记录」翻译成翻正动作（票 03）；
 *   ④ `decide(..., buildTasks: false)` 的快照口径 ＋ `tally()` 的统计口径（票 05）；
 *   ⑤ `touchStatements()` 的语句形状（票 02）：每条都带 `company = ?`、两条表各一句、
 *     分块后每句参数个数在 SQLite 999 上限内。**真落库不进本测试**（要真库），
 *     由副本实测兜着——见 .scratch/retail-platform-check/issues/02 的验证记录。
 *
 *   本文件真正钉的是三条**漏了就会出事**的性质：
 *   - **幂等**（用例 3）：同一 `(company, djbh)` 已有成功记录时必须 SKIP——否则每跑一轮采集
 *     就多一条成功记录，「同一张单只出现一行」这个统计口径随采集次数漂移
 *   - **不建任务**（用例 2 与用例 8）：已上传的单走 RECORD、落在 upload_tasks 之外——否则它会以
 *     「等待上传」出现在补传队列里，人对着一条已经传过的单再点一次补传（平台申报不可逆）；
 *     快照（`--all`）多一条：**未上传的历史单也不建任务**（否则一次灌进成千上万条）；
 *   - **按 (company, djbh) 判据**（用例 7）：闭环的每一条判据都不能退化成裸单号——生产库里
 *     单号并不唯一，乙店的成功记录会把甲店的待办整条吞掉（对外表现为"这张单凭空消失"）
 *   其余用例钉形状：列取值、来源常量与词表的对应、说明出处的 JSON、表名常量、统计键。
 *
 * **辨别力**（去掉关键行为必须变红）：
 *   - 把 `decide()` 里已上传分支的 `$hasSuccess` 检查去掉 → 用例 3 红
 *   - 把 `RESPONSE_STATUS` 改成「上传失败」→ 用例 4 红（记录会从已上传页消失、跑进失败页）
 *   - 把 `SOURCE` 改成词表没登记的取值 → 用例 5 红（页面上直出机器值）
 *   - 把 `task_id` 从 0 改成别的 → 用例 4 红
 *   - 把 `closureActions()` 里 `$uploadedBills` 那道检查去掉 → 用例 7 的"源库没标已上传"红
 *   - 把 `closureActions()` 里 `$success` 的判据从 `(company, djbh)` 降成裸单号 → 用例 7 的
 *     "同名单号两家各自判"红
 *   - 把 `append_record` 的 `$hasSuccess` 检查去掉 → 用例 7 的"已有成功记录不追加"红
 *   - 把 `decide()` 的 `$buildTasks` 分支去掉（快照照样建任务）→ 用例 8 的第一条红
 *   - 把 `$buildTasks` 的默认值改成 false（日常也不建任务）→ 用例 8 最后那条护栏红
 *   - 把 `tally()` 里 COUNT_ONLY/SKIP 也算进 codes（预演码数虚高）→ 用例 9 那两条红
 *   - 把 `tally()` 的 default 分支从抛异常改成静默 return → 用例 9 最后那条红
 *   - 把 `touchStatements()` 的 SQL 丢掉 `company = ?`（按裸单号刷）→ 用例 10 的 4 条红
 *   - 把 `IN_CHUNK_SIZE` 改成 1000（每句 1002 个参数）→ 用例 10 最后那条红（实测两种变异各跑过）
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
    uploadedBills: [$UP => true]
);
check('闭环：已上传 + 待办任务 → 翻任务行（任务不再挂在补传队列里）',
    ($actions[$storeA][$UP]['turn_task'] ?? null) === true);
check('闭环：已上传 + 待办任务 + 无成功记录 → 追加一条外部上传记录',
    ($actions[$storeA][$UP]['append_record'] ?? null) === true);

// ② 已有成功记录 → 只翻任务行，**不追加**（不变量：同一 (company, djbh) 最多一条成功记录）
$actions = RetailExternalUploads::closureActions(
    pending: [$storeA => [$UP => $trace(true, false)]],
    success: [$storeA => [$UP => true]],
    uploadedBills: [$UP => true]
);
check('闭环：已有成功记录 → 仍翻任务行（两条痕迹各自独立判）',
    ($actions[$storeA][$UP]['turn_task'] ?? null) === true);
check('闭环：已有成功记录 → 不追加第二条成功记录',
    ($actions[$storeA][$UP]['append_record'] ?? null) === false);

// ③ 只有补传失败记录（没有任务行）→ 追加记录让它从失败页消失
$actions = RetailExternalUploads::closureActions(
    pending: [$storeA => [$OTHER => $trace(false, true)]],
    success: [],
    uploadedBills: [$OTHER => true]
);
check('闭环：只有补传失败记录 → 追加外部上传记录（失败页那条随后被同单号判重隐藏）',
    ($actions[$storeA][$OTHER]['append_record'] ?? null) === true);
check('闭环：只有失败记录 → 没有任务行可翻', ($actions[$storeA][$OTHER]['turn_task'] ?? null) === false);

// ④ 失败记录 + 已有成功记录 → 两条动作都不做：这键没有待翻的痕迹（失败页本就不显示它）
$actions = RetailExternalUploads::closureActions(
    pending: [$storeA => [$OTHER => $trace(false, true)]],
    success: [$storeA => [$OTHER => true]],
    uploadedBills: [$OTHER => true]
);
check('闭环：失败记录 + 已有成功记录 → 不产生任何动作（键不出现）', !isset($actions[$storeA][$OTHER]));

// ⑤ 源库没标已上传 → 痕迹一个字段都不动。"没查到"只是没有证据，不是"没上传"
$actions = RetailExternalUploads::closureActions(
    pending: [$storeA => [$UP => $trace(true, true)]],
    success: [],
    uploadedBills: [$OTHER => true]   // 查回来的全是别的单号
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
    uploadedBills: [$UP => true]
);
check('闭环：同名单号两家都待办 → 各自判各自的（甲店追加、乙店不追加；判据按 (company, djbh)）',
    ($actions[$storeA][$UP]['append_record'] ?? null) === true
    && ($actions[$storeB][$UP]['append_record'] ?? null) === false);
check('闭环：同名单号两家的任务行都翻（`$uploadedBills` 里没有企业维度，翻正一条都不落下）',
    ($actions[$storeA][$UP]['turn_task'] ?? null) === true
    && ($actions[$storeB][$UP]['turn_task'] ?? null) === true);

// ⑦ 大小写：SQL Server 的比较不区分大小写，回传的是**表里**的写法，与本地清单未必逐字相同
$actions = RetailExternalUploads::closureActions(
    pending: [$storeA => [strtoupper($UP) => $trace(true, false)]],
    success: [],
    uploadedBills: [strtolower($UP) => true]
);
check('闭环：源库回小写、清单是大写 → 仍命中（不因大小写差异"查到了却没翻"）',
    isset($actions[$storeA][strtoupper($UP)]));

// ⑧ 清单为空 → 无动作（closeLoop 据此秒退，连源库都不连）
check('闭环：清单为空 → 无动作',
    RetailExternalUploads::closureActions(pending: [], success: [], uploadedBills: []) === []);

// ---------- 用例 8: 快照口径（票 05：`--all` 未上传的历史单不建任务） ----------
// `--all` 与日常走同一条采集代码，只差 decide 的第四个参数。这一格错了两边都出事：
// 漏传 → 一次快照灌进成千上万条「等待上传」（人工处理不现实、待补传清单的信号被淹没）；
// 传宽了 → 快照连已上传的记录都不写，"页面上有历史可看"这个用途直接落空。
$C = RetailExternalUploads::ACTION_COUNT_ONLY;

check('快照：未上传 + 本地无痕 → 只计数（**不建任务**）',
    RetailExternalUploads::decide(false, false, false, buildTasks: false) === $C);
check('快照：未上传 + 已有任务行 → 跳过（它在补传队列里看得见，不算欠账、不重复写）',
    RetailExternalUploads::decide(false, true, false, buildTasks: false) === $S);
check('快照：未上传 + 已有成功记录 → 跳过（已传成过，不再入队）',
    RetailExternalUploads::decide(false, false, true, buildTasks: false) === $S);
check('快照：已上传 + 本地无痕 → 照写外部上传记录（快照的用途就是"页面上有历史可看"）',
    RetailExternalUploads::decide(true, false, false, buildTasks: false) === $R);
check('快照：已上传 + 已有成功记录 → 跳过（幂等判据与日常同一套，没有第二份）',
    RetailExternalUploads::decide(true, false, true, buildTasks: false) === $S);
// 默认值这一条是**日常口径的护栏**：第四个参数忘了传时必须是"照常建任务"，不能是"静默不建"
check('日常（默认参数）：未上传 + 本地无痕 → 照旧建任务',
    RetailExternalUploads::decide(false, false, false) === $T);
check('COUNT_ONLY 与另外三个动作取值都不同',
    count(array_unique([$R, $T, $S, $C])) === 4, "{$R}/{$T}/{$S}/{$C}");

// ---------- 用例 9: 统计口径（`--dry-run` 那句「将写入 N 单 / M 码」的算法） ----------
// 预演报的数就是这里累加出来的，所以它必须与"真跑会写进去的东西"逐字一致：
// 被跳过、被只计数的单据，一个码都不能进 M（否则预演的数字虚高，看量级的人就被骗了）。
// 起手空数组：五个键一次补齐（调用方不必先初始化）——预演/采集都从 `$tally = []` 起手
$keys = array_keys(RetailExternalUploads::tally([], RetailExternalUploads::ACTION_SKIP, 0));
sort($keys);
check('统计：起手空数组 → 五个键都补上',
    $keys === ['codes', 'count_only', 'records', 'skipped', 'tasks'],
    implode('/', $keys));

$t1 = RetailExternalUploads::tally([], RetailExternalUploads::ACTION_RECORD, 5);
check('统计：写一条记录 → records+1、码数进 M', $t1['records'] === 1 && $t1['codes'] === 5,
    json_encode($t1, JSON_UNESCAPED_UNICODE));
$t2 = RetailExternalUploads::tally([], RetailExternalUploads::ACTION_TASK, 3);
check('统计：建一个任务 → tasks+1、码数同样进 M',
    $t2['tasks'] === 1 && $t2['codes'] === 3, json_encode($t2, JSON_UNESCAPED_UNICODE));
// 这两条是本用例的重点：**不写库的动作不许把码数算进来**
check('统计：快照只计数的单 → count_only+1、**码数不进 M**',
    ($c = RetailExternalUploads::tally([], RetailExternalUploads::ACTION_COUNT_ONLY, 7))['count_only'] === 1
    && $c['codes'] === 0,
    json_encode($c, JSON_UNESCAPED_UNICODE));
check('统计：跳过的单 → skipped+1、**码数不进 M**',
    ($s = RetailExternalUploads::tally([], RetailExternalUploads::ACTION_SKIP, 9))['skipped'] === 1
    && $s['codes'] === 0,
    json_encode($s, JSON_UNESCAPED_UNICODE));

// 连续累加：各键各归各的（一轮采集就是这么一单一单并起来的）
$sum = [];
$sum = RetailExternalUploads::tally($sum, RetailExternalUploads::ACTION_RECORD, 5);
$sum = RetailExternalUploads::tally($sum, RetailExternalUploads::ACTION_TASK, 2);
$sum = RetailExternalUploads::tally($sum, RetailExternalUploads::ACTION_COUNT_ONLY, 100);
$sum = RetailExternalUploads::tally($sum, RetailExternalUploads::ACTION_SKIP, 7);
$sum = RetailExternalUploads::tally($sum, RetailExternalUploads::ACTION_RECORD, 1);
check('统计：连续累加 → 写 2 单 / 6 码（5+1）、任务 1 单 / 2 码、只计数 1 单、跳过 1 单，码合计 8',
    $sum === ['records' => 2, 'tasks' => 1, 'count_only' => 1, 'skipped' => 1, 'codes' => 8],
    json_encode($sum, JSON_UNESCAPED_UNICODE));

// 未知动作抛异常而不是静默丢弃：将来加一个 ACTION_* 却忘了在这里归类，统计会悄悄少一块，
// 而"少一块"在页面上看不出来——只有变红才拦得住
$threw = false;
try {
    RetailExternalUploads::tally([], 'action_that_does_not_exist', 1);
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
check('统计：未知动作 → 抛异常（不静默丢弃）', $threw);

// ---------- 用例 10: 批量 touch 的语句形状（票 02） ----------
// `touchChecked()` 要真库才跑得动，但它最容易错的两处是纯的：**键带不带企业维度**、
// **分块与参数个数**（后者顶着本机 SQLite 3.7.17 的 999 参数上限）——故形状单独抽成
// `touchStatements()` 供离线断言；真正落库由副本实测（票 02 的验证记录）。
$st = RetailExternalUploads::touchStatements(
    ['门店甲' => ['D1' => true, 'D2' => true], '门店乙' => ['D3' => true]],
    '2026-10-05 17:00:00'
);
check('touch：两张表各一句（tasks 与 logs 都要刷）',
    count($st) === 4 && array_column(array_column($st, 1), 0) === array_fill(0, 4, '2026-10-05 17:00:00'),
    json_encode($st, JSON_UNESCAPED_UNICODE));
check('touch：每句都带 company = ?（裸单号跨门店会串）',
    count(array_filter($st, static fn(array $s): bool => strpos($s[0], 'WHERE company = ? AND djbh IN (') !== false)) === 4,
    $st[0][0]);
check('touch：甲店那两句的参数是 [时间, 门店甲, D1, D2]（企业名紧跟时间戳）',
    $st[0][1] === ['2026-10-05 17:00:00', '门店甲', 'D1', 'D2'], json_encode($st[0][1], JSON_UNESCAPED_UNICODE));
check('touch：乙店单独成句（不把两家合成一句）',
    $st[2][1] === ['2026-10-05 17:00:00', '门店乙', 'D3'] && strpos($st[2][0], 'UPDATE upload_tasks') === 0,
    json_encode($st[2], JSON_UNESCAPED_UNICODE));
check('touch：两张表用的是同一份单号与同一家企业',
    $st[1][1] === $st[0][1] && strpos($st[1][0], 'UPDATE upload_logs') === 0,
    json_encode($st[1], JSON_UNESCAPED_UNICODE));
check('touch：没有要刷的键 → 一句话都不生成（调用方据此不开事务）',
    RetailExternalUploads::touchStatements([], '2026-10-05 17:00:00') === []);

// 分块：超过 IN_CHUNK_SIZE 的单号切成多句，且**每句参数个数都在 SQLite 上限内**
// （2 + 500 = 502；当初按 (company, djbh) 两两成对写 OR 条件的话，500 块正好 1000 个 → 直接报错）
$many = [];
for ($i = 0; $i < RetailExternalUploads::IN_CHUNK_SIZE + 1; $i++) {
    $many['D' . $i] = true;
}
$st = RetailExternalUploads::touchStatements(['门店甲' => $many], '2026-10-05 17:00:00');
$sizes = array_map(static fn(array $s): int => count($s[1]), $st);
check('touch：501 个单号 → 每张表切成 2 句（4 句合计）', count($st) === 4, (string)count($st));
check('touch：每句参数个数 ≤ 999（本机 SQLite 3.7.17 的变量上限），最大 ' . max($sizes) . ' 个',
    max($sizes) === RetailExternalUploads::IN_CHUNK_SIZE + 2 && max($sizes) <= 999, json_encode($sizes));

echo "\n";
if ($failures === 0) {
    echo "全部通过 ✓\n";
    exit(0);
}
echo "失败 $failures 项 ✗\n";
exit(1);
