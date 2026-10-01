# 14: 门店手工新增单据（在线新增 + xlsx 导入），撤掉门店补传清单

- Type: task
- Status: done（2026-10-01；提交 `df1e036`、收口 `b7e4566`，gitee 与 origin 均已跟上）
- Blocked by: 无（13 已完成）
- 关联：`docs/adr/0010`（`fromUserId`/`toUserId` 语义待确认项）、`docs/adr/0011`（补传元数据取自落库行——本票是它的**例外**，见下）、`docs/adr/0012`（凭据 1:1，由服务端按门店取）、`docs/adr/0013`（2 年下限）、被本票撤掉的 `issues/07`/`issues/10`/`issues/12`（门店补传清单）

## 要交付的行为

用户 2026-10-01 原话：

> 手动上传模块，门店的补传去掉，这个跟上传任务其实是重复的！改成跟河药一样的"在线新增"和"xlsx 批量导入"，只是门店在线新增有2种单据不需要"往来单位名称"，选择这2种单据时不显示往来单位名称这个框即可

手动上传页选门店后，显示的内容**与批发分支同构**：左边"在线新增"卡片、右边"xlsx 批量导入"卡片（含下载模板），
不再有那份与上传任务页重复的补传清单。

- **单据类型下拉 = 门店的那 4 种**（`104` 调拨入库 / `203` 调拨出库 / `321` 使用出库 / `116` 消费者退货入库）
- **`321`/`116` 不显示"往来单位名称"**：这两种走 `lsyd.uploadretail`，接口里根本没有对手方入参（对手是消费者，
  不是平台注册的往来单位）。选中时该输入框隐藏、值清空、`required` 摘掉
- **`104`/`203` 显示"往来单位名称"**：这两种走 `lsyd.uploadinoutbill`，`fromUserId`/`toUserId` 是平台必填，
  服务端拿填的名称去平台查 `ent_id`
- 落库与上传走**门店自己的那套凭据**（`Enterprise::credentialFor()`，不由调用方给，ADR 0012）

## 用户拍板的四个决策（2026-10-01）

| 决策点 | 选择 | 后果 |
|--------|------|------|
| 哪 2 种不显示"往来单位名称" | **`321` 使用出库 + `116` 消费者退货入库** | 与接口必填集一致：`uploadretail` 无对手方字段、`uploadinoutbill` 必须有 |
| `104`/`203` 的 `from`/`toUserId` 从哪来 | **按名称调平台查 `ent_id`**（本店凭据查，与批发同款） | 要把 `ApiClient::queryEntInfo` 里写死的河药 `ref_ent_id` 参数化；门店 AppKey 若未被授权 `listparts` 会查不到 → fail-closed 拒建单（不静默错报） |
| 旧门店清单的删除范围 | **全删** | 页面清单/筛选/导出/批删/行操作 + `api/manual_retail_tasks.php` + `api/tasks_batch_retry_retail.php` + `RecordQuery::TYPE_RETAIL_TASKS` + `export` 的 `retail_tasks` 分支 + 对应测试断言。单条补传（上传任务页门店行的"补传"）保留 |
| 手工新建的门店任务行 `source` | **沿用 `'retail'`** | 上传任务页的补传按钮、门店徽标、2 年超期清理（`cleanup_logs`）全都自动覆盖；代价是来源列显示"零售采集"，手工录入的行也这么显示（要分辨只能看日志来源 `manual`） |

## 与 ADR 0011 的关系（第一件要说清的事）

ADR 0011 定的是**补传**：元数据（`from_user_id`/`to_user_id`/`physic_type`）一律取自采集时落库的行，
理由是"手工录 4 个平台 ID 几乎必然出错，且本轮不查平台，录错了察觉不了"。

本票新增的是**从零建单**——没有源表行可取，那三列必须现场确定：

- `physic_type` 取常量 `'3'`（源表 `zsm_ls.physic_type` 实测全表恒为 3，批发链路也硬编码 `"3"`）
- `from_user_id`/`to_user_id` **不由人录平台 ID**：人录的是**往来单位名称**，服务端用该门店的凭据调平台
  查 `ent_id`，再按单据类型的发货/收货语义落位。人手上没有"把单据报到错误主体"的机会——与批发分支
  （`ent_name` → `ent_list`/API → `ent_id`）是同一套做法

**与采集单的一处已知不一致**（ADR 0010 的待确认项所致）：采集单照搬源表同名列、**不按语义翻转**
（源表 104/203 的 `from_user_id` 都只有总部一个值）；手工单没有源表可照搬，只能按 SDK docblock 的
发货/收货语义合成（`104`：from=对方、to=本店；`203`：from=本店、to=对方）。两者对 `203` 的取值因此**可能不同**。
外部系统工程师确认后要一起对齐——本票把这条不一致写进 ADR 0015，不假装它不存在。

## 范围

### 新增

- `src/EntDirectory.php`：往来单位名录——`(company, ent_name)` 命中 `ent_list` 缓存则直取，否则用传入凭据
  （含其 `ref_ent_id`）调 `ApiClient::queryEntInfo` 并回写缓存。批发与零售手工建单共用一份。
  `UploadService::resolveEntId` 改为委托它（顺带还上"`queryEntInfo` 内部仍读 `.env`"那笔留债——
  河药凭据的 `ref_ent_id` 本就取自 `.env` 同一键，行为不变）
- `src/RetailManualEntry.php`：门店手工建单的**唯一实现**（在线新增与 xlsx 导入共用）
  - `needsCounterparty()` / `endpoints()`：纯规则（哪 2 种要对手方、对手方 `ent_id` 按语义落在 from 还是 to）
  - `prepare()`：校验 → 取凭据（fail-closed 三态）→ 解析对手方 → 返回补全后的单据（**平台往返只在这一步**，
    失败即拒建单：不落库、不发上传调用）
  - `create()`：落库（`source='retail'`、`credential`=该门店那套、带当次用的三列）→ 交给
    `RetailRetransmit` 上传（日志来源记为 `manual`，与补传的 `retail_retry` 区分）
- `src/api/manual_create_retail.php`（在线新增，NDJSON 流式，同 `manual_create`）
- `src/api/manual_import_retail.php`（xlsx 导入，NDJSON 流式，同 `manual_import`）
- `src/BillSheetParser.php`：xlsx → 按单号分组的解析（`manual_import` 与 `manual_import_retail` 共用一份，
  免得"同单号多行合并"这类规则出现两种实现）

### 改动

- `src/ApiClient.php`：`queryEntInfo(string $entName, ?string $refEntId = null)`
- `src/UploadService.php`：`resolveEntId` 委托 `EntDirectory`
- `src/api/template_download.php`：加 `?type=retail`（示例行用 `321`/`104`，表头注明"321/116 不填"）
- `src/views/manual_upload.php`：门店分支重写；两个分支的新增表单与导入按钮**共用同一段处理逻辑**（传参区分端点）
- `src/views/upload_tasks.php`：编辑弹窗对零售行**隐藏"往来单位名称"**——零售行的对手方 ID 在建单时冻结，
  改名称不会重解析，留着这个框等于留一处"改了不生效"的静默陷阱
- `src/RecordQuery.php` / `src/api/export.php` / `tests/record_query_test.php`：撤掉 `retail_tasks` 第 4 类

### 删除

- `src/api/manual_retail_tasks.php`、`src/api/tasks_batch_retry_retail.php`

## 不做（本轮）

- **`104`/`203` 的真传**：ADR 0010 的发货/收货语义仍待外部系统工程师确认。手工建单**不会**因此被拦
  （页面照旧放行），但验收里不真传 104/203——与 06/07 票的口径一致
- **批发的表单与导入行为**：一字不改（只把它那两段处理逻辑抽成带参数的函数，行为逐条核对）
- **门店补传清单的替代品**：它全部的能力都在上传任务页（筛选/导出/编辑/删除/补传），本票不再造第二份

## 验收

**实测环境**：项目副本（`/tmp/stage14`：`src`/`public`/`config`/`vendor`/`top_sdk` + 生产库 `.backup` 副本）
+ `php -S 127.0.0.1:8201`，**全程没有碰生产库**。平台侧用**离线桩**（`php -S 127.0.0.1:8202`，
只按 `method` 回平台形状的 XML、记录收到的出网参数、**不转发任何请求**）——副本里把
`TopClient::$gatewayUrl` 指向它（**仅副本**，仓库里的 vendored SDK 未改）。因此下面的"上传成功"
都是桩的应答，**不是真传**；真实申报仍由用户在页面上自己触发（平台写入最小化，见验收末尾）。
副本用毕已删除（含凭据）。

**诚实标注（先读这条）**：部署机**没有 JS 引擎**（node/deno/浏览器都没有），所以**所有客户端交互都没在
浏览器里跑过**——类型切换时的显隐、按钮禁用、提交流程、导入按钮，全部只做了静态自检：内联脚本括号配对
（带字符串/模板串/正则识别的扫描器，通过）、JS 引用的 DOM id（14 个静态 + 26 个绑定 opts 里的）逐个命中
渲染出的 HTML、注入页面的 `retailStores` 取值与配置现状一致（15 家店 ready 5 / pending 10）。
下面凡涉及"点了会怎样"的，一律标为**代码路径核对，待用户页面实测**。

- [x] 手动上传页选门店后是"在线新增 + xlsx 批量导入"两张卡片，没有补传清单
  - 副本实测（渲染出的 HTML）：门店分支含 `retail-manual-form` / `rm-bill-type` / `rm-btn-import` 等 8 处新标记；
    旧清单的 `retail-tbody` / `retail-pagination` / 筛选栏 / 批量补传按钮 / 三个弹窗**全部消失**（grep 零命中）
  - **代码路径核对（未在浏览器跑）**：选门店时 `branch-retail` 显示、`branch-wholesale` 隐藏
- [x] 单据类型下拉只列门店那 4 种；选 `321`/`116` 时"往来单位名称"隐藏且不参与校验，选 `104`/`203` 时显示且必填
  - 服务端判据实测：`999` / `201` 都被拒（"不是门店单据类型"）；`104` 不带往来单位被拒（"需要往来单位名称"）
  - 页面上"321/116 不显示"的判据与后端 `RetailManualEntry::needsCounterparty()` 同一套（`['104','203']`）
  - **代码路径核对（未在浏览器跑）**：`syncRetailEntNameField()` 隐藏时同时清空值并摘掉 `required`
- [x] 在线新增落库为门店单据：`company`=所选门店、`credential`=该门店凭据键、`source='retail'`、状态随结果翻
  - 副本实测（321 → 宝源店）：`{source: retail, credential: main, task_status: 已处理, response_status: 上传成功,
    request_status: 请求成功}`；`upload_logs` 一行 `source=manual`、同 `task_id`；JSONL 同步落盘
- [x] `104`/`203` 落库带 `from_user_id`/`to_user_id`/`physic_type`（按语义落位），`321`/`116` 三列为空
  - **用新江分店验的**（15 家里唯一 `ref_ent_id ≠ ent_id` 的门店，ADR 0010 的同一手法）：桩记录的出网参数为
    `listparts{ref_ent_id=61873868…(本店 ref_ent_id), ent_name=总部（桩）}`、`uploadinoutbill{from_user_id=MOCK-PARTNER-ENT-ID(对方),
    to_user_id=621988eb…(本店 ent_id), ref_user_id=61873868…(本店 ref_ent_id), physic_type=3, client_type=2}`——
    两个 ID 没有互换、落位方向对（104 入库：from=对方、to=本店）；任务行三列与出网参数逐项一致
  - 321 行三列皆空串，且装配出的请求里**没有**对手方字段
  - `ent_id` 与 `ref_ent_id` 相同的门店（如宝源店）在桩上另跑了一次，落库 `to_user_id` 即该门店 ID
- [x] 待配凭据 / 未声明凭据位 / 不在配置的门店：页面禁用并写明是哪一种原因，服务端同样拒建单
  - 副本 HTTP 实测：待配凭据门店 → `400 门店「…」的凭据尚未配齐（AppKey/SECRETKEY 未到手），拒绝建单`；
    批发主体 → `400 「…」不是门店（零售企业）`；不在配置的企业名 → 同一条（`isRetail` 判否，单测覆盖）
  - **代码路径核对（未在浏览器跑）**：三态文案与按钮禁用（`applyRetailAvailability()`）
- [x] 单据日期早于 2 年截止日的直接拒（与采集同一个 `App\RetailRetention`）
  - 副本 HTTP 实测：`2023-07-19` → `400 单据日期 2023-07-19 早于保留期截止日 2024-10-01——平台不接受 2 年前的单据…`；
    单测另钉住**截止日当天仍可建单**的边界
- [x] xlsx 导入：同单号多行合并、`321` 行的往来单位列留空也算合法、非法类型/缺往来单位逐行报错并跳过
  - 副本实测（6 组 3 成 3 拒）：321 两行合并成 `CODE-1,CODE-2` 一条并入队上传；116 填了往来单位也照样过（后端忽略）；
    104 走 `listparts` 后落 `ent_name`+三列；`999`、`104` 缺往来单位、2023 年的单**各自逐行报错并跳过**，
    其余照常导入；`_final` 汇总 `total=6, success_count=3, error_count=3`
- [x] 上传任务页上手工建的门店行有"补传"按钮（`source='retail'` 判定的自然结果）
  - 副本实测：`api/tasks?company=宝源店&search=MOCKTEST321` 回该行（`source=retail`、门店名、`credential=main`）
  - **代码路径核对（未在浏览器跑）**：门店行的判定是 `r.source === 'retail'`，未改
- [x] 旧门店清单的能力全部消失且**没有残留引用**
  - `manual_retail_tasks` / `tasks_batch_retry_retail` 两个端点副本实测 → `404 API not found`；
    `export&type=retail_tasks` → `400 无效的 type 参数`（`type=tasks` 仍 200）
  - `RecordQuery` 第 4 类与导出的门店分支已删，`record_query_test.php` 相应断言改为三页口径；
    全仓 grep 后仅**历史票面**（07/10/11/12/13）保留当时的原文，活文档（CLAUDE.md / CONTEXT.md / spec.md）已同步
- [x] 批发分支行为不变（在线新增 / 导入 / 模板下载逐条核对）
  - 两个分支的提交与导入改走**同一段前端逻辑**（`bindCreateForm` / `bindImportCard`，只传参），
    批发那侧的端点、载荷字段、清表单与 spinner 处理逐条比对无变化
  - 副本实测（都走离线桩）：批发在线新增 `manual_create`（201）→ 落库 `source=manual`、上传成功；
    批发 xlsx 导入 `manual_import`（改用共用 `App\BillSheetParser` 之后）→ 3 组 1 成 3 错，
    错误消息与改前逐字一致（行号指认、类型白名单、"往来单位为空"）；
    批发模板 `template_download`（不带 `type`）→ 文件名与列头与改前一致
  - 顺带删掉批发导入卡片里那条**从未被任何 JS 引用**的进度条标记（`import-progress` / `import-bar`）
- [x] 全部测试通过
  - 7 个测试文件全绿；新增 `tests/retail_manual_test.php`（33 项断言）
  - **变异验证**（4 组，均已恢复）：落位翻转 → 4 FAIL；把 `203` 从"需要往来单位"里漏掉 → FAIL；
    `PHYSIC_TYPE` 改成 `'2'` → 2 FAIL（**第一次变异没抓住**——原断言拿常量自己比自己，已改成字面量 `'3'`）；
    绕过 2 年下限 → FAIL
  - **未真传平台**：桩上跑的"上传成功"全是桩的应答。真实申报（含 `104`/`203` 的首次真传）仍待用户
    在页面上触发——ADR 0010 的发货/收货语义那条待确认项**没有解除**，手工建单这侧按语义合成，
    与采集单照搬源表的取值对 `203` 可能不一致（见 ADR 0015）

## Comments

### 2026-10-01 实现笔记

- **`EntDirectory` 顺手还了一笔留债**：`ApiClient::queryEntInfo()` 的 `ref_ent_id` 从写死的 `.env` 河药值
  改为调用方传入；`UploadService::resolveEntId()` 委托它。批发行为不变的证据：副本实测批发 `listparts` 的
  `ref_ent_id` 仍是 `.env` 的 `REFENTID_HYYY`（河药凭据本就引它），且导入/新增两条批发链路都跑通。
- **`319`/`104` 的 `physic_type` 取常量 3**：源表实测全表恒为 3，批发链路也硬编码 `"3"`。
- **一处需用户确认的运营前提**：门店 AppKey 是否被授权调 `listparts`（查往来单位）。未被授权时
  `104`/`203` 的手工建单会以"往来单位查不到"被拒（fail-closed，不会错报主体），`321`/`116` 不受影响。
  桩上无法验证这一点——真实验证需要一次只读的 `listparts` 调用（用某家门店的凭据查一个真实往来单位名）。

### 2026-10-01 code-review 收口

两条轴各跑了一个子代理（Standards / Spec），findings 逐条处置：

**改了**

| 轴 | 问题 | 处置 |
|----|------|------|
| Standards 1 | `CLAUDE.md` 表结构四行未同步（`source` / `from_user_id` / `to_user_id` / `physic_type`） | 四行都补上"手工建的怎么来"，`source` 行写明两个来源共用同一个值 |
| Standards 2 / Spec 3 | `ADR 0011` 缺指向 0015 的修订注 | 加在文首修订块（①推翻"不提供从零录入" ②"两个入口"收窄为单条 + 手工建单，并点出批量补传的缺口） |
| Standards 3 | `CLAUDE.md` 仍称"四个页面的类型标签表" | 改"三个数据页"，并注明手动上传页那份随清单一起删了 |
| Standards smell 1 | 导入端点的 `$rejectLine` 与刚删掉的 `rejectedProgress()` 七键同形 | **恢复 `RetailRetransmit::rejectedProgress()`**（这次它有真调用方了），导入端点改调它——"拒绝行与真实结果同形状"回到一处定义 |
| Standards smell 2 | `queryEntInfo($ent, ?string $refEntId = null)` 的 null 回落是死分支 | 参数改**必传**并去掉 `.env` 回落（那条回落正是"拿河药名录查门店往来单位"的错主体路径） |
| Standards smell 3 | 「哪两类要对手方」前端手抄一份 | 常量为 public，页面**注入** `RETAIL_TYPES_WITH_PARTNER`（判据与 `needsCounterparty()` 同一处取值） |
| Standards smell 4 | `new ApiClient(appkey, secretkey)` 三处手挑字段；追溯码归一两处新拷贝 | 加 `ApiClient::forCredential($credential)`（只挑两字段，挑错即错主体）；抽 `TraceSplitter::normalizeInput()`，批发建单 / 门店建单 / xlsx 解析三处共用，并补 6 条边界断言 |
| Standards smell 5 | `EntDirectory::resolve(company, entName, client, refEntId)` 参数可配错（client 与 refEntId 同出一份凭据） | 签名改收**一个凭据**，内部自己取 client 与 `ref_ent_id`——从构造上免掉"拿甲的名录查乙的往来单位" |
| Spec (c) 2 | 被 400 拒时表单照样被清空（粘好的追溯码连单号一起丢） | `streamFetch` 改为回"这次成功了吗"，两个绑定只在成功时清表单 / 清文件选择 |
| Spec (c) 4 | ADR 0015 §4 写"physic_type 取常量 3"，实际 321/116 三列留空 | 措辞订正为"对手方三列只对调拨两类写" |
| Spec (c) 5 | 导入汇总的"成功数"把平台业务拒传也算成功 | `create()` 之后按 `failed === 0` 计成功，否则进 `errors`（"上传未成功（子单成功 0 / 失败 1）"） |
| Spec (b) | 门店模板文件名、`streamFetch` 抽 error 字段、删死进度条标记 | 票面已声明，保留 |

**没改（附理由）**

- **Spec (c) 1：批量补传没有出口**（中）—— 认定成立，但**修它=新开一票的功能**：上传任务页的批量重传走的是批发 kyt 端点，要支持零售得按行分流到 `RetailRetransmit`，那是对生产批发端点的改造，超出本票"撤掉清单"的范围。已在 CLAUDE.md 与 ADR 0015 各记一条**已知缺口**，并当面报给用户。补传是不可逆的真实申报，逐条是更保守的默认。
- **Standards smell 6：`prepare()` 的名字看不出内含平台往返**（低）—— 名字在 ADR 0015、票面与三处注释里都已固定，改名要同步四处文档；类注释首段已写"平台往返只在这一步"，判断为收益不抵churn。

**收口后复验**：7 个测试文件全绿（`trace_splitter_test.php` 因新增 `normalizeInput` 断言从 16 组变 22 组）；新建副本 + 离线桩复跑：门店导入（含一单平台业务拒传）汇总 3 单 → 成功 2 / 失败 1 且失败原因带子单数、门店 104 的 `from/to` 仍按语义落位、批发 `manual_create` 的 `listparts` 仍用 `.env` 的河药 `ref_ent_id`、页面注入的 `RETAIL_TYPES_WITH_PARTNER = ["104","203"]` 正确；内联 JS 括号配对与 40 个 DOM id 引用复核通过。副本与桩已删除。
