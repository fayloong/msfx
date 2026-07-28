<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.listbillprocesspartsuccess request
 * 
 * @author auto create
 * @since 1.0, 2025.08.06
 */
class AlibabaAlihealthDrugKytListbillprocesspartsuccessRequest
{
	/** 
	 * 单据号
	 **/
	private $billCode;
	
	/** 
	 * 错误码
	 **/
	private $code;
	
	/** 
	 * 错误码类型
	 **/
	private $errorCode;
	
	/** 
	 * 当前页
	 **/
	private $page;
	
	/** 
	 * 页大小
	 **/
	private $pageSize;
	
	/** 
	 * 企业标识
	 **/
	private $refEntId;
	
	private $apiParas = array();
	
	public function setBillCode($billCode)
	{
		$this->billCode = $billCode;
		$this->apiParas["bill_code"] = $billCode;
	}

	public function getBillCode()
	{
		return $this->billCode;
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

	public function setErrorCode($errorCode)
	{
		$this->errorCode = $errorCode;
		$this->apiParas["error_code"] = $errorCode;
	}

	public function getErrorCode()
	{
		return $this->errorCode;
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
		return "alibaba.alihealth.drug.kyt.listbillprocesspartsuccess";
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
