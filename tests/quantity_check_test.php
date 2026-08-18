<?php
/**
 * App\ApiClient 单元测试（自包含断言脚本，无框架依赖）
 *
 * 运行: php tests/quantity_check_test.php
 *
 * 测试目标:
 * 1. isBillFound: 判定 searchbilldetail 响应是否表明单据在平台存在。
 *    平台"信息不存在"（msg_code=FAIL_BIZ_NO_PAT_INFO）是未上传的唯一判定依据；
 *    其余响应（SUCCESS、其他业务错误码、结构缺失）均视为单据存在。
 * 2. sumBillDetailCount: 汇总平台申报的最小包装单位数（min_pkg_count 累加）。
 *    返回 null = 无法核对（无明细结构/任一行缺 min_pkg_count），不按 0 处理。
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

// ================= sumBillDetailCount: 平台申报数量汇总 =================

// ---------- 真实样例 1: 单药品明细（关联数组结构，min_pkg_count=12） ----------
check('单药品累加正确', ApiClient::sumBillDetailCount($single) === 12);

// ---------- 真实样例 2: 多药品明细（列表结构，1+1+2=4） ----------
check('多药品累加正确', ApiClient::sumBillDetailCount($multi) === 4);

// ---------- 真实拆分场景: JHOWMS00012659 子单（存档 tests/searchbill_JHOWMS00012659_1.json）
// 以下 JSON 自真实响应裁剪（仅保留与数量断言相关的 min_pkg_count/physic_name 字段） ----------
$split1 = resp(<<<'JSON'
{"result":{"model":{"bill_chk_in_out_detail_list_d_t_o_list":{"billchkinoutdetaillistdtolist":[{"min_pkg_count":"94","physic_name":"复方百部止咳颗粒 颗粒剂 每袋装10g(相当于原药材6g)"},{"min_pkg_count":"140","physic_name":"蒲公英颗粒 颗粒剂 每袋装5g"},{"min_pkg_count":"120","physic_name":"复方百部止咳颗粒 颗粒剂 每袋装10g(相当于原药材6g)"},{"min_pkg_count":"212","physic_name":"蒲公英颗粒 颗粒剂 每袋装5g"},{"min_pkg_count":"264","physic_name":"头孢克洛干混悬剂 干混悬剂 0.125g（按C15H14ClN3O4S计算）"},{"min_pkg_count":"447","physic_name":"盐酸羟甲唑啉喷雾剂 喷雾剂 10ml:5mg"},{"min_pkg_count":"112","physic_name":"复方百部止咳颗粒 颗粒剂 每袋装10g(相当于原药材6g)"},{"min_pkg_count":"300","physic_name":"头孢克洛干混悬剂 干混悬剂 0.125g（按C15H14ClN3O4S计算）"},{"min_pkg_count":"815","physic_name":"肤痒颗粒 颗粒剂 每袋装9g"},{"min_pkg_count":"696","physic_name":"盐酸羟甲唑啉喷雾剂 喷雾剂 10ml:5mg"},{"min_pkg_count":"300","physic_name":"头孢克洛干混悬剂 干混悬剂 0.125g（按C15H14ClN3O4S计算）"}]},"bill_code":"JHOWMS00012659_1","bill_time":"2026-07-29","bill_type":"202"},"msg_code":"SUCCESS","msg_info":"调用成功","response_success":"true"},"request_id":"16kjfkak084nn"}
JSON);
check('真实拆分子单 _1 累加=3500', ApiClient::sumBillDetailCount($split1) === 3500);

$split2 = resp(<<<'JSON'
{"result":{"model":{"bill_chk_in_out_detail_list_d_t_o_list":{"billchkinoutdetaillistdtolist":[{"min_pkg_count":"268","physic_name":"穿王消炎胶囊 胶囊剂 每粒装0.4g"},{"min_pkg_count":"210","physic_name":"穿王消炎胶囊 胶囊剂 每粒装0.4g"},{"min_pkg_count":"286","physic_name":"穿王消炎胶囊 胶囊剂 每粒装0.4g"},{"min_pkg_count":"290","physic_name":"红霉素眼膏 眼膏剂 0.5%"},{"min_pkg_count":"400","physic_name":"红霉素眼膏 眼膏剂 0.5%"},{"min_pkg_count":"60","physic_name":"盐酸氨溴索口服溶液 溶液剂 0.3%"},{"min_pkg_count":"299","physic_name":"头孢呋辛酯片 片剂 0.125g(按C16H16N4O8S计)"},{"min_pkg_count":"50","physic_name":"穿王消炎胶囊 胶囊剂 每粒装0.4g"},{"min_pkg_count":"101","physic_name":"小儿麻甘颗粒 颗粒剂 每袋装10g;每袋装2.5g"},{"min_pkg_count":"1704","physic_name":"盐酸羟甲唑啉喷雾剂 喷雾剂 10ml:5mg"}]},"bill_code":"JHOWMS00012659_2","bill_time":"2026-07-29","bill_type":"202"},"msg_code":"SUCCESS","msg_info":"调用成功","response_success":"true"},"request_id":"16kjfkak083nn"}
JSON);
check('真实拆分子单 _2 累加=3668', ApiClient::sumBillDetailCount($split2) === 3668);

// ---------- 边界 1: 无明细结构（信息不存在响应）→ null（无法核对） ----------
check('信息不存在=null（无法核对）', ApiClient::sumBillDetailCount($notFound) === null);

// ---------- 边界 2: 空响应/结构缺失 → null ----------
check('空数组=null', ApiClient::sumBillDetailCount([]) === null);
check('result 无 model=null', ApiClient::sumBillDetailCount(['result' => ['msg_code' => 'SUCCESS']]) === null);
check('明细为空列表=null', ApiClient::sumBillDetailCount(['result' => ['model' => ['bill_chk_in_out_detail_list_d_t_o_list' => ['billchkinoutdetaillistdtolist' => []]]]]) === null);
check('null 输入=null', ApiClient::sumBillDetailCount(null) === null);

// ---------- 边界 3: 任一行缺 min_pkg_count → null（缺字段不按 0 处理） ----------
$missingField = resp(<<<'JSON'
{"result":{"model":{"bill_chk_in_out_detail_list_d_t_o_list":{"billchkinoutdetaillistdtolist":[{"min_pkg_count":"1","physic_name":"荆防颗粒 颗粒剂 每袋15克"},{"physic_name":"痔速宁片 片剂(糖衣) -"}]}},"msg_code":"SUCCESS","msg_info":"调用成功","response_success":"true"},"request_id":"test"}
JSON);
check('任一行缺 min_pkg_count=null', ApiClient::sumBillDetailCount($missingField) === null);

// ---------- 边界 4: min_pkg_count 非数字（空字符串）→ null ----------
$emptyCount = resp(<<<'JSON'
{"result":{"model":{"bill_chk_in_out_detail_list_d_t_o_list":{"billchkinoutdetaillistdtolist":{"min_pkg_count":"","physic_name":"测试药品"}}},"msg_code":"SUCCESS","msg_info":"调用成功","response_success":"true"},"request_id":"test"}
JSON);
check('min_pkg_count 空字符串=null', ApiClient::sumBillDetailCount($emptyCount) === null);

echo "\n";
if ($failures === 0) {
    echo "全部通过 ✓\n";
    exit(0);
}
echo "失败 $failures 项 ✗\n";
exit(1);
