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
     * @param array{djbh: string, request_status: string, response_status: ?string, response: string, task_id?: int, ent_name?: string, trace_codes?: string, rq?: string} $entry
     */
    public function write(array $entry): void
    {
        $record = [
            'timestamp' => date('Y-m-d H:i:s'),
            'djbh' => $entry['djbh'],
            'request_status' => $entry['request_status'],
            'response_status' => $entry['response_status'],
            'response' => $entry['response'] ?? '',
        ];
        if (isset($entry['task_id'])) {
            $record['task_id'] = $entry['task_id'];
        }
        if (!empty($entry['ent_name'])) {
            $record['ent_name'] = $entry['ent_name'];
        }
        if (!empty($entry['trace_codes'])) {
            $record['trace_codes'] = $entry['trace_codes'];
        }
        if (!empty($entry['rq'])) {
            $record['rq'] = $entry['rq'];
        }

        // 写入 JSONL 文件
        $jsonlFile = $this->logDir . '/api_' . date('Y-m-d') . '.jsonl';
        $line = json_encode($record, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($jsonlFile, $line, FILE_APPEND | LOCK_EX);

        // 写入 SQLite
        $db = Database::getInstance();
        $db->execute(
            "INSERT INTO upload_logs (task_id, djbh, ent_name, trace_codes, rq, request_status, response_status, response, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $entry['task_id'] ?? 0,
                $entry['djbh'],
                $entry['ent_name'] ?? '',
                $entry['trace_codes'] ?? '',
                $entry['rq'] ?? '',
                $entry['request_status'],
                $entry['response_status'] ?? null,
                $entry['response'] ?? '',
                date('Y-m-d H:i:s'),
            ]
        );
    }
}
