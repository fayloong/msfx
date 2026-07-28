<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.getdruginfo.downloadurl request
 * 
 * @author auto create
 * @since 1.0, 2024.03.08
 */
class AlibabaAlihealthDrugKytGetdruginfoDownloadurlRequest
{
	/** 
	 * 调用接口的企业ID
	 **/
	private $refEntId;
	
	private $apiParas = array();
	
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
		return "alibaba.alihealth.drug.kyt.getdruginfo.downloadurl";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
		RequestCheckUtil::checkNotNull($this->refEntId,"refEntId");
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
