<?php

/**
 * model
 * @author auto create
 */
class CodeRelationDto
{
	
	/** 
	 * 药品基础信息
	 **/
	public $base_infos_d_t_o;
	
	/** 
	 * 86678530000000375020
	 **/
	public $code;
	
	/** 
	 * 激活信息
	 **/
	public $code_active_info_d_t_o;
	
	/** 
	 * 码关联关系
	 **/
	public $code_relation_list;
	
	/** 
	 * 码异常的错误信息code
	 **/
	public $error_code;
	
	/** 
	 * 码异常的错误信息
	 **/
	public $error_info;
	
	/** 
	 * 是否是最小包装
	 **/
	public $is_smallest;
	
	/** 
	 * 药品包装信息
	 **/
	public $pkg_info_d_t_o;
	
	/** 
	 * 生产信息
	 **/
	public $produce_info_list;	
}
?>