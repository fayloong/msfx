<?php


class SqlSrvHelper
{
    private $config;
    private $connection;
    private $lastError;

    /**
     * 构造函数，初始化配置并建立连接。
     *
     * @param array<string,mixed> $config 连接配置：server, port, database, username, password, charset, timeout, options
     * @throws Exception 连接失败时抛出异常
     */
    public function __construct($config)
    {
        $this->config = array_merge([
            'server' => 'localhost',
            'port' => '1433',
            'database' => '',
            'username' => '',
            'password' => '',
            'charset' => 'UTF-8',
            'timeout' => 30,
            'options' => []
        ], $config);

        $this->connect();
    }

    /**
     * 建立 SQL Server 连接。
     *
     * @return void
     * @throws Exception 连接失败时抛出异常
     */
    private function connect()
    {
        $serverName = $this->config['server'];
        if (!empty($this->config['port'])) {
            $serverName .= ',' . $this->config['port'];
        }

        $connectionOptions = [
            'Database' => $this->config['database'],
            'UID' => $this->config['username'],
            'PWD' => $this->config['password'],
            'CharacterSet' => $this->config['charset'],
            'LoginTimeout' => $this->config['timeout'],
            'ConnectionPooling' => 1,
            'Encrypt' => 0,
            'TrustServerCertificate' => 1
        ];

        $connectionOptions = array_merge($connectionOptions, $this->config['options']);

        $this->connection = sqlsrv_connect($serverName, $connectionOptions);

        if ($this->connection === false) {
            $this->lastError = sqlsrv_errors();
            $errMsg = $this->getErrorMessage();
            info_log('DB连接', '连接失败', 'ERROR', [
                'server' => $this->config['server'],
                'database' => $this->config['database'],
                'error' => $errMsg
            ]);
            throw new Exception('SQL Server连接失败: ' . $errMsg);
        }

        info_log('DB连接', '连接成功', 'INFO', [
            'server' => $this->config['server'],
            'database' => $this->config['database']
        ]);
    }

    /**
     * 执行查询并返回所有结果行。
     *
     * @param string $sql SQL 查询语句
     * @param array<int,mixed> $params 绑定参数数组
     * @return array<int,array<string,mixed>> 查询结果数组
     */
    public function query($sql, $params = [])
    {
        $this->clearError();

        $stmt = $this->executeQuery($sql, $params);
        if ($stmt === false) {
            return [];
        }

        $results = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $results[] = $this->convertEncoding($row);
        }

        sqlsrv_free_stmt($stmt);
        return $results;
    }

    /**
     * 执行查询并返回第一行结果。
     *
     * @param string $sql SQL 查询语句
     * @param array<int,mixed> $params 绑定参数数组
     * @return array<string,mixed>|false 第一行结果或 false
     */
    public function queryOne($sql, $params = [])
    {
        $this->clearError();

        $stmt = $this->executeQuery($sql, $params);
        if ($stmt === false) {
            return false;
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return $row ? $this->convertEncoding($row) : false;
    }

    /**
     * 执行非查询语句（INSERT/UPDATE/DELETE）。
     *
     * @param string $sql SQL 语句
     * @param array<int,mixed> $params 绑定参数数组
     * @return int|false 受影响行数或 false
     */
    public function execute($sql, $params = [])
    {
        $this->clearError();

        $start = microtime(true);
        $stmt = sqlsrv_query($this->connection, $sql, $params);
        $elapsed = (microtime(true) - $start) * 1000;

        if ($stmt === false) {
            $this->lastError = sqlsrv_errors();
            info_log('SQL错误', substr($sql, 0, 200), 'ERROR', ['sql_error' => $this->getErrorMessage()]);
            return false;
        }

        if ($elapsed > 1000) {
            info_log('慢查询', substr($sql, 0, 200), 'WARN', [
                '耗时(ms)' => round($elapsed, 2),
                'params_count' => count($params)
            ]);
        }

        $rowsAffected = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        return $rowsAffected;
    }

    /**
     * 执行查询并返回语句句柄。
     *
     * @param string $sql SQL 查询语句
     * @param array<int,mixed> $params 绑定参数数组
     * @return resource|false SQL Server 语句资源或 false
     */
    private function executeQuery($sql, $params = [])
    {
        $start = microtime(true);
        $stmt = sqlsrv_query($this->connection, $sql, $params);
        $elapsed = (microtime(true) - $start) * 1000;

        if ($elapsed > 1000) {
            info_log('慢查询', substr($sql, 0, 200), 'WARN', [
                '耗时(ms)' => round($elapsed, 2),
                'params_count' => count($params)
            ]);
        }

        if ($stmt === false) {
            $this->lastError = sqlsrv_errors();
            info_log('SQL错误', substr($sql, 0, 200), 'ERROR', ['sql_error' => $this->getErrorMessage()]);
            return false;
        }

        return $stmt;
    }

    /**
     * 开始事务。
     *
     * @return bool 是否成功
     */
    public function beginTransaction()
    {
        if (sqlsrv_begin_transaction($this->connection) === false) {
            $this->lastError = sqlsrv_errors();
            return false;
        }
        return true;
    }

    /**
     * 提交事务。
     *
     * @return bool 是否成功
     */
    public function commit()
    {
        if (sqlsrv_commit($this->connection) === false) {
            $this->lastError = sqlsrv_errors();
            return false;
        }
        return true;
    }

    /**
     * 回滚事务。
     *
     * @return bool 是否成功
     */
    public function rollBack()
    {
        if (sqlsrv_rollback($this->connection) === false) {
            $this->lastError = sqlsrv_errors();
            return false;
        }
        return true;
    }

    /**
     * 获取最后插入的 ID。
     *
     * @return string|false 最后插入 ID 或 false
     */
    public function lastInsertId()
    {
        $sql = "SELECT SCOPE_IDENTITY() AS id";
        $result = $this->queryOne($sql);
        return $result ? $result['id'] : false;
    }

    /**
     * 获取最后一次错误信息数组。
     *
     * @return array<int,array<string,mixed>>|null 错误数组或 null
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * 获取格式化的最后一次错误信息。
     *
     * @return string 错误消息字符串
     */
    public function getErrorMessage()
    {
        if ($this->lastError === null) {
            return '';
        }

        $messages = [];
        foreach ($this->lastError as $error) {
            $messages[] = "[SQLSTATE {$error['SQLSTATE']}] {$error['message']}";
        }

        return implode('; ', $messages);
    }

    /**
     * 清除当前错误信息。
     *
     * @return void
     */
    private function clearError()
    {
        $this->lastError = null;
    }

    /**
     * 转换查询结果编码，处理字符串和 DateTime 类型。
     *
     * @param array<string,mixed> $data 查询结果行
     * @return array<string,mixed> 转换后的结果行
     */
    private function convertEncoding($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_string($value)) {
                    $data[$key] = $value;
                } elseif ($value instanceof DateTime) {
                    $data[$key] = $value->format('Y-m-d H:i:s');
                }
            }
        }
        return $data;
    }

    /**
     * 关闭数据库连接。
     *
     * @return void
     */
    public function close()
    {
        if ($this->connection) {
            sqlsrv_close($this->connection);
            $this->connection = null;
        }
    }

    /**
     * 检查是否已建立连接。
     *
     * @return bool 是否已连接
     */
    public function isConnected()
    {
        return $this->connection !== null;
    }

    /**
     * 获取底层 SQL Server 连接资源。
     *
     * @return resource|null 连接资源或 null
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * 执行批量 SQL，并返回每个结果集。
     *
     * @param string $sql 批量 SQL 语句
     * @return array<int,array<int,array<string,mixed>>> 多结果集数组
     */
    public function executeBatch(string $sql): array
    {
        $this->clearError();

        $start = microtime(true);
        $stmt = sqlsrv_query($this->connection, $sql);
        $elapsed = (microtime(true) - $start) * 1000;

        if ($stmt === false) {
            $this->lastError = sqlsrv_errors();
            info_log('SQL错误', substr($sql, 0, 200), 'ERROR', ['sql_error' => $this->getErrorMessage()]);
            return [];
        }

        if ($elapsed > 1000) {
            info_log('慢查询', substr($sql, 0, 200), 'WARN', ['耗时(ms)' => round($elapsed, 2)]);
        }

        $allResults = [];

        do {
            $rows = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $rows[] = $this->convertEncoding($row);
            }
            $allResults[] = $rows;
        } while (sqlsrv_next_result($stmt));

        sqlsrv_free_stmt($stmt);
        return $allResults;
    }

    /**
     * 析构函数，自动关闭连接。
     */
    public function __destruct()
    {
        $this->close();
    }
}





