<?php
/**
 * 数量对账：核对已上传成功单据的申报数量是否传齐
 * 用法: php scripts/check_quantity.php [日期 Y-m-d]（默认昨天）
 *
 * 定位: 外部系统负责上传，本项目只检查上传情况（不补传）。
 * 查询范围仅针对 check_bill_status.php 已检查过且状态是"上传成功"的单据
 * （upload_logs source='batch_check' AND response_status='上传成功'）——未上传的单据
 * 由 check_bill_status 以任务状态（等待上传）反映，数量对账不重复查询/告警。
 *
 * 逐单查询平台原始单号 + 拆分子单（_1/_2/...），跨子单累加申报数量（min_pkg_count），
 * 与本地应有数量（SUM(shl)，已展开的最小包装单位数，见 ADR 0004）对比。
 *
 * 判定分支:
 * - actual ≠ expected → response_status='数量不符'（非成功状态，Web 失败记录页天然可见），
 *   response 存 {djbh, rq, expected, actual, sub_bills:[{djbh, count}]}
 * - 相等 → 传齐零记录（只告警不补传）
 * - 全序列查不到 → '信息不存在'（防御分支：batch_check 已确认上传成功但平台查不到，异常情况）
 * - 无法核对跳过（不写任何记录）: 本地 SUM(shl) 为 NULL（明细视图无行）、
 *   平台响应解析失败（sumBillDetailCount 返回 null）——不误报
 *
 * 查询策略 "相等即停，不等查尽": 原始单号查到且数量相等即停（未拆分大头单
 * 1 次调用）；原始单号查不到或数量不等则继续查 _1.._9 直到查不到（上限 10 次），
 * 跨子单累加 min_pkg_count——防止外部系统"原单号+拆分并存"场景漏计。
 *
 * 幂等: 每轮先清理目标日期全部 quantity_check 记录再按新判定写入（限流熔断后
 * 下次运行重查不产生残留，历史"数量不符"误报随重跑自动清除）。
 * 运行时机: 必须避开 check_bill_status（8-20 点每 5 分钟一轮）的调用窗口，
 * 否则并发触发平台限流，cron 配 21:10 每天一次（默认检查昨天，参数可指定日期）。
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
const SOURCE_BATCH_CHECK = 'batch_check';
const RESPONSE_STATUS_UPLOADED = '上传成功';
const RESPONSE_STATUS_NOT_FOUND = '信息不存在';
const RESPONSE_STATUS_MISMATCH = '数量不符';
const PLATFORM_LIMIT_ERROR = 'App Call Limited';

/** 写 quantity_check 判定记录（JSONL + SQLite 双写，task_id 恒为 0） */
function writeCheckLog(LogWriter $logWriter, string $djbh, string $rq, string $entName, string $status, string $respJson): void
{
    $logWriter->write([
        'task_id' => 0,
        'djbh' => $djbh,
        'ent_name' => $entName,
        'rq' => $rq,
        'source' => SOURCE_QUANTITY_CHECK,
        'request_status' => '请求成功',
        'response_status' => $status,
        'response' => $respJson,
    ]);
}

$date = $argv[1] ?? date('Y-m-d', strtotime('-1 day'));

echo "[check_quantity] 开始数量对账，日期: {$date}\n";

try {
    // ── 基线: check_bill_status 已检查过且状态是"上传成功"的单据（不写库查询） ──
    $db = Database::getInstance();
    $successRows = $db->query(
        "SELECT DISTINCT djbh, ent_name, rq FROM upload_logs WHERE source = ? AND response_status = ? AND rq = ?",
        [SOURCE_BATCH_CHECK, RESPONSE_STATUS_UPLOADED, $date]
    );
    if (empty($successRows)) {
        echo "[check_quantity] {$date} 没有 check_bill_status 确认上传成功的单据，退出\n";
        exit(0);
    }

    $fetcher = new TaskFetcher();
    // 本地应有数量（SUM(shl)，剔除非药品行）；明细无行（null）→ 无法核对
    $expectedMap = $fetcher->fetchBillQuantitiesByCodes(array_column($successRows, 'djbh'));

    // 本轮判定前清理目标日期全部 quantity_check 记录（幂等：限流熔断后下次运行
    // 重查不产生残留；旧全量基线时代的"信息不存在"记录随重跑自动清除）
    $db->execute(
        "DELETE FROM upload_logs WHERE source = ? AND rq = ?",
        [SOURCE_QUANTITY_CHECK, $date]
    );

    $apiClient = new ApiClient();
    $logWriter = new LogWriter();
    $now = date('Y-m-d H:i:s');

    $total = count($successRows);
    $uploadedCount = 0;
    $mismatchCount = 0;
    $notFoundCount = 0;
    $errorCount = 0;
    $unverifiableCount = 0;
    $limited = false;

    foreach ($successRows as $i => $bill) {
        $djbh = $bill['djbh'];
        $expected = $expectedMap[$djbh] ?? null;
        $n = $i + 1;

        // 本地无法核对（明细视图无行，SUM(shl) 为 NULL）→ 跳过，不写任何记录
        if ($expected === null) {
            $unverifiableCount++;
            echo "[{$n}/{$total}] {$djbh} → 本地明细无数量数据，无法核对，跳过\n";
            continue;
        }

        // ── 查询序列: 原始单号 → _1 → _2... 直到查不到（上限 MAX_SUB_BILLS 次） ──
        // 相等即停: 任一步累计实际==期望立即结束；不等查尽: 查不到或不等继续查子单
        $actual = 0;
        $subBills = [];
        $foundAny = false;
        $queryError = '';
        $parseFailed = false;

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

            // API 异常（网络/业务错误）：跳过整单，不误报未上传/数量不符
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

            // 查到了：累加申报数量（min_pkg_count 之和）
            $foundAny = true;
            $count = ApiClient::sumBillDetailCount($result['response']);
            if ($count === null) {
                // 平台响应解析失败 → 无法核对，跳过（解析问题不伪装成数量差异）
                $parseFailed = true;
                break;
            }
            $actual += $count;
            $subBills[] = ['djbh' => $billCode, 'count' => $count];

            // 相等即停：任何一步累计实际==期望立即结束
            if ($actual === $expected) {
                break;
            }
            // 不等查尽：继续查下一个拆分子单（防止"原单号+拆分并存"漏计）
        }

        if ($queryError !== '') {
            $errorCount++;
            echo "[{$n}/{$total}] {$djbh} → 查询异常（{$queryError}），跳过\n";
            continue;
        }

        // 平台响应解析失败 → 无法核对，跳过（不写任何记录）
        if ($parseFailed) {
            $unverifiableCount++;
            echo "[{$n}/{$total}] {$djbh} → 平台响应解析失败，无法核对，跳过\n";
            continue;
        }

        // ── 全序列查不到 → 信息不存在（防御分支：batch_check 已确认上传成功但平台查不到） ──
        if (!$foundAny) {
            $notFoundCount++;
            $respJson = json_encode([
                'djbh' => $djbh,
                'rq' => $bill['rq'] ?? '',
                'status' => '未上传',
            ], JSON_UNESCAPED_UNICODE);

            writeCheckLog($logWriter, $djbh, $bill['rq'] ?? '', $bill['ent_name'] ?? '', RESPONSE_STATUS_NOT_FOUND, $respJson);

            echo "[{$n}/{$total}] {$djbh} → 未上传（平台无记录）\n";
            continue;
        }

        // ── 数量不符: 写 upload_logs（response_status='数量不符'）＋ JSONL ──
        if ($actual !== $expected) {
            $mismatchCount++;
            $respJson = json_encode([
                'djbh' => $djbh,
                'rq' => $bill['rq'] ?? '',
                'expected' => $expected,
                'actual' => $actual,
                'sub_bills' => $subBills,
            ], JSON_UNESCAPED_UNICODE);

            writeCheckLog($logWriter, $djbh, $bill['rq'] ?? '', $bill['ent_name'] ?? '', RESPONSE_STATUS_MISMATCH, $respJson);

            echo "[{$n}/{$total}] {$djbh} → 数量不符（本地应有 {$expected}，平台申报 {$actual}）\n";
            continue;
        }

        // ── 数量相等 → 已传齐，零记录（只告警不补传） ──
        $uploadedCount++;
        printf("\r[%s] %d%% (%d/%d)", str_repeat('=', (int)round(50 * $n / $total)) . str_repeat('-', 50 - (int)round(50 * $n / $total)), (int)round(100 * $n / $total), $n, $total);
    }

    $checked = $uploadedCount + $mismatchCount + $notFoundCount + $errorCount + $unverifiableCount;
    echo "\n\n[check_quantity] 对账完成: 传齐 {$uploadedCount} / 数量不符 {$mismatchCount} / 信息不存在 {$notFoundCount} / 异常 {$errorCount} / 无法核对 {$unverifiableCount} (已查 {$checked}/{$total} 条，日期 {$date})\n";
    if ($mismatchCount > 0) {
        echo "[check_quantity] 数量不符记录已写入 upload_logs（response_status='数量不符'，含预期数/平台数/子单明细），可在 Web 失败记录页查看\n";
    }
    if ($limited) {
        echo "[check_quantity] 注意: 本轮因平台限流未查完，下次运行会自动重查剩余单据\n";
    }

} catch (\Exception $e) {
    echo "[check_quantity] 错误: " . $e->getMessage() . "\n";
    exit(1);
}
