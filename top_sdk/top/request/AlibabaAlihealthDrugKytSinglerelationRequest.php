<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.singlerelation request
 * 
 * @author auto create
 * @since 1.0, 2025.12.04
 */
class AlibabaAlihealthDrugKytSinglerelationRequest
{
	/** 
	 * 追溯码
	 **/
	private $code;
	
	/** 
	 * 目标企业唯一标识（为哪个企业查询，一般与入参ref_ent_id一样）
	 **/
	private $desRefEntId;
	
	/** 
	 * 接口调用企业的唯一标识（接口调用者）
	 **/
	private $refEntId;
	
	private $apiParas = array();
	
	public function setCode($code)
	{
		$this->code = $code;
		$this->apiParas["code"] = $code;
	}

	public function getCode()
	{
		return $this->code;
	}

	public function setDesRefEntId($desRefEntId)
	{
		$this->desRefEntId = $desRefEntId;
		$this->apiParas["des_ref_ent_id"] = $desRefEntId;
	}

	public function getDesRefEntId()
	{
		return $this->desRefEntId;
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
		return "alibaba.alihealth.drug.kyt.singlerelation";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
		RequestCheckUtil::checkNotNull($this->code,"code");
		RequestCheckUtil::checkNotNull($this->desRefEntId,"desRefEntId");
		RequestCheckUtil::checkNotNull($this->refEntId,"refEntId");
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
