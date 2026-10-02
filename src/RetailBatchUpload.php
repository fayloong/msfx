<?php
/**
 * 零售门店单据的**批量上传**（票 06）：把「等待上传」的门店单逐条交给现有补传链路。
 *
 * **能力已备、暂不启用**——脚本（`scripts/upload_pending_retail.php`）不进 crontab，
 * 由人手动跑。它反转的是 ADR 0011 那条"补传只能人工触发、没有自动重试"：真挂上定时任务，
 * 申报就在人看不见的时候发出去了。**本类只是把那条能力装好**，启用与否由人决定（见该 ADR 的补注）。
 *
 * 与既有链路的分工（本类**不重写任何上传逻辑**）：
 *   - 逐条调用 `App\RetailRetransmit::retransmit()`——三关 fail-closed、拆单、装配、日志、
 *     任务状态翻转、源库回写全在它里面，本类只是把任务行递给它
 *   - 取数口径（`PENDING_SQL`）与"哪些行不该碰"（`run()` 里的判据）在本类；
 *     日志的 `source` 仍是 `retail_retry`（补传那个取值，不为本入口新造一个）
 *
 * 为什么主循环抽成收 `callable` 的纯函数（而不是把 if 写在脚本里）：票面要一条**自包含测试**
 * 证明"`--dry-run` 一次平台调用都不发"。没有这个接缝，那条只能靠人工跑一遍、看它没写日志来
 * "验证"。抽出来之后，测试注入一个"被调用就记一笔"的假回调即可断言（见
 * tests/retail_batch_upload_test.php 用例 2）——与 `App\RetailCollectionGate::guard()` 同款。
 */
namespace App;

class RetailBatchUpload
{
    /**
     * 取数口径（票 06）：**门店来源 + 还挂着「等待上传」**的行，按 id 升序。
     *
     * 三处刻意的取舍：
     *   - **不在这里筛掉 `未识别`**：票面要脚本打印"跳过未识别 N 条"，那个数只能从取回来的行里
     *     数（`run()` 逐条跳过并计数）。筛进 SQL 的话，日志上就只剩一个不知道多少的数
     *   - **按 id 升序**：`--limit` 给首跑小步走用（先传 2 张看看），"前 N 条"每次跑都得是同一批，
     *     否则小步走变成了随机抽样
     *   - **判据是 `source` 而非企业类型**：与上传任务页的补传按钮、批量重传的分流同一个开关
     *     （`未识别` 的行也在 `retail` 这一份里，由 `run()` 逐条跳过——同工单 15 的分流口径）。
     *     批发行（`source='cron'` 等）与已处理的行一个都不碰
     *
     * 列清单是补传装配要用的全集（`RetailRetransmit` 从任务行读元数据，不回头问源库）：
     * 少一列那条单就永远装配不出去，而这里不会报错——只会表现为一条带单号的拒绝。
     */
    public const PENDING_SQL = "SELECT id, rq, djbh, trace_codes, bill_type, company, credential,
                                       from_user_id, to_user_id, physic_type
                                  FROM upload_tasks
                                 WHERE source = 'retail' AND task_status = '等待上传'
                                 ORDER BY id";

    /**
     * 跑一轮批量上传：分流 → 限量 → 逐条交给 `$upload`（或 `--dry-run` 只报告）。
     *
     * 序列上的一处硬要求：**先跳过、后限量**。反过来的话，夹在队列里的 `未识别` 行会白占额度
     * ——`--limit=2` 遇上"未识别,门店,未识别,门店"就只能真传 1 张，而人以为自己限了 2 张。
     *
     * 逐条 try/catch：被三关 fail-closed 拒掉的一条（非门店 / 凭据未配齐 / 无路由 / 装配缺项）
     * 一个平台调用都没发，算失败并继续下一条——与上传任务页"批量重传"里零售那批的隔离口径一致
     * （工单 15：一条坏单不该把整批带下水）。
     *
     * @param array<int,array<string,mixed>> $tasks  `PENDING_SQL` 取回的行（含该被跳过的 `未识别`）
     * @param callable $upload   `fn(array $task): array{total:int,success:int,failed:int}`
     *                           生产上是 `RetailRetransmit::retransmit()` 的闭包；
     *                           **`$dryRun` 时一次都不调**
     * @param bool     $dryRun   只列不传：不调 `$upload`、不写任何库
     * @param int|null $limit    最多真传几条（null = 不限）；**跳过的行不占额度**
     * @param callable|null $report `fn(array $task, ?array $result, ?string $error): void`，逐条回调：
     *                           - `--dry-run`：`$result`/`$error` 均为 null（这一条只是计划）
     *                           - 真跑：`$result` 是上传返回、`$error` 是被拒原因（两者恰有其一非 null）
     * @return array{total:int,skipped:int,queued:int,remaining:int,success:int,failed:int,codes:int}
     *         `total` 取回的行数（含跳过）；`skipped` 非门店企业（含 `未识别`）的条数；
     *         `queued` 这轮实际处理（或计划）的条数；`remaining` 被 `--limit` 挡在外面、这轮没碰的条数
     */
    public static function run(
        array $tasks,
        callable $upload,
        bool $dryRun = false,
        ?int $limit = null,
        ?callable $report = null
    ): array {
        $stats = [
            'total' => count($tasks),
            'skipped' => 0,
            'queued' => 0,
            'remaining' => 0,
            'success' => 0,
            'failed' => 0,
            'codes' => 0,
        ];

        // ── 1. 分流：能传的进队列，其余（未识别、批发主体、空名、未知企业）只计数 ──
        // 判据用 Enterprise::isRetail 而不是 `$company === '未识别'`：后者只挡一个已知取值，
        // 前者把"批发行混进 retail 来源""企业名被改错"这类脏数据一并挡住（fail-closed）。
        // 这些行**不交给 upload**：RetailRetransmit 会拒它们，那只是多绕一圈、并在失败统计里
        // 混进一批"本来就不该由本脚本碰"的单，把真正的失败盖掉
        $queue = [];
        foreach ($tasks as $task) {
            $company = trim((string)($task['company'] ?? ''));
            if (!Enterprise::isRetail($company)) {
                $stats['skipped']++;
                continue;
            }
            $queue[] = $task;
        }

        // ── 2. --limit：截断的是"能传的那批"，没碰上的报个数（限量不等于当它们不存在）──
        if ($limit !== null && count($queue) > $limit) {
            $stats['remaining'] = count($queue) - $limit;
            $queue = array_slice($queue, 0, $limit);
        }

        // ── 3. 逐条：dry-run 只报告；真跑调 $upload，一条被拒不影响下一条 ──
        foreach ($queue as $task) {
            $stats['queued']++;
            // 码数用 TraceSplitter::countCodes（全站唯一口径：空串算 0）——日志里"N 单 / M 码"
            // 与页面"码数"列、导出对得上，靠的就是这一处不另写一份数逗号的
            $stats['codes'] += TraceSplitter::countCodes((string)($task['trace_codes'] ?? ''));

            if ($dryRun) {
                if ($report !== null) {
                    $report($task, null, null);
                }
                continue;
            }

            try {
                $result = $upload($task);
            } catch (\Throwable $e) {
                // 三关拒绝（一个平台调用都没发、库也一个字没动）或链路自身出错：这一条算失败，
                // 继续下一条。异常消息原样带出去——脚本要把它打成"这张单为什么没传"
                $stats['failed']++;
                if ($report !== null) {
                    $report($task, null, $e->getMessage());
                }
                continue;
            }

            // 成功口径与失败记录页一致（见 ADR 0011）：只有"全部子单都成"才算这一单成功；
            // 平台业务失败（如"存在已出售的码"）在 retransmit 里已翻成 failed，照实计
            if (($result['failed'] ?? 0) === 0) {
                $stats['success']++;
            } else {
                $stats['failed']++;
            }
            if ($report !== null) {
                $report($task, $result, null);
            }
        }

        return $stats;
    }
}
