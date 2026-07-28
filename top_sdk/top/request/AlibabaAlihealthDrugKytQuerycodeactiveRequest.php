<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.querycodeactive request
 * 
 * @author auto create
 * @since 1.0, 2022.08.10
 */
class AlibabaAlihealthDrugKytQuerycodeactiveRequest
{
	/** 
	 * 码列表【多个码时用逗号拼接的字符串。要求数量在4000个码以下，但一般不要传这么多，如果网络不好很容易传输一半报错】
	 **/
	private $codes;
	
	/** 
	 * 企业唯一标识
	 **/
	private $refEntId;
	
	private $apiParas = array();
	
	public function setCodes($codes)
	{
		$this->codes = $codes;
		$this->apiParas["codes"] = $codes;
	}

	public function getCodes()
	{
		return $this->codes;
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
		return "alibaba.alihealth.drug.kyt.querycodeactive";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
		RequestCheckUtil::checkNotNull($this->codes,"codes");
		RequestCheckUtil::checkMaxListSize($this->codes,4000,"codes");
		RequestCheckUtil::checkNotNull($this->refEntId,"refEntId");
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
