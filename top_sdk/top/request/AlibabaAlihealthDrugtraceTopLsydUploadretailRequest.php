<?php
/**
 * TOP API: alibaba.alihealth.drugtrace.top.lsyd.uploadretail request
 * 
 * @author auto create
 * @since 1.0, 2026.04.21
 */
class AlibabaAlihealthDrugtraceTopLsydUploadretailRequest
{
	/** 
	 * 单据编号（唯一）
	 **/
	private $billCode;
	
	/** 
	 * 单据时间（一般为药品入出库时间）
	 **/
	private $billTime;
	
	/** 
	 * 单据类型[321,使用出库][322,疫苗接种][116,消费者退货入库]
	 **/
	private $billType;
	
	/** 
	 * 购买人证件编号
	 **/
	private $customerId;
	
	/** 
	 * 购买人证件类型【1身份证2护照3 军官证4 医保卡5接种卡6学生证9其它】
	 **/
	private $customerIdType;
	
	/** 
	 * 发货企业(可为空)
	 **/
	private $fromUserId;
	
	/** 
	 * 码解析策略,1代表整单解析成功(任一码解析失败，上传时整单返回错误),传其他值或者不传代表部分解析成功(跳过无法解析的码，其余正常处理并上传)
	 **/
	private $ignorePartSuccessFlag;
	
	/** 
	 * 药品发药人(最大长度20)
	 **/
	private $medicDispenser;
	
	/** 
	 * 开药医师(最大长度20)
	 **/
	private $medicDoctor;
	
	/** 
	 * 互联网订单标识 0非互联网 1互联网
	 **/
	private $networkBillFlag;
	
	/** 
	 * 单据提交者(appkey编号，可为空)
	 **/
	private $operIcCode;
	
	/** 
	 * 单据提交者姓名(可为空)
	 **/
	private $operIcName;
	
	/** 
	 * 药品类型[2,特药，3,普药]【可以随便填写，单据上传后会以实际为准】  
	 **/
	private $physicType;
	
	/** 
	 * 码上放心平台企业唯一编码（门店或医疗机构），可通过“通过企业名得到唯一标识”接口获取；
	 **/
	private $refUserId;
	
	/** 
	 * 备注
	 **/
	private $remarks;
	
	/** 
	 * 追溯码【多个码时用逗号拼接的字符串。要求数量在3500个码以下，但一般不要传这么多，如果网络不好很容易传输一半报错】注意：在同一张单据里，不能有重复的码；在同一张单据中不能同时上传有关联关系的大、小码
	 **/
	private $traceCodes;
	
	/** 
	 * 药品代理人(最大长度20)
	 **/
	private $userAgent;
	
	/** 
	 * 药品使用者姓名(最大长度20)
	 **/
	private $userName;
	
	/** 
	 * 购买人电话(最大长度11)
	 **/
	private $userTel;
	
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

	public function setCustomerId($customerId)
	{
		$this->customerId = $customerId;
		$this->apiParas["customer_id"] = $customerId;
	}

	public function getCustomerId()
	{
		return $this->customerId;
	}

	public function setCustomerIdType($customerIdType)
	{
		$this->customerIdType = $customerIdType;
		$this->apiParas["customer_id_type"] = $customerIdType;
	}

	public function getCustomerIdType()
	{
		return $this->customerIdType;
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

	public function setMedicDispenser($medicDispenser)
	{
		$this->medicDispenser = $medicDispenser;
		$this->apiParas["medic_dispenser"] = $medicDispenser;
	}

	public function getMedicDispenser()
	{
		return $this->medicDispenser;
	}

	public function setMedicDoctor($medicDoctor)
	{
		$this->medicDoctor = $medicDoctor;
		$this->apiParas["medic_doctor"] = $medicDoctor;
	}

	public function getMedicDoctor()
	{
		return $this->medicDoctor;
	}

	public function setNetworkBillFlag($networkBillFlag)
	{
		$this->networkBillFlag = $networkBillFlag;
		$this->apiParas["network_bill_flag"] = $networkBillFlag;
	}

	public function getNetworkBillFlag()
	{
		return $this->networkBillFlag;
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

	public function setPhysicType($physicType)
	{
		$this->physicType = $physicType;
		$this->apiParas["physic_type"] = $physicType;
	}

	public function getPhysicType()
	{
		return $this->physicType;
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

	public function setRemarks($remarks)
	{
		$this->remarks = $remarks;
		$this->apiParas["remarks"] = $remarks;
	}

	public function getRemarks()
	{
		return $this->remarks;
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

	public function setUserAgent($userAgent)
	{
		$this->userAgent = $userAgent;
		$this->apiParas["user_agent"] = $userAgent;
	}

	public function getUserAgent()
	{
		return $this->userAgent;
	}

	public function setUserName($userName)
	{
		$this->userName = $userName;
		$this->apiParas["user_name"] = $userName;
	}

	public function getUserName()
	{
		return $this->userName;
	}

	public function setUserTel($userTel)
	{
		$this->userTel = $userTel;
		$this->apiParas["user_tel"] = $userTel;
	}

	public function getUserTel()
	{
		return $this->userTel;
	}

	public function getApiMethodName()
	{
		return "alibaba.alihealth.drugtrace.top.lsyd.uploadretail";
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
		RequestCheckUtil::checkNotNull($this->refUserId,"refUserId");
		RequestCheckUtil::checkNotNull($this->traceCodes,"traceCodes");
		RequestCheckUtil::checkMaxListSize($this->traceCodes,3500,"traceCodes");
	}
	
	public function putOtherTextParam($key, $value) {
		$this->apiParas[$key] = $value;
		$this->$key = $value;
	}
}
