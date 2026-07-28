<?php

/**
 * 企业注册地址省市区信息
 * @author auto create
 */
class Address
{
	
	/** 
	 * 境内填写区县名称/境外则填写境外国家中文名称
	 **/
	public $area_name;
	
	/** 
	 * 城市名称/境外不用填，境内必填
	 **/
	public $city_name;
	
	/** 
	 * 省份名称/境外不用填，境内必填
	 **/
	public $prov_name;	
}
?>