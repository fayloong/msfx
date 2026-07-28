<?php

/**
 * 返回列表
 * @author auto create
 */
class BillDealStatusSearchDo
{
	
	/** 
	 * 单号
	 **/
	public $bill_code;
	
	/** 
	 * 单据日期
	 **/
	public $bill_time;
	
	/** 
	 * 单据类型
	 **/
	public $bill_type;
	
	/** 
	 * 上传日期
	 **/
	public $crt_date;
	
	/** 
	 * 发货单位唯一标识
	 **/
	public $from_ref_user_id;
	
	/** 
	 * 发货单位主键
	 **/
	public $from_user_id;
	
	/** 
	 * 发货单位
	 **/
	public $from_user_name;
	
	/** 
	 * 操作人标识
	 **/
	public $ic_code;
	
	/** 
	 * 药品类型
	 **/
	public $physic_type;
	
	/** 
	 * 处理日期
	 **/
	public $process_date;
	
	/** 
	 * 处理结果表状态(暂不用)
	 **/
	public $process_flag;
	
	/** 
	 * 处理信息
	 **/
	public $process_info;
	
	/** 
	 * 用户唯一标识
	 **/
	public $ref_user_id;
	
	/** 
	 * 企业名称
	 **/
	public $ref_user_name;
	
	/** 
	 * 处理状态  0，处理中 1, 上传成功     3, 处理成功     4, 处理失败
	 **/
	public $result_type;
	
	/** 
	 * 角色类型
	 **/
	public $role_type;
	
	/** 
	 * 文件名
	 **/
	public $short_file_name;
	
	/** 
	 * 单据号
	 **/
	public $store_inout_seq_no;
	
	/** 
	 * 51全部成功 52部分成功
	 **/
	public $sub_process_flag;
	
	/** 
	 * 收货单位唯一标识
	 **/
	public $to_ref_user_id;
	
	/** 
	 * 收货单位主键
	 **/
	public $to_user_id;
	
	/** 
	 * 收货单位
	 **/
	public $to_user_name;
	
	/** 
	 * 上传文件名
	 **/
	public $upload_file_name;
	
	/** 
	 * 上传标识
	 **/
	public $upload_flag;
	
	/** 
	 * 用户主键
	 **/
	public $user_id;	
}
?>