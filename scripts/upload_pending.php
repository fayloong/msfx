<?php
/**
 * 批量上传所有等待中的上传任务
 * 用法: php scripts/upload_pending.php
 *
 * **只处理批发主体（河药）的 task_status='等待上传' 记录**。零售单据落库时是 task_status='待补传'
 * （语义是"仅人工补传"），本脚本不取；即便状态被误改，UploadService 的守关也会拒传。
 * 用白名单（company = 批发主体）而非排除法（如 source != 'retail'）：排除法 fail-open，
 * 将来任何新增来源漏改条件，就会把门店单据按河药主体申报上去。
 *
 * 手动上传场景中，用户创建任务后立即上传，任务状态已变为已处理，不会被此脚本重复处理。
 * 建议 cron: 5 12,18 * * * 和 30 22 * * *
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
use App\Enterprise;
use App\UploadService;

Config::load();

echo "[upload_pending] 开始上传...\n";

try {
    $db = Database::getInstance();
    $wholesale = Enterprise::wholesaleSubject();

    // 1. 读取批发主体等待上传的任务
    $tasks = $db->query(
        "SELECT id, rq, djbh, ent_name, trace_codes, bill_type, source, company, credential
         FROM upload_tasks WHERE task_status = '等待上传' AND company = ?",
        [$wholesale['name']]
    );

    if (empty($tasks)) {
        echo "[upload_pending] 没有等待上传的任务\n";
        exit(0);
    }

    $total = count($tasks);
    echo "[upload_pending] 待上传共 {$total} 条\n";

    // 2. 映射为 UploadService 格式
    $bills = [];
    foreach ($tasks as $task) {
        $bills[] = [
            'type' => !empty($task['bill_type']) ? $task['bill_type'] : substr($task['djbh'], 0, 3),
            'rq' => $task['rq'],
            'djbh' => $task['djbh'],
            'ent_name' => $task['ent_name'],
            'sn' => $task['trace_codes'],
            'task_id' => (int)$task['id'],
            'source' => $task['source'] ?? 'cron',
            'company' => $task['company'],
            'credential' => $task['credential'] ?? '',
        ];
    }

    // 3. 执行上传
    $uploadService = new UploadService();
    $result = $uploadService->upload($bills);

    echo "[upload_pending] 上传完成: 成功 {$result['success']} / 失败 {$result['failed']} (共 {$result['total']} 条)\n";

} catch (\Exception $e) {
    echo "[upload_pending] 错误: " . $e->getMessage() . "\n";
    exit(1);
}
