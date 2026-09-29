**Status:** ready-for-agent（设计已收口，工单 02–09 可直接开工；工单 01 已完成，见下方"实施状态"）

# 零售连锁门店接入（多企业支持）

## 实施状态（2026-09-29）

| # | 工单 | 状态 | 交付物 / 剩余范围 |
|---|------|------|------------------|
| 01 | 企业配置与凭据模型 | **✅ 已完成** | `src/Enterprise.php`（配置解析 / 门店认领 / 接口路由 / 配置自检）+ `config/enterprises{,.example,.local}.php` + `tests/enterprise_config_test.php`（49 项断言全绿）。提交 `2877779` |
| 02 | SQLite 多企业改造 | ⬜ 未开始 | `upload_tasks`/`upload_logs` 各加 `company`+`credential` 两列；`ent_list` 唯一约束改 `UNIQUE(company, ent_name)`；历史数据回填 `河药医药（河源）有限公司` |
| 03 | 【排雷】`upload_pending.php` 企业过滤 | ⬜ 未开始 | **本 feature 唯一"上线即可能污染生产数据"的点**：白名单过滤 + `UploadService` 取不到凭据即拒绝上传（不回落默认凭据）。依赖 02 |
| 04 | SDK：lsyd 请求类并入 `top_sdk/` | ⬜ 未开始 | 解压 `top_sdk_retail.zip`、逐个比对 31 个差异 domain 类（纯注释差异 vs 字段变化）后并入 |
| 05 | 零售单据采集 | ⬜ 未开始 | 采集 SQL 已定稿（§5，含按 `bill_code` 去重）；`App\Enterprise::claim()` 已就绪；**脚本未写** |
| 06 | 三个检查脚本排除零售 | ⬜ 未开始 | `check_bill_status` / `check_failed_logs` / `check_quantity` + `api/failed.php` 均排除零售记录 |
| 07 | 手动上传重构（企业下拉 + 零售补传） | ⬜ 未开始 | 页面顶部"所属企业"下拉；零售只支持重传已采集单据；依赖 04 与门店凭据 |
| 08 | 页面：三数据页加"所属企业"列与筛选 | ⬜ 未开始 | 含导出 xlsx 加列 + 筛选参数 |
| 09 | 文档同步收尾 | ⬜ 未开始 | 本 feature 收尾时统一复核 |

**已完成的设计侧工作（非代码）**：2026-09-29 对 `dyt` 源库的**只读**探测（开放问题 C、D 有答案，见 §5），结论落 `probe-findings-2026-09-29.md`；新增 ADR 0008、修订 ADR 0006；CLAUDE.md、CONTEXT.md 同步。

**外部依赖**（不阻塞上方工单开工，但阻塞对应功能可用）：

| 依赖 | 阻塞什么 | 现状 |
|------|---------|------|
| 10 家门店的 AppKey/SECRETKEY | 这 10 家的**手动补传**（采集与展示不受影响，状态为"待配凭据"） | 已有 5 家：宝源店、埔前立信分店、新江分店、徐洞分店、大湖分店 |
| 外部系统上传用的是哪套 AppKey | 补传**是否会在平台上造成重复申报**（两个主体各报一次） | 待向外部系统工程师确认；兜底是补传加人工二次确认 |
| 指定日期的采集口径人工核对 | 工单 05 上线前的数据验收 | 需在生产库跑一次采集并与源库去重单号数比对 |

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
Company（门店：中文全名 + 稳定 key + 类型）
    ├──  1 ──── N  Credential（appkey / secretkey / ref_ent_id / ent_id / label / primary）
    └──  ids[]     该门店全部平台 ID —— 采集认领用（**凭据没到手也必须有**，否则单据认领不到）

路由：(企业类型, 单据类型) → 请求类 + 追溯码上限     ← 与"用哪套凭据"无关
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

**实测事实**：门店凭据文件（本地 `tests/company.txt`，未入仓）现含 **5 家门店**的 AppKey/SECRETKEY/refEntId/entId；用户确认连锁共 **15 家门店**，最终形态是**"每个门店的 appkey、SECRETKEY 都不相同"**。故**不按 AppKey 建模，也不假设门店与凭据 1:1**。

```
Credential = { key, label, primary?, appkey, secretkey, ref_ent_id, ent_id }
Company    = { key(稳定 slug), name(中文全名), type(wholesale|retail), ids: 平台ID[], credentials: Credential[] }
```

- `key`：企业稳定标识（ASCII slug），用于把结构文件与凭据文件对上、把门店改名与凭据解耦；落库的 `company` 值用的是 `name`
- `label`：给下拉框显示的人读标签（如"主授权"）；门店仅一套凭据时可省略
- `primary`：一个门店有多套凭据时哪套是默认，**声明在结构文件 `config/enterprises.php` 里**（"哪套是默认授权"是结构性事实，不随密钥是否到手变化）；单套凭据可省略
- `ids`：该门店的**全部**平台 ID（含历史 ID、源库错值），是采集认领的键（见 §6）。**门店可以在没有凭据时先有 ids** —— 单据照常认领，只是不能补传（**待配凭据**状态，见 §8）
- **`bill_types` 已删除**：多套凭据的成因是"限流时顶替"，与单据类型无关，留成死字段比缺字段更危险。路由由 `(企业类型, 单据类型)` 决定，与选哪套凭据无关（ADR 0008）

### 2. 配置分三文件，凭据绝不入仓

| 文件 | 内容 | 入 git |
|------|------|--------|
| `config/enterprises.php` | 企业结构：`key`（稳定 slug）、`name`（中文全名）、`type`（wholesale·retail）、凭据位（`label`、`primary`） | ✅ |
| `config/enterprises.local.php` | 门店平台 ID 列表（`ids`）+ 凭据四字段明文（appkey/secretkey/ref_ent_id/ent_id） | ❌（`.gitignore`） |
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

注意 `refUserId` 是 **ref_ent_id**，而 `fromUserId` / `toUserId` 是 **entId**。

**⚠️ 探测推翻了原先"`zsm_ls` 三列语义与入参一致"的说法**（2026-09-29，见 `probe-findings-2026-09-29.md`）：源表 `ref_ent_id` 列是**全表单一值**（总部主体），**不是门店的**——上传时的 `refUserId` 必须取**凭据里**该门店的 `ref_ent_id`。而源表 `from_user_id` / `to_user_id` 确实是门店的平台 ID（实测与凭据的 `entId`/`refEntId` 吻合，20/20 命中），它们的作用是**采集认领键**（§6），不是上传入参的直接来源。

### 4. SDK 集成：只挑 lsyd 类并入现有 `top_sdk/`

**不引入第二套 SDK**。实测事实：`top_sdk_retail.zip` 与现有 `top_sdk/` 有 **67 个同名文件**，其中 31 个内容不同（30 个 domain + `TopClient.php`），而 `TopClient.php` 的差异**只有 `sdkVersion` 一行**（`top-sdk-php-20260203` → `20260929`）——即零售包是同版本 SDK 的新版，不是另一套 SDK。

**排除的方案**：独立目录 `top_sdk_retail/` 会直接坏——两个 `Autoloader.php` 都声明全局 `class Autoloader`（PHP 无 namespace，重声明 Fatal error），且 `TopSdk.php` 的 `TOP_AUTOLOADER_PATH` 常量第二个 SDK 会因已定义而跳过，自动加载指向错误目录；要修就得改 SDK 文件，而项目约定 `top_sdk/` 不可修改。

**采用**：把 `AlibabaAlihealthDrugtraceTopLsydUploadinoutbillRequest.php`、`...UploadretailRequest.php` 及其依赖的 domain 类拷入现有 `top_sdk/top/request/` 与 `top/domain/`。`TopClient` 是通用的，走哪个 API 完全由 request 类的 `getApiMethodName()` 决定，零售接口不需要"零售 SDK"。

**实施前必做**：逐个人工比对 31 个差异 domain 类，确认是纯注释差异还是字段新增/重命名。**若 `CodeRelationDto` 这类码级对账已在用的 domain 有字段变化，则升级为整体覆盖升级**（回到 `TopClient` 版本号差异一并处理）。

### 5. 零售采集口径

源：`dyt` 链接服务器（用户确认是 linked server 名，不是同实例跨库）的 `dyt.msfx.dbo.zsm_ls`（单据头）+ `dyt.msfx.dbo.zsm_ls_code`（追溯码，按 `bill_code` 关联）。参考 SQL 在 `config/sql.php` 的 `$get_up_task_retail`（**只是调试残留，别照抄**：它写死单一 `bill_type='203'`、无日期范围、无去重）。

**连接（开放问题 C，已实测答）**：同一条 `SqlSrvHelper` 连接可直接查 4 段式链接服务器名 `dyt.msfx.dbo.zsm_ls`，**不需要任何新连接封装**（`COUNT(*)` 全表 638ms）。

**表结构与索引（开放问题 D，已实测答）**：

- `zsm_ls`（119,522 行）：`bill_time` 是 **`varchar(10)` 纯日期 `YYYY-MM-DD`**（全表长度均为 10，非 datetime）；`bill_type`/`physic_type` 是 int（`physic_type` 全表恒为 3）；`states` / `relation_states` **全表恒为 0**（无可用状态过滤）；索引 `(bill_code, bill_type, from_user_id, to_user_id, ref_ent_id)`，**`bill_time` 无索引** → 按日期范围采集是全表扫描（~0.6s，可接受）
- `zsm_ls_code`（298,430 行）：`trace_codes` **每行只存 1 个码**（varchar(200) 但实测最长 20 字符），`bs` 全表恒为 1，**无排序列**；一单的码数 = 该 `bill_code` 的行数，实测 **1 ~ 1,718**；索引以 `bill_code` 打头，按单号取码高效
- **两张表都没有任何数量列** → 零售数量对账在源库侧**没有本地基线**（比"暂时没找到办法"更硬的否定结论）

**采集 SQL 定稿口径**（两步，先头后码）：

```sql
-- 第一步：单据头。必须先按 bill_code 去重——321 存在完全重复行（同一 bill_code 最多 120 行，
-- 14 列值全同、无任何区分列），不去重会让下一步按单号取码时追溯码被放大最多 120 倍
select bill_code, min(bill_time) as bill_time, min(bill_type) as bill_type,
       min(from_user_id) as from_user_id, min(to_user_id) as to_user_id,
       min(oper_ic_name) as oper_ic_name
from dyt.msfx.dbo.zsm_ls
where bill_type in (104, 203, 321, 116)      -- 写死四种，int 比较；999 不采（用户判定，语义未明）
  and bill_time >= ? and bill_time < ?       -- 'YYYY-MM-DD' 字符串比较即日期比较（ISO 格式）
group by bill_code

-- 第二步：按单号批量取码（group by 去重 + order by 保证拼接结果确定——表里没有排序列）
select bill_code, trace_codes
from dyt.msfx.dbo.zsm_ls_code
where bill_code in (...)
group by bill_code, trace_codes
order by bill_code, trace_codes
```

**不设采集门卫**：批发那套 `fetch_bill_counter.json` 计数门卫是为"重视图查询空转"设计的；零售是一次 ~0.6s 的全表扫 + `(company, djbh)` 去重保证幂等，门卫只省 0.6s 却多一份要维护的状态文件。

**不需要拆单**：实测单张单据码数上限 1,718 < 3,500，零售暂不会触发拆分（但路由表已按接口带上限：`lsyd.uploadinoutbill` 10000 / `lsyd.uploadretail` 3500，见 §12）。

**去掉 `NOT EXISTS(dyt.bs_msfx.dbo.update_state)`**（关键决策，见 ADR 0007）：该过滤会把"外部系统已上传的单"全部隐藏，而对账/核对恰恰需要看见它们。`update_state` 是外部系统的私有状态表（实测只有单号 + 上传状态两列，**无企业列**，跨门店单号重复时它自身就会串），不能拿它当本项目的采集门卫。

### 6. 门店认领：平台 ID 优先，名字回退，未识别兜底

**⚠️ 本节已被实测推翻并重写**（原设计是"`oper_ic_name` 精确相等"，见 ADR 0008）。`oper_ic_name` 在 `321`/`116` 两类消费级单据上**全空**（101,288 行、占全表 84.7%），只有 `104`/`203`（8,153 行）有门店名——照原设计实现会让 85% 的单据认领不到。

三分支判定（`App\Enterprise::claim()`）：

| 分支 | 依据 | 结果 |
|---|---|---|
| 1 | 按单据类型取 ID 列（`321`/`116` → `from_user_id`，`104`/`203` → `to_user_id`），命中该门店登记过的**任一**平台 ID | 认领为该门店，`matched_by='id'` |
| 2 | ID 为空或未命中 → `oper_ic_name` 与配置门店名**精确相等** | 认领为该门店，`matched_by='name'` |
| 3 | 都不命中 | `company='未识别'`，**照常入库** + 页面显著提示 + 禁用补传 |

几条实测支撑与脏数据处理：

- **20/20 命中**：321/116 的 20 个非空 `from_user_id` 全部能由 `104` 的「`oper_ic_name` ↔ `to_user_id`」对照认到门店——一张对照表覆盖全部四种单据类型
- **一家门店登记多个 ID**：新江分店 2 个（旧 entId 用到 2025-07、新 refEntId 自 2024-03 起）、雅居乐分店 4 个（含源库把数字 `0` 写成字母 `o` 的错值）；ID 清单在该门店的 `ids` 里
- **名字降级为显示 + 回退**：凭据文件的门店名原先 5/5 都因半角/全角括号差异不命中源库，改用 ID 认领后这个坑失效；但配置里的名字仍应与源库**一字不差**（全角括号），因为它还承担 ID 缺失时的回退
- **ID 命中而名字对不上任何门店**时置 `name_unmatched` 警告位（采集记 JSONL 日志，不改判定）——用于发现源库错名/已关店/改名
- **`未识别` 是真异常信号**：它现在只该出现在"配置漏了门店"或"源库改了名"时；"门店还没拿到凭据"不是它——那是**待配凭据**（§8）

权威门店清单（15 家）与平台 ID 由 2026-09-29 探测直接生成，见 `probe-findings-2026-09-29.md`；已关店（大同/帝景/黄村，末单 3~24 个月前）不入配置。

### 7. 数据模型：`company` + `credential` 两列，去重键 `(company, djbh)`

- `upload_tasks` / `upload_logs` 各加**两列**：`company`（门店**中文全名**，页面"所属企业"列的值与筛选键）、`credential`（该企业的 **primary 凭据键**，如 `main`；页面不单独显示，只作审计）
- **去重键 = `(company, djbh)`**（原三元组已废弃，见 ADR 0008）：多套凭据实为**限流备用**而非"两个主体各传一遍"，故不存在"B 主体需另传一次"；三元组不但不提供保护，反而**允许同一张单被传两次**——平台侧重复申报，恰是备用凭据永远不该做的事
- `credential` **不参与任何键**：采集时预填 primary 键（表达"默认会用哪套"），补传时若人工切到备用凭据则由补传流程覆盖该行——它只回答"这次实际用了哪套"
- **`ent_list` 加 `company` 列**，唯一约束 `ent_name UNIQUE` → `UNIQUE(company, ent_name)`，现有数据回填河药。零售**不使用** `ent_list`（`from/toUserId` 直接来自源表，`uploadretail` 只要门店自己的 `refUserId`）。选择在只有一家批发企业时改这张表，是因为**此时成本最低**——第二个批发主体进来时再改就要停机洗数据
- 历史数据（`upload_tasks` / `upload_logs` 现有行）回填 **`河药医药（河源）有限公司`**（批发主体全名，取自平台响应的 `from_ent_name`/`to_ent_name`，全角括号）
- `config/enterprises.local.php` 的 `ids` 里，河药登记 `REFENTID_HYYY` + `ENTID_HYYY`；零售 15 家门店登记探测得到的平台 ID

### 8. 零售任务状态：新值 `待补传`，且必须给 `upload_pending.php` 排雷

零售单据由外部系统上传，本项目不自动上传，故**不复用 `等待上传`**（现语义是"cron 会来取走并上传"），改用新值 **`待补传`**。

**必须同步修改** `scripts/upload_pending.php:35` 的取数口径——现为：

```sql
SELECT id, rq, djbh, ent_name, trace_codes, bill_type, source
FROM upload_tasks WHERE task_status = '等待上传'
```

**无任何企业过滤**，且第 61 行 `new UploadService()` 凭据写死河药。零售单据一旦以 `等待上传` 落库，该 cron 就会**用河药 AppKey 走 kyt 接口把门店单据申报到河药主体名下**——这是传到平台上的不可逆错误。两种候选改法（择一，实现时定）：`AND company = '<河药批发>'` 或 `AND source != 'retail'`。

`task_status` 取值集合因此变为：`等待上传`（批发，cron 会取）/ `待补传`（零售，仅人工作用）/ `已处理`。

**另有企业级状态「待配凭据」（不是 task_status）**：该门店有名字与平台 ID、能正常认领单据，但 AppKey/SECRETKEY 未到手（凭据四字段没填齐）。页面标"待配凭据"并**禁用补传**。它与 `未识别` 的区别是：后者说明**配置漏了门店或源库改了名**（真异常，要人去查），前者只是**授权还没拿到**（预期内的正常状态）。15 家门店中当前有 5 家已配凭据、10 家待配。

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

- **原则**：对齐既有风格——自包含断言脚本、无框架、失败非零退出（`tests/trace_splitter_test.php` / `tests/quantity_check_test.php`）。只测**对外行为**，不测实现细节。
- **禁止项**：不 mock 平台 API、不在测试里连生产库、不依赖部署机的真实凭据文件（一律用固化 fixture）。
- **接缝：全 feature 收敛为 2 个，其中 1 个已落地**

  1. **`App\Enterprise`（已落地）** —— 本 feature 的最高接缝。配置解析、门店认领、接口路由、配置自检全部收敛在这一个模块的静态方法上，输入是纯数据（结构数组 + 取值数组）、**无 IO**；测试用 fixture 驱动，生产走 `loadFromFiles()`。已由 `tests/enterprise_config_test.php` 覆盖（49 项断言）：1:N 凭据、待配凭据合法、认领三分支与"按单据类型选 ID 列"规则、历史 ID 别名、路由与码上限（含未知类型不猜）、9 类配置自检拒绝。
     - 认领的**选列规则**（321/116 用 `from_user_id`、104/203 用 `to_user_id`）是实测支撑的（20/20 命中），测试把它钉住，防止将来"顺手改成另一列"。
     - 配置自检（`validate()`）不是普通断言而是**载入即抛异常**：它拦的是"违反了就会静默把单据传到错误主体"的一类错误（平台 ID 跨企业重复、凭据 ID 不属于本企业、多凭据未标 primary）。
  2. **零售请求装配（待落地，工单 07）** —— 给定一条落库零售单 + 一套凭据 → `getApiParas()` 断言，**不发起网络调用**。这是本 feature 最有价值的接缝：`refUserId` 必须取凭据的 `ref_ent_id`（**不是源表 `zsm_ls.ref_ent_id`**——那列全表单一值、属总部主体）、`fromUserId`/`toUserId` 必须取 `ent_id`、`clientType` 必须为 `"2"`、`physicType` 必须填；混淆任意一项都会把单据申报到**错误主体**，且在平台上不可逆。
     - 拟新增 `tests/retail_upload_test.php`。**必须包含一个真实数据构造的用例**：新江分店是 15 家中唯一 `ref_ent_id ≠ ent_id` 的门店，用它断言两个字段没有互换——用 `ref_ent_id == ent_id` 的门店做用例，互换了也测不出来。

- **不新增第三个接缝**：采集 SQL 的去重与码拼接、Web 页面交互都不写单元测试——前者靠"指定日期采集后与源库去重单号数比对"的人工验收（工单 05 验收标准），后者靠页面实测。为可测性再把采集拆出一层纯函数，收益不抵多一处接缝的长期成本。

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

**开放问题已全部有答**（2026-09-29 探测轮 + 用户答复）：

| # | 问题 | 结论 |
|---|------|---------|
| A | 权威门店清单 | **15 家**（用户确认"事实就是 15 家"）；清单由探测的 `104` 对照表直接生成，见 `probe-findings-2026-09-29.md`。已关店 3 家（大同/帝景/黄村）不入配置 |
| B | 各门店 SECRETKEY | 已到手 **5 家**（`tests/company.txt` → `config/enterprises.local.php`）；其余 10 家待授权，属"待配凭据"正常状态，不阻塞采集与展示 |
| C | `dyt` 能否复用现有连接 | **能**（实测 4 段式查询 638ms），采集脚本无需新连接封装 |
| D | 两张表的列与索引 | 已答，见 §5：`bill_time` 是 `varchar(10)` 纯日期且**无索引**；码一码一行、无排序列；**无任何数量列** |
| E | 多套凭据的成因 | **门店授权给不同开发者；目前只保留 1 个授权，第二个仅在主授权被限流时顶替（数据量小，基本不会限流）** → 去重键退回二元组（ADR 0008） |
| F | 门店改名时的键处理 | 中文全名 + `ids` 认领 + 稳定 `key`：改名只需改配置里的 `name`（`key` 不变、凭据不脱钩）；源库里出现过旧名时，旧名下的历史单据靠 `ids` 仍能认领 |

**仍未定（不阻塞工单 01/02/03/05）**：

- **外部系统上传用的是哪套 AppKey**？若与 `company.txt` 里这些不同，本项目补传就可能在平台上造成**重复申报**（两个主体各报一次）。需向外部系统的工程师确认；无法确认的兜底是补传加一道人工二次确认（详见 R2-Q4）
- 其余 10 家门店的 AppKey/SECRETKEY

### 2026-09-29 探测轮（第三轮会话）

在用户明确授权（点名生产库）后，对 `dyt.msfx.dbo.zsm_ls` / `zsm_ls_code` 做**只读**探测（全程 SELECT，未写任何表），结论落 `probe-findings-2026-09-29.md`。

**推翻了三条已定设计**：门店认领键（§6，由"名字精确匹配"改为"平台 ID 优先"）、`zsm_ls.ref_ent_id` 的门店语义（§3）、去重键三元组（§7，由用户对成因的答复推翻）。**新增两条边界**：`bill_type=999` 不采（用户判定，语义未明）；321 的完全重复行必须在采集时去重。

**同时完成工单 01**（`src/Enterprise.php` + 配置三文件 + `tests/enterprise_config_test.php`，全部通过）。

### 2026-09-29 to-spec 收口（第四轮会话）

把设计树、探测轮与工单 01 的实现结果收口为本版 spec，并在文首新增"实施状态"一节，区分**已完成（工单 01 + 设计侧探测/ADR）**与**未完成（工单 02–09 + 三项外部依赖）**。

本轮**唯一新增的设计内容是把测试接缝收敛为 2 个**（`App\Enterprise` 已落地、零售请求装配待工单 07 落地），并显式声明**不为采集 SQL 与页面交互新增接缝**——理由与各自的替代验收方式见 Testing Decisions。

用户在本轮明确的两条边界：**门店数量就是 15 家**（不再纠缠探测发现的 18 个店名，3 家已关店不入配置）；**`bill_type=999` 不采**（语义未明，只采 104/203/321/116 四种）。

### 2026-09-29 to-tickets（第五轮会话）

把本 spec 拆成工单。**工单 01 保留原编号与内容**（已完成），**02–09 被整体替换为垂直切片**（tracer bullet：
每一票都切穿"数据 → 后端 → 页面 → 验收"、独立可验收），旧横向切层版移入 `issues/_superseded/`。
所以：**本节上方"实施状态"表里的 02–09 描述的是已被替换的旧切法**，实际工单以 `issues/` 下的文件为准
（工单 09 收尾时会把该表回填为实际完成情况）。

本轮测绘代码后新增/修正的四条事实：

1. **雷不止 `upload_pending.php` 一处**：`api/tasks_retry.php` 与 `api/tasks_batch_retry.php` 同样是
   `new UploadService()->upload()`、同样不传企业。故排雷的正确落点是 `UploadService` 里的 **fail-closed 守卫**
   （按企业路由取凭据，取不到或不是批发就拒绝），它一次护住全部调用方；`upload_pending.php` 的 SQL 白名单是第二道
2. **筛选逻辑在仓库里有 4 份拷贝**（3 个列表 API + 导出各写一份 WHERE）、前端参数构造与列渲染各 3 份。
   "加一列"是 4+3+3 处机械修改，不是 1 处（工单 08）
3. **零售的 `upload_logs` 只可能由人工补传产生**（三个检查脚本根本写不出零售日志）。
   因此"失败记录页排除零售"实际等于"人工补传失败看不见"——**用户 2026-09-29 改判：放行人工补传的失败**，
   `api/failed.php` 不再排除零售；`check_failed_logs.php` 仍必须排除（它拿河药凭据查门店单号只会得到"信息不存在"）
4. **既有 bug**：`export.php` 的失败记录分支里 NOT EXISTS 无条件生效，缺了页面版 `failed.php` 的
   `source='quantity_check' OR` 豁免——失败记录页看得见的数量对账告警，导出的 xlsx 里被漏掉（工单 08 一并修）

用户在本轮定的两条：

- **零售补传入口做两处**：数据页单条补传（工单 06）+ 手动上传页选定门店后的批量补传（工单 07）
- **lsyd 入参 `fromUserId` / `toUserId` 照搬源表同名列**（`zsm_ls.from_user_id` / `to_user_id`）——
  依据是这两列不随调拨方向翻转（104/203 的 `from_user_id` 都只有总部一个值），观察到的规律是
  "from = 单据发起方、to = 对方主体"；`refUserId` 仍必须取**凭据**的 `ref_ent_id`（工单 05 落 ADR 并写进测试）
