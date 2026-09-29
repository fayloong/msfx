# 04: 把 lsyd 请求类并入 top_sdk/

- Type: task
- Status: done（2026-09-30）
- Blocked by: None（可与 02/03 并行）
- 关联：spec.md §4

## 要交付的行为

零售两个接口（`alibaba.alihealth.drugtrace.top.lsyd.uploadinoutbill` / `...uploadretail`）的请求类在本仓库内可用、
与零售 SDK 压缩包解耦；**批发链路一行代码都没变**。

## 范围

- **先比对**：`top_sdk_retail.zip` 与现有 `top_sdk/` 有 67 个同名文件，其中 31 个内容不同（30 个 domain + `TopClient.php`，而 `TopClient.php` 的差异只有 `sdkVersion` 一行）。逐个确认差异是**纯注释**还是**字段新增/重命名**
- 若差异仅为注释 → 只把 `AlibabaAlihealthDrugtraceTopLsydUploadinoutbillRequest`、`AlibabaAlihealthDrugtraceTopLsydUploadretailRequest` 及其依赖 domain 类并入 `top_sdk/top/request/`、`top/domain/`
- 若 `CodeRelationDto` 这类**码级对账已在用的** domain 有字段变化 → 升级为整体覆盖升级（连 `TopClient` 版本号一并处理），并在本票 Comments 里记下比对结论
- `TopClient` 是通用的，走哪个 API 完全由请求类的 `getApiMethodName()` 决定——**零售接口不需要"零售 SDK"**
- **不要**建独立目录 `top_sdk_retail/`：两个 `Autoloader.php` 都声明全局 `class Autoloader`（PHP 无 namespace，重声明 Fatal error），且 `TopSdk.php` 的 `TOP_AUTOLOADER_PATH` 常量第二个 SDK 会因已定义而跳过、自动加载指向错误目录；要修就得改 SDK 文件，而项目约定 `top_sdk/` 不可修改
- 压缩包本身**不进 git**
- **顺带记一笔**：并入后，两个请求类的 `check()` 就是"这个接口哪些参数必填、码上限多少"的权威表述
  （由平台的"是否必填"元数据生成）——工单 05 的测试直接拿它当判据；本票不必额外抄一份必填清单到文档里

## 验收

- [x] 两个 lsyd 请求类能实例化，`getApiMethodName()` 分别为 `alibaba.alihealth.drugtrace.top.lsyd.uploadinoutbill` 与 `alibaba.alihealth.drugtrace.top.lsyd.uploadretail`
- [x] `php tests/trace_splitter_test.php` 与 `php tests/quantity_check_test.php` 全绿
- [x] 批发上传路径无改动：`UploadService` 的 kyt 请求类仍能正常构造并取到参数

## Comments

### 2026-09-30 比对结论（工单 04 执行）

**票面的分支条件没有触发，且触发后也无法执行**——走的是主分支：只并入两个 lsyd 请求类，`top_sdk/` 既有文件一个字节没动（`git status` 只有两个 `??` 新增文件）。

**文件级事实**（与票面有出入的已标出）：同名文件 **68**（票面写 67）——完全相同 37 / 内容不同 **31** ✓；仅旧包有 **40**；仅新包有 260（188 request + 71 domain + `OapiTest.php`）。

**31 个差异文件逐项结论**：

- `top/TopClient.php`：唯一差异 `sdkVersion`（`top-sdk-php-20260203` → `top-sdk-php-20260929`）。按约定未动。
- **9 个 domain：纯注释差异**（`token_get_all` 剥注释后代码逐字节相同，只是注释文案/示例值变了）：`BillDealStatusSearchDo`、`CodeActiveInfoDto`、`CodeInfoListDto`、`CodeStatusTypeDto`、`PSynonymUserEntInfoDTO`、`PUserEntDto`、`Page`、`ResPSynonymDTO`、`ResultModel`
- **17 个 domain：只有新增属性**（无删除、无改名；21 个真代码差异文件的方法名集合全部一致）：
  | 文件 | 新增属性 |
  |---|---|
  | `CodeRelationDto` | `query_code_mix_flag`、`top_code_mix_flag` |
  | `AddEntReqDto` | `partner_id` |
  | `BaseInfoDto` / `DrugEntBaseDto` | `approval_licence_date`、`approval_licence_expiry`、`approval_licence_expiry_old`、`approval_licence_no_old` |
  | `BillChkInOutDo` | `crt_date` |
  | `BillInOutDetailDto` | `bill_out_id`、`codes`、`dis_ent_info_list` |
  | `BillUpOutDetailDo` | `approval_licence_no`、`ass_ent_id`、`ass_ent_name`、`ass_ref_ent_id`、`bill_detail_id`、`bill_upload_time`、`check_date`、`crt_date`、`mod_date`、`prepn_type_desc`、`prepn_unit_desc` |
  | `BillUpOutDetailDto` | `order_code` |
  | `BillUpstreamDTO` | `ass_ent_id`、`ass_ent_name`、`ass_ref_ent_id`、`bill_out_id`、`code_str` |
  | `CodeFullInfoDto` | `code_mix_flag`、`pkg_ratio` |
  | `Codeandparentlist` | `has_parent_code` |
  | `DrugInfosDto` | `approval_no`、`cfda_drug_id`、`physic_name`、`physic_type`、`physic_type_name`、`pkg_unit_desc`、`prepn_type_desc` |
  | `DrugTableDto` | `authorized_ref_name`、`produce_ref_name` |
  | `EntExtend` | `replace_ref_ent_id` |
  | `PEntParDto` | `org_code`、`shared` |
  | `ProduceInfoDto` | `mah_name`、`mah_ref_ent_id` |
  | `SubTypeList` | `approve_no_old` |
- **4 个 domain：有属性消失/改名**（唯一"会丢东西"的一类，未采用）：
  - `Model.php`：`add_sucess`/`check_msg`/`par_ref_ent_id` → `result`/`total_num`
  - `PageInfoDTO.php`：`page`/`page_size`/`pages` 消失，`result` → `result_list`
  - `Billchkinoutdetaillistdtolist.php`：`code_and_parent_list` → `code_info_list`
  - `PUserEntInfoDto.php`：`ent_capital_name` 消失

**两条推翻票面/ spec §4 前提的实测事实**：

1. **整体覆盖物理上做不到**。旧包 `top/request/` 的 34 个接口方法（批发链路在用的四个请求类 `AlibabaAlihealthDrugKytUploadinoutbillRequest` / `...SearchbillDetailRequest` / `...SinglerelationRequest` / `...ListpartsRequest` 都在其中），**新包一个都没有**——新包的 kyt 类全是 `drug.kyt.wes.*`（另一 API 世代，方法名不同）。覆盖 = `ApiClient` 的 `new \AlibabaAlihealthDrugKytSearchbillDetailRequest` 等立刻 Fatal error。
2. **"`CodeRelationDto` 这类码级对账已在用的 domain"这个前提不成立**。domain 类在本项目里完全惰性：`TopClient` 里没有任何 domain 相关代码（`$format="xml"` → `execute()` 把 `simplexml_load_string` 得到的 **`SimpleXMLElement`** 直接返回；`exec()` 只按方法名反推 request 类）；`src/` `scripts/` `tests/` `config/` `public/` 对 30 个 domain 类零引用。故 `query_code_mix_flag` 两个新字段对码级对账零影响。

**并入产出**：`top_sdk/top/request/` 新增 2 个文件（UTF-8 无 BOM、CRLF，与既有请求类同风格，`cp -p` 逐字节拷贝，md5 与压缩包内一致）。两个类**零依赖**：不 `new` 任何 domain 类、无 `extends`，只用到 `RequestCheckUtil`（旧包已有，两包该文件逐字节相同），故**没有 domain 类需要一并拷入**（票面"及其依赖 domain 类"实为空集）。

**验收**（一次性命令核对，未新增测试接缝）：

- 两个类经 SDK `Autoloader` 可加载、可实例化，`getApiMethodName()` 分别为 `alibaba.alihealth.drugtrace.top.lsyd.uploadinoutbill` / `...uploadretail`；塞满必填项后 `check()` 通过，`getApiParas()` 分别为 9 项 / 5 项（键名与工单 05 的断言口径一致）
- 批发回归：`new \AlibabaAlihealthDrugKytUploadinoutbillRequest`（同 `UploadService.php:177`）取到 `bill_code`/`to_user_id`/`from_user_id`/`dis_ent_id` 等 12 项，`ApiClient` 在用的三个查询类 `class_exists` 通过——全程本地构造，**未发任何平台请求**
- `php tests/trace_splitter_test.php`、`php tests/quantity_check_test.php`、`php tests/enterprise_config_test.php` 三个自包含测试全绿

**边界**：本票未验证"用这两个类真调平台的返回"——那要等工单 05 的装配接缝与门店凭据（且首调是**真实申报**，需用户批准）。

**顺带产出**：决策落 `docs/adr/0009`（含排除方案与"跟进新版 SDK 只能逐类挑"的后果）；`CLAUDE.md` 的 `top_sdk/` 段与 `spec.md` §4 同步修订。

### 2026-09-30 两轴审查（Standards / Spec）提出后逐条处理

两条轴各自独立复核了本票的全部技术断言（含解包压缩包重跑比对、复跑验收命令），**未发现缺失项、漏项或越界**；提出 3 条，全部处理：

- **响应类型名写错**（Spec 轴 (c) 类、Standards 轴同指）：`simplexml_load_string()` 返回 **`SimpleXMLElement`**，不是 stdClass——我原先 4 处都写成了 "simplexml → stdClass"。**承载的结论不受影响**（domain 类不参与响应解析、项目零引用，已独立核实为真），但照错的类型名写 `instanceof stdClass` 会踩。4 处（`CLAUDE.md` / `docs/adr/0009` / `spec.md` 修订注 / 本票 Comments）已改
- **"本项目在用的 34 个 `getApiMethodName()`"措辞不实**：34 是旧包 `top/request/` 的方法名总数，项目代码真正 `new` 的只有 4 个（`grep -rn 'new \\Alibaba' src scripts tests`）。实质结论（新包与旧包 34 个方法**交集为空**）经复核为真，已把三处措辞改为"旧包 `top/request/` 的 34 个接口方法，新包一个都没有"
- **比对数字在三处各存一份会各自漂移**：`spec.md` §4 修订注已瘦身为"结论 + 指向 ADR/本票"（不再复述 68/31/40/260），完整比对表只留本票 Comments、决策摘要留 `docs/adr/0009`

**未采纳（记录理由）**：ADR 的 Consequences 复述了必填项数（9/5）与码上限（10000/3500），与票面"本票不必额外抄一份必填清单到文档里"略有张力——但它不抄字段名，且与"以 `check()` 为准"的口径一致；这两组数字是工单 05 的判据，在 ADR 里留一句交叉引用比只留路径更耐用。
