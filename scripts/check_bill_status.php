<?php
/**
 * 批量查询单据上传状态
 * 用法: php scripts/check_bill_status.php [日期 Y-m-d]
 *
 * 从两个来源合并单据列表，逐个调用码上放心查询 API：
 *   1. upload_tasks 中 task_status='等待上传' 的记录
 *   2. upload_logs 中 success=0 的记录
 *
 * 合并后按 djbh 去重，根据查询结果和来源执行不同处理策略。
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
Config::load();

$date = $argv[1] ?? date('Y-m-d');

echo "[check_bill_status] 开始查询，日期: {$date}\n";

try {
    $db = Database::getInstance();
    $allRecords = [];

    // ── 来源 1: upload_tasks（等待上传） ──
    echo "[check_bill_status] 正在从 upload_tasks 拉取等待上传的记录...\n";
    $tasks = $db->query(
        "SELECT id AS task_id, djbh, ent_name, trace_codes, rq FROM upload_tasks WHERE task_status = '等待上传'"
    );
    if (!empty($tasks)) {
        foreach ($tasks as $task) {
            $allRecords[] = [
                'djbh' => $task['djbh'],
                'ent_name' => $task['ent_name'] ?? '',
                'trace_codes' => $task['trace_codes'] ?? '',
                'rq' => $task['rq'] ?? '',
                'source' => 'upload_tasks',
                'task_id' => $task['task_id'],
            ];
        }
    }
    $taskCount = count($tasks);
    echo "[check_bill_status] upload_tasks 拉取到 {$taskCount} 条记录\n";

    // ── 来源 2: upload_logs（失败记录） ──
    echo "[check_bill_status] 正在从 upload_logs 拉取失败记录...\n";
    $logs = $db->query(
        "SELECT id AS log_id, task_id, djbh, ent_name, trace_codes, rq FROM upload_logs WHERE (response_status IS NULL OR response_status != '上传成功')"
    );
    if (!empty($logs)) {
        foreach ($logs as $log) {
            $allRecords[] = [
                'djbh' => $log['djbh'],
                'ent_name' => $log['ent_name'] ?? '',
                'trace_codes' => $log['trace_codes'] ?? '',
                'rq' => $log['rq'] ?? '',
                'source' => 'upload_logs',
                'log_id' => $log['log_id'],
                'task_id' => $log['task_id'] ?? 0,
            ];
        }
    }
    $logCount = count($logs);
    echo "[check_bill_status] upload_logs 拉取到 {$logCount} 条记录\n";

    // ── 合并去重（按 djbh，首次遇到胜出） ──
    $merged = [];
    foreach ($allRecords as $rec) {
        $djbh = $rec['djbh'];
        if (!isset($merged[$djbh])) {
            $merged[$djbh] = $rec;
        }
    }
    $allRecords = null; // 释放内存

    $totalAfterMerge = count($merged);
    echo "[check_bill_status] 合并去重后共 {$totalAfterMerge} 条单据（upload_tasks: {$taskCount}, upload_logs: {$logCount}）\n";

    if (empty($merged)) {
        echo "[check_bill_status] 没有需要查询的单据\n";
        exit(0);
    }

    $apiClient = new ApiClient();
    $logWriter = new LogWriter();
    $logDir = __DIR__ . '/../logs';
    $now = date('Y-m-d H:i:s');

    $foundCount = 0;
    $notFoundCount = 0;
    $errorCount = 0;
    $skipCount = 0;

    $records = array_values($merged);
    $total = count($records);

    // ── 逐条查询 ──
    foreach ($records as $i => $rec) {
        $djbh = $rec['djbh'];
        $source = $rec['source'];
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
                // ── 上传成功 ──
                $foundCount++;

                switch ($source) {
                    case 'upload_tasks':
                        $db->execute(
                            "UPDATE upload_tasks SET task_status = '已处理', request_status = '请求成功', response_status = '上传成功', updated_at = ? WHERE id = ?",
                            [$now, $rec['task_id']]
                        );
                        $logWriter->write([
                            'task_id' => $rec['task_id'],
                            'djbh' => $djbh,
                            'request_status' => '请求成功',
                            'response_status' => '上传成功',
                            'response' => $responseJson,
                            'ent_name' => $rec['ent_name'],
                            'trace_codes' => $rec['trace_codes'],
                            'rq' => $rec['rq'],
                        ]);
                        break;

                    case 'upload_logs':
                        $db->execute(
                            "UPDATE upload_logs SET request_status = '请求成功', response_status = '上传成功', updated_at = ? WHERE id = ?",
                            [$now, $rec['log_id']]
                        );
                        // 同步更新关联的 upload_tasks
                        if (!empty($rec['task_id']) && $rec['task_id'] > 0) {
                            $db->execute(
                                "UPDATE upload_tasks SET task_status = '已处理', request_status = '请求成功', response_status = '上传成功', updated_at = ? WHERE id = ?",
                                [$now, $rec['task_id']]
                            );
                        }
                        // 手动写 JSONL（LogWriter 只支持 INSERT）
                        _writeJsonl($logDir, [
                            'action' => 'update',
                            'log_id' => $rec['log_id'],
                            'djbh' => $djbh,
                            'request_status' => '请求成功',
                            'response_status' => '上传成功',
                            'response' => $responseJson,
                            'ent_name' => $rec['ent_name'],
                            'trace_codes' => $rec['trace_codes'],
                            'rq' => $rec['rq'],
                            'task_id' => $rec['task_id'] ?? 0,
                        ]);
                        break;
                }

                echo "[{$n}/{$total}] {$djbh} → 已上传\n";
            } else {
                // ── 信息不存在 ──
                $notFoundCount++;

                switch ($source) {
                    case 'upload_tasks':
                        $db->execute(
                            "UPDATE upload_tasks SET updated_at = ? WHERE id = ?",
                            [$now, $rec['task_id']]
                        );
                        break;

                    case 'upload_logs':
                        $db->execute(
                            "UPDATE upload_logs SET updated_at = ? WHERE id = ?",
                            [$now, $rec['log_id']]
                        );
                        break;
                }

                echo "[{$n}/{$total}] {$djbh} → 未上传\n";
            }
        } catch (\Exception $e) {
            // ── API 异常 ──
            $errorCount++;

            // upload_tasks / upload_logs 来源: 跳过，不修改

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
