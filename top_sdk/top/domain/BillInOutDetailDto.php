<?php

/**
 * 对象模型信息
 * @author auto create
 */
class BillInOutDetailDto
{
	
	/** 
	 * 单据详情
	 **/
	public $bill_chk_in_out_detail_list_d_t_o_list;
	
	/** 
	 * 单据号码
	 **/
	public $bill_code;
	
	/** 
	 * 单据日期
	 **/
	public $bill_time;
	
	/** 
	 * 单据类型
	 **/
	public $bill_type;
	
	/** 
	 * 单据类型名称
	 **/
	public $bill_type_name;
	
	/** 
	 * 发货企业名称
	 **/
	public $from_ent_name;
	
	/** 
	 * 发货企业id
	 **/
	public $from_user_id;
	
	/** 
	 * 修改时间
	 **/
	public $mod_date;
	
	/** 
	 * 处理时间
	 **/
	public $process_date;
	
	/** 
	 * 收货企业名称
	 **/
	public $to_ent_name;
	
	/** 
	 * 收货企业id
	 **/
	public $to_user_id;	
}
?>