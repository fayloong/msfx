<?php
/**
 * API: GET /api/manual_retail_tasks — 手动上传页零售分支的"待补传"清单
 *
 * 只服务一个页面（手动上传页选定门店后的清单），故不复用 api/tasks.php 那套通用筛选：
 * 这里是一个**固定口径**的队列视图——该门店、采集来源、待补传，三个条件写死。
 *
 * 分页与上传任务页同款（page_num + 每页 20 条，回 page/per_page/total_pages），页面据此渲染
 * 同一套分页条。**分页取代了原先的 MAX_ROWS=200 截断**：那时候的处理是把"看不全"的后果推给
 * 操作者（"仅列出最早的 N 条，处理后再刷新"），分页是把它解决掉。
 *
 * 追溯码随列表一并返回（每行的"查看追溯码"按钮要用，交互与上传任务页同一套）。
 * 这确实比原先"只回码数"大：实测单张单据码数上限 1,718 ≈ 34KB，一页 20 条最坏 ~680KB。
 * 原注释担心的是"整个门店几百张全量发到页面"，分页之后这个量级已不成立；改成点击时按 id 再拉
 * 一次的话，页面上要多维护一套加载态与失败态，省下的却只有当页这点带宽——不值。
 *
 * 入参：company（门店名，页面下拉选定）、page_num（可选，默认 1）
 * 返回：{data: [{id, djbh, rq, bill_type, trace_codes, code_count}], total, page, per_page, total_pages}
 */

use App\Auth;
use App\BillType;
use App\Database;
use App\Enterprise;

/** 每页条数与上传任务页一致（api/tasks.php 里的 20），两页的分页条样式也是同一套 */
const PER_PAGE = 20;

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

$page = max(1, intval($_GET['page_num'] ?? 1));
$offset = ($page - 1) * PER_PAGE;

$db = Database::getInstance();

$where = "company = ? AND source = 'retail' AND task_status = '待补传'";
$total = (int)($db->queryOne("SELECT COUNT(*) AS cnt FROM upload_tasks WHERE {$where}", [$company])['cnt'] ?? 0);

// 码数用 SQL 数逗号（空串要单独判，否则会算成 1）
$rows = $db->query(
    "SELECT id, djbh, rq, bill_type, trace_codes,
            CASE WHEN TRIM(trace_codes) = '' THEN 0
                 ELSE LENGTH(trace_codes) - LENGTH(REPLACE(trace_codes, ',', '')) + 1 END AS code_count
     FROM upload_tasks
     WHERE {$where}
     ORDER BY rq ASC, id ASC
     LIMIT ? OFFSET ?",
    array_merge([$company], [PER_PAGE, $offset])
);

foreach ($rows as &$row) {
    $row['id'] = (int)$row['id'];
    $row['code_count'] = (int)$row['code_count'];
    $row['trace_codes'] = (string)($row['trace_codes'] ?? '');
    $row['bill_type'] = BillType::normalize((string)($row['bill_type'] ?? ''), (string)($row['djbh'] ?? ''));
}
unset($row);

echo json_encode([
    'data' => $rows,
    'total' => $total,
    'page' => $page,
    'per_page' => PER_PAGE,
    'total_pages' => (int)ceil($total / PER_PAGE),
], JSON_UNESCAPED_UNICODE);
