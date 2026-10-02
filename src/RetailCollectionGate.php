<?php
/**
 * 零售采集的计数门卫（票 04，见 .scratch/retail-collection-split/spec.md §2）
 *
 * 采集开跑前先数三个数——**当日单据总数 / 已上传 / 未上传**（范围与 cron 采集逐字一致：
 * 当日 + 四种门店单据类型）——与上一次的基线比对：三个数都没变就跳过整轮，并在日志里写明
 * 原因。交付给运维的是**日志从此能区分"今天真没新单"与"脚本压根没跑"**；顺带省掉源库没事时
 * 本地那一段空转（去重、认领、写库）。
 *
 * 三条容易做漏的硬要求（spec §2 实测踩过）：
 *
 *   ① **计数 SQL 的形状**：SQL Server **不允许在聚合里套子查询**
 *      （`sum(case when exists(...) then 1 else 0 end)` 直接报"不能对包含聚合或子查询的表达式
 *      执行聚合函数"），可用形状是把状态表**去重成派生表**再 LEFT JOIN 后 `count()`。
 *      去重派生表不能省——状态表无主键无唯一约束（实测 82 个单号多行），直接 JOIN 会把 count
 *      放大。**非聚合**的 `case when exists(...)` 才允许，采集 SQL 因此不受这条限制（见
 *      scripts/fetch_bills_retail.php 里那段注释）。
 *   ② **基线文件独立**（`data/fetch_bill_counter_retail.json`）：批发那边是
 *      `data/fetch_bill_counter.json`，两个脚本共写一个文件会互相踩——一边写 `{date,count}`、
 *      另一边写 `{date,total,uploaded,unuploaded}`，彼此的判定都会被对方的写入喂成"格式非法
 *      →视为无基线"，表现是门卫时灵时不灵。
 *   ③ **计数查询失败/超时 = 无基线、照常采集**，不是"跳过"。门卫跳过的是一整轮采集，
 *      方向必须是"宁可多跑一轮，绝不让采集停摆"——这是与批发那边（计数失败即跳过本次）
 *      刻意相反的一处：零售没有第二道兜底，漏采就是整天单据在页面上不存在。
 *
 * 基线**只在整轮采集成功之后**写（`guard()` 里 `collect()` 返回之后那一句）：落库跑到一半
 * 失败却把计数记成"见过"，下一轮就判"无变化"永久跳过——那天剩下的单据再也采不进来，且日志上
 * 完全看不出异常。任何异常路径（源库读取失败、落库中途失败）都不更新基线。这条与批发采集一致。
 *
 * **顺序红线**：调用点在 `scripts/fetch_bills_retail.php` 里必须排在**状态闭环之后**——
 * 门卫的"三个数没变就跳过"跳的是整轮采集，而闭环不在它的覆盖范围里（票 03 验收项：闭环每轮
 * 都跑，不受门卫约束）。插到闭环前面，外部系统后来传成的单就不会被翻正了。
 *
 * 本票全程不调码上放心：门卫只发**一条源库只读计数 SQL**（见 `counts()`）。
 */
namespace App;

final class RetailCollectionGate
{
    /**
     * 基线文件名（`data/` 下）。
     *
     * 名字带 `retail` 是给人工看的：`ls data/` 时要一眼分得清哪份是零售的、哪份是批发的
     * ——两个文件的内容形状不同，混用即门卫失灵。
     */
    public const STATE_FILE_NAME = 'fetch_bill_counter_retail.json';

    /**
     * 基线文件的绝对路径。
     *
     * 由类自己算（`dirname(__DIR__)` 而不是相对路径）：相对路径随 cwd 变，而 cron 与手工跑的
     * cwd 未必相同——那会让门卫"时灵时不灵"。调用方（脚本）与测试都该从这里取，别各写各的。
     */
    public static function stateFile(): string
    {
        return dirname(__DIR__) . '/data/' . self::STATE_FILE_NAME;
    }

    /**
     * 跑一轮门卫 + 采集（门卫的全部策略都在这一个方法里）。
     *
     * 两个副作用都由调用方注入，好让"失败不写基线"这条顺序铁律可被断言
     * （tests/retail_collection_gate_test.php）：`$count` 去数三个数（生产上就是 `counts()`），
     * `$collect` 跑一整轮采集（源库读取 + 认领 + 分流落库）。
     *
     * @param string        $stateFile 基线文件路径（生产用 `stateFile()`；测试用临时文件）
     * @param string|null   $date      采集日期；**null = 不适用**（`--all` 全量快照——它的计数口径
     *                                 是"整个两年窗口"，与门卫的"当日"不是一回事：既不比对也不写
     *                                 基线，写了次日 cron 会拿当日的数去比一个快照的数，永远不等）
     * @param callable      $count     `fn(string $date): array{total:int,uploaded:int,unuploaded:int}`
     *                                 ；失败请抛异常（本方法捕住后按"无基线"处理）
     * @param callable      $collect   `fn(): void`；抛出的任何异常**原样上抛**，基线不写
     * @return array{skipped:bool,counts:?array,reason:string,warning:?string}
     *         `reason` 是给日志的一行话（调用方原样打印）；`warning` 非 null 时另起一行打
     * @throws \Throwable `$collect` 抛出的异常原样穿过本方法
     */
    public static function guard(string $stateFile, ?string $date, callable $count, callable $collect): array
    {
        // ── --all 快照：绕开门卫 ──
        if ($date === null) {
            $collect();
            return [
                'skipped' => false,
                'counts' => null,
                'reason' => '全量快照（--all）：门卫不适用，不计数、不写基线',
                'warning' => null,
            ];
        }

        $read = self::read($stateFile);
        $baseline = $read['baseline'];

        // ── 数三个数：失败 = 无基线，照常采集（见类注释 ③）──
        $counts = null;
        $countError = null;
        try {
            $counts = $count($date);
        } catch (\Throwable $e) {
            $countError = $e->getMessage();
        }
        if ($counts === null) {
            $collect();
            return [
                'skipped' => false,
                'counts' => null,
                // 手里没有三个数，本轮也就没有资格写基线：把旧数写回去会让"计数一直失败"的这几天
                // 看起来像"一直没变化"——那正是永久跳过的那条路
                'reason' => "计数查询失败（{$countError}），视为无基线，照常采集；本轮不写基线",
                'warning' => null,
            ];
        }

        // ── 三个数都没变：跳过整轮（基线一字不动）──
        if (self::unchanged($baseline, $date, $counts)) {
            return [
                'skipped' => true,
                'counts' => $counts,
                'reason' => self::describe($counts) . '，与基线一致，跳过本轮采集',
                'warning' => null,
            ];
        }

        // ── 采集 ──
        // 抛异常就直接穿出去（调用方非零退出），**基线不写**：失败要留下一份能与下一轮比出
        // "有变化"的旧数，才能自动重采。这条是门卫的命门，别把 write() 挪到这一行之前。
        $collect();

        $saved = self::write($stateFile, $date, $counts);
        $reason = $baseline === null
            ? "无基线（{$read['note']}），执行采集"
            : '计数有变化（' . self::describe($counts) . '；基线 ' . self::describe($baseline) . '），执行采集';

        return [
            'skipped' => false,
            'counts' => $counts,
            'reason' => $reason . '，采集成功',
            // 写失败只影响门卫自己（下轮仍会照常采集），不是采集失败
            'warning' => $saved ? null : "基线写入失败 {$stateFile}（下轮仍会照常采集）",
        ];
    }

    /**
     * 三个数都与基线一致？（纯函数，日期也要对上）
     *
     * 月份日期不符视同"有变化"：换了采集日期就得重采——基线的语义是"某一天的计数"，
     * 拿昨天的数去判今天，等于把一整天的新单吞掉。
     *
     * 数值一律 `(int)` 后再比：基线文件是给人看也给人改的，手工写成 `"45"` 不该让门卫
     * 判成"有变化"而白跑一轮（比的是数，不是类型）。
     */
    public static function unchanged(?array $baseline, string $date, array $counts): bool
    {
        if ($baseline === null) {
            return false;
        }
        foreach (['total', 'uploaded', 'unuploaded'] as $key) {
            if (!isset($baseline[$key], $counts[$key]) || (int)$baseline[$key] !== (int)$counts[$key]) {
                return false;
            }
        }
        return (string)($baseline['date'] ?? '') === $date;
    }

    /**
     * 读基线。文件缺失 / 损坏 / 形状不全 → **一律视为"无基线"**（照常采集），用 `note` 说明原因。
     *
     * 不抛异常：门卫是"省一轮空转"的优化，任何时候都不该成为采集停摆的理由——读不了就当没有。
     *
     * @return array{baseline:?array{date:string,total:int,uploaded:int,unuploaded:int}, note:string}
     *         `note` 为空串表示读到了一个能用的基线
     */
    public static function read(string $file): array
    {
        if (!is_file($file)) {
            return ['baseline' => null, 'note' => '基线文件不存在'];
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return ['baseline' => null, 'note' => '基线文件读不出来'];
        }
        $decoded = json_decode($raw, true);
        // 形状校验：日期 + 三个数一个都不能少。半份基线拿去比对，比出来的"没变化"是不可信的
        // ——那正是"采集停摆"的来源，故宁可整份作废
        if (!is_array($decoded)
            || !isset($decoded['date'], $decoded['total'], $decoded['uploaded'], $decoded['unuploaded'])
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$decoded['date'])) {
            return ['baseline' => null, 'note' => '基线文件损坏或格式非法'];
        }

        return [
            'baseline' => [
                'date' => (string)$decoded['date'],
                'total' => (int)$decoded['total'],
                'uploaded' => (int)$decoded['uploaded'],
                'unuploaded' => (int)$decoded['unuploaded'],
            ],
            'note' => '',
        ];
    }

    /**
     * 写基线（形状见 spec §2：日期 + 三个数，一个键都不多）。
     *
     * **只该在整轮采集成功之后调**——生产调用点在 `guard()` 里，别在别处调它。
     * `LOCK_EX` 防两个进程同时写（cron 与手工跑撞上时至少不会写出一份半截 JSON）。
     *
     * @return bool 是否写成功；失败由调用方记警告——门卫的写入失败不影响采集结果
     */
    public static function write(string $file, string $date, array $counts): bool
    {
        $payload = json_encode([
            'date' => $date,
            'total' => (int)($counts['total'] ?? 0),
            'uploaded' => (int)($counts['uploaded'] ?? 0),
            'unuploaded' => (int)($counts['unuploaded'] ?? 0),
        ], JSON_UNESCAPED_UNICODE);

        return @file_put_contents($file, $payload, LOCK_EX) !== false;
    }

    /**
     * 数三个数：**一条只读 SQL**，源库上就这一次往返（实测 47–59ms，冷启动 761ms）。
     *
     * SQL 形状见类注释 ①：两张表各自**去重**成派生表 + LEFT JOIN + `count()`。四处要点：
     *   - `count(a.bill_code)` 数的是"状态表里有该单号"的**单据数** = 已上传；`count(*)` 是当日单据
     *     总数；两者相减即未上传。第三个是派生值，一并存下来只为人工对账时看得见
     *   - **左表也必须去重**（`select distinct bill_code from zsm_ls`），这是票面那段示例 SQL 漏掉的
     *     一处：`zsm_ls` **自己**就有完全重复行（321 平均 **2.08 行/单**、最多 120 行，14 列值全同，
     *     见 .scratch/retail-chain/probe-findings-2026-09-29.md 第 3 条）。不去重数出来的是**行数**，
     *     不是单据数——首跑实测 111 行 vs 采集的 55 张单（≈2.02×），而日志上那句"当日总数"
     *     是要给运维看的，翻倍的数字只会让人以为门卫坏了。去重后与采集脚本的"拉取到 N 张单据"
     *     逐字相等（采集侧的 PHP 去重干的就是这件事）
     *   - 数的是 `zsm_ls` 的单据，故不会像采集 SQL 那样被 `zsm_ls_code` 的一码一行放大（那张表不在本查询里）
     *   - 单据类型清单与 2 年下限都由调用方给/取自 `RetailRetention`，与采集 SQL 同一批口径；
     *     门卫的判定只有在"数的范围和采集的范围逐字一致"时才成立（数窄了会跳过有单要采的那一轮）
     *
     * @param \SqlSrvHelper $source    源库连接（只发 SELECT，不写源库、不调平台）
     * @param string        $date      采集日期（YYYY-MM-DD）
     * @param array<int,int|string> $billTypes 采集的四种单据类型（由调用方传采集脚本用的那份清单）
     * @return array{total:int,uploaded:int,unuploaded:int}
     * @throws \RuntimeException 查询失败——调用方（`guard()`）把它当"无基线"处理，照常采集
     */
    public static function counts(\SqlSrvHelper $source, string $date, array $billTypes): array
    {
        $sql = "select count(*) as total, count(a.bill_code) as uploaded,
                       count(*) - count(a.bill_code) as unuploaded
                  from (select distinct bill_code from dyt.msfx.dbo.zsm_ls
                         where bill_type in (" . implode(', ', array_map('intval', $billTypes)) . ")
                           and bill_time >= ? and bill_time = ?) ls
                  left join (select distinct bill_code from " . RetailExternalUploads::TABLE . ") a
                         on a.bill_code = ls.bill_code";

        // 2 年下限与采集 SQL 一样"始终在"（纵深：超期日期在脚本入口已被拒），等值条件是当日口径
        $row = $source->queryOne($sql, [RetailRetention::cutoffDate(), $date]);
        if (!is_array($row)) {
            throw new \RuntimeException('门卫计数查询失败: ' . $source->getErrorMessage());
        }

        return [
            'total' => (int)($row['total'] ?? 0),
            'uploaded' => (int)($row['uploaded'] ?? 0),
            'unuploaded' => (int)($row['unuploaded'] ?? 0),
        ];
    }

    /** 三个数的人类可读形态，供日志与基线比对说明用（`总数 45 / 已上传 4 / 未上传 41`） */
    private static function describe(array $counts): string
    {
        return '总数 ' . (int)($counts['total'] ?? 0)
            . ' / 已上传 ' . (int)($counts['uploaded'] ?? 0)
            . ' / 未上传 ' . (int)($counts['unuploaded'] ?? 0);
    }
}
