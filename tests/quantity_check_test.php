<?php
/**
 * App\ApiClient::isBillFound 单元测试（自包含断言脚本，无框架依赖）
 *
 * 运行: php tests/quantity_check_test.php
 *
 * 测试目标: 判定 searchbilldetail 响应是否表明单据在平台存在。
 * 平台"信息不存在"（msg_code=FAIL_BIZ_NO_PAT_INFO）是未上传的唯一判定依据；
 * 其余响应（SUCCESS、其他业务错误码、结构缺失）均视为单据存在——
 * 数量对账/状态检查据此区分"未上传"与"已上传"，不再比对申报数量。
 */

require __DIR__ . '/../vendor/autoload.php';

use App\ApiClient;

$failures = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    global $failures;
    if ($cond) {
        echo "PASS  $name\n";
    } else {
        $failures++;
        echo "FAIL  $name  $detail\n";
    }
}

/** 从内嵌 JSON 字符串解码出 API 响应数组 */
function resp(string $json): array
{
    return json_decode($json, true);
}

// ---------- 真实样例 1: 单据不存在（FAIL_BIZ_NO_PAT_INFO） ----------
$notFound = resp(<<<'JSON'
{"result":{"msg_code":"FAIL_BIZ_NO_PAT_INFO","msg_info":"信息不存在","response_success":"false"},"request_id":"15r2vlnf966ie"}
JSON);
check('信息不存在=未上传', ApiClient::isBillFound($notFound) === false);

// ---------- 真实样例 2: 查询成功（单药品明细，含 model） ----------
$single = resp(<<<'JSON'
{"result":{"model":{"bill_chk_in_out_detail_list_d_t_o_list":{"billchkinoutdetaillistdtolist":{"approve_no":"国药准字Z19990065","min_pkg_count":"12","min_preparations_count":"12","physic_name":"参芪扶正注射液 注射剂 250ml 1","physic_type":"3","physic_type_name":"普通药品","preparations_unit":"袋","prod_code":"9500001094","produce_date":"2025-12-31 00:00:00","produce_ent_name":"丽珠集团利民制药厂","product_batch_no":"S251252","temp_pkg_spec":"1袋/袋"}},"bill_code":"XSOWMS00983638","bill_time":"2026-07-29","bill_type":"201","from_ent_name":"河药医药（河源）有限公司","to_ent_name":"紫金钟运铭西医备案诊所"},"msg_code":"SUCCESS","msg_info":"调用成功","response_success":"true"},"request_id":"16l6jg8ozoiuk"}
JSON);
check('查询成功（单药品）=已上传', ApiClient::isBillFound($single) === true);

// ---------- 真实样例 3: 查询成功（多药品明细，列表结构） ----------
$multi = resp(<<<'JSON'
{"result":{"model":{"bill_chk_in_out_detail_list_d_t_o_list":{"billchkinoutdetaillistdtolist":[{"min_pkg_count":"1","physic_name":"荆防颗粒 颗粒剂 每袋15克"},{"min_pkg_count":"1","physic_name":"痔速宁片 片剂(糖衣) -"},{"min_pkg_count":"2","physic_name":"小牛血去蛋白提取物注射液 注射剂 5ml:0.2g"}]},"bill_code":"XSOWMS00998402","bill_time":"2026-08-17","bill_type":"201","from_ent_name":"河药医药（河源）有限公司","to_ent_name":"河源市源城区杨爱群西医诊所"},"msg_code":"SUCCESS","msg_info":"调用成功","response_success":"true"},"request_id":"16kdmnkwyvaqk"}
JSON);
check('查询成功（多药品）=已上传', ApiClient::isBillFound($multi) === true);

// ---------- 边界 1: 其他业务错误码（非信息不存在）不判为未上传 ----------
$otherError = resp(<<<'JSON'
{"result":{"msg_code":"FAIL","msg_info":"系统繁忙","response_success":"false"},"request_id":"test"}
JSON);
check('其他业务错误码=视为存在', ApiClient::isBillFound($otherError) === true);

// ---------- 边界 2: 结构缺失（无 result / 无 msg_code）不判为未上传 ----------
check('无 result=视为存在', ApiClient::isBillFound([]) === true);
check('result 无 msg_code=视为存在', ApiClient::isBillFound(['result' => []]) === true);

// ---------- 边界 3: null 输入（execute 失败时的语义，错误不判为未上传） ----------
check('null 输入=视为存在', ApiClient::isBillFound(null) === true);

echo "\n";
if ($failures === 0) {
    echo "全部通过 ✓\n";
    exit(0);
}
echo "失败 $failures 项 ✗\n";
exit(1);
