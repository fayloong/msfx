<?php

/**
 * 码关联关系
 * @author auto create
 */
class CodeInfo
{
	
	/** 
	 * 追溯码
	 **/
	public $code;
	
	/** 
	 * 码等级--展示等级 【相当于包装等级，1代表最大展示等级, 如：申请的包装比例是1:5:10, 对应的码展示等级就是 1、2、3, 代表大码、中码、小码】  
	 **/
	public $code_level;
	
	/** 
	 * 码等级【1代表最小码   如：申请的包装比例是1:5:10, 对应的码等级就是3、2、1, 代表大码、中码、小码】
	 **/
	public $code_pack_level;
	
	/** 
	 * 父码
	 **/
	public $parent_code;
	
	/** 
	 * 码状态（I核注O核销A激活C注销E错误码）
	 **/
	public $status;	
}
?>