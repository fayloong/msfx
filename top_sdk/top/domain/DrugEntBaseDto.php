<?php

/**
 * 药品基本信息对象
 * @author auto create
 */
class DrugEntBaseDto
{
	
	/** 
	 * 批准文号
	 **/
	public $approval_licence_no;
	
	/** 
	 * 药品id
	 **/
	public $drug_ent_base_info_id;
	
	/** 
	 * 有效期
	 **/
	public $exprie;
	
	/** 
	 * 药品名称
	 **/
	public $physic_name;
	
	/** 
	 * 药品类型描述
	 **/
	public $physic_type_desc;
	
	/** 
	 * 小包下的制剂数量
	 **/
	public $pkg_num;
	
	/** 
	 * 包装规格
	 **/
	public $pkg_spec_crit;
	
	/** 
	 * 制剂规格
	 **/
	public $prepn_spec;
	
	/** 
	 * 剂型描述
	 **/
	public $prepn_type_desc;	
}
?>