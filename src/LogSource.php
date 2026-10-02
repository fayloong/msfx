<?php
/**
 * 「来源」（`upload_tasks.source` / `upload_logs.source`）的取值词表——中文标签与徽标色的
 * **唯一事实源**。
 *
 * 取值本身由各自的写入方决定（fetch_bills 写 cron、check_quantity 写 quantity_check……），
 * 这里只管"这个值怎么给人看"：中文标签、徽标色、筛选下拉的选项。三个视图与导出都从这儿取，
 * 于是新增一个来源只改这一处。
 *
 * 收口之前是三份各写各的 JS map，键集互不相同：已上传页与失败页认得 `retail_retry` 不认得
 * `retail`，任务页反过来；而 `quantity_check` **三份都没有**——数量对账的告警因此在失败记录页
 * 直出机器值、按来源也筛不出来（`.scratch/retail-chain/issues/09-docs-closeout.md` 记的欠账，
 * 本轮还上）。
 *
 * 各取值的出处（tests/log_source_test.php 里另列了一份，两边互为对照）：
 *
 *   cron            fetch_bills.php（批发采集落库）
 *   manual          手动上传（manual_create / manual_import）与门店手工建单（RetailManualEntry::LOG_SOURCE）
 *   batch_check     check_bill_status.php / check_failed_logs.php（批量核查）
 *   batch_retry     批量重传（tasks_retry / tasks_batch_retry）
 *   retail          fetch_bills_retail.php（零售采集）——**只出现在任务表**，日志里没有这个值
 *   retail_retry    RetailRetransmit::SOURCE（门店补传）
 *   retail_external 外部系统上传的记录（采集分流出的一支，见 .scratch/retail-collection-split/spec.md §4）
 *   quantity_check  check_quantity.php（数量对账）——**只写 upload_logs**
 *
 * 两条刻意保留的空白：
 *   - **历史空串**：`source` 列上线前的旧日志行是空串，本表没有这个键，`label('')` 回落成空串
 *     （页面那侧另有 `|| '-'` 兜成短横）——与改动前逐字一致。
 *   - **未知取值**：`label()` 回落成机器值本身。显示机器值总比显示空白强：漏配一个标签时，
 *     页面/导出上还认得出是哪来的数据，不至于静默变成一列空白。
 */
namespace App;

class LogSource
{
    /**
     * 取值 => [中文标签, 徽标色]
     *
     * 顺序即两个日志页筛选下拉的顺序。徽标色是给人眼看的分辨率，不是严重程度：
     *   - `retail_external` 给灰：本项目**唯一不经过自己上传**的来源，与零售补传（黑）一眼分得开；
     *   - `quantity_check` 给红：它只写"数量不符"的告警行，出现在失败记录页上就是让人去看的。
     * 剩下六色沿用收口前各自的取值，一个像素都没动。
     */
    private const MAP = [
        'cron'            => ['定时采集', 'bg-primary'],
        'manual'          => ['手动上传', 'bg-success'],
        'batch_check'     => ['批量核查', 'bg-info'],
        'batch_retry'     => ['批量重传', 'bg-warning text-dark'],
        'retail'          => ['零售采集', 'bg-dark'],
        'retail_retry'    => ['零售补传', 'bg-dark'],
        'retail_external' => ['外部上传', 'bg-secondary'],
        'quantity_check'  => ['数量对账', 'bg-danger'],
    ];

    /**
     * 全部取值 => 中文标签（顺序同 MAP）
     *
     * 两个日志页的筛选下拉**直接 foreach 它**渲染 `<option>`：下拉因此天然覆盖全部取值，
     * 不会出现"页面认得某个值却筛不出来"。任务页的 JS map 也用它（下拉成员另说，那一页只列
     * 任务表会出现的取值）。
     */
    public static function labels(): array
    {
        return array_map(fn(array $meta) => $meta[0], self::MAP);
    }

    /**
     * 全部取值 => 徽标色（顺序同 MAP）
     *
     * 未知取值的兜底色不在本表里，仍由视图那侧 `|| 'bg-secondary'` 兜——那是"这一行渲染不出来"
     * 的显示层兜底，不属于已知词表。
     */
    public static function badges(): array
    {
        return array_map(fn(array $meta) => $meta[1], self::MAP);
    }

    /**
     * 单个取值的中文标签；未知取值（含历史空串）原样返回
     *
     * 导出的「来源」列与任务页下拉走这一个——xlsx 里由机器值变成中文标签就是这儿的效果。
     */
    public static function label(string $value): string
    {
        return self::MAP[$value][0] ?? $value;
    }
}
