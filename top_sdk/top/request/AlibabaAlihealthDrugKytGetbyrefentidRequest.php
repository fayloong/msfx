<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.getbyrefentid request
 * 
 * @author auto create
 * @since 1.0, 2025.05.14
 */
class AlibabaAlihealthDrugKytGetbyrefentidRequest
{
	/** 
	 * 准备要查询的企业唯一标识（返回该唯一标识企业的详细信息）
	 **/
	private $destRefEntId;
	
	/** 
	 * 接口调用企业的唯一标识（接口调用者）
	 **/
	private $refEntId;
	
	private $apiParas = array();
	
	public function setDestRefEntId($destRefEntId)
	{
		$this->destRefEntId = $destRefEntId;
		$this->apiParas["dest_ref_ent_id"] = $destRefEntId;
	}

	public function getDestRefEntId()
	{
		return $this->destRefEntId;
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
		return "alibaba.alihealth.drug.kyt.getbyrefentid";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
		RequestCheckUtil::checkNotNull($this->destRefEntId,"destRefEntId");
		RequestCheckUtil::checkNotNull($this->refEntId,"refEntId");
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
