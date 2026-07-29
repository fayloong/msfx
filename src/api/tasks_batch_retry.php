<?php
/**
 * API: POST /api/tasks/batch-retry — 批量重传
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
$ids = $input['ids'] ?? [];

if (empty($ids) || !is_array($ids)) {
    http_response_code(400);
    echo json_encode(['error' => '缺少 ids 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = Database::getInstance();
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$tasks = $db->query("SELECT * FROM upload_tasks WHERE id IN ({$placeholders})", $ids);

if (empty($tasks)) {
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache');
    echo json_encode(['_final' => true, 'success' => true, 'result' => ['total' => 0, 'success' => 0, 'failed' => 0]], JSON_UNESCAPED_UNICODE) . "\n";
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
    $bills = array_map(function ($t) {
        return [
            'type' => substr($t['djbh'], 0, 3),
            'rq' => $t['rq'],
            'djbh' => $t['djbh'],
            'ent_name' => $t['ent_name'],
            'sn' => $t['trace_codes'] ?? '',
            'task_id' => (int)$t['id'],
        ];
    }, $tasks);

    $uploadService = new UploadService();
    $result = $uploadService->upload($bills, function (array $progress) {
        echo json_encode($progress, JSON_UNESCAPED_UNICODE) . "\n";
        flush();
    });

    echo json_encode(['_final' => true, 'success' => true, 'result' => $result], JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Throwable $e) {
    echo json_encode(['_final' => true, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n";
}
