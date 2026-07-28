<?php

/**
 * 返回列表
 * @author auto create
 */
class LogCodeReplaceDTO
{
	
	/** 
	 * 码级别
	 **/
	public $code_level;
	
	/** 
	 * 药品ID
	 **/
	public $drug_ent_base_info_id;
	
	/** 
	 * 主键
	 **/
	public $id;
	
	/** 
	 * 操作时间
	 **/
	public $oper_date;
	
	/** 
	 * 操作人
	 **/
	public $oper_ic_code;
	
	/** 
	 * 新码
	 **/
	public $piats_code_new;
	
	/** 
	 * 旧码
	 **/
	public $piats_code_old;
	
	/** 
	 * 企业ID
	 **/
	public $ref_ent_id;	
}
?>