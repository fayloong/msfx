<?php
/**
 * API: GET /api/failed — 失败记录列表
 */

use App\Auth;
use App\Database;

Auth::init();
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = Database::getInstance();
$page = max(1, intval($_GET['page_num'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = ["success = 0"];
$params = [];

if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where[] = "(djbh LIKE ? OR response LIKE ?)";
    $params = array_merge($params, [$search, $search]);
}

if (!empty($_GET['date_from'])) {
    $where[] = "date(created_at) >= ?";
    $params[] = $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $where[] = "date(created_at) <= ?";
    $params[] = $_GET['date_to'];
}
if (!empty($_GET['djbh'])) {
    $where[] = "djbh LIKE ?";
    $params[] = '%' . $_GET['djbh'] . '%';
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$countRow = $db->queryOne("SELECT COUNT(*) as cnt FROM upload_logs {$whereClause}", $params);
$total = $countRow['cnt'] ?? 0;

$rows = $db->query(
    "SELECT * FROM upload_logs {$whereClause} ORDER BY id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

echo json_encode([
    'data' => $rows,
    'total' => $total,
    'page' => $page,
    'per_page' => $perPage,
    'total_pages' => ceil($total / $perPage),
], JSON_UNESCAPED_UNICODE);
