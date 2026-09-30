<?php
/**
 * 清理 SQLite 里的历史数据。三条判据各不相同，别互相照抄：
 *   1. upload_logs —— 按 created_at，超过 3 个月（与 JSONL 双写策略对应）
 *   2. upload_tasks 的已处理任务 —— 按 updated_at，超过 3 个月（任务表是待处理队列）
 *   3. upload_tasks 的门店（零售）任务 —— 按 **rq 单据日期**，超过 2 年
 *      （平台硬性规定不接受 2 年前的单据，见 App\RetailRetention）
 * 用法: php scripts/cleanup_logs.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\RetailRetention;

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

// 3. 门店（零售）的超期单据——1、2 两条判的是"记录存了多久"，这条判的是**单据本身的日期**：
//    平台不接受 2 年前的单据（App\RetailRetention 有平台原话），一张昨天才采进来的 2023 年
//    单据昨天就该清掉。这也是"只保留最近 2 年"必须落在这里、不能只堵采集入口的原因——
//    今天合法的单据两年后就超期了，出口得有清理。
//    量级：滚动窗口每天淘汰一天的量（几十条），不分批。
$retailCutoff = RetailRetention::cutoffDate();
$retailDeleted = $db->execute(
    "DELETE FROM upload_tasks WHERE source = 'retail' AND rq < ?",
    [$retailCutoff]
);
echo "[cleanup_logs] 已清理 {$retailDeleted} 条超期门店单据（单据日期早于 {$retailCutoff}）\n";
