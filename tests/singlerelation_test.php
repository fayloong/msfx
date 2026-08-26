<?php
/**
 * singlerelation 接口逐码查询调试工具（码级对账探针）
 * 用法: php tests/singlerelation_test.php <单号> [--limit N] [--no-detail]
 *
 * 验证码级数量对账方案的核心等式（设计见 .scratch/quantity-check/singlerelation-tier2.md）：
 *   Σ singlerelation(本地每个追溯码).pkg_amount  ==  searchbill.detail 的 min_pkg_count
 *
 * pkg_amount = 该追溯码折算成平台"最小溯源单位"的系数（实测：大包装码=100，
 * 中包装码=5/20，本身就是最小溯源单位的码=1）；min_pkg_count 同为此口径。
 * 相等 = 本地单据的码全部申报且折算数一致（没漏传）。
 *
 * 单号基线: 从 SQLite upload_logs 取 source='batch_check' 且 trace_codes 非空的最长
 * 一条记录（与 check_quantity.php 的基线同源，batch_check 成功单的码必然可查）。
 * 入参: ref_ent_id 与 des_ref_ent_id 均为河药自己（REFENTID_HYYY）。
 *
 * 输出: 逐码 pkg_amount、Σ pkg_amount、平台 min_pkg_count（searchbill.detail 跨子单
 * 累加）及相等/不等结论；第一个码的原始响应另存 tests/singlerelation_<单号>.json
 * （响应结构确认用，解析路径若有偏差以此为准修正）。
 *
 * 注意: 运行时机避开 check_bill_status 的 8-20 点调用窗口（同 AppKey 并发触发限流），
 * 建议 20:05 后运行。
 *
 * 退出码: 0=全部查询成功，1=存在网络/业务错误（如 App Call Limited 限流）。
 */

require_once __DIR__ . '/../vendor/autoload.php';

// CLI 环境下 db.php 不在 include_path，提供桩函数
if (!function_exists('info_log')) {
    function info_log(string $title, string $msg = '', string $level = 'INFO', array $data = []): void {
        $ts = date('Y-m-d H:i:s');
        $ctx = $data ? ' ' . json_encode($data, JSON_UNESCAPED_UNICODE) : '';
        fwrite(STDERR, "[{$ts}] [{$level}] {$title}{$msg}{$ctx}\n");
    }
}

use App\ApiClient;
use App\Config;
use App\Database;
Config::load();

// API 调用间隔：singlerelation 限速按 1 秒 2 次（500ms），与 searchbill.detail 同 AppKey
const API_INTERVAL_US = 500000;
// 默认逐码数量上限（防误用跑爆；--limit 覆盖）。0=无限制
const DEFAULT_LIMIT = 0;
// 每个码查询间隔内打印进度的步长
const PROGRESS_STEP = 20;

$args = $argv;
array_shift($args);
$billCode = null;
$limit = DEFAULT_LIMIT;
$showDetail = true;

for ($ai = 0; $ai < count($args); $ai++) {
    $arg = $args[$ai];
    if ($arg === '--limit' && isset($args[$ai + 1])) {
        $limit = (int)$args[++$ai];
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int)$m[1];
    } elseif ($arg === '--no-detail') {
        $showDetail = false;
    } elseif ($billCode === null) {
        $billCode = $arg;
    }
}

if ($billCode === null) {
    fwrite(STDERR, "用法: php tests/singlerelation_test.php <单号> [--limit N] [--no-detail]\n");
    exit(1);
}

// ── 取该单的追溯码列表（batch_check 成功单基线，与 check_quantity 同源） ──
$rows = Database::getInstance()->query(
    "SELECT trace_codes FROM upload_logs WHERE source = 'batch_check' AND djbh = ?
     AND trace_codes IS NOT NULL AND trace_codes != '' ORDER BY LENGTH(trace_codes) DESC LIMIT 1",
    [$billCode]
);
if (empty($rows)) {
    fwrite(STDERR, "[singlerelation] 单号 {$billCode} 在 upload_logs 无 batch_check 追溯码记录（不是上传成功基线），退出\n");
    exit(1);
}
$codes = array_values(array_filter(array_map('trim', explode(',', $rows[0]['trace_codes']))));
$codes = array_values(array_unique($codes));
$total = count($codes);
if ($limit > 0 && $total > $limit) {
    $codes = array_slice($codes, 0, $limit);
}
echo "[singlerelation] 单号: {$billCode}, 追溯码: {$total} 个" . ($limit > 0 ? "（仅查前 {$limit} 个）" : '') . "\n";

// ── 逐码查询 singlerelation，累加 pkg_amount ──
$apiClient = new ApiClient();
$refEntId = Config::get('REFENTID_HYYY');
$exitCode = 0;
$sum = 0;
$notFoundCodes = [];   // 查询出错/无 pkg_amount 的码（理论不应出现，记录以验证）
$pkgByCode = [];
$firstResp = null;

foreach ($codes as $i => $code) {
    $req = new \AlibabaAlihealthDrugKytSinglerelationRequest;
    $req->setCode($code);
    $req->setRefEntId($refEntId);
    $req->setDesRefEntId($refEntId);

    $result = $apiClient->execute($req);
    usleep(API_INTERVAL_US);

    if ($i === 0) {
        // 第一个码的原始响应存档，供确认响应结构（解析路径以此为准修正）
        $firstResp = $result;
        $file = __DIR__ . '/singlerelation_' . $billCode . '.json';
        file_put_contents($file, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        echo "[saved] {$file}\n";
    }

    if (!$result['success']) {
        $exitCode = 1;
        $notFoundCodes[] = ['code' => $code, 'error' => $result['error']];
        echo "[{$i}/{$total}] {$code} → 查询失败（{$result['error']}）\n";
        continue;
    }

    $respArr = json_decode(json_encode($result['data'], JSON_UNESCAPED_UNICODE), true);
    $pkgAmount = is_array($respArr) ? ApiClient::sumPkgAmount($respArr) : null;
    if ($pkgAmount === null) {
        // 码查不到或响应结构异常——记录下来验证"batch_check 成功单的码必然可查"的假设
        $exitCode = 1;
        $notFoundCodes[] = ['code' => $code, 'error' => '无 pkg_amount（结构: ' . json_encode($result['data'], JSON_UNESCAPED_UNICODE) . '）'];
        echo "[{$i}/{$total}] {$code} → 无 pkg_amount\n";
        continue;
    }

    $sum += $pkgAmount;
    $pkgByCode[$code] = $pkgAmount;

    if ($showDetail && $i < 30) {
        echo "[{$i}/{$total}] {$code} → pkg_amount={$pkgAmount}\n";
    } elseif ($showDetail && $i === 30) {
        echo "...（其余 " . ($total - 30) . " 个码省略，--no-detail 可全关）\n";
    }

    if (($i + 1) % PROGRESS_STEP === 0) {
        printf("\r[%d%%] Σ pkg_amount = %d（%d/%d 码）", (int)round(100 * ($i + 1) / $total), $sum, $i + 1, $total);
    }
}

echo "\n[singlerelation] Σ pkg_amount = {$sum}（" . count($codes) . " 个码，限速 500ms/次）\n";
if (!empty($notFoundCodes)) {
    echo "[singlerelation] 注意: " . count($notFoundCodes) . " 个码查询失败/无 pkg_amount（与'batch_check 成功单的码必然可查'假设矛盾，需排查）\n";
    if ($showDetail) {
        foreach ($notFoundCodes as $n) {
            echo "  - {$n['code']}: {$n['error']}\n";
        }
    }
}

// ── 平台申报总数: searchbill.detail 跨拆分子单累加 min_pkg_count（与 check_quantity 同策略） ──
echo "[singlerelation] 查询 searchbill.detail（原始单号 → _1 → _2...）...\n";
$actual = 0;
$subBills = [];
$foundAny = false;
for ($suffix = 1; $suffix <= 10; $suffix++) {
    $subBill = $suffix === 1 ? $billCode : $billCode . '_' . ($suffix - 1);
    $r = $apiClient->searchBillDetail($subBill);
    usleep(API_INTERVAL_US);
    if ($r['error'] !== '') {
        echo "[singlerelation] searchbill.detail({$subBill}) 查询失败（{$r['error']}），退出\n";
        exit(1);
    }
    if (!$r['found']) {
        if ($suffix === 1) {
            echo "[singlerelation] 注意: 原始单号在平台不存在（拆分子单场景）\n";
            continue;
        }
        break;
    }
    $count = ApiClient::sumBillDetailCount($r['response']);
    if ($count === null) {
        echo "[singlerelation] searchbill.detail({$subBill}) 解析失败，退出\n";
        exit(1);
    }
    $foundAny = true;
    $actual += $count;
    $subBills[] = ['djbh' => $subBill, 'count' => $count];
    echo "[singlerelation] {$subBill}: min_pkg_count={$count}\n";
}

if (!$foundAny) {
    echo "[singlerelation] 平台无此单记录，无法对比\n";
    exit(0);
}

echo "\n[singlerelation] 对比结果:\n";
echo "  Σ pkg_amount（本地码折算） = {$sum}\n";
echo "  min_pkg_count（平台申报）  = {$actual}\n";
if ($sum === $actual) {
    echo "  结论: 相等 ✓（码级口径验证通过，没漏传）\n";
} else {
    echo "  结论: 不等 ✗（差 " . ($sum - $actual) . "，需排查）\n";
}

exit($exitCode);

/**
 * 注: pkg_amount 解析逻辑已收敛到 App\ApiClient::sumPkgAmount（单一事实源，
 * check_quantity.php 第 2 级精查复用），本探针不再维护独立解析函数。
 * 响应结构（2026-08-26 实测确认，存档 tests/singlerelation_<单号>.json）：
 *   result.model_list.code_relation_dto.produce_info_list.produce_info_dto.pkg_amount
 * 实测样本（6片/盒氯雷他定片盒码）：is_smallest="Y"（该码即最小溯源单位）、
 * pkg_amount="1"（系数 1，与"最小单位码=1"的 spec 语义一致）。
 * code_relation_dto 与 produce_info_dto 均兼容单条（关联数组）/多条（列表）两种形态。
 */
