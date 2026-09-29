<?php
/**
 * 初始化/迁移 SQLite 数据库及表结构
 *
 * 幂等，可重复执行。两类操作的幂等规则不同：
 *   - 加列 / 建索引 / 重建表：按当前结构判断，重复跑无副作用
 *   - 历史行回填：**只在加列那一刻做一次**——重复回填会把零售的合法取值误标成河药
 *     （company='未识别' 是认领失败的明确标记；credential 为空表示该门店"待配凭据"）
 *
 * 多企业支持（见 .scratch/retail-chain/spec.md §7、docs/adr/0006）：
 *   upload_tasks / upload_logs 各加 company（企业中文全名：页面"所属企业"列的值与筛选键）
 *   + credential（该企业 primary 凭据键，如 main；只作审计，不参与任何键，去重键是 (company, djbh)）
 *   ent_list 加 company 并把唯一约束 ent_name → (company, ent_name)——SQLite 改不了约束，只能重建表
 */

$dbPath = __DIR__ . '/../data/msfx.db';

// 历史行回填值：迁移时刻库里的行全部属于批发主体。
// 用字面量而非读配置——本脚本要保持自包含（故障恢复时可独立运行，不依赖 config/ 是否就绪）；
// 企业改名是独立的数据迁移动作，不是本脚本的职责。
const BACKFILL_COMPANY = '河药医药（河源）有限公司';
const BACKFILL_CREDENTIAL = 'main';

/**
 * 表是否已有某列（加列的幂等判据）。
 *
 * 不用 try/catch ALTER —— PHP 8.1 的 SQLite3 默认不抛异常，ALTER 失败只是返回 false，
 * try 块里后续语句照样执行（回填会跟着一起跑，重复执行就把零售行误标了）。
 */
function hasColumn(\SQLite3 $db, string $table, string $column): bool
{
    $result = $db->query("PRAGMA table_info({$table})");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] === $column) {
            return true;
        }
    }
    return false;
}

/**
 * 表上是否已存在指定列顺序的唯一索引（重建 ent_list 的幂等判据）。
 *
 * @param array<int,string> $columns 期望的唯一键列（按顺序）
 */
function hasUniqueIndex(\SQLite3 $db, string $table, array $columns): bool
{
    $indexes = $db->query("PRAGMA index_list({$table})");
    while ($index = $indexes->fetchArray(SQLITE3_ASSOC)) {
        if (empty($index['unique'])) {
            continue;
        }
        $cols = [];
        $info = $db->query('PRAGMA index_info("' . $index['name'] . '")');
        while ($col = $info->fetchArray(SQLITE3_ASSOC)) {
            $cols[(int)$col['seqno']] = $col['name'];
        }
        ksort($cols);
        if (array_values($cols) === $columns) {
            return true;
        }
    }
    return false;
}

try {
    $db = new SQLite3($dbPath);
    $db->busyTimeout(15000);
    // 让 try/catch 真正生效：下面"兼容旧表结构"的重复 ALTER 靠抛异常被吞掉来保持幂等
    $db->enableExceptions(true);
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
        company TEXT DEFAULT '',
        credential TEXT DEFAULT '',
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
        rq TEXT DEFAULT '',
        source TEXT DEFAULT '',
        request_status TEXT DEFAULT NULL,
        response_status TEXT DEFAULT NULL,
        company TEXT DEFAULT '',
        credential TEXT DEFAULT '',
        response TEXT,
        created_at TEXT DEFAULT (datetime('now','localtime')),
        updated_at TEXT DEFAULT NULL
    )");

    // 兼容旧表结构：缺少列时自动补上
    try { $db->exec("ALTER TABLE upload_logs ADD COLUMN ent_name TEXT DEFAULT ''"); } catch (\Exception $e) {}
    try { $db->exec("ALTER TABLE upload_logs ADD COLUMN trace_codes TEXT DEFAULT ''"); } catch (\Exception $e) {}
    try { $db->exec("ALTER TABLE upload_logs ADD COLUMN rq TEXT DEFAULT ''"); } catch (\Exception $e) {}
    try { $db->exec("ALTER TABLE upload_logs ADD COLUMN request_status TEXT DEFAULT NULL"); } catch (\Exception $e) {}
    try { $db->exec("ALTER TABLE upload_logs ADD COLUMN response_status TEXT DEFAULT NULL"); } catch (\Exception $e) {}
    try { $db->exec("ALTER TABLE upload_logs ADD COLUMN updated_at TEXT DEFAULT NULL"); } catch (\Exception $e) {}
    try { $db->exec("ALTER TABLE upload_tasks ADD COLUMN task_status TEXT DEFAULT '等待上传'"); } catch (\Exception $e) {}
    try { $db->exec("ALTER TABLE upload_tasks ADD COLUMN request_status TEXT DEFAULT NULL"); } catch (\Exception $e) {}
    try { $db->exec("ALTER TABLE upload_tasks ADD COLUMN response_status TEXT DEFAULT NULL"); } catch (\Exception $e) {}
    try { $db->exec("ALTER TABLE upload_tasks ADD COLUMN bill_type TEXT DEFAULT ''"); } catch (\Exception $e) {}
    try { $db->exec("ALTER TABLE upload_logs ADD COLUMN source TEXT DEFAULT ''"); } catch (\Exception $e) {}
    try { $db->exec("ALTER TABLE upload_tasks ADD COLUMN last_checked_at TEXT DEFAULT NULL"); } catch (\Exception $e) {}
    try { $db->exec("ALTER TABLE upload_logs ADD COLUMN last_checked_at TEXT DEFAULT NULL"); } catch (\Exception $e) {}

    // ── 多企业：company / credential 两列 + 历史行回填 ──
    // 回填与加列绑定：列已存在说明上次已迁移并回填过，再跑一次会把零售行误标成河药
    foreach (['upload_tasks', 'upload_logs'] as $table) {
        if (hasColumn($db, $table, 'company')) {
            continue;
        }
        $db->exec("ALTER TABLE {$table} ADD COLUMN company TEXT DEFAULT ''");
        $db->exec("ALTER TABLE {$table} ADD COLUMN credential TEXT DEFAULT ''");
        $stmt = $db->prepare("UPDATE {$table} SET company = :company, credential = :credential");
        $stmt->bindValue(':company', BACKFILL_COMPANY, SQLITE3_TEXT);
        $stmt->bindValue(':credential', BACKFILL_CREDENTIAL, SQLITE3_TEXT);
        $stmt->execute();
        echo "{$table}: 新增 company/credential 列，历史行回填 " . $db->changes() . " 条\n";
    }

    // 往来单位缓存表
    $db->exec("CREATE TABLE IF NOT EXISTS ent_list (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        company TEXT NOT NULL DEFAULT '',
        ent_name TEXT NOT NULL,
        ent_id TEXT,
        ref_ent_id TEXT,
        created_at TEXT DEFAULT (datetime('now','localtime')),
        UNIQUE(company, ent_name)
    )");

    // 旧库的 ent_list：先补 company 列并回填，再把唯一约束从 ent_name 改成 (company, ent_name)
    if (!hasColumn($db, 'ent_list', 'company')) {
        $db->exec("ALTER TABLE ent_list ADD COLUMN company TEXT NOT NULL DEFAULT ''");
        $stmt = $db->prepare("UPDATE ent_list SET company = :company");
        $stmt->bindValue(':company', BACKFILL_COMPANY, SQLITE3_TEXT);
        $stmt->execute();
        echo "ent_list: 新增 company 列，历史行回填 " . $db->changes() . " 条\n";
    }

    if (!hasUniqueIndex($db, 'ent_list', ['company', 'ent_name'])) {
        // SQLite 无法修改约束，只能建新表 → 搬数据 → 换名（整体一个事务，其他连接要么看到旧表要么看到新表）
        $db->exec('BEGIN');
        try {
            $db->exec("CREATE TABLE ent_list_migrating (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                company TEXT NOT NULL DEFAULT '',
                ent_name TEXT NOT NULL,
                ent_id TEXT,
                ref_ent_id TEXT,
                created_at TEXT DEFAULT (datetime('now','localtime')),
                UNIQUE(company, ent_name)
            )");
            $db->exec("INSERT INTO ent_list_migrating (id, company, ent_name, ent_id, ref_ent_id, created_at)
                       SELECT id, company, ent_name, ent_id, ref_ent_id, created_at FROM ent_list");
            $db->exec("DROP TABLE ent_list");
            $db->exec("ALTER TABLE ent_list_migrating RENAME TO ent_list");
            $db->exec('COMMIT');
            echo "ent_list: 唯一约束改为 (company, ent_name)，重建表并保留全部行\n";
        } catch (\Exception $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    }

    // 索引
    $db->exec("CREATE INDEX IF NOT EXISTS idx_upload_tasks_task_status ON upload_tasks(task_status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_upload_tasks_request_status ON upload_tasks(request_status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_upload_tasks_djbh ON upload_tasks(djbh)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_upload_tasks_rq ON upload_tasks(rq)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_upload_logs_request_status ON upload_logs(request_status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_upload_logs_response_status ON upload_logs(response_status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_upload_logs_created ON upload_logs(created_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_upload_logs_djbh ON upload_logs(djbh)");
    // 复合索引：fetch_bills 去重查询（djbh IN (...) AND response_status IN (...)）走 djbh 相等查找，
    // 避免 SQLite 因统计偏差选 response_status 索引全量扫描（10 万行实测 135ms → 3ms）。
    // 加 company 条件后仍然适用：djbh 列表选择性极高，company 只在命中行上回表过滤
    // （2026-09-29 复核，EXPLAIN QUERY PLAN 实测，见 commit）；同理 upload_tasks 用 idx_upload_tasks_djbh
    $db->exec("CREATE INDEX IF NOT EXISTS idx_upload_logs_djbh_response ON upload_logs(djbh, response_status)");

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
