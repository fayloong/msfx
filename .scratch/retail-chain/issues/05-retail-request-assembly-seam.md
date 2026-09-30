# 05: 零售补传的请求装配 + 测试接缝

- Type: task
- Status: done（2026-09-30）
- Blocked by: 04
- 关联：spec.md §3、Testing Decisions（本 feature 收敛为 2 个接缝，这一票落地第 2 个）、docs/adr/0008-retail-claim-by-platform-id.md

## 要交付的行为

给定一条**已落库的零售单据 + 一套门店凭据**，能装配出一个参数完全正确的 lsyd 请求对象——
且装配过程**不发起任何网络调用**，可以在测试里断言。

这是本 feature **唯一新增的测试接缝**（第 1 个 `App\Enterprise` 已随工单 01 落地）。**不新增第三个接缝**：
采集 SQL 的去重与码拼接、Web 页面交互都不写单元测试，前者靠"指定日期采集后与源库去重单号数比对"的人工验收（工单 03 的验收标准），后者靠页面实测。

## 必填参数：两个接口不一样，不要互相照搬

**权威来源是请求类自己的 `check()`**（由平台的"是否必填"元数据生成，漏填就抛异常）。官方文档与之一致：
[uploadinoutbill](https://open.fliggy.com/docs/api.htm?apiId=52555) / [uploadretail](https://doc.alidayu.com/docs/api.htm?apiId=52554)。

| | `lsyd.uploadinoutbill`（104 / 203） | `lsyd.uploadretail`（321 / 116） |
|---|---|---|
| `check()` 强制非空 | billCode、billTime、billType、**clientType**、**fromUserId**、**physicType**、**refUserId**、**toUserId**、traceCodes | billCode、billTime、billType、**refUserId**、traceCodes |
| 码上限（`checkMaxListSize`） | 10000 | 3500 |
| `clientType` | 必须填 `"2"` | **这个类里根本没有这个字段**，不要设 |
| `physicType` | 必填。docblock 写"可不填"、`check()` 却强制非空——**以 `check()` 为准** | 不填（docblock："可以随便填写，单据上传后会以实际为准"） |
| `fromUserId` | 必填 | docblock 写明"发货企业**(可为空)**" → **不填** |
| `toUserId` | 必填 | 这个类里没有这个字段 |

（`billType` 在 uploadretail 上还接受 `322 疫苗接种`，但本项目只采四种单据类型，不涉及。）

**填值规则：只填 `check()` 强制的必填项，加上源库能直接确定的值**，其余可选字段一律不填——少填比填错安全。
`oper_ic_code` / `oper_ic_name`（单据提交者）、地址、人名字段等都不填；等与外部系统工程师对齐后再决定是否补齐。

## 参数映射（写错任意一项都会把单据申报到错误主体，且在平台上不可逆）

- `refUserId` ← **凭据里的 `ref_ent_id`**，**不是**源表 `zsm_ls.ref_ent_id`（那一列全表单一值、属总部主体）。
  SDK 的 docblock 也写死了这一点："该入参是 ref_ent_id，不是 ent_id"、"上传单据企业的单位编码（门店或医疗机构）"
- `fromUserId` / `toUserId` ← **源表同名列**（`zsm_ls.from_user_id` / `to_user_id`），仅 `uploadinoutbill` 使用。
  用户 2026-09-29 定案。
  - ⚠️ **一处待确认，实现时不要自行改掉**：SDK 的 docblock 把 `fromUserId` 写作"发货企业 entId"、`toUserId` 写作"收货企业 entId"（发货/收货语义）；而源表 `104`（门店收货）与 `203`（门店发货）两类的 `from_user_id` 都只有总部一个值、`to_user_id` 才是门店，**两列不随调拨方向翻转**。若严格按发货/收货语义，`203` 的两列应当反向使用。这条**无法从代码自证**，是整个装配里唯一"错了就不可逆"的地方——建议向外部系统工程师确认（它才是真正在上传这些单的人）。确认结果直接改本票的这条断言，其余代码不用动
- `physicType` ← 源表 `physic_type` 列（实测全表恒为 `3`）
- `clientType` ← 常量 `"2"`（仅 `uploadinoutbill`）
- 请求类与追溯码上限一律取自 `App\Enterprise::route()`（工单 01 已就绪），**不硬编码**；路由表里的上限应与 SDK 的 `checkMaxListSize` 一致（10000 / 3500）

**零售链路不查 `ent_list`**：对手方 ID 直接来自源表的 `from_user_id` / `to_user_id`，不需要往来单位缓存，也不需要企业名。

## 测试

新增 `tests/retail_upload_test.php`：

- 自包含断言脚本、无框架、失败非零退出，对齐 `tests/enterprise_config_test.php` 的风格
- fixture 用**固化数组**：不连生产库、不依赖部署机的真实凭据文件（`config/enterprises.local.php`）
- 断言 `getApiParas()`，不 mock 平台 API
- **直接用请求类的 `check()` 当判据**：必填项填齐后 `check()` 不应抛异常；抽掉任一必填项后 `check()` 必须抛。
  平台强制的契约由 SDK 自己表达，比在测试里手抄一份必填清单可靠（也不会随 SDK 升级而失真）

**ADR**：落一条，记零售上传入参的映射规则——两个接口各自的必填集、`refUserId` 取凭据而非源表、
`from/toUserId` 照搬源表列（连同上面那条待确认项，标为暂定）。

## 验收

- [x] `uploadinoutbill` 用例（`104`/`203`）：`getApiParas()` 中 `client_type = "2"`、`physic_type` 非空、
      `ref_user_id == 凭据.ref_ent_id`、`from_user_id` / `to_user_id` == 源表对应列；并通过 `check()`
- [x] `uploadretail` 用例（`321`/`116`）：`getApiParas()` **不含** `client_type`（该类无此字段）、
      **不含** `from_user_id`（可为空，本轮不填）；必填项齐备并通过 `check()`
- [x] **必须包含一个 `ref_ent_id ≠ ent_id` 的用例**：把 `refUserId` 与 `ent_id` 对换，测试必须变红
  - 新江分店是 15 家里唯一这样的门店（旧 entId 用到 2025-07、新 refEntId 自 2024-03 起，两个 ID 都在源库出现）——
    用例的**形状**照它构造，**取值用占位符**（平台 ID 属凭据级信息，不入仓）
  - 用 `ref_ent_id == ent_id` 的门店做用例，互换了也测不出来，等于没测
  - **变异实测**：把实现的 `setRefUserId` 改成取 `ent_id` → **5 项 FAIL**；另加一条 `from/to` 两列互换的变异 → **3 项 FAIL**
    （fixture 里 `from_user_id ≠ to_user_id`，`from/to` 照搬那两条断言同样有辨别力）
- [x] 两个接口各一个用例，断言路由确实按 `(企业类型, 单据类型)` 走而不是硬编码
  - 四个类型逐个断言 `Enterprise::route()` 给的类，且 `get_class($assembled['request'])` 与之相等
- [x] 码上限断言取自路由：`104` → 10000、`321` → 3500（不是全局常量 3500）
  - 另断言"路由上限 == SDK 自己的 `checkMaxListSize`"：`104` 恰好 10000 个码装配通过、10001 个码装配拒绝；
    `321` 恰好 3500 通过、3501 拒绝（上限取自路由，SDK 侧不硬编码）

## 实现笔记（超出票面的必要处理）

- **⚠️ 装配的三个字段没有落库路径（本票定案：只做纯函数，不动表结构）**：`from_user_id` / `to_user_id` /
  `physic_type` 在 `upload_tasks` 里没有列，`fetch_bills_retail.php` 只把前两列用于**认领**、连 `physic_type`
  都没 `SELECT`。票面写的输入是"已落库的零售单据"，而落库行给不出这三个值——**开工前已就此问过用户**，
  定案：本票只交付纯函数接缝（输入数组带这三列，形状＝落库任务列 + 源表同名列），
  **补齐落库路径（加列 + 采集写入）留给工单 06**（其票面写着"元数据全部取自采集时落库的记录"，届时必须先补）。
  详见 `docs/adr/0010` 的 Consequences
- **`RetailRequestAssembler` 自己 `require_once top_sdk/TopSdk.php`**：请求类不在 composer 的 autoload 里，
  由 SDK 自己的 Autoloader 加载；本类不依赖 `ApiClient`（纯函数），故照 `ApiClient` 的写法在文件顶部引入
- **`method_exists($req, 'setClientType')` 做接口能力探测**，不做类名 `if/else`：`clientType` 是
  `uploadinoutbill` 独有的字段（`uploadretail` 类里根本没有），用它判定"这批参数只有 uploadinoutbill 需要"；
  票面要求的"不硬编码类名"因此也覆盖了装配分支
- **装配末尾调 `check()`（fail-closed）**：缺任必填项即抛带单号的 `\RuntimeException`，
  挡住的是"装配出一个必被平台退回、却已经把单号占掉的请求"；`TopClient::execute()` 调用前本来也会 `check()`
- **测试不手抄必填清单**：`checkOnlyRequiredParas()` 从 `getApiParas()` 的键反推 setter、逐项清空后断言
  `check()` 必抛。既钉住"必填项齐备"，又钉住"没多填可选字段"——多填的项清空后 `check()` 不抛，直接测红
- **非零售一律拒装配**（批发 / `未识别` / 未知企业 / 无路由的单据类型），与 `UploadService::resolveContext`
  同向的 fail-closed：拿零售模板装配批发单据同样是把单据报到错误主体
- **未被任何链路调用**：本票只交付接缝本身（纯函数 + 测试），接入点是工单 06 的补传流程
