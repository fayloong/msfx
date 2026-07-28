<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.codereplace request
 * 
 * @author auto create
 * @since 1.0, 2025.05.14
 */
class AlibabaAlihealthDrugKytCodereplaceRequest
{
	/** 
	 * 替换后的追溯码
	 **/
	private $newCode;
	
	/** 
	 * 被替换的追溯码
	 **/
	private $oldCode;
	
	/** 
	 * 企业ref_ent_id（码申请企业）
	 **/
	private $refEntId;
	
	private $apiParas = array();
	
	public function setNewCode($newCode)
	{
		$this->newCode = $newCode;
		$this->apiParas["new_code"] = $newCode;
	}

	public function getNewCode()
	{
		return $this->newCode;
	}

	public function setOldCode($oldCode)
	{
		$this->oldCode = $oldCode;
		$this->apiParas["old_code"] = $oldCode;
	}

	public function getOldCode()
	{
		return $this->oldCode;
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
		return "alibaba.alihealth.drug.kyt.codereplace";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
		RequestCheckUtil::checkNotNull($this->newCode,"newCode");
		RequestCheckUtil::checkNotNull($this->oldCode,"oldCode");
		RequestCheckUtil::checkNotNull($this->refEntId,"refEntId");
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
