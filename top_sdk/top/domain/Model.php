<?php

/**
 * 具体返回值
 * @author auto create
 */
class Model
{
	
	/** 
	 * 新增成功还是失败，true：新增成功
	 **/
	public $add_sucess;
	
	/** 
	 * 新增失败的时候错误原因
	 **/
	public $check_msg;
	
	/** 
	 * 新增成功后分配的往来单位refEntId
	 **/
	public $par_ref_ent_id;	
}
?>