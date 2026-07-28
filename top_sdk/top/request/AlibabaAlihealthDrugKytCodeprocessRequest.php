<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.codeprocess request
 * 
 * @author auto create
 * @since 1.0, 2021.11.23
 */
class AlibabaAlihealthDrugKytCodeprocessRequest
{
	/** 
	 * 客户端
	 **/
	private $clientType;
	
	/** 
	 * 药品ID
	 **/
	private $drugEntBaseInfoId;
	
	/** 
	 * 结束时间
	 **/
	private $endDate;
	
	/** 
	 * 页数
	 **/
	private $page;
	
	/** 
	 * 条数
	 **/
	private $pageSize;
	
	/** 
	 * 药品类型（所有药品  A ，未分类 9， 特殊药品原料药  1， 特殊药品制  2， 普通药品	3）
	 **/
	private $physicType;
	
	/** 
	 * 包装规格
	 **/
	private $pkgSpec;
	
	/** 
	 * 处理状态(所有状态 A ,待处理  1 ,处理成功  3 ,处理失败  4)
	 **/
	private $processFlag;
	
	/** 
	 * 生产企业ID
	 **/
	private $prodSeqNo;
	
	/** 
	 * 批次号
	 **/
	private $produceBatchNo;
	
	/** 
	 * 查询标识(处理状态查询 传0 , 关联关系个修改 传1)
	 **/
	private $queryFlag;
	
	/** 
	 * 企业ID
	 **/
	private $refEntId;
	
	/** 
	 * 开始时间
	 **/
	private $startDate;
	
	/** 
	 * 上传标识
	 **/
	private $uploadFlag;
	
	private $apiParas = array();
	
	public function setClientType($clientType)
	{
		$this->clientType = $clientType;
		$this->apiParas["client_type"] = $clientType;
	}

	public function getClientType()
	{
		return $this->clientType;
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

	public function setPkgSpec($pkgSpec)
	{
		$this->pkgSpec = $pkgSpec;
		$this->apiParas["pkg_spec"] = $pkgSpec;
	}

	public function getPkgSpec()
	{
		return $this->pkgSpec;
	}

	public function setProcessFlag($processFlag)
	{
		$this->processFlag = $processFlag;
		$this->apiParas["process_flag"] = $processFlag;
	}

	public function getProcessFlag()
	{
		return $this->processFlag;
	}

	public function setProdSeqNo($prodSeqNo)
	{
		$this->prodSeqNo = $prodSeqNo;
		$this->apiParas["prod_seq_no"] = $prodSeqNo;
	}

	public function getProdSeqNo()
	{
		return $this->prodSeqNo;
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

	public function setQueryFlag($queryFlag)
	{
		$this->queryFlag = $queryFlag;
		$this->apiParas["query_flag"] = $queryFlag;
	}

	public function getQueryFlag()
	{
		return $this->queryFlag;
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

	public function setUploadFlag($uploadFlag)
	{
		$this->uploadFlag = $uploadFlag;
		$this->apiParas["upload_flag"] = $uploadFlag;
	}

	public function getUploadFlag()
	{
		return $this->uploadFlag;
	}

	public function getApiMethodName()
	{
		return "alibaba.alihealth.drug.kyt.codeprocess";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
		RequestCheckUtil::checkNotNull($this->clientType,"clientType");
		RequestCheckUtil::checkNotNull($this->endDate,"endDate");
		RequestCheckUtil::checkNotNull($this->page,"page");
		RequestCheckUtil::checkNotNull($this->pageSize,"pageSize");
		RequestCheckUtil::checkNotNull($this->physicType,"physicType");
		RequestCheckUtil::checkNotNull($this->processFlag,"processFlag");
		RequestCheckUtil::checkNotNull($this->queryFlag,"queryFlag");
		RequestCheckUtil::checkNotNull($this->refEntId,"refEntId");
		RequestCheckUtil::checkNotNull($this->startDate,"startDate");
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
