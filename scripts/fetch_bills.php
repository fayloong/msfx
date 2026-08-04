<?php
/**
 * 从 SQL Server 采集当天单据写入上传任务表
 * 用法: php scripts/fetch_bills.php [日期 Y-m-d]
 *
 * 仅采集不入库，不上传。上传由 upload_pending.php 负责。
 * 建议 cron: 8-22 点每 30 分钟一次（门卫保证无变化时零开销）
 *
 * 变化检测门卫：启动时先轻量查询 SALEOUTMT/PURINMT 当天单据计数，
 * 与 data/fetch_bill_counter.json 记录的基线比较。同一日期且计数相同
 * 说明没有新单据，直接跳过采集（视图查询很重，避免空转）。
 * 前提（已与业务确认）：两表记录只增不减，且采集视图结果的变化仅来源于这两张表。
 * 基线只在采集成功（视图查询 + SQLite 写入全部完成）后更新，失败时保持旧值自动重试。
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

use App\Config;
use App\Database;
use App\TaskFetcher;

Config::load();

$date = $argv[1] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo "[fetch_bills] 日期格式无效: {$date}，需要 YYYY-MM-DD\n";
    exit(1);
}

echo "[fetch_bills] 开始采集，日期: {$date}\n";

$stateFile = __DIR__ . '/../data/fetch_bill_counter.json';

// ── 门卫：当天单据计数无变化则跳过 ──
$count = 0;
$baseline = null;
try {
    $fetcher = new TaskFetcher();
    $count = $fetcher->countBills($date);

    if (is_file($stateFile)) {
        $raw = file_get_contents($stateFile);
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($decoded) && isset($decoded['date'], $decoded['count'])
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$decoded['date'])) {
            $baseline = $decoded;
        } else {
            echo "[fetch_bills] 状态文件损坏或格式非法，视为无基线，执行采集\n";
        }
    }

    if ($baseline !== null && $baseline['date'] === $date && (int)$baseline['count'] === $count) {
        echo "[fetch_bills] 单据数量无变化（{$count}），跳过采集\n";
        exit(0);
    }
} catch (\Exception $e) {
    // 计数查询失败说明 SQL Server 不可用，采集同样无法进行，打日志后跳过本次
    echo "[fetch_bills] 单据计数查询失败，本次跳过采集: " . $e->getMessage() . "\n";
    exit(1);
}

// 采集成功（视图查询 + SQLite 写入全部完成）后更新基线
$saveState = function (int $cnt) use ($stateFile, $date): void {
    $ok = file_put_contents($stateFile, json_encode(['date' => $date, 'count' => $cnt], JSON_UNESCAPED_UNICODE), LOCK_EX);
    if ($ok === false) {
        echo "[fetch_bills] 警告: 基线写入失败 {$stateFile}\n";
    }
};

try {
    // 1. 从 SQL Server 拉取当天单据（复用门卫阶段创建的连接）
    echo "[fetch_bills] 正在从 SQL Server 拉取单据...\n";
    $bills = $fetcher->fetchBills($date);

    if (empty($bills)) {
        echo "[fetch_bills] 没有需要采集的单据\n";
        // 视图查询成功（结果为空）也算采集成功，更新基线避免后续空转
        $saveState($count);
        exit(0);
    }

    echo "[fetch_bills] 拉取到 " . count($bills) . " 条单据\n";

    // 2. 去重 + 写入 upload_tasks
    $db = Database::getInstance();
    $now = date('Y-m-d H:i:s');

    // 批量查询已存在的 djbh，构建查找集合（分块查询，规避 SQLite 999 参数上限）
    // 除 upload_tasks 中的任务外，upload_logs 已上传成功/单据重复的单据也不采集，
    // 避免任务被删除后已上传单据被重新入队
    $djbhs = array_column($bills, 'djbh');
    $existingSet = [];
    foreach (array_chunk($djbhs, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $existing = $db->query(
            "SELECT djbh FROM upload_tasks WHERE djbh IN ({$placeholders})",
            $chunk
        );
        foreach ($existing as $row) {
            $existingSet[$row['djbh']] = true;
        }
        $uploaded = $db->query(
            "SELECT djbh FROM upload_logs WHERE djbh IN ({$placeholders}) AND response_status IN ('上传成功', '单据重复')",
            $chunk
        );
        foreach ($uploaded as $row) {
            $existingSet[$row['djbh']] = true;
        }
    }

    $insertCount = 0;
    $skipCount = 0;

    foreach ($bills as $bill) {
        if (isset($existingSet[$bill['djbh']])) {
            $skipCount++;
            continue;
        }

        $db->execute(
            "INSERT INTO upload_tasks (rq, djbh, ent_name, trace_codes, bill_type, task_status, source, created_at, updated_at) VALUES (?, ?, ?, ?, ?, '等待上传', 'cron', ?, ?)",
            [$bill['rq'], $bill['djbh'], $bill['ent_name'], $bill['sn'], $bill['type'], $now, $now]
        );
        $insertCount++;
    }

    echo "[fetch_bills] 采集完成: 新增 {$insertCount} 条, 跳过 {$skipCount} 条\n";

    // 3. 采集成功（视图查询 + SQLite 写入全部完成）后更新基线
    $saveState($count);

} catch (\Exception $e) {
    // 采集失败不更新基线，下次计数仍不等，自动重试
    echo "[fetch_bills] 错误: " . $e->getMessage() . "\n";
    exit(1);
}
