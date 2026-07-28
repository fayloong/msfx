<?php

/**
 * 返回对象
 * @author auto create
 */
class PUserEntInfoDto
{
	
	/** 
	 * 市
	 **/
	public $area_name;
	
	/** 
	 * 县
	 **/
	public $city_name;
	
	/** 
	 * 所在地编码
	 **/
	public $dict_region_code;
	
	/** 
	 * 所在地明细
	 **/
	public $dict_region_detail;
	
	/** 
	 * 所属管理机构
	 **/
	public $direct_manage;
	
	/** 
	 * 拼音缩写
	 **/
	public $ent_capital_name;
	
	/** 
	 * 企业id
	 **/
	public $ent_id;
	
	/** 
	 * 企业名称
	 **/
	public $ent_name;
	
	/** 
	 * 企业机构详细类别
	 **/
	public $ent_org_type;
	
	/** 
	 * 是否入网
	 **/
	public $is_network;
	
	/** 
	 * 是否法人
	 **/
	public $legal_org_flag;
	
	/** 
	 * 注册地明细
	 **/
	public $org_code;
	
	/** 
	 * 省
	 **/
	public $prov_name;
	
	/** 
	 * 企业唯一标识
	 **/
	public $ref_ent_id;
	
	/** 
	 * 注册地编码
	 **/
	public $reg_region_code;
	
	/** 
	 * 所在地明细
	 **/
	public $reg_region_detail;
	
	/** 
	 * 状态1.使用中0.已废除
	 **/
	public $status;
	
	/** 
	 * 企业类型
	 **/
	public $user_role_type;
	
	/** 
	 * 企业类型编码
	 **/
	public $user_role_type_str;	
}
?>