<?php
/**
 * API: DELETE /api/logs — 删除单条上传日志记录
 */

use App\Auth;
use App\Database;

Auth::init();
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = $_GET['id'] ?? null;
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => '缺少 id 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = Database::getInstance();
$db->execute("DELETE FROM upload_logs WHERE id = ?", [$id]);
echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
