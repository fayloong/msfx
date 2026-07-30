<?php
/**
 * 回填 upload_logs 的 rq（单据日期）字段
 *
 * 分三步回填：
 *   1. 通过 task_id 关联 upload_tasks
 *   2. 通过 djbh 关联 upload_tasks（task_id=0 的记录）
 *   3. 从 SQL Server 按 djbh 批量查询
 */

require_once __DIR__ . '/../vendor/autoload.php';

// CLI stub
if (!function_exists('info_log')) {
    function info_log(string $title, string $msg = '', string $level = 'INFO', array $data = []): void {
        $ts = date('Y-m-d H:i:s');
        $ctx = $data ? ' ' . json_encode($data, JSON_UNESCAPED_UNICODE) : '';
        fwrite(STDERR, "[{$ts}] [{$level}] {$title}{$msg}{$ctx}\n");
    }
}

use App\Config;
use App\Database;

Config::load();

$db = Database::getInstance();

// ====== Step 1: 通过 task_id 匹配 ======
echo "=== Step 1: 通过 task_id 回填 ===\n";
$rows = $db->query(
    "SELECT l.id, t.rq FROM upload_logs l JOIN upload_tasks t ON l.task_id = t.id
     WHERE (l.rq IS NULL OR l.rq = '') AND l.task_id > 0"
);
$count1 = 0;
foreach ($rows as $row) {
    $db->execute("UPDATE upload_logs SET rq = ? WHERE id = ?", [$row['rq'], $row['id']]);
    $count1++;
}
echo "  回填 {$count1} 条\n";

// ====== Step 2: 通过 djbh 匹配（task_id=0 或无） ======
echo "=== Step 2: 通过 djbh 回填 ===\n";
$rows = $db->query(
    "SELECT l.id, t.rq FROM upload_logs l JOIN upload_tasks t ON l.djbh = t.djbh
     WHERE (l.rq IS NULL OR l.rq = '') AND (l.task_id = 0 OR l.task_id IS NULL)"
);
$count2 = 0;
foreach ($rows as $row) {
    $db->execute("UPDATE upload_logs SET rq = ? WHERE id = ?", [$row['rq'], $row['id']]);
    $count2++;
}
echo "  回填 {$count2} 条\n";

// ====== Step 3: 从 SQL Server 批量查询 ======
$remainingCount = $db->queryOne("SELECT COUNT(*) as cnt FROM upload_logs WHERE rq IS NULL OR rq = ''")['cnt'] ?? 0;
$remaining = $db->query("SELECT DISTINCT djbh FROM upload_logs WHERE rq IS NULL OR rq = ''");
$remainingDjbhs = array_column($remaining, 'djbh');

if (empty($remainingDjbhs)) {
    echo "\n全部回填完成，无需查询 SQL Server。\n";
} else {
    echo "\n=== Step 3: SQL Server 查询（剩余 {$remainingCount} 条记录，" . count($remainingDjbhs) . " 个不同单号） ===\n";

    $ss = new \SqlSrvHelper([
        'server' => Config::get('DB_SERVER', '192.168.2.133'),
        'port' => Config::get('DB_PORT', '1433'),
        'database' => Config::get('DB_DATABASE', 'hyyy_zyscm'),
        'username' => Config::get('DB_USERNAME', 'sa'),
        'password' => Config::get('DB_PASSWORD', ''),
    ]);

    $batchSize = 200;
    $batches = array_chunk($remainingDjbhs, $batchSize);
    $totalSsMatches = 0;

    foreach ($batches as $bi => $batch) {
        $n = $bi + 1;
        $placeholders = implode(',', array_fill(0, count($batch), '?'));
        $params = array_merge($batch, $batch);

        $sql = "
            SELECT DISTINCT a.djbh, a.rq
            FROM skwms_new.dbo.v_pf_phlrhz a
            WHERE a.djbh IN ({$placeholders})
            UNION
            SELECT DISTINCT a.djbh, a.rq
            FROM skwms_new.dbo.v_jzorder_hz a
            WHERE a.djbh IN ({$placeholders})
        ";

        $ssRows = $ss->query($sql, $params);
        $totalSsMatches += count($ssRows);
        echo "  批次 {$n}/" . count($batches) . ": 在 SQL Server 匹配到 " . count($ssRows) . " 个单号\n";

        foreach ($ssRows as $ssRow) {
            $db->execute(
                "UPDATE upload_logs SET rq = ? WHERE djbh = ? AND (rq IS NULL OR rq = '')",
                [$ssRow['rq'], $ssRow['djbh']]
            );
        }
    }

    echo "  SQL Server 共匹配到 {$totalSsMatches} 个不同单号\n";

    $stillEmptyAfterSs = $db->queryOne("SELECT COUNT(*) as cnt FROM upload_logs WHERE rq IS NULL OR rq = ''")['cnt'] ?? 0;
    echo "  从 SQL Server 回填的记录数: " . ($remainingCount - $stillEmptyAfterSs) . "\n";
}

// ====== 最终统计 ======
$stillEmpty = $db->queryOne("SELECT COUNT(*) as cnt FROM upload_logs WHERE rq IS NULL OR rq = ''")['cnt'] ?? 0;
$total = $db->queryOne("SELECT COUNT(*) as cnt FROM upload_logs")['cnt'] ?? 0;

echo "\n=== 完成 ===\n";
echo "总记录数: {$total}\n";
echo "已回填: " . ($total - $stillEmpty) . "\n";
echo "仍为空: {$stillEmpty}\n";
