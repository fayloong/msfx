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

    /**
     * 把一个写批次包进一次事务（`BEGIN IMMEDIATE` … `COMMIT`）。
     *
     * 为什么需要它：SQLite 把每条语句当作一个隐式事务，**每条都要一次 fsync**——本机（WAL、
     * 默认 `synchronous=FULL`）实测单条 INSERT 21–28 ms。日常那几十条无所谓，零售快照（票 05）
     * 一次要写 47,813 条记录，按"一条一提交"得等 25–30 分钟磁盘；攒成一批一次提交后实测
     * 0.054 ms/条（68,000 条 / 每批 500 = 3.7 秒）。
     *
     * **批次别开太大**：事务期间持有写锁，而 cron 那边的采集正靠 `busyTimeout(30s)` 等锁。
     * 一批 500 条约 50 ms，撞上也只等一会儿；整轮一个大事务则会让采集卡满 30 秒后失败。
     *
     * `IMMEDIATE` 是刻意选的：一开始就拿写锁，不做"先读后升级"，避免与并发的写入者死锁。
     * `$fn` 抛异常即回滚并**原样上抛**——调用方非零退出，源库那一趟白跑，但半批数据不会留在库里。
     *
     * @param callable():void $fn 批内的全部写操作（含 `LogWriter::write()`——它走同一个单例连接，
     *                            因此会被包进来）
     * @throws \Throwable `$fn` 抛出的异常（回滚后原样上抛）
     */
    public function transaction(callable $fn): void
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $fn();
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
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
