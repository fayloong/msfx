<?php
/**
 * App\ApiClient::sumBillDetailCount 单元测试（自包含断言脚本，无框架依赖）
 *
 * 运行: php tests/quantity_check_test.php
 *
 * 测试目标: 从 searchbilldetail 的 API 响应解析单据申报的追溯码数量。
 * 响应结构（来自真实平台数据）:
 *   result.model.bill_chk_in_out_detail_list_d_t_o_list.billchkinoutdetaillistdtolist
 *   - 单药品: 关联数组（键为字段名）
 *   - 多药品: 列表（键 0,1,2...），各药品 min_pkg_count 累加
 *   - 单据不存在: msg_code=FAIL_BIZ_NO_PAT_INFO，无 model
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

// ---------- 真实样例 1: 单药品（billchkinoutdetaillistdtolist 为关联数组） ----------
$single = resp(<<<'JSON'
{"result":{"model":{"bill_chk_in_out_detail_list_d_t_o_list":{"billchkinoutdetaillistdtolist":{"approve_no":"国药准字Z19990065","min_pkg_count":"12","min_preparations_count":"12","physic_name":"参芪扶正注射液 注射剂 250ml 1","physic_type":"3","physic_type_name":"普通药品","preparations_unit":"袋","prod_code":"9500001094","produce_date":"2025-12-31 00:00:00","produce_ent_name":"丽珠集团利民制药厂","product_batch_no":"S251252","temp_pkg_spec":"1袋/袋"}},"bill_code":"XSOWMS00983638","bill_time":"2026-07-29","bill_type":"201","from_ent_name":"河药医药（河源）有限公司","to_ent_name":"紫金钟运铭西医备案诊所"},"msg_code":"SUCCESS","msg_info":"调用成功","response_success":"true"},"request_id":"16l6jg8ozoiuk"}
JSON);
check('单药品汇总=12', ApiClient::sumBillDetailCount($single) === 12, '实际 ' . ApiClient::sumBillDetailCount($single));

// ---------- 真实样例 2: 多药品（列表 10 项，min_pkg_count 累加 = 27） ----------
$multi = resp(<<<'JSON'
{"result":{"model":{"bill_chk_in_out_detail_list_d_t_o_list":{"billchkinoutdetaillistdtolist":[{"min_pkg_count":"1","physic_name":"荆防颗粒 颗粒剂 每袋15克"},{"min_pkg_count":"1","physic_name":"痔速宁片 片剂(糖衣) -"},{"min_pkg_count":"2","physic_name":"小牛血去蛋白提取物注射液 注射剂 5ml:0.2g"},{"min_pkg_count":"5","physic_name":"地塞米松磷酸钠注射液 注射剂 1ml:5mg"},{"min_pkg_count":"1","physic_name":"奥美拉唑肠溶胶囊 胶囊剂 20mg"},{"min_pkg_count":"10","physic_name":"维C银翘片 片剂"},{"min_pkg_count":"2","physic_name":"复方桔梗止咳片 片剂(糖衣,素)"},{"min_pkg_count":"1","physic_name":"维生素B6注射液 注射剂 2ml:0.1g"},{"min_pkg_count":"2","physic_name":"马来酸氯苯那敏注射液 注射剂 1ml:10mg"},{"min_pkg_count":"2","physic_name":"通宣理肺片 片剂(薄膜衣)"}]},"bill_code":"XSOWMS00998402","bill_time":"2026-08-17","bill_type":"201","from_ent_name":"河药医药（河源）有限公司","to_ent_name":"河源市源城区杨爱群西医诊所"},"msg_code":"SUCCESS","msg_info":"调用成功","response_success":"true"},"request_id":"16kdmnkwyvaqk"}
JSON);
check('多药品 10 项汇总=27', ApiClient::sumBillDetailCount($multi) === 27, '实际 ' . ApiClient::sumBillDetailCount($multi));

// ---------- 真实样例 3: 多药品 2 项（列表，min_pkg_count 累加 = 15） ----------
$multi2 = [
    'result' => ['model' => ['bill_chk_in_out_detail_list_d_t_o_list' => ['billchkinoutdetaillistdtolist' => [
        ['min_pkg_count' => '10', 'physic_name' => '土霉素片 片剂 0.125g(125,000单位)'],
        ['min_pkg_count' => '5', 'physic_name' => '氯霉素片 片剂(糖衣) 0.125g'],
    ]]]],
];
check('多药品 2 项汇总=15', ApiClient::sumBillDetailCount($multi2) === 15, '实际 ' . ApiClient::sumBillDetailCount($multi2));

// ---------- 真实样例 4: 单据不存在（FAIL_BIZ_NO_PAT_INFO） ----------
$notFound = resp(<<<'JSON'
{"result":{"msg_code":"FAIL_BIZ_NO_PAT_INFO","msg_info":"信息不存在","response_success":"false"},"request_id":"15r2vlnf966ie"}
JSON);
check('单据不存在=0', ApiClient::sumBillDetailCount($notFound) === 0, '实际 ' . ApiClient::sumBillDetailCount($notFound));

// ---------- 边界 4: min_pkg_count 缺失/非数字按 0 处理 ----------
$missingCount = [
    'result' => ['model' => ['bill_chk_in_out_detail_list_d_t_o_list' => ['billchkinoutdetaillistdtolist' => [
        ['physic_name' => '无数量字段'],
        ['min_pkg_count' => '5'],
        ['min_pkg_count' => 'abc'],
        ['min_pkg_count' => null],
    ]]]],
];
check('数量缺失/非法按 0 处理=5', ApiClient::sumBillDetailCount($missingCount) === 5, '实际 ' . ApiClient::sumBillDetailCount($missingCount));

// ---------- 边界 5: 结构缺失（无 model / 无明细列表 / 空数组） ----------
check('无 model=0', ApiClient::sumBillDetailCount([]) === 0);
check('model 无明细=0', ApiClient::sumBillDetailCount(['result' => ['model' => []]]) === 0);
check('空明细列表=0', ApiClient::sumBillDetailCount(['result' => ['model' => ['bill_chk_in_out_detail_list_d_t_o_list' => ['billchkinoutdetaillistdtolist' => []]]]]) === 0);

// ---------- 边界 6: 异常返回（execute 失败时的 data=null 语义） ----------
check('null 输入=0', ApiClient::sumBillDetailCount(null) === 0);

echo "\n";
if ($failures === 0) {
    echo "全部通过 ✓\n";
    exit(0);
}
echo "失败 $failures 项 ✗\n";
exit(1);
