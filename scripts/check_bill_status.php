<?php
/**
 * 批量查询单据上传状态
 * 用法: php scripts/check_bill_status.php [日期 Y-m-d]
 *
 * 从 SQL Server 获取当天单据列表，逐个调用码上放心查询 API，
 * 判断单据是否已在平台存在。已存在的写入 upload_logs(success=1)，
 * 不存在的写入 upload_tasks(任务失败) + upload_logs(success=0) 方便后续重传。
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

$date = $argv[1] ?? date('Y-m-d');

echo "[check_bill_status] 开始查询，日期: {$date}\n";

try {
    // 1. 从 SQL Server 拉取当天单据
    echo "[check_bill_status] 正在从 SQL Server 拉取单据...\n";
    $fetcher = new TaskFetcher();
    $bills = $fetcher->fetchBills($date);

    if (empty($bills)) {
        echo "[check_bill_status] 没有需要查询的单据\n";
        exit(0);
    }

    $total = count($bills);
    echo "[check_bill_status] 拉取到 {$total} 条单据\n";

    $apiClient = new ApiClient();
    $logWriter = new LogWriter();
    $db = Database::getInstance();
    $now = date('Y-m-d H:i:s');

    $foundCount = 0;
    $notFoundCount = 0;
    $errorCount = 0;
    $skipCount = 0;

    // 2. 逐个查询
    foreach ($bills as $i => $bill) {
        $djbh = $bill['djbh'];
        $n = $i + 1;

        // 去重：已确认在平台存在的跳过 API 查询
        $already = $db->queryOne(
            "SELECT id FROM upload_logs WHERE djbh = ? AND response_status = '上传成功' LIMIT 1",
            [$djbh]
        );
        if ($already) {
            $skipCount++;
            echo "[{$n}/{$total}] {$djbh} → 已确认在平台，跳过\n";
            continue;
        }

        try {
            $result = $apiClient->searchBillDetail($djbh);
            $responseJson = json_encode($result['response'], JSON_UNESCAPED_UNICODE);

            if ($result['found']) {
                $foundCount++;
                $logWriter->write([
                    'djbh' => $djbh,
                    'request_status' => '请求成功',
                    'response_status' => '上传成功',
                    'response' => $responseJson,
                    'ent_name' => $bill['ent_name'],
                    'trace_codes' => $bill['sn'],
                    'rq' => $bill['rq'],
                ]);
                echo "[{$n}/{$total}] {$djbh} → 已上传\n";
            } else {
                $notFoundCount++;
                // 检查是否已有 batch_check 任务（避免重复创建）
                $existing = $db->queryOne(
                    "SELECT id FROM upload_tasks WHERE djbh = ? AND source = 'batch_check' AND response_status = '信息不存在'",
                    [$djbh]
                );

                if (!$existing) {
                    $db->execute(
                        "INSERT INTO upload_tasks (rq, djbh, ent_name, trace_codes, task_status, request_status, response_status, source, resp, created_at, updated_at) VALUES (?, ?, ?, ?, '等待上传', '请求成功', '信息不存在', 'batch_check', ?, ?, ?)",
                        [$bill['rq'], $djbh, $bill['ent_name'], $bill['sn'], $responseJson, $now, $now]
                    );
                    $taskId = (int)$db->lastInsertId();

                    $logWriter->write([
                        'task_id' => $taskId,
                        'djbh' => $djbh,
                        'request_status' => '请求成功',
                        'response_status' => '信息不存在',
                        'response' => $responseJson,
                        'ent_name' => $bill['ent_name'],
                        'trace_codes' => $bill['sn'],
                        'rq' => $bill['rq'],
                    ]);
                }

                echo "[{$n}/{$total}] {$djbh} → 未上传\n";
            }
        } catch (\Exception $e) {
            $errorCount++;
            $logWriter->write([
                'djbh' => $djbh,
                'request_status' => '请求失败',
                'response_status' => null,
                'response' => json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
                'ent_name' => $bill['ent_name'],
                'trace_codes' => $bill['sn'],
                'rq' => $bill['rq'],
            ]);
            echo "[{$n}/{$total}] {$djbh} → 查询异常: " . $e->getMessage() . "\n";
        }

        // 进度条
        $percent = round($n / $total * 100);
        $bar = str_repeat('=', (int)round(50 * $n / $total)) . str_repeat('-', 50 - (int)round(50 * $n / $total));
        printf("\r[%s] %d%% (%d/%d)", $bar, $percent, $n, $total);

        usleep(500000);
    }

    echo "\n\n[check_bill_status] 查询完成: 已上传 {$foundCount} / 未上传 {$notFoundCount} / 跳过 {$skipCount} / 异常 {$errorCount} (共 {$total} 条)\n";

} catch (\Exception $e) {
    echo "[check_bill_status] 错误: " . $e->getMessage() . "\n";
    exit(1);
}
