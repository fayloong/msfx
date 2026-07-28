<?php

namespace App;

class TaskFetcher
{
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
        WHERE a.rq >= '{$date}' AND a.rq <= '{$date}'

        UNION ALL

        SELECT DISTINCT LEFT(a.djbh,3) AS type, a.rq, a.djbh, a.erpbillcode, bd.businessname
        FROM skwms_new.dbo.v_jzorder_hz a
        JOIN skwms_new.dbo.mchk c ON c.dwbh = a.dwbh
        JOIN hyyy_zyscm.dbo.businessdoc bd ON bd.businessid = c.entdwbh
        JOIN skwms_new.dbo.v_sjdmx_mx d ON d.ysdjbh = a.djbh
        WHERE a.rq >= '{$date}' AND a.rq <= '{$date}'

        IF OBJECT_ID('tempdb..#task_detail') IS NOT NULL
            DROP TABLE #task_detail

        SELECT LEFT(a.djbh,3) AS type,
            a.rq, a.djbh, a.erpbillcode, bd.businessname AS ent_name, b.dzjgm AS trace_codes
        INTO #task_detail
        FROM skwms_new.dbo.v_pf_phlrhz a
        JOIN skwms_new.dbo.wms_dzjg b ON b.djbh = a.djbh
        JOIN skwms_new.dbo.mchk c ON c.dwbh = a.dwbh
        JOIN hyyy_zyscm.dbo.businessdoc bd ON bd.businessid = c.entdwbh
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
        WHERE a.rq >= '{$date}' AND a.rq <= '{$date}'

        UNION ALL

        SELECT DISTINCT LEFT(a.djbh,3) AS type, a.rq, a.djbh, a.erpbillcode, bd.businessname
        FROM skwms_new.dbo.v_jzorder_hz a
        JOIN skwms_new.dbo.mchk c ON c.dwbh = a.dwbh
        JOIN hyyy_zyscm.dbo.businessdoc bd ON bd.businessid = c.entdwbh
        JOIN skwms_new.dbo.v_sjdmx_mx d ON d.ysdjbh = a.djbh
        WHERE a.rq >= '{$date}' AND a.rq <= '{$date}'

        SELECT * FROM #bill_list
        ";
    }
}
