# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 文档同步规则

完成任何代码变更（新功能、改表结构、改流程、改路由、新增/删除文件）后，在提交前必须同步更新相关文档：

- 本文件（CLAUDE.md）：架构图、Web 路由表、表结构、核心数据流、常用命令等章节，与代码现状不一致的地方
- `CONTEXT.md`：领域词汇/术语含义发生变化时
- `docs/adr/`：做出难以逆转的决策时追加一条 ADR
- `.scratch/<feature-slug>/`：对应 feature 的 issue 状态

提交时文档与代码一同提交，不允许只提交代码而文档过时。如果发现已有文档与代码现状不符，先修正文档再继续。

## 项目概述

药品追溯码上传系统（码上放心平台对接），用于河药将 ERP 系统中的出入库单上传至阿里健康 "码上放心" 平台。Web 管理端 + CLI 脚本，OOP 架构，无框架。

计划开发 Python 桌面客户端版（单机软件，交付客户），完整工程提示词见 `docs/python-client/engineering-prompt.md`（含技术栈、目录结构、数据模型、业务规则、UI 设计、开发里程碑）。

## 项目架构

```
root/
├── top_sdk/              # 阿里健康 TOP SDK（vendored，不可修改）
│   ├── TopSdk.php        # SDK 入口
│   └── top/
│       ├── TopClient.php          # HTTP 客户端（cURL + MD5 签名）
│       ├── request/*.php          # API 请求类（批发 drug.kyt.* + 零售 drugtrace.top.lsyd.* 两个，见下方说明）
│       └── domain/*.php           # 返回结果的 DTO（本项目里惰性，响应走 simplexml → SimpleXMLElement）
├── src/                  # 项目自建类（namespace App\，PSR-4 自动加载）
│   ├── Config.php                # .env 配置加载
│   ├── Database.php              # SQLite 数据库封装（单例）
│   ├── Auth.php                  # 单用户 session 认证
│   ├── Enterprise.php            # 企业/门店配置解析、门店认领（平台 ID 优先）、接口路由与码上限、配置自检、批发主体入口（wholesaleSubject）、三数据页"所属企业"下拉选项（selectableNames）、某企业的凭据键（defaultCredentialKey，编辑任务改企业时用）、某企业那套凭据（credentialFor，门店与凭据 1:1）、页面补传可用性摘要（retailCredentialReady）
│   ├── BillType.php              # 单据类型码归一化（字母前缀 ↔ 3 位数字码）
│   ├── ApiClient.php             # 封装 TopClient（上传/查询/搜索/singlerelation 码级折算、上传响应状态解析 resolveUploadResponseStatus——批发与零售补传共用，平台响应怎么读只在这一个文件里回答）
│   ├── TaskFetcher.php           # 从 SQL Server 拉取/统计待上传单据（含 fetch_bills 门卫计数、fetchBillQuantities 数量基线聚合、fetchWmsCodesByDjbhList 第 2 级码基线现查）
│   ├── UploadService.php         # 核心上传逻辑（cron 和 Web 共用）；上传前 fail-closed 校验任务所属企业与凭据，非批发 kyt 一律拒传
│   ├── RetailRequestAssembler.php # 零售补传的请求装配（纯函数：不发起平台调用、不读数据库、不写日志）：请求类与追溯码上限取自 Enterprise::route()，refUserId 取凭据 ref_ent_id，装配完调 SDK 的 check() fail-closed；调用方是 App\RetailRetransmit
│   ├── RetailRetransmit.php      # 零售补传的完整流程（三关 fail-closed → 拆单 → 调用 → 写日志 → 翻任务状态）：单条（tasks_retry_retail）与批量（tasks_batch_retry_retail）两个端点共用的唯一实现；装配仍走 RetailRequestAssembler，来源写 retail_retry
│   ├── RetailRetention.php       # 门店数据保留期（平台硬性规定 2 年，不接受 2 年前的单据）：YEARS + cutoffDate() 是采集下限与清理下限的**唯一来源**；两个调用点必须共用，各写各的会让超期数据滞留
│   ├── TraceSplitter.php         # 追溯码两种拆法：splitByCount 按码数拆单（上传用，上限取自 Enterprise::route()，批发 3500 / 零售 10000·3500）、splitByCharLimit 按字符数拆行（导出用，每行 ≤32000 字符）
│   ├── RecordQuery.php           # 三数据页筛选条件单一事实源（build(类型, 参数) → WHERE/SELECT/ORDER/params）：列表 API 与导出**共用同一段代码**，"导出的行数与页面一致"是构造上的性质。曾经四处各写一份，export 的失败分支因此漏过 quantity_check 豁免
│   ├── LogWriter.php             # JSONL + SQLite 双写日志
│   ├── SqlSrvHelper.php          # SQL Server 数据库操作封装（根命名空间，classmap 加载；queryEach 为逐行消费大结果集的
│   │                             #  回调式接口，供"结果集可能有数十万行、不能攒进内存"的场景用）
│   ├── LockManager.php           # 未使用（预留）
│   ├── Logger.php                # 未使用（预留）
│   ├── api/                      # AJAX API 端点
│   │   ├── tasks.php             # 上传任务 CRUD（GET 列表/单条, PUT 编辑, DELETE 删除）；PUT 改 company 时连带把 credential 重设为该企业的凭据键（只改企业不改凭据，守卫会以"取不到可用凭据"拒传——未识别行本就是 NULL）
│   │   ├── tasks_retry.php       # 单条重传（批发 kyt）
│   │   ├── tasks_retry_retail.php # 零售门店单据补传（人工逐条；凭据不由入参给，服务端按门店取）：只做请求解析与流式输出，流程在 App\RetailRetransmit
│   │   ├── tasks_batch_retry_retail.php # 零售批量补传（手动上传页选定门店后触发）：整批限同一门店（混入别家整批拒），逐条 try/catch 隔离，共用 App\RetailRetransmit
│   │   ├── tasks_batch_delete.php # 批量删除上传任务
│   │   ├── tasks_batch_retry.php  # 批量重传
│   │   ├── uploaded.php          # 已上传记录列表（upload_logs success=1）
│   │   ├── failed.php            # 失败记录列表（排除**同一企业内**该单号已有上传成功/单据重复记录的日志行——去重键是 (company, djbh)，裸 djbh 会让零售失败记录被同号批发成功单顶掉；quantity_check 来源记录豁免——数量对账仅查已上传成功单，若不豁免会被 NOT EXISTS 全隐藏，告警出口失效；**已知缺口**：该页"来源"列的标签表与来源下拉都没有 `quantity_check`，数量对账告警因此在页面上直出机器值、也按来源筛不出来——本轮未动）
│   │   ├── logs_delete.php       # 删除单条日志记录
│   │   ├── logs_batch_delete.php # 批量删除日志记录
│   │   ├── manual_create.php     # 手动创建任务并立即上传
│   │   ├── manual_import.php     # xlsx 导入批量创建并上传（只服务批发）
│   │   ├── manual_retail_tasks.php # 手动上传页零售分支的待补传清单（固定口径：该门店 + source=retail + task_status=待补传；
│   │   │                           #   分页每页 20 条，回 trace_codes 供每行的"查看追溯码"按钮；
│   │   │                           #   排序 rq DESC, id DESC——单据日期倒序，新的在前）
│   │   ├── template_download.php # 下载 xlsx 导入模板
│   │   └── export.php            # 按当前筛选条件导出 xlsx（流式生成，内存 O(1)）
│   └── views/                    # 页面视图（PHP 模板）
│       ├── layout.php            # 全局布局（左侧菜单 + 顶栏）
│       ├── login.php             # 登录页
│       ├── dashboard.php         # 首页仪表盘（4 个统计卡片）
│       ├── upload_tasks.php      # 上传任务管理页（表格 + CRUD + 批量操作）
│       ├── uploaded.php          # 已上传记录页
│       ├── failed.php            # 失败记录页
│       └── manual_upload.php     # 手动上传（顶部先选"所属企业"：批发分支＝在线表单 + xlsx 导入，门店分支＝待补传清单 + 批量补传）
├── config/
│   ├── .env                      # 数据库连接 + API 凭证（迁移期）+ 管理员密码
│   ├── enterprises.php           # 企业结构：企业名/类型(wholesale·retail)/凭据位(label) —— 入 git，无凭据
│   │                             #   （门店与凭据已定为 1:1，结构里的 `primary` 标记已删除，见 docs/adr/0012）
│   ├── enterprises.example.php   # enterprises.local.php 的模板（占位符）—— 入 git
│   ├── enterprises.local.php     # 门店平台 ID + 凭据四字段明文 —— **不入 git**（.gitignore）
│   └── sql.php                   # SQL Server 原始查询（**调试残留，口径以脚本为准**；批发采集口径含 a.is_zx='是' 已执行单据过滤，2026-08-27；
│                                 #  零售 `$get_up_task_retail` 已不适用——写死单一 bill_type='203'、类型不全，现行口径见
│                                 #  scripts/fetch_bills_retail.php；它那句 NOT EXISTS(update_state) 曾于 2026-09-29 被 ADR 0007
│                                 #  判为"去掉"，又于 2026-09-30 因测试阶段口径临时加回——**两边都别照抄，以脚本为准**）
├── public/
│   ├── index.php                 # Web 单入口（page 参数分发路由）
│   └── favicon.svg               # SVG 网站图标
├── scripts/
│   ├── fetch_bills.php           # cron 从 SQL Server 采集**批发**单据写入上传任务表
│   ├── fetch_bills_retail.php    # cron 从 dyt 链接服务器采集**零售门店**单据（单条 SQL：LEFT JOIN + NOT EXISTS(update_state)
│   │                             #  过滤，测试阶段口径；默认当日，`--all` 为一次性全量快照入口——**下限 2 年**，
│   │                             #  超期单据采进来也补传不出去；去重在 PHP 侧，走 queryEach 逐行消费；
│   │                             #  认领走 Enterprise::claim；落库 source=retail / task_status=待补传）
│   ├── upload_pending.php        # cron 批量上传队列中等待中的任务（只取批发主体的"等待上传"）
│   ├── check_bill_status.php     # 批量查询单据上传状态（来源 1：等待上传任务，高频 8-20 点）
│   ├── check_failed_logs.php     # 复查失败记录（来源 2：upload_logs 未上传成功记录，每天 20:40）
│   ├── check_quantity.php        # 数量对账两级流水线（第 1 级 shl 粗筛嫌疑单 → 第 2 级 singlerelation 码级精查，双差异才写"数量不符"）
│   ├── cleanup_logs.php          # 清理 SQLite 历史数据，三条判据各不相同：日志按 created_at 清 3 个月前、已处理任务按
│   │                             #  updated_at 清 3 个月前、**门店（零售）任务按 rq 单据日期清 2 年前**（平台不接受 2 年前的
│   │                             #  单据，见 App\RetailRetention——这条判"单据本身多老"，前两条判"记录存了多久"）
│   ├── backfill_rq.php           # 回填 upload_logs 的单据日期（rq 列；按 djbh 关联处一律限定批发主体——djbh 不是跨企业唯一的）
│   ├── init_db.php               # 初始化/迁移 SQLite 数据库及表结构（幂等；含 company/credential 列、历史回填、ent_list 唯一键重建）
│   ├── sqlite_query.php          # 调试工具：直接传 SQL 查询/操作 SQLite（表格输出）
│   ├── migrate_status_fields.php # 【一次性迁移，2026-07-29 已执行】旧 status/success 两列拆为 task_status/request_status/response_status
│   ├── fix_response_status.php   # 【一次性修复，2026-07-29 已执行】按 resp/response 重解析，修正映射错误、显示为"未确定"的记录
│   └── cron_handle.php           # 空文件（0 字节、全仓无引用）——归档残留，无用途，别指望它有行为
├── data/
│   ├── msfx.db                   # SQLite 本地数据库（3 张表 + 索引）
│   └── fetch_bill_counter.json   # fetch_bills 变化检测门卫基线（当天单据计数）
├── tests/
│   ├── trace_splitter_test.php   # TraceSplitter 自包含断言测试（php tests/trace_splitter_test.php；用例 16 是工单 07 验收第 3 条的离线口径——2000 码的 104 在 10000 上限下不拆、4000 码的 321 在 3500 上限下拆 3500+500）
│   ├── quantity_check_test.php   # ApiClient::isBillFound 自包含断言测试（php tests/quantity_check_test.php）
│   ├── enterprise_config_test.php # App\Enterprise 自包含断言测试：配置解析/门店认领/接口路由/配置自检
│   ├── retail_upload_test.php    # App\RetailRequestAssembler 自包含断言测试：lsyd 入参映射（refUserId 取凭据 ref_ent_id、from/to 照搬源表列、clientType=2、码上限取自路由），判据用请求类自己的 check()
│   ├── retail_retention_test.php # App\RetailRetention 自包含断言测试：2 年截止日的计算与边界（常规/跨年/月末/闰日溢出方向、截止日当天保留、不传参时相对今天滚动）
│   ├── search_bill_test.php      # searchbill.detail 查询调试：传单号输出完整返回并另存 searchbill_<单号>.json（tests 目录内；退出码 0=全部成功，1=存在网络/业务错误）
│   ├── singlerelation_test.php   # singlerelation 逐码查询调试（码级对账探针）：验证 Σ 折算系数 == min_pkg_count 核心等式（折算规则 is_smallest=Y→1 忽略 pkg_amount，2026-08-26 加固；设计见 .scratch/quantity-check/singlerelation-tier2.md；避开 8-20 点窗口运行）
│   └── searchbill_*.json         # search_bill_test.php 的查询结果存档
├── logs/                         # API 日志 JSONL 文件
├── upload_test.php               # 原始上传脚本（旧版，保留参考；**勿运行**——include 路径失效、连的是旧库、ent_list 读写早于多企业改动）
├── get_ent_list_test.php         # 原始往来单位同步脚本（旧版，保留参考；**勿运行**——ent_list 唯一键已改为 (company, ent_name)）
└── bill_info_test.php            # 原始单据查询脚本（旧版）
```

**`top_sdk/` 的 vendored 约定（2026-09-30 工单 04）**：`top_sdk/top/request/` 下现在共存两代请求类——批发用的 `drug.kyt.*`（SDK 2026-02 世代，原有）与零售用的 `drugtrace.top.lsyd.*`（2026-09 世代，从 `top_sdk_retail.zip` 逐类并入的 2 个：`AlibabaAlihealthDrugtraceTopLsydUploadinoutbillRequest` / `...UploadretailRequest`）。压缩包**不进仓库**。**跟进新版 SDK 只能逐类挑，不能整包覆盖**：新包里没有一个旧包的 `drug.kyt.*` 方法（它是 `drug.kyt.wes.*`），覆盖会直接砍掉批发链路；`top/domain/` 的 DTO 类在本项目里是惰性的（`TopClient` 走 `$format="xml"` → `simplexml_load_string` → `SimpleXMLElement` 直接返回，domain 类不参与响应解析）。理由与比对数据见 `docs/adr/0009`。

## Web 路由

单入口 `public/index.php`，通过 `page` 参数分发：

| page 参数 | 视图文件 | 说明 |
|-----------|---------|------|
| `login` | `views/login.php` | 登录页（公开） |
| `dashboard` | `views/dashboard.php` | 首页仪表盘 |
| `upload-tasks` | `views/upload_tasks.php` | 上传任务管理 |
| `uploaded` | `views/uploaded.php` | 已上传记录 |
| `failed` | `views/failed.php` | 失败记录 |
| `manual-upload` | `views/manual_upload.php` | 手动上传 |
| `api` | `api/{action}.php` | AJAX API 端点（导出实际走 `page=api&action=export`，前端按钮以此调用） |
| `asset` | —（`return false` 结束脚本，无输出） | 静态资源分支（`index.php:37`），**位于登录校验之前**故不经过认证。**当前 nginx 配置里没有路由指向它**（`location /` 只 `try_files $uri $uri/ /index.php?$args`，静态资源另有 `location ~* \.(css\|js\|svg\|…)$`），因此实际不可达——保留原因未见于代码 |

所有页面（除 login、api 与 asset）需要登录。API 端点内部自行处理认证。

**上传任务页（工单 03，2026-09-30）**：表格含**"所属企业"列**（零售门店单即为门店名；`未识别` 整行标红 + 红色徽标，表示源库单据认领不到门店——真异常信号，需人工核查）；零售行（`source='retail'`，即采集来的门店单据）走**补传按钮**（工单 06 落地，批发行仍是原来的"重传"）——见"核心数据流 → 零售补传"。任务状态下拉含 `待补传`、来源下拉含 `零售采集`——**默认筛选是"等待上传"，门店单据要看需切到"待补传"或"全部"**。编辑弹窗可改"所属企业"（工单 08），**改后需二次确认**——那是"单据申报到哪个主体"的开关（失败记录页那份弹窗同样生效）。

**手动上传页（工单 07，2026-09-30）**：顶部先选"所属企业"，批发分支＝原来的两张卡片（行为不变），门店分支＝该门店的待补传清单 + 批量补传（清单分页、每行可"查看追溯码"）——见"核心数据流 → 手动上传（Web 端）"。批发与零售都有的两类单据类型（`321` 使用出库 / `116` 消费者退货入库，工单 03 起会采集入库）本次补进四个页面的类型标签表：缺了它们这三类零售单在页面上显示成 `-`。

三个数据页面（upload-tasks / uploaded / failed）均支持筛选：单号、往来单位、状态、**单据日期**（`rq`）、**任务创建时间**（`created_at`）。日期筛选使用 flatpickr 范围选择器，一个输入框同时选起止日期，默认最近 7 天（含当天）。**关键词检索（单号/往来单位）不受默认日期范围限制**：输入关键词时若日期选择器仍是默认 7 天（用户未手动改过），前端自动不传日期参数实现全库检索；用户手动改过日期则关键词+日期正常组合过滤。分页最多显示 10 个页码，超出用省略号。

三个数据页工具栏均有"导出 xlsx"按钮：按当前生效筛选条件全量导出（前端已计算关键词忽略默认日期后的参数）。导出走 `page=api&action=export`（`api/export.php`），**流式生成**（sheet XML 逐行写临时文件 + ZipArchive 打包，不用 PhpSpreadsheet 避免全量驻留内存）；追溯码按字符数拆行（`App\TraceSplitter::splitByCharLimit`，每行 ≤32000 字符 ≈ 1523 码，超限时一单多行、单号加 `_N` 后缀，命名对齐上传拆分、已带后缀的单号追加后缀），拆行兜底（单条码自身超 32000 字符的极端情况）仍截断并追加 `…(共N个码)`，其余列超限追加 `…(已截断)`；无匹配数据时前端拦截提示、后端仍输出带表头的空文件。导出列与页面表格对齐（来源列导出机器值 cron/manual/...，单据类型导出归一化 3 位码），文件名 `上传任务/已上传/失败记录_YYYY-MM-DD.xlsx`。

**三数据页的"所属企业"筛选与导出列（工单 08）**：三页表格都有"所属企业"列（上传任务页工单 03 加、已上传页工单 06 加、失败记录页工单 08 补齐）。筛选栏都有"所属企业"下拉，选项由 `Enterprise::selectableNames()` 给出（企业枚举 + `未识别`，已配 16 家企业共 17 项；`未识别` 不是一个企业，但确实是 `company` 列的一个取值——门店认领失败的那批，必须能单独筛出来）。**按 `company` 去重**——每家恰一套凭据（1:1，见 `docs/adr/0012`），故"一门店多套凭据时显示 `门店名（label）`"这类问题已不存在。xlsx 三类导出都含"所属企业"列（位置与页面表格一致，在"单据类型"之后）。

- **`未识别` 的呈现三页并不相同**：只有上传任务页另加红色徽标 + 整行标红（它是操作页，那批单等着人处置）；已上传/失败两个日志页只作普通文字——工单 06 定的就是日志页不标红，本票未改
- **"企业下拉不算关键词"**：三页都有"输入关键词时丢掉默认 7 天日期范围"的逻辑，那里的关键词**只算单号与往来单位**。企业下拉是筛选维度，算进来会让"选了企业"顺手把默认日期范围也丢掉，日期行为被无声改变
- **三页的默认 7 天维度不同**（上传任务页＝单据日期 `date_from/to`；已上传/失败页＝任务创建时间 `date_from/to`，单据日期改用 `rq_from/to`），映射写在 `RecordQuery::addRange` 的调用处，改参数名时别搞混
- **筛选构造单一事实源 `App\RecordQuery`**：`tasks/uploaded/failed/export` 四个入口都调它，原先那 4 份拷贝已删。已知的一处漂移顺带消失——`export.php` 的失败记录分支曾无条件走 NOT EXISTS、缺了页面版那句 `source = 'quantity_check' OR` 豁免，结果是失败记录页看得见的数量对账告警、导出的 xlsx 里没有。**仪表盘那张卡片仍是第 5 份拷贝**（`views/dashboard.php`，其 NOT EXISTS 既没限定 `company` 也没有该豁免），本轮明确不动，等零售接入稳定后再统一

## 核心数据流

### 定时上传（fetch_bills.php + upload_pending.php）

采集和上传解耦为两个独立脚本，可分别设 cron 规则。

**采集（fetch_bills.php）**：启动时轻量查询 SALEOUTMT/PURINMT 当天单据计数，与 `data/fetch_bill_counter.json` 基线比较——同一日期且计数相同则跳过采集（避免重视图查询空转），基线只在采集成功（视图查询 + SQLite 写入全部完成）后更新；然后 SQL Server 查询当天单据（**仅取已执行单据 `a.is_zx='是'`**，作废/未执行单据不采集，口径与 `config/sql.php` 一致）→ 按 **`(company, djbh)`** 去重（跳过 `upload_tasks` 中已存在的任务，以及 `upload_logs` 中已上传成功/单据重复的单据；同名单号属于别的企业时是另一条记录，不能互相顶掉）→ 写入 SQLite `upload_tasks`（source=cron, task_status=等待上传, bill_type=单据号前缀, **company/credential 取 `App\Enterprise::wholesaleSubject()`**）

**上传（upload_pending.php）**：读取 `upload_tasks` 中 `task_status='等待上传'` **且 `company` = 批发主体**的任务——**白名单取数**，不是"排除零售/其他来源"的排除法（排除法 fail-open：将来任何新增来源漏改条件，就会把门店单据按河药主体申报到平台，不可逆）→ 查 SQLite `ent_list` 缓存（按 `(company, ent_name)`）→ 缓存未命中调码上放心 API 获取 `ent_id` → 超过 3500 追溯码自动拆分为 `单号_1, 单号_2...` → 调 API 上传 → 结果写入 JSONL + SQLite `upload_logs`（关联 task_id，带 company/credential）→ 更新 `upload_tasks` 状态 → 重试 3 次（仅网络错误，间隔 30s）→ API 间隔 0.33s → flock 文件锁防并发

**上传守卫（UploadService::resolveContext，2026-09-29 多企业排雷）**：`upload()` 在取锁与任何平台调用**之前**逐条校验所属企业、接口族与凭据，任一不合规**整批拒绝**（抛 `\RuntimeException`，不发一次调用、不写一条日志；混合批次"传一半才报错"比一开始就拒绝更难收拾）：
- `company` 必须在企业配置中（`App\Enterprise::find`）——`未识别`/空/未知企业名一律拒传
- 该企业在该单据类型上的路由必须落在**本服务支持的批发 kyt 接口**（`Enterprise::route()` 的 class 比对）——零售走 lsyd，一律拒传（零售装配见工单 05/06）
- 该企业必须取到**填齐的**凭据（任务行的 `credential` 列指定凭据位，取不到或残缺即拒传）；**绝不回落到默认（河药）凭据**

守卫在 UploadService 而非调用方，是刻意的：`upload_pending` / `tasks_retry` / `tasks_batch_retry` 三处以及将来新增的调用方都会 `new UploadService()`，只把 SQL 写对护不住直接调用的脚本。

手动上传（manual_create / manual_import）保持立即上传不变，两套上传路径并存；其落库主体同为批发主体（`Enterprise::wholesaleSubject()`，按企业切换表单见工单 07）。

### 零售单据采集（fetch_bills_retail.php，工单 03，2026-09-30）

零售单据由外部系统上传，本项目只做"**可见** + 人工补传"（见 `docs/adr/0007`）：本脚本**只采集入库、不上传**，不调任何平台接口，故不受 8-20 点限流窗口约束（cron 与 fetch_bills 同频、错开 5 分钟，见下方 cron 时间表）。

> ⚠️ **2026-09-30 起为测试阶段临时口径**（用户指定，与 ADR 0007 的原始决定**相反**，测试结束需回收）：采集改为**单条 SQL**（`zsm_ls LEFT JOIN zsm_ls_code`）+ **`NOT EXISTS(update_state)` 过滤**（只采外部系统尚未上传的单）。代价照 ADR 0007：已上传的单在页面上不可见；`update_state` 无企业列，跨门店单号重复时它自身会串。原先的"两步 SQL + 全量"口径见 git 历史。
>
> **日期：cron 限当日，历史欠账走 `--all` 一次全量**（2026-09-30 用户定）——不带参数 = 当日；`--all` = 不带等值日期条件的一次性全量快照，把外部系统尚未上传的历史单一次性入库，**跑一次即可、别挂进 cron**。
>
> **2 年下限（2026-09-30 用户定，平台硬性规定）**：采集 SQL **始终**带 `bill_time >= 截止日`（`App\RetailRetention`，今天是 2026-09-30 则 2024-09-30）——`--all` 靠它截断，故其语义是"**最近 2 年**的快照"而非全部历史（票 03 回填的首跑数字是旧口径，重跑会变小）；cron 的当日采集天然满足。**显式指定一个超期日期时直接拒绝并退出 1**（在连源库之前）：静默采回 0 条会被读成"那天真没单据"，而真相是那天即使有单也补传不出去。依据是平台的原话——补传 2023 年的单会返回 `FAIL_BIZ_PARAM_BILL_TIME_BEFORE_ERROR`「系统不支持上传2年前单据」。决策与代价见 `docs/adr/0013`。

- **源**：dyt 链接服务器（`dyt.msfx.dbo.zsm_ls` 单据头 + `zsm_ls_code` 追溯码，**一码一行**；`bs_msfx.dbo.update_state` 单号 + 状态两列，**只读**、仅用于过滤），复用 `SqlSrvHelper` 同一条连接直接查 4 段式名；全程只读 SELECT，不写源库
- **单条 SQL（测试阶段口径）**：`LEFT JOIN` + `NOT EXISTS(update_state)` + `bill_time >= ?`（保留下限，始终在）+ `bill_time = ?`（默认当日；`--all` 时不加这一段）。**必须 LEFT JOIN 而非内连接**——没码的单也要采（它是待补传队列里值得看见的一条）
- **去重在 PHP 侧收口**：`321` 存在 14 列值全同的完全重复行（同一单号最多 120 行），连接结果随之放大最多 120 倍——追溯码用关联数组去重（保序），单据头字段取首次出现的行（重复行各列本就相同）。`--all` 时行数可能到数十万，走 `SqlSrvHelper::queryEach` **逐行消费**而非 `query()` 攒数组（后者会撞上 CLI 的 `memory_limit=128M`）
- **`physic_type` 必须显式取**：老 SQL 里没有这列，但补传装配要它（ADR 0010）——漏掉它，`104`/`203` 那些单会被 SDK 的 `check()` 拒掉且**永远补不出去**
- **单据类型写死四种** `104`/`203`/`321`/`116`（`bill_type` 是 int；第五种 `999` 语义未明，用户判定不采）；`bill_time` 是 `varchar(10)` 纯日期
- **无计数门卫**（`fetch_bill_counter.json` 那套是为"重视图查询空转"设计的，零售是幂等去重，门卫只省一次扫描却多一份状态文件）；**不需要拆单**（实测单张单据码数上限 1,718 < 3500）
- **认领**走 `App\Enterprise::claim()`（不另写一套匹配）：`321`/`116` 取 `from_user_id`、`104`/`203` 取 `to_user_id` 命中门店登记过的任一平台 ID，ID 缺失才回退 `oper_ic_name`；都不命中 → `company='未识别'` **照常入库**（丢单比错标更危险）。`name_unmatched`（ID 认到、源库名字对不上任何门店）记一条 JSONL 警告，**只进 JSONL 不进 `upload_logs`**（后者是上传结果日志，写进去会在失败记录页冒出既非上传也非失败的记录，污染唯一告警出口），不改判定
- **落库**：`task_status='待补传'`（不复用"等待上传"——那语义是"cron 会来取走并上传"）、`source='retail'`、`company` 取认领结果、`credential` 取 `claim()` 返回的该门店凭据键（**待配凭据的门店同样预填键**，页面据 `credentialConfigured()` 禁用补传）、`ent_name` 留空（零售对手方 ID 直接来自源表，不用 `ent_list`）
- **补传要用的元数据一并落库**（工单 06）：`from_user_id` / `to_user_id` / `physic_type` 照搬源表同名列（单据头字段取该单首次出现的行）。补传装配要这三列，缺一列这条单就永远补不出去——**没有历史回填**（那三列对批发行无意义，零售的值只能从源表现采），工单 06 之前采的零售行已删除并按日期重采；将来遇到缺列的旧行，办法同样是重采（`(company, djbh)` 去重会跳过已存在的行，不重采就补不上值）
- **幂等**：按 `(company, djbh)` 去重（同批发：`upload_tasks` 已有行、或 `upload_logs` 已上传成功/单据重复的单据都不再入队——人工补传成功后任务行被删，重采集不该再入队造成重复申报）
- **失败不写库**：源库不可用时 `SqlSrvHelper::queryEach` 返回 `false` 且错误另存在 `lastError`，脚本据此区分"真没单据"与"查询失败"，后者非零退出；源库查询**全部读完才开始写库**，不会产生"读一半写一半"
- **页面**（`views/upload_tasks.php`）：表格加"所属企业"列，`未识别` 行标红 + 红色徽标；零售行（`source='retail'`）走**补传按钮**（工单 06 落地，取代工单 03 里那个被关掉的重传按钮）；任务状态下拉补 `待补传`、来源下拉补 `零售采集`（默认筛选仍是"等待上传"，要看门店单据需切到"待补传/全部"）

### 零售补传（Web 端，App\RetailRetransmit，工单 06 / 07）

零售单据由外部系统上传，本项目只做"可见 + 人工补传"（ADR 0007）。补传**只能人工触发**——没有 cron、没有自动重试：
向平台的每一次申报都不可逆，由人在页面上看清是哪张单再点，比自动重试可靠。落库口径、三列入库与失败算不算处理完的决策见 `docs/adr/0011`；**用哪套凭据不由人给**（门店与凭据 1:1，服务端按门店取）见 `docs/adr/0012`。

**两个入口共用 `App\RetailRetransmit` 一份实现**（工单 07 收口）：单条（`api/tasks_retry_retail.php`，上传任务页零售行的"补传"按钮）与批量（`api/tasks_batch_retry_retail.php`，手动上传页选定门店后勾选清单）。两个端点各自只做「解析请求 + 流式输出」——流程（三关 fail-closed → 拆单 → 调用 → 写日志 → 翻任务状态）在类里；之所以不能各写一份、也不能靠 include 复用，是因为端点文件被另一个端点 include 会执行它的认证与 `exit`。

- **入口**：上传任务页零售行（`source='retail'`）的"补传"按钮 → 弹窗列出单据元数据（单号/日期/类型/门店/码数）→ 确认即传，**页面上没有任何要填的字段**。**元数据全部取自采集时落库的记录**，不接受调用方传任何单据字段（手工录 4 个平台 ID 几乎必然出错，且本轮不查平台，录错了察觉不了）
- **批量入口**（工单 07）：入参 `{ids, company}`，整批必须同属页面选定的那家门店——混入别家门店的 id **整批拒绝**（一次请求里出现两家门店没有正当来由），凭据随门店定。请求级校验（非零售企业 / 门店无凭据位 / 待配凭据）在打开流之前用 400 一次说清，逐条把关仍在 `RetailRetransmit` 里（纵深，不是重复）。**逐条隔离**：某条被拒或已不存在只影响它自己，其余照常补传——批量入口里一条坏单不该让整批停摆；没能进平台的行发一条与真实结果同形状的进度行（原因写在 `response` 里），汇总按**单据**计（单条入口那份按子单计）
- **日志来源值单条与批量共用 `retail_retry`**（与批发链路一致：`tasks_retry` 与 `tasks_batch_retry` 都写 `batch_retry`）：单条/批量是入口差异、不是数据差异；复用则三个页面的来源标签/徽标/下拉不必各加一处，失败记录页那个"补传没成功"的出口也照旧覆盖两种入口
- **链路**：`Enterprise::route()` 给的接口与码上限 → 超限才拆单（沿用 `单号_1` 约定；实测零售单张码数上限 1,718，不触发）→ `RetailRequestAssembler::assemble()` → `ApiClient::execute()`（0.33s 间隔、仅网络错误重试 3 次/30s、业务错误不重试）→ `LogWriter` 写 JSONL + `upload_logs`（`source='retail_retry'`，带 company/credential/task_id）→ 翻 `upload_tasks`：`task_status='已处理'` + `request_status`/`response_status`/`resp`，并把该行 `credential` 覆盖为**这次实际用的那套**（采集预填该门店那套，这里写回的是同一套——单套时代这一写不改变取值，只是把事实记下来）
- **三关 fail-closed 都在第一次平台调用之前**（非零售企业 / 门店无凭据位或未配齐 / 无路由或装配必填项缺失），任一不过即整条拒绝：不发一次调用、不写一条日志、**任务行一个字段都不动**（实测：拒绝后 `updated_at` 不变）。页面上的禁用态（未识别 / 待配凭据）只是显示层提示，真正的关口在链路里——任何直接调端点的路径都拦得住
- **异常时不复位任务状态**（批发链路那两个入口会复位，本入口刻意不照搬）：任务行只在平台调用**之后**被写，故异常要么发生在第一次调用之前（没动过，复位是空操作）、要么发生在某个子单已完成之后（那一写就是本次尝试的真实结果，复位反而把结果抹成 NULL）
- **进度里的"成功"按业务结果算，不照搬 `ApiClient::execute` 的 `success`**：后者是网关级（无 `code` 错误即 true），实测平台对"存在已出售的码"这类业务拒绝也返回 `success=true` + `msg_code=FAIL`，照搬会把真实失败显示成绿色 [成功]、汇总写成"成功 1"。口径与已上传页/失败页一致：`上传成功` 与 `单据重复` 都算成功（单据已在平台上），其余算失败
- **实测（2026-09-30 首次真传，单条两单 + 批量一批四张）**：批量那次是新江分店 4 张 `321`，全部 `上传成功`（4 行 `upload_logs` 带 `source='retail_retry'`/门店/凭据/task_id，4 行任务翻 `已处理` + `上传成功`，该门店待补传 24 → 20，已上传页按门店名可辨认）。**104/203 刻意未真传**：ADR 0010 的 `fromUserId`/`toUserId` 发货收货语义仍待外部系统工程师确认，传错方向会在平台上留下错误申报（装配正确性目前由工单 05 的纯函数断言兜着）。两个 lsyd 接口的响应都是同一套 TOP 信封（`result.msg_code` / `msg_info` / `response_success`），故 `ApiClient::resolveUploadResponseStatus` 两个接口族通用——`SUCCESS`+`response_success=true` → 上传成功；`msg_info` 含"该单据号已存在" → 单据重复；`msg_code=FAIL` → 上传失败。平台对**已被申报过的销售单**返回业务错误「存在已出售的码」（→ 上传失败），即补传不是"重放"而是真实申报
- **失败也算"已处理"**（与批发链路一致）：任务表是待处理队列，`上传失败`/`未确定`/网络请求失败都翻 `已处理`，`待补传` 队列不会无限堆积；"补传没成功"的出口是失败记录页（该页第一条条件 `response_status NOT IN ('上传成功','单据重复')` 挡住 `单据重复` 自身，实测零售的 `上传失败` 记录确实可见）。因此补传失败后要再试，是在任务页按"已处理"筛出该行重传，不是等它回到待补传

### 批量查询上传状态（check_bill_status.php + check_failed_logs.php）

两脚本共用同一套查询/更新语义，仅调度频率不同，各带独立 flock 锁（`logs/check_bill_status.lock`、`logs/check_failed_logs.lock`，`LOCK_EX|LOCK_NB`，锁被占用直接退出防并发）。**两者一律只查批发主体**（`company` 白名单，同 upload_pending 的理由）：它们用河药凭据查平台，拿门店单号去查只会得到"信息不存在"、白烧调用还可能把状态翻错。

**check_bill_status.php（来源 1：等待上传任务，高频）**：查询 `upload_tasks`（task_status='等待上传' **且 company=批发主体**）带 `last_checked_at` 新鲜度门卫（`last_checked_at IS NULL OR last_checked_at <= 阈值`，阈值常量 `CHECK_INTERVAL_MINUTES = 30`）→ 逐个调 `ApiClient::searchBillDetail()`（API 间隔 0.5s）→ 已上传的标记任务已处理 + 写 upload_logs（source=batch_check）+ JSONL → 未上传（信息不存在）的仅更新 `updated_at` → cron: 8-20 点每 30 分钟一次（与门卫阈值一致）。每轮跑不完是可接受状态（只剩一个队列，下一轮续跑即可）。

**check_failed_logs.php（来源 2：失败记录，低频）**：查询 `upload_logs`（response_status IS NULL 或 NOT IN ('上传成功','单据重复') **且 company=批发主体**）带同样门卫 → 按 `djbh` 去重（首次遇到胜出，同单多条失败记录只查一次 API）→ 逐个 `searchBillDetail`：平台存在 → 记录翻转为"上传成功" + 同步关联 upload_tasks（task_id>0 标已处理）+ 写 JSONL；信息不存在 → 仅更新 `updated_at`/`last_checked_at`；API 异常 → 跳过不修改 → cron: 每天 20:40。作用：外部系统补传后失败记录页自动干净（配合 failed.php 的 NOT EXISTS 逻辑）。

循环内"已确认在平台跳过"（SQLite 已有上传成功/单据重复记录，按 `(company, djbh)` 判重）时不调 API：check_bill_status 对任务直接标记"已处理"（任务目标已达成，避免停留在"等待上传"被反复拉取/重传）；check_failed_logs 保留历史记录不动。

**零售记录刻意留在失败记录页**（`api/failed.php` **不加** company 过滤）：零售的 `upload_logs` 只可能由人工补传产生（三个检查脚本写不出零售日志），所以失败页上出现的零售记录必然是人工补传失败——那是操作者唯一能看见"补传没成功"的聚合出口。见 `docs/adr/0007`。页面与导出（`api/export.php` 的 `type=failed`）的判重口径一致，都限定同一企业。

`last_checked_at` 更新规则（两脚本一致）：API 查询成功（含"信息不存在"）和"已确认在平台跳过"（标记任务已处理时）都会 touch；仅 API 异常不 touch，下次 cron 自动重查。新采集/新建任务的 `last_checked_at` 为 NULL，天然立即查。

### cron 时间表（全部检查类脚本错峰，8-20 点窗口只跑 check_bill_status）

| 脚本 | cron | 说明 |
|------|------|------|
| fetch_bills（批发采集） | `0,30 0,1,2,3,8-23 * * *` | 写库与检查脚本的 SQLite 锁冲突由 busyTimeout(30s) 兜底 |
| fetch_bills_retail（零售采集） | `5,35 0,1,2,3,8-23 * * *` | 与 fetch_bills 同频、**错开 5 分钟**（同为写 SQLite 的进程，同刻写会撞上 `Database::__construct` 里 `PRAGMA journal_mode=WAL` 那道无 busyTimeout 的既有竞态窗口 → Web 端 500 "database is locked"）。**不调平台 API，不受 8-20 点限流窗口约束**，故时段照抄 fetch_bills（含 8-20 点） |
| check_bill_status（来源 1） | `*/30 8-20 * * *` | 高频确认新单（与门卫阈值 30 分钟一致） |
| check_failed_logs（来源 2） | `40 20 * * *` | 20:40，fetch_bills 20:30 轮已结束、21:00 轮未到 |
| check_quantity（数量对账） | `10 21 * * *` | **当前未调度**（手动运行）；下表值仅为恢复调度时的建议时间——21:10，fetch_bills 21:00/21:30 两轮之间；数量对比（shl vs min_pkg_count 求和），~650 单约 13 分钟 |
| cleanup_logs | `0 3 * * *` | 三条清理判据不同：日志 3 个月前（`created_at`）、已处理任务 3 个月前（`updated_at`）、**门店超期单据 2 年前（`rq` 单据日期）**——后者是"今天合法的单据两年后就不合法了"的唯一出口，见 `App\RetailRetention` |

**注（2026-09-30 核对 root crontab 现状）**：`fetch_bills`、**`fetch_bills_retail`**（工单 03 交付的条目已人工装上，当日 08:05/08:35/… 的采集记录可见）、`check_bill_status` **都在跑**；`check_quantity` **未调度**、仅手动运行。改动本表前先 `crontab -l` 核对，别照抄文档。

覆盖保证：任何单据最终都会被查到平台状态（等待上传 ≤30 分钟 / 失败记录 ≤24h / SQL Server 全量 ≤24h）。check_quantity 与 check_failed_logs 不得改到 8-20 点窗口内运行（与 check_bill_status 并发调同一 AppKey 立即触发平台限流）。

### 数量对账（check_quantity.php，两级流水线）
定位：外部系统负责上传时，本项目只检查上传情况、不补传。**查询范围仅针对 check_bill_status 已检查过且状态是"上传成功"的批发主体单据**（upload_logs `source='batch_check' AND response_status='上传成功' AND company=批发主体`，按 rq 筛选）——未上传的单据由 check_bill_status 以任务状态（等待上传）反映，数量对账不重复查询/告警；零售没有本地数量基线（`SUM(shl)` 来自 `skwms_new` 明细视图）也不做平台对账，故不参与（见 `docs/adr/0007`）。幂等清理同样限定 company，清理范围与写入范围一致。

**第 1 级（快，全量，SQL Server 聚合）**：逐单依次查询平台原始单号 → `_1` → `_2`...（上限 10 次），跨拆分子单累加平台申报数量（`ApiClient::sumBillDetailCount()`：累加 `min_pkg_count`），与本地应有数量对比（`TaskFetcher::fetchBillQuantitiesByCodes()`：明细视图 `SUM(shl)` 聚合，轻量查询不写库）。**比较口径统一为最小包装单位数**：本地 `shl` 即"已展开的最小包装单位数"（整件行 `shl = baozhshl × jlgg`、零散行 `shl = lingsshl`，见 ADR 0004——推翻早期"两数量纲无法统一"结论）。**基线剔除本地非药品行**（jixing 含商品/食品/消杀/用品/器械/化妆品/消毒剂/敷料/试剂/材料/设备等，spkfk 查不到剂型的行保守保留）——平台是药品追溯平台，外部系统按平台规则不申报非药品。**查询策略"相等即停，不等查尽"**：原始单号查到且数量相等即停（未拆分大头单 1 次调用）；原始单号查不到或数量不等继续查子单，防止"原单号+拆分并存"漏计。**"数量不符"嫌疑单仅收集在内存（不写库）**，其余分支照旧：相等 → 传齐零记录；全序列查不到 → `信息不存在`（防御分支：batch_check 已确认上传成功但平台查不到）；无法核对跳过（不写任何记录）——本地 `SUM(shl)` 为 NULL（明细视图无行）、平台响应解析失败（`sumBillDetailCount` 返回 null），不误报。

**第 2 级（慢，精查，仅嫌疑单，singlerelation 码级口径）**：逐码调 `ApiClient::searchSingleRelation()` 把本地追溯码折算成平台"最小溯源单位"系数（`ApiClient::sumPkgAmount()` 解析，单一事实源），Σ 系数与第 1 级 actual（跨子单 min_pkg_count 累加）同口径对比——**本地零售规格 ≠ 平台注册规格的结构性口径差异（青霉素钠 20 瓶 vs 1 盒、氨咖黄敏胶囊 10粒/盒 vs 500粒/盒等，ADR 0004 判定的"本阶段无更优解"遗留硬伤）在此消除**。**码基线来自 wms_dzjg 现查**（`TaskFetcher::fetchWmsCodesByDjbhList`，2026-08-26 加固——batch_check 快照缺采集后手持扫码补录的大包装箱码（整件只有大码的氯化钠/葡萄糖 40/50/120瓶/箱），实测 25/25 "数量不符"判定全为此类假阳性；check_quantity 21:10 运行晚于发货补录，现查天然规避；现查为空回退快照基线；"数量不符"响应附 `code_source`/`codes_checked`/`base_codes` 便于识别）。**折算规则（2026-08-26 加固）**：`is_smallest="Y"`（该码即平台最小溯源单位）→ 恒取 1、忽略 pkg_amount——反例实测：葡萄糖注射液 120瓶/箱 箱码 is_smallest=Y 但 pkg_amount=120（整件只有大码、内部 120 个最小单位无追溯码，注射液类常见），120 是注册规格非可对账单位数，平台 min_pkg_count 对该码按 1 计，pkg_amount 直取会误判"数量不符"；is_smallest="N"/缺失 → 用 pkg_amount（大包装码=100、中包装码=5/20、最小单位码=1）。核心等式 `Σ singlerelation(本地每个追溯码).折算系数 == min_pkg_count` 2026-08-26 探针实测成立（50/50、240/240 全等），设计见 `.scratch/quantity-check/singlerelation-tier2.md`。判定：**双方案都有差异 → 真问题**，写 `数量不符`（expected=Σ 码级折算，response 存 `{djbh, rq, expected, actual, sub_bills:[{djbh, count}], stopped_early, code_source, codes_checked, base_codes}`）；**单方案有差异（第 2 级相等）→ 规格口径噪声，不写库** → Web 失败记录页零噪声；**码查询失败/无法核对（理论不存在，实测零次）→ 跳过不写库**（Σ 不完整判定不可信，不误报）。**"超过即停"优化**：累计 Σ 一旦 > actual 即确定"本地多于平台"立即停（所有码系数 >0 不可能回落相等；只有相等/偏少才需查完全部码，嫌疑单平均 ~16 码）。第 2 级限速 500ms/次（1 秒 2 次，spec 实测确认；与 searchbill.detail 同 AppKey 限流池）。

**2026-08-15 全量实测**（589 单）：传齐 527 / 第 1 级差异 62 → 码级精查后**真问题 0 / 规格噪声排除 62** / 无法核对 0 / 异常 0（2026-08-27 码基线现查加固后重查结果）。历史结论修正轨迹：旧版快照基线时代实测"真问题 17 / 规格噪声排除 45"，2026-08-26 复核发现 25/25 判定（含 17 条）全为"采集后补录"假阳性（batch_check 快照缺采集后手持补录的大包装箱码，差 1-8 恰等于补录码数）——**差 1-5"平台申报 > 本地码折算"方向的最可能成因是本地码基线缺码而非外部多传**，旧"外部系统多传/混码"方向经验作废；运维看到"数量不符"且 expected < actual 时先核对 wms_dzjg 现查码数（response 的 code_source/codes_checked/base_codes 可辨识），现查折算 == 平台申报即假阳性。

**幂等**：每轮先清理目标日期全部 quantity_check 记录再按新判定写入（限流熔断后下次运行重查不产生重复/残留记录，历史"数量不符"误报随重跑自动清除）。第 1 级 API 间隔 1s；两级任一处平台限流（App Call Limited）时**本轮熔断**，剩余单据下次运行自动重查。**运行时机**：必须避开 check_bill_status（8-20 点每 30 分钟一轮）的调用窗口，否则并发触发平台限流，cron 建议配 21:10 每天一次（详见上方 cron 时间表，**当前未调度**），默认检查昨天（参数可指定日期）。该检查顺带修正 check_bill_status 的盲区：外部系统拆分上传后原始单号查不到被误判"未上传"的场景，数量对账的运行时子单查询能识别子单已传齐。

### 手动上传（Web 端，工单 07 起顶部先选"所属企业"）

页面最上方是**"所属企业"下拉**（选项来自 `App\Enterprise`），选定后显示该企业对应的内容——批发与零售的字段、接口、凭据完全不同，混在一个表单里只会让两边都难读：

- **批发分支**（默认选中批发主体）：即原来的两个卡片，**行为一字未变**——在线新增（单据类型下拉 → 日期/单号/往来单位 → 粘贴追溯码，一行一个自动转逗号）写入 SQLite 后立即上传并实时反馈；xlsx 导入（列: 日期 | 单号 | 单据类型 | 往来单位名称 | 追溯码，同单号多行自动合并为一个任务，取第一个非空的日期/单据类型/往来单位并拼接追溯码）。落库主体取 `Enterprise::wholesaleSubject()`。xlsx 导入与模板下载**只服务批发**，不分叉
- **门店分支**：**不提供从零手工录入**，只列该门店的"待补传"清单（单号 / 单据日期 / 单据类型 / 追溯码 / 码数，**按单据日期倒序**——新的在前，2026-09-30 用户指定；原先是最早在前）→ 勾选（支持全选）→ 批量补传 → NDJSON 流式逐条反馈，传完自动刷新清单（用哪套凭据由服务端按门店取，页面不让人选，见 `docs/adr/0012`）。清单来自 `api/manual_retail_tasks.php`（固定口径：该门店 + `source='retail'` + `task_status='待补传'`），**分页与上传任务页同款**（`page_num` + 每页 20 条，页面渲染同一套 Bootstrap 分页条）——原先的 200 条截断已删：截断把"看不全"推给操作者，分页把它解决掉。清单里**不会有 2 年前的单**（平台不接受，采集与清理两侧都按 `App\RetailRetention` 挡住），所以"最早在前"并不指向一批欠了很久的账——这是倒序后仍要记得的前提。**每行有"查看追溯码"按钮**（与上传任务页同一套弹窗：全量列出 + 复制），故清单接口随列表回 `trace_codes`——一页 20 条最坏约 680KB；比起省这点带宽，改成点击时按 id 另拉一次要在页面上多维护一套加载态与失败态，不划算。**勾选集与页码跨页保持**：翻页丢勾选的话批量补传没法用；被选中的行不一定在当页，故 `104`/`203` 的二次确认按"跨页累积的 id→行"索引回查单据类型（只查当前页会让跨页选中的调拨单逃过点名）。补传刷新后当前页可能已空，此时自动退到最后一页重拉。两端口的禁用情形都写明原因：**待配凭据**（清单可见、补传禁用，属预期内的正常状态）、企业配置载入失败（降级为不可用，但批发表单照常渲染）
- **勾选里含 `104`/`203`（调拨）时，二次确认会额外点名警告**：那两类的 `fromUserId`/`toUserId` 发货/收货语义仍待外部系统工程师确认（ADR 0010），单条入口同样暴露该风险、批量会一次放大成一批。**是警告不是拦阻**——不改变单条入口既有的放行口径
- **`未识别` 不出现在门店分支里**（它不是一个企业）——那些单据在上传任务页标红，由人去查配置/源库

### 日志链
```
码上放心 API 响应
    ↓ 实时写入
  JSONL 文件（永久保存，logs/api_YYYY-MM-DD.jsonl）
    ↓ 同步写入
  SQLite upload_logs（查询用，保留 3 个月）
    ↓ 定时清理（cleanup_logs.php，每天凌晨 3 点）
  删除 3 个月前的 upload_logs 记录，以及 3 个月前已处理（task_status='已处理'）
  的 upload_tasks 任务（按 updated_at 判断，避免误清 rq 很旧但最近才采集/处理的任务；
  历史仍可查 upload_logs 与 JSONL，任务表本质是待处理队列，终态任务无保留价值）
  另：门店（零售）任务按 **rq 单据日期**清超 2 年的（判断的是单据本身多老，不是记录存了
  多久——平台不接受 2 年前的单据，见 App\RetailRetention）
```

## SQLite 本地数据库

文件：`data/msfx.db`，通过 `scripts/init_db.php` 初始化（幂等，可重复执行；两类操作的幂等规则不同：加列/建索引/重建表按当前结构判断，**历史行回填只在加 `company` 列那一刻做一次**——重复回填会把零售的合法取值（`company='未识别'`、`credential` 为 NULL 表示待配凭据）误标成河药）。

### upload_tasks（上传任务）
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER PK | |
| rq | TEXT | 单据日期（来自 SQL Server） |
| djbh | TEXT | 单号（去重键是 `(company, djbh)`，不是裸 `djbh`） |
| ent_name | TEXT | 往来单位名称（**零售不用此列**——对手方 ID 来自源表 `from_user_id`/`to_user_id`，采集时写空串且不查 `ent_list`） |
| trace_codes | TEXT | 追溯码（逗号分隔） |
| task_status | TEXT | 等待上传（批发，cron 会取）/ **待补传**（零售采集落库，仅人工补传——**不复用"等待上传"**，那语义是"cron 会来取走并上传"）/ 已处理 |
| source | TEXT | **retail**（`fetch_bills_retail` 零售采集）/ cron（批发采集）/ manual / batch_check / batch_retry |
| company | TEXT | 所属企业中文全名（页面"所属企业"列的值与筛选键；`未识别` 表示门店认领失败） |
| credential | TEXT | 该企业那套凭据的键（如 `main`，门店与凭据 1:1）；只作审计，不参与任何键；零售待配凭据时为 NULL；零售补传成功后写回**这次实际用的那套**（单套时代即同一取值）；编辑任务把所属企业改成不在配置中的企业时也写 NULL（守卫届时明确拒传，不静默换主体）。**只在企业真的改了时才重设**——页面只在该情形才把 `company` 送上来，改个日期不会把审计值重置 |
| from_user_id | TEXT | 零售专用：源表 `zsm_ls.from_user_id` 照搬（补传装配的 `fromUserId`，仅 104/203 用）。批发行与工单 06 之前采的零售行为空 |
| to_user_id | TEXT | 零售专用：源表 `zsm_ls.to_user_id` 照搬（补传装配的 `toUserId`，仅 104/203 用；321/116 源库本就为空） |
| physic_type | TEXT | 零售专用：源表 `zsm_ls.physic_type`（实测全表恒为 `3`；补传装配的 `physicType`，仅 104/203 用） |
| bill_type | TEXT | 单据类型码（3 位数字，兼容旧字母前缀如 XSO；读取时经 `App\BillType::normalize` 归一化） |
| request_status | TEXT | 请求成功/请求失败 |
| response_status | TEXT | 上传成功/单据重复/上传失败/信息不存在/往来单位缺失/未确定（任务表不产生"数量不符"，该状态仅 quantity_check 写 upload_logs） |
| resp | TEXT | API 返回内容 |
| created_at | TEXT | 任务创建时间（写入 SQLite 的时间） |
| updated_at | TEXT | 最后更新时间 |
| last_checked_at | TEXT | 距上次 check_bill_status 成功查询的时间（新鲜度门卫用，NULL=从未查过） |

### upload_logs（上传日志）
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER PK | |
| task_id | INTEGER | 关联 upload_tasks.id（0 表示无关联） |
| djbh | TEXT | 单号 |
| ent_name | TEXT | 往来单位名称 |
| trace_codes | TEXT | 追溯码 |
| rq | TEXT | 单据日期（回填自 upload_tasks 或 SQL Server） |
| source | TEXT | cron/manual/batch_check/batch_retry/quantity_check/**retail_retry**（零售人工补传——检查脚本一律只查批发主体，写不出零售日志，见 ADR 0007）；**历史行为空串**（`source` 列上线前的旧记录，实测 2,324 行） |
| company | TEXT | 所属企业中文全名（同 upload_tasks） |
| credential | TEXT | 这次实际用了哪套凭据（采集时预填该门店那套；补传写回同一套）；只作审计 |
| request_status | TEXT | 请求成功/请求失败 |
| response_status | TEXT | 上传成功/单据重复/上传失败/信息不存在/往来单位缺失/未确定/数量不符（quantity_check 专用） |
| response | TEXT | API 返回内容 |
| created_at | TEXT | 任务创建时间（API 调用时间） |
| updated_at | TEXT | 最后更新时间 |
| last_checked_at | TEXT | 距上次 check_bill_status 成功查询的时间（新鲜度门卫用，NULL=从未查过） |

### ent_list（往来单位缓存）
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER PK | |
| company | TEXT | 所属企业中文全名 |
| ent_name | TEXT | 企业名称（唯一键是 **`(company, ent_name)`**；同名往来单位跨企业各存一行） |
| ent_id | TEXT | 阿里健康企业 ID |
| ref_ent_id | TEXT | 企业编码 |
| created_at | TEXT | |

> 该表的唯一键在 2026-09-29 由 `ent_name UNIQUE` 重建为 `UNIQUE(company, ent_name)`（SQLite 改不了约束只能建新表搬数据），选择在**只有一家批发企业时**改是因为此时成本最低——第二个批发主体进来再改就要停机洗数据。

## 企业配置与凭据（多企业支持，进行中）

三文件分离，**凭据绝不入 git**：

| 文件 | 内容 | 入 git |
|------|------|--------|
| `config/enterprises.php` | 企业结构：企业名 / 类型（`wholesale`·`retail`）/ 凭据位（`label`；每家恰一套，无 `primary` 标记） | ✅ |
| `config/enterprises.local.php` | 门店平台 ID 列表 + 凭据四字段明文（`appkey`/`secretkey`/`ref_ent_id`/`ent_id`） | ❌（`.gitignore`） |
| `config/enterprises.example.php` | 模板（占位符） | ✅ |

- **凭据是路由主体**，门店与凭据 **1:1**（每家企业恰一套——已确认门店在本项目中都有专门的凭据、不会有第二套，故配置里声明多套直接判为配置错误，页面与端点也不再由人指定用哪套）；**同一张单只传一次**，去重键是 `(company, djbh)`，`credential` 列只记录"这次实际用了哪套"。见 `docs/adr/0006`、`docs/adr/0008`、`docs/adr/0012`
- **门店认领以平台 ID 为主键**：源表 `zsm_ls` 的 `from_user_id`（`321`/`116`）或 `to_user_id`（`104`/`203`）命中该门店登记过的任一 ID 即认领；ID 缺失才回退 `oper_ic_name` 与配置门店名精确匹配；都不命中 → `company='未识别'` 照常入库（页面提示、禁用补传）。依据：`oper_ic_name` 在 321/116 上**全空**（占全表 84.7%）。见 `docs/adr/0008`
- **待配凭据**：门店有名字与平台 ID 但没密钥时，单据照常认领，页面标"待配凭据"并禁用补传（15 家中当前已配 5 家）
- 接口路由 `(企业类型, 单据类型)`：零售 `104`/`203` → `lsyd.uploadinoutbill`（码上限 10000）、`321`/`116` → `lsyd.uploadretail`（3500）；批发一律 kyt `uploadinoutbill`（3500）
- `App\Enterprise` 是唯一入口：`loadFromFiles()` 载入并**强制自检**（平台 ID 不得跨企业重复、凭据的 `ref_ent_id`/`ent_id` 必须属于本企业、**每家企业只允许一套凭据**…），违反即抛异常——这些都是"违反了就会静默把单据传到错误主体"的错误
- **凭据相关的入口**：`credential(企业名, 键)` 按键取（`RetailRequestAssembler` 用它校验凭据归属）、`defaultCredentialKey(企业名)` 返回该企业的凭据键（编辑任务改企业时重设 `credential` 列）、`credentialFor(企业名)` 返回该企业那套凭据（含 `key`，补传链路与端点用；待配凭据返回空凭据而非 null，由 `credentialConfigured()` 判定）、`retailCredentialReady()` 返回 `门店名 => 'ready'|'pending'|'no_slot'`（门店名缺席 = 不在配置中），供两个视图判断"这家能不能补传"——**三态把"待配凭据"（等密钥，正常态）与"没声明凭据位"（配置缺口）分开**，页面不再出凭据位键与 label
- **批发主体入口 `Enterprise::wholesaleSubject()`**：返回 `['key' => 配置key, 'name' => 企业全名, 'credential_key' => 凭据位键]`，批发链路（采集落库 / cron 取数 / 手动上传 / 三个检查脚本）取"本项目的自动上传主体"的唯一入口，免得各脚本各自硬编码企业名与凭据键。**批发企业不是恰好一家时抛异常**而不是静默取第一个——那正是"把单据申报到错误主体"的经典路径。
  - `credential_key` 是**键**（落库到 `upload_tasks.credential` 的值），凭据数组由 `Enterprise::credential(企业名, 键)` 取——两者形状不同，别混用
  - 它与 `scripts/init_db.php` 的迁移回填常量（`BACKFILL_COMPANY` / `BACKFILL_CREDENTIAL`）必须一致，否则历史行会被各处 `company` 白名单静默漏掉；`tests/enterprise_config_test.php` 有断言钉住这个等式，改企业名或换凭据键时两处 + 历史数据要一起动
- **`company`/`credential` 已落库（2026-09-29，工单 02）**：`upload_tasks` / `upload_logs` 两列 + `ent_list` 的 `company` 列与 `UNIQUE(company, ent_name)` 已在生产库完成迁移，历史行回填为河药批发主体。上传侧的 fail-closed 守卫见"核心数据流 → 上传守卫"
- **迁移期**：河药批发凭据仍从 `.env` 读取（`enterprises.local.php` 的 `heyao` 条目引用 `Config::get`，刻意不复制明文以免两份不一致）。待 `ApiClient` 全面改读本配置后，删除 `.env` 的 `APPKEY_HYYY`/`SECRETKEY_HYYY`/`REFENTID_HYYY`/`ENTID_HYYY`

## 环境配置

- **Web 服务器**: Nginx，监听 `192.168.2.189:8188`，root `public/`
- **PHP-FPM**: 池名 `mashangfangxin`，监听 `127.0.0.1:9008`
- **防火墙**: firewalld 需开放 `8188/tcp`（`firewall-cmd --add-port=8188/tcp --permanent`）
- **SELinux**: `data/` 和 `logs/` 需设 `httpd_sys_rw_content_t` 上下文
- **文件权限**: `data/msfx.db` 和 `logs/` 及内容必须属主为 `nginx:nginx`（PHP-FPM 运行用户），否则 Web 端将报 "readonly database" 错误导致空响应

## 关键依赖

- Composer 依赖：`phpoffice/phpspreadsheet`（xlsx 导入/导出）
- 前端 CDN：Bootstrap 5.3.3 + Bootstrap Icons 1.11.3 + flatpickr 4.6.9（日期范围选择器 + 中文 locale）
- `db.php`（不在仓库内，位于 Web PHP include_path），提供 `info_log()`、`hht()` 等函数
  - CLI 环境下 `db.php` 不可用，CLI 脚本内部定义了 `info_log()` 桩函数输出到 stderr
- `src/SqlSrvHelper.php` 通过 composer `classmap` 自动加载（非 namespace 类）
- PHP 扩展：`sqlsrv`（SQL Server）、`curl`、`sqlite3`
- 运行环境：PHP 8.1 + Nginx + SQL Server

## 常用命令

```bash
# 采集当天单据到上传队列
php /usr/share/nginx/mashangfangxin/scripts/fetch_bills.php

# 采集指定日期的单据
php /usr/share/nginx/mashangfangxin/scripts/fetch_bills.php 2026-07-28

# 采集零售门店单据（dyt 链接服务器；只读源库、不调平台接口，随时可跑）
# 落库 task_status='待补传' / source='retail'，需要 nginx 或跑完 chown（同 init_db 的属主注意事项）
# ⚠️ 2026-09-30 起为测试阶段临时口径：单条 SQL（LEFT JOIN + NOT EXISTS(update_state)，
#    只采外部系统尚未上传的单）
# 2 年下限（平台硬性规定，App\RetailRetention）：超期日期会被**拒绝并退出 1**；
#    --all 也只采最近 2 年——平台不接受 2 年前的单据，采进来也补传不出去
php /usr/share/nginx/mashangfangxin/scripts/fetch_bills_retail.php             # 当天（cron 的口径）
php /usr/share/nginx/mashangfangxin/scripts/fetch_bills_retail.php 2026-09-28  # 指定日期
# 一次性全量快照：不加等值日期条件，把外部系统尚未上传的历史单入库（**下限 2 年**）
# ⚠️ 跑一次即可、别挂进 cron；建议错开 :00/:30（那是 fetch_bills 的写库窗口）
php /usr/share/nginx/mashangfangxin/scripts/fetch_bills_retail.php --all

# 批量上传队列中等待上传的任务（只取批发主体的记录：task_status='等待上传' AND company=批发主体）
# 零售单据是 task_status='待补传'，本脚本不取；即便状态被误改，UploadService 的守卫也会拒传
php /usr/share/nginx/mashangfangxin/scripts/upload_pending.php

# 批量查询单据上传状态（来源 1：等待上传任务；新鲜度门卫：距上次查询不足 30 分钟的单据自动跳过）
# 注：日期参数仅打印在日志中，查询范围不受日期限制（按门卫规则扫描全部待查单据）；只查批发主体
php /usr/share/nginx/mashangfangxin/scripts/check_bill_status.php

# 复查失败记录（来源 2：upload_logs 未上传成功记录；每天 20:40 由 cron 调用，错峰避开 check_bill_status）
php /usr/share/nginx/mashangfangxin/scripts/check_failed_logs.php

# 清理 SQLite 历史数据（三条判据：日志按 created_at 清 3 个月前、已处理任务按 updated_at 清
# 3 个月前、门店任务按 rq 单据日期清 2 年前——最后一条是平台硬性规定，见 App\RetailRetention）
php /usr/share/nginx/mashangfangxin/scripts/cleanup_logs.php

# 回填 upload_logs 的单据日期（首次部署后执行一次即可）
php /usr/share/nginx/mashangfangxin/scripts/backfill_rq.php

# 初始化/迁移 SQLite 数据库（幂等，可重复执行；含 company/credential 列、历史回填、ent_list 唯一键重建、
# upload_tasks 的 from_user_id/to_user_id/physic_type 三列——这三列**没有历史回填**，
# 缺列的零售旧行只能删掉重采（见"核心数据流 → 零售单据采集"））
# 生产库上跑注意属主：以 nginx 用户执行（su -s /bin/bash nginx -c "php ..."），
# 或用 root 跑完 chown nginx:nginx data/msfx.db*——否则 php-fpm 会报 readonly database
php /usr/share/nginx/mashangfangxin/scripts/init_db.php

# 直接传 SQL 查询/操作 SQLite（调试工具，可传多条，无参数时列出表及行数）
php /usr/share/nginx/mashangfangxin/scripts/sqlite_query.php "SELECT * FROM upload_tasks ORDER BY id DESC LIMIT 10"
php /usr/share/nginx/mashangfangxin/scripts/sqlite_query.php "UPDATE upload_tasks SET task_status='已处理' WHERE id=1"

# 数量对账（两级流水线）：核对指定日期单据申报数量是否传齐（默认昨天）
# 第 1 级 shl 粗筛嫌疑单 → 第 2 级 singlerelation 码级精查（码基线 wms_dzjg 现查替代
# batch_check 快照——规避采集后手持补录大包装箱码的假阳性，见 src/TaskFetcher.php
# fetchWmsCodesByDjbhList；折算系数: is_smallest=Y→1 忽略 pkg_amount，N/缺失→pkg_amount，
# 见 ApiClient::sumPkgAmount），双方案都有差异才写"数量不符"，规格口径噪声不写库
# （2026-08-15 重查：62 嫌疑全部排除，真问题 0——旧"17 真问题"结论系基线缺码假阳性）
# 注意: 只能在 20:00 后运行（避开 check_bill_status 8-20 点的调用窗口，否则并发触发平台限流；
# 当前 cron 未调度，仅手动运行；恢复调度时按上方 cron 时间表配 21:10）
php /usr/share/nginx/mashangfangxin/scripts/check_quantity.php
php /usr/share/nginx/mashangfangxin/scripts/check_quantity.php 2026-08-16

# 运行单元测试
php /usr/share/nginx/mashangfangxin/tests/trace_splitter_test.php
php /usr/share/nginx/mashangfangxin/tests/quantity_check_test.php
php /usr/share/nginx/mashangfangxin/tests/enterprise_config_test.php
php /usr/share/nginx/mashangfangxin/tests/retail_upload_test.php
php /usr/share/nginx/mashangfangxin/tests/retail_retention_test.php

# 查询单号在码上放心平台的上传状态（searchbill.detail；输出 JSON + 另存 tests/searchbill_<单号>.json）
php /usr/share/nginx/mashangfangxin/tests/search_bill_test.php XSOWMS00997501

# 码级对账探针：逐码调 singlerelation 验证 Σ 折算系数 == searchbill.detail min_pkg_count
#（折算规则 is_smallest=Y→1 忽略 pkg_amount，2026-08-26 加固；核心等式实测成立；
#  设计见 .scratch/quantity-check/singlerelation-tier2.md；避开 8-20 点窗口运行）
php /usr/share/nginx/mashangfangxin/tests/singlerelation_test.php XSOWMS00997406

# 网页访问（需要登录，密码见 .env ADMIN_PASSWORD）
http://192.168.2.189:8188
```

## 业务编码映射

- 单据类型（入库 1xx）：`102`=采购入库, `103`=退货入库, `104`=调拨入库, `107`=供应入库, `108`=召回入库, `110`=赠品入库, `111`=盘盈入库, `112`=报废入库, `113`=其他入库
- 单据类型（出库 2xx）：`201`=销售出库, `202`=退货出库, `203`=调拨出库, `204`=返工出库, `205`=销毁出库, `206`=抽检出库, `207`=直调出库, `209`=供应出库, `211`=召回出库, `212`=赠品出库, `214`=盘亏出库, `215`=损坏出库, `216`=报废出库, `217`=其他出库, `237`=直调退货
- 单据号前缀与类型映射（cron 使用，兼容旧格式）：`XSO`→201, `XST`→103, `JHG`→102, `JHO`→202
- UploadService 支持直接传 3 位数字类型码，也兼容旧的字母前缀（自动查 `$billTypeMap`）
- 药品类型：`3`=普药（非89开头追溯码）, `2`=特药（89开头追溯码）
- 客户端类型：上传接口必须填 `"2"`
- 追溯码拆分阈值：单次最多 3500 个，超出自动拆分为 `单号_1, 单号_2...`
- API 重试：最多 3 次，间隔 30s，仅网络超时重试，业务错误不重试
- API 限速：每次调用间隔 330ms（usleep(330000)）

## Agent skills

### Issue tracker

本地 markdown 文件，存储在 `.scratch/<feature-slug>/` 下。详见 `docs/agents/issue-tracker.md`。

### Triage labels

使用默认五个标准 triage 标签。详见 `docs/agents/triage-labels.md`。

### Domain docs

单上下文布局：根目录 `CONTEXT.md` + `docs/adr/`。详见 `docs/agents/domain.md`。
