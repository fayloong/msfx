<?php
/**
 * 数量对账：检查单据追溯码是否完整上传到码上放心平台
 * 用法: php scripts/check_quantity.php [日期 Y-m-d]（默认昨天）
 *
 * 定位: 外部系统负责上传，本项目只检查上传情况（不补传）。
 * 以 SQL Server 为"应有码"基线，逐单查询平台原始单号 + 拆分子单（_1/_2/...）
 * 的申报数量（sumBillDetailCount），与本地应有码数对比。
 * 数量不符时写 upload_logs（response_status='数量不符'）＋ JSONL，Web 失败记录页可见。
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
const RESPONSE_STATUS_MISMATCH = '数量不符';
const PLATFORM_LIMIT_ERROR = 'App Call Limited';

$date = $argv[1] ?? date('Y-m-d', strtotime('-1 day'));

echo "[check_quantity] 开始数量对账，日期: {$date}\n";

try {
    // ── 基线: 从 SQL Server 拉取单据及应有码数（复用 TaskFetcher 查询，不写库） ──
    $fetcher = new TaskFetcher();
    $bills = $fetcher->fetchBills($date);
    if (empty($bills)) {
        echo "[check_quantity] {$date} 没有单据，退出\n";
        exit(0);
    }

    $apiClient = new ApiClient();
    $logWriter = new LogWriter();
    $now = date('Y-m-d H:i:s');

    $total = count($bills);
    $okCount = 0;
    $mismatchCount = 0;
    $errorCount = 0;
    $limited = false;

    foreach ($bills as $i => $bill) {
        $djbh = $bill['djbh'];
        $expected = count(array_filter(explode(',', $bill['sn'])));
        $n = $i + 1;

        // ── 查询原始单号 + 拆分子单，汇总平台申报数量 ──
        $subBills = [];
        $actual = 0;
        $queryError = '';

        for ($suffix = 1; $suffix <= MAX_SUB_BILLS; $suffix++) {
            $billCode = $suffix === 1 ? $djbh : $djbh . '_' . ($suffix - 1);
            $result = $apiClient->searchBillDetail($billCode);
            usleep(API_INTERVAL_US); // 每次调用后限速（含查不到的调用）

            // 平台限流（App Call Limited）：本轮熔断，剩余单据下次运行自动重查（数量对账幂等）
            if ($result['error'] !== '' && str_contains($result['error'], PLATFORM_LIMIT_ERROR)) {
                $limited = true;
                echo "[check_quantity] 平台限流（{$result['error']}），剩余 " . ($total - $n + 1) . " 单本轮跳过，下次运行自动重查\n";
                break 2;
            }

            // API 异常（网络/业务错误）：跳过整单，不误报数量不符
            if ($result['error'] !== '') {
                $queryError = $result['error'];
                break;
            }

            // 查不到：原始单号查不到继续查拆分子单（外部系统拆分上传场景，修正 check_bill_status 盲区）；
            // 拆分子单查不到则拆分序列结束
            if (!$result['found']) {
                if ($suffix === 1) {
                    continue;
                }
                break;
            }

            $count = ApiClient::sumBillDetailCount($result['response']);
            $subBills[] = ['djbh' => $billCode, 'count' => $count];
            $actual += $count;

            // 原始单号已传齐：无需再查拆分子单（正常整单场景，省 API 调用）
            if ($suffix === 1 && $actual === $expected) {
                break;
            }
        }

        if ($queryError !== '') {
            $errorCount++;
            echo "[{$n}/{$total}] {$djbh} → 查询异常（{$queryError}），跳过\n";
            continue;
        }

        // ── 判定: 平台申报总数 vs 本地应有码数 ──
        if ($actual === $expected) {
            $okCount++;
            printf("\r[%s] %d%% (%d/%d)", str_repeat('=', (int)round(50 * $n / $total)) . str_repeat('-', 50 - (int)round(50 * $n / $total)), (int)round(100 * $n / $total), $n, $total);
            continue;
        }

        // 数量不符: 写 upload_logs（response_status='数量不符'）＋ JSONL（LogWriter 双写）
        // 幂等: 同单同日期已有数量不符记录则 UPDATE（限流熔断后下次运行重查不会产生重复记录）
        $mismatchCount++;
        $respJson = json_encode([
            'djbh' => $djbh,
            'rq' => $bill['rq'] ?? '',
            'expected' => $expected,
            'actual' => $actual,
            'sub_bills' => $subBills,
        ], JSON_UNESCAPED_UNICODE);

        $db = Database::getInstance();
        $existing = $db->queryOne(
            "SELECT id FROM upload_logs WHERE djbh = ? AND rq = ? AND response_status = ? AND source = ?",
            [$djbh, $bill['rq'] ?? '', RESPONSE_STATUS_MISMATCH, SOURCE_QUANTITY_CHECK]
        );
        if ($existing) {
            $db->execute(
                "UPDATE upload_logs SET response = ?, updated_at = ?, last_checked_at = ? WHERE id = ?",
                [$respJson, $now, $now, $existing['id']]
            );
            _writeJsonl(__DIR__ . '/../logs', [
                'action' => 'update',
                'log_id' => $existing['id'],
                'djbh' => $djbh,
                'request_status' => '请求成功',
                'response_status' => RESPONSE_STATUS_MISMATCH,
                'response' => $respJson,
                'ent_name' => $bill['ent_name'] ?? '',
                'rq' => $bill['rq'] ?? '',
                'task_id' => 0,
                'source' => SOURCE_QUANTITY_CHECK,
            ]);
        } else {
            $logWriter->write([
                'task_id' => 0,
                'djbh' => $djbh,
                'ent_name' => $bill['ent_name'] ?? '',
                'rq' => $bill['rq'] ?? '',
                'source' => SOURCE_QUANTITY_CHECK,
                'request_status' => '请求成功',
                'response_status' => RESPONSE_STATUS_MISMATCH,
                'response' => $respJson,
            ]);
        }

        $detail = implode('; ', array_map(fn($s) => "{$s['djbh']}={$s['count']}", $subBills)) ?: '平台无记录';
        echo "[{$n}/{$total}] {$djbh} → 数量不符: 应有 {$expected}，平台 {$actual}（{$detail}）\n";
    }

    $checked = $okCount + $mismatchCount + $errorCount;
    echo "\n\n[check_quantity] 对账完成: 相符 {$okCount} / 数量不符 {$mismatchCount} / 异常 {$errorCount} (已查 {$checked}/{$total} 条，日期 {$date})\n";
    if ($mismatchCount > 0) {
        echo "[check_quantity] 数量不符记录已写入 upload_logs（response_status='数量不符'），可在 Web 失败记录页查看\n";
    }
    if ($limited) {
        echo "[check_quantity] 注意: 本轮因平台限流未查完，下次运行会自动重查剩余单据\n";
    }

} catch (\Exception $e) {
    echo "[check_quantity] 错误: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * 手动写 JSONL 行（用于 UPDATE 场景，LogWriter 只支持 INSERT）
 */
function _writeJsonl(string $logDir, array $record): void
{
    $line = [
        'timestamp' => date('Y-m-d H:i:s'),
    ] + $record;
    $jsonlFile = $logDir . '/api_' . date('Y-m-d') . '.jsonl';
    $content = json_encode($line, JSON_UNESCAPED_UNICODE) . "\n";
    file_put_contents($jsonlFile, $content, FILE_APPEND | LOCK_EX);
}
