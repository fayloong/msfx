<?php
/**
 * 初始化 SQLite 数据库及表结构
 */

$dbPath = __DIR__ . '/../data/msfx.db';

try {
    $db = new SQLite3($dbPath);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');

    // 上传任务表
    $db->exec("CREATE TABLE IF NOT EXISTS upload_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        rq TEXT NOT NULL,
        djbh TEXT NOT NULL,
        ent_name TEXT NOT NULL,
        trace_codes TEXT,
        task_status TEXT DEFAULT '等待上传',
        source TEXT DEFAULT 'cron',
        request_status TEXT DEFAULT NULL,
        response_status TEXT DEFAULT NULL,
        resp TEXT,
        created_at TEXT DEFAULT (datetime('now','localtime')),
        updated_at TEXT DEFAULT (datetime('now','localtime'))
    )");

    // 上传日志表
    $db->exec("CREATE TABLE IF NOT EXISTS upload_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER,
        djbh TEXT NOT NULL,
        ent_name TEXT DEFAULT '',
        trace_codes TEXT DEFAULT '',
        request_status TEXT DEFAULT NULL,
        response_status TEXT DEFAULT NULL,
        response TEXT,
        created_at TEXT DEFAULT (datetime('now','localtime'))
    )");

    // 兼容旧表结构：缺少列时自动补上
    try { $db->exec("ALTER TABLE upload_logs ADD COLUMN ent_name TEXT DEFAULT ''"); } catch (\Exception $e) {}
    try { $db->exec("ALTER TABLE upload_logs ADD COLUMN trace_codes TEXT DEFAULT ''"); } catch (\Exception $e) {}
    try { $db->exec("ALTER TABLE upload_logs ADD COLUMN rq TEXT DEFAULT ''"); } catch (\Exception $e) {}
    try { $db->exec("ALTER TABLE upload_logs ADD COLUMN request_status TEXT DEFAULT NULL"); } catch (\Exception $e) {}
    try { $db->exec("ALTER TABLE upload_logs ADD COLUMN response_status TEXT DEFAULT NULL"); } catch (\Exception $e) {}
    try { $db->exec("ALTER TABLE upload_tasks ADD COLUMN task_status TEXT DEFAULT '等待上传'"); } catch (\Exception $e) {}
    try { $db->exec("ALTER TABLE upload_tasks ADD COLUMN request_status TEXT DEFAULT NULL"); } catch (\Exception $e) {}
    try { $db->exec("ALTER TABLE upload_tasks ADD COLUMN response_status TEXT DEFAULT NULL"); } catch (\Exception $e) {}

    // 往来单位缓存表
    $db->exec("CREATE TABLE IF NOT EXISTS ent_list (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ent_name TEXT NOT NULL UNIQUE,
        ent_id TEXT,
        ref_ent_id TEXT,
        created_at TEXT DEFAULT (datetime('now','localtime'))
    )");

    // 索引
    $db->exec("CREATE INDEX IF NOT EXISTS idx_upload_tasks_task_status ON upload_tasks(task_status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_upload_tasks_request_status ON upload_tasks(request_status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_upload_tasks_djbh ON upload_tasks(djbh)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_upload_tasks_rq ON upload_tasks(rq)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_upload_logs_request_status ON upload_logs(request_status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_upload_logs_response_status ON upload_logs(response_status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_upload_logs_created ON upload_logs(created_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_upload_logs_djbh ON upload_logs(djbh)");

    echo "SQLite 数据库初始化完成: {$dbPath}\n";

    // 创建空日志文件
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }

} catch (Exception $e) {
    echo "初始化失败: " . $e->getMessage() . "\n";
    exit(1);
}
