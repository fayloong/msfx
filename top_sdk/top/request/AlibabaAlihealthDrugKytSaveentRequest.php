<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.saveent request
 * 
 * @author auto create
 * @since 1.0, 2024.03.08
 */
class AlibabaAlihealthDrugKytSaveentRequest
{
	/** 
	 * 新增企业信息
	 **/
	private $addEntReq;
	
	/** 
	 * 图片数据流。图片大小务必控制在2M以内
	 **/
	private $licPictureByte;
	
	/** 
	 * 添加企业唯一标识
	 **/
	private $refEntId;
	
	private $apiParas = array();
	
	public function setAddEntReq($addEntReq)
	{
		$this->addEntReq = $addEntReq;
		$this->apiParas["add_ent_req"] = $addEntReq;
	}

	public function getAddEntReq()
	{
		return $this->addEntReq;
	}

	public function setLicPictureByte($licPictureByte)
	{
		$this->licPictureByte = $licPictureByte;
		$this->apiParas["lic_picture_byte"] = $licPictureByte;
	}

	public function getLicPictureByte()
	{
		return $this->licPictureByte;
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
		return "alibaba.alihealth.drug.kyt.saveent";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
		RequestCheckUtil::checkNotNull($this->licPictureByte,"licPictureByte");
		RequestCheckUtil::checkNotNull($this->refEntId,"refEntId");
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
