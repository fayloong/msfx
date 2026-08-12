<?php
/**
 * SQLite 直接查询/操作工具（调试用）
 *
 * 用法:
 *   php scripts/sqlite_query.php "SELECT * FROM upload_tasks ORDER BY id DESC LIMIT 10"
 *   php scripts/sqlite_query.php "UPDATE upload_tasks SET task_status='已处理' WHERE id=1"
 *   php scripts/sqlite_query.php "SELECT COUNT(*) AS cnt FROM upload_logs" "SELECT COUNT(*) AS cnt FROM upload_tasks"
 *
 * 多个参数可同时传入，依次执行；查询语句输出对齐表格，写语句输出影响行数。
 * 不带参数时列出所有表及行数。
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;

// CLI stub
if (!function_exists('info_log')) {
    function info_log(string $title, string $msg = '', string $level = 'INFO', array $data = []): void {
        $ts = date('Y-m-d H:i:s');
        $ctx = $data ? ' ' . json_encode($data, JSON_UNESCAPED_UNICODE) : '';
        fwrite(STDERR, "[{$ts}] [{$level}] {$title}{$msg}{$ctx}\n");
    }
}

/** 字符串显示宽度（全角字符算 2 格），无 mbstring 时退化为字节数 */
function sw(string $s): int
{
    return function_exists('mb_strwidth') ? mb_strwidth($s) : strlen($s);
}

/** 按显示宽度截断，超出加省略号，避免超长字段（如 trace_codes）撑爆终端 */
function truncate(string $s, int $maxWidth): string
{
    if (sw($s) <= $maxWidth) {
        return $s;
    }
    $result = '';
    $w = 0;
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        if ($w + sw($ch) > $maxWidth - 1) {
            break;
        }
        $result .= $ch;
        $w += sw($ch);
    }
    return $result . '…';
}

/** 输出对齐表格 */
function renderTable(array $rows, int $maxColWidth = 60): void
{
    if (!$rows) {
        echo "  (无结果)\n";
        return;
    }
    $headers = array_keys($rows[0]);
    $widths = [];
    foreach ($headers as $h) {
        $widths[$h] = min(sw($h), $maxColWidth);
    }
    foreach ($rows as $row) {
        foreach ($headers as $h) {
            $w = min(sw((string)$row[$h]), $maxColWidth);
            if ($w > $widths[$h]) {
                $widths[$h] = $w;
            }
        }
    }

    $fmt = function (array $row) use ($headers, $widths): array {
        $cells = [];
        foreach ($headers as $h) {
            $val = $row[$h] === null ? 'NULL' : (string)$row[$h];
            $cells[] = str_pad(truncate($val, $widths[$h]), $widths[$h]);
        }
        return $cells;
    };

    echo '  ' . implode(' | ', $fmt(array_combine($headers, $headers))) . "\n";
    echo '  ' . implode('-+-', array_map(fn($w) => str_repeat('-', $w), $widths)) . "\n";
    foreach ($rows as $row) {
        echo '  ' . implode(' | ', $fmt($row)) . "\n";
    }
}

$db = Database::getInstance();

// 不带参数：列出所有表及行数
if ($argc < 2) {
    echo "用法: php scripts/sqlite_query.php \"SQL 语句\" [\"SQL 语句\" ...]\n\n";
    echo "SQLite 中的表:\n";
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    if (!$tables) {
        echo "  (数据库为空)\n";
        exit(0);
    }
    $rows = [];
    foreach ($tables as $t) {
        $cnt = $db->queryOne("SELECT COUNT(*) AS cnt FROM \"{$t['name']}\"")['cnt'] ?? 0;
        $rows[] = ['表名' => $t['name'], '行数' => $cnt];
    }
    renderTable($rows);
    exit(0);
}

// 判断是否查询类语句（其余按写语句处理）
function isQuery(string $sql): bool
{
    $head = strtoupper(ltrim($sql));
    foreach (['SELECT', 'PRAGMA', 'EXPLAIN', 'WITH'] as $kw) {
        if ($head === $kw || str_starts_with($head, $kw . ' ')) {
            return true;
        }
    }
    return false;
}

$failed = false;
foreach (array_slice($argv, 1) as $sql) {
    echo "SQL: {$sql}\n";
    try {
        if (isQuery($sql)) {
            $rows = $db->query($sql);
            $total = count($rows);
            $limit = 2000;
            if ($total > $limit) {
                $rows = array_slice($rows, 0, $limit);
            }
            renderTable($rows);
            echo "  共 {$total} 行" . ($total > $limit ? "（仅显示前 {$limit} 行）" : '') . "\n";
        } else {
            $changes = $db->execute($sql);
            $lastId = $db->lastInsertId();
            $msg = "  影响 {$changes} 行";
            if ($lastId > 0) {
                $msg .= "，last_insert_id = {$lastId}";
            }
            echo $msg . "\n";
        }
    } catch (Exception $e) {
        echo "  错误: " . $e->getMessage() . "\n";
        $failed = true;
    }
    echo "\n";
}

exit($failed ? 1 : 0);
