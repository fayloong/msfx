<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.searchbill.detail request
 * 
 * @author auto create
 * @since 1.0, 2025.08.21
 */
class AlibabaAlihealthDrugKytSearchbillDetailRequest
{
	/** 
	 * 货主/配送
	 **/
	private $authRefUserId;
	
	/** 
	 * 单据号码
	 **/
	private $billCode;
	
	/** 
	 * 企业refEntId
	 **/
	private $refEntId;
	
	private $apiParas = array();
	
	public function setAuthRefUserId($authRefUserId)
	{
		$this->authRefUserId = $authRefUserId;
		$this->apiParas["auth_ref_user_id"] = $authRefUserId;
	}

	public function getAuthRefUserId()
	{
		return $this->authRefUserId;
	}

	public function setBillCode($billCode)
	{
		$this->billCode = $billCode;
		$this->apiParas["bill_code"] = $billCode;
	}

	public function getBillCode()
	{
		return $this->billCode;
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
		return "alibaba.alihealth.drug.kyt.searchbill.detail";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
		RequestCheckUtil::checkNotNull($this->billCode,"billCode");
		RequestCheckUtil::checkNotNull($this->refEntId,"refEntId");
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
