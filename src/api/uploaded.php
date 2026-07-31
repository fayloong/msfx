<?php
/**
 * API: GET /api/uploaded — 已上传记录列表
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

$where = ["response_status IN ('上传成功', '单据重复')"];
$params = [];

if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where[] = "(djbh LIKE ? OR ent_name LIKE ? OR trace_codes LIKE ? OR request_status LIKE ? OR response_status LIKE ? OR response LIKE ?)";
    $params = array_merge($params, [$search, $search, $search, $search, $search, $search]);
}

if (!empty($_GET['date_from'])) {
    $where[] = "date(created_at) >= ?";
    $params[] = $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $where[] = "date(created_at) <= ?";
    $params[] = $_GET['date_to'];
}
if (!empty($_GET['rq_from'])) {
    $where[] = "rq >= ?";
    $params[] = $_GET['rq_from'];
}
if (!empty($_GET['rq_to'])) {
    $where[] = "rq <= ?";
    $params[] = $_GET['rq_to'];
}
if (!empty($_GET['djbh'])) {
    $where[] = "djbh LIKE ?";
    $params[] = '%' . $_GET['djbh'] . '%';
}
if (!empty($_GET['ent_name'])) {
    $where[] = "ent_name LIKE ?";
    $params[] = '%' . $_GET['ent_name'] . '%';
}
if (!empty($_GET['response_status'])) {
    $where[] = "response_status = ?";
    $params[] = $_GET['response_status'];
}
if (!empty($_GET['source'])) {
    $where[] = "source = ?";
    $params[] = $_GET['source'];
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
