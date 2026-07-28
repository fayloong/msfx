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
    echo json_encode(['success' => true, 'result' => ['total' => 0, 'success' => 0, 'failed' => 0]], JSON_UNESCAPED_UNICODE);
    exit;
}

// 批量更新为上传中
$db->execute("UPDATE upload_tasks SET status = '上传中', updated_at = datetime('now','localtime') WHERE id IN ({$placeholders})", $ids);

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
    $result = $uploadService->upload($bills);

    echo json_encode(['success' => true, 'result' => $result], JSON_UNESCAPED_UNICODE);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
