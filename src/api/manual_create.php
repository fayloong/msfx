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
$traceCodes = trim($input['trace_codes'] ?? '');

// 前端验证
$errors = [];
if (empty($rq)) $errors[] = '日期不能为空';
if (empty($djbh)) $errors[] = '单号不能为空';
if (empty($entName)) $errors[] = '往来单位不能为空';
if (empty($traceCodes)) $errors[] = '追溯码不能为空';

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['error' => implode('; ', $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = Database::getInstance();

// 写入 upload_tasks
$db->execute(
    "INSERT INTO upload_tasks (rq, djbh, ent_name, trace_codes, status, source, created_at, updated_at) VALUES (?, ?, ?, ?, '等待上传', 'manual', datetime('now','localtime'), datetime('now','localtime'))",
    [$rq, $djbh, $entName, $traceCodes]
);
$taskId = $db->lastInsertId();

// 立即上传
try {
    $uploadService = new UploadService();
    $result = $uploadService->upload([[
        'type' => substr($djbh, 0, 3),
        'rq' => $rq,
        'djbh' => $djbh,
        'ent_name' => $entName,
        'sn' => $traceCodes,
        'task_id' => $taskId,
    ]]);

    echo json_encode([
        'success' => true,
        'task_id' => $taskId,
        'result' => $result,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Exception $e) {
    $db->execute("UPDATE upload_tasks SET status = '任务失败', updated_at = datetime('now','localtime') WHERE id = ?", [$taskId]);
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
