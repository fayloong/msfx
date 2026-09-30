# 零售 lsyd 上传的入参映射：`refUserId` 取凭据，`from/toUserId` 照搬源表列

- 状态：已接受（2026-09-30）

零售补传的请求装配（`App\RetailRequestAssembler::assemble()`）按下面的规则填参，**不发起任何平台调用**（纯函数，输入单据数据 + 凭据，输出请求对象 + 追溯码上限）。混淆任意一项都会把单据申报到**错误主体**，且在平台上不可逆，故这几条规则与那条待确认项都需要白纸黑字。

| 入参 | 来源 | 说明 |
|---|---|---|
| `billCode` / `billTime` / `billType` | 单据数据（`djbh` / `rq` / `bill_type`，类型码经 `BillType::normalize`） | |
| `refUserId` | **凭据的 `ref_ent_id`** | 不是凭据的 `ent_id`，也不是源表 `zsm_ls.ref_ent_id` |
| `traceCodes` | 单据数据（`trace_codes`） | |
| `clientType` | 常量 `"2"` | 仅 `lsyd.uploadinoutbill`（`uploadretail` 类里没有这个字段） |
| `physicType` | 源表 `physic_type` 列 | 仅 `uploadinoutbill`；**docblock 写"可不填"、`check()` 却强制非空——以 `check()` 为准** |
| `fromUserId` / `toUserId` | **源表同名列**（`zsm_ls.from_user_id` / `to_user_id`） | 仅 `uploadinoutbill`；**不按发货/收货语义翻转**（见下方待确认项） |

其余可选字段（`oper_ic_code` / `oper_ic_name`、地址、人名字段、`ignore_part_success_flag`、`remarks` 等）**一律不填**——少填比填错安全，等与外部系统工程师对齐后再决定是否补齐。

**请求类与追溯码上限一律取自 `Enterprise::route()`**，不硬编码类名、不硬编码 3500/10000：`104`/`203` → `lsyd.uploadinoutbill`（上限 10000）、`321`/`116` → `lsyd.uploadretail`（3500）。

## Context

- **两个接口的必填集不是同一套**，容易互相照搬出错。权威表述是请求类自己的 `check()`（由平台"是否必填"元数据生成，`TopClient::execute()` 调用前也会执行它）——本文不另抄一份清单，两个类的比对与码上限见 ADR 0009，免得副本与 `check()` 各自漂移。
- **`refUserId` 必须取凭据的 `ref_ent_id`**：SDK docblock 写死"该入参是 ref_ent_id，不是 ent_id"、"上传单据企业的单位编码（门店或医疗机构）"。源表 `zsm_ls.ref_ent_id` 列是**全表单一值**（总部主体），拿它当 `refUserId` 会把门店单据报到总部名下（2026-09-29 只读探测结论，见 `.scratch/retail-chain/probe-findings-2026-09-29.md`）。
- **判据不手抄**：`tests/retail_upload_test.php` 用 `check()` 当判据——装配后 `check()` 不抛即必填齐备；把 `getApiParas()` 里任一项清空后 `check()` 必须抛，即"装配只填了必填项、没有多填可选字段"。必填清单来自请求对象自身（`getApiParas()` + 键名反推 setter），SDK 升级后不会失真。
- **实测定案**：`ref_ent_id ≠ ent_id` 的用例形状取自新江分店（15 家里唯一一家，旧 entId 用到 2025-07、新 refEntId 自 2024-03 起）；用两值相同的门店做用例，`refUserId` 取错也测不出来。断言辨别力经变异验证：把实现改成取 `ent_id` → 5 项 FAIL；把 `from/to` 两列互换 → 3 项 FAIL。

## ⚠️ 待确认项（暂定）

SDK docblock 把 `fromUserId` / `toUserId` 写作**"发货企业 entId" / "收货企业 entId"**（发货/收货语义）；而源表 `104`（门店收货）与 `203`（门店发货）两类的 `from_user_id` **都只有总部一个值**、`to_user_id` 才是门店，**两列不随调拨方向翻转**。若严格按发货/收货语义，`203` 的两列应当反向使用。

这条**无法从代码自证**，是整个装配里唯一"错了就不可逆"的地方。用户 2026-09-29 定案**照搬源表同名列**，此处标为**暂定**；待向外部系统工程师（真正的上传方）确认后，**只改 `RetailRequestAssembler` 里那两行取值与测试中 `203` 那条断言**，其余代码不动。

## 排除的方案

- **`refUserId` 取源表 `zsm_ls.ref_ent_id`**：那列全表单一值、属总部主体，会让门店单据报到总部名下。
- **`refUserId` 取凭据的 `ent_id`**：SDK docblock 明说这个入参是 ref_ent_id；两者在新江分店上确实不同值，取错即错主体。
- **测试里手抄一份必填清单**：会随 SDK 升级失真，且与 `check()` 这个权威来源形成两份事实。
- **装配时回源库现查 `physic_type` / `from_user_id`**：装配必须是零网络调用的纯函数（本票约束），补传链路上也不该多一次源库往返。
- **装配多填可选字段（`oper_ic_code` 等）**：本轮不填——少填比填错安全；`getApiParas()` 清空断言会把"多填"直接测红。

## Consequences

- **装配的输入形状**是落库任务列 + 源表同名列（`djbh` / `rq` / `bill_type` / `trace_codes` / `from_user_id` / `to_user_id` / `physic_type`）。后三列的落库路径**已由工单 06 补齐**（`upload_tasks` 加三列、`fetch_bills_retail.php` 采集时照搬源表同名列），怎么补、旧行怎么处理见 `docs/adr/0011`。
- **非零售企业与错配凭据一律拒装配**（fail-closed）：批发、`未识别`、未知企业、无路由的单据类型都抛 `\RuntimeException`；传入的凭据还必须**确实属于该企业**（凭据位 + `ref_ent_id` 比对）——`(企业, 凭据)` 是调用方给的两个独立参数，配错即错主体，而这一步 `check()` 拦不住（拿总部凭据装配门店单据，参数字段全都"合法"）。与 `UploadService::resolveContext` 同向：它用"按企业 + 凭据键现取"从构造上避免了错配，本接缝收的是凭据数组本身，故显式比对。批发链路有自己的装配（`UploadService::uploadSingle`），拿零售模板装配批发单据同样是把单据报到错误主体。
- **装配末尾调 `check()`**：缺任必填项即拒绝装配（错误消息带单号）。挡住的是"装配出一个必被平台退回、却已经把单号占掉的请求"。
