<?php
/**
 * API: POST /api/tasks/batch-retry — 批量重传（**按行分流**：批发走 kyt、门店单走 lsyd）
 *
 * 上传任务页那张表混着全部企业的任务行（批发主体 + 各门店），勾选跨企业是自然操作，
 * 故本端点取到行之后按行分流到两条链路：
 *   - `source='retail'` → App\RetailRetransmit（lsyd 链路，**逐条隔离**）
 *   - 其余             → App\UploadService（批发 kyt 链路，行为与分流前一字不变）
 *
 * **判据是 `source` 而不是企业类型**（工单 15 用户拍板）：采集来的门店单与手工建的门店单共用
 * `source='retail'` 这一个值（见 docs/adr/0015），`未识别` 行也在这一份里——它们会被
 * RetailRetransmit 的关闸**逐条**拒掉（"「未识别」不是零售企业"），不再像分流前那样把整批带下水。
 * 按企业类型判（`Enterprise::isRetail`）会漏掉 `未识别` 行，也拦不住"门店行被改错所属企业"这类脏数据。
 *
 * 两处刻意的非对称：
 * - **批发那批被守卫拒绝即整批打住**：UploadService 在任何平台调用之前整批校验，抛异常时
 *   零售那部分**一行都不动**（不传、不复位）——让操作者去掉坏行再点一次，比"传了一半"好收拾。
 * - **零售行不复位**：批发那批在异常时逐条恢复为各自调用前的状态（与分流前一致），零售行按
 *   ADR 0011 的口径不复位——任务行只在平台调用**之后**被写，复位会把已记录的结果抹成 NULL。
 *
 * `_final.result` 的形状（工单 15 用户拍板）：**合并数 + 两段明细**。`total` 是本批**任务行数**；
 * `success`/`failed` 是"批发子单 + 零售单据"之和——与进度流里前端边跑边数的口径一致（跑完数字
 * 不跳变），前端因而不必分叉。注意两个刻意的口径，别拿 `total` 去减（改动前就是如此）：
 *   - 批发那侧拆单时 `success`/`failed` 按**子单**计，`success + failed` 会 **> total**（本票保持原样）
 *   - 零售那侧按**单据**计（一张单的子单全成功才算成功）。零售单实测不触发拆单（单张码数上限
 *     1,718 < 3500/10000），故与"按进度行"在实跑中恒等；真出现"拆到一半成一半败"时，这一单按
 *     失败计，而进度流里已为成功的那张子单打过一条绿行——届时 `_final` 会与前端累计数差一条
 *
 * 被拒的零售行（未识别 / 待配凭据 / 无路由 / 任务已不存在）算**失败**：发一条与真实结果同形状的
 * 进度行说明原因（RetailRetransmit::rejectedProgress），`failed` +1——与已撤的零售批量补传同一口径。
 * 选中的 id 在页面加载后被删掉时它不在 `$tasks` 里，逐条分流时自然只影响它自己，不报错。
 */

use App\Auth;
use App\Database;
use App\RetailRetransmit;
use App\UploadService;

Auth::init();
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$ids = $input['ids'] ?? [];

if (empty($ids) || !is_array($ids)) {
    http_response_code(400);
    echo json_encode(['error' => '缺少 ids 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = Database::getInstance();
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$tasks = $db->query("SELECT * FROM upload_tasks WHERE id IN ({$placeholders})", $ids);

// ── 分流：门店单一份、其余一份（两组的相对顺序都保持查询给出的顺序） ──
// 判据只有 `source` 一项。名字里的"批发"是按**链路**说的（这一份交给 UploadService），不是按企业类型说的：
// `未识别` 行、被改错所属企业的脏数据行都落进这一份，由守卫去拒——那正是它们该去的地方
$wholesaleTasks = [];
$retailTasks = [];
foreach ($tasks as $t) {
    if (($t['source'] ?? '') === 'retail') {
        $retailTasks[] = $t;
    } else {
        $wholesaleTasks[] = $t;
    }
}

// NDJSON 流式输出
header('Content-Type: application/x-ndjson; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache');
ini_set('output_buffering', 'off');
while (ob_get_level()) { ob_end_clean(); }
ob_implicit_flush(true);

$emit = function (array $line) {
    echo json_encode($line, JSON_UNESCAPED_UNICODE) . "\n";
    flush();
};

/** 汇总：合并数（批发子单 + 零售单据）+ 两段各自的明细，形状只在这里定义一次 */
$summarize = function (array $wholesale, array $retail): array {
    return [
        'total' => $wholesale['total'] + $retail['total'],
        'success' => $wholesale['success'] + $retail['success'],
        'failed' => $wholesale['failed'] + $retail['failed'],
        'wholesale' => $wholesale,
        'retail' => $retail,
    ];
};
$zero = ['total' => 0, 'success' => 0, 'failed' => 0];   // 一段（批/零售）的零汇总

if (empty($tasks)) {
    $emit(['_final' => true, 'success' => true, 'result' => $summarize($zero, $zero)]);
    exit;
}

// ── 批发那批：原样交给 UploadService（映射、守卫、限速、异常复位逻辑都不动） ──

$wholesaleResult = $zero;

try {
    // 空数组不调用：那是给批发链路取 flock 用的，纯门店批次不该被一个正在跑的 cron 上传挡住
    if (!empty($wholesaleTasks)) {
        $bills = array_map(function ($t) {
            return [
                'type' => substr($t['djbh'], 0, 3),
                'rq' => $t['rq'],
                'djbh' => $t['djbh'],
                'ent_name' => $t['ent_name'],
                'sn' => $t['trace_codes'] ?? '',
                'task_id' => (int)$t['id'],
                'source' => 'batch_retry',
                'company' => $t['company'] ?? '',
                'credential' => $t['credential'] ?? '',
            ];
        }, $wholesaleTasks);

        $wholesaleResult = (new UploadService())->upload($bills, $emit);
    }
} catch (\Throwable $e) {
    // 批发被整批拒绝（守卫）或链路抛错：**止于此**，零售那部分一行都不动——操作者去掉坏行再点一次。
    // 尝试恢复状态，忽略数据库错误（与单条重传 tasks_retry 保持一致）。
    // 逐条恢复为**各自调用前的状态**而不是统一写"等待上传"：在"已处理"的行上点重传
    // （补传失败后的出口，见 ADR 0011），失败后不该把它拖回待上传队列。
    // 只扫批发那一份：零售行根本没进过平台，碰它们只会把已有的结果抹掉。
    try {
        foreach ($wholesaleTasks as $t) {
            $db->execute(
                "UPDATE upload_tasks SET task_status = ?, request_status = NULL, response_status = NULL, updated_at = datetime('now','localtime') WHERE id = ?",
                [$t['task_status'] ?? '等待上传', (int)$t['id']]
            );
        }
    } catch (\Throwable $dbEx) {
        // 忽略
    }
    $emit(['_final' => true, 'error' => $e->getMessage()]);
    exit;
}

// ── 零售那批：逐行调用同一份实现，**逐条 try/catch**（一条坏单不该让整批停摆） ──

$retailTotal = 0;
$retailSuccess = 0;
$retailFailed = 0;

if (!empty($retailTasks)) {
    $retransmit = new RetailRetransmit();
    foreach ($retailTasks as $task) {
        $retailTotal++;
        try {
            $result = $retransmit->retransmit($task, $db, $emit);
            // 按单据计：子单全成功才算这张单成功（失败也翻"已处理"，出口是失败记录页，见 ADR 0011）
            $result['failed'] === 0 ? $retailSuccess++ : $retailFailed++;
        } catch (\Throwable $e) {
            $retailFailed++;
            $emit(RetailRetransmit::rejectedProgress(
                (string)$task['djbh'],
                trim((string)($task['company'] ?? '')),
                $e->getMessage()
            ));
        }
    }
}

$emit([
    '_final' => true,
    'success' => true,
    'result' => $summarize($wholesaleResult, [
        'total' => $retailTotal,
        'success' => $retailSuccess,
        'failed' => $retailFailed,
    ]),
]);
