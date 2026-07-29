<?php
/**
 * API: POST /api/tasks/:id/retry — 单条重传
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
$id = $input['id'] ?? ($_GET['id'] ?? null);

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

// NDJSON 流式输出
header('Content-Type: application/x-ndjson; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache');
ini_set('output_buffering', 'off');
while (ob_get_level()) { ob_end_clean(); }
ob_implicit_flush(true);

try {
    $uploadService = new UploadService();
    $result = $uploadService->upload([[
        'type' => substr($task['djbh'], 0, 3),
        'rq' => $task['rq'],
        'djbh' => $task['djbh'],
        'ent_name' => $task['ent_name'],
        'sn' => $task['trace_codes'] ?? '',
        'task_id' => (int)$task['id'],
    ]], function (array $progress) {
        echo json_encode($progress, JSON_UNESCAPED_UNICODE) . "\n";
        flush();
    });

    echo json_encode(['_final' => true, 'success' => true, 'result' => $result], JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Throwable $e) {
    // 尝试恢复状态，忽略数据库错误
    try {
        $db->execute("UPDATE upload_tasks SET task_status = '等待上传', request_status = NULL, response_status = NULL, updated_at = datetime('now','localtime') WHERE id = ?", [$id]);
    } catch (\Throwable $dbEx) {
        // 忽略
    }
    echo json_encode(['_final' => true, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n";
}
