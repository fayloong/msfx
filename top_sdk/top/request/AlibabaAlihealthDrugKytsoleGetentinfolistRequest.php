<?php
/**
 * TOP API: alibaba.alihealth.drug.kytsole.getentinfolist request
 * 
 * @author auto create
 * @since 1.0, 2025.12.16
 */
class AlibabaAlihealthDrugKytsoleGetentinfolistRequest
{
	/** 
	 * 查询企业信息参数
	 **/
	private $queryParam;
	
	/** 
	 * refEntId
	 **/
	private $refEntId;
	
	private $apiParas = array();
	
	public function setQueryParam($queryParam)
	{
		$this->queryParam = $queryParam;
		$this->apiParas["query_param"] = $queryParam;
	}

	public function getQueryParam()
	{
		return $this->queryParam;
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
		return "alibaba.alihealth.drug.kytsole.getentinfolist";
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
