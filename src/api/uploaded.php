<?php
/**
 * API: GET /api/uploaded — 上传成功记录列表
 */

use App\Auth;
use App\BillType;
use App\Database;
use App\RecordQuery;

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

// 筛选条件构造在 App\RecordQuery——本页、另两页与导出共用同一份实现（见该类注释）
$query = RecordQuery::build(RecordQuery::TYPE_UPLOADED, $_GET);

$countRow = $db->queryOne("SELECT COUNT(*) as cnt FROM {$query['count_from']} {$query['where']}", $query['params']);
$total = $countRow['cnt'] ?? 0;

$rows = $db->query(
    "{$query['select']} {$query['where']} {$query['order']} LIMIT ? OFFSET ?",
    array_merge($query['params'], [$perPage, $offset])
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
