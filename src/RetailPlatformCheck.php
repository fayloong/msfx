<?php
/**
 * 门店单据在**平台上**再核查一次（`alibaba.alihealth.drugtrace.top.lsyd.query.upbilldetail`）。
 *
 * 背景：门店单"是否已上传"原本只有一条判据——源库状态表（`RetailExternalUploads::closeLoop()`，
 * 每轮采集前跑）。那张表记的是**外部系统的行为**，不是平台的承诺：外部系统传了单却没写它，
 * 本系统永远不知道；而且它没有企业列，判据只能按裸单号比对（ADR 0007 记的已知代价）。
 *
 * 本类补第二条判据：拿**各门店自己的凭据**（appkey/secretkey + ref_ent_id）直接问平台。
 * 带 ref_ent_id 意味着这条判据**天然按企业隔离**——同一个单号在甲店查到、在乙店查不到是两个
 * 独立问题，这正是状态表做不到的那一格。
 *
 * 与既有链路的分工（本类**不重写判定与落库**）：
 *   - 判定与落库仍是 `RetailExternalUploads` 那套（`closureActions()` + `applyActions()`）：
 *     本类只负责"按企业分组 → 逐条查平台 → 把每家企业查到的单号收齐"
 *   - 取数口径（`RetailExternalUploads::pendingItems()`）与状态闭环共用一份——"哪些单还算待办"
 *     全仓只有一个答案
 *
 * 四处刻意的取舍：
 *   - **取不到凭据的门店整组跳过**（`未识别` / 待配凭据 / 不在配置中）：没有 ref_ent_id 这条判据
 *     不成立。跳过不是放弃——`未识别` 的任务行仍归源库闭环管（那条判据不需要凭据）
 *   - **查询异常只跳过、不修改**："不知道"不等于"没上传"（与闭环对"源库查不通"的态度一致）
 *   - **不做 `last_checked_at` 门卫、也不 touch**：本脚本由人手动跑（不挂 cron，见 spec），
 *     一趟几千条、@500ms 自带限速；清单还会随翻正自然收敛（已上传的翻正后离开待办清单）。
 *     批发的 30 分钟门卫是给高频 cron 用的——这里没有那个场景，将来真要挂 cron 时再加
 *     （那时 touch 才有意义：现在不 touch，门卫就没有数据可用）
 *   - **主循环抽成收 `callable` 的纯函数**（与 `RetailBatchUpload::run()` 同款）：票面要一条
 *     自包含测试证明"`--dry-run` 一次平台调用都不发"，注入一个"被调用就记一笔"的假回调即可断言
 */
namespace App;

class RetailPlatformCheck
{
    /** 每条查询之间的间隔（微秒）——与批发两个查询脚本同速（1 秒 2 次） */
    public const QUERY_INTERVAL_US = 500000;

    /**
     * 一条待核查项的处理结果——`run()` 交给 `$report` 的事件里的 `outcome`。
     *
     * 由**本类判定**而不是让调用方拿 `$result` 自己看：脚本要拿它决定打什么，`run()` 要拿同一个
     * 判据计数——两处各写一份的话，改口径时总有一处会漏（`RetailBatchUpload::OUTCOME_*` 同理）。
     */
    public const OUTCOME_PLAN     = 'plan';     // --dry-run：这一条只是计划，没查
    public const OUTCOME_UPLOADED = 'uploaded'; // 平台上有 → 会被翻正
    public const OUTCOME_ABSENT   = 'absent';   // 平台"信息不存在" → 未上传，不修改任何痕迹
    public const OUTCOME_ERROR    = 'error';    // 查询异常（网络/平台错误）→ 不修改

    /**
     * 按企业分组，分出「能查的」与「该跳过的」（纯函数：只读企业配置，不发调用、不读数据库）。
     *
     * 跳过判据是**取不到一套填齐的凭据**：`未识别` 不在配置里（`credentialFor()` 返回 null），
     * 待配凭据的门店四字段没填齐（`credentialConfigured()` 为假）——两者都拿不出 ref_ent_id，
     * 而 ref_ent_id 是这个接口的必填项。它们不是"核查失败"，是"这条判据对它们不适用"，
     * 故单独计数、不算进查询统计。
     *
     * @param array<string,array<string,array>> $pending pendingItems() 的清单（企业 => 单号 => 痕迹）
     * @return array{queryable:array<string,array{credential:array,items:array<string,array>}>,skipped:array<string,int>}
     *         `queryable` 里每家企业带上它那套凭据（省得调用方再取一次）；`skipped` 是企业 => 被跳过的**单数**
     */
    public static function groupByCompany(array $pending): array
    {
        $queryable = [];
        $skipped = [];

        foreach ($pending as $company => $byDjbh) {
            $credential = Enterprise::credentialFor((string)$company);
            // 判据用 `Enterprise::isRetail` 而不是"凭据取不到"：前者连"批发行混进来"这类脏数据
            // 一并挡住（fail-closed，与 RetailBatchUpload 同一开关）——拿河药凭据去查门店单号
            // 只会得到"信息不存在"，白烧调用还可能把状态判错
            if ($credential === null
                || !Enterprise::isRetail((string)$company)
                || !Enterprise::credentialConfigured($credential)) {
                $skipped[(string)$company] = count($byDjbh);
                continue;
            }
            $queryable[(string)$company] = [
                'credential' => $credential,
                'items' => $byDjbh,
            ];
        }

        return ['queryable' => $queryable, 'skipped' => $skipped];
    }

    /**
     * 每个企业各自判定（**企业隔离**的落点，纯函数）。
     *
     * `closureActions()` 的 `$uploadedBills` 是**扁平的裸单号集合**（源库状态表没有企业列，
     * 那是它的结构限制）。平台核查的判据带 ref_ent_id、本来就是按企业查的——把每家企业查到的
     * 单号**单独喂给一次 `closureActions()`**，甲店查到的单号就不会翻乙店的同名待办。
     * 逐家调用是刻意的：一次把全量集合喂进去，同名单号跨门店就会互相顶掉。
     *
     * @param array<string,array<string,array>> $pending 全量待办（企业 => 单号 => 痕迹）
     * @param array<string,array<string,bool>>  $uploadedByCompany 各企业查到的已上传单号
     * @param array<string,array<string,bool>>  $success 本地已有成功记录（`successKeys()`）
     * @return array<string,array<string,array{turn_task:bool,append_record:bool}>> 企业 => 单号 => 动作
     */
    public static function actionsByCompany(array $pending, array $uploadedByCompany, array $success): array
    {
        $actions = [];

        foreach ($pending as $company => $byDjbh) {
            $uploaded = $uploadedByCompany[$company] ?? [];
            if (empty($uploaded)) {
                continue;
            }
            foreach (RetailExternalUploads::closureActions([$company => $byDjbh], $success, $uploaded) as $c => $acts) {
                $actions[$c] = $acts;
            }
        }

        return $actions;
    }

    /**
     * 跑一轮平台核查：按企业分组 → 逐条问平台 → 收齐每家企业「平台上有」的单号。
     *
     * 本方法**只查不写**（除了 `$report` 回调）：翻正由调用方拿返回的集合去
     * `actionsByCompany()` + `applyActions()`——那两步是纯函数与既有落库，本方法不重复实现。
     *
     * 逐条 try/catch 且 `error` 非空即算异常：一个门店查不通不该把整轮带下水（各门店的 AppKey
     * 相互独立，某家的密钥/网络出问题只该影响它自己），与批量补传的逐条隔离同口径。
     *
     * @param array<string,array<string,array>> $pending `RetailExternalUploads::pendingItems()` 的清单
     * @param callable $query `fn(string $djbh, array $credential): array{found:bool,response:mixed,error:string}`
     *                        生产上是 `ApiClient::forCredential($credential)->queryUpbillDetail($djbh, $credential['ref_ent_id'])` 的闭包；
     *                        **`$dryRun` 时一次都不调**
     * @param bool $dryRun 只列不查：不调 `$query`、不写任何库
     * @param int|null $limit 最多查几条（null = 不限）；**跳过的门店不占额度**（它们压根不在这条队列里）
     * @param callable|null $report `fn(string $company, string $djbh, array $event): void`，逐条回调；
     *                              `$event` 形状见 `emit()`
     * @param int $intervalUs 每条之间的间隔（微秒）——**参数化是为了可测**：测试传 0 就不必等
     *                        （不传即生产口径 `QUERY_INTERVAL_US`）
     * @return array{companies:int,skipped_companies:int,skipped_items:int,queued:int,uploaded:int,absent:int,error:int,remaining:int,uploaded_by_company:array<string,array<string,bool>>}
     *         `queued` 这轮实际查（或计划查）的条数；`remaining` 被 `--limit` 挡在外面、这轮没碰的条数
     */
    public static function run(
        array $pending,
        callable $query,
        bool $dryRun = false,
        ?int $limit = null,
        ?callable $report = null,
        int $intervalUs = self::QUERY_INTERVAL_US
    ): array {
        $grouped = self::groupByCompany($pending);

        $stats = [
            'companies' => count($grouped['queryable']),
            'skipped_companies' => count($grouped['skipped']),
            'skipped_items' => array_sum($grouped['skipped']),
            'queued' => 0,
            'uploaded' => 0,
            'absent' => 0,
            'error' => 0,
            'remaining' => 0,
        ];
        $uploadedByCompany = [];

        // ── 展平成一条待查队列（企业, 单号），再限量：截断的是"能查的那批"，没碰上的报个数 ──
        $queue = [];
        foreach ($grouped['queryable'] as $company => $entry) {
            foreach ($entry['items'] as $djbh => $_) {
                $queue[] = [$company, (string)$djbh];
            }
        }
        if ($limit !== null && count($queue) > $limit) {
            $stats['remaining'] = count($queue) - $limit;
            $queue = array_slice($queue, 0, $limit);
        }

        foreach ($queue as [$company, $djbh]) {
            $stats['queued']++;

            if ($dryRun) {
                self::emit($report, $company, $djbh, self::OUTCOME_PLAN, null);
                continue;
            }

            try {
                $result = $query($djbh, $grouped['queryable'][$company]['credential']);
            } catch (\Throwable $e) {
                // 链接路自身出错（正常路径下 queryUpbillDetail 不抛）：这一条算异常，继续下一条
                $stats['error']++;
                self::emit($report, $company, $djbh, self::OUTCOME_ERROR, $e->getMessage());
                usleep($intervalUs);
                continue;
            }

            $error = (string)($result['error'] ?? '');
            if ($error !== '') {
                // 查询失败**不冒充"未上传"**：不动任何痕迹，下次再查（与闭环"源库查不通一条都不翻"同理）
                $stats['error']++;
                self::emit($report, $company, $djbh, self::OUTCOME_ERROR, $error);
            } elseif (!empty($result['found'])) {
                $stats['uploaded']++;
                // 单号原样收下：与本地清单的大小写比对由 closureActions() 一处负责
                $uploadedByCompany[$company][$djbh] = true;
                self::emit($report, $company, $djbh, self::OUTCOME_UPLOADED, null);
            } else {
                $stats['absent']++;
                self::emit($report, $company, $djbh, self::OUTCOME_ABSENT, null);
            }

            usleep(self::QUERY_INTERVAL_US);
        }

        $stats['uploaded_by_company'] = $uploadedByCompany;

        return $stats;
    }

    /**
     * 把一条事件交给 `$report`（`null` 回调是常态：调用方可能不关心逐条输出，测试里也常用）。
     *
     * 事件形状：`['outcome' => OUTCOME_*, 'error' => ?string]`——`error` 只在 `OUTCOME_ERROR` 时非 null。
     */
    private static function emit(?callable $report, string $company, string $djbh, string $outcome, ?string $error): void
    {
        if ($report !== null) {
            $report($company, $djbh, [
                'outcome' => $outcome,
                'error' => $error,
            ]);
        }
    }
}
