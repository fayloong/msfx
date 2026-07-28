<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.drugtable request
 * 
 * @author auto create
 * @since 1.0, 2025.05.14
 */
class AlibabaAlihealthDrugKytDrugtableRequest
{
	/** 
	 * 批准文号
	 **/
	private $approvalLicenceNo;
	
	/** 
	 * 结束日期
	 **/
	private $endDate;
	
	/** 
	 * 企业名称
	 **/
	private $entName;
	
	/** 
	 * 包装规格
	 **/
	private $packageSpec;
	
	/** 
	 * 页码
	 **/
	private $page;
	
	/** 
	 * 页大小（最大每页查询条数100）
	 **/
	private $pageSize;
	
	/** 
	 * 药品通用名
	 **/
	private $physicName;
	
	/** 
	 * 制剂规格
	 **/
	private $prepnSpec;
	
	/** 
	 * 企业ID
	 **/
	private $refEntId;
	
	/** 
	 * 开始日期
	 **/
	private $startDate;
	
	private $apiParas = array();
	
	public function setApprovalLicenceNo($approvalLicenceNo)
	{
		$this->approvalLicenceNo = $approvalLicenceNo;
		$this->apiParas["approval_licence_no"] = $approvalLicenceNo;
	}

	public function getApprovalLicenceNo()
	{
		return $this->approvalLicenceNo;
	}

	public function setEndDate($endDate)
	{
		$this->endDate = $endDate;
		$this->apiParas["end_date"] = $endDate;
	}

	public function getEndDate()
	{
		return $this->endDate;
	}

	public function setEntName($entName)
	{
		$this->entName = $entName;
		$this->apiParas["ent_name"] = $entName;
	}

	public function getEntName()
	{
		return $this->entName;
	}

	public function setPackageSpec($packageSpec)
	{
		$this->packageSpec = $packageSpec;
		$this->apiParas["package_spec"] = $packageSpec;
	}

	public function getPackageSpec()
	{
		return $this->packageSpec;
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

	public function setPhysicName($physicName)
	{
		$this->physicName = $physicName;
		$this->apiParas["physic_name"] = $physicName;
	}

	public function getPhysicName()
	{
		return $this->physicName;
	}

	public function setPrepnSpec($prepnSpec)
	{
		$this->prepnSpec = $prepnSpec;
		$this->apiParas["prepn_spec"] = $prepnSpec;
	}

	public function getPrepnSpec()
	{
		return $this->prepnSpec;
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

	public function setStartDate($startDate)
	{
		$this->startDate = $startDate;
		$this->apiParas["start_date"] = $startDate;
	}

	public function getStartDate()
	{
		return $this->startDate;
	}

	public function getApiMethodName()
	{
		return "alibaba.alihealth.drug.kyt.drugtable";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
		RequestCheckUtil::checkNotNull($this->page,"page");
		RequestCheckUtil::checkNotNull($this->pageSize,"pageSize");
		RequestCheckUtil::checkNotNull($this->physicName,"physicName");
		RequestCheckUtil::checkNotNull($this->refEntId,"refEntId");
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
