<?php
/**
 * 零售采集的计数门卫（2026-10-02 票 04 建；2026-10-05 起**只数一个数**，见 ADR 0018）
 *
 * 采集开跑前先数**当日单据总数**（范围与 cron 采集逐字一致：当日 + 四种门店单据类型 + 2 年下限）
 * ——与上一次的基线比对：没变就跳过整轮，并在日志里写明原因。交付给运维的是**日志从此能区分
 * "今天真没新单"与"脚本压根没跑"**；顺带省掉源库没事时本地那一段空转（去重、认领、写库）。
 *
 * **2026-10-05 起为什么只剩一个数**：原先还数「已上传 / 未上传」，两个数都读源库状态表（JOIN
 * 派生表）。那天起门店单的判据统一到**平台核查**（ADR 0018），采集不再读那张表——而门卫留着它
 * 还有一层害处：外部系统写状态写得乱时，那两个数一直在变，门卫会一直判"有变化"，整天一轮不省。
 * 判定「今天有没有新单」这件事，`total` 一个数就够。
 *
 * 三条容易做漏的硬要求：
 *
 *   ① **计数 SQL 的形状**：`zsm_ls` **必须先去重成派生表**再 `count()`——那张表**自己**就有完全
 *      重复行（321 平均 2.08 行/单、最多 120 行、14 列值全同，见 .scratch/retail-chain/
 *      probe-findings-2026-09-29.md 第 3 条）。不去重数出来的是**行数**：首跑实测 111 行 vs 采集的
 *      55 张单，而日志上那句"当日总数"是要给运维看的，翻倍的数字只会让人以为门卫坏了。
 *      去重后与采集脚本那句"拉取到 N 张单据"**逐字相等**（采集侧的 PHP 去重干的就是这件事）。
 *      同时数的是 `zsm_ls` 的单据，故不会像采集 SQL 那样被 `zsm_ls_code` 的一码一行放大。
 *      （历史注：那张表参与 JOIN 的年代，SQL Server 不允许在聚合里套子查询，形状只能是"两个派生表
 *      LEFT JOIN + count"；现在不 JOIN 任何表，那条限制随之消失——但**去重派生表不能省**。）
 *   ② **基线文件独立**（`data/fetch_bill_counter_retail.json`）：批发那边是
 *      `data/fetch_bill_counter.json`，两个脚本共写一个文件会互相踩——一边写 `{date,count}`、
 *      另一边写 `{date,total,…}`，彼此的判定都会被对方的写入喂成"格式非法 → 视为无基线"，
 *      表现是门卫时灵时不灵。
 *   ③ **计数查询失败/超时 = 无基线、照常采集**，不是"跳过"。门卫跳过的是一整轮采集，
 *      方向必须是"宁可多跑一轮，绝不让采集停摆"——这是与批发那边（计数失败即跳过本次）
 *      刻意相反的一处：零售没有第二道兜底，漏采就是整天单据在页面上不存在。
 *
 * 基线**只在整轮采集成功之后**写（`guard()` 里 `collect()` 返回之后那一句）：落库跑到一半
 * 失败却把计数记成"见过"，下一轮就判"无变化"永久跳过——那天剩下的单据再也采不进来了，且日志上
 * 完全看不出异常。任何异常路径（源库读取失败、落库中途失败）都不更新基线。这条与批发采集一致。
 *
 * （历史：门卫曾必须排在**状态闭环之后**。闭环 2026-10-05 起停跑（ADR 0018），那条顺序红线
 * 随之失效——**恢复闭环时记得接回门卫之前**。）
 *
 * 本类全程不调码上放心：门卫只发**一条源库只读计数 SQL**（见 `counts()`）。
 */
namespace App;

class RetailCollectionGate
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
     * （tests/retail_collection_gate_test.php）：`$count` 去数当日总数（生产上就是 `counts()`），
     * `$collect` 跑一整轮采集（源库读取 + 认领 + 落库）。
     *
     * @param string        $stateFile 基线文件路径（生产用 `stateFile()`；测试用临时文件）
     * @param string|null   $date      采集日期；**null = 不适用**（`--all` 两年窗口侦察——它的计数
     *                                 口径是"整个两年窗口"，与门卫的"当日"不是一回事：既不比对也不写
     *                                 基线，写了次日 cron 会拿当日的数去比一个窗口的数，永远不等）
     * @param callable      $count     `fn(string $date): array{total:int}`
     *                                 ；失败请抛异常（本方法捕住后按"无基线"处理）
     * @param callable      $collect   `fn(): void`；抛出的任何异常**原样上抛**，基线不写
     * @return array{skipped:bool,counts:?array,reason:string,warning:?string}
     *         `reason` 是给日志的一行话（调用方原样打印，那个数就在那句话里）；`counts` 是这一轮
     *         数到的数（计数失败/快照为 null）——调用方**没有**单独打印它的场景，留着是为了
     *         "这一轮到底有没有数到数"能被直接问出来（测试断言的就是它）；`warning` 非 null 时另起一行打
     * @throws \Throwable `$collect` 抛出的异常原样穿过本方法
     */
    public static function guard(string $stateFile, ?string $date, callable $count, callable $collect): array
    {
        // ── --all：绕开门卫 ──
        if ($date === null) {
            $collect();
            return [
                'skipped' => false,
                'counts' => null,
                'reason' => '两年窗口侦察（--all）：门卫不适用，不计数、不写基线',
                'warning' => null,
            ];
        }

        $read = self::read($stateFile);
        $baseline = $read['baseline'];

        // ── 数当日总数：失败 = 无基线，照常采集（见类注释 ③）──
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
                // 手里没有数，本轮也就没有资格写基线：把旧数写回去会让"计数一直失败"的这几天
                // 看起来像"一直没变化"——那正是永久跳过的那条路
                'reason' => "计数查询失败（{$countError}），视为无基线，照常采集；本轮不写基线",
                'warning' => null,
            ];
        }

        // ── 数没变：跳过整轮（基线一字不动）──
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
     * 计数与基线一致？（纯函数，日期也要对上）
     *
     * 日期不符视同"有变化"：换了采集日期就得重采——基线的语义是"某一天的计数"，
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
        if (!isset($baseline['total'], $counts['total']) || (int)$baseline['total'] !== (int)$counts['total']) {
            return false;
        }
        return (string)($baseline['date'] ?? '') === $date;
    }

    /**
     * 读基线。文件缺失 / 损坏 / 形状不全 / **旧版形状** → 一律视为"无基线"（照常采集），
     * 用 `note` 说明原因。
     *
     * 不抛异常：门卫是"省一轮空转"的优化，任何时候都不该成为采集停摆的理由——读不了就当没有。
     *
     * **旧版形状单列一条**（2026-10-05）：文件里若还有 `uploaded` / `unuploaded`，说明它是这次改
     * 口径**之前**的门卫写的（那时数三个数）。它的 `total` 其实仍然可用，但"形状对不上就整份作废"
     * 是本类一贯的立场（宁可多采一轮），而且换口径的头一轮本来就该把单据重新过一遍。
     *
     * @return array{baseline:?array{date:string,total:int}, note:string}
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

        if (!is_array($decoded)) {
            return ['baseline' => null, 'note' => '基线文件损坏或格式非法'];
        }
        if (isset($decoded['uploaded']) || isset($decoded['unuploaded'])) {
            return ['baseline' => null, 'note' => '基线是旧版形状（含 uploaded/unuploaded，2026-10-05 之前写的）'];
        }
        // 形状校验：日期 + 那个数一个都不能少。半份基线拿去比对，比出来的"没变化"是不可信的
        // ——那正是"采集停摆"的来源，故宁可整份作废
        if (!isset($decoded['date'], $decoded['total'])
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$decoded['date'])) {
            return ['baseline' => null, 'note' => '基线文件损坏或格式非法'];
        }

        return [
            'baseline' => [
                'date' => (string)$decoded['date'],
                'total' => (int)$decoded['total'],
            ],
            'note' => '',
        ];
    }

    /**
     * 写基线（形状：日期 + 当日总数，一个键都不多）。
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
        ], JSON_UNESCAPED_UNICODE);

        return @file_put_contents($file, $payload, LOCK_EX) !== false;
    }

    /**
     * 数当日单据总数：**一条只读 SQL**，源库上就这一次往返（实测 47–59ms，冷启动 761ms）。
     *
     * SQL 形状见类注释 ①：`zsm_ls` 去重成派生表再 `count()`。四处要点：
     *   - **去重不能省**（理由见类注释 ①：不去重数出来的是行数，实测 111 行 vs 55 张单）
     *   - **不 JOIN 任何表**：自 2026-10-05 起不再看源库状态表（ADR 0018）——原先那三个数里的
     *     「已上传」来自与状态表的 LEFT JOIN，它随外部系统的写入一直变，反而让门卫判"有变化"
     *   - 数的是 `zsm_ls` 的单据，故不会像采集 SQL 那样被 `zsm_ls_code` 的一码一行放大（那张表不在本查询里）
     *   - 单据类型清单与 2 年下限都由调用方给/取自 `RetailRetention`，与采集 SQL 同一批口径；
     *     门卫的判定只有在"数的范围和采集的范围逐字一致"时才成立（数窄了会跳过有单要采的那一轮）
     *
     * @param \SqlSrvHelper $source    源库连接（只发 SELECT，不写源库、不调平台）
     * @param string        $date      采集日期（YYYY-MM-DD）
     * @param array<int,int|string> $billTypes 采集的四种单据类型（由调用方传采集脚本用的那份清单）
     * @param string        $cutoff    2 年下限（`RetailRetention::cutoffDate()`），**由调用方算一次传进来**：
     *                                 采集 SQL 用的是脚本开头算的那一份，这里各算各的话，跨零点的那一跑
     *                                 两个范围会差一天。范围不一致正是门卫最危险的失效方式（数窄了 = 跳过有单要采的那一轮）
     * @return array{total:int}
     * @throws \RuntimeException 查询失败——调用方（`guard()`）把它当"无基线"处理，照常采集
     */
    public static function counts(\SqlSrvHelper $source, string $date, array $billTypes, string $cutoff): array
    {
        $sql = "select count(*) as total
                  from (select distinct bill_code from dyt.msfx.dbo.zsm_ls
                         where bill_type in (" . implode(', ', array_map('intval', $billTypes)) . ")
                           and bill_time >= ? and bill_time = ?) ls";

        // 2 年下限与采集 SQL 一样"始终在"（纵深：超期日期在脚本入口已被拒），等值条件是当日口径；
        // 下限用的是调用方给的那一份，与采集 SQL 逐字同一个值
        $row = $source->queryOne($sql, [$cutoff, $date]);
        if (!is_array($row)) {
            throw new \RuntimeException('门卫计数查询失败: ' . $source->getErrorMessage());
        }

        return ['total' => (int)($row['total'] ?? 0)];
    }

    /** 那个数的人类可读形态，供日志与基线比对说明用（`总数 45`） */
    private static function describe(array $counts): string
    {
        return '总数 ' . (int)($counts['total'] ?? 0);
    }
}
