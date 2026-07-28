<?php
/**
 * TOP API: alibaba.alihealth.drug.bill.upbill.detail.withcode request
 * 
 * @author auto create
 * @since 1.0, 2025.07.23
 */
class AlibabaAlihealthDrugBillUpbillDetailWithcodeRequest
{
	/** 
	 * 委托企业id
	 **/
	private $agentRefEntId;
	
	/** 
	 * 单据编码
	 **/
	private $billCode;
	
	/** 
	 * 发货企业renEntId
	 **/
	private $fromRefUserId;
	
	/** 
	 * 企业id
	 **/
	private $refEntId;
	
	/** 
	 * 收货企业refEntId
	 **/
	private $toRefUserId;
	
	private $apiParas = array();
	
	public function setAgentRefEntId($agentRefEntId)
	{
		$this->agentRefEntId = $agentRefEntId;
		$this->apiParas["agent_ref_ent_id"] = $agentRefEntId;
	}

	public function getAgentRefEntId()
	{
		return $this->agentRefEntId;
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

	public function setFromRefUserId($fromRefUserId)
	{
		$this->fromRefUserId = $fromRefUserId;
		$this->apiParas["from_ref_user_id"] = $fromRefUserId;
	}

	public function getFromRefUserId()
	{
		return $this->fromRefUserId;
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

	public function setToRefUserId($toRefUserId)
	{
		$this->toRefUserId = $toRefUserId;
		$this->apiParas["to_ref_user_id"] = $toRefUserId;
	}

	public function getToRefUserId()
	{
		return $this->toRefUserId;
	}

	public function getApiMethodName()
	{
		return "alibaba.alihealth.drug.bill.upbill.detail.withcode";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
		RequestCheckUtil::checkNotNull($this->billCode,"billCode");
		RequestCheckUtil::checkNotNull($this->fromRefUserId,"fromRefUserId");
		RequestCheckUtil::checkNotNull($this->refEntId,"refEntId");
		RequestCheckUtil::checkNotNull($this->toRefUserId,"toRefUserId");
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
