**Status:** designing（2026-09-29 grilling 会话完成设计树，实现未开始；见 Comments 的开放问题）

# 零售连锁门店接入（多企业支持）

## Problem Statement

现有系统服务**单个批发企业**（河药，`.env` 里的 `APPKEY_HYYY` / `SECRETKEY_HYYY` / `REFENTID_HYYY` / `ENTID_HYYY`），凭据在代码里写死：`ApiClient` 构造函数默认取 `*_HYYY`，`searchBillDetail` / `queryEntInfo` / `searchSingleRelation` 都硬编码 `REFENTID_HYYY`，`UploadService::uploadSingle` 写死 `\AlibabaAlihealthDrugKytUploadinoutbillRequest`。所有数据页与日志表也没有"这张单属于哪家企业"的概念。

现在要接入一个**零售连锁企业**（多家门店，门店名以部署配置为准，权威清单待用户提供），带来四个维度的差异：

1. **凭据**：每个门店各有 AppKey / SecretKey，且**一个门店可能有多套**（门店与凭据是 1:N，不是 1:1）
2. **接口**：零售走 `alibaba.alihealth.drugtrace.top.lsyd.*` 系列，批发走 `alibaba.alihealth.drug.kyt.uploadinoutbill`——**同一个单据类型码在不同企业下走不同接口**
3. **数据源**：零售单据取自 `dyt` 链接服务器的 `zsm_ls` / `zsm_ls_code`，字段与批发（`skwms_new` 视图族）完全不同，且**单据自带 `from_user_id` / `to_user_id` / `ref_ent_id`**，不需要 `ent_list` 往来单位缓存
4. **上传主体**：零售单据由**外部系统上传**，本项目只做采集、展示与手动补传

另有两条**必须显式记录的口径差异**：零售的 `321 使用出库` / `116 消费者退货入库` 是**消费者级**单据，平台侧没有可对账的本地数量基线；`zsm_ls` 采集 SQL 原本带 `NOT EXISTS(dyt.bs_msfx.dbo.update_state)` 过滤，会把"外部系统已上传的单"全部隐藏掉——若照搬，对账基准面为空。

## Solution

引入**企业（Company）**与**凭据（Credential）**两个概念，把"用哪套 AppKey 调哪个接口"从代码常量提升为数据：

```
Company（门店，中文名）  1 ──── N  Credential（AppKey/SecretKey/ref_ent_id/ent_id/label）
                                        │
                                        └── bill_types 白名单 → 路由到接口类
```

- **配置**：`config/enterprises.php`（结构，进仓库）+ `config/enterprises.local.php`（凭据，gitignore）+ `config/enterprises.example.php`（模板）。河药批发一并迁入，不留两套机制
- **零售链路**：采集 `dyt` 源库 → 按 `oper_ic_name` 认领门店 → 落 SQLite（`company` + `credential`）→ Web 展示 → 人工手动补传（走 lsyd 接口）
- **本轮不做**：平台状态查询、数量对账（用户判定"暂时没有很好的办法可以对账"）
- **路由键**：`(company, credential, djbh)`——同一门店多套凭据时，主体 A 传成功不代表主体 B 传成功
- **排雷**：`upload_pending.php` 现按 `task_status='等待上传'` 取全部任务且无企业过滤，零售单据若以该状态落库会被**用河药凭据误传到平台**，取数口径必须一并改

## User Stories

1. 作为运维人员，我想要在网站每个页面上看到每张单属于哪家企业，以便区分河药批发的单与连锁门店的单
2. 作为运维人员，我想要按"所属企业"筛选三个数据页并能按该条件导出，以便只处理某家门店或只看批发的单据
3. 作为运维人员，我想要系统自动从 `dyt` 源库采集门店单据入库，以便不用人工登记门店单据
4. 作为运维人员，我想要门店单据自动认领到正确门店（按源表 `oper_ic_name`），以便不会把 A 店的单错派到 B 店
5. 作为运维人员，当某条单据的门店名匹配不上配置时，我想要它照常入库并标记"未识别"且页面显著可见，以便我发现"有单没被认领"而不是它凭空消失
6. 作为运维人员，我想要零售单据在库里有独立的任务状态，以便它不会被 upload_pending 这个自动上传 cron 拿河药凭据误传到平台
7. 作为运维人员，我想要零售单据不出现在失败记录页的告警里，以便我唯一的告警出口不被无关噪声污染
8. 作为运维人员，我想要在手动上传页顶部先选"所属企业"，再看到该企业对应的表单，以便批发与零售两套完全不同的字段不会互相干扰
9. 作为运维人员，我想要对已采集的零售单据手动补传（元数据自动带出），以便不用手工填 4 个平台 ID
10. 作为运维人员，我想要手动补传时由我显式选择用哪套凭据，以便门店有多套 AppKey 时不会传错主体
11. 作为运维人员，我想要零售的手动补传走 lsyd 接口、批发走 kyt 接口，且由单据类型自动决定，以便我不用记住接口映射
12. 作为运维人员，我想要一个门店多套凭据时页面上能区分记录，以便重传时能指明重传哪一条
13. 作为开发人员，我想要零售 SDK 的 lsyd 请求类以最小改动并入现有 `top_sdk/`，以便不引入第二套 autoloader / TopClient
14. 作为开发人员，我想要凭据不进 git 仓库，以便 AppKey/SecretKey 不会因双远程推送而泄露
15. 作为运维人员，我想要零售相关的采集与手动补传不触碰 `update_state` 表，以便不对生产库做难以回滚的写入

## Implementation Decisions

### 1. 多企业模型：凭据是路由主体，门店 1:N 凭据

**实测事实**：门店凭据草稿（本地文件 `tests/company.txt`，未入仓，用户声明数据不准确待更新）显示 9 家门店中 8 家曾共用同一个 AppKey——但用户明确最终形态是**"每个门店的 appkey、SECRETKEY 都不相同，每个门店也可能存在多套 appkey、SECRETKEY"**。故**不按 AppKey 建模，也不假设门店与凭据 1:1**。

```
Credential = { appkey, secretkey, ref_ent_id, ent_id, label, bill_types? }
Company    = { name(中文名), credentials: Credential[], source_names?: string[] }
```

- `label`：给下拉框显示的人读标签（如"主主体"/"零售主体"）；门店仅一套凭据时可省略
- `bill_types`：该凭据适用的单据类型白名单，缺省为全集。**本轮不实现自动分发规则**——零售只有人工手动补传会真正用到凭据，让用户在页面显式选择比猜规则可靠

### 2. 配置分三文件，凭据绝不入仓

| 文件 | 内容 | 入 git |
|------|------|--------|
| `config/enterprises.php` | 企业结构（门店名、凭据 label、`bill_types`、采集开关），引用 `enterprises.local.php` 取凭据 | ✅ |
| `config/enterprises.local.php` | appkey / secretkey / ref_ent_id / ent_id 明文 | ❌（已加 `.gitignore`） |
| `config/enterprises.example.php` | 模板，占位符 | ✅ |

河药批发**一并迁入**该结构，`Config::get('APPKEY_HYYY')` 等旧写法逐步下线（`.env` 保留数据库连接与管理员密码）。

### 3. 接口路由：(Company, 单据类型) → 接口类

**所有门店通用一套映射**（用户确认），且**同一类型码在批发/零售下走不同接口**：

| 企业类型 | 单据类型 | 请求类 | API |
|---|---|---|---|
| 批发 | 现有全部 | `AlibabaAlihealthDrugKytUploadinoutbillRequest` | `alibaba.alihealth.drug.kyt.uploadinoutbill` |
| 零售 | `104` 调拨入库、`203` 调拨出库 | `AlibabaAlihealthDrugtraceTopLsydUploadinoutbillRequest` | `alibaba.alihealth.drugtrace.top.lsyd.uploadinoutbill` |
| 零售 | `321` 使用出库、`116` 消费者退货入库 | `AlibabaAlihealthDrugtraceTopLsydUploadretailRequest` | `alibaba.alihealth.drugtrace.top.lsyd.uploadretail` |

**两个 lsyd 接口的入参差异**（源码实读）：

| | `lsyd.uploadinoutbill` | `lsyd.uploadretail` |
|---|---|---|
| 必填 | billCode, billTime, billType, **clientType(=2)**, **fromUserId(entId)**, **toUserId(entId)**, **physicType**, refUserId(ref_ent_id), traceCodes | billCode, billTime, billType, refUserId(ref_ent_id), traceCodes |
| 码上限 | **10000** | 3500 |
| 可选 | warehouseId, destUserId, operIcCode/Name, ignorePartSuccessFlag, drugListJson 等 | fromUserId, operIcCode/Name, physicType, userName/userTel/customerId/customerIdType, medicDoctor/medicDispenser/userAgent, networkBillFlag, remarks |

注意 `refUserId` 是 **ref_ent_id**，而 `fromUserId` / `toUserId` 是 **entId**（用户已确认 `zsm_ls` 三列语义与此一致）。

### 4. SDK 集成：只挑 lsyd 类并入现有 `top_sdk/`

**不引入第二套 SDK**。实测事实：`top_sdk_retail.zip` 与现有 `top_sdk/` 有 **67 个同名文件**，其中 31 个内容不同（30 个 domain + `TopClient.php`），而 `TopClient.php` 的差异**只有 `sdkVersion` 一行**（`top-sdk-php-20260203` → `20260929`）——即零售包是同版本 SDK 的新版，不是另一套 SDK。

**排除的方案**：独立目录 `top_sdk_retail/` 会直接坏——两个 `Autoloader.php` 都声明全局 `class Autoloader`（PHP 无 namespace，重声明 Fatal error），且 `TopSdk.php` 的 `TOP_AUTOLOADER_PATH` 常量第二个 SDK 会因已定义而跳过，自动加载指向错误目录；要修就得改 SDK 文件，而项目约定 `top_sdk/` 不可修改。

**采用**：把 `AlibabaAlihealthDrugtraceTopLsydUploadinoutbillRequest.php`、`...UploadretailRequest.php` 及其依赖的 domain 类拷入现有 `top_sdk/top/request/` 与 `top/domain/`。`TopClient` 是通用的，走哪个 API 完全由 request 类的 `getApiMethodName()` 决定，零售接口不需要"零售 SDK"。

**实施前必做**：逐个人工比对 31 个差异 domain 类，确认是纯注释差异还是字段新增/重命名。**若 `CodeRelationDto` 这类码级对账已在用的 domain 有字段变化，则升级为整体覆盖升级**（回到 `TopClient` 版本号差异一并处理）。

### 5. 零售采集口径

源：`dyt` 链接服务器（用户确认是 linked server 名，不是同实例跨库）的 `dyt.msfx.dbo.zsm_ls` left join `dyt.msfx.dbo.zsm_ls_code`（`co.bill_code = ls.bill_code`）。参考 SQL 已存入 `config/sql.php` 的 `$get_up_task_retail`。

采集 SQL 定稿口径：

```sql
select ls.bill_code, ls.bill_time, ls.bill_type, ls.from_user_id, ls.to_user_id,
       ls.ref_ent_id, ls.oper_ic_name, co.trace_codes
from dyt.msfx.dbo.zsm_ls ls
left join dyt.msfx.dbo.zsm_ls_code co on co.bill_code = ls.bill_code
where ls.bill_type in ('104','203','321','116')   -- 写死四种（用户确认）
  and ls.bill_time >= ? and ls.bill_time < ?       -- 按日期范围
order by ls.bill_time desc
```

**去掉 `NOT EXISTS(dyt.bs_msfx.dbo.update_state)`**（关键决策，见 ADR 0007）：该过滤会把"外部系统已上传的单"全部隐藏，而对账/核对恰恰需要看见它们。`update_state` 是外部系统的私有状态表（实测只有单号 + 上传状态两列，**无企业列**，跨门店单号重复时它自身就会串），不能拿它当本项目的采集门卫。

### 6. 门店认领：`oper_ic_name` 精确相等，匹配不上归"未识别"

用户确认：`zsm_ls.oper_ic_name` 就是门店名称，与配置里的门店名**精确相等**匹配。配置里每家门店保留 `source_names` 数组以容纳源系统别名（防静默失败）。

匹配不上 → **照常入库**，`company = '未识别'`，页面显著提示，且**禁用该记录的手动补传**（不知道用哪套凭据）。理由：丢单比错标更危险，必须能看见"有单没被认领"。

`oper_ic_name` 与配置门店名的权威清单由**用户提供**（用户 2026-09-29 承诺"会给出具体名称"）。建议下一步先跑 `SELECT DISTINCT oper_ic_name FROM dyt.msfx.dbo.zsm_ls` 拿初稿核对。

### 7. 数据模型：`company` + `credential` 两列，去重键三元组

- `upload_tasks` / `upload_logs` 各加**两列**：`company`（门店中文名，页面"所属企业"列）、`credential`（凭据标识，存 `label` 或 AppKey；页面不单独显示，重传/去重/日志用它区分）
- **去重键从 `(company, djbh)` 细化为 `(company, credential, djbh)`**：一门店多主体时，主体 A 传成功不代表主体 B 传成功，否则会出现"B 主体漏传但被 A 主体的成功记录挡住"
- **`ent_list` 加 `company` 列**，唯一约束 `ent_name UNIQUE` → `UNIQUE(company, ent_name)`，现有数据回填河药。零售**不使用** `ent_list`（`from/toUserId` 直接来自源表，`uploadretail` 只要门店自己的 `refUserId`）。选择在只有一家批发企业时改这张表，是因为**此时成本最低**——第二个批发主体进来时再改就要停机洗数据
- 历史数据（`upload_tasks` / `upload_logs` 现有行）回填河药批发

### 8. 零售任务状态：新值 `待补传`，且必须给 `upload_pending.php` 排雷

零售单据由外部系统上传，本项目不自动上传，故**不复用 `等待上传`**（现语义是"cron 会来取走并上传"），改用新值 **`待补传`**。

**必须同步修改** `scripts/upload_pending.php:35` 的取数口径——现为：

```sql
SELECT id, rq, djbh, ent_name, trace_codes, bill_type, source
FROM upload_tasks WHERE task_status = '等待上传'
```

**无任何企业过滤**，且第 61 行 `new UploadService()` 凭据写死河药。零售单据一旦以 `等待上传` 落库，该 cron 就会**用河药 AppKey 走 kyt 接口把门店单据申报到河药主体名下**——这是传到平台上的不可逆错误。两种候选改法（择一，实现时定）：`AND company = '<河药批发>'` 或 `AND source != 'retail'`。

`task_status` 取值集合因此变为：`等待上传`（批发，cron 会取）/ `待补传`（零售，仅人工作用）/ `已处理`。

### 9. 三个检查脚本一律排除零售

`check_bill_status.php` / `check_failed_logs.php` / `check_quantity.php` 均**排除零售记录**：

- `check_bill_status` / `check_failed_logs`：本轮不查平台（见 ADR 0007），且它们用河药凭据查门店单号只会得到"信息不存在"，污染状态
- `check_quantity`：完全无关——依赖 `skwms_new` 明细视图的 `SUM(shl)` 本地数量基线，零售没有
- 零售记录**也不应出现在失败记录页**（`api/failed.php`），避免污染现有唯一告警出口

### 10. 页面：三数据页加列与筛选，仪表盘本轮不动

- **三个数据页**（上传任务 / 已上传 / 失败记录）表格加"所属企业"列；筛选条件加"所属企业"下拉（数据来自 `config/enterprises.php` 枚举）；导出 xlsx 加列 + 参数
- **筛选下拉**按 `company` 去重（同一门店不重复列）；一门店多套凭据时下拉显示 `门店名（label）`
- **仪表盘 4 个统计卡片本轮不按企业拆分**（等零售接入稳定后再做）
- **上传任务编辑弹窗允许改"所属企业"**，但需二次确认（改企业意味着重传要走另一套凭据）

### 11. 手动上传模块重构：顶部企业下拉 + 按企业切换表单

**交互**（用户明确要求）：进入"手动上传"页时，**页面最上方有"所属企业"下拉**，选定后显示该企业对应的表单内容。

- **批发表单**：保持现状（日期 / 单号 / 单据类型下拉 / 往来单位 / 追溯码）
- **零售表单**：**不提供从零手工录入**。零售入口是数据页上的"重传"——单据元数据（from/toUserId、refUserId、日期、类型、追溯码）全部取自采集时落库的记录，用户只选门店 + 凭据 + 单号。理由：手工录 4 个平台 ID 几乎必然出错，且本轮不查平台，录错了察觉不了
- **xlsx 导入只保留批发**；`template_download.php` 不分叉。零售的批量需求由"采集 + 批量重传"覆盖
- **拆独立文件**（如 `api/manual_create_retail.php`），不改造现有 `manual_create.php` / `manual_import.php`。批发路径已在生产运行且有 `ent_list` 依赖，零售字段与接口完全不同，塞进同一文件会让两边都难读；`public/index.php` 按 `company` 的企业类型分发

### 12. 隐藏约束（沿用现有）

- **API 限流池按 AppKey 独立**：现有"避开 8-20 点窗口"的约束是针对河药 AppKey 与 `check_bill_status` 并发而言；引入多门店后**各门店 AppKey 与河药不共享限流池**
- **追溯码拆分阈值按接口区分**：`lsyd.uploadinoutbill` 平台上限 **10000**、`lsyd.uploadretail` **3500**、批发 kyt **3500**。现 `UploadService::MAX_TRACE_CODES = 3500` 是全局常量，需按接口参数化（零售门店调拨单码量可能大）

## Testing Decisions

- **原则**：对齐既有风格——自包含断言脚本、无框架、失败非零退出（`tests/trace_splitter_test.php` / `tests/quantity_check_test.php`）
- **可测接缝**（纯函数优于 IO）：
  - **企业配置解析**：`config/enterprises.php` + local 合并后的结构（1:N 凭据、`bill_types` 缺省全集、门店名字典）
  - **接口路由**：`(企业类型, 单据类型)` → 请求类名 的映射函数（含未知类型 → 跳过不猜）
  - **门店认领**：`oper_ic_name` → `company` 匹配函数（精确命中 / 别名命中 / 未识别三分支）
  - **零售请求装配**：给定一条落库零售单 → `lsyd.uploadinoutbill` / `uploadretail` 的 `getApiParas()` 断言（**这是最有价值的一个**：`refUserId` 必须是 ref_ent_id、`from/toUserId` 必须是 entId、`clientType` 必须是 `"2"`、`physicType` 必须填——混淆这几项会导致上传到错误主体）
  - **拆分阈值**：按接口取 3500 / 10000
- **测试禁止项**：不 mock 平台 API、不测实现细节、不在测试里连生产库
- **新增测试文件**：`tests/enterprise_config_test.php`（配置 + 路由 + 门店认领）、`tests/retail_upload_test.php`（请求装配）

## Out of Scope

- **零售平台状态查询**（`lsyd.searchbill.detail` / `lsyd.query.billstatus`）——用户 2026-09-29 判定"暂时搁置对账功能，暂时没有很好的办法可以对账"
- **零售数量对账**——无本地数量基线；三级流水线（`shl` 粗筛 → `singlerelation` 码级精查）依赖 `skwms_new` 视图与 `searchbill.detail` 的 `min_pkg_count`，零售两者皆无
- **`update_state` 表回写**——本项目不对该生产表做任何写入（只读也不读，见 ADR 0007）。手动补传是否导致外部系统重复上传，作为独立议题另行确认
- **零售 xlsx 导入与模板**——只保留批发
- **零售从零手工录入单据**——只支持"重传已采集单据"
- **仪表盘按企业拆分**——本轮不做
- **多套凭据的自动分发规则**——本轮由人工在下拉框显式选择
- **`config/sql.php` 内联 SQL 的下线**——本轮仍作为参考 SQL 保留

## Further Notes

- **量级**：零售预估 **500 单/天**（全连锁合计）。批发 350~1000 单/天（均值 ~650）
- **`zsm_ls_code` 是否含数量列尚不确认**——这决定未来零售能否做数量对账。探测被中断，待补（见开放问题）
- **两个 lsyd 查询接口的细节未确认**：`lsyd.searchbill.detail` 的 `showCode` 填什么、`lsyd.query.billstatus` 的 `dealStatus` 取值——本轮搁置，恢复对账时才需要
- **`dyt` 链接服务器对现有 `SqlSrvHelper` 连接是否可用未验证**——`SqlSrvHelper` 现连 `192.168.2.133` 实例的 `hyyy_zyscm` 库；跨 linked server 查询能否复用同一连接对象待实测
- **`tests/company.txt` 已加入 `.gitignore`**（凭据草稿，数据不准确，待用户提供权威清单后废弃）
- **提交 `e341e94`**（2026-09-29）：`config/sql.php` 对齐生产库连接 + 补录零售参考 SQL + `.gitignore` + CLAUDE.md 同步，已双推 Gitee/GitHub

## Comments

### 2026-09-29 grilling 会话（本轮设计树）

分三轮完成设计树。**关键转折**：第 1 轮按"每门店一套 AppKey"推荐，第 2 轮发现门店凭据草稿显示 8 家门店共用同一 AppKey，第 3 轮用户澄清最终形态是每门店各自不同、**且可能一个门店多套**——故模型定为**凭据是路由主体、门店 1:N 凭据**，而不是"按门店建模"或"按 AppKey 建模"。

已定与未定的分界（用户答复原文要点）：

1. **凭据**：每个门店的 appkey / SECRETKEY 都不相同，每门店也可能存在多套。凭据草稿 `tests/company.txt` 数据不准确，用户后续更新
2. **上传主体**：外部系统上传，本项目只做状态查询 / 对账 / 手动补传
3. **接口映射**：所有门店通用一套；`104`/`203` → `lsyd.uploadinoutbill`，`321`/`116` → `lsyd.uploadretail`
4. **采集 SQL**：写死只提取四种单据类型
5. **手动上传**：零售需单独构建，接口 / appkey / 参数都不一样，**要重构整个模块**；交互为页面最上方"所属企业"下拉 → 选定后显示对应表单
6. **门店匹配**：`oper_ic_name` 与配置门店名精确相等；匹配不上归"未识别门店"照常入库
7. **搁置**：对账功能（含平台状态查询）——"暂时没有很好的办法可以对账"
8. **仓库边界**：SDK zip 不进 git；凭据不进 git；花名册归位/废弃

**开放问题（下次会话起点）**：

| # | 问题 | 阻塞什么 |
|---|------|---------|
| A | **权威门店清单**（用户承诺提供具体名称） | 门店认领与凭据配置的最终形态 |
| B | **各门店的 SECRETKEY**（现有草稿无此列） | 任何门店 API 调用（含手动补传） |
| C | `dyt` 链接服务器能否被现有 `SqlSrvHelper` 连接复用 | 采集脚本实现方式 |
| D | `zsm_ls` / `zsm_ls_code` 的列与索引（是否含数量列、`bill_time` 类型与索引） | 采集 SQL 定稿、未来数量对账可行性 |
| E | 多套凭据的**成因**（多主体 / 按单据类型分 / 历史遗留 / 其他）、同一张单是否需多套各传一次 | 仅影响未来的自动分发规则；本轮已用"人工选择"绕开 |
| F | 门店二次改名时 `company` 中文名作为键的处理（用户选择中文名，代价是改名要洗数据） | 数据维护流程 |
