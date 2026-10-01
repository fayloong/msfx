<?php
/**
 * App\RecordQuery 单元测试（自包含断言脚本，无框架依赖）
 *
 * 运行: php tests/record_query_test.php
 *
 * 测试目标: 三个数据入口（上传任务 / 已上传 / 失败记录）的筛选构造。
 *   这一个类存在的全部理由，是让**列表与导出走同一段代码**——08 票之前它们各写一份，
 *   实测漂移过一次（导出漏了失败页那句 quantity_check 豁免，页面上看得见的告警导出的文件里没有）。
 *   故这里钉的不是"SQL 长什么样"，而是几条**漂移了就会出事**的性质：
 *
 *   - 各页的固定口径还在（失败页的判重限定同企业 + quantity_check 豁免、已上传页的两个成功状态）
 *   - `?` 占位符与 params **数量恒等**——条件成对追加是合并四处拷贝后最该保住的性质，
 *     漏配一个参数会让后面所有参数整体错位，且只在运行时表现为"筛选没生效"
 *   - 日期参数名到列的映射：`date_from/to` 指单据日期、`created_from/to` 指任务创建时间，
 *     三页的映射各不相同，改错就是"日期筛选悄悄失效"
 *
 * 第 4 类 retail_tasks（门店补传清单）随门店分支撤销而删除，见
 * .scratch/retail-chain/issues/14-retail-manual-entry.md
 */

require __DIR__ . '/../vendor/autoload.php';

use App\RecordQuery;

$failures = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    global $failures;
    if ($cond) {
        echo "PASS  $name\n";
    } else {
        $failures++;
        echo "FAIL  $name  $detail\n";
    }
}

/** `?` 出现次数：与 params 数量比对，验证"条件与参数成对追加" */
function placeholders(array $query): int
{
    return substr_count($query['where'], '?');
}

/** 某类型在"把下拉全填上"时的完整 filters（页面/导出实际会送的那一组） */
function fullFilters(): array
{
    return [
        'djbh' => 'XSO',
        'ent_name' => '某单位',
        'task_status' => '等待上传',
        'response_status' => '上传成功',
        'source' => 'cron',
        'company' => '某某门店',
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-30',
        'created_from' => '2026-09-01',
        'created_to' => '2026-09-30',
        // 日志两页的单据日期走这两个参数名（它们的 date_from/to 指的是任务创建时间）
        'rq_from' => '2026-09-01',
        'rq_to' => '2026-09-30',
    ];
}

// ---------- 用例 1: 未知类型不静默（既有行为） ----------
$threw = false;
try {
    RecordQuery::build('nope', []);
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
check('未知类型抛异常（不静默返回空条件）', $threw);

// ---------- 用例 2: 上传任务页既有口径不回归 ----------
$q = RecordQuery::build(RecordQuery::TYPE_TASKS, fullFilters());
check('上传任务页排序仍为 id DESC', $q['order'] === 'ORDER BY id DESC', $q['order']);
check('上传任务页仍从 upload_tasks 取数', $q['count_from'] === 'upload_tasks', $q['count_from']);
check('上传任务页 source 可筛（非固定口径）', strpos($q['where'], 'source = ?') !== false, $q['where']);
check('上传任务页 company 可筛', strpos($q['where'], 'company = ?') !== false, $q['where']);
check('上传任务页 date_from/to 指单据日期 rq', strpos($q['where'], 'rq >= ?') !== false, $q['where']);
check('上传任务页 created_from/to 指任务创建时间', strpos($q['where'], 'date(created_at) >= ?') !== false, $q['where']);
check('上传任务页占位符与参数数量恒等', placeholders($q) === count($q['params']),
    placeholders($q) . ' vs ' . count($q['params']));

// ---------- 用例 3: 已上传页既有口径不回归 ----------
$q = RecordQuery::build(RecordQuery::TYPE_UPLOADED, fullFilters());
check(
    '已上传页固定口径：response_status 属两个成功状态',
    strpos($q['where'], "response_status IN ('上传成功', '单据重复')") !== false,
    $q['where']
);
check('已上传页从 upload_logs 取数', $q['count_from'] === 'upload_logs', $q['count_from']);
check('已上传页列名限定 upload_logs.', strpos($q['where'], 'upload_logs.company = ?') !== false, $q['where']);
check('已上传页 date_from/to 指任务创建时间', strpos($q['where'], 'date(upload_logs.created_at) >= ?') !== false, $q['where']);
check('已上传页 rq_from/to 指单据日期', strpos($q['where'], 'upload_logs.rq >= ?') !== false, $q['where']);
check('已上传页占位符与参数数量恒等', placeholders($q) === count($q['params']),
    placeholders($q) . ' vs ' . count($q['params']));

// ---------- 用例 4: 失败记录页既有口径不回归（08 票漂移过的那一处） ----------
$q = RecordQuery::build(RecordQuery::TYPE_FAILED, fullFilters());
$where = $q['where'];
check(
    '失败页固定口径：请求失败 或 响应不成功',
    strpos($where, "(upload_logs.request_status = '请求失败' OR upload_logs.response_status NOT IN ('上传成功', '单据重复'))") !== false,
    $where
);
check('失败页保留 quantity_check 豁免', strpos($where, "upload_logs.source = 'quantity_check' OR") !== false, $where);
check(
    '失败页判重限定同一企业（否则零售失败记录会被同号批发成功单顶掉）',
    strpos($where, 'ok.company = upload_logs.company') !== false,
    $where
);
check('失败页占位符与参数数量恒等', placeholders($q) === count($q['params']),
    placeholders($q) . ' vs ' . count($q['params']));

// ---------- 用例 5: response_status 在失败页的"请求失败"特例仍生效 ----------
$q = RecordQuery::build(RecordQuery::TYPE_FAILED, ['response_status' => '请求失败']);
check(
    '失败页选"请求失败"落到 request_status 列',
    strpos($q['where'], "request_status = '请求失败'") !== false && strpos($q['where'], 'response_status = ?') === false,
    $q['where']
);

echo "\n";
if ($failures === 0) {
    echo "全部通过 ✓\n";
    exit(0);
}
echo "失败 $failures 项 ✗\n";
exit(1);
