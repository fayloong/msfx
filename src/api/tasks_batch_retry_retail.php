<?php
/**
 * API: POST /api/tasks_batch_retry_retail — 零售门店单据的批量补传（手动上传页选定门店后触发）
 *
 * 本文件只做「校验请求 + 取任务行 + 逐条反馈 + 汇总」：补传的流程（三关 fail-closed、拆单、调用、
 * 写日志、翻任务状态）全在 App\RetailRetransmit —— 票面要求批量与单条共用同一份实现，不要写第二份。
 *
 * 入参：{ids: int[], company: string, credential: string}
 * - company 是操作者在页面上选定的门店：整批单据必须都属于它。混进别家门店的 id 说明调用方或页面
 *   出了问题，**整批拒绝**而不是挑着传——一次请求里出现两家门店没有任何正当来由。
 * - credential 由操作者显式选择，一套管整批（本轮不做多套凭据的自动分发规则，由人指定比猜一套规则可靠）
 *
 * 逐条隔离：某条被 fail-closed 拒绝（非零售 / 待配凭据 / 无路由 / 装配缺项）或已不存在，只影响它自己，
 * 其余照常补传——批量入口里一条坏单不该让整批停摆。这是与单条入口唯一的差异（那里异常一路冒到 _final）。
 * **不复位任务状态**（同单条入口）：任务行只在平台调用之后被写，回滚会把已记录的结果抹成 NULL。
 *
 * 时长：每条 0.33s 限速 + 平台往返，php-fpm 池的 300s 上限下可连续传数百条。真被超时截断时，
 * 已传完的行已落库并翻"已处理"，剩的仍在"待补传"清单里（页面刷新即见），重新勾选再传即可。
 */

use App\Auth;
use App\Database;
use App\Enterprise;
use App\RetailRetransmit;

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
$rawIds = $input['ids'] ?? [];
$company = trim((string)($input['company'] ?? ''));
$credentialKey = trim((string)($input['credential'] ?? ''));

if (!is_array($rawIds)) {
    http_response_code(400);
    echo json_encode(['error' => 'ids 参数格式错误'], JSON_UNESCAPED_UNICODE);
    exit;
}
$ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), fn($v) => $v > 0)));
if (empty($ids)) {
    http_response_code(400);
    echo json_encode(['error' => '缺少 ids 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($company === '') {
    http_response_code(400);
    echo json_encode(['error' => '缺少 company 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($credentialKey === '') {
    http_response_code(400);
    echo json_encode(['error' => '未选择凭据'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 请求级校验，全在打开流之前：这类"整批都不该发"的情形要一次说清，而不是逐条报 N 遍 ──

// 门店必须是配置里的零售企业（未识别/批发主体/未知企业一律拒）
if (!Enterprise::isRetail($company)) {
    http_response_code(400);
    echo json_encode(['error' => "「{$company}」不是零售企业，本入口只补传门店单据"], JSON_UNESCAPED_UNICODE);
    exit;
}

// 凭据必须是这家门店的、且四字段填齐。纵深而非重复：RetailRetransmit 逐条还会再拦一次
// （它才是可信边界，这里只是让"整批都发不出去"的情形早点说清）
$credential = Enterprise::credential($company, $credentialKey);
if ($credential === null) {
    http_response_code(400);
    echo json_encode(['error' => "门店「{$company}」没有凭据位「{$credentialKey}」，拒绝补传"], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!Enterprise::credentialConfigured($credential)) {
    http_response_code(400);
    echo json_encode(['error' => "门店「{$company}」的凭据「{$credentialKey}」尚未配齐（AppKey/SECRETKEY 未到手），拒绝补传"], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = Database::getInstance();
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$rows = $db->query("SELECT * FROM upload_tasks WHERE id IN ({$placeholders})", $ids);

$taskById = [];
foreach ($rows as $row) {
    $taskById[(int)$row['id']] = $row;
}

// 按页面给的顺序逐条取（页面按 rq/id 排好序），同时校验归属：混入别家门店的 id 整批拒绝
$tasks = [];
$missingIds = [];
foreach ($ids as $id) {
    $task = $taskById[$id] ?? null;
    if ($task === null) {
        // 任务可能在页面加载后被删/被处理：不当作请求错误，逐条报出来即可
        $missingIds[] = $id;
        continue;
    }
    if (trim((string)($task['company'] ?? '')) !== $company) {
        http_response_code(400);
        echo json_encode([
            'error' => "任务 #{$id}（单号 {$task['djbh']}）属于「{$task['company']}」，不属于门店「{$company}」，整批拒绝",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $tasks[] = $task;
}

// NDJSON 流式输出（与批发批量重传同格式，前端复用同一个进度弹窗）
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

/** 一条"没能进平台"的进度行：形状与真实结果一致（前端同一个渲染路径），response 里带原因 */
$emitRejected = function (string $djbh, string $message) use ($emit, $company) {
    $emit([
        'djbh' => $djbh,
        'ent_name' => '',
        'company' => $company,
        'success' => false,
        'request_status' => '请求失败',
        'response_status' => null,
        'response' => json_encode(['error' => $message], JSON_UNESCAPED_UNICODE),
    ]);
};

$okTasks = 0;
$badTasks = 0;

foreach ($missingIds as $id) {
    $badTasks++;
    $emitRejected("任务 #{$id}", '任务不存在（可能已被删除），未补传');
}

$retransmit = new RetailRetransmit();
foreach ($tasks as $task) {
    try {
        // 逐条调用同一份实现；某条被拒只影响它自己，进度由 shared 实现逐子单回调
        $result = $retransmit->retransmit($task, $credentialKey, $db, $emit);
        $result['failed'] === 0 ? $okTasks++ : $badTasks++;
    } catch (\Throwable $e) {
        $badTasks++;
        $emitRejected((string)$task['djbh'], $e->getMessage());
    }
}

// total/success/failed 按**单据**计（单条入口那份是按子单计的：那里一次只传一张单）
echo json_encode([
    '_final' => true,
    'success' => true,
    'result' => [
        'total' => count($tasks) + count($missingIds),
        'success' => $okTasks,
        'failed' => $badTasks,
    ],
], JSON_UNESCAPED_UNICODE) . "\n";
