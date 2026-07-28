<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.uploadrelation request
 * 
 * @author auto create
 * @since 1.0, 2026.02.03
 */
class AlibabaAlihealthDrugKytUploadrelationRequest
{
	/** 
	 * affirmFlag
	 **/
	private $affirmFlag;
	
	/** 
	 * 客户端类型
	 **/
	private $clientType;
	
	/** 
	 * fileContent(可不添)
	 **/
	private $fileContent;
	
	/** 
	 * 加密之后的文件内容字符串（先把文件内容用java的zip类压缩然后转base64）
	 **/
	private $fileContentString;
	
	/** 
	 * 上传文件的企业ID
	 **/
	private $refEntId;
	
	/** 
	 * 关联关系文件信息
	 **/
	private $saveCodeRelation;
	
	private $apiParas = array();
	
	public function setAffirmFlag($affirmFlag)
	{
		$this->affirmFlag = $affirmFlag;
		$this->apiParas["affirm_flag"] = $affirmFlag;
	}

	public function getAffirmFlag()
	{
		return $this->affirmFlag;
	}

	public function setClientType($clientType)
	{
		$this->clientType = $clientType;
		$this->apiParas["client_type"] = $clientType;
	}

	public function getClientType()
	{
		return $this->clientType;
	}

	public function setFileContent($fileContent)
	{
		$this->fileContent = $fileContent;
		$this->apiParas["file_content"] = $fileContent;
	}

	public function getFileContent()
	{
		return $this->fileContent;
	}

	public function setFileContentString($fileContentString)
	{
		$this->fileContentString = $fileContentString;
		$this->apiParas["file_content_string"] = $fileContentString;
	}

	public function getFileContentString()
	{
		return $this->fileContentString;
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

	public function setSaveCodeRelation($saveCodeRelation)
	{
		$this->saveCodeRelation = $saveCodeRelation;
		$this->apiParas["save_code_relation"] = $saveCodeRelation;
	}

	public function getSaveCodeRelation()
	{
		return $this->saveCodeRelation;
	}

	public function getApiMethodName()
	{
		return "alibaba.alihealth.drug.kyt.uploadrelation";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
		RequestCheckUtil::checkNotNull($this->fileContentString,"fileContentString");
		RequestCheckUtil::checkNotNull($this->refEntId,"refEntId");
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
