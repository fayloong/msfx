<?php

/**
 * 返回列表
 * @author auto create
 */
class BillProcessPartSuccessExcelDTO
{
	
	/** 
	 * 追溯码
	 **/
	public $code;
	
	/** 
	 * 错误类型
	 **/
	public $error_code;
	
	/** 
	 * 错误类型描述
	 **/
	public $error_code_desc;
	
	/** 
	 * 最后一次重新处理时间
	 **/
	public $last_process_date_desc;
	
	/** 
	 * 处理失败原因描述
	 **/
	public $process_status_reason_desc;	
}
?>