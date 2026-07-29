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
     * @param callable|null $onProgress 进度回调 function(array $progress): void
     * @return array{total: int, success: int, failed: int}
     */
    public function upload(array $bills, ?callable $onProgress = null): array
    {
        $lock = $this->acquireLock();
        if (!$lock) {
            throw new \RuntimeException('上传任务正在进行中，请稍后重试');
        }

        try {
            return $this->doUpload($bills, $onProgress);
        } finally {
            $this->releaseLock($lock);
        }
    }

    private function doUpload(array $bills, ?callable $onProgress = null): array
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
                    $this->updateTaskStatus($bill['task_id'], '已处理', $result['request_status'], $result['response_status'], $result['response']);
                }

                if ($onProgress) {
                    $onProgress([
                        'djbh' => $subBillCode,
                        'ent_name' => $bill['ent_name'] ?? '',
                        'success' => $result['success'],
                        'request_status' => $result['request_status'],
                        'response_status' => $result['response_status'],
                        'response' => $result['response'],
                    ]);
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
        $billType = $bill['type'] ?? '201';
        // 支持数字类型码（如 "102"）直接使用，也兼容旧的字母前缀（如 "JHG"）
        if (!preg_match('/^\d{3}$/', $billType)) {
            $billType = self::$billTypeMap[$billType] ?? '201';
        }

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            try {
                $entId = $this->resolveEntId($bill['ent_name']);
                if ($entId === null) {
                    $response = json_encode(['error' => '无法获取往来单位ent_id: ' . $bill['ent_name']], JSON_UNESCAPED_UNICODE);
                    $this->logWriter->write([
                        'djbh' => $billCode,
                        'request_status' => '请求失败',
                        'response_status' => '往来单位缺失',
                        'response' => $response,
                        'task_id' => $bill['task_id'] ?? 0,
                        'ent_name' => $bill['ent_name'] ?? '',
                        'trace_codes' => $traceCodes,
                    ]);
                    return ['success' => false, 'response' => $response, 'request_status' => '请求失败', 'response_status' => '往来单位缺失'];
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

                $requestStatus = $result['is_network_error'] ? '请求失败' : '请求成功';
                $responseStatus = $this->resolveResponseStatus($result);

                $this->logWriter->write([
                    'djbh' => $billCode,
                    'request_status' => $requestStatus,
                    'response_status' => $responseStatus,
                    'response' => $response,
                    'task_id' => $bill['task_id'] ?? 0,
                    'ent_name' => $bill['ent_name'] ?? '',
                    'trace_codes' => $traceCodes,
                ]);

                if ($result['success']) {
                    return ['success' => true, 'response' => $response, 'request_status' => $requestStatus, 'response_status' => $responseStatus];
                }

                // 业务错误不重试
                if (!$result['is_network_error']) {
                    return ['success' => false, 'response' => $response, 'request_status' => $requestStatus, 'response_status' => $responseStatus];
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
                        'request_status' => '请求失败',
                        'response_status' => null,
                        'response' => $response,
                        'task_id' => $bill['task_id'] ?? 0,
                        'ent_name' => $bill['ent_name'] ?? '',
                        'trace_codes' => $traceCodes,
                    ]);
                    return ['success' => false, 'response' => $response, 'request_status' => '请求失败', 'response_status' => null];
                }
                sleep(self::RETRY_INTERVAL_SEC);
            }
        }

        return ['success' => false, 'response' => 'Max retries exceeded', 'request_status' => '请求失败', 'response_status' => null];
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

        if ($billType === '201') {
            // 销售出库：需额外设置 disEntId
            $req->setToUserId($entId);
            $req->setDisEntId($ownEntId);
            $req->setFromUserId($ownEntId);
        } elseif (str_starts_with($billType, '1')) {
            // 入库类（1xx）：toUserId=自己, fromUserId=对方
            $req->setToUserId($ownEntId);
            $req->setFromUserId($entId);
        } else {
            // 出库类（2xx）：toUserId=对方, fromUserId=自己
            $req->setToUserId($entId);
            $req->setFromUserId($ownEntId);
        }
    }

    private function updateTaskStatus(int $taskId, string $taskStatus, string $requestStatus, ?string $responseStatus, string $resp): void
    {
        $db = Database::getInstance();
        $db->execute(
            "UPDATE upload_tasks SET task_status = ?, request_status = ?, response_status = ?, resp = ?, updated_at = datetime('now','localtime') WHERE id = ?",
            [$taskStatus, $requestStatus, $responseStatus, $resp, $taskId]
        );
    }

    /**
     * 从 API 返回结果解析响应状态。
     */
    private function resolveResponseStatus(array $result): ?string
    {
        if ($result['is_network_error']) {
            return null;
        }

        $data = $result['data'];
        if ($data === null) {
            return '未确定';
        }

        if (is_object($data)) {
            $data = json_decode(json_encode($data), true);
        }

        $inner = $data['result'] ?? [];
        if (empty($inner)) {
            // 部分响应（如重复单据）msg_code/msg_info 直接在 data 层级
            $inner = $data;
        }
        $msgCode = $inner['msg_code'] ?? '';
        $msgInfo = $inner['msg_info'] ?? '';
        $responseSuccess = $inner['response_success'] ?? '';

        if ($msgCode === 'SUCCESS' && $responseSuccess === 'true') {
            return '上传成功';
        }
        if (strpos($msgInfo, '该单据号已存在') !== false) {
            return '单据重复';
        }
        if ($msgCode === 'FAIL_BIZ_NO_PAT_INFO') {
            return '信息不存在';
        }
        if ($msgCode === 'FAIL') {
            return '上传失败';
        }

        return '未确定';
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
