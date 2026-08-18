<?php
/**
 * API: GET /api/failed — 失败记录列表
 */

use App\Auth;
use App\BillType;
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

// 排除该单号已有"上传成功/单据重复"记录的日志行——重传成功后旧失败记录不再显示。
// 例外：quantity_check 来源的记录（数量对账"数量不符"告警）不受此约束——其单号必然存在
// batch_check 的"上传成功"记录（数量对账仅查已上传成功单），若参与 NOT EXISTS 会被全部隐藏，
// 导致告警出口（Web 失败记录页）失效。该来源记录在下次数量对账重跑时自动按新判定清理。
$where = ["(upload_logs.request_status = '请求失败' OR upload_logs.response_status NOT IN ('上传成功', '单据重复'))",
          "(upload_logs.source = 'quantity_check' OR NOT EXISTS (SELECT 1 FROM upload_logs ok WHERE ok.djbh = upload_logs.djbh AND ok.response_status IN ('上传成功', '单据重复')))"];
$params = [];

if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where[] = "(upload_logs.djbh LIKE ? OR upload_logs.ent_name LIKE ? OR upload_logs.trace_codes LIKE ? OR upload_logs.request_status LIKE ? OR upload_logs.response_status LIKE ? OR upload_logs.response LIKE ?)";
    $params = array_merge($params, [$search, $search, $search, $search, $search, $search]);
}

if (!empty($_GET['date_from'])) {
    $where[] = "date(upload_logs.created_at) >= ?";
    $params[] = $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $where[] = "date(upload_logs.created_at) <= ?";
    $params[] = $_GET['date_to'];
}
if (!empty($_GET['rq_from'])) {
    $where[] = "upload_logs.rq >= ?";
    $params[] = $_GET['rq_from'];
}
if (!empty($_GET['rq_to'])) {
    $where[] = "upload_logs.rq <= ?";
    $params[] = $_GET['rq_to'];
}
if (!empty($_GET['djbh'])) {
    $where[] = "upload_logs.djbh LIKE ?";
    $params[] = '%' . $_GET['djbh'] . '%';
}
if (!empty($_GET['ent_name'])) {
    $where[] = "upload_logs.ent_name LIKE ?";
    $params[] = '%' . $_GET['ent_name'] . '%';
}
if (!empty($_GET['response_status'])) {
    if ($_GET['response_status'] === '请求失败') {
        $where[] = "upload_logs.request_status = '请求失败'";
    } else {
        $where[] = "upload_logs.response_status = ?";
        $params[] = $_GET['response_status'];
    }
}
if (!empty($_GET['source'])) {
    $where[] = "upload_logs.source = ?";
    $params[] = $_GET['source'];
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$countRow = $db->queryOne("SELECT COUNT(*) as cnt FROM upload_logs {$whereClause}", $params);
$total = $countRow['cnt'] ?? 0;

$rows = $db->query(
    "SELECT upload_logs.*, t.bill_type AS t_bill_type FROM upload_logs LEFT JOIN upload_tasks t ON t.id = upload_logs.task_id {$whereClause} ORDER BY upload_logs.id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

foreach ($rows as &$row) {
    $row['bill_type'] = BillType::normalize($row['t_bill_type'] ?? '', $row['djbh'] ?? '');
    unset($row['t_bill_type']);
}
unset($row);

echo json_encode([
    'data' => $rows,
    'total' => $total,
    'page' => $page,
    'per_page' => $perPage,
    'total_pages' => ceil($total / $perPage),
], JSON_UNESCAPED_UNICODE);
