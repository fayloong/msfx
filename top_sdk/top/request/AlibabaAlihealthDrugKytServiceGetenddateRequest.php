<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.service.getenddate request
 * 
 * @author auto create
 * @since 1.0, 2024.03.08
 */
class AlibabaAlihealthDrugKytServiceGetenddateRequest
{
	/** 
	 * 行业线 1：药，2：非药
	 **/
	private $business;
	
	/** 
	 * 调用接口的企业ID
	 **/
	private $refEntId;
	
	/** 
	 * 基础版：11 高级版 ：12
	 **/
	private $service;
	
	private $apiParas = array();
	
	public function setBusiness($business)
	{
		$this->business = $business;
		$this->apiParas["business"] = $business;
	}

	public function getBusiness()
	{
		return $this->business;
	}

	public function setRefEntId($refEntId)
	{
		$this->refEntId = $refEntId;
		$this->apiParas["ref_ent_id"] = $refEntId;
	}

	public function getRefEntId()
	{
		return $this->refEntId;
	}

	public function setService($service)
	{
		$this->service = $service;
		$this->apiParas["service"] = $service;
	}

	public function getService()
	{
		return $this->service;
	}

	public function getApiMethodName()
	{
		return "alibaba.alihealth.drug.kyt.service.getenddate";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
		RequestCheckUtil::checkNotNull($this->business,"business");
		RequestCheckUtil::checkNotNull($this->refEntId,"refEntId");
		RequestCheckUtil::checkNotNull($this->service,"service");
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
