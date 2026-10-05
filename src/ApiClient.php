<?php

namespace App;

// TOP SDK 的工作目录（TopLogger 用它拼 <WORK_DIR>/logs/top_*_err_*.log）。
// 默认值是 /tmp/（见 top_sdk/TopSdk.php:18），而 /tmp/logs 在本机是 root:root 755——以 nginx
// 用户跑时 fopen 失败 → getFileHandle() 返回 false → fwrite(false, …) 抛 TypeError，从
// TopClient::execute() 抛穿整条上传链路，把真实的平台错误（限流 code=7 / 响应不合法）
// 掩盖成一句类型错误（2026-10-02 实测，2,861 条里踩中 4 次）。
// TopSdk.php 那句是 `if (!defined(...))`，文件头也注明"在 include 之前定义这些常量，
// 不要直接修改本文件"——所以在 require 之前定义即可，不需要动 vendored 的 SDK。
// ⚠️ 本文件与 RetailRequestAssembler.php 都 require TopSdk.php，**谁先被自动加载谁定这个常量**，
//    故两处各有一份同样的守卫（`if (!defined)` 幂等）——只在其中一处定义会随加载顺序静默回落 /tmp/，
//    `tests/api_client_test.php` 先加载装配类再断言，钉住这条。
if (!defined('TOP_SDK_WORK_DIR')) {
    define('TOP_SDK_WORK_DIR', dirname(__DIR__));
}

require_once __DIR__ . '/../top_sdk/TopSdk.php';

class ApiClient
{
    private \TopClient $client;

    public function __construct(?string $appkey = null, ?string $secretKey = null)
    {
        $this->client = new \TopClient(
            $appkey ?? Config::get('APPKEY_HYYY'),
            $secretKey ?? Config::get('SECRETKEY_HYYY')
        );
    }

    /**
     * 按**一套凭据**造客户端（AppKey 决定签名与平台限流池）。
     *
     * 内部只取 `appkey` / `secretkey` 两字段——凭据数组里还有 `ref_ent_id` / `ent_id`，
     * 手工挑字段挑错一个就是把请求签到了别的主体名下。少一处手挑，少一条错主体的路径。
     * 迁移期仍允许不传（回落 .env 的河药凭据，见构造函数），但生产链路一律走本方法。
     */
    public static function forCredential(array $credential): self
    {
        return new self((string)($credential['appkey'] ?? ''), (string)($credential['secretkey'] ?? ''));
    }

    /**
     * 执行 API 请求，区分网络异常和业务异常。
     *
     * @return array{success: bool, data: mixed, error: string, is_network_error: bool}
     */
    public function execute($request): array
    {
        try {
            $resp = $this->client->execute($request);

            // 检查是否有错误码
            if (isset($resp->code) && $resp->code != 0) {
                return [
                    'success' => false,
                    'data' => $resp,
                    'error' => $resp->msg ?? 'Unknown API error',
                    'is_network_error' => self::isRetryableTopError($resp->code, (string)($resp->msg ?? '')),
                ];
            }

            return [
                'success' => true,
                'data' => $resp,
                'error' => '',
                'is_network_error' => false,
            ];
        } catch (\Throwable $e) {
            // cURL 异常 → 网络错误。
            // 捕 \Throwable 而非 \Exception：`\Error`（TypeError 等）不被后者捕获——SDK 内部抛出的
            // Error 因此会**抛穿整条上传链路**（2026-10-02 实测：TopLogger 写日志失败抛的 TypeError
            // 把限流掩盖成一句类型错误）。归为「网络错误（可重试）」比抛穿安全。
            $msg = $e->getMessage();
            return [
                'success' => false,
                'data' => null,
                'error' => $msg,
                'is_network_error' => true,
            ];
        }
    }

    /**
     * 顶层错误码是否属于「调用级、等一会儿重试就能成」的那一类。
     *
     * 与 `resolveUploadResponseStatus()` 的区别：那个读的是**业务响应**（success=true + data 里的
     * msg_code），本方法读的是**顶层 code**（网关级错误，SDK 在 TopClient.php:330 专门为它写日志）。
     * 顶层 code 过去一律按「业务错误不重试」处理，但限流是典型的可重试错误——实测封禁只有一两秒
     * （sub_msg: "This ban will last for N more seconds"），而重试间隔是 30 秒。
     * 判据：code=7（App Call Limited）或 msg 含 "App Call Limited"（code 变了也能兜住）。
     *
     * @param mixed $code 顶层错误码（string|int）
     */
    public static function isRetryableTopError($code, string $msg): bool
    {
        if ((string)$code === '7') {
            return true;
        }
        return stripos($msg, 'App Call Limited') !== false;
    }

    /**
     * 从 execute() 的返回结果解析上传响应状态（批发 kyt 与零售 lsyd 共用同一套平台返回语义）。
     *
     * 原为 UploadService 的私有方法，零售补传（src/api/tasks_retry_retail.php）要用同一套判定，
     * 故上移到本类——与 isBillFound / sumBillDetailCount / sumPkgAmount 同处：平台响应怎么读，
     * 只在这一个文件里回答。UploadService 改为调用本方法，行为不变。
     *
     * @param array{success: bool, data: mixed, error: string, is_network_error: bool} $result
     */
    public static function resolveUploadResponseStatus(array $result): ?string
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

    /**
     * 查询往来单位信息。
     *
     * $refEntId 是**申报主体**的单位编码（查出来的往来单位是相对它而言的）：批发传河药那套、
     * 门店手工建单传该门店自己那套——**必传**，没有默认值（原本回落到 `.env` 的河药值，
     * 那正是"拿河药的名录去查门店的往来单位"这条错主体路径）。用错主体的编码查，查到的是
     * 别人名下的往来单位，单据随后会报到错误主体。
     *
     * @return array{ent_name: string, ent_id: string, ref_ent_id: string}|null
     */
    public function queryEntInfo(string $entName, string $refEntId): ?array
    {
        $req = new \AlibabaAlihealthDrugKytListpartsRequest;
        $req->setRefEntId($refEntId);
        $req->setEntName($entName);
        $req->setAuditFlag("1");
        $req->setPageSize("20");
        $req->setPage("1");

        $result = $this->execute($req);

        if (!$result['success']) {
            return null;
        }

        $respArray = json_decode(json_encode($result['data'], JSON_UNESCAPED_UNICODE), true);
        if (!isset($respArray['result']['model']['result_list']['p_ent_par_dto'])) {
            return null;
        }

        $dto = $respArray['result']['model']['result_list']['p_ent_par_dto'];
        // 单条结果 vs 多条结果
        if (isset($dto['par_ref_ent_id'])) {
            return [
                'ent_name' => $entName,
                'ent_id' => $dto['partner_ent_id'],
                'ref_ent_id' => $dto['par_ref_ent_id'],
            ];
        }
        if (isset($dto[0])) {
            return [
                'ent_name' => $entName,
                'ent_id' => $dto[0]['partner_ent_id'],
                'ref_ent_id' => $dto[0]['par_ref_ent_id'],
            ];
        }

        return null;
    }

    /**
     * 查询单据在平台的详情。
     *
     * @return array{found: bool, response: mixed, error: string}
     */
    public function searchBillDetail(string $billCode): array
    {
        $req = new \AlibabaAlihealthDrugKytSearchbillDetailRequest;
        $req->setBillCode($billCode);
        $req->setRefEntId(Config::get('REFENTID_HYYY'));

        $result = $this->execute($req);

        if (!$result['success']) {
            return ['found' => false, 'response' => $result['data'], 'error' => $result['error']];
        }

        $respArray = json_decode(json_encode($result['data'], JSON_UNESCAPED_UNICODE), true);

        return ['found' => self::isBillFound($respArray), 'response' => $respArray, 'error' => ''];
    }

    /**
     * 查单据在平台的上传详情（零售 `lsyd.query.upbilldetail`）。
     *
     * 与 `searchBillDetail()` 的差别只在接口与入参：这个是零售链路用的，且 **ref_ent_id 必传、
     * 没有默认值**（照 `queryEntInfo` 的先例）——用错主体的 ref_ent_id 会把单据判到别人名下，
     * 而"判在不在"这件事恰恰是按主体分的（同一个单号在甲店查到、在乙店查不到是两个独立问题）。
     *
     * 响应形状与判据与 searchbill.detail 同款（`isBillFound()` 两个接口共用；2026-10-05 用新江
     * 分店凭据实测：已上传 `msg_code=SUCCESS` + `response_success=true` + `model` 有单据详情，
     * 未上传 `msg_code=FAIL_BIZ_NO_PAT_INFO` + `msg_info=信息不存在`）。
     *
     * ⚠️ 调用方**必须先看 `error`**：查询失败时返回的是 `found=false` + 非空 error，
     * 不看 error 就把它当"未上传"处理，会把网络故障记成平台事实。
     *
     * @param string $refEntId 本企业 ref_ent_id（门店自己的那套凭据里的）
     * @return array{found: bool, response: mixed, error: string}
     */
    public function queryUpbillDetail(string $billCode, string $refEntId): array
    {
        $req = new \AlibabaAlihealthDrugtraceTopLsydQueryUpbilldetailRequest;
        $req->setBillCode($billCode);
        $req->setRefEntId($refEntId);

        $result = $this->execute($req);

        if (!$result['success']) {
            return ['found' => false, 'response' => $result['data'], 'error' => $result['error']];
        }

        $respArray = json_decode(json_encode($result['data'], JSON_UNESCAPED_UNICODE), true);

        return ['found' => self::isBillFound($respArray), 'response' => $respArray, 'error' => ''];
    }

    /**
     * 判定响应是否表明单据在平台存在（数量对账/状态查询用）。
     *
     * **两个接口共用**：kyt 的 `searchbill.detail` 与零售的 `lsyd.query.upbilldetail`——
     * 2026-10-05 实测两个接口的响应形状同款（未上传都是 `result.msg_code = FAIL_BIZ_NO_PAT_INFO`、
     * `msg_info = 信息不存在`），故判据不另写一份。
     *
     * 仅 msg_code=FAIL_BIZ_NO_PAT_INFO（信息不存在）视为未上传，其余响应（含其他业务错误码）
     * 均视为单据存在——平台"信息不存在"是未上传的唯一判定依据。
     *
     * @param array|null $respArray searchBillDetail()/queryUpbillDetail() 返回的 response
     *                               （已解码数组；查询失败时为 null，此时调用方应先看 error，见上）
     * @return bool true=单据在平台存在，false=信息不存在（未上传）
     */
    public static function isBillFound(?array $respArray): bool
    {
        return !(isset($respArray['result']['msg_code']) && $respArray['result']['msg_code'] === 'FAIL_BIZ_NO_PAT_INFO');
    }

    /**
     * 汇总 searchbilldetail 响应的平台申报数量（最小包装单位数，数量对账用）。
     *
     * 累加各药品行的 min_pkg_count（单药品=关联数组、多药品=列表两种结构都支持）。
     * 返回 null 表示无法核对：响应无明细结构（含信息不存在）、或任一行缺 min_pkg_count
     * ——缺字段不按 0 处理，防止解析异常伪装成"数量不符"差异。
     *
     * @param array|null $respArray searchBillDetail() 返回的 response（已解码数组，异常时为 null）
     * @return int|null 平台申报的最小包装单位总数；无法核对时为 null
     */
    public static function sumBillDetailCount(?array $respArray): ?int
    {
        $list = $respArray['result']['model']['bill_chk_in_out_detail_list_d_t_o_list']['billchkinoutdetaillistdtolist'] ?? null;
        if (!is_array($list) || empty($list)) {
            return null;
        }

        // 单药品=关联数组（含 min_pkg_count 等字段），多药品=列表
        if (isset($list['min_pkg_count'])) {
            $list = [$list];
        }

        $total = 0;
        foreach ($list as $item) {
            if (!is_array($item) || !isset($item['min_pkg_count']) || !is_numeric($item['min_pkg_count'])) {
                return null;
            }
            $total += (int)$item['min_pkg_count'];
        }

        return $total;
    }

    /**
     * 逐码查询 singlerelation（追溯码 → 平台最小溯源单位系数）。
     *
     * 码级对账第 2 级精查用（见 .scratch/quantity-check/singlerelation-tier2.md）：
     * 把本地追溯码折算成平台"最小溯源单位"的 pkg_amount（大包装码=100、最小单位码=1），
     * 使本地码列表能与平台 searchbill.detail 的 min_pkg_count 同口径求和对比，
     * 消除本地零售规格 ≠ 平台注册规格的结构性口径差异（ADR 0004 的遗留硬伤）。
     * 入参 ref_ent_id 与 des_ref_ent_id 均为河药自己（REFENTID_HYYY）。
     *
     * @return array{success: bool, data: mixed, error: string, is_network_error: bool}
     */
    public function searchSingleRelation(string $code): array
    {
        $req = new \AlibabaAlihealthDrugKytSinglerelationRequest;
        $req->setCode($code);
        $refEntId = Config::get('REFENTID_HYYY');
        $req->setRefEntId($refEntId);
        $req->setDesRefEntId($refEntId);

        return $this->execute($req);
    }

    /**
     * 汇总 singlerelation 响应的码级折算系数。
     *
     * 响应结构（2026-08-26 探针实测确认，存档 tests/singlerelation_<单号>.json）：
     *   result.model_list.code_relation_dto.is_smallest
     *   result.model_list.code_relation_dto.produce_info_list.produce_info_dto.pkg_amount
     *
     * 折算规则（2026-08-26 加固）：
     * - is_smallest="Y"（该码即平台最小溯源单位）→ 折算系数恒为 1，忽略 pkg_amount。
     *   反例实测：葡萄糖注射液 120瓶/箱 的箱码 is_smallest="Y" 但 pkg_amount="120"——
     *   120 是注册规格（整件只有大码、内部 120 个最小单位无追溯码，注射液类常见），
     *   不是可对账的单位数；平台 searchbill.detail 的 min_pkg_count 对该码按 1 计，
     *   码级折算必须同为 1 才同口径（否则"数量不符"误报）。is_smallest="Y" 且
     *   pkg_amount 缺失同样取 1（Y 本身即判定依据）。
     * - is_smallest 为 "N"/缺失 → 累加 pkg_amount（大包装码系数 >1，实测样本 213 码 → Σ 240）。
     * code_relation_dto 与 produce_info_dto 均兼容单条（关联数组）/多条（列表）两种形态。
     * 返回 null 表示无法核对：响应无明细结构、或 is_smallest 缺失且无 pkg_amount——
     * 缺字段不按 0 处理，防止解析异常伪装成"数量不符"差异。
     *
     * @param array|null $respArray searchSingleRelation() 返回的 data（已解码数组，异常时为 null）
     * @return int|null 该码折算成平台最小溯源单位的系数；无法核对时为 null
     */
    public static function sumPkgAmount(?array $respArray): ?int
    {
        $model = $respArray['result']['model_list'] ?? null;
        if (!is_array($model)) {
            return null;
        }

        // code_relation_dto: 单码=关联数组、多码=列表
        $rels = $model['code_relation_dto'] ?? null;
        if (isset($rels['produce_info_list'])) {
            $rels = [$rels];
        }
        if (!is_array($rels)) {
            return null;
        }

        $sum = 0;
        foreach ($rels as $rel) {
            if (!is_array($rel)) {
                continue;
            }
            // is_smallest=Y → 该码即最小溯源单位，折算 1（忽略 pkg_amount；见方法注释反例）
            if (($rel['is_smallest'] ?? null) === 'Y') {
                $sum += 1;
                continue;
            }
            $pis = $rel['produce_info_list'] ?? null;
            if (isset($pis['pkg_amount'])) {
                $pis = [$pis];
            }
            if (!is_array($pis)) {
                continue;
            }
            foreach ($pis as $pi) {
                if (is_array($pi) && isset($pi['pkg_amount']) && is_numeric($pi['pkg_amount'])) {
                    $sum += (int)$pi['pkg_amount'];
                }
            }
        }

        return $sum > 0 ? $sum : null;
    }
}
