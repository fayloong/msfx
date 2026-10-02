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

    /** 单号 IN 列表分块大小（规避超长 SQL 与参数上限；与采集脚本同值） */
    private const IN_CHUNK_SIZE = 500;

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
            'response'        => self::explain('外部系统已上传该单据（源库状态表里有该单号），本项目未发起任何平台请求'),
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
     *   - **`$uploadedCodes` 只能是裸单号**：源库状态表里没有企业列（该结构限制是已知代价，
     *     见 docs/adr/0007），这一层的串号风险无法在本地消除，只能如实建模
     *   - **追加与翻任务是两条独立的判据**：已有成功记录时任务行仍要翻（那是两条痕迹，翻正
     *     任务行与"记录已存在"无关），反过来没有任务行时也仍要追加记录
     *
     * @param array<string,array<string,array{task:bool,failure:bool}>> $pending 本地待办（企业 => 单号 => 痕迹）
     * @param array<string,array<string,bool>>                         $success 本地已有成功记录（企业 => 单号 => true）
     * @param array<string,bool>                                       $uploadedCodes 源库状态表里有的单号
     * @return array<string,array<string,array{turn_task:bool,append_record:bool}>> 无动作的键不出现
     */
    public static function closureActions(array $pending, array $success, array $uploadedCodes): array
    {
        // 单号比对统一按大写：SQL Server 的比较不区分大小写，源库回传的是**表里**的写法，
        // 与本地清单里的写法未必逐字相同——两侧不拉平会"查到了却没翻"（单号是 ASCII，strtoupper 够用）
        $uploaded = [];
        foreach ($uploadedCodes as $code => $_) {
            $uploaded[strtoupper((string)$code)] = true;
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
        $db = Database::getInstance();

        // ── 1. 本地待办：还在「等待上传」的门店任务 ──
        // 按 source='retail' 筛——批发行一个字段都不动。采集来的与手动上传页手工建的门店单
        // 共用这一个 source，故两类都在清单里：它们都是"本地挂着待办痕迹的门店单"。
        $pending = [];
        foreach ($db->query(
            "SELECT company, djbh, rq, trace_codes, credential FROM upload_tasks
              WHERE source = 'retail' AND task_status = '等待上传'"
        ) as $row) {
            $company = (string)($row['company'] ?? '');
            $djbh = (string)($row['djbh'] ?? '');
            if ($djbh === '') {
                continue;
            }
            $pending[$company][$djbh] ??= self::pendingItem($row);
            $pending[$company][$djbh]['task'] = true;
        }

        // ── 2. 本地待办：零售企业的补传失败记录 ──
        // 按**企业类型**筛（`Enterprise::isRetail`）：批发的失败记录不在清单里；`未识别` 也不是
        // 零售企业（它压根不在配置里），且补传三关会把它拒在写日志之前——它留不下失败记录。
        //
        // 判据取"一切非成功记录"（宽于失败记录页的口径）：多取到的行随后会被 `closureActions()`
        // 判成无动作，无害；**少取才是问题**——那会让失败页上看得见的行永远翻不掉。
        foreach ($db->query(
            "SELECT company, djbh, rq, trace_codes, credential FROM upload_logs
              WHERE request_status = '请求失败' OR response_status IS NULL
                 OR response_status NOT IN ('上传成功', '单据重复')"
        ) as $row) {
            $company = (string)($row['company'] ?? '');
            $djbh = (string)($row['djbh'] ?? '');
            if ($djbh === '' || !Enterprise::isRetail($company)) {
                continue;
            }
            $pending[$company][$djbh] ??= self::pendingItem($row);
            $pending[$company][$djbh]['failure'] = true;
        }

        $pendingCount = 0;
        foreach ($pending as $byDjbh) {
            $pendingCount += count($byDjbh);
        }
        if ($pendingCount === 0) {
            return ['pending' => 0, 'turned' => 0, 'recorded' => 0, 'error' => null];
        }

        // ── 3. 源库核对：按单号 IN 分块查状态表（**只读**）──
        // DISTINCT 不能省：那张表无主键无唯一约束（82 个单号多行），不然同一个单号会回传多行。
        $codes = [];
        foreach ($pending as $byDjbh) {
            foreach ($byDjbh as $djbh => $_) {
                $codes[$djbh] = true;
            }
        }
        $uploadedCodes = [];
        foreach (array_chunk(array_keys($codes), self::IN_CHUNK_SIZE) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $ok = $source->queryEach(
                'SELECT DISTINCT bill_code FROM ' . self::TABLE . " WHERE bill_code IN ({$placeholders})",
                $chunk,
                static function (array $row) use (&$uploadedCodes): void {
                    $code = trim((string)($row['bill_code'] ?? ''));
                    if ($code !== '') {
                        // 原样收下：与清单单号的大小写比对由 closureActions() 一处负责
                        // （SQL Server 的比较不区分大小写，回传的是**表里**的写法）
                        $uploadedCodes[$code] = true;
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

        // ── 4. 本地已有成功记录（只查清单里出现过的单号，按 (company, djbh) 取键）──
        $success = [];
        foreach (array_chunk(array_keys($codes), self::IN_CHUNK_SIZE) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            foreach ($db->query(
                "SELECT DISTINCT company, djbh FROM upload_logs
                  WHERE djbh IN ({$placeholders}) AND response_status IN ('上传成功', '单据重复')",
                $chunk
            ) as $row) {
                $success[(string)$row['company']][(string)$row['djbh']] = true;
            }
        }

        // ── 5. 判定与落库 ──
        $logWriter = new LogWriter();
        $now = date('Y-m-d H:i:s');
        $turned = 0;
        $recorded = 0;

        foreach (self::closureActions($pending, $success, $uploadedCodes) as $company => $byDjbh) {
            foreach ($byDjbh as $djbh => $action) {
                $item = $pending[$company][$djbh];
                $traces = [];
                if (!empty($item['task'])) {
                    $traces[] = 'task';
                }
                if (!empty($item['failure'])) {
                    $traces[] = 'failure';
                }
                $turnedRows = 0;

                if ($action['turn_task']) {
                    // 翻正任务行：**保留痕迹、不删行**（与"失败也算已处理"同一口径）。
                    // request_status 刻意不动：本项目从没为这张单发起过上传请求，写「请求成功」是失真
                    // ——与 buildRecord() 那句 request_status=null 同理。
                    $turnedRows = $db->execute(
                        "UPDATE upload_tasks
                            SET task_status = '已处理', response_status = ?, resp = ?, updated_at = ?
                          WHERE company = ? AND djbh = ? AND source = 'retail' AND task_status = '等待上传'",
                        [
                            self::RESPONSE_STATUS,
                            self::explain('外部系统已上传该单据（源库状态表里有该单号），本行任务由状态闭环翻正；本项目未发起任何平台请求'),
                            $now,
                            $company,
                            $djbh,
                        ]
                    );
                    $turned += $turnedRows;
                }

                if ($action['append_record']) {
                    // **不改写历史**：那条补传失败记录原样留着，失败页靠既有的同单号判重自动隐藏它
                    // （自己改它的 response_status 会造出"来源失真"的历史）
                    self::record([
                        'djbh' => $djbh,
                        'rq' => $item['rq'],
                        'trace_codes' => $item['trace_codes'],
                        'company' => $company,
                        'credential' => $item['credential'],
                    ]);
                    $recorded++;
                }

                // 每次翻转记一条 JSONL（企业 / 单号 / 原痕迹类型）——**不进 upload_logs**：
                // 上面追加的那条就是"上传结果"日志，再写一条说明会在已上传页冒出重复行
                $logWriter->writeJsonlOnly([
                    'type' => 'retail_status_closure',
                    'company' => $company,
                    'djbh' => $djbh,
                    'traces' => $traces,
                    'turned_rows' => $turnedRows,
                    'recorded' => $action['append_record'],
                ]);
            }
        }

        return ['pending' => $pendingCount, 'turned' => $turned, 'recorded' => $recorded, 'error' => null];
    }

    /**
     * 说明出处的 JSON：任务行的 `resp` 与记录的 `response` 共用同一段结构。
     *
     * 两处都写它，是为了让「API 返回详情」弹窗里看得见这条痕迹**为什么**是这个状态——
     * 不至于让人以为本项目真调过一次平台。
     */
    private static function explain(string $reason): string
    {
        return json_encode([
            'external_upload' => true,
            'judged_by' => self::TABLE,
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
