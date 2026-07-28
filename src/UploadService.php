<?php

namespace App;

class UploadService
{
    private ApiClient $apiClient;
    private LogWriter $logWriter;
    private ?string $lockFile;

    private const MAX_TRACE_CODES = 3500;
    private const MAX_RETRIES = 3;
    private const RETRY_INTERVAL_SEC = 30;
    private const API_INTERVAL_US = 330000;

    private static array $billTypeMap = [
        'XSO' => '201', // 销售出库
        'XST' => '103', // 退货入库
        'JHG' => '102', // 采购入库
        'JHO' => '202', // 采购退出
    ];

    public function __construct(?string $lockFile = null)
    {
        $this->apiClient = new ApiClient();
        $this->logWriter = new LogWriter();
        $this->lockFile = $lockFile ?? __DIR__ . '/../logs/upload.lock';
    }

    /**
     * 上传单据列表（cron 和 Web 共用）。
     *
     * @param array<int, array{type?: string, rq: string, djbh: string, ent_name: string, sn: string, task_id?: int}> $bills
     * @return array{total: int, success: int, failed: int}
     */
    public function upload(array $bills): array
    {
        $lock = $this->acquireLock();
        if (!$lock) {
            throw new \RuntimeException('上传任务正在进行中，请稍后重试');
        }

        try {
            return $this->doUpload($bills);
        } finally {
            $this->releaseLock($lock);
        }
    }

    private function doUpload(array $bills): array
    {
        $total = count($bills);
        $success = 0;
        $failed = 0;

        foreach ($bills as $index => $bill) {
            $billCodes = $this->splitBillCodes($bill['djbh'], $bill['sn']);

            foreach ($billCodes as $subBillCode => $traceCodes) {
                $result = $this->uploadSingle($subBillCode, $bill, $traceCodes);

                if ($result['success']) {
                    $success++;
                } else {
                    $failed++;
                }

                // 更新 upload_tasks 状态
                if (!empty($bill['task_id'])) {
                    $this->updateTaskStatus($bill['task_id'], $result['success'] ? '已上传' : '任务失败', $result['response']);
                }

                usleep(self::API_INTERVAL_US);
            }
        }

        return ['total' => $total, 'success' => $success, 'failed' => $failed];
    }

    /**
     * 上传单个单据（含重试逻辑）。
     */
    private function uploadSingle(string $billCode, array $bill, string $traceCodes): array
    {
        $billType = self::$billTypeMap[$bill['type']] ?? '201';

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            try {
                $entId = $this->resolveEntId($bill['ent_name']);
                if ($entId === null) {
                    $response = json_encode(['error' => '无法获取往来单位ent_id: ' . $bill['ent_name']], JSON_UNESCAPED_UNICODE);
                    $this->logWriter->write([
                        'djbh' => $billCode,
                        'success' => false,
                        'response' => $response,
                        'task_id' => $bill['task_id'] ?? 0,
                    ]);
                    return ['success' => false, 'response' => $response];
                }

                $req = new \AlibabaAlihealthDrugKytUploadinoutbillRequest;
                $req->setBillCode($billCode);
                $req->setBillTime($bill['rq']);
                $req->setBillType($billType);
                $req->setPhysicType("3");
                $req->setRefUserId(Config::get('REFENTID_HYYY'));
                $req->setOperIcCode(Config::get('APPKEY_HYYY'));
                $req->setOperIcName(Config::get('APPKEY_HYYY'));
                $req->setTraceCodes($traceCodes);
                $req->setClientType("2");

                $this->setBillEntIds($req, $billType, $entId);

                $result = $this->apiClient->execute($req);
                $response = json_encode($result, JSON_UNESCAPED_UNICODE);

                $this->logWriter->write([
                    'djbh' => $billCode,
                    'success' => $result['success'],
                    'response' => $response,
                    'task_id' => $bill['task_id'] ?? 0,
                ]);

                if ($result['success']) {
                    return ['success' => true, 'response' => $response];
                }

                // 业务错误不重试
                if (!$result['is_network_error']) {
                    return ['success' => false, 'response' => $response];
                }

                // 网络错误，等待后重试
                if ($attempt < self::MAX_RETRIES - 1) {
                    sleep(self::RETRY_INTERVAL_SEC);
                }

            } catch (\Exception $e) {
                if ($attempt >= self::MAX_RETRIES - 1) {
                    $response = json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    $this->logWriter->write([
                        'djbh' => $billCode,
                        'success' => false,
                        'response' => $response,
                        'task_id' => $bill['task_id'] ?? 0,
                    ]);
                    return ['success' => false, 'response' => $response];
                }
                sleep(self::RETRY_INTERVAL_SEC);
            }
        }

        return ['success' => false, 'response' => 'Max retries exceeded'];
    }

    /**
     * 拆分追溯码：超过 3500 个自动拆分单号。
     */
    public function splitBillCodes(string $billCode, string $traceCodes): array
    {
        $codes = array_filter(explode(',', $traceCodes));
        if (count($codes) <= self::MAX_TRACE_CODES) {
            return [$billCode => $traceCodes];
        }

        $chunks = array_chunk($codes, self::MAX_TRACE_CODES);
        $result = [];
        foreach ($chunks as $i => $chunk) {
            $suffix = $i + 1;
            $result[$billCode . '_' . $suffix] = implode(',', $chunk);
        }
        return $result;
    }

    /**
     * 获取往来单位 ent_id，优先 SQLite 缓存，未命中调 API。
     */
    private function resolveEntId(string $entName): ?string
    {
        $db = Database::getInstance();
        $cached = $db->queryOne("SELECT ent_id FROM ent_list WHERE ent_name = ?", [$entName]);

        if ($cached && !empty($cached['ent_id'])) {
            return $cached['ent_id'];
        }

        // API 在线查询
        $entInfo = $this->apiClient->queryEntInfo($entName);
        if ($entInfo && !empty($entInfo['ent_id'])) {
            // 写入缓存
            $db->execute(
                "INSERT OR REPLACE INTO ent_list (ent_name, ent_id, ref_ent_id) VALUES (?, ?, ?)",
                [$entInfo['ent_name'], $entInfo['ent_id'], $entInfo['ref_ent_id'] ?? '']
            );
            return $entInfo['ent_id'];
        }

        return null;
    }

    private function setBillEntIds($req, string $billType, string $entId): void
    {
        $ownEntId = Config::get('ENTID_HYYY');

        switch ($billType) {
            case '201': // 销售出库
                $req->setToUserId($entId);
                $req->setDisEntId($ownEntId);
                $req->setFromUserId($ownEntId);
                break;
            case '102': // 采购入库
                $req->setToUserId($ownEntId);
                $req->setFromUserId($entId);
                break;
            case '103': // 销售退回
                $req->setToUserId($ownEntId);
                $req->setFromUserId($entId);
                break;
            case '202': // 采购退出
                $req->setToUserId($entId);
                $req->setFromUserId($ownEntId);
                break;
        }
    }

    private function updateTaskStatus(int $taskId, string $status, string $resp): void
    {
        $db = Database::getInstance();
        $db->execute(
            "UPDATE upload_tasks SET status = ?, resp = ?, updated_at = datetime('now','localtime') WHERE id = ?",
            [$status, $resp, $taskId]
        );
    }

    private function acquireLock()
    {
        $fp = fopen($this->lockFile, 'w+');
        if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
            if ($fp) {
                fclose($fp);
            }
            return null;
        }
        return $fp;
    }

    private function releaseLock($fp): void
    {
        if ($fp) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
