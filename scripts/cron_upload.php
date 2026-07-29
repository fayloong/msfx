<?php
/**
 * 定时上传任务 — cron 入口
 * 用法: php scripts/cron_upload.php [日期 Y-m-d]
 * 每天 20:00 执行，上传当天的出入库单据到码上放心平台
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
use App\UploadService;

Config::load();

$date = $argv[1] ?? date('Y-m-d');

echo "[cron_upload] 开始上传，日期: {$date}\n";

try {
    // 1. 从 SQL Server 拉取当天待上传单据
    echo "[cron_upload] 正在从 SQL Server 拉取单据...\n";
    $fetcher = new TaskFetcher();
    $bills = $fetcher->fetchBills($date);

    if (empty($bills)) {
        echo "[cron_upload] 没有需要上传的单据\n";
        exit(0);
    }

    echo "[cron_upload] 拉取到 " . count($bills) . " 条单据\n";

    // 2. 过滤已成功上传的单据，写入 upload_tasks 表（使失败记录可重传）
    $db = Database::getInstance();
    $now = date('Y-m-d H:i:s');
    $newBills = [];
    $skipCount = 0;
    foreach ($bills as $bill) {
        $already = $db->queryOne(
            "SELECT id FROM upload_tasks WHERE djbh = ? AND task_status = '已处理' AND response_status = '上传成功'",
            [$bill['djbh']]
        );
        if ($already) {
            $skipCount++;
            echo "[cron_upload] {$bill['djbh']} 已上传过，跳过\n";
            continue;
        }
        $db->execute(
            "INSERT INTO upload_tasks (rq, djbh, ent_name, trace_codes, task_status, source, created_at, updated_at) VALUES (?, ?, ?, ?, '等待上传', 'cron', ?, ?)",
            [$bill['rq'], $bill['djbh'], $bill['ent_name'], $bill['sn'], $now, $now]
        );
        $bill['task_id'] = (int)$db->lastInsertId();
        $newBills[] = $bill;
    }
    $bills = $newBills;

    if ($skipCount > 0) {
        echo "[cron_upload] 跳过 {$skipCount} 条已上传单据\n";
    }

    if (empty($bills)) {
        echo "[cron_upload] 所有单据均已上传，无需处理\n";
        exit(0);
    }

    echo "[cron_upload] 待上传 " . count($bills) . " 条单据\n";

    // 3. 执行上传
    $uploadService = new UploadService();
    $result = $uploadService->upload($bills);

    echo "[cron_upload] 上传完成: 成功 {$result['success']} / 失败 {$result['failed']} (共 {$result['total']} 条)\n";

} catch (\Exception $e) {
    echo "[cron_upload] 错误: " . $e->getMessage() . "\n";
    exit(1);
}
