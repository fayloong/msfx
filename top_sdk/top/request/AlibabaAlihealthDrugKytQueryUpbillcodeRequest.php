<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.query.upbillcode request
 * 
 * @author auto create
 * @since 1.0, 2024.02.29
 */
class AlibabaAlihealthDrugKytQueryUpbillcodeRequest
{
	/** 
	 * 追溯码
	 **/
	private $code;
	
	/** 
	 * 企业REF_ENT_ID （当前企业的唯一标识）
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
		return "alibaba.alihealth.drug.kyt.query.upbillcode";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
