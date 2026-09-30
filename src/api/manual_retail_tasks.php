<?php
/**
 * API: GET /api/manual_retail_tasks — 手动上传页零售分支的"待补传"清单
 *
 * 只服务一个页面（手动上传页选定门店后的清单），故不复用 api/tasks.php 那套通用筛选：
 * 这里是一个**固定口径**的队列视图——该门店、采集来源、待补传，三个条件写死。
 *
 * 只回清单要用的字段：**不含追溯码**（单张单据最多 1,718 个码 ≈ 34KB，几百张全量发到页面
 * 只是浪费带宽与 DOM 内存），只回码数——由 SQL 数逗号个数得出。
 *
 * 入参：company（门店名，页面下拉选定）
 * 返回：{data: [{id, djbh, rq, bill_type, code_count}], total, shown}
 *       total 是该门店待补传总数，shown 是本次返回条数（上限 MAX_ROWS，命中上限时页面要说明）
 */

use App\Auth;
use App\BillType;
use App\Database;
use App\Enterprise;

/** 一次最多返回多少条：待补传是队列，正常量级几十条；积压上千条时列表本身也点不动，截断并告知 */
const MAX_ROWS = 200;

Auth::init();
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$company = trim((string)($_GET['company'] ?? ''));
if ($company === '') {
    http_response_code(400);
    echo json_encode(['error' => '缺少 company 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 只认配置里的零售企业：未识别（认领失败的门店）、批发主体、未知企业都不该出现在这个列表里
if (!Enterprise::isRetail($company)) {
    http_response_code(400);
    echo json_encode(['error' => "「{$company}」不是零售企业，本入口只列门店单据"], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = Database::getInstance();

$where = "company = ? AND source = 'retail' AND task_status = '待补传'";
$total = (int)($db->queryOne("SELECT COUNT(*) AS cnt FROM upload_tasks WHERE {$where}", [$company])['cnt'] ?? 0);

// 码数用 SQL 数逗号（空串要单独判，否则会算成 1）
$rows = $db->query(
    "SELECT id, djbh, rq, bill_type,
            CASE WHEN TRIM(trace_codes) = '' THEN 0
                 ELSE LENGTH(trace_codes) - LENGTH(REPLACE(trace_codes, ',', '')) + 1 END AS code_count
     FROM upload_tasks
     WHERE {$where}
     ORDER BY rq ASC, id ASC
     LIMIT " . MAX_ROWS,
    [$company]
);

foreach ($rows as &$row) {
    $row['id'] = (int)$row['id'];
    $row['code_count'] = (int)$row['code_count'];
    $row['bill_type'] = BillType::normalize((string)($row['bill_type'] ?? ''), (string)($row['djbh'] ?? ''));
}
unset($row);

echo json_encode([
    'data' => $rows,
    'total' => $total,
    'shown' => count($rows),
], JSON_UNESCAPED_UNICODE);
