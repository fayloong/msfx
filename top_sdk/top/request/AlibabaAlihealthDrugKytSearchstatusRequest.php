<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.searchstatus request
 * 
 * @author auto create
 * @since 1.0, 2025.12.19
 */
class AlibabaAlihealthDrugKytSearchstatusRequest
{
	/** 
	 * 代理商（第三方物流企业）
	 **/
	private $agentRefUserId;
	
	/** 
	 * 开始日期（没有时分秒，【单据创建时间】）
	 **/
	private $beginDate;
	
	/** 
	 * 单据号（精确值，不支持模糊查询）
	 **/
	private $billCode;
	
	/** 
	 * 单据类型 A：全部 AI：全部入库 AO：全部出库
	 **/
	private $billType;
	
	/** 
	 * 状态  0, 处理中     3, 处理成功     4, 处理失败
	 **/
	private $dealStatus;
	
	/** 
	 * 药品类型
	 **/
	private $drugType;
	
	/** 
	 * 结束日期（没有时分秒，【单据创建时间】）
	 **/
	private $endDate;
	
	/** 
	 * 发货商
	 **/
	private $fromUserId;
	
	/** 
	 * 页码
	 **/
	private $page;
	
	/** 
	 * 页大小
	 **/
	private $pageSize;
	
	/** 
	 * 企业ref_ent_id（货主企业的ref_ent_id）
	 **/
	private $refEntId;
	
	/** 
	 * 收货商
	 **/
	private $toUserId;
	
	private $apiParas = array();
	
	public function setAgentRefUserId($agentRefUserId)
	{
		$this->agentRefUserId = $agentRefUserId;
		$this->apiParas["agent_ref_user_id"] = $agentRefUserId;
	}

	public function getAgentRefUserId()
	{
		return $this->agentRefUserId;
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

	public function setBillCode($billCode)
	{
		$this->billCode = $billCode;
		$this->apiParas["bill_code"] = $billCode;
	}

	public function getBillCode()
	{
		return $this->billCode;
	}

	public function setBillType($billType)
	{
		$this->billType = $billType;
		$this->apiParas["bill_type"] = $billType;
	}

	public function getBillType()
	{
		return $this->billType;
	}

	public function setDealStatus($dealStatus)
	{
		$this->dealStatus = $dealStatus;
		$this->apiParas["deal_status"] = $dealStatus;
	}

	public function getDealStatus()
	{
		return $this->dealStatus;
	}

	public function setDrugType($drugType)
	{
		$this->drugType = $drugType;
		$this->apiParas["drug_type"] = $drugType;
	}

	public function getDrugType()
	{
		return $this->drugType;
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

	public function setFromUserId($fromUserId)
	{
		$this->fromUserId = $fromUserId;
		$this->apiParas["from_user_id"] = $fromUserId;
	}

	public function getFromUserId()
	{
		return $this->fromUserId;
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

	public function setToUserId($toUserId)
	{
		$this->toUserId = $toUserId;
		$this->apiParas["to_user_id"] = $toUserId;
	}

	public function getToUserId()
	{
		return $this->toUserId;
	}

	public function getApiMethodName()
	{
		return "alibaba.alihealth.drug.kyt.searchstatus";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
		RequestCheckUtil::checkNotNull($this->beginDate,"beginDate");
		RequestCheckUtil::checkNotNull($this->billType,"billType");
		RequestCheckUtil::checkNotNull($this->endDate,"endDate");
		RequestCheckUtil::checkNotNull($this->page,"page");
		RequestCheckUtil::checkNotNull($this->pageSize,"pageSize");
		RequestCheckUtil::checkNotNull($this->refEntId,"refEntId");
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
