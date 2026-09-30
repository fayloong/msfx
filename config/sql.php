<?php
include_once __DIR__.'/../src/SqlSrvHelper.php';

$db=$db = new SqlSrvHelper([
    'server'   => '192.168.2.133',
    'port'     => '1433',
    'database' => 'hyyy',
    'username' => 'sa',
    'password' => 'xty123.'
]);

$get_up_task="
if OBJECT_ID('tempdb..#bill_list') is not null 
DROP table #bill_list

select distinct left(a.djbh,3) as type, 
a.rq,a.djbh,a.erpbillcode,bd.businessname as ent_name
into #bill_list
from skwms_new.dbo.v_pf_phlrhz a 
join skwms_new.dbo.mchk c on c.dwbh = a.dwbh
join hyyy_zyscm.dbo.businessdoc bd on bd.businessid=c.entdwbh
where 1=1 
and a.is_zx='是'
and a.rq >='2026-07-25' and a.rq<='2026-07-25'

union ALL

select  DISTINCT left(a.djbh,3) as type,a.rq,a.djbh,a.erpbillcode,bd.businessname  
from skwms_new.dbo.v_jzorder_hz a 
join skwms_new.dbo.mchk c on c.dwbh=a.dwbh
join hyyy_zyscm.dbo.businessdoc bd on bd.businessid=c.entdwbh
join skwms_new.dbo.v_sjdmx_mx d on d.ysdjbh=a.djbh  
where 1=1 
and a.is_zx='是'
and a.rq >='2026-07-25' and a.rq<='2026-07-25'


if OBJECT_ID('tempdb..#task_detail') is not null 
DROP table #task_detail

select  left(a.djbh,3) as type,
a.rq,a.djbh,a.erpbillcode,bd.businessname as ent_name,b.dzjgm as trace_codes

into #task_detail
from skwms_new.dbo.v_pf_phlrhz a 
join skwms_new.dbo.wms_dzjg b on b.djbh = a.djbh
join skwms_new.dbo.mchk c on c.dwbh = a.dwbh
join hyyy_zyscm.dbo.businessdoc bd on bd.businessid=c.entdwbh
where 1=1
and a.is_zx='是'
and exists(select  * from #bill_list x where x.djbh=a.djbh)

UNION ALL

select left(a.djbh,3) as type,a.rq,a.djbh,a.erpbillcode,bd.businessname  ,b.dzjgm
from skwms_new.dbo.v_jzorder_hz a 
join skwms_new.dbo.mchk c on c.dwbh=a.dwbh
join hyyy_zyscm.dbo.businessdoc bd on bd.businessid=c.entdwbh
join skwms_new.dbo.v_sjdmx_mx d on d.ysdjbh=a.djbh  
join skwms_new.dbo.wms_dzjg_rk b on b.djbh=d.ysdjbh and b.dj_sn=d.ydj_sn and b.spid=d.spid
where 1=1
and a.is_zx='是'
AND exists(select  * from #bill_list x where x.djbh=a.djbh)



select * from  #task_detail
";






$rows=$db->executeBatch($get_up_task);
print_r($rows);

//零售连锁门店
//
//⚠️ 探测残留，**别照抄本段**：写死单一 bill_type='203'（真实口径是四种）、缺 physic_type 列
// （补传装配要它）、按 bill_code 未去重（321 有完全重复行，会把追溯码放大最多 120 倍——现行脚本
// 把去重挪到 PHP 侧）。
// 它那句 NOT EXISTS(update_state) 的来龙去脉：ADR 0007（2026-09-29）曾判为去掉，2026-09-30 又因
// 测试阶段口径临时加回——现行口径一律以 scripts/fetch_bills_retail.php 为准。
$get_up_task_retail="
select ls.bill_code,ls.bill_time,ls.bill_type,ls.from_user_id,ls.to_user_id,ls.ref_ent_id,ls.oper_ic_name,co.trace_codes
from dyt.msfx.dbo.zsm_ls ls 
left join dyt.msfx.dbo.zsm_ls_code co on co.bill_code=ls.bill_code
where bill_type='203' --(104：调拨入库；203：调拨出库；321：使用出库；116：消费者退货入库)
AND not exists(select * from dyt.bs_msfx.dbo.update_state a where a.bill_code=ls.bill_code) 

";

$rows_retail=$db->executeBatch($get_up_task_retail);
print_r($rows_retail);