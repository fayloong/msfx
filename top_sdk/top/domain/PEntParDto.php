<?php

/**
 * 返回列表
 * @author auto create
 */
class PEntParDto
{
	
	/** 
	 * 往来单位所在市
	 **/
	public $area_name;
	
	/** 
	 * 往来单位审核状态：0-审核中；1-审核通过；2-审核不通过
	 **/
	public $audit_flag;
	
	/** 
	 * 往来单位所在县
	 **/
	public $city_name;
	
	/** 
	 * 添加到本企业往来单位列表日期
	 **/
	public $crt_date;
	
	/** 
	 * 创建IC码：废弃字段
	 **/
	public $crt_ic_code;
	
	/** 
	 * 创建IC名称：废弃字段
	 **/
	public $crt_ic_name;
	
	/** 
	 * 拓展属性
	 **/
	public $ent_extend;
	
	/** 
	 * 企业id：废弃字段
	 **/
	public $ent_id;
	
	/** 
	 * 往来单位企业所在省编码
	 **/
	public $ent_prov_code;
	
	/** 
	 * 是不是入网企业：1-是；0-不是
	 **/
	public $is_network;
	
	/** 
	 * 往来单位最近修改日期
	 **/
	public $last_mod_date;
	
	/** 
	 * 修改IC码：废弃字段
	 **/
	public $mod_ic_code;
	
	/** 
	 * 修改IC名称：废弃字段
	 **/
	public $mod_ic_name;
	
	/** 
	 * 记录ID
	 **/
	public $p_ent_par_id;
	
	/** 
	 * 往来单位企业refEntId
	 **/
	public $par_ref_ent_id;
	
	/** 
	 * 往来单位拼音缩写
	 **/
	public $partner_capital_name;
	
	/** 
	 * 往来单位企业entId
	 **/
	public $partner_ent_id;
	
	/** 
	 * 往来单位ID：企业自定义编号
	 **/
	public $partner_id;
	
	/** 
	 * 级别：废弃字段
	 **/
	public $partner_level;
	
	/** 
	 * 往来单位名称
	 **/
	public $partner_name;
	
	/** 
	 * 往来单位类型
	 **/
	public $partner_type;
	
	/** 
	 * 往来单位企业类型描述
	 **/
	public $partner_type_desc;
	
	/** 
	 * 往来单位所在省
	 **/
	public $prov_name;
	
	/** 
	 * 调用企业唯一标识
	 **/
	public $ref_ent_id;
	
	/** 
	 * 状态
	 **/
	public $status;	
}
?>