<?php

/**
 * 返回列表
 * @author auto create
 */
class DrugTableDto
{
	
	/** 
	 * 企业名称
	 **/
	public $ent_name;
	
	/** 
	 * 修改日期
	 **/
	public $mod_date;
	
	/** 
	 * 药品详细类型
	 **/
	public $physic_detail_type;
	
	/** 
	 * 药品类型详情描述
	 **/
	public $physic_detail_type_desc;
	
	/** 
	 * 药品名称
	 **/
	public $physic_name;
	
	/** 
	 * 药品类型(详见码表) 1：特殊药品原料药，2：特殊药品制剂，3：普通药品，9：未分类
	 **/
	public $physic_type;
	
	/** 
	 * 药品类型描述
	 **/
	public $physic_type_desc;
	
	/** 
	 * 包装单位描述
	 **/
	public $pkg_unit_desc;
	
	/** 
	 * 制剂类型描述
	 **/
	public $prepn_type_desc;
	
	/** 
	 * 制剂单位描述
	 **/
	public $prepn_unit_desc;
	
	/** 
	 * 药品自类编码
	 **/
	public $prod_code;
	
	/** 
	 * 商品名称
	 **/
	public $prod_name;
	
	/** 
	 * 企业主键
	 **/
	public $ref_ent_id;
	
	/** 
	 * 子列表
	 **/
	public $sub_type_list;	
}
?>