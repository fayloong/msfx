<?php
/**
 * 数量对账：检查单据是否上传到码上放心平台
 * 用法: php scripts/check_quantity.php [日期 Y-m-d]（默认昨天）
 *
 * 定位: 外部系统负责上传，本项目只检查上传情况（不补传）。
 * 以 SQL Server 单据列表为基线（fetchBillsMeta，不写库），逐单查询平台原始单号
 * + 拆分子单（_1/_2/...），判定单据是否已上传（searchBillDetail 的 found 判定）。
 *
 * 历史说明: 早期版本以"平台 min_pkg_count 之和"与"本地追溯码数"对比报"数量不符"，
 * 但 min_pkg_count 是最小包装数（中包装码/件码按件内盒数展开申报），与码数量纲不同，
 * 含中包装码的单据必然误报。故数量对账退化为"是否上传"检查，不再比对数量。
 *
 * 未上传单据写 upload_logs（response_status='信息不存在'，source=quantity_check）
 * ＋ JSONL，Web 失败记录页可见；已上传单据不写记录。
 * 幂等: 每次运行先清理该单旧的 quantity_check 记录再按新判定写入，限流熔断后
 * 下次运行重查不产生重复/残留记录（历史"数量不符"误报随重跑自动清除）。
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
use App\LogWriter;
use App\TaskFetcher;
Config::load();

// 拆分子单查询上限（原始单号 + _1.._9，3500 码/批 × 10 已远超现实单据）
const MAX_SUB_BILLS = 10;
// API 调用间隔（ms），比 check_bill_status 保守：数量对账单次量大，易触发平台 App Call Limited 限流
const API_INTERVAL_US = 1000000;
// 魔法字符串收敛为常量
const SOURCE_QUANTITY_CHECK = 'quantity_check';
const RESPONSE_STATUS_NOT_FOUND = '信息不存在';
const PLATFORM_LIMIT_ERROR = 'App Call Limited';

$date = $argv[1] ?? date('Y-m-d', strtotime('-1 day'));

echo "[check_quantity] 开始上传检查，日期: {$date}\n";

try {
    // ── 基线: 从 SQL Server 拉取单据列表（复用 TaskFetcher 轻量查询，不写库） ──
    $fetcher = new TaskFetcher();
    $bills = $fetcher->fetchBillsMeta($date);
    if (empty($bills)) {
        echo "[check_quantity] {$date} 没有单据，退出\n";
        exit(0);
    }

    $apiClient = new ApiClient();
    $logWriter = new LogWriter();
    $now = date('Y-m-d H:i:s');

    $total = count($bills);
    $uploadedCount = 0;
    $notFoundCount = 0;
    $errorCount = 0;
    $limited = false;

    foreach ($bills as $i => $bill) {
        $djbh = $bill['djbh'];
        $n = $i + 1;

        // ── 查询原始单号 + 拆分子单，判定是否已上传 ──
        $foundAny = false;
        $queryError = '';

        for ($suffix = 1; $suffix <= MAX_SUB_BILLS; $suffix++) {
            $billCode = $suffix === 1 ? $djbh : $djbh . '_' . ($suffix - 1);
            $result = $apiClient->searchBillDetail($billCode);
            usleep(API_INTERVAL_US); // 每次调用后限速（含查不到的调用）

            // 平台限流（App Call Limited）：本轮熔断，剩余单据下次运行自动重查（幂等）
            if ($result['error'] !== '' && str_contains($result['error'], PLATFORM_LIMIT_ERROR)) {
                $limited = true;
                echo "[check_quantity] 平台限流（{$result['error']}），剩余 " . ($total - $n + 1) . " 单本轮跳过，下次运行自动重查\n";
                break 2;
            }

            // API 异常（网络/业务错误）：跳过整单，不误报未上传
            if ($result['error'] !== '') {
                $queryError = $result['error'];
                break;
            }

            // 查不到：原始单号查不到继续查拆分子单（外部系统拆分上传场景）；
            // 拆分子单查不到则拆分序列结束
            if (!$result['found']) {
                if ($suffix === 1) {
                    continue;
                }
                break;
            }

            // 查到了：原始单号存在即已上传，无需再查拆分子单（省 API 调用）
            $foundAny = true;
            if ($suffix === 1) {
                break;
            }
        }

        if ($queryError !== '') {
            $errorCount++;
            echo "[{$n}/{$total}] {$djbh} → 查询异常（{$queryError}），跳过\n";
            continue;
        }

        // ── 先清理该单旧的 quantity_check 记录（幂等 + 清除历史"数量不符"误报） ──
        $db = Database::getInstance();
        $db->execute(
            "DELETE FROM upload_logs WHERE djbh = ? AND rq = ? AND source = ?",
            [$djbh, $bill['rq'] ?? '', SOURCE_QUANTITY_CHECK]
        );

        if ($foundAny) {
            $uploadedCount++;
            printf("\r[%s] %d%% (%d/%d)", str_repeat('=', (int)round(50 * $n / $total)) . str_repeat('-', 50 - (int)round(50 * $n / $total)), (int)round(100 * $n / $total), $n, $total);
            continue;
        }

        // ── 未上传: 写 upload_logs（response_status='信息不存在'）＋ JSONL（LogWriter 双写） ──
        $notFoundCount++;
        $respJson = json_encode([
            'djbh' => $djbh,
            'rq' => $bill['rq'] ?? '',
            'status' => '未上传',
        ], JSON_UNESCAPED_UNICODE);

        $logWriter->write([
            'task_id' => 0,
            'djbh' => $djbh,
            'ent_name' => $bill['ent_name'] ?? '',
            'rq' => $bill['rq'] ?? '',
            'source' => SOURCE_QUANTITY_CHECK,
            'request_status' => '请求成功',
            'response_status' => RESPONSE_STATUS_NOT_FOUND,
            'response' => $respJson,
        ]);

        echo "[{$n}/{$total}] {$djbh} → 未上传（平台无记录）\n";
    }

    $checked = $uploadedCount + $notFoundCount + $errorCount;
    echo "\n\n[check_quantity] 检查完成: 已上传 {$uploadedCount} / 未上传 {$notFoundCount} / 异常 {$errorCount} (已查 {$checked}/{$total} 条，日期 {$date})\n";
    if ($notFoundCount > 0) {
        echo "[check_quantity] 未上传记录已写入 upload_logs（response_status='信息不存在'），可在 Web 失败记录页查看\n";
    }
    if ($limited) {
        echo "[check_quantity] 注意: 本轮因平台限流未查完，下次运行会自动重查剩余单据\n";
    }

} catch (\Exception $e) {
    echo "[check_quantity] 错误: " . $e->getMessage() . "\n";
    exit(1);
}
