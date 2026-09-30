# CONTEXT.md

## 领域词汇表

### 核心实体

- **上传任务 (Upload Task)**：待上传到码上放心平台的出入库单据。包含单据日期（`rq`，来自 SQL Server）、单号、往来单位、追溯码列表、任务状态（`task_status`）、API 响应状态（`request_status` / `response_status`）、任务创建时间（`created_at`）。来源可能是 cron 定时从 SQL Server 抓取，也可能是用户在 Web 端手动创建。存储在 SQLite `upload_tasks` 表。

- **上传日志 (Upload Log)**：每次 API 调用的结果记录。与上传任务通过 `task_id` 关联。包含单据日期（`rq`，回填自 upload_tasks 或 SQL Server）、单号、往来单位、追溯码、请求状态（`request_status`）、响应状态（`response_status`）、API 返回内容、任务创建时间（`created_at`，即 API 调用时间）。存储在 SQLite `upload_logs` 表，同时写入 JSONL 文件永久保存。

- **单据 (Bill)**：ERP 系统中的出入库单。类型分入库（1xx：102 采购入库, 103 退货入库, 104 调拨入库, 107 供应入库, 108 召回入库, 110 赠品入库, 111 盘盈入库, 112 报废入库, 113 其他入库）和出库（2xx：201 销售出库, 202 退货出库, 203 调拨出库, 204 返工出库, 205 销毁出库, 206 抽检出库, 207 直调出库, 209 供应出库, 211 召回出库, 212 赠品出库, 214 盘亏出库, 215 损坏出库, 216 报废出库, 217 其他出库, 237 直调退货）。在 SQL Server 中以 `djbh` 作为唯一标识。类型码统一经 `App\BillType::normalize` 归一化为 3 位数字码：字母前缀（如 XSO/XST/JHG/JHO）转数字、空值按 `djbh` 前 3 位推导；cron 上传时来自 SQL Server 的 type 字段，手动上传时用户直接在下拉菜单选择数字类型码。Web 三个数据页表格以中文类型名称展示。

- **追溯码 (Trace Code)**：药品电子监管码，字符串类型。一个单据对应多个追溯码，以英文逗号分隔拼接为长文本。单次上传的追溯码上限**按接口区分**（批发 kyt 3500、零售 `lsyd.uploadinoutbill` 10000、`lsyd.uploadretail` 3500，取值为 `App\Enterprise::route()` 的 limit），超出时自动拆分为 `单号_1, 单号_2...`（**上传拆分**）。导出 xlsx 时因 Excel 单格字符上限（32767），按字符数（32000）再次拆行（**导出拆行**），每行一个分片、单号加 `_N` 后缀（已带后缀的单号追加后缀，如 `单号_1` → `单号_1_1`）；3500 码 ≈ 73500 字符，故导出拆行不能按 3500 码粒度。

- **所属企业 (Company)**：**上传这张单的申报主体**——河药批发企业，或零售连锁的某家门店（形如"大源堂智慧药房（河源）有限公司新江分店"，共 15 家门店，权威名单以部署配置 `config/enterprises.php` 为准）。以**中文企业名**作为标识（存在 `upload_tasks` / `upload_logs` 的 `company` 列）。所属企业决定用哪套凭据（见"凭据"）调哪个平台接口，因此它是多企业支持下的**路由主体**。

  零售单据的**认领**（采集时把单据归到哪家门店）**以门店的平台 ID 为准**：源表 `zsm_ls` 的 `from_user_id` / `to_user_id`（即凭据里的 `ent_id` / `ref_ent_id`），按单据类型取对应列——321/116 两类消费级单据取出库方向，104/203 两类调拨单据取入库方向。一家门店可登记**多个**平台 ID（历史 ID、源库错值），命中任意一个即认领成功。ID 缺失时才回退用 `oper_ic_name` 与配置里的门店名**精确相等**匹配；该列在 321/116 单据上**全空**，故不能作为主键。两者都匹配不上 → 照常入库但标 **`未识别`**，页面显著提示、禁用补传（丢单比错标更危险，必须能看见"有单没被认领"）。判定见 ADR 0008。

- **凭据 (Credential)**：一套平台接入身份，含 `appkey`、`secretkey`、`ref_ent_id`、`ent_id`、`label`（人读标签，如"主授权"，供页面下拉显示）、`primary`（一个门店有多套凭据时哪套是默认，声明在结构文件里）。**门店与凭据是 1:N**——门店可授权给多个开发者，但**同一张单只需、也只能传一次**（备用授权仅在主授权被平台限流时顶替，不是"两个主体各传一遍"），因此落库的 `credential` 列只记录**实际用了哪套**（审计用），**不参与去重键**：去重键是 `(company, djbh)`。凭据明文存 `config/enterprises.local.php`（不入 git），结构（企业名 / 类型 / 凭据位）存 `config/enterprises.php`。

  注：**平台侧要的是两种不同的 ID**——`ref_user_id` / `ref_ent_id` 是**企业编码**，`from_user_id` / `to_user_id` / `ent_id` 是**阿里健康企业 ID**。同一个凭据两者都有，不能混用。源表 `zsm_ls.ref_ent_id` 那一列**不是门店的**（全表单一值，属总部主体），上传时的 `refUserId` 必须取门店自己凭据里的值。

- **待配凭据 (Credential Pending)**：企业已在配置里（名字与平台 ID 齐备，其单据能被正常认领），但该门店的 `appkey` / `secretkey` 尚未到手，凭据四字段没填齐。此时单据照常采集入库，页面标"待配凭据"并**禁用补传**——这是**预期内的正常状态**，与 `未识别` 那个真异常信号不同：后者说明配置漏了门店或源库改了名，前者只是授权还没拿到。

- **往来单位 (Partner Enterprise)**：**单据的对方企业**（供应商或客户），**不是申报主体**——与"所属企业"是正交的两个维度：所属企业答"这张单是谁报的"，往来单位答"这张单是对谁报的"。具有 `ent_name`（企业名称）、`ent_id`（阿里健康企业 ID）、`ref_ent_id`（企业编码）属性。首次遇到的往来单位通过 API 在线查询并缓存到本地 SQLite `ent_list` 表（该缓存**按所属企业隔离**，唯一约束是 `UNIQUE(company, ent_name)`）。**零售单据不使用往来单位缓存**——`from_user_id` / `to_user_id` 直接来自源表 `zsm_ls`。

- **单号 (Bill Code)**：单据编号，如 `JHGWMS00061116`。cron 上传时前 3 位标识单据类型（XSO/XST/JHG/JHO）；手动上传时单据类型由用户从下拉菜单独立选择。拆分时衍生为 `单号_1, 单号_2...`。

- **最小包装数 (Min Package Count)**：药品申报数量的统一量纲，即"已展开到最小包装单位"的数量（如盒装药品的最小包装数是盒内的最小销售单位数）。平台 `searchbill.detail` 返回的 `min_pkg_count` 即此量纲；本地对应 SQL Server 明细视图（出库 `v_pf_phlrmx`、入库 `v_sjdmx_mx`）的 `shl` 字段——整件行 `shl = baozhshl(包装数) × jlgg(件规格)`、零散行 `shl = lingsshl(零散数量)`。数量对账（check_quantity.php）**第 1 级**以此为比较口径（见 ADR 0004），与追溯码数不同量纲（件码按件内盒数展开，1 件码=5/10/200 盒）。

- **最小溯源单位 (Min Traceability Unit)**：平台码库中追溯码的固有折算单位，每个追溯码都携带一个"折算成最小溯源单位的系数"（`pkg_amount`，singlerelation 接口返回）：本身就是最小溯源单位的码系数为 1、大包装码系数为其内含最小溯源单位数（如 100）。平台 `min_pkg_count` 即按此口径统计的申报总数。与"最小包装数"的区别：后者是**本地**口径（按本地零售规格展开，如青霉素钠按瓶卖），前者是**平台注册规格**口径——两者在本地零售规格 ≠ 平台注册规格的药品上不一致（差 19 的青霉素钠假阳性即源于此）。数量对账**第 2 级**以 `Σ pkg_amount`（把本地追溯码逐码折算后求和）与平台 `min_pkg_count` 对比，从根上消除规格口径差异。详见 `.scratch/quantity-check/singlerelation-tier2.md` 与 ADR 0005。

### 核心流程

- **定时上传 (Cron Upload)**：分两步独立调度 —— `scripts/fetch_bills.php` 定时（当前 cron 每 30 分钟）从 SQL Server 采集单据写入 upload_tasks（source=cron, task_status=等待上传），采集带计数门卫（当天单据计数无变化则跳过）；`scripts/upload_pending.php` **只读取批发主体的等待上传任务**（`company` 白名单，不是"排除零售/其他来源"的排除法——零售单压根不以"等待上传"落库；当前 crontab 未启用，手动触发），通过 UploadService 调码上放心 API（含 ent_list 缓存查找、3 次重试、0.33s 限速、追溯码超该接口上限拆分，上限取自 `App\Enterprise::route()`——批发 kyt 为 3500）→ LogWriter 写 JSONL + SQLite。手动上传保持立即上传不变。

- **零售采集 (Retail Collection)**：`scripts/fetch_bills_retail.php` 定时（cron 与 `fetch_bills` 同频、**错开 5 分钟**——两者都是写 SQLite 的进程）从 **dyt 链接服务器**采集门店单据：源表 `dyt.msfx.dbo.zsm_ls`（单据头）+ `zsm_ls_code`（追溯码，一码一行）。单据类型写死四种 `104`/`203`/`321`/`116`（`bill_type` 是 int；`999` 语义未明，用户判定不采）。认领走 `App\Enterprise::claim()`（平台 ID 优先、门店名回退），落库 `source='retail'` + `task_status=待补传`。**全程只读 SELECT、不调任何平台接口**，故不受 8-20 点限流窗口约束；**没有计数门卫**（幂等靠 `(company, djbh)` 去重）、**不需要拆单**（实测单张码数上限 1,718 < 3,500）。源库不可用时非零退出且不写库。见 ADR 0007 / 0008，口径细节见 CLAUDE.md 的"零售单据采集"。

  ⚠️ **2026-09-30 起为测试阶段临时口径**（与 ADR 0007 原始决定相反，测试结束需回收）：**单条 SQL**（`zsm_ls LEFT JOIN zsm_ls_code`）+ **`NOT EXISTS(update_state)` 过滤**（只采外部系统尚未上传的单）+ **无日期过滤**（每轮全表扫）。去重从 SQL 挪到 PHP 侧（`321` 的 14 列全同重复行会让连接结果放大最多 120 倍，故追溯码用关联数组去重、单据头字段取首次出现的行），且走 `SqlSrvHelper::queryEach` 逐行消费以免撑爆内存。此前的"两步 SQL + 全量采集"是本条的历史口径。

- **批量查询上传状态 (Batch Check)**：由**两个脚本**分担、共用同一套查询/更新语义，仅调度频率不同，各带独立 flock 锁（`LOCK_EX|LOCK_NB`，锁被占用直接退出防并发）。两者取数一律**只查批发主体**（`company` 白名单）——拿门店单号去查只会得到"信息不存在"，白烧调用还可能把状态翻错。

  - **来源 1 `scripts/check_bill_status.php`**（等待上传任务，高频，cron 8-20 点每 30 分钟）：查 `upload_tasks` 中 `task_status=等待上传` 的任务 → 逐个调 `ApiClient::searchBillDetail()`（API 间隔 0.5s）→ 已上传的标记任务 `已处理` 并写 `upload_logs`（来源 `batch_check`）；"信息不存在"只动时间戳、不改状态
  - **来源 2 `scripts/check_failed_logs.php`**（失败记录，低频，每天 20:40）：查 `upload_logs` 中未上传成功的记录 → 按 `djbh` 去重（同单多条失败记录只查一次 API）→ 平台存在则把记录翻转为"上传成功"并同步关联任务（`task_id>0` 标 `已处理`）；"信息不存在"同样只动时间戳

  查询受**新鲜度门卫**约束：两表各带 `last_checked_at` 列记录上次成功查询时间，距上次查询不足 30 分钟（常量 `CHECK_INTERVAL_MINUTES`）的单据直接跳过。循环内"已确认在平台跳过"（SQLite 已有上传成功/单据重复记录，按 `(company, djbh)` 判重）不调 API：`check_bill_status` 对任务直接标记 `已处理` 并一并 touch；`check_failed_logs` 保留历史记录、`continue` 不 touch。**仅 API 异常不 touch**，下次 cron 自动重查。新单据 `last_checked_at` 为 NULL，天然立即查。

- **手动上传 (Manual Upload)**：页面最上方先选**所属企业**（选项来自 `App\Enterprise`），选定后显示该企业对应的内容——批发与零售的字段、接口、凭据完全不同。

  - **批发分支**（默认选中批发主体）：**在线新增**——用户选择单据类型（必选下拉，分组展示全部入库/出库类型）→ 填写日期/单号/往来单位 → 粘贴追溯码（支持一行一个或逗号分隔，JS 自动转逗号）→ 写入 SQLite → 立即上传；**xlsx 导入**——下载模板（5 列：日期/单号/单据类型/往来单位/追溯码），同单号多行自动合并为一个任务（取首个非空的日期/单据类型/往来单位，合并所有追溯码），也兼容传统一行一个单据格式。上传逻辑与 cron 共用 UploadService。xlsx 导入与模板下载**只服务批发**
  - **门店分支**：**不提供从零手工录入**，只列该门店的"待补传"清单（单号/日期/类型/码数）→ 勾选 → 选凭据 → **批量补传**（与数据页的单条补传共用 `App\RetailRetransmit`）。待配凭据的门店清单可见但补传禁用并写明原因；`未识别` 不是一个企业，不出现在这个下拉里

- **Web 管理**：Bootstrap 5 + flatpickr，左侧可折叠菜单，AJAX 交互。三个数据页面（上传任务/上传成功/失败记录）均支持按单号/往来单位/状态/所属企业/单据日期/任务创建时间筛选，表格含单据类型列（中文名称）与所属企业列；日期使用 flatpickr 范围选择器（一个输入框选起止日期），分页最多 10 个页码。管理上传任务（查看/编辑/删除/重传/零售补传/批量操作）、浏览上传日志（已上传/失败记录）、手动上传（顶部先选所属企业：批发＝在线新增/xlsx 导入，门店＝待补传清单批量补传）。零售行的补传入口在**不可补传**时（`未识别`、待配凭据）显示为禁用并写明是哪一种原因——两者含义完全不同：前者要人去查配置/源库，后者只是授权还没拿到。

- **重传 (Retransmit)**：对已处理的任务重新发起上传调用，**批发与零售是两条链路**。批发走 `UploadService` 的单条/批量重传（kyt 接口 + 往来单位缓存），区分网络超时（重试最多 3 次，间隔 30s）和 API 业务错误（不重试，直接标记失败）。零售走**补传**——见下条。

- **补传 (Retail Retransmit)**：零售门店单据的**人工触发**重传（只走 lsyd 接口），**不自动**——没有 cron、没有自动重试：向平台的每次申报都不可逆，由人在页面上显式选凭据（门店多套凭据时由人指定，不做自动分发规则）比自动重试可靠。**两个入口共用 `App\RetailRetransmit` 一份实现**：**逐条**（上传任务页零售行的"补传"按钮）与**批量**（手动上传页选定门店后勾选清单一并补传，整批限同一门店）。单据元数据（日期 / 类型 / 追溯码 / fromUserId / toUserId / physicType）**全部取自采集时落库的记录**，不提供从零手工录入——手工录 4 个平台 ID 几乎必然出错，且本轮不查平台，录错了察觉不了。装配前逐关 fail-closed（非零售企业 / 凭据不属于该门店或未配齐 / 无路由或必填项缺失），任一不过即整条拒绝且不发一次调用。结果落 `upload_logs`（来源值 `retail_retry`——单条与批量共用该值，同批发链路 `retry`/`batch_retry` 都写 `batch_retry` 的取法；带所属企业与实际用的凭据）并翻转任务状态——**失败也翻 `已处理`**，因为任务表是待处理队列，"补传没成功"的出口是失败记录页。校验不过时任务行**一个字段都不动**（不发调用、不写日志）；批发链路那两个重传入口遇到未捕获异常会恢复为**调用前的状态**（批发行即"等待上传"——零售补传不照搬那套复位，理由见源码注释）。批量入口**逐条隔离**：一条被拒只影响它自己，其余照常补传。

### 任务状态机

```
批发：等待上传 → 已处理
零售：待补传   → 已处理（仅人工触发，不会被自动上传 cron 取走）
```

- **等待上传**：**批发**任务已创建，尚未发起上传，`upload_pending.php` 会取走并自动上传
- **待补传**：**零售**单据已采集入库、等待人工手动补传。**不复用"等待上传"**——零售由外部系统上传，若以"等待上传"落库会被 `upload_pending.php` 用河药凭据、走批发接口误传到河药主体名下（不可逆的平台数据错误）
- **已处理**：上传完成（不论成功或失败），具体结果见 `request_status` 和 `response_status` 字段

`request_status`（请求状态）：请求成功 / 请求失败
`response_status`（响应状态）：上传成功 / 单据重复 / 上传失败 / 信息不存在 / 往来单位缺失 / 未确定 / 数量不符（**仅 `upload_logs`**，数量对账 `check_quantity` 专用——任务表不产生该值）

状态颜色标签：等待上传(灰)、已处理(绿/黄/红取决于 response_status)。

### 日志链

```
码上放心 API 响应
    ↓ 实时写入
  JSONL 文件（永久保存，logs/api_YYYY-MM-DD.jsonl）
    ↓ 同步写入
  SQLite upload_logs（查询用，保留 3 个月）
    ↓ 定时清理（scripts/cleanup_logs.php）
  删除 3 个月前的 SQLite 记录
```

### 外部系统

- **SQL Server (192.168.2.133)**：**批发**的 ERP 数据库，`hyyy_zyscm` 库 + `skwms_new` 库。cron 定时查询源。通过 `TaskFetcher` 访问。
- **dyt 链接服务器**：**零售连锁**的单据来源。`dyt.msfx.dbo.zsm_ls`（单据头）+ `zsm_ls_code`（追溯码，按 `bill_code` 关联）；上游还有 `dyt.bs_msfx.dbo.update_state`（单号 + 上传状态两列），是**外部系统的私有状态**——本项目对源库**只读、从不回写**。它常被用来过滤出"尚未上传的单"：ADR 0007 曾判它不能当采集门卫（会把已上传的单全部隐藏，而核对需要看见它们；且它**无企业列**，跨门店单号重复时自身会串），**2026-09-30 测试阶段临时把这个过滤加回**（见 ADR 0007 的修订注与 CLAUDE.md"零售单据采集"）。
- **码上放心 API (gw.api.taobao.com)**：阿里健康药品追溯平台，通过 TOP SDK 调用。**批发与零售走不同接口族**：批发 `alibaba.alihealth.drug.kyt.*`（`uploadinoutbill`、`searchbill.detail`、`listparts`、`singlerelation`）；零售 `alibaba.alihealth.drugtrace.top.lsyd.*`（`uploadinoutbill` 用于 104/203 调拨、`uploadretail` 用于 321/116 零售场景）。凭据按所属企业取，不再只有一套。
- **SQLite (本地 data/msfx.db)**：存放上传任务、上传日志、往来单位缓存。Web 查询和写入选 SQLite，cron 写入 SQLite。

### 系统架构

```
浏览器 (192.168.2.189:8188)
    ↓ Nginx → PHP-FPM 127.0.0.1:9008
    ↓ public/index.php (page 参数路由)
    ↓
Web 视图 (src/views/)     AJAX API (src/api/)
    ↓                        ↓
Database (SQLite)      UploadService (API调用)    check_bill_status.php
                           ↓                        ↓
                       TaskFetcher (SQL Server)   ApiClient::searchBillDetail()
                           ↓                        ↓
                       码上放心 API             码上放心 API
```

### 认证

单用户登录，密码 bcrypt 哈希存储在 `config/.env`（ADMIN_PASSWORD_HASH）。session 认证，所有非公开页面需登录。登录页无菜单，登录后进入仪表盘。
