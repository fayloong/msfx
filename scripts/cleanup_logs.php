<?php
/**
 * 清理超过 3 个月的 SQLite 上传日志与已完成任务
 * 用法: php scripts/cleanup_logs.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;

$cutoff = date('Y-m-d H:i:s', strtotime('-3 months'));

echo "[cleanup_logs] 清理 {$cutoff} 之前的 SQLite 历史数据...\n";

$db = Database::getInstance();

// 1. 上传日志（与 JSONL 双写策略对应，SQLite 侧保留 3 个月）
$deleted = $db->execute("DELETE FROM upload_logs WHERE created_at < ?", [$cutoff]);
echo "[cleanup_logs] upload_logs 已清理 {$deleted} 条记录\n";

// 2. 已处理任务（任务表本质是待处理队列，终态任务无保留价值；历史仍可查 upload_logs/JSONL）
//    按 updated_at 判断（避免误清 rq 很旧但最近才采集/处理的任务）；
//    分批删除避免单次大事务长时间持有写锁（SQLite 3.7 不支持 DELETE LIMIT，用 id 分页）
$taskDeleted = 0;
while (true) {
    $ids = $db->query(
        "SELECT id FROM upload_tasks WHERE task_status = '已处理' AND updated_at < ? ORDER BY id LIMIT 1000",
        [$cutoff]
    );
    if (empty($ids)) {
        break;
    }
    $idList = array_column($ids, 'id');
    $placeholders = implode(',', array_fill(0, count($idList), '?'));
    $taskDeleted += $db->execute("DELETE FROM upload_tasks WHERE id IN ({$placeholders})", $idList);
}
echo "[cleanup_logs] upload_tasks 已清理 {$taskDeleted} 条记录\n";
