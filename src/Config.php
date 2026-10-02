<?php

namespace App;

class Config
{
    private static array $config = [];

    public static function load(string $envPath = null): void
    {
        $envPath = $envPath ?? __DIR__ . '/../config/.env';
        if (!file_exists($envPath)) {
            return;
        }
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            self::$config[$key] = $value;
            if (!defined($key)) {
                define($key, $value);
            }
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::$config[$key] ?? $default;
    }

    /**
     * SQL Server 连接配置（五字段），四个连接点的**唯一来源**。
     *
     * `App\TaskFetcher` / `App\UpdateStateWriter` / `scripts/fetch_bills_retail.php` /
     * `scripts/backfill_rq.php` 共用这一份：改 `.env` 键名或换实例只改这里。
     * 曾经四处各写一份，漏改一处不会报错——连到同构的另一个库是最坏的形态（连得上、看不出）。
     *
     * **只含五字段，不含 `timeout`**：超时不是连接的属性，是**连接点**的属性
     * （`UpdateStateWriter` 在 Web 请求里回写、用 5s，其余用 `SqlSrvHelper` 的默认 30s）。
     * 混进这一层，四处就要各自决定"要不要被覆盖"，`SqlSrvHelper` 的默认值也多出一份。
     *
     * 方法内确保已 load：调用点忘了先 `Config::load()` 时不会静默拿到下面这些兜底默认值。
     * `load()` 幂等（同值覆盖写、`define` 有 `!defined` 保护），重复调用只多读一次十几行的文件。
     *
     * @return array{server: string, port: string, database: string, username: string, password: string}
     */
    public static function sqlServer(): array
    {
        self::load();

        return [
            'server' => self::get('DB_SERVER', '192.168.2.133'),
            'port' => self::get('DB_PORT', '1433'),
            'database' => self::get('DB_DATABASE', 'hyyy_zyscm'),
            'username' => self::get('DB_USERNAME', 'sa'),
            'password' => self::get('DB_PASSWORD', ''),
        ];
    }
}
