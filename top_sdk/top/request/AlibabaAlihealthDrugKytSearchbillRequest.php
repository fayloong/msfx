<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.searchbill request
 * 
 * @author auto create
 * @since 1.0, 2025.12.15
 */
class AlibabaAlihealthDrugKytSearchbillRequest
{
	/** 
	 * 代理企业
	 **/
	private $agentRefUserId;
	
	/** 
	 * 单据所有者
	 **/
	private $authRefUserId;
	
	/** 
	 * 开始日期
	 **/
	private $beginDate;
	
	/** 
	 * 单据号码
	 **/
	private $billCode;
	
	/** 
	 * 单据类型  A : 所有  AI :入库    AO:出库
	 **/
	private $billType;
	
	/** 
	 * 当前页
	 **/
	private $curPage;
	
	/** 
	 * 结束日期
	 **/
	private $endDate;
	
	/** 
	 * 页大小
	 **/
	private $pageSize;
	
	/** 
	 * 收货企业
	 **/
	private $partnerIdRecv;
	
	/** 
	 * 发货企业
	 **/
	private $partnerIdSend;
	
	/** 
	 * 企业标识
	 **/
	private $refEntId;
	
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

	public function setAuthRefUserId($authRefUserId)
	{
		$this->authRefUserId = $authRefUserId;
		$this->apiParas["auth_ref_user_id"] = $authRefUserId;
	}

	public function getAuthRefUserId()
	{
		return $this->authRefUserId;
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

	public function setCurPage($curPage)
	{
		$this->curPage = $curPage;
		$this->apiParas["cur_page"] = $curPage;
	}

	public function getCurPage()
	{
		return $this->curPage;
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

	public function setPageSize($pageSize)
	{
		$this->pageSize = $pageSize;
		$this->apiParas["page_size"] = $pageSize;
	}

	public function getPageSize()
	{
		return $this->pageSize;
	}

	public function setPartnerIdRecv($partnerIdRecv)
	{
		$this->partnerIdRecv = $partnerIdRecv;
		$this->apiParas["partner_id_recv"] = $partnerIdRecv;
	}

	public function getPartnerIdRecv()
	{
		return $this->partnerIdRecv;
	}

	public function setPartnerIdSend($partnerIdSend)
	{
		$this->partnerIdSend = $partnerIdSend;
		$this->apiParas["partner_id_send"] = $partnerIdSend;
	}

	public function getPartnerIdSend()
	{
		return $this->partnerIdSend;
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
		return "alibaba.alihealth.drug.kyt.searchbill";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
		RequestCheckUtil::checkNotNull($this->authRefUserId,"authRefUserId");
		RequestCheckUtil::checkNotNull($this->beginDate,"beginDate");
		RequestCheckUtil::checkNotNull($this->billType,"billType");
		RequestCheckUtil::checkNotNull($this->curPage,"curPage");
		RequestCheckUtil::checkNotNull($this->endDate,"endDate");
		RequestCheckUtil::checkNotNull($this->pageSize,"pageSize");
		RequestCheckUtil::checkNotNull($this->refEntId,"refEntId");
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
