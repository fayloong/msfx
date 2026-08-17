<?php

namespace App;

class Database
{
    private static ?Database $instance = null;
    private \SQLite3 $db;

    private function __construct()
    {
        $dbPath = __DIR__ . '/../data/msfx.db';
        $this->db = new \SQLite3($dbPath);
        $this->db->enableExceptions(true);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA foreign_keys=ON');
        // 撞写锁时等待最多 30s 而非立即报错（cron 脚本与 fetch_bills 每半小时写库时间窗可能重叠）
        $this->db->busyTimeout(30000);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $value) {
            $type = is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT;
            $stmt->bindValue($i + 1, $value, $type);
        }
        $result = $stmt->execute();
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        $result->finalize();
        return $rows;
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $rows = $this->query($sql, $params);
        return $rows[0] ?? null;
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $value) {
            $type = is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT;
            $stmt->bindValue($i + 1, $value, $type);
        }
        $stmt->execute();
        $changes = $this->db->changes();
        $stmt->close();
        return $changes;
    }

    public function lastInsertId(): int
    {
        return $this->db->lastInsertRowID();
    }

    public function escape(string $value): string
    {
        return $this->db->escapeString($value);
    }

    public function getDb(): \SQLite3
    {
        return $this->db;
    }
}
