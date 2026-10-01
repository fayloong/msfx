<?php
/**
 * API: POST /api/manual/create_retail — 门店手工新增单条并立即上传
 *
 * 与批发那条（`manual_create.php`）分开成两个文件：字段、接口、凭据、落库主体全都不同
 * （批发固定落河药主体，这里落页面上选定的那家门店）。合成一个文件只会让两条链路互相牵连。
 *
 * 入参：{company, rq, djbh, bill_type, trace_codes, ent_name?}
 *   - `company` 是页面上选定的门店，**必须是配置里的零售企业**（否则整条拒绝）
 *   - `ent_name` 只在 104/203 需要（调拨单的 from/to 由它查出），321/116 传了也忽略
 *   - **没有 credential 入参**：门店与凭据 1:1，服务端按门店取（ADR 0012）
 *
 * 流程分两步（见 App\RetailManualEntry）：`prepare()` 校验 + 取凭据 + 解析对手方，失败直接 400——
 * 拒绝发生在落库之前，库里不留半条，进度弹窗里也不会出现"HTTP 400"这种最难读的错误；
 * `create()` 才是落库与上传，成功失败都留一行可追查的记录。
 */

use App\Auth;
use App\Database;
use App\RetailManualEntry;
use App\TraceSplitter;

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

// 支持一行一个追溯码，自动转换为逗号分隔（归一实现见 App\TraceSplitter::normalizeInput）
$traceCodes = TraceSplitter::normalizeInput(trim($input['trace_codes'] ?? ''));

$bill = [
    'company' => trim($input['company'] ?? ''),
    'rq' => trim($input['rq'] ?? ''),
    'djbh' => trim($input['djbh'] ?? ''),
    'bill_type' => trim($input['bill_type'] ?? ''),
    'ent_name' => trim($input['ent_name'] ?? ''),
    'trace_codes' => $traceCodes,
];

try {
    $prepared = RetailManualEntry::prepare($bill);
} catch (\RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// NDJSON 流式输出
header('Content-Type: application/x-ndjson; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache');
ini_set('output_buffering', 'off');
while (ob_get_level()) { ob_end_clean(); }
ob_implicit_flush(true);

try {
    $result = RetailManualEntry::create($prepared, Database::getInstance(), function (array $progress) {
        echo json_encode($progress, JSON_UNESCAPED_UNICODE) . "\n";
        flush();
    });

    echo json_encode([
        '_final' => true,
        'success' => true,
        'task_id' => $result['task_id'],
        'result' => [
            'total' => $result['total'],
            'success' => $result['success'],
            'failed' => $result['failed'],
        ],
    ], JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Exception $e) {
    // 走到这里说明落库之后出了意外（配置临时坏掉、库异常…）。**不复位任务状态**：那条行留在
    // "等待上传"里，人可以在上传任务页看见它并按门店补传，比抹成一条没有结果的终态强
    echo json_encode(['_final' => true, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n";
}
