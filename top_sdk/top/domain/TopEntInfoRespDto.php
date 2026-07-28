<?php

/**
 * 响应结果
 * @author auto create
 */
class TopEntInfoRespDto
{
	
	/** 
	 * 企业所在区县代码
	 **/
	public $area_code;
	
	/** 
	 * 企业所在区县名称
	 **/
	public $area_name;
	
	/** 
	 * 1-审核通过，0-审核中，2-审核不通过
	 **/
	public $audit_status;
	
	/** 
	 * 企业所在城市代码
	 **/
	public $city_code;
	
	/** 
	 * 企业所在城市名称
	 **/
	public $city_name;
	
	/** 
	 * 企业ID【ent_id】（单据上传时的收发货企业id就是填这个字段）
	 **/
	public $ent_id;
	
	/** 
	 * 企业名称
	 **/
	public $ent_name;
	
	/** 
	 * 唯一代码来源的资质代码（非精准）
	 **/
	public $lic_type_code;
	
	/** 
	 * 唯一代码来源的资质名称（非精准）
	 **/
	public $lic_type_name;
	
	/** 
	 * 企业所在省份代码
	 **/
	public $prov_code;
	
	/** 
	 * 企业所在省份名称
	 **/
	public $prov_name;
	
	/** 
	 * 企业唯一标识【ref_ent_id】（单据上传时的货主企业ref_user_id就是填这个字段）
	 **/
	public $ref_ent_id;
	
	/** 
	 * 企业注册详细地址
	 **/
	public $reg_region_detail;
	
	/** 
	 * 是否入驻，1-入驻企业，0-非入驻
	 **/
	public $settle_status;
	
	/** 
	 * 唯一代码
	 **/
	public $unique_code;	
}
?>