<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.getentinfo request
 * 
 * @author auto create
 * @since 1.0, 2025.11.19
 */
class AlibabaAlihealthDrugKytGetentinfoRequest
{
	/** 
	 * 企业名称
	 **/
	private $entName;
	
	private $apiParas = array();
	
	public function setEntName($entName)
	{
		$this->entName = $entName;
		$this->apiParas["ent_name"] = $entName;
	}

	public function getEntName()
	{
		return $this->entName;
	}

	public function getApiMethodName()
	{
		return "alibaba.alihealth.drug.kyt.getentinfo";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
		RequestCheckUtil::checkNotNull($this->entName,"entName");
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
