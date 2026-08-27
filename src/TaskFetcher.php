<?php

namespace App;

class TaskFetcher
{
    /** 非药品剂型关键词：含这些词的 jixing 行（消杀用品/器械/商品/食品等）平台药品追溯不申报，数量对账基线剔除 */
    private const NON_DRUG_JIXING_KEYWORDS = [
        '商品', '食品', '消杀', '用品', '器械', '化妆品', '消毒剂', '敷料', '试剂', '材料', '设备',
    ];

    private \SqlSrvHelper $db;

    public function __construct(?array $config = null)
    {
        $this->db = new \SqlSrvHelper($config ?? [
            'server' => Config::get('DB_SERVER', '192.168.2.133'),
            'port' => Config::get('DB_PORT', '1433'),
            'database' => Config::get('DB_DATABASE', 'hyyy_zyscm'),
            'username' => Config::get('DB_USERNAME', 'sa'),
            'password' => Config::get('DB_PASSWORD', ''),
        ]);
    }

    /**
     * 从 SQL Server 拉取当天待上传单据。
     *
     * @param string|null $date 日期 Y-m-d，null 表示当天
     * @return array<int, array{type: string, rq: string, djbh: string, erpbillcode: string, ent_name: string, sn: string}>
     */
    public function fetchBills(?string $date = null): array
    {
        $date = $this->validateDate($date ?? date('Y-m-d'));

        $sql = $this->buildQuery($date);
        $results = $this->db->executeBatch($sql);

        // executeBatch 返回多结果集，最后一个结果集是 #task_detail
        $rows = end($results);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        // 按单号聚合追溯码
        $bills = [];
        foreach ($rows as $row) {
            $key = $row['djbh'];
            if (!isset($bills[$key])) {
                $bills[$key] = [
                    'type' => $row['type'],
                    'rq' => $row['rq'],
                    'djbh' => $row['djbh'],
                    'erpbillcode' => $row['erpbillcode'] ?? '',
                    'ent_name' => $row['ent_name'] ?? '',
                    'sn' => [],
                ];
            }
            if (!empty($row['trace_codes'])) {
                $bills[$key]['sn'][] = $row['trace_codes'];
            }
        }

        // 合并为一个逗号分隔字符串
        foreach ($bills as &$bill) {
            $bill['sn'] = array_unique($bill['sn']);
            $bill['sn'] = implode(',', $bill['sn']);
        }
        unset($bill);

        return array_values($bills);
    }

    /**
     * 查询待处理单据数量（用于仪表盘）。
     */
    public function countPending(?string $date = null): int
    {
        $date = $this->validateDate($date ?? date('Y-m-d'));
        $sql = $this->buildCountQuery($date);
        $results = $this->db->executeBatch($sql);
        $rows = end($results);
        return is_array($rows) ? count($rows) : 0;
    }

    /**
     * 拉取指定日期单据元数据（单号/日期/往来单位），不含追溯码明细。
     * 数量对账用：仅需单据列表判断是否上传，避免重查询 wms_dzjg 追溯码明细。
     *
     * @param string|null $date 日期 Y-m-d，null 表示当天
     * @return array<int, array{type: string, rq: string, djbh: string, erpbillcode: string, ent_name: string}>
     */
    public function fetchBillsMeta(?string $date = null): array
    {
        $date = $this->validateDate($date ?? date('Y-m-d'));
        $sql = $this->buildCountQuery($date);
        $results = $this->db->executeBatch($sql);
        $rows = end($results);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }
        return array_values($rows);
    }

    /**
     * 拉取指定日期单据的本地应有数量基线（最小包装单位数，数量对账用）。
     *
     * 以 fetchBillsMeta 单据列表为基线，逐单聚合明细视图 SUM(shl)，返回结构与基线一一对应。
     *
     * @param string $date 日期 Y-m-d
     * @return array<int, array{type: string, rq: string, djbh: string, erpbillcode: string, ent_name: string, expected: int|null}>
     *         expected 为 null 表示该单在明细视图无行（SUM(shl) 为 NULL），无法核对，上层应跳过不误报
     */
    public function fetchBillQuantities(string $date): array
    {
        $bills = $this->fetchBillsMeta($date);
        if (empty($bills)) {
            return [];
        }
        $expectedMap = $this->fetchBillQuantitiesByCodes(array_column($bills, 'djbh'));
        foreach ($bills as &$bill) {
            $bill['expected'] = $expectedMap[$bill['djbh']] ?? null;
        }
        unset($bill);
        return $bills;
    }

    /**
     * 按单号列表聚合本地应有数量（最小包装单位数，数量对账用）。
     *
     * 从明细视图聚合 SUM(shl)：
     * - 出库侧：v_pf_phlrmx 按 djbh 分组（覆盖 XSO 销售出库/JHO 退货出库）
     * - 入库侧：v_sjdmx_mx 按 ysdjbh 分组（入库单号在 ysdjbh 列，djbh 是 JHI 前缀临时单，
     *   沿用 fetchBillsMeta 的 join 模式）
     * 用单号 IN 列表聚合而非 rq 日期过滤——rq 字符串比较在明细视图上不可靠（实测
     * BETWEEN 返回空而单条 djbh 查询正常），IN 列表与单据基线天然一致。
     *
     * shl 即"已展开的最小包装单位数"（整件行 shl = baozhshl × jlgg、零散行 shl = lingsshl），
     * 与平台 searchbill.detail 的 min_pkg_count 同量纲，可直接对比（见 ADR 0004）。
     * 非药品行（jixing 含消杀/器械/商品/食品等，spkfk 查不到剂型的行保守保留）从聚合中剔除——
     * 平台是药品追溯平台，外部系统按平台规则不申报非药品（实测 08-15 有 16 条"数量不符"纯属此类）。
     *
     * @param array<int, string> $djbhList 单据单号列表
     * @return array<string, int|null> djbh => SUM(shl)；明细视图无行（SUM 为 NULL）时为 null（无法核对）
     */
    public function fetchBillQuantitiesByCodes(array $djbhList): array
    {
        if (empty($djbhList)) {
            return [];
        }

        // 单号来自 SQL Server 信任域（字母数字），转义单引号后内插 IN 列表
        $escaped = array_map(static fn ($djbh) => str_replace("'", "''", $djbh), $djbhList);
        $inList = "'" . implode("','", $escaped) . "'";

        $outResults = $this->db->query(
            "SELECT m.djbh, SUM(m.shl) AS total
             FROM skwms_new.dbo.v_pf_phlrmx m
             LEFT JOIN skwms_new.dbo.spkfk s ON s.spid = m.spid
             WHERE m.djbh IN ({$inList}) AND {$this->drugRowCondition()}
             GROUP BY m.djbh"
        );
        $inResults = $this->db->query(
            "SELECT m.ysdjbh AS djbh, SUM(m.shl) AS total
             FROM skwms_new.dbo.v_sjdmx_mx m
             LEFT JOIN skwms_new.dbo.spkfk s ON s.spid = m.spid
             WHERE m.ysdjbh IN ({$inList}) AND {$this->drugRowCondition()}
             GROUP BY m.ysdjbh"
        );

        $sumByDjbh = [];
        foreach (array_merge($outResults, $inResults) as $row) {
            $sumByDjbh[$row['djbh']] = (int)$row['total'];
        }

        return $sumByDjbh;
    }

    /**
     * 按单号列表现查 wms_dzjg 追溯码（第 2 级码级精查的码基线，替代 batch_check 快照）。
     *
     * 背景（2026-08-26 复核会话）：batch_check 的 trace_codes 是 fetch_bills 采集时刻的
     * 快照，而大包装箱码（氯化钠/葡萄糖 40/50/120瓶/箱，整件只有大码）常在发货环节由
     * 手持扫码补录进 wms_dzjg（shuom='手持扫码'，xuhao 与采集码连续段间隔跳跃）——
     * 快照永远缺补录码，导致"数量不符"假阳性（实测 25/25 全假阳性，见
     * .scratch/quantity-check/singlerelation-tier2.md）。check_quantity 21:10 运行
     * 必然晚于发货补录，现查天然规避。
     *
     * 出库侧：wms_dzjg 按 djbh 直接取全（与 fetch_bills 采集同构）
     * 入库侧：wms_dzjg_rk 需 join v_sjdmx_mx（ysdjbh 为入库原始单号，与
     *   fetchBillQuantitiesByCodes 的入库侧 join 模式一致）
     * 单号 IN 聚合；现查无码（码全删/视图无行）→ 不返回该单，调用方回退快照基线。
     *
     * @param array<int, string> $djbhList 单据单号列表
     * @return array<string, array<int, string>> djbh => 码列表（去重保序）
     */
    public function fetchWmsCodesByDjbhList(array $djbhList): array
    {
        if (empty($djbhList)) {
            return [];
        }

        // 单号来自 SQL Server 信任域（字母数字），转义单引号后内插 IN 列表
        $escaped = array_map(static fn ($djbh) => str_replace("'", "''", $djbh), $djbhList);
        $inList = "'" . implode("','", $escaped) . "'";

        $outRows = $this->db->query(
            "SELECT djbh, dzjgm FROM skwms_new.dbo.wms_dzjg WHERE djbh IN ({$inList})"
        );
        $inRows = $this->db->query(
            "SELECT d.ysdjbh AS djbh, b.dzjgm
             FROM skwms_new.dbo.v_sjdmx_mx d
             JOIN skwms_new.dbo.wms_dzjg_rk b ON b.djbh = d.ysdjbh AND b.dj_sn = d.ydj_sn AND b.spid = d.spid
             WHERE d.ysdjbh IN ({$inList})"
        );

        $codesByDjbh = [];
        foreach (array_merge($outRows, $inRows) as $row) {
            $code = trim((string)($row['dzjgm'] ?? ''));
            if ($code === '') {
                continue;
            }
            if (!isset($codesByDjbh[$row['djbh']])) {
                $codesByDjbh[$row['djbh']] = [];
            }
            $codesByDjbh[$row['djbh']][$code] = true; // 关联数组去重（保序）
        }

        return array_map(static fn (array $codes) => array_keys($codes), $codesByDjbh);
    }

    /** 药品行过滤条件 SQL 片段：jixing 不含非药品关键词（spkfk 查不到剂型时保守保留） */
    private function drugRowCondition(): string
    {
        $parts = [];
        foreach (self::NON_DRUG_JIXING_KEYWORDS as $kw) {
            $parts[] = "s.jixing NOT LIKE '%{$kw}%'";
        }
        return '(s.jixing IS NULL OR (' . implode(' AND ', $parts) . '))';
    }

    /**
     * 查询指定日期 SALEOUTMT/PURINMT 当天单据计数（fetch_bills 变化检测门卫用）。
     * 轻量查询，用于判断是否需要执行重查询 fetchBills。
     *
     * @throws \RuntimeException 计数查询无结果时
     */
    public function countBills(string $date): int
    {
        $date = $this->validateDate($date);
        $row = $this->db->queryOne(
            "SELECT (SELECT COUNT(1) FROM SALEOUTMT WHERE Dates = ?) + (SELECT COUNT(1) FROM PURINMT WHERE Dates = ?) AS cnt",
            [$date, $date]
        );
        if ($row === false) {
            throw new \RuntimeException('单据计数查询无结果');
        }
        return (int)($row['cnt'] ?? 0);
    }

    private function validateDate(string $date): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException("日期格式无效: {$date}，需要 YYYY-MM-DD");
        }
        return $date;
    }

    private function buildQuery(string $date): string
    {
        return "
        IF OBJECT_ID('tempdb..#bill_list') IS NOT NULL
            DROP TABLE #bill_list

        SELECT DISTINCT LEFT(a.djbh,3) AS type,
        a.rq, a.djbh, a.erpbillcode, bd.businessname AS ent_name
        INTO #bill_list
        FROM skwms_new.dbo.v_pf_phlrhz a
        JOIN skwms_new.dbo.mchk c ON c.dwbh = a.dwbh
        JOIN hyyy_zyscm.dbo.businessdoc bd ON bd.businessid = c.entdwbh
        WHERE a.is_zx = '是' AND a.rq >= '{$date}' AND a.rq <= '{$date}'

        UNION ALL

        SELECT DISTINCT LEFT(a.djbh,3) AS type, a.rq, a.djbh, a.erpbillcode, bd.businessname
        FROM skwms_new.dbo.v_jzorder_hz a
        JOIN skwms_new.dbo.mchk c ON c.dwbh = a.dwbh
        JOIN hyyy_zyscm.dbo.businessdoc bd ON bd.businessid = c.entdwbh
        JOIN skwms_new.dbo.v_sjdmx_mx d ON d.ysdjbh = a.djbh
        WHERE a.is_zx = '是' AND a.rq >= '{$date}' AND a.rq <= '{$date}'

        IF OBJECT_ID('tempdb..#task_detail') IS NOT NULL
            DROP TABLE #task_detail

        SELECT LEFT(a.djbh,3) AS type,
            a.rq, a.djbh, a.erpbillcode, bd.businessname AS ent_name, b.dzjgm AS trace_codes
        INTO #task_detail
        from skwms_new.dbo.v_pf_phlrhz a
        join skwms_new.dbo.wms_dzjg b on b.djbh = a.djbh
        join skwms_new.dbo.mchk c on c.dwbh = a.dwbh
        join hyyy_zyscm.dbo.businessdoc bd on bd.businessid = c.entdwbh
        WHERE EXISTS(SELECT * FROM #bill_list x WHERE x.djbh = a.djbh)

        UNION ALL

        SELECT LEFT(a.djbh,3) AS type, a.rq, a.djbh, a.erpbillcode, bd.businessname, b.dzjgm
        FROM skwms_new.dbo.v_jzorder_hz a
        JOIN skwms_new.dbo.mchk c ON c.dwbh = a.dwbh
        JOIN hyyy_zyscm.dbo.businessdoc bd ON bd.businessid = c.entdwbh
        JOIN skwms_new.dbo.v_sjdmx_mx d ON d.ysdjbh = a.djbh
        JOIN skwms_new.dbo.wms_dzjg_rk b ON b.djbh = d.ysdjbh AND b.dj_sn = d.ydj_sn AND b.spid = d.spid
        WHERE EXISTS(SELECT * FROM #bill_list x WHERE x.djbh = a.djbh)

        SELECT * FROM #task_detail
        ";
    }

    private function buildCountQuery(string $date): string
    {
        return "
        IF OBJECT_ID('tempdb..#bill_list') IS NOT NULL
            DROP TABLE #bill_list

        SELECT DISTINCT LEFT(a.djbh,3) AS type,
            a.rq, a.djbh, a.erpbillcode, bd.businessname AS ent_name
        INTO #bill_list
        FROM skwms_new.dbo.v_pf_phlrhz a
        JOIN skwms_new.dbo.mchk c ON c.dwbh = a.dwbh
        JOIN hyyy_zyscm.dbo.businessdoc bd ON bd.businessid = c.entdwbh
        WHERE a.is_zx = '是' AND a.rq >= '{$date}' AND a.rq <= '{$date}'

        UNION ALL

        SELECT DISTINCT LEFT(a.djbh,3) AS type, a.rq, a.djbh, a.erpbillcode, bd.businessname
        FROM skwms_new.dbo.v_jzorder_hz a
        JOIN skwms_new.dbo.mchk c ON c.dwbh = a.dwbh
        JOIN hyyy_zyscm.dbo.businessdoc bd ON bd.businessid = c.entdwbh
        JOIN skwms_new.dbo.v_sjdmx_mx d ON d.ysdjbh = a.djbh
        WHERE a.is_zx = '是' AND a.rq >= '{$date}' AND a.rq <= '{$date}'

        SELECT * FROM #bill_list
        ";
    }
}
