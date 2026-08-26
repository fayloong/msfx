<?php

namespace App;

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
                    'is_network_error' => false,
                ];
            }

            return [
                'success' => true,
                'data' => $resp,
                'error' => '',
                'is_network_error' => false,
            ];
        } catch (\Exception $e) {
            // cURL 异常 → 网络错误
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
     * 查询往来单位信息。
     *
     * @return array{ent_name: string, ent_id: string, ref_ent_id: string}|null
     */
    public function queryEntInfo(string $entName): ?array
    {
        $req = new \AlibabaAlihealthDrugKytListpartsRequest;
        $req->setRefEntId(Config::get('REFENTID_HYYY'));
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
     * 判定 searchbilldetail 响应是否表明单据在平台存在（数量对账/状态查询用）。
     *
     * 仅 msg_code=FAIL_BIZ_NO_PAT_INFO（信息不存在）视为未上传，其余响应（含其他业务错误码）
     * 均视为单据存在——平台"信息不存在"是未上传的唯一判定依据。
     *
     * @param array|null $respArray searchBillDetail() 返回的 response（已解码数组，异常时为 null）
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
     * 汇总 singlerelation 响应的码级折算系数 pkg_amount。
     *
     * 响应结构（2026-08-26 探针实测确认，存档 tests/singlerelation_<单号>.json）：
     *   result.model_list.code_relation_dto.produce_info_list.produce_info_dto.pkg_amount
     * 实测（6片/盒氯雷他定片盒码）：is_smallest="Y"（该码即最小溯源单位）、
     * pkg_amount="1"（系数 1）；大包装码系数 >1（实测样本 213 码 → Σ 240）。
     * code_relation_dto 与 produce_info_dto 均兼容单条（关联数组）/多条（列表）两种形态。
     * 返回 null 表示无法核对：响应无明细结构、或任一条缺 pkg_amount——缺字段不按 0
     * 处理，防止解析异常伪装成"数量不符"差异。
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
