<?php
/**
 * API: GET /api/manual_retail_tasks — 手动上传页门店分支的补传清单
 *
 * 只服务一个页面（手动上传页选定门店后的清单）。**筛选构造走 App\RecordQuery**
 * （TYPE_RETAIL_TASKS）——与导出同一段代码，"导出的行数与页面一致"是构造上的性质，
 * 不在这个文件里再写第二份 WHERE（08 票之前四处各写一份，实测漂移过）。
 *
 * 口径（用户 2026-09-30 定）：
 * - `company` 必填且必须是配置里的零售企业：未识别（认领失败的门店）、批发主体、未知企业
 *   都不该出现在这条清单里
 * - `source = 'retail'` 写死（这个清单就是门店采集来的单，不由调用方给）
 * - `task_status` **可为空**：默认由页面的状态下拉给"待补传"，也可切"已处理/全部"——
 *   补传失败会把任务翻成"已处理"并从待补传里消失（ADR 0011），能切过去才在本页重传
 * - 排序 `rq DESC, id DESC`（单据日期倒序）——操作者在第一页看到的就是最近要处理的单
 *
 * 分页与上传任务页同款（page_num + 每页 20 条，回 page/per_page/total_pages），页面据此渲染
 * 同一套分页条。**分页取代了原先的 MAX_ROWS=200 截断**：那时候是把"看不全"的后果推给操作者
 * （"仅列出最早的 N 条，处理后再刷新"），分页是把它解决掉。
 *
 * 追溯码随列表一并返回（每行的"查看追溯码"按钮要用，交互与上传任务页同一套），编辑弹窗也直接
 * 用列表里的行数据回填。这确实比原先"只回码数"大：实测单张单据码数上限 1,718 ≈ 34KB，
 * 一页 20 条最坏 ~680KB。改成点击时按 id 再拉一次的话，页面上要多维护一套加载态与失败态，
 * 省下的却只有当页这点带宽——不值。
 *
 * 入参：company（门店名，页面下拉选定）、page_num（可选，默认 1），
 *       另支持与上传任务页同名的筛选参数（djbh / task_status / response_status /
 *       date_from,date_to=单据日期 / created_from,created_to=补传任务创建时间）
 * 返回：{data: [{…upload_tasks 全部列, code_count}], total, page, per_page, total_pages}
 */

use App\Auth;
use App\BillType;
use App\Database;
use App\Enterprise;
use App\RecordQuery;
use App\TraceSplitter;

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

// company 上面已校验非空，这里不会再抛（build() 对门店清单缺 company 是 fail-closed 抛异常）
$query = RecordQuery::build(RecordQuery::TYPE_RETAIL_TASKS, $_GET);

$total = (int)($db->queryOne(
    "SELECT COUNT(*) AS cnt FROM {$query['count_from']} {$query['where']}",
    $query['params']
)['cnt'] ?? 0);

$rows = $db->query(
    "{$query['select']} {$query['where']} {$query['order']} LIMIT ? OFFSET ?",
    array_merge($query['params'], [PER_PAGE, $offset])
);

foreach ($rows as &$row) {
    $row['id'] = (int)$row['id'];
    $row['trace_codes'] = (string)($row['trace_codes'] ?? '');
    // 码数走单一来源（页面"码数"列、追溯码弹窗、导出都读这一个口径）
    $row['code_count'] = TraceSplitter::countCodes($row['trace_codes']);
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
