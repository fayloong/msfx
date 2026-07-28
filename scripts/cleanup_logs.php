<?php
/**
 * 清理超过 3 个月的 SQLite 上传日志记录
 * 用法: php scripts/cleanup_logs.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;

$cutoff = date('Y-m-d H:i:s', strtotime('-3 months'));

echo "[cleanup_logs] 清理 {$cutoff} 之前的 SQLite 日志记录...\n";

$db = Database::getInstance();
$deleted = $db->execute("DELETE FROM upload_logs WHERE created_at < ?", [$cutoff]);

echo "[cleanup_logs] 已清理 {$deleted} 条记录\n";
