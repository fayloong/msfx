<?php
/**
 * 从 SQL Server 采集当天单据写入上传任务表
 * 用法: php scripts/fetch_bills.php [日期 Y-m-d]
 *
 * 仅采集不入库，不上传。上传由 upload_pending.php 负责。
 * 建议 cron: 0 8-22 * * *（每小时一次）
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

echo "[fetch_bills] 开始采集，日期: {$date}\n";

try {
    // 1. 从 SQL Server 拉取当天单据
    echo "[fetch_bills] 正在从 SQL Server 拉取单据...\n";
    $fetcher = new TaskFetcher();
    $bills = $fetcher->fetchBills($date);

    if (empty($bills)) {
        echo "[fetch_bills] 没有需要采集的单据\n";
        exit(0);
    }

    echo "[fetch_bills] 拉取到 " . count($bills) . " 条单据\n";

    // 2. 去重 + 写入 upload_tasks
    $db = Database::getInstance();
    $now = date('Y-m-d H:i:s');

    // 批量查询已存在的 djbh，构建查找集合
    $djbhs = array_column($bills, 'djbh');
    $placeholders = implode(',', array_fill(0, count($djbhs), '?'));
    $existing = $db->query(
        "SELECT djbh FROM upload_tasks WHERE djbh IN ({$placeholders})",
        $djbhs
    );
    $existingSet = array_flip(array_column($existing, 'djbh'));

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

} catch (\Exception $e) {
    echo "[fetch_bills] 错误: " . $e->getMessage() . "\n";
    exit(1);
}
