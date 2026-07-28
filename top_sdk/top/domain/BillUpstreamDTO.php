<?php

/**
 * model
 * @author auto create
 */
class BillUpstreamDTO
{
	
	/** 
	 * 单据号
	 **/
	public $bill_code;
	
	/** 
	 * 单据时间
	 **/
	public $bill_time;
	
	/** 
	 * 单据类型
	 **/
	public $bill_type;
	
	/** 
	 * 发货企业REF_ENT_ID
	 **/
	public $from_ref_user_id;
	
	/** 
	 * 发货企业ID
	 **/
	public $from_user_id;
	
	/** 
	 * 发货企业名称
	 **/
	public $from_user_name;
	
	/** 
	 * 货主
	 **/
	public $ref_user_id;
	
	/** 
	 * 收货企业REF_ENT_ID
	 **/
	public $to_ref_user_id;
	
	/** 
	 * 收货企业ID
	 **/
	public $to_user_id;
	
	/** 
	 * 收货企业名称
	 **/
	public $to_user_name;	
}
?>