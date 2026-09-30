<?php
/**
 * API: POST /api/tasks_retry_retail — 零售门店单据的补传（人工逐条触发）
 *
 * 本文件只做「解析请求 + 流式输出」：补传的流程（三关 fail-closed、拆单、调用、写日志、翻任务状态）
 * 全在 App\RetailRetransmit —— 批量入口（tasks_batch_retry_retail）用的是同一份实现（工单 07）。
 *
 * 与批发重传（tasks_retry / tasks_batch_retry）刻意分成两个入口：那条走 kyt 接口 + ent_list
 * 往来单位缓存，这条走 lsyd 接口 + 源表平台 ID，装配与凭据来源完全不同（见 docs/adr/0010）。
 * 合成一条"按企业类型分发"的路由，只会让两边都难读；分开后各自的 fail-closed 关口也一目了然。
 *
 * **补传是向平台的真实申报**，装配错一项就是把单据报到错误主体、在平台上不可逆，
 * 故所有校验都发生在第一次平台调用之前（见 docs/adr/0006 / docs/adr/0007）。
 *
 * 入参：{id: 任务 ID}
 * - 单据元数据一律取自**采集时落库的记录**，不接受调用方传任何单据字段（票面：不提供从零手工录入。
 *   手工录 4 个平台 ID 几乎必然出错，且本轮不查平台，录错了察觉不了）
 * - 用哪套凭据**不由调用方给**：门店与凭据是 1:1（docs/adr/0012），服务端据任务行的 company
 *   取该门店那套。入参里没有这个键，也就没有"传错一套"的路径
 */

use App\Auth;
use App\Database;
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
$id = $input['id'] ?? null;

if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => '缺少 id 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = Database::getInstance();
$task = $db->queryOne("SELECT * FROM upload_tasks WHERE id = ?", [$id]);

if (!$task) {
    http_response_code(404);
    echo json_encode(['error' => '任务不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

// NDJSON 流式输出（与批发重传同格式，前端复用同一个进度弹窗）
header('Content-Type: application/x-ndjson; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache');
ini_set('output_buffering', 'off');
while (ob_get_level()) { ob_end_clean(); }
ob_implicit_flush(true);

try {
    $result = (new RetailRetransmit())->retransmit($task, $db, function (array $progress) {
        echo json_encode($progress, JSON_UNESCAPED_UNICODE) . "\n";
        flush();
    });

    echo json_encode(['_final' => true, 'success' => true, 'result' => $result], JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Throwable $e) {
    // **不复位任务状态**（批发链路那两个入口会复位，本入口刻意不照搬）：这条链路上任务行只在
    // 平台调用**之后**被写（RetailRetransmit::updateTaskStatus 是唯一的写入点），所以异常要么发生在
    // 第一次调用之前（任务行根本没被动过，复位是空操作），要么发生在某个子单已完成之后（此时那一写
    // 就是本次尝试的真实结果，复位反而把已记录的结果抹成 NULL）。凭据列同理不回滚——它记的是
    // "这次实际用了哪套"，而抛异常意味着根本没机会用上凭据。
    echo json_encode(['_final' => true, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n";
}
