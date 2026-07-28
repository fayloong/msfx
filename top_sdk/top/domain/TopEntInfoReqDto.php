<?php

/**
 * 查询企业信息参数
 * @author auto create
 */
class TopEntInfoReqDto
{
	
	/** 
	 * 查询参数：企业entId
	 **/
	public $ent_id;
	
	/** 
	 * 查询参数：企业名称，无其他查询条件时不能为空
	 **/
	public $ent_name;
	
	/** 
	 * 查询参数：诊所备案号或医疗单位登记号，无其他查询条件时不能为空
	 **/
	public $medical_code;
	
	/** 
	 * 查询参数：统一社会信用代码，无其他查询条件时不能为空
	 **/
	public $org_code;
	
	/** 
	 * 查询参数：企业refEntId
	 **/
	public $par_ref_ent_id;	
}
?>