<?php
/**
 * 定时上传任务 — cron 入口
 * 用法: php scripts/cron_upload.php [日期 Y-m-d]
 * 每天 20:00 执行，上传当天的出入库单据到码上放心平台
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
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

    // 2. 执行上传
    $uploadService = new UploadService();
    $result = $uploadService->upload($bills);

    echo "[cron_upload] 上传完成: 成功 {$result['success']} / 失败 {$result['failed']} (共 {$result['total']} 条)\n";

} catch (\Exception $e) {
    echo "[cron_upload] 错误: " . $e->getMessage() . "\n";
    exit(1);
}
