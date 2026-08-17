<?php
/**
 * 批量查询单据上传状态（来源 1：等待上传任务）
 * 用法: php scripts/check_bill_status.php [日期 Y-m-d]
 *
 * 逐个调用码上放心查询 API 确认 upload_tasks 中 task_status='等待上传' 的记录：
 *   - 已上传 → 任务标记已处理
 *   - 信息不存在 → 保持状态，仅更新 updated_at / last_checked_at
 *   - API 异常 → 跳过不修改
 *
 * 失败记录（来源 2）的复查已拆分到 check_failed_logs.php（每天 20:40）。
 * 建议 cron: 8-20 点每 5 分钟一次。flock 防并发：锁被占用时直接退出。
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

// 新鲜度门卫：距上次成功查询超过该分钟数的单据才重新调 API，避免高频 cron 下重复请求触发限流
const CHECK_INTERVAL_MINUTES = 30;

$date = $argv[1] ?? date('Y-m-d');

// flock 防并发：锁被占用说明已有实例在跑，直接退出
$lockFile = __DIR__ . '/../logs/check_bill_status.lock';
$lockFp = fopen($lockFile, 'w+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    if ($lockFp) {
        fclose($lockFp);
    }
    echo "[check_bill_status] 已有实例在运行（锁文件 {$lockFile} 被占用），本次退出\n";
    exit(0);
}

echo "[check_bill_status] 开始查询，日期: {$date}\n";

try {
    $db = Database::getInstance();
    $threshold = date('Y-m-d H:i:s', time() - CHECK_INTERVAL_MINUTES * 60);

    // ── 来源: upload_tasks（等待上传，且上次查询已过期） ──
    echo "[check_bill_status] 正在从 upload_tasks 拉取等待上传的记录...\n";
    $tasks = $db->query(
        "SELECT id AS task_id, djbh, ent_name, trace_codes, rq FROM upload_tasks WHERE task_status = '等待上传' AND (last_checked_at IS NULL OR last_checked_at <= ?)",
        [$threshold]
    );
    $taskCount = count($tasks);
    echo "[check_bill_status] upload_tasks 拉取到 {$taskCount} 条记录\n";

    if (empty($tasks)) {
        echo "[check_bill_status] 没有需要查询的单据\n";
        exit(0);
    }

    $total = count($tasks);
    echo "[check_bill_status] 共 {$total} 条待确认单据\n";

    $apiClient = new ApiClient();
    $logWriter = new LogWriter();
    $now = date('Y-m-d H:i:s');

    $foundCount = 0;
    $notFoundCount = 0;
    $errorCount = 0;
    $skipCount = 0;

    $records = $tasks;

    // ── 逐条查询 ──
    foreach ($records as $i => $rec) {
        $djbh = $rec['djbh'];
        $n = $i + 1;

        // 去重：已确认在平台存在的跳过 API 查询（上传成功或单据重复均视为已上传）
        $already = $db->queryOne(
            "SELECT id FROM upload_logs WHERE djbh = ? AND response_status IN ('上传成功', '单据重复') LIMIT 1",
            [$djbh]
        );
        if ($already) {
            $skipCount++;
            // 平台已有该单，任务目标已达成：标记任务已处理，避免停留在"等待上传"被反复拉取/重传
            $db->execute(
                "UPDATE upload_tasks SET task_status = '已处理', updated_at = ?, last_checked_at = ? WHERE id = ?",
                [$now, $now, $rec['task_id']]
            );
            echo "[{$n}/{$total}] {$djbh} → 已确认在平台，任务标记已处理\n";
            continue;
        }

        try {
            $result = $apiClient->searchBillDetail($djbh);
            $responseJson = json_encode($result['response'], JSON_UNESCAPED_UNICODE);

            if ($result['found']) {
                // ── 上传成功：任务标记已处理 ──
                $foundCount++;

                $db->execute(
                    "UPDATE upload_tasks SET task_status = '已处理', request_status = '请求成功', response_status = '上传成功', updated_at = ?, last_checked_at = ? WHERE id = ?",
                    [$now, $now, $rec['task_id']]
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
                    'source' => 'batch_check',
                ]);

                echo "[{$n}/{$total}] {$djbh} → 已上传\n";
            } else {
                // ── 信息不存在：保持状态，仅 touch ──
                $notFoundCount++;

                $db->execute(
                    "UPDATE upload_tasks SET updated_at = ?, last_checked_at = ? WHERE id = ?",
                    [$now, $now, $rec['task_id']]
                );

                echo "[{$n}/{$total}] {$djbh} → 未上传\n";
            }
        } catch (\Exception $e) {
            // ── API 异常：跳过，不修改 ──
            $errorCount++;

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
