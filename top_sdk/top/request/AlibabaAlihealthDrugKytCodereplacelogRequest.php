<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.codereplacelog request
 * 
 * @author auto create
 * @since 1.0, 2025.05.14
 */
class AlibabaAlihealthDrugKytCodereplacelogRequest
{
	/** 
	 * 开始时间（最大查询区间一年）
	 **/
	private $beginTime;
	
	/** 
	 * 追溯码（不区分新码、旧码）
	 **/
	private $code;
	
	/** 
	 * 药品ID
	 **/
	private $drugEntBaseInfoId;
	
	/** 
	 * 截至时间（最大查询区间一年）
	 **/
	private $endTime;
	
	/** 
	 * 页数（默认一页20条）
	 **/
	private $page;
	
	/** 
	 * 企业ref_ent_id（码申请企业）
	 **/
	private $refEntId;
	
	private $apiParas = array();
	
	public function setBeginTime($beginTime)
	{
		$this->beginTime = $beginTime;
		$this->apiParas["begin_time"] = $beginTime;
	}

	public function getBeginTime()
	{
		return $this->beginTime;
	}

	public function setCode($code)
	{
		$this->code = $code;
		$this->apiParas["code"] = $code;
	}

	public function getCode()
	{
		return $this->code;
	}

	public function setDrugEntBaseInfoId($drugEntBaseInfoId)
	{
		$this->drugEntBaseInfoId = $drugEntBaseInfoId;
		$this->apiParas["drug_ent_base_info_id"] = $drugEntBaseInfoId;
	}

	public function getDrugEntBaseInfoId()
	{
		return $this->drugEntBaseInfoId;
	}

	public function setEndTime($endTime)
	{
		$this->endTime = $endTime;
		$this->apiParas["end_time"] = $endTime;
	}

	public function getEndTime()
	{
		return $this->endTime;
	}

	public function setPage($page)
	{
		$this->page = $page;
		$this->apiParas["page"] = $page;
	}

	public function getPage()
	{
		return $this->page;
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
		return "alibaba.alihealth.drug.kyt.codereplacelog";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
		RequestCheckUtil::checkNotNull($this->beginTime,"beginTime");
		RequestCheckUtil::checkNotNull($this->endTime,"endTime");
		RequestCheckUtil::checkNotNull($this->page,"page");
		RequestCheckUtil::checkNotNull($this->refEntId,"refEntId");
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
