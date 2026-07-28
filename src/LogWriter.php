<?php

namespace App;

class LogWriter
{
    private string $logDir;

    public function __construct(?string $logDir = null)
    {
        $this->logDir = $logDir ?? __DIR__ . '/../logs';
    }

    /**
     * 写入上传日志到 JSONL + SQLite。
     *
     * @param array{djbh: string, success: bool, response: string, task_id?: int} $entry
     */
    public function write(array $entry): void
    {
        $record = [
            'timestamp' => date('Y-m-d H:i:s'),
            'djbh' => $entry['djbh'],
            'success' => $entry['success'],
            'response' => $entry['response'] ?? '',
        ];
        if (isset($entry['task_id'])) {
            $record['task_id'] = $entry['task_id'];
        }

        // 写入 JSONL 文件
        $jsonlFile = $this->logDir . '/api_' . date('Y-m-d') . '.jsonl';
        $line = json_encode($record, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($jsonlFile, $line, FILE_APPEND | LOCK_EX);

        // 写入 SQLite
        $db = Database::getInstance();
        $db->execute(
            "INSERT INTO upload_logs (task_id, djbh, success, response, created_at) VALUES (?, ?, ?, ?, ?)",
            [
                $entry['task_id'] ?? 0,
                $entry['djbh'],
                $entry['success'] ? 1 : 0,
                $entry['response'] ?? '',
                date('Y-m-d H:i:s'),
            ]
        );
    }
}
