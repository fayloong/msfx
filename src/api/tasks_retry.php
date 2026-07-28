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

// 更新状态为上传中
$db->execute("UPDATE upload_tasks SET status = '上传中', updated_at = datetime('now','localtime') WHERE id = ?", [$id]);

try {
    $uploadService = new UploadService();
    $result = $uploadService->upload([[
        'type' => substr($task['djbh'], 0, 3),
        'rq' => $task['rq'],
        'djbh' => $task['djbh'],
        'ent_name' => $task['ent_name'],
        'sn' => $task['trace_codes'] ?? '',
        'task_id' => (int)$task['id'],
    ]]);

    echo json_encode(['success' => true, 'result' => $result], JSON_UNESCAPED_UNICODE);
} catch (\Exception $e) {
    $db->execute("UPDATE upload_tasks SET status = '任务失败', updated_at = datetime('now','localtime') WHERE id = ?", [$id]);
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
