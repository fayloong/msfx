<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.listparts request
 * 
 * @author auto create
 * @since 1.0, 2025.11.19
 */
class AlibabaAlihealthDrugKytListpartsRequest
{
	/** 
	 * 1:审核通过，2：审核不通过
	 **/
	private $auditFlag;
	
	/** 
	 * 开始时间：往来单位最后修改时间（不推荐使用：因为往来单位是共用的，任意企业提交了信息变更都会引起这个值的变更）
	 **/
	private $beginDate;
	
	/** 
	 * 结束时间：往来单位最后修改时间（不推荐使用：因为往来单位是共用的，任意企业提交了信息变更都会引起这个值的变更）
	 **/
	private $endDate;
	
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
	 * 企业ID：查谁的往来单位数据就传谁
	 **/
	private $refEntId;
	
	/** 
	 * 企业自定义编号
	 **/
	private $refPartnerId;
	
	private $apiParas = array();
	
	public function setAuditFlag($auditFlag)
	{
		$this->auditFlag = $auditFlag;
		$this->apiParas["audit_flag"] = $auditFlag;
	}

	public function getAuditFlag()
	{
		return $this->auditFlag;
	}

	public function setBeginDate($beginDate)
	{
		$this->beginDate = $beginDate;
		$this->apiParas["begin_date"] = $beginDate;
	}

	public function getBeginDate()
	{
		return $this->beginDate;
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

	public function setRefPartnerId($refPartnerId)
	{
		$this->refPartnerId = $refPartnerId;
		$this->apiParas["ref_partner_id"] = $refPartnerId;
	}

	public function getRefPartnerId()
	{
		return $this->refPartnerId;
	}

	public function getApiMethodName()
	{
		return "alibaba.alihealth.drug.kyt.listparts";
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
