<?php

/**
 * 药品信息数据
 * @author auto create
 */
class DrugInfosDto
{
	
	/** 
	 * 码信息
	 **/
	public $code_info_list_dto_list;
	
	/** 
	 * 药品标识
	 **/
	public $drug_ent_base_info_id;
	
	/** 
	 * 按最小包装单位统计数量
	 **/
	public $least_pkg_amount;
	
	/** 
	 * 按最小制剂单位统计数量
	 **/
	public $least_prepn_amount;
	
	/** 
	 * 产品包装规格
	 **/
	public $package_spec;
	
	/** 
	 * 制剂单位描述
	 **/
	public $preparations_unit;
	
	/** 
	 * 制剂规格
	 **/
	public $prepn_spec;
	
	/** 
	 * 制剂单位编码
	 **/
	public $prepn_unit;
	
	/** 
	 * 药品商品名
	 **/
	public $prod_name;
	
	/** 
	 * 药品标识
	 **/
	public $prod_seq_no;
	
	/** 
	 * 批次号
	 **/
	public $produce_batch_no;
	
	/** 
	 * 生产日期
	 **/
	public $produce_date;
	
	/** 
	 * 生产企业名称
	 **/
	public $product_ent_name;
	
	/** 
	 * 有效期至
	 **/
	public $valid_end_date;	
}
?>