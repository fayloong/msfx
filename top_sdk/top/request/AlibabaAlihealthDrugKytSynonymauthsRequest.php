<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.synonymauths request
 * 
 * @author auto create
 * @since 1.0, 2025.05.14
 */
class AlibabaAlihealthDrugKytSynonymauthsRequest
{
	/** 
	 * 企业名称
	 **/
	private $entName;
	
	/** 
	 * 页面大小
	 **/
	private $page;
	
	/** 
	 * 页码
	 **/
	private $pageSize;
	
	/** 
	 * 企业ID
	 **/
	private $refEntId;
	
	/** 
	 * 货主自定义编号
	 **/
	private $synOwnEntId;
	
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

	public function setSynOwnEntId($synOwnEntId)
	{
		$this->synOwnEntId = $synOwnEntId;
		$this->apiParas["syn_own_ent_id"] = $synOwnEntId;
	}

	public function getSynOwnEntId()
	{
		return $this->synOwnEntId;
	}

	public function getApiMethodName()
	{
		return "alibaba.alihealth.drug.kyt.synonymauths";
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
