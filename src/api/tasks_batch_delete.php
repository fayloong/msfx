<?php
/**
 * API: POST /api/tasks/batch-delete — 批量删除
 */

use App\Auth;
use App\Database;

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
$db->execute("DELETE FROM upload_tasks WHERE id IN ({$placeholders})", $ids);

echo json_encode(['success' => true, 'deleted' => count($ids)], JSON_UNESCAPED_UNICODE);
