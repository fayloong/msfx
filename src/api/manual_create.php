<?php
/**
 * API: POST /api/manual/create — 手动新增单条上传任务并立即上传
 */

use App\Auth;
use App\Database;
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

$rq = trim($input['rq'] ?? '');
$djbh = trim($input['djbh'] ?? '');
$entName = trim($input['ent_name'] ?? '');
$billType = trim($input['bill_type'] ?? '');
$traceCodes = trim($input['trace_codes'] ?? '');
// 支持一行一个追溯码，自动转换为逗号分隔
$traceCodes = preg_replace('/\r\n|\r/', "\n", $traceCodes);
$traceCodes = preg_replace('/\n+/', ',', $traceCodes);
$traceCodes = trim($traceCodes, ',');

// 白名单
$allowedBillTypes = [
    '102', '103', '104', '107', '108', '110', '111', '112', '113',
    '201', '202', '203', '204', '205', '206', '207', '209', '211', '212', '214', '215', '216', '217', '237',
];

// 前端验证
$errors = [];
if (empty($rq)) $errors[] = '日期不能为空';
if (empty($djbh)) $errors[] = '单号不能为空';
if (empty($entName)) $errors[] = '往来单位不能为空';
if (empty($billType)) $errors[] = '单据类型不能为空';
elseif (!in_array($billType, $allowedBillTypes, true)) $errors[] = '无效的单据类型';
if (empty($traceCodes)) $errors[] = '追溯码不能为空';

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['error' => implode('; ', $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = Database::getInstance();

// 写入 upload_tasks
$db->execute(
    "INSERT INTO upload_tasks (rq, djbh, ent_name, trace_codes, task_status, source, created_at, updated_at) VALUES (?, ?, ?, ?, '等待上传', 'manual', datetime('now','localtime'), datetime('now','localtime'))",
    [$rq, $djbh, $entName, $traceCodes]
);
$taskId = $db->lastInsertId();

// NDJSON 流式输出
header('Content-Type: application/x-ndjson; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache');
ini_set('output_buffering', 'off');
while (ob_get_level()) { ob_end_clean(); }
ob_implicit_flush(true);

// 立即上传
try {
    $uploadService = new UploadService();
    $result = $uploadService->upload([[
        'type' => $billType,
        'rq' => $rq,
        'djbh' => $djbh,
        'ent_name' => $entName,
        'sn' => $traceCodes,
        'task_id' => $taskId,
    ]], function (array $progress) {
        echo json_encode($progress, JSON_UNESCAPED_UNICODE) . "\n";
        flush();
    });

    echo json_encode([
        '_final' => true,
        'success' => true,
        'task_id' => $taskId,
        'result' => $result,
    ], JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Exception $e) {
    $db->execute("UPDATE upload_tasks SET task_status = '已处理', request_status = '请求失败', response_status = NULL, updated_at = datetime('now','localtime') WHERE id = ?", [$taskId]);
    echo json_encode(['_final' => true, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n";
}
