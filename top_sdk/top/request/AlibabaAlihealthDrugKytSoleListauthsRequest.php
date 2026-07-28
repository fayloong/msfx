<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.sole.listauths request
 * 
 * @author auto create
 * @since 1.0, 2025.12.02
 */
class AlibabaAlihealthDrugKytSoleListauthsRequest
{
	/** 
	 * 企业名称
	 **/
	private $entName;
	
	/** 
	 * 页码
	 **/
	private $page;
	
	/** 
	 * 页大小
	 **/
	private $pageSize;
	
	/** 
	 * 企业ID
	 **/
	private $refEntId;
	
	private $apiParas = array();
	
	public function setEntName($entName)
	{
		$this->entName = $entName;
		$this->apiParas["ent_name"] = $entName;
	}

	public function getEntName()
	{
		return $this->entName;
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

	public function setPageSize($pageSize)
	{
		$this->pageSize = $pageSize;
		$this->apiParas["page_size"] = $pageSize;
	}

	public function getPageSize()
	{
		return $this->pageSize;
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
		return "alibaba.alihealth.drug.kyt.sole.listauths";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
		RequestCheckUtil::checkNotNull($this->page,"page");
		RequestCheckUtil::checkNotNull($this->pageSize,"pageSize");
		RequestCheckUtil::checkNotNull($this->refEntId,"refEntId");
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
