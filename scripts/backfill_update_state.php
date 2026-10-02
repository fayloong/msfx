<?php
/**
 * 一次性回填：把本项目**已申报成功**的零售门店单号写进 dyt.bs_msfx.dbo.update_state
 *
 * 背景：回写功能上线（工单 18，见 docs/adr/0016）之前，人工补传成功的门店单没有在源库留状态，
 * 外部系统不知道它们已经上过平台，仍会重传（平台会返回"该单据号已存在"）。本脚本按 upload_logs
 * 里已有的成功记录把这批存量补齐。
 *
 * **幂等**：写入走 `App\UpdateStateWriter` 的 `INSERT ... WHERE NOT EXISTS`，可反复重跑——
 * 第一次"写入 N"，之后每次都是"已存在跳过 N"，这正是复验的方式。
 *
 * 判据（全部来自落库事实，不另录清单）：
 *   upload_logs 里 source IN ('retail_retry','manual')、response_status IN ('上传成功','单据重复')，
 *   且 company 是**零售企业**（`Enterprise::isRetail`）——批发的手工上传日志 source 同样是 manual，
 *   只能靠企业类型区分（批发的单进的是 hyyy_zyscm 那条链路，与 dyt 源库这张表无关）。
 *
 * **单号即原始单号**：零售实测单张 ≤1,718 码、不触发拆单（见 ADR 0010），故日志里的 djbh 就是
 * 外部系统认的那个单号。若将来零售开始拆单，判据要先改成剥 `_N` 后缀再回填。
 *
 * 只对 dyt.bs_msfx.dbo.update_state 做 INSERT，不 UPDATE/DELETE；不调任何平台接口。
 * 用法: php scripts/backfill_update_state.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

// CLI stub
if (!function_exists('info_log')) {
    function info_log(string $title, string $msg = '', string $level = 'INFO', array $data = []): void {
        $ts = date('Y-m-d H:i:s');
        $ctx = $data ? ' ' . json_encode($data, JSON_UNESCAPED_UNICODE) : '';
        fwrite(STDERR, "[{$ts}] [{$level}] {$title}{$msg}{$ctx}\n");
    }
}

use App\Config;
use App\Database;
use App\Enterprise;
use App\UpdateStateWriter;

Config::load();

$db = Database::getInstance();

$rows = $db->query(
    "SELECT DISTINCT djbh, company FROM upload_logs
     WHERE source IN ('retail_retry', 'manual')
       AND response_status IN ('上传成功', '单据重复')"
);

$codes = [];
foreach ($rows as $row) {
    // 批发企业的手工上传日志也在这个结果里（source='manual'），按企业类型剔除
    if (!Enterprise::isRetail((string)($row['company'] ?? ''))) {
        continue;
    }
    $code = trim((string)($row['djbh'] ?? ''));
    if ($code !== '') {
        $codes[$code] = true;
    }
}

echo "[backfill_update_state] 待回填 " . count($codes) . " 个单号（零售企业、日志里已申报成功）\n";

if (empty($codes)) {
    exit(0);
}

$writer = new UpdateStateWriter();
$inserted = 0;
$skipped = 0;
$failed = 0;

foreach (array_keys($codes) as $code) {
    $affected = $writer->mark($code);
    if ($affected === false) {
        $failed++;
        echo "[backfill_update_state] 写入失败: {$code}（原因见 logs/api_*.jsonl 的 update_state_write_failed）\n";
    } elseif ($affected > 0) {
        $inserted++;
    } else {
        $skipped++;
    }
}

echo "[backfill_update_state] 完成: 写入 {$inserted}, 已存在跳过 {$skipped}, 失败 {$failed}\n";
exit($failed > 0 ? 1 : 0);
