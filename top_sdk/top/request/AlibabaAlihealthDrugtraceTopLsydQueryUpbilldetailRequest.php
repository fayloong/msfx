<?php
/**
 * TOP API: alibaba.alihealth.drugtrace.top.lsyd.query.upbilldetail request
 * 
 * @author auto create
 * @since 1.0, 2026.02.25
 */
class AlibabaAlihealthDrugtraceTopLsydQueryUpbilldetailRequest
{
	/** 
	 * 单据号码
	 **/
	private $billCode;
	
	/** 
	 * 本企业refEntId
	 **/
	private $refEntId;
	
	private $apiParas = array();
	
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
		return "alibaba.alihealth.drugtrace.top.lsyd.query.upbilldetail";
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
