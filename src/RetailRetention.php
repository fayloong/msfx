<?php
/**
 * 门店（零售）数据的保留期——平台硬性规定的单一事实源
 *
 * 平台**不接受 2 年前的单据**。原话见 logs/api_2026-09-30.jsonl（2026-09-30 13:52 补传宝源店
 * WRKA0300000598，单据日期 2023-07-19）——指 JSONL 而非 upload_logs 的同日行：后者会被
 * cleanup_logs 按 3 个月规则清掉，JSONL 才是永久副本。
 *   sub_msg_code: FAIL_BIZ_PARAM_BILL_TIME_BEFORE_ERROR
 *   msg_info:     单据上传失败！:系统不支持上传2年前单据(您上传的是2023-07-19 00:00:00)
 * 故门店采集入库的数据本地只保留最近 2 年：超期的留在门店单据队列里没有任何出口，
 * 点开只会被平台拒绝、在失败记录页留一条噪音。
 *
 * 决策与代价见 docs/adr/0013-retail-two-year-retention.md；需求与验收见
 * .scratch/retail-chain/issues/11-retail-two-year-retention-and-order.md。
 *
 * **两个调用点必须共用这里的截止日**：
 *   - scripts/fetch_bills_retail.php：不再把超期单据拉进补传队列（入口）
 *   - scripts/cleanup_logs.php：把存量中随时间自然过期的删掉（出口）
 * 只堵入口不够——**今天合法的单据两年后就不合法了**，出口得有清理。两处若各写各的年数，
 * 超期数据会滞留（清单里点不动、只能被平台拒），重新制造本规则要解决的问题。
 */
namespace App;

class RetailRetention
{
    /** 保留年数（平台硬性规定，不是本项目可调的参数） */
    public const YEARS = 2;

    /**
     * 保留期截止日（YYYY-MM-DD）：**早于**它的单据即超期，截止日当天保留。
     *
     * 相对"今天"滚动计算（今天是 2026-09-30 → 2024-09-30）。闰日会溢出：2024-02-29 往前
     * 两年没有 2 月 29 日，PHP 给 2022-03-01 而非 2022-02-28——溢出方向恰好是安全的
     * （宁可多删一天，也不留下平台必拒的那一天）。见 tests/retail_retention_test.php。
     *
     * $today 仅供测试注入，生产调用不传（用当天）。
     */
    public static function cutoffDate(?string $today = null): string
    {
        $base = $today ?? date('Y-m-d');
        return date('Y-m-d', strtotime($base . ' -' . self::YEARS . ' years'));
    }
}
