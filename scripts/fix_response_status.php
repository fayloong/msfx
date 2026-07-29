<?php
/**
 * 修正 response_status 映射错误
 * 针对迁移后显示为「未确定」的记录，基于 resp/response JSON 重新解析
 *
 * 用法: php scripts/fix_response_status.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;

Config::load();

$db = Database::getInstance()->getDb();

echo "=== 修正 response_status 映射 ===\n\n";

// ── 修正 upload_tasks ──

echo "[1/2] 修正 upload_tasks.response_status...\n";
$tasks = $db->query("SELECT id, resp FROM upload_tasks WHERE request_status = '请求成功' AND (response_status IS NULL OR response_status = '未确定')");
$fixed = 0;

while ($row = $tasks->fetchArray(SQLITE3_ASSOC)) {
    $newStatus = parseResponseStatus($row['resp']);
    if ($newStatus && $newStatus !== '未确定') {
        $stmt = $db->prepare("UPDATE upload_tasks SET response_status = ? WHERE id = ?");
        $stmt->bindValue(1, $newStatus, SQLITE3_TEXT);
        $stmt->bindValue(2, $row['id'], SQLITE3_INTEGER);
        $stmt->execute();
        $fixed++;
        echo "  #{$row['id']}: 未确定 → {$newStatus}\n";
    }
}
echo "  修正 {$fixed} 条\n\n";

// ── 修正 upload_logs ──

echo "[2/2] 修正 upload_logs.response_status...\n";
$logs = $db->query("SELECT id, response FROM upload_logs WHERE request_status = '请求成功' AND (response_status IS NULL OR response_status = '未确定')");
$fixed = 0;

while ($row = $logs->fetchArray(SQLITE3_ASSOC)) {
    $newStatus = parseResponseStatus($row['response']);
    if ($newStatus && $newStatus !== '未确定') {
        $stmt = $db->prepare("UPDATE upload_logs SET response_status = ? WHERE id = ?");
        $stmt->bindValue(1, $newStatus, SQLITE3_TEXT);
        $stmt->bindValue(2, $row['id'], SQLITE3_INTEGER);
        $stmt->execute();
        $fixed++;
        echo "  #{$row['id']}: 未确定 → {$newStatus}\n";
    }
}
echo "  修正 {$fixed} 条\n\n";

echo "=== 修正完成 ===\n";

// ── 辅助函数 ──

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

    // 尝试从 result 层级获取
    $inner = $data['result'] ?? $data['response']['result'] ?? [];

    // 也尝试从 data 字段中获取（ApiClient 返回值结构: {success, data, error, is_network_error}）
    if (empty($inner) && isset($data['data'])) {
        $innerData = $data['data'];
        if (is_string($innerData)) {
            $innerData = json_decode($innerData, true);
        }
        if (is_array($innerData)) {
            $inner = $innerData['result'] ?? [];
            if (empty($inner)) {
                $inner = $innerData;
            }
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
