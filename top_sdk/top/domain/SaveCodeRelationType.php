<?php

/**
 * 关联关系文件信息
 * @author auto create
 */
class SaveCodeRelationType
{
	
	/** 
	 * 1药  3中药饮片  5医疗器材
	 **/
	public $business_type;
	
	/** 
	 * 上传日期(格式 2018-09-18)
	 **/
	public $crt_date;
	
	/** 
	 * 企业名
	 **/
	public $ent_name;
	
	/** 
	 * 上传文件的企业ID
	 **/
	public $ent_seq_no;
	
	/** 
	 * 操作的icCode
	 **/
	public $oper_ic_code;
	
	/** 
	 * 操作员姓名
	 **/
	public $oper_ic_name;
	
	/** 
	 * 上传关联关系的药品子类编码
	 **/
	public $prod_code;
	
	/** 
	 * 上传文件的文件名（建议填写一个长度小于500，用于后面查询）
	 **/
	public $upload_file_name;
	
	/** 
	 * 上传标记
	 **/
	public $upload_flag;
	
	/** 
	 * 用户证书
	 **/
	public $user_cert;	
}
?>