<?php
/**
 * 状态字段拆分迁移脚本
 * 将 upload_tasks.status 和 upload_logs.success 迁移为
 * task_status / request_status / response_status 三个字段
 *
 * 用法: php scripts/migrate_status_fields.php
 *
 * 执行前请先备份 data/msfx.db！
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;

Config::load();

$db = Database::getInstance()->getDb(); // SQLite3 实例

echo "=== 状态字段拆分迁移 ===\n";
echo "执行前请确认已备份 data/msfx.db\n\n";

// ── 第一步：添加新列 ──

echo "[1/6] 添加新列...\n";

// upload_tasks
foreach ([
    "ALTER TABLE upload_tasks ADD COLUMN task_status TEXT DEFAULT '等待上传'",
    "ALTER TABLE upload_tasks ADD COLUMN request_status TEXT DEFAULT NULL",
    "ALTER TABLE upload_tasks ADD COLUMN response_status TEXT DEFAULT NULL",
] as $sql) {
    try { $db->exec($sql); echo "  OK: {$sql}\n"; }
    catch (\Exception $e) { echo "  SKIP (已存在): {$sql}\n"; }
}

// upload_logs
foreach ([
    "ALTER TABLE upload_logs ADD COLUMN request_status TEXT DEFAULT NULL",
    "ALTER TABLE upload_logs ADD COLUMN response_status TEXT DEFAULT NULL",
] as $sql) {
    try { $db->exec($sql); echo "  OK: {$sql}\n"; }
    catch (\Exception $e) { echo "  SKIP (已存在): {$sql}\n"; }
}

// ── 第二步：迁移 upload_tasks ──

echo "\n[2/6] 迁移 upload_tasks...\n";

$tasks = $db->query("SELECT id, status, resp FROM upload_tasks");
$taskCount = 0;
$taskStats = [];

while ($row = $tasks->fetchArray(SQLITE3_ASSOC)) {
    $oldStatus = $row['status'];
    $resp = $row['resp'];
    $taskStatus = '等待上传';
    $requestStatus = null;
    $responseStatus = null;

    // 旧 status → task_status
    if (in_array($oldStatus, ['已上传', '任务失败', '部分上传成功'])) {
        $taskStatus = '已处理';
        $requestStatus = '请求成功'; // 默认：能写入最终状态说明 API 调用完成了

        // 从 resp JSON 解析 response_status
        $responseStatus = parseResponseStatus($resp);
    }
    // 等待上传、上传中 → task_status = '等待上传', request/response 均为 NULL

    $taskStats[$oldStatus] = ($taskStats[$oldStatus] ?? 0) + 1;

    $stmt = $db->prepare("UPDATE upload_tasks SET task_status = ?, request_status = ?, response_status = ? WHERE id = ?");
    $stmt->bindValue(1, $taskStatus, SQLITE3_TEXT);
    $stmt->bindValue(2, $requestStatus, $requestStatus === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(3, $responseStatus, $responseStatus === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(4, $row['id'], SQLITE3_INTEGER);
    $stmt->execute();
    $taskCount++;
}

echo "  迁移 {$taskCount} 条记录\n";
foreach ($taskStats as $status => $cnt) {
    echo "    {$status} → {$cnt} 条\n";
}

// ── 第三步：迁移 upload_logs ──

echo "\n[3/6] 迁移 upload_logs...\n";

$logs = $db->query("SELECT id, success, response FROM upload_logs");
$logCount = 0;
$logStats = [];

while ($row = $logs->fetchArray(SQLITE3_ASSOC)) {
    $oldSuccess = (int)$row['success'];
    $response = $row['response'];
    $requestStatus = null;
    $responseStatus = null;

    if ($oldSuccess === 1) {
        // 旧 success=1 → 请求成功 + 上传成功
        $requestStatus = '请求成功';
        $responseStatus = '上传成功';
    } else {
        // 旧 success=0 → 需根据 response 内容判断
        $respObj = json_decode($response, true);
        if (is_array($respObj) && !empty($respObj['is_network_error'])) {
            $requestStatus = '请求失败';
            $responseStatus = null;
        } else {
            $requestStatus = '请求成功';
            $responseStatus = parseResponseStatus($response);
        }
    }

    $logStats[$requestStatus . '|' . ($responseStatus ?? 'NULL')] =
        ($logStats[$requestStatus . '|' . ($responseStatus ?? 'NULL')] ?? 0) + 1;

    $stmt = $db->prepare("UPDATE upload_logs SET request_status = ?, response_status = ? WHERE id = ?");
    $stmt->bindValue(1, $requestStatus, SQLITE3_TEXT);
    $stmt->bindValue(2, $responseStatus, $responseStatus === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(3, $row['id'], SQLITE3_INTEGER);
    $stmt->execute();
    $logCount++;
}

echo "  迁移 {$logCount} 条记录\n";
foreach ($logStats as $key => $cnt) {
    echo "    {$key} → {$cnt} 条\n";
}

// ── 第四步：数据校验 ──

echo "\n[4/6] 数据校验...\n";

$tasksNullCount = $db->querySingle("SELECT COUNT(*) FROM upload_tasks WHERE task_status IS NULL");
$logsNullCount = $db->querySingle("SELECT COUNT(*) FROM upload_logs WHERE request_status IS NULL AND response_status IS NULL");
echo "  upload_tasks: {$taskCount} 条, task_status 为 NULL: {$tasksNullCount}\n";
echo "  upload_logs: {$logCount} 条, 请求和响应均为 NULL: {$logsNullCount}\n";

// ── 第五步：创建新索引 ──

echo "\n[5/6] 创建新索引...\n";

$newIndexes = [
    "CREATE INDEX IF NOT EXISTS idx_upload_tasks_task_status ON upload_tasks(task_status)",
    "CREATE INDEX IF NOT EXISTS idx_upload_tasks_request_status ON upload_tasks(request_status)",
    "CREATE INDEX IF NOT EXISTS idx_upload_logs_request_status ON upload_logs(request_status)",
    "CREATE INDEX IF NOT EXISTS idx_upload_logs_response_status ON upload_logs(response_status)",
];

foreach ($newIndexes as $sql) {
    try {
        $db->exec($sql);
        echo "  OK: {$sql}\n";
    } catch (\Exception $e) {
        echo "  ERR: {$e->getMessage()}\n";
    }
}

// ── 第六步：删除旧列 ──

echo "\n[6/6] 删除旧列...\n";
echo "  WARNING: SQLite 不支持直接 DROP COLUMN（3.35 之前版本）\n";
echo "  尝试删除旧列...\n";

// 检查 SQLite 版本
$version = $db->querySingle("SELECT sqlite_version()");
echo "  SQLite 版本: {$version}\n";

$dropOps = [
    "upload_tasks" => "status",
    "upload_logs" => "success",
];

foreach ($dropOps as $table => $column) {
    try {
        $db->exec("ALTER TABLE {$table} DROP COLUMN {$column}");
        echo "  OK: {$table}.{$column} 已删除\n";
    } catch (\Exception $e) {
        echo "  SKIP: {$table}.{$column} 删除失败（需手动处理）: {$e->getMessage()}\n";
    }
}

// 删除旧索引
$oldIndexes = [
    "DROP INDEX IF EXISTS idx_upload_tasks_status",
    "DROP INDEX IF EXISTS idx_upload_logs_success",
];

foreach ($oldIndexes as $sql) {
    try {
        $db->exec($sql);
        echo "  OK: {$sql}\n";
    } catch (\Exception $e) {
        echo "  SKIP: {$sql}\n";
    }
}

echo "\n=== 迁移完成 ===\n";
echo "建议验证前端页面功能正常后，手动清理无法自动删除的旧列。\n";

// ── 辅助函数 ──

/**
 * 从 resp JSON 解析 response_status
 */
function parseResponseStatus(?string $respJson): ?string
{
    if (empty($respJson)) {
        return '未确定';
    }

    $data = json_decode($respJson, true);
    if (!is_array($data)) {
        return '未确定';
    }

    // 自定义错误：无法获取往来单位ent_id
    if (isset($data['error']) && strpos($data['error'], '无法获取往来单位ent_id') !== false) {
        return '往来单位缺失';
    }

    // 双层 JSON 解码（response 中可能嵌套了 JSON 字符串）
    $inner = $data['result'] ?? $data['response']['result'] ?? [];

    // 也尝试从 data 字段中获取（ApiClient 返回值结构: {success, data, error, is_network_error}）
    if (empty($inner) && isset($data['data'])) {
        $innerData = $data['data'];
        if (is_string($innerData)) {
            $innerData = json_decode($innerData, true);
        }
        $inner = $innerData['result'] ?? [];
        if (empty($inner)) {
            $inner = $innerData;
        }
    }

    if (empty($inner)) {
        $inner = $data;
    }

    $msgCode = $inner['msg_code'] ?? '';
    $msgInfo = $inner['msg_info'] ?? '';
    $responseSuccess = $inner['response_success'] ?? '';

    if ($msgCode === 'SUCCESS' && $responseSuccess === 'true') {
        return '上传成功';
    }
    if (strpos($msgInfo, '该单据号已存在') !== false) {
        return '单据重复';
    }
    if ($msgCode === 'FAIL_BIZ_NO_PAT_INFO') {
        return '信息不存在';
    }
    if ($msgCode === 'FAIL') {
        return '上传失败';
    }

    return '未确定';
}
