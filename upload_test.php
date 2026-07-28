<?php

include_once "TopSdk.php";
include_once "SqlSrvHelper.php";


$db = new SqlSrvHelper([
    'server'   => '192.168.2.82',
    'port'     => '1433',
    'database' => 'hyyy',
    'username' => 'sa',
    'password' => 'hy123.'
]);

$bill = $db ->query("select type,rq,djbh,erpbillcode,ent_name,trace_codes as sn from msfx_up_task where resp is null  ", []);


if (empty($bill)) {
    info_log('上传入库单： 没有需要上传的入库单', '');
    die;
}

/*
$bill_up = array();
foreach ($bill as $t) {
    $key = $t['rq'] . '|' .  $t['djbh'] . '|' . $t['erpbillcode'];
    if (!isset($bill_up[$key])) {
        $bill_up[$key] = [
            'type' => $t['type'],
            'rq' => $t['rq'],
            'djbh' => $t['djbh'],
            'ent_name' => $t['ent_name'],
            'erpbillcode' => $t['erpbillcode'],
            'sn' => []
        ];
    }
    $bill_up[$key]['sn'][] = $t['trace_codes'];
}

$bill_up = array_values($bill_up);
foreach ($bill_up as &$b) {
    $b['sn'] = array_unique($b['sn']); //去除重复
    $b['sn'] = implode(',', $b['sn']); //字符串拼接
}

*/
//print_r($bill);

upload($bill);

//企业上传出入库单

function upload($bill_up)
{
    $c = new TopClient;
    $c->appkey = appkey_hyyy;
    $c->secretKey = secretKey_hyyy;
    $setBillType = ['XSO' => '201', 'XST' => '103', 'JHG' => '102', 'JHO' => '202']; //单据类型
    $bill_count = count($bill_up);
    $up_id = 1;
    foreach ($bill_up as $v) {
        $req = new AlibabaAlihealthDrugKytUploadinoutbillRequest;
        $req->setBillCode($v['djbh'] ); //单号//. '_' . date("YmdHis")
        $req->setBillTime($v['rq']); //单据时间
        $req->setBillType($setBillType[$v['type']]); //单据类型：102, "采购入库"；103, "退货入库"；104, "调拨入库"；107, "供应入库"；108, "召回入库"；110,"赠品入库"；111,"盘盈入库"；112,"报废入库"；113,"其他入库" 201, "销售出库"；202, "退货出库"；203, "调拨出库"；204, "返工出库"；205, "销毁出库"；206, "抽检出库"；207, "直调出库"；209, "供应出库"；211, "召回出库"；212,"赠品出库"；214,"盘亏出库"；215,"损坏出库"；216,"报废出库"；217,"其他出库"；237, "直调退货"。
        $req->setPhysicType("3"); //药品类型【3普药2特药】89开头的码定义为特药，其它码定义成普药
        $req->setRefUserId(RefEntId_hyyy); //上传单据企业的单位编码ref_ent_id
        $entId = hht("select top 1 ent_id from ent_list where ent_name = ?", [$v['ent_name']]); //本地获取
        if (count($entId) === 0) {
            //echo '本地无数据，在线获取！';
            $entId_arr = get_ent_info($v['ent_name']);

            if (!isset($entId_arr['ent_id'])) {
                info_log("往来单位需要添加：", $v['ent_name']);
                continue;
            }
            $entId = $entId_arr['ent_id'];
            hht("insert into ent_list(ent_name,ent_id,ref_ent_id) values (?,?,?)", [$entId_arr['ent_name'], $entId_arr['ent_id'], $entId_arr['ref_ent_id']]);
        } elseif (count($entId) === 1) {
            //echo '取本地数据';
            $entId = $entId[0]['ent_id'];
        }


        if ($setBillType[$v['type']] === '201') { //销售出库
            $req->setToUserId($entId); //收货企业entId
            $req->setDisEntId(ent_id_hyyy); //药品配送企业entId
            $req->setFromUserId(ent_id_hyyy); //发货企业entId        
            //$req->setAssEntId(ent_id_dyt); //单据委托企业entId
        } elseif ($setBillType[$v['type']] === '102') { //采购入库
            $req->setToUserId(ent_id_hyyy); //收货企业entId
            //$req->setDisEntId(); //药品配送企业entId
            $req->setFromUserId($entId); //发货企业entId        
            //$req->setAssEntId(ent_id_dyt); //单据委托企业entId            
        } elseif ($setBillType[$v['type']] === '103') { //销售退回
            $req->setToUserId(ent_id_hyyy); //收货企业entId
            //$req->setDisEntId(ent_id_hyyy); //药品配送企业entId
            $req->setFromUserId($entId); //发货企业entId        
            //$req->setAssEntId(ent_id_dyt); //单据委托企业entId
        } elseif ($setBillType[$v['type']] === '202') { //采购退出
            $req->setToUserId($entId); //收货企业entId
            //$req->setDisEntId(ent_id_hyyy); //药品配送企业entId
            $req->setFromUserId(ent_id_hyyy); //发货企业entId        
            //$req->setAssEntId(ent_id_dyt); //单据委托企业entId
        }
        $req->setOperIcCode(appkey_hyyy); //单据提交者（appkey编号）可为空
        $req->setOperIcName(appkey_hyyy); //单据提交者姓名
        $req->setTraceCodes($v['sn']); //追溯码
        $req->setClientType("2"); //	客户端类型[必须填2]
        $resp = $c->execute($req);
        hht("update msfx_up_task set resp=? where djbh=?", [json_encode($resp, JSON_UNESCAPED_UNICODE), $v['djbh']]);
        $resp = json_decode(json_encode($resp, JSON_UNESCAPED_UNICODE), true);
        info_log("河药补上传[" . $v['rq'] . "],进度:[{$up_id}/{$bill_count}] 单号:", $req->getBillCode() . '请求返回:' . json_encode($resp, JSON_UNESCAPED_UNICODE));
        usleep(330000);
        $up_id++;
    }
}

function get_ent_info($ent_name)
{
    $c = new TopClient;
    $c->appkey = appkey_hyyy;
    $c->secretKey = secretKey_hyyy;
    try {
        $req = new AlibabaAlihealthDrugKytListpartsRequest;
        $req->setRefEntId(RefEntId_hyyy);
        $req->setEntName($ent_name);
        $req->setAuditFlag("1");
        $req->setPageSize("20");
        $req->setPage("1");
        $resp = $c->execute($req);
        $respArray = json_decode(json_encode($resp, JSON_UNESCAPED_UNICODE), true);
        if (isset($respArray['result']['model']['result_list']['p_ent_par_dto'])) {
            if (isset($respArray['result']['model']['result_list']['p_ent_par_dto']['par_ref_ent_id'])) {
                $buffer = ['ent_name' => $ent_name, "ref_ent_id" => $respArray['result']['model']['result_list']['p_ent_par_dto']['par_ref_ent_id'], "ent_id" => $respArray['result']['model']['result_list']['p_ent_par_dto']['partner_ent_id']];
            } else {
                $buffer = ['ent_name' => $ent_name, "ref_ent_id" => $respArray['result']['model']['result_list']['p_ent_par_dto'][0]['par_ref_ent_id'], "ent_id" => $respArray['result']['model']['result_list']['p_ent_par_dto'][0]['partner_ent_id']];
            }
        } else {
            $buffer[$ent_name] = ['ent_name' => $ent_name, 'ref_ent_id' => null, 'ent_id' => null];
            info_log("获取[{$ent_name}]失败:", json_encode($respArray, JSON_UNESCAPED_UNICODE));
        }
    } catch (Exception $e) {
        info_log("异常: ", $e->getMessage());
    }

    return $buffer;
}
