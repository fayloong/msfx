<?php

/**
 * 内层大对象
 * @author auto create
 */
class CodeFullInfoDto
{
	
	/** 
	 * 追溯码
	 **/
	public $code;
	
	/** 
	 * 码生产信息对象
	 **/
	public $code_produce_info_d_t_o;
	
	/** 
	 * 码对象
	 **/
	public $code_status_type_d_t_o;
	
	/** 
	 * 药品基本信息对象
	 **/
	public $drug_ent_base_d_t_o;
	
	/** 
	 * 企业信息对象
	 **/
	public $p_user_ent_d_t_o;
	
	/** 
	 * 码包装层级，1代表最小包装。如：申请的包装比例是1:5:10, 对应的包装等级就是 3、2、1，代表大包装、中包装、小包装
	 **/
	public $package_level;	
}
?>