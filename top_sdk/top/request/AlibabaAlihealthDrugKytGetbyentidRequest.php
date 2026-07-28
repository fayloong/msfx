<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.getbyentid request
 * 
 * @author auto create
 * @since 1.0, 2024.03.08
 */
class AlibabaAlihealthDrugKytGetbyentidRequest
{
	/** 
	 * 准备要查询的企业ID（返回该企业ID的详细信息）
	 **/
	private $entId;
	
	/** 
	 * 接口调用企业的唯一标识（接口调用者）
	 **/
	private $refEntId;
	
	private $apiParas = array();
	
	public function setEntId($entId)
	{
		$this->entId = $entId;
		$this->apiParas["ent_id"] = $entId;
	}

	public function getEntId()
	{
		return $this->entId;
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

	public function getApiMethodName()
	{
		return "alibaba.alihealth.drug.kyt.getbyentid";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
		RequestCheckUtil::checkNotNull($this->entId,"entId");
		RequestCheckUtil::checkNotNull($this->refEntId,"refEntId");
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
