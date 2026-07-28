<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.listupout request
 * 
 * @author auto create
 * @since 1.0, 2026.02.03
 */
class AlibabaAlihealthDrugKytListupoutRequest
{
	/** 
	 * 第三方物流企业唯一标识（只有代查其它企业数据时填写）
	 **/
	private $agentRefEntId;
	
	/** 
	 * 开始日期（不写时分秒）
	 **/
	private $beginDate;
	
	/** 
	 * 单据号
	 **/
	private $billCode;
	
	/** 
	 * 单据类型
	 **/
	private $billType;
	
	/** 
	 * 药品ID
	 **/
	private $drugEntBaseInfoId;
	
	/** 
	 * 结束日期（不写时分秒）
	 **/
	private $endDate;
	
	/** 
	 * 发货单位
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
	 * 是否返回经营国家重点品种（1代表返回国家重点品种的单据）
	 **/
	private $physicType;
	
	/** 
	 * 生产批号
	 **/
	private $produceBatchNo;
	
	/** 
	 * 货主企业唯一标识（一般情况下填写自已企业）
	 **/
	private $refEntId;
	
	/** 
	 * 状态
	 **/
	private $status;
	
	private $apiParas = array();
	
	public function setAgentRefEntId($agentRefEntId)
	{
		$this->agentRefEntId = $agentRefEntId;
		$this->apiParas["agent_ref_ent_id"] = $agentRefEntId;
	}

	public function getAgentRefEntId()
	{
		return $this->agentRefEntId;
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

	public function setDrugEntBaseInfoId($drugEntBaseInfoId)
	{
		$this->drugEntBaseInfoId = $drugEntBaseInfoId;
		$this->apiParas["drug_ent_base_info_id"] = $drugEntBaseInfoId;
	}

	public function getDrugEntBaseInfoId()
	{
		return $this->drugEntBaseInfoId;
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

	public function setPhysicType($physicType)
	{
		$this->physicType = $physicType;
		$this->apiParas["physic_type"] = $physicType;
	}

	public function getPhysicType()
	{
		return $this->physicType;
	}

	public function setProduceBatchNo($produceBatchNo)
	{
		$this->produceBatchNo = $produceBatchNo;
		$this->apiParas["produce_batch_no"] = $produceBatchNo;
	}

	public function getProduceBatchNo()
	{
		return $this->produceBatchNo;
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

	public function setStatus($status)
	{
		$this->status = $status;
		$this->apiParas["status"] = $status;
	}

	public function getStatus()
	{
		return $this->status;
	}

	public function getApiMethodName()
	{
		return "alibaba.alihealth.drug.kyt.listupout";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
		RequestCheckUtil::checkNotNull($this->beginDate,"beginDate");
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
