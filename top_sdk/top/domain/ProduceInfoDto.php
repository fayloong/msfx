<?php

/**
 * 生产信息集合
 * @author auto create
 */
class ProduceInfoDto
{
	
	/** 
	 * 批次号
	 **/
	public $batch_no;
	
	/** 
	 * 有效期至
	 **/
	public $expire_date;
	
	/** 
	 * 有效期
	 **/
	public $original_expire_date;
	
	/** 
	 * 生产日期
	 **/
	public $original_produce_date;
	
	/** 
	 * 最小包装数量
	 **/
	public $pkg_amount;
	
	/** 
	 * 生产日期
	 **/
	public $produce_date_str;	
}
?>