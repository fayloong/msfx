<?php
/**
 * 批量复查上传日志中的失败记录（check_bill_status 拆分出的来源 2）
 * 用法: php scripts/check_failed_logs.php
 *
 * 查询 upload_logs 中未上传成功的记录（失败/信息不存在等），逐个调用码上放心查询 API：
 *   - 平台存在 → 记录翻转为"上传成功"，同步关联 upload_tasks（task_id>0 标已处理），写 JSONL
 *   - 信息不存在 → 保持状态，仅更新 updated_at / last_checked_at
 *   - API 异常 → 跳过不修改
 *
 * 每天 20:40 跑一次（避开 check_bill_status 8-20 点的调用窗口，防平台限流）。
 * flock 防并发：锁被占用时直接退出。
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

// 新鲜度门卫：距上次成功查询超过该分钟数的记录才重新调 API
// （每天一次运行时形同虚设，但保留常量避免与 check_bill_status 行为差异）
const CHECK_INTERVAL_MINUTES = 30;

// flock 防并发：锁被占用说明已有实例在跑，直接退出
$lockFile = __DIR__ . '/../logs/check_failed_logs.lock';
$lockFp = fopen($lockFile, 'w+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    if ($lockFp) {
        fclose($lockFp);
    }
    echo "[check_failed_logs] 已有实例在运行（锁文件 {$lockFile} 被占用），本次退出\n";
    exit(0);
}

echo "[check_failed_logs] 开始复查失败记录...\n";

try {
    $db = Database::getInstance();
    $threshold = date('Y-m-d H:i:s', time() - CHECK_INTERVAL_MINUTES * 60);

    // ── 拉取未上传成功的记录（失败/信息不存在等，且上次查询已过期） ──
    $logs = $db->query(
        "SELECT id AS log_id, task_id, djbh, ent_name, trace_codes, rq FROM upload_logs WHERE (response_status IS NULL OR response_status NOT IN ('上传成功', '单据重复')) AND (last_checked_at IS NULL OR last_checked_at <= ?)",
        [$threshold]
    );

    $logCount = count($logs);
    echo "[check_failed_logs] 拉取到 {$logCount} 条失败记录\n";

    if ($logCount === 0) {
        echo "[check_failed_logs] 没有需要复查的记录\n";
        exit(0);
    }

    // ── 合并去重（按 djbh，首次遇到胜出；同单多条失败记录只查一次 API） ──
    $merged = [];
    foreach ($logs as $log) {
        $djbh = $log['djbh'];
        if (!isset($merged[$djbh])) {
            $merged[$djbh] = [
                'djbh' => $djbh,
                'ent_name' => $log['ent_name'] ?? '',
                'trace_codes' => $log['trace_codes'] ?? '',
                'rq' => $log['rq'] ?? '',
                'log_id' => $log['log_id'],
                'task_id' => $log['task_id'] ?? 0,
            ];
        }
    }
    $logs = null; // 释放内存

    $records = array_values($merged);
    $total = count($records);
    echo "[check_failed_logs] 合并去重后共 {$total} 条单据\n";

    $apiClient = new ApiClient();
    $logDir = __DIR__ . '/../logs';
    $now = date('Y-m-d H:i:s');

    $foundCount = 0;
    $notFoundCount = 0;
    $errorCount = 0;
    $skipCount = 0;

    // ── 逐条查询 ──
    foreach ($records as $i => $rec) {
        $djbh = $rec['djbh'];
        $n = $i + 1;

        // 去重：已确认在平台存在的跳过 API 查询（同单已有上传成功/单据重复记录即视为已上传）
        $already = $db->queryOne(
            "SELECT id FROM upload_logs WHERE djbh = ? AND response_status IN ('上传成功', '单据重复') LIMIT 1",
            [$djbh]
        );
        if ($already) {
            $skipCount++;
            // 平台已有该单，本条失败记录保留（历史记录不动），直接跳过
            echo "[{$n}/{$total}] {$djbh} → 已确认在平台，跳过\n";
            continue;
        }

        try {
            $result = $apiClient->searchBillDetail($djbh);
            $responseJson = json_encode($result['response'], JSON_UNESCAPED_UNICODE);

            if ($result['found']) {
                // ── 上传成功：记录翻转为成功 ──
                $foundCount++;

                $db->execute(
                    "UPDATE upload_logs SET request_status = '请求成功', response_status = '上传成功', updated_at = ?, last_checked_at = ? WHERE id = ?",
                    [$now, $now, $rec['log_id']]
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
                    'source' => 'batch_check',
                ]);

                echo "[{$n}/{$total}] {$djbh} → 已上传\n";
            } else {
                // ── 信息不存在：保持状态，仅 touch ──
                $notFoundCount++;

                $db->execute(
                    "UPDATE upload_logs SET updated_at = ?, last_checked_at = ? WHERE id = ?",
                    [$now, $now, $rec['log_id']]
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

    echo "\n\n[check_failed_logs] 复查完成: 已上传 {$foundCount} / 未上传 {$notFoundCount} / 跳过 {$skipCount} / 异常 {$errorCount} (共 {$total} 条)\n";

} catch (\Exception $e) {
    echo "[check_failed_logs] 错误: " . $e->getMessage() . "\n";
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
