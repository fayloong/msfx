<?php

/**
 * 最外层对象
 * @author auto create
 */
class BillUpOutDetailDto
{
	
	/** 
	 * 单据编码
	 **/
	public $bill_code;
	
	/** 
	 * 单据类型
	 **/
	public $bill_type;
	
	/** 
	 * 单据类型描述
	 **/
	public $bill_type_name;
	
	/** 
	 * 药品信息数据
	 **/
	public $drug_infos_dto_list;
	
	/** 
	 * 收货企业ref_ent_id
	 **/
	public $ent_recv_id;
	
	/** 
	 * 收货企业名称
	 **/
	public $ent_recv_name;
	
	/** 
	 * 发货企业的ref_ent_id
	 **/
	public $ent_send_id;
	
	/** 
	 * 发货企业名称
	 **/
	public $ent_send_name;
	
	/** 
	 * 单据日期
	 **/
	public $store_out_date;
	
	/** 
	 * 最后更新时间
	 **/
	public $update_date;	
}
?>