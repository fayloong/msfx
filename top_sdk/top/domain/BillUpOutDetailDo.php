<?php

/**
 * 返回列表
 * @author auto create
 */
class BillUpOutDetailDo
{
	
	/** 
	 * 单据码
	 **/
	public $bill_code;
	
	/** 
	 * 单据ID
	 **/
	public $bill_out_id;
	
	/** 
	 * 单据时间
	 **/
	public $bill_time;
	
	/** 
	 * 单据时间格式化
	 **/
	public $bill_time_format;
	
	/** 
	 * 单据类型
	 **/
	public $bill_type;
	
	/** 
	 * 最小码量
	 **/
	public $code_count;
	
	/** 
	 * 药品ID
	 **/
	public $drug_ent_base_info_id;
	
	/** 
	 * 失效日期
	 **/
	public $exprie_date;
	
	/** 
	 * 失效日期格式化
	 **/
	public $exprie_date_format;
	
	/** 
	 * 发货单位
	 **/
	public $from_ent_name;
	
	/** 
	 * 发货单位REF_ENT_ID
	 **/
	public $from_ref_user_id;
	
	/** 
	 * 发货企业ent_id
	 **/
	public $from_user_id;
	
	/** 
	 * 收货企业
	 **/
	public $from_user_name;
	
	/** 
	 * 药品信息
	 **/
	public $physic_info;
	
	/** 
	 * 药品名称
	 **/
	public $physic_name;
	
	/** 
	 * 包装规格
	 **/
	public $pkg_spec;
	
	/** 
	 * 制剂数量
	 **/
	public $prepn_count;
	
	/** 
	 * 制剂规格
	 **/
	public $prepn_spec;
	
	/** 
	 * 制剂单位
	 **/
	public $prepn_unit;
	
	/** 
	 * 生产批号
	 **/
	public $produce_batch_no;
	
	/** 
	 * 生产日期
	 **/
	public $produce_date;
	
	/** 
	 * 生产日期格式化
	 **/
	public $produce_date_format;
	
	/** 
	 * 厂商
	 **/
	public $produce_ent_name;
	
	/** 
	 * 确认状态1未确认2已确认
	 **/
	public $status;
	
	/** 
	 * 收货单位REF_ENT_ID
	 **/
	public $to_ref_user_id;
	
	/** 
	 * 收货企业ent_id
	 **/
	public $to_user_id;
	
	/** 
	 * 发货企业
	 **/
	public $to_user_name;	
}
?>