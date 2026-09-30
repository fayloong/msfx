<?php

namespace App;

class UploadService
{
    private LogWriter $logWriter;
    private ?string $lockFile;

    private const MAX_TRACE_CODES = 3500;
    private const MAX_RETRIES = 3;
    private const RETRY_INTERVAL_SEC = 30;
    private const API_INTERVAL_US = 330000;

    /**
     * 本服务支持的接口族：批发 kyt 上传。
     *
     * 企业路由到别的接口（零售 lsyd）时一律拒传——零售请求装配另有接缝
     * （见 .scratch/retail-chain/spec.md §3），拿批发模板去装配门店单据就是把单据报到错误主体。
     */
    private const SUPPORTED_REQUEST_CLASS = \AlibabaAlihealthDrugKytUploadinoutbillRequest::class;

    /** @var array<string,ApiClient> 按 AppKey 缓存（同批次同企业共用；不同企业各自签名） */
    private array $apiClients = [];

    public function __construct(?string $lockFile = null)
    {
        $this->logWriter = new LogWriter();
        $this->lockFile = $lockFile ?? __DIR__ . '/../logs/upload.lock';
    }

    /**
     * 上传单据列表（cron 和 Web 共用）。
     *
     * 上传前逐条校验所属企业与凭据（fail-closed，见 resolveContext）：任何一条不合规就整批拒绝——
     * 混有零售单据的批次"传一半才报错"比一开始就拒绝更难收拾。
     *
     * @param array<int, array{type?: string, rq: string, djbh: string, ent_name: string, sn: string, task_id?: int, source?: string, company?: string, credential?: string}> $bills
     * @param callable|null $onProgress 进度回调 function(array $progress): void
     * @return array{total: int, success: int, failed: int}
     * @throws \RuntimeException 任一条单据所属企业/接口/凭据不合规（拒传，不发一次平台调用）
     */
    public function upload(array $bills, ?callable $onProgress = null): array
    {
        $contexts = [];
        foreach ($bills as $index => $bill) {
            $contexts[$index] = $this->resolveContext($bill);
        }

        $lock = $this->acquireLock();
        if (!$lock) {
            throw new \RuntimeException('上传任务正在进行中，请稍后重试');
        }

        try {
            return $this->doUpload($bills, $contexts, $onProgress);
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * 上传前置校验：确认所属企业、接口族与凭据，返回这次上传要用的凭据。
     *
     * 本类的 fail-closed 关口——凭据只能来自**任务所属企业**，取不到就拒传，绝不回落到默认
     * （河药）凭据。只让调用方把 SQL 写对是不够的：任何脚本直接 new UploadService() 都会绕过
     * 调用方，而单据一旦申报到错误主体，在平台上不可逆（见 docs/adr/0006）。
     *
     * @param array{type?: string, djbh?: string, company?: string, credential?: string} $bill
     * @return array{company:string, credential_key:string, credential:array<string,string>}
     * @throws \RuntimeException 企业未知/未识别、接口不属本服务支持的族、凭据缺失或残缺
     */
    private function resolveContext(array $bill): array
    {
        $djbh = (string)($bill['djbh'] ?? '');
        $company = trim((string)($bill['company'] ?? ''));
        $billType = $this->resolveBillType($bill);

        $enterprise = $company === '' ? null : Enterprise::find($company);
        if ($enterprise === null) {
            throw new \RuntimeException(
                "单号 {$djbh}: 所属企业「{$company}」不在企业配置中，拒绝上传"
                . '（未识别的单据只能人工处理，不能用默认主体顶上）'
            );
        }

        $route = Enterprise::route($company, $billType);
        if ($route === null || $route['class'] !== self::SUPPORTED_REQUEST_CLASS) {
            throw new \RuntimeException(
                "单号 {$djbh}: 企业「{$company}」的单据类型 {$billType} 不走批发上传接口，本服务拒绝上传"
            );
        }

        $credentialKey = trim((string)($bill['credential'] ?? ''));
        $credential = $credentialKey === '' ? null : Enterprise::credential($company, $credentialKey);
        if ($credential === null || !Enterprise::credentialConfigured($credential)) {
            throw new \RuntimeException(
                "单号 {$djbh}: 企业「{$company}」取不到可用凭据"
                . ($credentialKey === '' ? '（任务未记录凭据）' : "（凭据位 {$credentialKey}）")
                . '，拒绝上传'
            );
        }

        return ['company' => $company, 'credential_key' => $credentialKey, 'credential' => $credential];
    }

    /**
     * @param array<int, array> $bills
     * @param array<int, array{company:string, credential_key:string, credential:array}> $contexts 与 $bills 同下标
     */
    private function doUpload(array $bills, array $contexts, ?callable $onProgress = null): array
    {
        $total = count($bills);
        $success = 0;
        $failed = 0;

        foreach ($bills as $index => $bill) {
            $context = $contexts[$index];
            $billCodes = TraceSplitter::splitByCount($bill['djbh'], $bill['sn'], self::MAX_TRACE_CODES);

            foreach ($billCodes as $subBillCode => $traceCodes) {
                $result = $this->uploadSingle($subBillCode, $bill, $traceCodes, $context);

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
     *
     * @param array{company:string, credential_key:string, credential:array} $context resolveContext 的返回值
     */
    private function uploadSingle(string $billCode, array $bill, string $traceCodes, array $context): array
    {
        $billType = $this->resolveBillType($bill);
        $credential = $context['credential'];
        $apiClient = $this->apiClientFor($credential);

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            try {
                $entId = $this->resolveEntId($bill['ent_name'], $context['company'], $apiClient);
                if ($entId === null) {
                    $response = json_encode(['error' => '无法获取往来单位ent_id: ' . $bill['ent_name']], JSON_UNESCAPED_UNICODE);
                    $this->writeLog($billCode, $bill, $traceCodes, $context, [
                        'request_status' => '请求失败',
                        'response_status' => '往来单位缺失',
                        'response' => $response,
                    ]);
                    return ['success' => false, 'response' => $response, 'request_status' => '请求失败', 'response_status' => '往来单位缺失'];
                }

                $req = new \AlibabaAlihealthDrugKytUploadinoutbillRequest;
                $req->setBillCode($billCode);
                $req->setBillTime($bill['rq']);
                $req->setBillType($billType);
                $req->setPhysicType("3");
                $req->setRefUserId($credential['ref_ent_id']);
                $req->setOperIcCode($credential['appkey']);
                $req->setOperIcName($credential['appkey']);
                $req->setTraceCodes($traceCodes);
                $req->setClientType("2");

                $this->setBillEntIds($req, $billType, $entId, $credential['ent_id']);

                $result = $apiClient->execute($req);
                $response = json_encode($result, JSON_UNESCAPED_UNICODE);

                $requestStatus = $result['is_network_error'] ? '请求失败' : '请求成功';
                // 响应状态解析在 ApiClient（批发与零售补传共用，见源码注释）
                $responseStatus = ApiClient::resolveUploadResponseStatus($result);

                $this->writeLog($billCode, $bill, $traceCodes, $context, [
                    'request_status' => $requestStatus,
                    'response_status' => $responseStatus,
                    'response' => $response,
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
                    $this->writeLog($billCode, $bill, $traceCodes, $context, [
                        'request_status' => '请求失败',
                        'response_status' => null,
                        'response' => $response,
                    ]);
                    return ['success' => false, 'response' => $response, 'request_status' => '请求失败', 'response_status' => null];
                }
                sleep(self::RETRY_INTERVAL_SEC);
            }
        }

        return ['success' => false, 'response' => 'Max retries exceeded', 'request_status' => '请求失败', 'response_status' => null];
    }

    /** 单据类型归一化：数字类型码直通，字母前缀（如 "JHG"）转数字；无法识别兜底销售出库（原逻辑） */
    private function resolveBillType(array $bill): string
    {
        return BillType::normalize($bill['type'] ?? '', '') ?: '201';
    }

    /**
     * 写上传日志（JSONL + SQLite）。三个分支共用，免得各写一份时漏掉 company/credential。
     *
     * @param array{company:string, credential_key:string} $context
     * @param array{request_status:string, response_status:?string, response:string} $result
     */
    private function writeLog(string $billCode, array $bill, string $traceCodes, array $context, array $result): void
    {
        $this->logWriter->write([
            'djbh' => $billCode,
            'request_status' => $result['request_status'],
            'response_status' => $result['response_status'],
            'response' => $result['response'],
            'task_id' => $bill['task_id'] ?? 0,
            'ent_name' => $bill['ent_name'] ?? '',
            'trace_codes' => $traceCodes,
            'rq' => $bill['rq'] ?? '',
            'source' => $bill['source'] ?? '',
            'company' => $context['company'],
            'credential' => $context['credential_key'],
        ]);
    }

    /**
     * 获取往来单位 ent_id，优先 SQLite 缓存（按企业区分，同名往来单位跨企业各有各的 ent_id），未命中调 API。
     *
     * 用哪套凭据查由调用方传入的 client 决定——批发链路即任务所属企业的凭据。
     * 留债：ApiClient::queryEntInfo 内部的 ref_ent_id 仍读 .env（见 CLAUDE.md「企业配置与凭据」，
     * 查询类凭据随 .env 旧键下线一并处理）；因本服务只放行批发单据，目前与传入凭据一致。
     */
    private function resolveEntId(string $entName, string $company, ApiClient $apiClient): ?string
    {
        $db = Database::getInstance();
        $cached = $db->queryOne(
            "SELECT ent_id FROM ent_list WHERE ent_name = ? AND company = ?",
            [$entName, $company]
        );

        if ($cached && !empty($cached['ent_id'])) {
            return $cached['ent_id'];
        }

        // API 在线查询
        $entInfo = $apiClient->queryEntInfo($entName);
        if ($entInfo && !empty($entInfo['ent_id'])) {
            // 写入缓存（唯一键 (company, ent_name)：同名往来单位在不同企业下各存一行）
            $db->execute(
                "INSERT OR REPLACE INTO ent_list (company, ent_name, ent_id, ref_ent_id) VALUES (?, ?, ?, ?)",
                [$company, $entInfo['ent_name'], $entInfo['ent_id'], $entInfo['ref_ent_id'] ?? '']
            );
            return $entInfo['ent_id'];
        }

        return null;
    }

    /** 按企业凭据取 API 客户端（AppKey 决定签名与平台限流池，同一批同企业共用实例） */
    private function apiClientFor(array $credential): ApiClient
    {
        $appkey = (string)$credential['appkey'];
        return $this->apiClients[$appkey] ??= new ApiClient($appkey, (string)$credential['secretkey']);
    }

    private function setBillEntIds($req, string $billType, string $entId, string $ownEntId): void
    {
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
