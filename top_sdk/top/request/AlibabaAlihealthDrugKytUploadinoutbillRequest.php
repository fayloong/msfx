<?php
/**
 * TOP API: alibaba.alihealth.drug.kyt.uploadinoutbill request
 * 
 * @author auto create
 * @since 1.0, 2025.10.27
 */
class AlibabaAlihealthDrugKytUploadinoutbillRequest
{
	/** 
	 * 第三方物流代理企业【注意：该入参是ref_ent_id，不是ent_id】，该字段兼容之前接口逻辑，后期将不允许使用，不要填值。
	 **/
	private $agentRefUserId;
	
	/** 
	 * 单据委托企业entId
	 **/
	private $assEntId;
	
	/** 
	 * 单据委托企业refEntId
	 **/
	private $assRefEntId;
	
	/** 
	 * 单据编号【同一个企业不能上传相同单据号】
	 **/
	private $billCode;
	
	/** 
	 * 单据时间（扫码时间）
	 **/
	private $billTime;
	
	/** 
	 * 单据类型【102代表采购入库,201代表销售出库，其它单据类型详见文档】
	 **/
	private $billType;
	
	/** 
	 * 已废弃，无需填写
	 **/
	private $cancelReasonCode;
	
	/** 
	 * 已废弃，无需填写
	 **/
	private $cancelReasonDes;
	
	/** 
	 * 请求端类型【暂定都写2】
	 **/
	private $clientType;
	
	/** 
	 * 直调企业【注意：该入参是ent_id,并不是ref_ent_id】
	 **/
	private $destUserId;
	
	/** 
	 * 药品配送企业entId【出库单填写】
	 **/
	private $disEntId;
	
	/** 
	 * 药品配送企业refentid【出库单填写，与dis_ent_id入参选其一添加】
	 **/
	private $disRefEntId;
	
	/** 
	 * 药品ID[企业自已系统的药品ID]
	 **/
	private $drugId;
	
	/** 
	 * 【可不填】药品列表Json："codeCount":         药品数量 "commDrugId":     国家药品唯一标识 "exprieDate":         生产日期 "physicInfo":          药品信息 "pkgSpec":           包状规格 "prepnCount":       制剂数量 "produceBatchNo":生产批次 "produceDate":      生产日期
	 **/
	private $drugListJson;
	
	/** 
	 * 已废弃，无需填写
	 **/
	private $executerCode;
	
	/** 
	 * 已废弃，无需填写
	 **/
	private $executerName;
	
	/** 
	 * 发货地址
	 **/
	private $fromAddress;
	
	/** 
	 * 发货单编号
	 **/
	private $fromBillCode;
	
	/** 
	 * 发货人(特药出单据必填)
	 **/
	private $fromPerson;
	
	/** 
	 * 发货企业entId【注意：该入参是ent_id,并不是ref_ent_id】
	 **/
	private $fromUserId;
	
	/** 
	 * 码解析策略,1代表整单解析成功(任一码解析失败，上传时整单返回错误),传其他值或者不传代表部分解析成功(跳过无法解析的码，其余正常处理并上传)
	 **/
	private $ignorePartSuccessFlag;
	
	/** 
	 * 操作人标识（写appkey编号）
	 **/
	private $operIcCode;
	
	/** 
	 * 单据提交者姓名
	 **/
	private $operIcName;
	
	/** 
	 * 定货单编号
	 **/
	private $orderCode;
	
	/** 
	 * 药品类型【3普药2特药】89开头的码定义为特药，其它码定义成普药【可以随便填写，单据上传后会以实际为准】
	 **/
	private $physicType;
	
	/** 
	 * 应收货总数量
	 **/
	private $quReceivable;
	
	/** 
	 * 货主企业（单据的所有者，上传人）【注意：该入参是ref_ent_id，不是ent_id】
	 **/
	private $refUserId;
	
	/** 
	 * 已废弃，无需填写
	 **/
	private $returnReasonCode;
	
	/** 
	 * 已废弃，无需填写
	 **/
	private $returnReasonDes;
	
	/** 
	 * 已废弃，无需填写
	 **/
	private $superviserCode;
	
	/** 
	 * 已废弃，无需填写
	 **/
	private $superviserName;
	
	/** 
	 * 收货地址
	 **/
	private $toAddress;
	
	/** 
	 * 收货人(特药入单据必填)
	 **/
	private $toPerson;
	
	/** 
	 * 收货企业entId【注意：该入参是ent_id,并不是ref_ent_id】
	 **/
	private $toUserId;
	
	/** 
	 * 追溯码【多个码时用逗号拼接的字符串。要求数量在3500个码以下，但一般不要传这么多，如果网络不好很容易传输一半报错】
	 **/
	private $traceCodes;
	
	/** 
	 * 仓号
	 **/
	private $warehouseId;
	
	/** 
	 * 未验证通过原因【验证未通过时填写】
	 **/
	private $xtCheckCode;
	
	/** 
	 * 未验证通过原因描述【验证未通过时填写】
	 **/
	private $xtCheckCodeDesc;
	
	/** 
	 * 是否验证，0：未通过验证，1：已验证
	 **/
	private $xtIsCheck;
	
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

	public function setAssEntId($assEntId)
	{
		$this->assEntId = $assEntId;
		$this->apiParas["ass_ent_id"] = $assEntId;
	}

	public function getAssEntId()
	{
		return $this->assEntId;
	}

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

	public function setCancelReasonCode($cancelReasonCode)
	{
		$this->cancelReasonCode = $cancelReasonCode;
		$this->apiParas["cancel_reason_code"] = $cancelReasonCode;
	}

	public function getCancelReasonCode()
	{
		return $this->cancelReasonCode;
	}

	public function setCancelReasonDes($cancelReasonDes)
	{
		$this->cancelReasonDes = $cancelReasonDes;
		$this->apiParas["cancel_reason_des"] = $cancelReasonDes;
	}

	public function getCancelReasonDes()
	{
		return $this->cancelReasonDes;
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

	public function setDestUserId($destUserId)
	{
		$this->destUserId = $destUserId;
		$this->apiParas["dest_user_id"] = $destUserId;
	}

	public function getDestUserId()
	{
		return $this->destUserId;
	}

	public function setDisEntId($disEntId)
	{
		$this->disEntId = $disEntId;
		$this->apiParas["dis_ent_id"] = $disEntId;
	}

	public function getDisEntId()
	{
		return $this->disEntId;
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

	public function setDrugId($drugId)
	{
		$this->drugId = $drugId;
		$this->apiParas["drug_id"] = $drugId;
	}

	public function getDrugId()
	{
		return $this->drugId;
	}

	public function setDrugListJson($drugListJson)
	{
		$this->drugListJson = $drugListJson;
		$this->apiParas["drug_list_json"] = $drugListJson;
	}

	public function getDrugListJson()
	{
		return $this->drugListJson;
	}

	public function setExecuterCode($executerCode)
	{
		$this->executerCode = $executerCode;
		$this->apiParas["executer_code"] = $executerCode;
	}

	public function getExecuterCode()
	{
		return $this->executerCode;
	}

	public function setExecuterName($executerName)
	{
		$this->executerName = $executerName;
		$this->apiParas["executer_name"] = $executerName;
	}

	public function getExecuterName()
	{
		return $this->executerName;
	}

	public function setFromAddress($fromAddress)
	{
		$this->fromAddress = $fromAddress;
		$this->apiParas["from_address"] = $fromAddress;
	}

	public function getFromAddress()
	{
		return $this->fromAddress;
	}

	public function setFromBillCode($fromBillCode)
	{
		$this->fromBillCode = $fromBillCode;
		$this->apiParas["from_bill_code"] = $fromBillCode;
	}

	public function getFromBillCode()
	{
		return $this->fromBillCode;
	}

	public function setFromPerson($fromPerson)
	{
		$this->fromPerson = $fromPerson;
		$this->apiParas["from_person"] = $fromPerson;
	}

	public function getFromPerson()
	{
		return $this->fromPerson;
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

	public function setIgnorePartSuccessFlag($ignorePartSuccessFlag)
	{
		$this->ignorePartSuccessFlag = $ignorePartSuccessFlag;
		$this->apiParas["ignore_part_success_flag"] = $ignorePartSuccessFlag;
	}

	public function getIgnorePartSuccessFlag()
	{
		return $this->ignorePartSuccessFlag;
	}

	public function setOperIcCode($operIcCode)
	{
		$this->operIcCode = $operIcCode;
		$this->apiParas["oper_ic_code"] = $operIcCode;
	}

	public function getOperIcCode()
	{
		return $this->operIcCode;
	}

	public function setOperIcName($operIcName)
	{
		$this->operIcName = $operIcName;
		$this->apiParas["oper_ic_name"] = $operIcName;
	}

	public function getOperIcName()
	{
		return $this->operIcName;
	}

	public function setOrderCode($orderCode)
	{
		$this->orderCode = $orderCode;
		$this->apiParas["order_code"] = $orderCode;
	}

	public function getOrderCode()
	{
		return $this->orderCode;
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

	public function setQuReceivable($quReceivable)
	{
		$this->quReceivable = $quReceivable;
		$this->apiParas["qu_receivable"] = $quReceivable;
	}

	public function getQuReceivable()
	{
		return $this->quReceivable;
	}

	public function setRefUserId($refUserId)
	{
		$this->refUserId = $refUserId;
		$this->apiParas["ref_user_id"] = $refUserId;
	}

	public function getRefUserId()
	{
		return $this->refUserId;
	}

	public function setReturnReasonCode($returnReasonCode)
	{
		$this->returnReasonCode = $returnReasonCode;
		$this->apiParas["return_reason_code"] = $returnReasonCode;
	}

	public function getReturnReasonCode()
	{
		return $this->returnReasonCode;
	}

	public function setReturnReasonDes($returnReasonDes)
	{
		$this->returnReasonDes = $returnReasonDes;
		$this->apiParas["return_reason_des"] = $returnReasonDes;
	}

	public function getReturnReasonDes()
	{
		return $this->returnReasonDes;
	}

	public function setSuperviserCode($superviserCode)
	{
		$this->superviserCode = $superviserCode;
		$this->apiParas["superviser_code"] = $superviserCode;
	}

	public function getSuperviserCode()
	{
		return $this->superviserCode;
	}

	public function setSuperviserName($superviserName)
	{
		$this->superviserName = $superviserName;
		$this->apiParas["superviser_name"] = $superviserName;
	}

	public function getSuperviserName()
	{
		return $this->superviserName;
	}

	public function setToAddress($toAddress)
	{
		$this->toAddress = $toAddress;
		$this->apiParas["to_address"] = $toAddress;
	}

	public function getToAddress()
	{
		return $this->toAddress;
	}

	public function setToPerson($toPerson)
	{
		$this->toPerson = $toPerson;
		$this->apiParas["to_person"] = $toPerson;
	}

	public function getToPerson()
	{
		return $this->toPerson;
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

	public function setTraceCodes($traceCodes)
	{
		$this->traceCodes = $traceCodes;
		$this->apiParas["trace_codes"] = $traceCodes;
	}

	public function getTraceCodes()
	{
		return $this->traceCodes;
	}

	public function setWarehouseId($warehouseId)
	{
		$this->warehouseId = $warehouseId;
		$this->apiParas["warehouse_id"] = $warehouseId;
	}

	public function getWarehouseId()
	{
		return $this->warehouseId;
	}

	public function setXtCheckCode($xtCheckCode)
	{
		$this->xtCheckCode = $xtCheckCode;
		$this->apiParas["xt_check_code"] = $xtCheckCode;
	}

	public function getXtCheckCode()
	{
		return $this->xtCheckCode;
	}

	public function setXtCheckCodeDesc($xtCheckCodeDesc)
	{
		$this->xtCheckCodeDesc = $xtCheckCodeDesc;
		$this->apiParas["xt_check_code_desc"] = $xtCheckCodeDesc;
	}

	public function getXtCheckCodeDesc()
	{
		return $this->xtCheckCodeDesc;
	}

	public function setXtIsCheck($xtIsCheck)
	{
		$this->xtIsCheck = $xtIsCheck;
		$this->apiParas["xt_is_check"] = $xtIsCheck;
	}

	public function getXtIsCheck()
	{
		return $this->xtIsCheck;
	}

	public function getApiMethodName()
	{
		return "alibaba.alihealth.drug.kyt.uploadinoutbill";
	}
	
	public function getApiParas()
	{
		return $this->apiParas;
	}
	
	public function check()
	{
		
		RequestCheckUtil::checkNotNull($this->billCode,"billCode");
		RequestCheckUtil::checkNotNull($this->billTime,"billTime");
		RequestCheckUtil::checkNotNull($this->billType,"billType");
		RequestCheckUtil::checkNotNull($this->clientType,"clientType");
		RequestCheckUtil::checkNotNull($this->fromUserId,"fromUserId");
		RequestCheckUtil::checkNotNull($this->operIcCode,"operIcCode");
		RequestCheckUtil::checkNotNull($this->operIcName,"operIcName");
		RequestCheckUtil::checkNotNull($this->physicType,"physicType");
		RequestCheckUtil::checkNotNull($this->refUserId,"refUserId");
		RequestCheckUtil::checkNotNull($this->toUserId,"toUserId");
		RequestCheckUtil::checkNotNull($this->traceCodes,"traceCodes");
		RequestCheckUtil::checkMaxListSize($this->traceCodes,10000,"traceCodes");
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
