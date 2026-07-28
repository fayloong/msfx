<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.remnantbill.upload request
 * 
 * @author auto create
 * @since 1.0, 2024.12.30
 */
class AlibabaAlihealthDrugKytRemnantbillUploadRequest
{
	/** 
	 * 委托企业【注意：该入参是ref_ent_id,并不是ent_id】
	 **/
	private $assRefEntId;
	
	/** 
	 * 单据编号 
	 **/
	private $billCode;
	
	/** 
	 * 单据时间:yyyy-MM-dd HH:mm:ss
	 **/
	private $billTime;
	
	/** 
	 * 零头入库：106；零头出库：210
	 **/
	private $billType;
	
	/** 
	 * 配送企业【注意：该入参是ref_ent_id,并不是ent_id】
	 **/
	private $disRefEntId;
	
	/** 
	 * 药品ID
	 **/
	private $drugEntBaseInfoId;
	
	/** 
	 * 有效期:yyyyMMdd 
	 **/
	private $expireDate;
	
	/** 
	 * 发货企业【注意：该入参是ref_ent_id,并不是ent_id】
	 **/
	private $fromRefUserId;
	
	/** 
	 * 药品数量
	 **/
	private $inputAmount;
	
	/** 
	 * 生产批次  
	 **/
	private $produceBatchNo;
	
	/** 
	 * 生产日期：yyyy-MM-dd 
	 **/
	private $produceDate;
	
	/** 
	 * 企业ref_ent_id
	 **/
	private $refEntId;
	
	/** 
	 * 收货企业【注意：该入参是ref_ent_id,并不是ent_id】
	 **/
	private $toRefUserId;
	
	private $apiParas = array();
	
	public function setAssRefEntId($assRefEntId)
	{
		$this->assRefEntId = $assRefEntId;
		$this->apiParas["ass_ref_ent_id"] = $assRefEntId;
	}

	public function getAssRefEntId()
	{
		return $this->assRefEntId;
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

	public function setBillTime($billTime)
	{
		$this->billTime = $billTime;
		$this->apiParas["bill_time"] = $billTime;
	}

	public function getBillTime()
	{
		return $this->billTime;
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

	public function setDisRefEntId($disRefEntId)
	{
		$this->disRefEntId = $disRefEntId;
		$this->apiParas["dis_ref_ent_id"] = $disRefEntId;
	}

	public function getDisRefEntId()
	{
		return $this->disRefEntId;
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

	public function setExpireDate($expireDate)
	{
		$this->expireDate = $expireDate;
		$this->apiParas["expire_date"] = $expireDate;
	}

	public function getExpireDate()
	{
		return $this->expireDate;
	}

	public function setFromRefUserId($fromRefUserId)
	{
		$this->fromRefUserId = $fromRefUserId;
		$this->apiParas["from_ref_user_id"] = $fromRefUserId;
	}

	public function getFromRefUserId()
	{
		return $this->fromRefUserId;
	}

	public function setInputAmount($inputAmount)
	{
		$this->inputAmount = $inputAmount;
		$this->apiParas["input_amount"] = $inputAmount;
	}

	public function getInputAmount()
	{
		return $this->inputAmount;
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

	public function setProduceDate($produceDate)
	{
		$this->produceDate = $produceDate;
		$this->apiParas["produce_date"] = $produceDate;
	}

	public function getProduceDate()
	{
		return $this->produceDate;
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

	public function setToRefUserId($toRefUserId)
	{
		$this->toRefUserId = $toRefUserId;
		$this->apiParas["to_ref_user_id"] = $toRefUserId;
	}

	public function getToRefUserId()
	{
		return $this->toRefUserId;
	}

	public function getApiMethodName()
	{
		return "alibaba.alihealth.drug.kyt.remnantbill.upload";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
		RequestCheckUtil::checkNotNull($this->assRefEntId,"assRefEntId");
		RequestCheckUtil::checkNotNull($this->billCode,"billCode");
		RequestCheckUtil::checkNotNull($this->billTime,"billTime");
		RequestCheckUtil::checkNotNull($this->billType,"billType");
		RequestCheckUtil::checkNotNull($this->disRefEntId,"disRefEntId");
		RequestCheckUtil::checkNotNull($this->drugEntBaseInfoId,"drugEntBaseInfoId");
		RequestCheckUtil::checkNotNull($this->expireDate,"expireDate");
		RequestCheckUtil::checkNotNull($this->fromRefUserId,"fromRefUserId");
		RequestCheckUtil::checkNotNull($this->inputAmount,"inputAmount");
		RequestCheckUtil::checkNotNull($this->produceBatchNo,"produceBatchNo");
		RequestCheckUtil::checkNotNull($this->produceDate,"produceDate");
		RequestCheckUtil::checkNotNull($this->refEntId,"refEntId");
		RequestCheckUtil::checkNotNull($this->toRefUserId,"toRefUserId");
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
