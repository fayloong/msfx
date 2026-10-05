<?php
/**
 * 外部上传：外部系统已上传的门店单据——采集侧的**分流判据**、**记录形状**与**状态闭环**。
 *
 * 门店单据由外部系统负责上传，本项目只做「可见 + 人工补传」（见 docs/adr/0007）。2026-09-30
 * 的测试阶段临时口径曾用 `NOT EXISTS(update_state)` 把外部已上传的单整批挡在采集之外，
 * 代价是它们在页面上不可见——「这张单到底传没传」只能回源库查。本类把那条口径改成**分流**：
 * 单据全采进来，按源库状态表一分为二（见 .scratch/retail-collection-split/spec.md §1、§3、§4）：
 *
 *   已上传 → 写一条「外部上传」记录进已上传记录页，**不建**补传任务；
 *   未上传 → 照旧建「等待上传」任务，由人在上传任务页补传。
 *
 * **唯一的例外是 `--all` 全量快照**（票 05）：窗口内**未上传的历史单不建任务**，只留一个数
 * ——一次跑出成千上万条「等待上传」人工处理不现实，还会把待补传这份工作清单的信号淹没
 * （工作队列由日常采集按日累积；2026-10-02 首跑实测窗口内未上传 6,251 单，其中 6,245 单
 * 本地早有痕迹、真正没进过本系统的只有 6 单——决定与理由不变，量级以这次实测为准）。
 * 它在 `decide()` 上只多传一个 `buildTasks: false`，
 * 未上传 + 本地无痕那一格落到 ACTION_COUNT_ONLY；**日常口径一字不改**（默认参数）。
 *
 * 判据只有一处：源库 `dyt.bs_msfx.dbo.update_state` 里有该单号（`bill_state` 实测全为 '1'，
 * 判存在即判已上传）。采集侧对它**只读**，且必须是 `EXISTS` 子查询而非 JOIN——那张表无主键、
 * 无唯一约束，实测 67,856 行里有 82 个单号是多行，JOIN 会把结果集放大。
 *
 * **状态闭环**（`closeLoop()`，票 03）收拾的是分流管不到的那一半：一张单**先**以「等待上传」
 * 落进了本地、**后来**才被外部系统传成——采集侧只在写入新行时看判据，不回头改已有行（那是
 * 刻意的，见 `decide()`），于是那条任务行会永远挂在补传队列里。闭环每轮拿**本地待办清单**
 * （不是当次采集结果，故**跨日有效**）去状态表核对，命中后把痕迹翻正。
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
    public const ACTION_COUNT_ONLY = 'count_only'; // 快照（`--all`）：未上传的历史单不建任务，只计数

    /**
     * 单号 IN 列表分块大小（规避超长 SQL 与参数上限）。
     *
     * `public` 是给采集脚本用的：它也要按单号分块查本地痕迹（`array_chunk(…, self::IN_CHUNK_SIZE)`
     * 那两处）。两处各写一个 500 时，改了一处另一处不会报错——只会有一边的 IN 悄悄少几个参数。
     */
    public const IN_CHUNK_SIZE = 500;

    /**
     * 分流决定：这一单该怎么落库。
     *
     *   uploaded | hasTask | hasSuccess | buildTasks | 动作
     *   ---------|---------|------------|------------|------------------------------------------
     *   true     | 任意    | false      | 任意       | RECORD —— 写外部上传记录，**不建任务**
     *   true     | 任意    | true       | 任意       | SKIP   —— 本地已有成功记录（不变量：同一 (company, djbh)
     *                                                     最多一条成功记录），重跑同一日期不再写第二条
     *   false    | 任意    | true       | 任意       | SKIP   —— 已传成过（本项目补传的或外部系统的），不再入队
     *   false    | true    | false      | 任意       | SKIP   —— 任务行已在，重采集不重复建
     *   false    | false   | false      | true       | TASK   —— 建「等待上传」任务，由人补传
     *   false    | false   | false      | false      | COUNT_ONLY —— 快照：不建任务，只计数（见下）
     *
     * 三处容易看漏的：
     *   - **已上传 + 有任务行**走 RECORD 而不是"顺手把那条任务翻掉"：任务行是本地待办痕迹，
     *     翻正是**状态闭环（票 03）**的事；采集只读源库、只按判据写新行，不回头改已有行
     *   - **未上传 + 有任务行**走 SKIP 而不是 UPDATE：重采集不该碰已有任务行的任何字段
     *   - **`$buildTasks = false` 只改最后一格**：不能建任务 ≠ 什么都不写——已上传的照样写记录
     *     （快照的用途就是"页面上有历史可看"），本地已有痕迹的照样跳过（幂等判据一个字没变）。
     *     落到 COUNT_ONLY 的只有"未上传 **且** 本地一条痕迹都没有"的那些单：它们在本系统里
     *     **不可见**，快照给不出任务行，只能留一个数（这就是票面那句"窗口内未上传 N 张，未建任务"）。
     *     本地已有任务行的未上传单**不算**这个数——它在补传队列里看得见，不是欠账
     *
     * @param bool $uploaded   源库状态表里有该单号（采集 SQL 的 EXISTS 子查询给的标志）
     * @param bool $hasTask    本地已有该 (company, djbh) 的任务行
     * @param bool $hasSuccess 本地已有该 (company, djbh) 的成功记录（上传成功/单据重复）
     * @param bool $buildTasks 是否建「等待上传」任务；`false` 只该由 `--all` 快照传
     *                         （日常采集、闭环、手工建单都不传，走默认值）
     * @return string ACTION_* 之一
     */
    public static function decide(bool $uploaded, bool $hasTask, bool $hasSuccess, bool $buildTasks = true): string
    {
        if ($uploaded) {
            return $hasSuccess ? self::ACTION_SKIP : self::ACTION_RECORD;
        }
        if ($hasTask || $hasSuccess) {
            return self::ACTION_SKIP;
        }
        return $buildTasks ? self::ACTION_TASK : self::ACTION_COUNT_ONLY;
    }

    /**
     * 一轮采集的统计累加（纯函数）：把「这一单的动作 + 它的码数」并进计数，返回新的计数。
     *
     * `--dry-run` 打印的那句「**将写入 N 单 / M 码**」就是从这里来的，所以口径必须与"真跑写进去
     * 的东西"逐字一致：
     *   - `codes`（= M）**只累加真会落库的两种动作**（RECORD / TASK）的码数。被跳过与只计数的单据
     *     一个码都不进 M——否则预演报出来的码数比真跑写进去的多，那份数字就不再是"将写入"了
     *   - `records`（写记录）/ `tasks`（建任务）两者相加是 N；`count_only` 是快照里"未上传且本地
     *     无痕"的张数（**不进 N**，它不写库）；`skipped` 是本地已有痕迹、这次一条都没写的单数
     *   - 未知动作**抛异常**而不是静默丢弃：加一个 ACTION_* 却忘了在这里归类，统计就会悄悄少一块
     *
     * @param array<string,int> $counts 上一轮的计数（起手传空数组；`+=` 补齐缺失的键，故调用方不必先初始化）
     * @param string $action ACTION_* 之一
     * @param int    $codes  这一单的追溯码个数（去重后的，即真正会写进 `trace_codes` 的那些）
     * @return array{records:int,tasks:int,count_only:int,skipped:int,codes:int}
     * @throws \InvalidArgumentException 动作不在 ACTION_* 里
     */
    public static function tally(array $counts, string $action, int $codes): array
    {
        $counts += ['records' => 0, 'tasks' => 0, 'count_only' => 0, 'skipped' => 0, 'codes' => 0];

        switch ($action) {
            case self::ACTION_RECORD:
                $counts['records']++;
                $counts['codes'] += $codes;
                break;
            case self::ACTION_TASK:
                $counts['tasks']++;
                $counts['codes'] += $codes;
                break;
            case self::ACTION_COUNT_ONLY:
                $counts['count_only']++;
                break;
            case self::ACTION_SKIP:
                $counts['skipped']++;
                break;
            default:
                throw new \InvalidArgumentException("未知动作: {$action}");
        }

        return $counts;
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
     * @param string|null $reason 出处说明（写进 `response` 的 reason 字段）；`null` = 采集那条老话术
     *                            （源库状态表判的），闭环与平台核查各自传自己的（见 `applyActions()`）
     * @param string $judgedBy    `response` 里 `judged_by` 字段（判据出处）；默认源库状态表
     * @return array 可直接交给 LogWriter::write() 的记录
     */
    public static function buildRecord(array $bill, ?string $reason = null, string $judgedBy = self::TABLE): array
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
            'response'        => self::provenanceJson(
                $reason ?? '外部系统已上传该单据（源库状态表里有该单号），本项目未发起任何平台请求',
                $judgedBy
            ),
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

    // ---------------------------------------------------------------------------------------
    // 状态闭环（票 03）
    // ---------------------------------------------------------------------------------------

    /**
     * 闭环动作分类：本地待办 × 源库判据 → 这一键该翻什么（纯函数，可独立断言）。
     *
     * 判据表（`$pending` 里的每个键，即每个 (company, djbh)）：
     *
     *   源库已上传 | 有任务行 | 本地已有成功记录 | 动作
     *   -----------|----------|------------------|------------------------------------------
     *   否         | 任意     | 任意             | 不动（**不出现在返回值里**）
     *   是         | 是       | 否               | 翻任务行 ＋ 追加一条外部上传记录
     *   是         | 是       | 是               | 只翻任务行（不追加）
     *   是         | 否       | 否               | 只追加记录（这键是补传失败记录那条痕迹）
     *   是         | 否       | 是               | 不动（失败页那条已被同单号判重隐藏，没有待办）
     *
     * 三处刻意之处：
     *   - **判据按 (company, djbh) 不按裸 djbh**：`$success` 用企业维度取键（生产库里裸单号
     *     并不唯一，乙店的成功记录会把甲店的待办判成"已追加过"而整条吞掉）
     *   - **`$uploadedBills` 只能是裸单号**：源库状态表里没有企业列（该结构限制是已知代价，
     *     见 docs/adr/0007），这一层的串号风险无法在本地消除，只能如实建模
     *   - **追加与翻任务是两条独立的判据**：已有成功记录时任务行仍要翻（那是两条痕迹，翻正
     *     任务行与"记录已存在"无关），反过来没有任务行时也仍要追加记录
     *
     * @param array<string,array<string,array{task:bool,failure:bool}>> $pending 本地待办（企业 => 单号 => 痕迹）
     * @param array<string,array<string,bool>>                         $success 本地已有成功记录（企业 => 单号 => true）
     * @param array<string,bool>                                       $uploadedBills 源库状态表里有的单号
     * @return array<string,array<string,array{turn_task:bool,append_record:bool}>> 无动作的键不出现
     */
    public static function closureActions(array $pending, array $success, array $uploadedBills): array
    {
        // 单号比对统一按大写：SQL Server 的比较不区分大小写，源库回传的是**表里**的写法，
        // 与本地清单里的写法未必逐字相同——两侧不拉平会"查到了却没翻"（单号是 ASCII，strtoupper 够用）
        $uploaded = [];
        foreach ($uploadedBills as $djbh => $_) {
            $uploaded[strtoupper((string)$djbh)] = true;
        }

        $actions = [];
        foreach ($pending as $company => $byDjbh) {
            foreach ($byDjbh as $djbh => $traces) {
                // 源库没标已上传 → 痕迹一个字段不动。"没查到"只说明**没有证据**，不代表没上传；
                // 唯一一条判据就在这张表里（见类注释），表里没有就得维持现状
                if (!isset($uploaded[strtoupper($djbh)])) {
                    continue;
                }
                $turnTask = !empty($traces['task']);
                $appendRecord = !isset($success[$company][$djbh]);
                // 两条动作都不做：这键没有待翻的痕迹（只可能是"失败记录 + 已有成功记录"那一格）
                if (!$turnTask && !$appendRecord) {
                    continue;
                }
                $actions[$company][$djbh] = [
                    'turn_task' => $turnTask,
                    'append_record' => $appendRecord,
                ];
            }
        }
        return $actions;
    }

    /**
     * 跑一轮状态闭环：拿**本地待办清单**去源库状态表核对，外部系统后来传成了的痕迹翻正。
     *
     * 为什么按清单查、不按日期扫源库：本地待办是**跨日**的——昨天的单今天才被外部系统传成，
     * 按当次采集的日期窗口永远覆盖不到（那张单不在今天的采集结果里）。清单通常几十到几百条，
     * 按单号 IN 分块查即可，**不受计数门卫约束**（它不扫源库大表，见 spec §2、§3）。
     *
     * 调用点在采集**之前**（scripts/fetch_bills_retail.php）：闭环不依赖本批采到什么，采集
     * 失败或空批次都不该让它漏跑一轮；反过来，本方法追加的成功记录会让同轮采集的 `decide()`
     * 判 SKIP——两条路径对"已上传"给的是同一个结论，不会一边写记录一边又建任务。
     *
     * @param \SqlSrvHelper $source 源库连接（本方法只对它发 SELECT，全程不调平台接口）
     * @return array{pending:int,turned:int,recorded:int,error:?string}
     *         pending=待办键数, turned=被翻正的任务**行**数（同一键可能不止一行）, recorded=追加的记录数；
     *         error 非 null 时本轮什么都没动——源库查询失败时一条都不翻，"不知道"不等于"没上传"
     */
    public static function closeLoop(\SqlSrvHelper $source): array
    {
        // ── 1、2. 本地待办清单（与平台核查共用，见 pendingItems()）──
        $pending = self::pendingItems();

        $pendingCount = self::pendingCount($pending);
        if ($pendingCount === 0) {
            return ['pending' => 0, 'turned' => 0, 'recorded' => 0, 'error' => null];
        }

        // ── 3. 源库核对：按单号 IN 分块查状态表（**只读**）──
        // DISTINCT 不能省：那张表无主键无唯一约束（82 个单号多行），不然同一个单号会回传多行。
        $djbhs = self::pendingDjbhList($pending);
        $uploadedBills = [];
        foreach (self::chunkedIn($djbhs) as [$chunk, $placeholders]) {
            $ok = $source->queryEach(
                'SELECT DISTINCT bill_code FROM ' . self::TABLE . " WHERE bill_code IN ({$placeholders})",
                $chunk,
                static function (array $row) use (&$uploadedBills): void {
                    $code = trim((string)($row['bill_code'] ?? ''));
                    if ($code !== '') {
                        // 原样收下：与清单单号的大小写比对由 closureActions() 一处负责
                        // （SQL Server 的比较不区分大小写，回传的是**表里**的写法）
                        $uploadedBills[$code] = true;
                    }
                }
            );
            if ($ok === false) {
                $error = $source->getErrorMessage();
                (new LogWriter())->writeJsonlOnly([
                    'type' => 'retail_status_closure_failed',
                    'reason' => '源库状态表查询失败，本轮未翻正任何痕迹',
                    'error' => $error,
                    'pending' => $pendingCount,
                ]);
                return ['pending' => $pendingCount, 'turned' => 0, 'recorded' => 0, 'error' => $error];
            }
        }

        // ── 4、5. 判定与落库（与平台核查共用，见 successKeys() / applyActions()）──
        $success = self::successKeys($djbhs);
        $applied = self::applyActions(
            self::closureActions($pending, $success, $uploadedBills),
            $pending,
            [
                'reason' => '外部系统已上传该单据（源库状态表里有该单号）',
                'checker' => '状态闭环',
                'log_type' => 'retail_status_closure',
                'judged_by' => self::TABLE,
            ]
        );

        return ['pending' => $pendingCount, 'turned' => $applied['turned'], 'recorded' => $applied['recorded'], 'error' => null];
    }

    /**
     * 本地待办清单：本地还挂着痕迹的门店单（企业 => 单号 => 痕迹与元数据）。
     *
     * 两类痕迹（同一个 (company, djbh) 可能两类都有，形状里两个标志各自为真）：
     *   - 还在「等待上传」的门店任务行（按 `source='retail'` 筛——批发行一个字段都不动；
     *     采集来的与手动上传页手工建的门店单共用这一个 source，故两类都在清单里）
     *   - 零售企业的非成功日志记录（补传失败留下的那条痕迹）
     *
     * 第二类按**企业类型**筛（`Enterprise::isRetail`）：批发的失败记录不在清单里；`未识别` 也不是
     * 零售企业（它压根不在配置里），且补传三关会把它拒在写日志之前——它留不下失败记录。
     * 判据取"一切非成功记录"（宽于失败记录页的口径）：多取到的行随后会被 `closureActions()`
     * 判成无动作，无害；**少取才是问题**——那会让失败页上看得见的行永远翻不掉。
     *
     * 两个判据（源库状态表的闭环、平台核查）都从这份清单出发——"哪些单还算待办"只在这一处回答。
     *
     * **新鲜度门卫**（票 02）：`$freshnessMinutes` 非 null 时只取「`last_checked_at` 为空、或早于
     * 该分钟数之前」的行（条件下到 SQL 里，两张表逐字同一句）。两个调用方对它的用法**刻意相反**：
     *   - **状态闭环不传**（`null` = 不过滤）：它每轮都要看全量清单——抓的是"外部系统**后来**才传成"
     *     的跨日翻转，被门卫挡住就漏了（票 03 的铁律：闭环不受门卫约束）
     *   - **平台核查传 30**（与批发两个检查脚本同值）：它挂 cron 逐条调平台，门卫就是为它存在的
     *
     * 过滤是**逐行**的，不是逐键的：同一个 (company, djbh) 在两张表里各有若干行时，只要有一行
     * 过期就仍会进清单。"多查一次"优于"漏查一次"；反向由 `touchChecked()` 按同一键刷**全部**行。
     *
     * @param int|null $freshnessMinutes 门卫窗口（分钟）；`null` = 不设门卫（状态闭环走这条）
     * @return array<string,array<string,array{task:bool,failure:bool,rq:string,trace_codes:string,credential:?string}>>
     */
    public static function pendingItems(?int $freshnessMinutes = null): array
    {
        $db = Database::getInstance();
        $pending = [];

        // 门卫条件两处共用一份：两张表的这两列同名同义，各写一遍就会有一边悄悄不设防
        $gate = $freshnessMinutes === null ? '' : ' AND (last_checked_at IS NULL OR last_checked_at <= ?)';
        $gateParams = $freshnessMinutes === null
            ? []
            : [date('Y-m-d H:i:s', time() - $freshnessMinutes * 60)];

        foreach ($db->query(
            "SELECT company, djbh, rq, trace_codes, credential FROM upload_tasks
              WHERE source = 'retail' AND task_status = '等待上传'{$gate}",
            $gateParams
        ) as $row) {
            $company = (string)($row['company'] ?? '');
            $djbh = (string)($row['djbh'] ?? '');
            if ($djbh === '') {
                continue;
            }
            $pending[$company][$djbh] ??= self::pendingItem($row);
            $pending[$company][$djbh]['task'] = true;
        }

        foreach ($db->query(
            "SELECT company, djbh, rq, trace_codes, credential FROM upload_logs
              WHERE (request_status = '请求失败' OR response_status IS NULL
                 OR response_status NOT IN ('上传成功', '单据重复')){$gate}",
            $gateParams
        ) as $row) {
            $company = (string)($row['company'] ?? '');
            $djbh = (string)($row['djbh'] ?? '');
            if ($djbh === '' || !Enterprise::isRetail($company)) {
                continue;
            }
            $pending[$company][$djbh] ??= self::pendingItem($row);
            $pending[$company][$djbh]['failure'] = true;
        }

        return $pending;
    }

    /**
     * 待办清单里有多少**条**待办（企业下每个单号算一条，与清单的键数同义）。
     *
     * 三处要报这个数：`closeLoop()` 的"核对 N 条待办"、平台核查脚本的"本地待办 N 条 / 其中 N 条
     * 被门卫挡下"。各写一遍 foreach 时改了一处另一处不会报错——只会有一边的数悄悄变味。
     *
     * @param array<string,array<string,mixed>> $pending 待办清单
     */
    public static function pendingCount(array $pending): int
    {
        $n = 0;
        foreach ($pending as $byDjbh) {
            $n += count($byDjbh);
        }
        return $n;
    }

    /**
     * 批量刷新 `last_checked_at`：本轮**平台给了答复**的那些键（`RetailPlatformCheck::run()` 的
     * `checked_by_company`），两张表一起刷——下一轮 cron 的新鲜度门卫据此跳过它们。
     *
     * 为什么批量而不是逐条 UPDATE：本机 SQLite 单条 UPDATE = 一次 fsync（21–28 ms，见
     * `Database::transaction()`），一趟 1,400 条逐条写光等磁盘就要 30 秒往上；这里按企业分块 `IN`、
     * 整批包进**一次事务**。批发那两个检查脚本是逐条写的——它们一趟只有几十条，别照抄。
     *
     * 键必须带企业维度（`company = ? AND djbh IN (…)`）：裸单号跨门店会串——同一个单号在甲店查过、
     * 乙店压根没查，却把乙店那行也刷成"刚查过"，那张单会被门卫白白挡掉一整轮。
     *
     * 写入范围**比"查过的那些待办键"略宽**：按 (company, djbh) 刷两张表的全部行，不筛
     * `task_status`/`source`。门卫看的正是这两张表的 `last_checked_at`，把该键在两张表上的行一并
     * 对齐，下一轮问"这个键查过没有"才只有一个答案；翻正过的任务行（已处理）顺带被刷也无害——
     * 它本来就已离开待办清单。
     *
     * **整轮一个事务**（`Database::transaction()` 的 docblock 说"批次别开太大"）：那条告诫针对的是
     * 零售快照那种"几万条 INSERT 攒一个大事务"——那时事务期间长期持有写锁，cron 那边的采集会卡满
     * `busyTimeout(30s)` 后失败。这里语句数是**企业数 × 分块数 × 2**（一趟 5 家门店、十几到几十条
     * 语句），执行时间是亚秒级，与那个量级不是一回事。
     *
     * @param array<string,array<string,bool>> $checkedByCompany 企业 => 单号 => true
     * @param string|null $checkedAt 刷成什么时间（默认现在）——**参数化是为了让语句形状能被逐字断言**
     * @return int 被刷新的**行**数（两张表合计）；键不存在于表里时是 0，不算错误
     */
    public static function touchChecked(array $checkedByCompany, ?string $checkedAt = null): int
    {
        $statements = self::touchStatements($checkedByCompany, $checkedAt ?? date('Y-m-d H:i:s'));
        if ($statements === []) {
            // 没有要刷的键就别开事务（被门卫挡下整轮时走这条——空事务白拿一次写锁）
            return 0;
        }

        $db = Database::getInstance();
        $touched = 0;
        $db->transaction(function () use ($db, $statements, &$touched): void {
            foreach ($statements as [$sql, $params]) {
                $touched += $db->execute($sql, $params);
            }
        });

        return $touched;
    }

    /**
     * 批量 touch 的**语句形状**（纯函数：不连库、不执行）：`[[sql, params], …]`。
     *
     * 单独抽出来是为了让"分块与归属"能被**离线断言**（见
     * tests/retail_external_uploads_test.php 用例 10）——`touchChecked()` 本身要真库才跑得动，
     * 而它最容易错的两处都是纯的：
     *   - **每条语句都带 `company = ?`**：裸单号跨门店会串（甲店查过的单号把乙店没查过的行也刷成
     *     "刚查过"，那张单会被门卫白挡一整轮）
     *   - **每条语句最多 `IN_CHUNK_SIZE` 个单号** → 参数 2 + 500 = **502 个**，离本机 SQLite 3.7.17
     *     的 **999 参数上限**还差一半。当初若按 `(company, djbh)` 两两成对写 `OR` 条件，500 块正好
     *     1000 个参数，会**直接报错**——分块大小与键的形状是绑在一起的，改一处得重算另一处
     *
     * @param array<string,array<string,bool>> $checkedByCompany 企业 => 单号 => true
     * @return array<int,array{0:string,1:array<int,string>}> 两张表各一条，按企业分块
     */
    public static function touchStatements(array $checkedByCompany, string $checkedAt): array
    {
        $statements = [];
        foreach ($checkedByCompany as $company => $byDjbh) {
            foreach (self::chunkedIn(array_keys($byDjbh)) as [$chunk, $placeholders]) {
                foreach (['upload_tasks', 'upload_logs'] as $table) {
                    $statements[] = [
                        "UPDATE {$table} SET last_checked_at = ? WHERE company = ? AND djbh IN ({$placeholders})",
                        array_merge([$checkedAt, (string)$company], $chunk),
                    ];
                }
            }
        }
        return $statements;
    }

    /**
     * 待办清单里的全部单号（扁平、去重）——两处 `IN` 查询的输入形状。
     *
     * 状态闭环与平台核查都要它（各自写一遍 3 行 foreach 时，改了一处另一处不会报错，
     * 只会有一边悄悄少查几个单号）。
     *
     * @param array<string,array<string,array>> $pending 待办清单（企业 => 单号 => 痕迹）
     * @return array<int,string>
     */
    public static function pendingDjbhList(array $pending): array
    {
        $djbhs = [];
        foreach ($pending as $byDjbh) {
            foreach ($byDjbh as $djbh => $_) {
                $djbhs[$djbh] = true;
            }
        }
        return array_keys($djbhs);
    }

    /**
     * 本地已有的成功记录（企业 => 单号 => true），只查给定单号里的那些。
     *
     * 判据是 `(company, djbh)` 两维：生产库里裸单号并不唯一，乙店的成功记录会把甲店的待办
     * 判成"已追加过"而整条吞掉。
     *
     * @param array<int,string> $djbhs 待查单号（通常来自待办清单）
     * @return array<string,array<string,bool>>
     */
    public static function successKeys(array $djbhs): array
    {
        $db = Database::getInstance();
        $success = [];
        foreach (self::chunkedIn($djbhs) as [$chunk, $placeholders]) {
            foreach ($db->query(
                "SELECT DISTINCT company, djbh FROM upload_logs
                  WHERE djbh IN ({$placeholders}) AND response_status IN ('上传成功', '单据重复')",
                $chunk
            ) as $row) {
                $success[(string)$row['company']][(string)$row['djbh']] = true;
            }
        }
        return $success;
    }

    /**
     * 落库一组闭环动作：翻任务行 / 追加「外部上传」记录 / 每次翻转记一条 JSONL。
     *
     * **动作怎么落库只在这一处**——两个判据（源库状态表的闭环、平台核查）的差异全在
     * `$provenance` 里："凭什么判它已上传"与"谁翻的"都要如实写进痕迹，否则详情弹窗里那条
     * 记录看起来就像本项目自己传的。
     *
     * 两处刻意的写法（与票 03 定下时一致）：
     *   - 翻任务行时 `request_status` **不动**：本项目从没为这张单发起过上传请求，写「请求成功」
     *     是失真——与 `buildRecord()` 那句 `request_status=null` 同理
     *   - 追加记录而**不改写**那条补传失败记录：失败页靠既有的同单号判重自动隐藏它，
     *     改它的 `response_status` 会造出"来源失真"的历史
     *
     * @param array<string,array<string,array{turn_task:bool,append_record:bool}>> $actions
     *        `closureActions()` 的返回值
     * @param array<string,array<string,array{task:bool,failure:bool,rq:string,trace_codes:string,credential:?string}>> $pending
     *        同一轮的待办清单（取 `rq`/`trace_codes`/`credential` 用）
     * @param array{reason:string,checker:string,log_type:string,judged_by:string} $provenance
     *        `reason`=判据出处（如「源库状态表里有该单号」）；`checker`=翻正者（如「状态闭环」）；
     *        `log_type`=每次翻转记的那条 JSONL 的 type；`judged_by`=出处结构的 `judged_by` 字段
     * @return array{turned:int,recorded:int} `turned` 是任务**行**数（同一键可能不止一行）
     */
    public static function applyActions(array $actions, array $pending, array $provenance): array
    {
        $db = Database::getInstance();
        $logWriter = new LogWriter();
        $now = date('Y-m-d H:i:s');
        $turned = 0;
        $recorded = 0;

        // 四个键**直接取**（不给静默默认）：docblock 说必传就必传——缺一个键会拼出
        // "本行任务由翻正"这样的残句，或让 judged_by 指错出处；缺键时在这里报 warning 更显眼
        $reason = (string)$provenance['reason'];
        $checker = (string)$provenance['checker'];
        $logType = (string)$provenance['log_type'];
        $judgedBy = (string)$provenance['judged_by'];

        foreach ($actions as $company => $byDjbh) {
            foreach ($byDjbh as $djbh => $action) {
                $item = $pending[$company][$djbh] ?? self::pendingItem([]);
                $traces = [];
                if (!empty($item['task'])) {
                    $traces[] = 'task';
                }
                if (!empty($item['failure'])) {
                    $traces[] = 'failure';
                }
                $turnedRows = 0;

                if (!empty($action['turn_task'])) {
                    // 翻正任务行：**保留痕迹、不删行**（与"失败也算已处理"同一口径）
                    $turnedRows = $db->execute(
                        "UPDATE upload_tasks
                            SET task_status = '已处理', response_status = ?, resp = ?, updated_at = ?
                          WHERE company = ? AND djbh = ? AND source = 'retail' AND task_status = '等待上传'",
                        [
                            self::RESPONSE_STATUS,
                            self::provenanceJson("{$reason}，本行任务由{$checker}翻正；本项目未发起任何平台请求", $judgedBy),
                            $now,
                            $company,
                            $djbh,
                        ]
                    );
                    $turned += $turnedRows;
                }

                if (!empty($action['append_record'])) {
                    $logWriter->write(self::buildRecord([
                        'djbh' => $djbh,
                        'rq' => $item['rq'],
                        'trace_codes' => $item['trace_codes'],
                        'company' => $company,
                        'credential' => $item['credential'],
                    ], "{$reason}，本项目未发起任何平台请求", $judgedBy));
                    $recorded++;
                }

                // 每次翻转记一条 JSONL（企业 / 单号 / 原痕迹类型）——**不进 upload_logs**：
                // 上面追加的那条就是"上传结果"日志，再写一条说明会在已上传页冒出重复行
                $logWriter->writeJsonlOnly([
                    'type' => $logType,
                    'company' => $company,
                    'djbh' => $djbh,
                    'traces' => $traces,
                    'turned_rows' => $turnedRows,
                    'recorded' => !empty($action['append_record']),
                ]);
            }
        }

        return ['turned' => $turned, 'recorded' => $recorded];
    }

    /**
     * 把待查的单号切成 `IN (?, ?, …)` 能吃的块（每块附一份占位符串）。
     *
     * 类里两处 IN 查询（源库核对、本地成功记录）共用这一处：分块与占位符构造各写一遍时，
     * 改了一处另一处不会报错——只会有一边的 `IN` 悄悄少几个参数，而 SQL 照样能跑。
     *
     * @param array<int,string> $djbhs
     * @return \Generator<int,array{0:array<int,string>,1:string}>
     */
    private static function chunkedIn(array $djbhs): \Generator
    {
        foreach (array_chunk($djbhs, self::IN_CHUNK_SIZE) as $chunk) {
            yield [$chunk, implode(',', array_fill(0, count($chunk), '?'))];
        }
    }

    /**
     * 说明出处的 JSON：任务行的 `resp` 与记录的 `response` 共用同一段结构。
     *
     * 两处都写它，是为了让「API 返回详情」弹窗里看得见这条痕迹**为什么**是这个状态——
     * 不至于让人以为本项目真调过一次平台。
     *
     * `judged_by` 是**判据的出处**：默认源库状态表（采集与状态闭环），平台核查传平台接口名
     * （`lsyd.query.upbilldetail`）——两条判据翻出来的痕迹在详情弹窗里因此分得清。
     */
    private static function provenanceJson(string $reason, string $judgedBy = self::TABLE): string
    {
        return json_encode([
            'external_upload' => true,
            'judged_by' => $judgedBy,
            'reason' => $reason,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 待办清单里一键的初始形状（元数据取自任务行或失败记录那一行，供追加记录用）。
     *
     * 同一个 (company, djbh) 的两类痕迹**谁先遇到用谁的元数据**（`??=` 不覆盖）：任务行优先，
     * 它的 `trace_codes` 是采集/手工建单当场落下的那份。
     */
    private static function pendingItem(array $row): array
    {
        return [
            'rq' => (string)($row['rq'] ?? ''),
            'trace_codes' => (string)($row['trace_codes'] ?? ''),
            'credential' => $row['credential'] ?? null,
            'task' => false,
            'failure' => false,
        ];
    }
}
