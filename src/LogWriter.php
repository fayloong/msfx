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
     * `request_status` 允许 null：外部上传记录（App\RetailExternalUploads）用它表示
     * **本项目没有发起任何请求**——写「请求成功」是失真（那会让它在详情弹窗里看起来像一次真实调用）。
     *
     * @param array{djbh: string, request_status: ?string, response_status: ?string, response: string, task_id?: int, ent_name?: string, trace_codes?: string, rq?: string, source?: string, company?: string, credential?: ?string} $entry
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
        if (!empty($entry['source'])) {
            $record['source'] = $entry['source'];
        }
        if (!empty($entry['company'])) {
            $record['company'] = $entry['company'];
        }
        if (!empty($entry['credential'])) {
            $record['credential'] = $entry['credential'];
        }

        // 写入 JSONL 文件
        $this->appendJsonl($record);

        // 写入 SQLite
        $db = Database::getInstance();
        $db->execute(
            "INSERT INTO upload_logs (task_id, djbh, ent_name, trace_codes, rq, request_status, response_status, response, source, company, credential, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $entry['task_id'] ?? 0,
                $entry['djbh'],
                $entry['ent_name'] ?? '',
                $entry['trace_codes'] ?? '',
                $entry['rq'] ?? '',
                $entry['request_status'],
                $entry['response_status'] ?? null,
                $entry['response'] ?? '',
                $entry['source'] ?? '',
                $entry['company'] ?? '',
                $entry['credential'] ?? '',
                date('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * **只写 JSONL、不写 SQLite**：给那些"不是上传结果"的告警用。
     *
     * 为什么不复用 `write()`：它会写 `upload_logs`，而失败记录页把那张表当"上传结果"读——
     * 采集的 `retail_claim_name_unmatched`、回写的 `update_state_write_failed` 写进去，会在
     * 失败记录页冒出既非上传也非失败的记录，污染唯一的告警出口（ADR 0007 定的口径）。
     * 这些告警的出口只有 JSONL，但**JSONL 怎么写的只有这一处**（文件命名、编码、追加与锁）。
     *
     * @param array<string,mixed> $record 记录内容；未带 timestamp 时自动补
     */
    public function writeJsonlOnly(array $record): void
    {
        $record += ['timestamp' => date('Y-m-d H:i:s')];
        $this->appendJsonl($record);
    }

    /**
     * JSONL 的**唯一**写入点：`write()` 与 `writeJsonlOnly()` 都走这里。
     */
    private function appendJsonl(array $record): void
    {
        $jsonlFile = $this->logDir . '/api_' . date('Y-m-d') . '.jsonl';
        $line = json_encode($record, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($jsonlFile, $line, FILE_APPEND | LOCK_EX);
    }
}
