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
        $found = !(isset($respArray['result']['msg_code']) && $respArray['result']['msg_code'] === 'FAIL_BIZ_NO_PAT_INFO');

        return ['found' => $found, 'response' => $respArray, 'error' => ''];
    }

    /**
     * 从 searchbilldetail 响应解析单据申报的追溯码数量（数量对账用）。
     *
     * 结构: result.model.bill_chk_in_out_detail_list_d_t_o_list.billchkinoutdetaillistdtolist
     *   - 单药品: 关联数组（键为字段名），取 min_pkg_count
     *   - 多药品: 列表（键 0,1,2...），各药品 min_pkg_count 累加
     *   - 单据不存在（FAIL_BIZ_NO_PAT_INFO）/ 结构缺失: 0
     *
     * @param array|null $respArray searchBillDetail() 返回的 response（已解码数组，异常时为 null）
     * @return int 单据申报的追溯码总数
     */
    public static function sumBillDetailCount(?array $respArray): int
    {
        $dto = $respArray['result']['model']['bill_chk_in_out_detail_list_d_t_o_list']['billchkinoutdetaillistdtolist'] ?? null;
        if (!is_array($dto) || empty($dto)) {
            return 0;
        }

        // 单药品: 关联数组（键为字段名）
        if (isset($dto['physic_name'])) {
            return (int)($dto['min_pkg_count'] ?? 0);
        }

        // 多药品: 列表，累加各项
        $sum = 0;
        foreach ($dto as $item) {
            if (is_array($item)) {
                $sum += (int)($item['min_pkg_count'] ?? 0);
            }
        }
        return $sum;
    }
}
