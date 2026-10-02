<?php
/**
 * 外部上传：外部系统已上传的门店单据——采集侧的**分流判据**与**记录形状**。
 *
 * 门店单据由外部系统负责上传，本项目只做「可见 + 人工补传」（见 docs/adr/0007）。2026-09-30
 * 的测试阶段临时口径曾用 `NOT EXISTS(update_state)` 把外部已上传的单整批挡在采集之外，
 * 代价是它们在页面上不可见——「这张单到底传没传」只能回源库查。本类把那条口径改成**分流**：
 * 单据全采进来，按源库状态表一分为二（见 .scratch/retail-collection-split/spec.md §1、§4）：
 *
 *   已上传 → 写一条「外部上传」记录进已上传记录页，**不建**补传任务；
 *   未上传 → 照旧建「等待上传」任务，由人在上传任务页补传。
 *
 * 判据只有一处：源库 `dyt.bs_msfx.dbo.update_state` 里有该单号（`bill_state` 实测全为 '1'，
 * 判存在即判已上传）。采集侧对它**只读**，且必须是 `EXISTS` 子查询而非 JOIN——那张表无主键、
 * 无唯一约束，实测 67,856 行里有 82 个单号是多行，JOIN 会把结果集放大。
 *
 * **状态闭环**（外部系统后来把某张单传成了 → 本地待办痕迹自动翻正）是票 03，同样归本类；
 * 本类当前只有分流与记录形状两件事。
 */
namespace App;

class RetailExternalUploads
{
    /**
     * 源库状态表（4 段式链接服务器名）——**全仓唯一一处硬编码**。
     *
     * 采集（scripts/fetch_bills_retail.php）、回写（App\UpdateStateWriter）、计数门卫（票 04）
     * 全部引用它：读侧与写侧各写一遍表名，改一处漏一处时两边会静默读写**不同的表**，
     * 而那种错不会有任何报错——只会表现为"回写了却还是被采回来"这种没头绪的现象。
     */
    public const TABLE = 'dyt.bs_msfx.dbo.update_state';

    /** 「外部上传」来源取值（App\LogSource 词表里的键）——页面与导出据此显示中文标签 */
    public const SOURCE = 'retail_external';

    /** 记录写「上传成功」：与上传链路同一个成功口径（单据已在平台上） */
    public const RESPONSE_STATUS = '上传成功';

    /** 分流决定（`decide()` 的返回值） */
    public const ACTION_RECORD = 'record'; // 写一条外部上传记录，**不建任务行**
    public const ACTION_TASK   = 'task';   // 建「等待上传」任务，由人补传
    public const ACTION_SKIP   = 'skip';   // 本地已有这条单的痕迹，整条跳过（幂等）

    /**
     * 分流决定：这一单该怎么落库。
     *
     *   uploaded | hasTask | hasSuccess | 动作
     *   ---------|---------|------------|--------------------------------------------------
     *   true     | 任意    | false      | RECORD —— 写外部上传记录，**不建任务**
     *   true     | 任意    | true       | SKIP   —— 本地已有成功记录（不变量：同一 (company, djbh)
     *                                       最多一条成功记录），重跑同一日期不再写第二条
     *   false    | 任意    | true       | SKIP   —— 已传成过（本项目补传的或外部系统的），不再入队
     *   false    | true    | false      | SKIP   —— 任务行已在，重采集不重复建
     *   false    | false   | false      | TASK   —— 建「等待上传」任务，由人补传
     *
     * 两处容易看漏的：
     *   - **已上传 + 有任务行**走 RECORD 而不是"顺手把那条任务翻掉"：任务行是本地待办痕迹，
     *     翻正是**状态闭环（票 03）**的事；采集只读源库、只按判据写新行，不回头改已有行
     *   - **未上传 + 有任务行**走 SKIP 而不是 UPDATE：重采集不该碰已有任务行的任何字段
     *
     * @param bool $uploaded   源库状态表里有该单号（采集 SQL 的 EXISTS 子查询给的标志）
     * @param bool $hasTask    本地已有该 (company, djbh) 的任务行
     * @param bool $hasSuccess 本地已有该 (company, djbh) 的成功记录（上传成功/单据重复）
     * @return string ACTION_* 之一
     */
    public static function decide(bool $uploaded, bool $hasTask, bool $hasSuccess): string
    {
        if ($uploaded) {
            return $hasSuccess ? self::ACTION_SKIP : self::ACTION_RECORD;
        }
        return ($hasTask || $hasSuccess) ? self::ACTION_SKIP : self::ACTION_TASK;
    }

    /**
     * 构造一条「外部上传」记录的列取值（喂给 `App\LogWriter::write()`）。
     *
     * 纯函数：不发调用、不读库、不写日志——记录形状因此能被独立断言
     * （tests/retail_external_uploads_test.php）。
     *
     * 几处刻意的取值：
     *   - `request_status` 留 null：**本项目没有发起任何请求**，写「请求成功」是失真——
     *     那会让它在详情弹窗里看起来像一次真实调用
     *   - `task_id = 0`：不关联任务行（这张单根本没建任务；0 是既有约定的"无关联"）
     *   - `response` 写一段 JSON 说明出处：已上传页的「API 返回详情」弹窗里看得见它**为什么
     *     在这儿**，不至于让人以为本项目真调过一次平台
     *
     * @param array{djbh:string, rq:string, trace_codes:string, company:string, credential:?string} $bill
     * @return array 可直接交给 LogWriter::write() 的记录
     */
    public static function buildRecord(array $bill): array
    {
        return [
            'task_id'         => 0,
            'djbh'            => (string)($bill['djbh'] ?? ''),
            // 零售的往来单位是 from/to 两个平台 ID，本链路从不写名字（与补传链路一致）
            'ent_name'        => '',
            'trace_codes'     => (string)($bill['trace_codes'] ?? ''),
            'rq'              => (string)($bill['rq'] ?? ''),
            'request_status'  => null,
            'response_status' => self::RESPONSE_STATUS,
            'response'        => json_encode([
                'external_upload' => true,
                'judged_by'       => self::TABLE,
                'reason'          => '外部系统已上传该单据（源库状态表里有该单号），本项目未发起任何平台请求',
            ], JSON_UNESCAPED_UNICODE),
            'source'          => self::SOURCE,
            'company'         => (string)($bill['company'] ?? ''),
            // 认领不到门店时是 null（company 为「未识别」）——原样下传，由 LogWriter 落库
            'credential'      => $bill['credential'] ?? null,
        ];
    }

    /**
     * 把一条已上传的单落成「外部上传」记录（JSONL + `upload_logs`）。
     *
     * **记录的"已有成功记录"这个判据由调用方给**（见 `decide()` 的 `$hasSuccess`）：采集按批
     * 取本地痕迹时顺带就有了，在这里再查一次等于每条单据多一次往返。本方法不做去重查询。
     *
     * 与类里其余方法一样是静态的：这个类不带状态（判定与形状都是纯函数）——注入一个
     * LogWriter 的构造参数曾经在这儿，但全仓无人传，纯属给将来准备的钩子，删了。
     * 需要写入行为可替换时（真要 mock 它）再引入不迟。
     */
    public static function record(array $bill): void
    {
        (new LogWriter())->write(self::buildRecord($bill));
    }
}
