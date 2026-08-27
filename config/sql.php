<?php
include_once __DIR__.'/../src/SqlSrvHelper.php';

$db=$db = new SqlSrvHelper([
    'server'   => '192.168.2.82',
    'port'     => '1433',
    'database' => 'hyyy',
    'username' => 'sa',
    'password' => 'hy123.'
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