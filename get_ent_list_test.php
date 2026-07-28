<?php

include_once "top_sdk/TopSdk.php";
include_once "db.php";


$c = new TopClient;
$c->appkey = appkey_hyyy;
$c->secretKey = secretKey_hyyy;

$page = 1;
$hasMore = true;

while ($hasMore) {
    info_log("获取往来单位:", "抓取第{$page}页");
    try {
        $req = new AlibabaAlihealthDrugKytListpartsRequest;
        $req->setRefEntId(RefEntId_hyyy);
        $req->setAuditFlag("1");
        $req->setPageSize("500");
        $req->setPage($page);
        $resp = $c->execute($req);
        $respArray = json_decode(json_encode($resp, JSON_UNESCAPED_UNICODE), true);
        if (isset($respArray['result']['model']['result_list']['p_ent_par_dto'])) {
            //获取total_num
            $total_num = $respArray['result']['model']['total_num'];
            if (isset($respArray['result']['model']['result_list']['p_ent_par_dto']['par_ref_ent_id'])) {
                $buffer[$respArray['result']['model']['result_list']['p_ent_par_dto']['partner_name']] = ["ref_ent_id" => $respArray['result']['model']['result_list']['p_ent_par_dto']['par_ref_ent_id'], "ent_id" => $respArray['result']['model']['result_list']['p_ent_par_dto']['partner_ent_id']];
            } else {
                foreach ($respArray['result']['model']['result_list']['p_ent_par_dto'] as $ent_info) {
                    $buffer[$ent_info['partner_name']] = ["ref_ent_id" => $ent_info['par_ref_ent_id'], "ent_id" => $ent_info['partner_ent_id']];
                }
            }
        } else {
            info_log("获取往来单位失败: ", json_encode($respArray, JSON_UNESCAPED_UNICODE));
            $hasMore = false;
        }
        info_log("第{$page}页抓取完成", "合计{$total_num}条");
    } catch (Exception $e) {
        info_log("异常: ", $e->getMessage());
    }

    usleep(330000);

    //插入数据库
    foreach ($buffer as $k => $v) {
        hht("insert into ent_list(ent_name,ent_id,ref_ent_id) values (?,?,?)", [$k, $v['ent_id'] ?? null, $v['ref_ent_id'] ?? null]);
    }
    info_log("第{$page}页{$total_num}条数据已插入数据库", "");

    //清空
    $buffer = [];

    //跳出循环条件
    if ($total_num < 500) {
        $hasMore = false;
    } else {
        $page++;
    }
}

