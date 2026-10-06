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
│   ├── Config.php                # .env 配置加载；sqlServer() —— SQL Server 连接五字段（server/port/database/
│   │                             #   username/password）的**唯一来源**，四个连接点（TaskFetcher / UpdateStateWriter /
│   │                             #   fetch_bills_retail / backfill_rq）都调它；**不含 timeout**（那是连接点的属性：
│   │                             #   回写就地叠加 5s、其余用 SqlSrvHelper 默认 30s）；方法内确保已 load（幂等），
│   │                             #   调用点忘了先 load 不会静默用兜底默认值
│   ├── Database.php              # SQLite 数据库封装（单例）；transaction() 把一个写批次包进一次
│   │                             #   BEGIN IMMEDIATE/COMMIT——SQLite 每条语句一个隐式事务 = 一次 fsync
│   │                             #   （本机实测 21–28 ms/条），零售快照那 47,813 条记录按"一条一提交"
│   │                             #   要等 25–30 分钟磁盘，攒批后整轮写库 ~20 秒（票 05）
│   ├── Auth.php                  # 单用户 session 认证
│   ├── Enterprise.php            # 企业/门店配置解析、门店认领（平台 ID 优先）、接口路由与码上限、配置自检、批发主体入口（wholesaleSubject）、某企业的凭据键（defaultCredentialKey，编辑任务改企业时用）、某企业那套凭据（credentialFor，门店与凭据 1:1）、全部企业的凭据就绪态（credentialReady：企业名 => ready/pending/no_slot，**三态判据的唯一一处**）、补传可用性摘要（retailCredentialReady＝前者按零售过滤）、全站 6 处"所属企业"下拉的选项（selectableOptions：企业名 => 就绪态，另含 `未识别` => unidentified）
│   ├── BillType.php              # 单据类型码归一化（字母前缀 ↔ 3 位数字码）
│   ├── ApiClient.php             # 封装 TopClient（上传/查询/搜索/singlerelation 码级折算、上传响应状态解析 resolveUploadResponseStatus——批发与零售补传共用，平台响应怎么读只在这一个文件里回答；**顶层错误码可重试判定 isRetryableTopError**——code=7/App Call Limited 即平台限流，判 is_network_error=true 走重试通道）；forCredential 按一套凭据造客户端（内部只挑 appkey/secretkey 两个字段）；queryEntInfo(名称, ref_ent_id) —— 查往来单位，**ref_ent_id 必传**（申报主体自己那套，没有默认值）；queryUpbillDetail(单号, ref_ent_id) —— 查零售单在不在平台上（**零售平台核查专用**，ref_ent_id 同样必传；判据复用 isBillFound，两个接口的响应形状 2026-10-05 实测同款）；**文件顶部把 TOP_SDK_WORK_DIR 钉在项目根**（在 require TopSdk 之前；SDK 日志因此落 logs/top_*.log，见下方 TopLogger 坑）
│   ├── TaskFetcher.php           # 从 SQL Server 拉取/统计待上传单据（含 fetch_bills 门卫计数、fetchBillQuantities 数量基线聚合、fetchWmsCodesByDjbhList 第 2 级码基线现查）
│   ├── UploadService.php         # 核心上传逻辑（cron 和 Web 共用）；上传前 fail-closed 校验任务所属企业与凭据，非批发 kyt 一律拒传；往来单位解析委托 EntDirectory
│   ├── EntDirectory.php          # 往来单位名录：人填的名称 → 平台认的 ent_id（ent_list 缓存按 (company, ent_name) 隔离 → 未命中才调平台、查到才回写）；批发链路与门店手工建单共用一份
│   ├── RetailRequestAssembler.php # 零售补传的请求装配（纯函数：不发起平台调用、不读数据库、不写日志）：请求类与追溯码上限取自 Enterprise::route()，refUserId 取凭据 ref_ent_id，装配完调 SDK 的 check() fail-closed；调用方是 App\RetailRetransmit；**自己 require TopSdk**，故顶部也定义了 TOP_SDK_WORK_DIR（与 ApiClient 同款、幂等——谁先加载谁定，见下方 TopLogger 坑）
│   ├── RetailRetransmit.php      # 零售单据上传的完整流程（三关 fail-closed → 拆单 → 调用 → 写日志 → 翻任务状态 → **全部子单成功后回写源库 update_state**）：单条补传（tasks_retry_retail）、批量重传里的零售那批（tasks_batch_retry 逐条调它，日志来源 retail_retry）、门店手工建单（App\RetailManualEntry，日志来源 manual）三处共用；装配仍走 RetailRequestAssembler；**限速 400ms/次**（`API_INTERVAL_US`，2026-10-02 由 330ms 放宽——330ms 的理论速率 3.03 单/秒正压在平台"1 秒 3 单"的限流边界上，见"业务编码映射 → API 限速"）
│   ├── RetailBatchUpload.php     # 零售门店单据的**批量上传**（票 06；能力已备、暂不启用）：取数口径 `PENDING_SQL`（门店来源 + 等待上传；**刻意不把 `未识别` 筛进 SQL**——脚本末尾那句"跳过非门店企业（含未识别）N 条"要靠它数出来）+ 主循环 `run($tasks, $upload, $dryRun, $limit, $report)`（**先跳过非门店、后限量**，未识别因此不占 `--limit` 额度；逐条 try/catch 隔离；**`--dry-run` 时 `$upload` 一次都不调**——主循环抽成收 callable 正是为了这条能自包含地断言）。上传逻辑一行不重写：逐条交给 RetailRetransmit；脚本侧另有 `--company=k1,k2`（按企业 key 过滤**取回之后**的队列——`PENDING_SQL` 这个取数口径本身不变）
│   ├── UpdateStateWriter.php     # 回写零售源库状态表（告诉外部系统"这单传过了"）：幂等靠 SQL 自身（`INSERT ... WHERE NOT EXISTS`——该表无主键、无唯一约束），写失败只记 JSONL 警告、不影响上传结果；**绝不能包本地事务**（写链接服务器起不了分布式事务，MSDTC 被禁），见 docs/adr/0016；表名取自 RetailExternalUploads::TABLE（**写侧不再自己写一遍表名**）。**2026-10-05 起它是那张表在本项目里唯一的活跃用途**——读侧（采集分流、状态闭环、门卫的两个数）已随 ADR 0018 停用，回写保留（它服务的是外部系统，不是本地判据）
│   ├── RetailExternalUploads.php # 门店单的落库判定与「外部上传」记录形状——**判据统一在平台核查**（2026-10-05 起，
│   │                             #   见 docs/adr/0018）：落库判定 `decide(hasTask, hasSuccess, buildTasks = true)`
│   │                             #   （本地已有任务行或成功记录 → 跳过；否则建任务；**`buildTasks: false` 是
│   │                             #   `--all` 的只统计口径**，本地无痕也不建任务 → `ACTION_COUNT_ONLY`。
│   │                             #   判定里**没有"源库说传没传"这一维**——那半分流随 ADR 0018 撤掉，
│   │                             #   `ACTION_RECORD` 常量与 `record()` 一并删除，测试有用例钉着）、
│   │                             #   记录形状 `buildRecord(单据, reason, judged_by)`（source=retail_external、
│   │                             #   response_status=上传成功、task_id=0、request_status 留 NULL、response 写出处
│   │                             #   JSON；**后两个参数没有默认值**——判据出处是事实，得由调用方说清楚）、
│   │                             #   统计累加 `tally()`（动作 → 计数；**只有 TASK 的码数进 M**，未知动作抛异常）、
│   │                             #   `TABLE` 常量（源库状态表名，全仓唯一一处硬编码；**读侧停了、写侧仍在**：
│   │                             #   回写 + 停接的 closeLoop 用它）。
│   │                             #   翻正那一套（平台核查走的就是它）：纯函数 `closureActions()`（待办 × 判据 →
│   │                             #   翻任务/追加记录，**判据按 (company, djbh)**，调用方按企业逐个喂）＋
│   │                             #   `closeLoop()`（**2026-10-05 起暂时不接线**，方法与 docblock 都留着，
│   │                             #   恢复路径见 ADR 0018）。
│   │                             #   取数与落库的两段（平台核查与停接的闭环共用一份）：`pendingItems(?int $freshnessMinutes = null)`
│   │                             #   （本地待办清单：等待上传的门店任务 + 零售企业的非成功记录——"哪些单还算待办"
│   │                             #   全仓只此一处；非 null 时 SQL 里只取 `last_checked_at` 为空或过期的行，
│   │                             #   **平台核查传 30**）、`pendingCount()`（清单条数）、`successKeys()`（本地已有
│   │                             #   成功记录，按 (company, djbh)）、`applyActions(动作, 待办, 出处)`（翻任务行 /
│   │                             #   追加记录 / 记 JSONL，**出处信息参数化**——reason/checker/log_type/judged_by
│   │                             #   由调用方给）、**`touchChecked(键)`**（票 02：平台核查把"给过答复的键"在两张表
│   │                             #   批量刷 `last_checked_at`——按企业分块 `IN`、**整批一次事务**，逐条写要等
│   │                             #   1,400 次 fsync；键必须带企业维度，裸单号跨门店会串）+
│   │                             #   **`touchStatements(键, 时间)`**（上面那件事的语句形状，**纯函数**——分块与
│   │                             #   归属因此能离线断言，见 `retail_external_uploads_test.php` 用例 10）
│   ├── RetailPlatformCheck.php   # 门店单据在**平台上**再核查一次（2026-10-05 新建；`lsyd.query.upbilldetail`）：
│   │                             #   拿各门店自己的凭据问平台——**2026-10-05 起它是门店单「是否已上传」的
│   │                             #   唯一判据**（源库状态表那两条读侧判据随 ADR 0018 停用）。
│   │                             #   相对那张表的增益是**企业隔离**（带 ref_ent_id，甲店查到的单号
│   │                             #   不会翻乙店的同名待办；状态表没有企业列，做不到）。三处刻意：
│   │                             #   ① `groupByCompany()` **整组跳过**取不到凭据的门店（未识别 / 待配凭据 /
│   │                             #      不在配置中）与混进来的批发主体——没有 ref_ent_id 这条判据不成立，
│   │                             #      拿错凭据查是白烧调用；② `actionsByCompany()` **按企业分别调**
│   │                             #      `closureActions()`（一次喂全量集合就是跨门店串号）；
│   │                             #   ③ `run()` 收 callable（`--dry-run` 一次都不调它、测试注入假回调即可
│   │                             #      断言，`$intervalUs` 参数化同理——测试传 0 不必等）。
│   │                             #   查询异常只跳过不修改（"不知道"不等于"没上传"）；判定与落库
│   │                             #   一行不重写，全在 `RetailExternalUploads`。
│   │                             #   **票 02 加 `checked_by_company`**：本轮**平台给过答复**的键
│   │                             #   （已上传 ＋ 未上传都算；`error` 的与没轮到的**都不进**）——
│   │                             #   它就是门卫的记账范围。门卫过滤与 touch 都**不在本类**
│   │                             #   （取数侧 `pendingItems(30)`、落库侧 `touchChecked()`），
│   │                             #   `run()` 才保得住"只查不写"、离线测试才钉得住 dry-run
│   ├── RetailManualEntry.php     # 门店手工建单（在线新增 + xlsx 导入共用的唯一实现）：prepare() 校验/取凭据/查对手方（唯一一次平台往返，失败即拒建单）→ create() 落库 + 交 RetailRetransmit 上传；needsCounterparty/endpoints 是「哪两类要往来单位」「对手方落 from 还是 to」的纯规则，见 docs/adr/0015
│   ├── BillSheetParser.php       # xlsx 导入表的解析：读表 → 按单号分组成「一单一条」（同单号多行合并、一行一个码也认）；批发与门店两个导入端点共用，只管「读成什么」、不管「合不合法」
│   ├── RetailRetention.php       # 门店数据保留期（平台硬性规定 2 年，不接受 2 年前的单据）：YEARS + cutoffDate() 是采集下限与清理下限的**唯一来源**；两个调用点必须共用，各写各的会让超期数据滞留
│   ├── RetailCollectionGate.php  # 零售采集的**计数门卫**（票 04，2026-10-02；**2026-10-05 起只数一个数**）：
│   │                             #   **当日单据总数**（一条只读 SQL，`zsm_ls` 去重成派生表 + count()——
│   │                             #   不去重数出来的是行数，实测 111 行 vs 55 张单）与基线比对，没变就跳过
│   │                             #   整轮采集并打印原因；`guard()` 把「基线只在整轮采集成功后写」变成构造上的
│   │                             #   性质（采集从闭包注入，抛异常则基线不写）；计数失败**照常采集**
│   │                             #   （与批发相反——门卫跳过的是一整轮，方向必须朝"宁可多跑一轮"）。
│   │                             #   **原先那两个数（已上传/未上传）读源库状态表，本次去掉**：外部系统写得乱时
│   │                             #   它们一直在变，门卫会一直判"有变化"而丢掉省轮次的作用（ADR 0018）。
│   │                             #   基线 data/fetch_bill_counter_retail.json（形状 `{date,total}`；**带旧键
│   │                             #   uploaded/unuploaded 的旧版基线一律判非法**，见 read()）；`--all` 绕过
│   ├── TraceSplitter.php         # 追溯码两种拆法：splitByCount 按码数拆单（上传用，上限取自 Enterprise::route()，批发 3500 / 零售 10000·3500）、splitByCharLimit 按字符数拆行（导出用，每行 ≤32000 字符）；countCodes 数码——页面"码数"列、追溯码弹窗、导出共用这一个口径（空串算 0）；normalizeInput 把人粘的一串码（一行一个）归一成逗号分隔，两条建单路径与 xlsx 解析共用
│   ├── RecordQuery.php           # 数据页筛选条件单一事实源（build(类型, 参数) → WHERE/SELECT/ORDER/params，三类：tasks/uploaded/failed）：列表 API 与导出**共用同一段代码**，「导出的行数与页面一致」是构造上的性质。曾经四处各写一份，export 的失败分支因此漏过 quantity_check 豁免（第 4 类 retail_tasks 随门店补传清单撤销，见 docs/adr/0015）
│   ├── LogSource.php             # 「来源」词表（upload_tasks/upload_logs 的 source 列）——中文标签与徽标色的
│   │                             #   **唯一事实源**：labels()/badges() 供页面 JS map 与筛选下拉、label() 供导出与
│   │                             #   任务页按值取；两个日志页的下拉直接 foreach labels() 渲染（天然覆盖全部取值），
│   │                             #   任务页下拉另写一份五个值的名单（含两个来自日志表的取值，见下方「来源」那条）、
│   │                             #   成员名单留在视图里。收口前三份 JS map
│   │                             #   各写各的且键集互不相同，quantity_check 三份都没有——数量对账告警因此在失败
│   │                             #   记录页直出机器值、按来源也筛不出来（见 .scratch/retail-collection-split/）
│   ├── LogWriter.php             # JSONL + SQLite 双写日志
│   ├── SqlSrvHelper.php          # SQL Server 数据库操作封装（根命名空间，classmap 加载；queryEach 为逐行消费大结果集的
│   │                             #  回调式接口，供"结果集可能有数十万行、不能攒进内存"的场景用）
│   ├── LockManager.php           # 未使用（预留）
│   ├── Logger.php                # 未使用（预留）
│   ├── api/                      # AJAX API 端点
│   │   ├── tasks.php             # 上传任务 CRUD（GET 列表/单条, PUT 编辑, DELETE 删除）；PUT 改 company 时连带把 credential 重设为该企业的凭据键（只改企业不改凭据，守卫会以"取不到可用凭据"拒传——未识别行本就是 NULL）
│   │   ├── tasks_retry.php       # 单条重传（批发 kyt）
│   │   ├── tasks_retry_retail.php # 零售门店单据补传（人工逐条；凭据不由入参给，服务端按门店取）：只做请求解析与流式输出，流程在 App\RetailRetransmit
│   │   ├── tasks_batch_delete.php # 批量删除上传任务
│   │   ├── tasks_batch_retry.php  # 批量重传（工单 15 起**按行分流**：source='retail' 的逐条走
│   │   │                          #   App\RetailRetransmit，其余原样走 UploadService；混批不再被守卫整批拒绝）
│   │   ├── uploaded.php          # 已上传记录列表（筛选条件构造在 App\RecordQuery，与另两页及导出共用一份）
│   │   ├── failed.php            # 失败记录列表（排除**同一企业内**该单号已有上传成功/单据重复记录的日志行——去重键是 (company, djbh)，裸 djbh 会让零售失败记录被同号批发成功单顶掉；quantity_check 来源记录豁免——数量对账仅查已上传成功单，若不豁免会被 NOT EXISTS 全隐藏，告警出口失效；来源列与来源下拉取自 `App\LogSource`，`quantity_check` 显示「数量对账」、也能按它筛——此前"直出机器值、筛不出来"的已知缺口 2026-10-02 已消）
│   │   ├── logs_delete.php       # 删除单条日志记录
│   │   ├── logs_batch_delete.php # 批量删除日志记录
│   │   ├── manual_create.php     # 手动创建任务并立即上传（批发主体，服务端取 wholesaleSubject）
│   │   ├── manual_import.php     # xlsx 导入批量创建并上传（只服务批发）
│   │   ├── manual_create_retail.php # 门店手工新增单条并立即上传：prepare() 失败直接 400（库里不留半条），
│   │   │                           #   成功才开流；落库主体是入参 company（必须零售企业），凭据由服务端按门店取
│   │   ├── manual_import_retail.php # 门店 xlsx 导入：与批发同一套列（解析共用 App\BillSheetParser），
│   │   │                           #   差别只在口径——类型限四种、往来单位仅 104/203 必填、逐条隔离
│   │   ├── template_download.php # 下载 xlsx 导入模板（?type=retail 给门店版：示例行换门店类型、表头注明 321/116 留空）
│   │   └── export.php            # 按当前筛选条件导出 xlsx（流式生成，内存 O(1)）；三类 type：tasks/uploaded/failed
│   └── views/                    # 页面视图（PHP 模板）
│       ├── layout.php            # 全局布局（左侧菜单 + 顶栏）+ companyOptionAttrs()：全站"所属企业"
│       │                         #   下拉选项的样式规则（凭据填齐 → 加粗黑字；未配齐 → 正常字体灰字），6 处共用这一份
│       │                         #   + .cell-longtext：三数据页"所属企业/往来单位"两列的呈现
│       │                         #   （字号略小 + 允许换行，列宽上限仍由 td 的内联 max-width 兜着）
│       ├── login.php             # 登录页
│       ├── dashboard.php         # 首页仪表盘（4 个统计卡片）
│       ├── upload_tasks.php      # 上传任务管理页（表格 + CRUD + 批量操作）
│       ├── uploaded.php          # 已上传记录页
│       ├── failed.php            # 失败记录页
│       └── manual_upload.php     # 手动上传（顶部先选"所属企业"：两个分支**同构**，都是在线新增 + xlsx 导入；
│                                 #   门店分支的差别只有——类型限门店那四种、321/116 不显示"往来单位名称"、
│                                 #   落库主体是所选门店。补传清单已撤（与上传任务页重复，见 docs/adr/0015））
├── config/
│   ├── .env                      # 数据库连接 + API 凭证（迁移期）+ 管理员密码
│   ├── enterprises.php           # 企业结构：企业名/类型(wholesale·retail)/凭据位(label) —— 入 git，无凭据
│   │                             #   （门店与凭据已定为 1:1，结构里的 `primary` 标记已删除，见 docs/adr/0012）
│   ├── enterprises.example.php   # enterprises.local.php 的模板（占位符）—— 入 git
│   ├── enterprises.local.php     # 门店平台 ID + 凭据四字段明文 —— **不入 git**（.gitignore）
│   └── sql.php                   # SQL Server 原始查询（**调试残留，口径以脚本为准**；批发采集口径含 a.is_zx='是' 已执行单据过滤，2026-08-27；
│                                 #  零售 `$get_up_task_retail` 已不适用——写死单一 bill_type='203'、类型不全，现行口径见
│                                 #  scripts/fetch_bills_retail.php；它那句 NOT EXISTS(update_state) 的来龙去脉：ADR 0007 曾判为
│                                 #  "去掉"（2026-09-29）→ 测试阶段临时加回（2026-09-30）→ 2026-10-02 票 02 改成分流 →
│                                 #  **2026-10-05 起连分流也停了**（采集整个不读那张表，见 ADR 0018）——
│                                 #  **别照抄本文件，以脚本为准**）
├── public/
│   ├── index.php                 # Web 单入口（page 参数分发路由）
│   ├── favicon.svg               # SVG 网站图标
│   └── assets/                   # 前端资源**本地自托管**（无外网依赖）：css/（Bootstrap 5.3.3、
│                                 #   Bootstrap Icons 1.11.3、flatpickr 4.6.9）+ js/（含 flatpickr 中文 locale
│                                 #   zh.js）+ fonts/（bootstrap-icons.woff/woff2），由 layout.php 按 /assets/… 引用
├── scripts/
│   ├── fetch_bills.php           # cron 从 SQL Server 采集**批发**单据写入上传任务表
│   ├── fetch_bills_retail.php    # cron 从 dyt 链接服务器采集**零售门店**单据（**2026-10-05 起不分流、不读源库
│   │                             #  状态表**，见 docs/adr/0018：单据一律建「等待上传」任务、由人在页面上补传，
│   │                             #  是否已上传交给平台核查判定——信那张表会让单据**静默消失**）；
│   │                             #  单条 SQL：zsm_ls LEFT JOIN zsm_ls_code（原本还带一个
│   │                             #  `case when exists(状态表)` 标志列，本次去掉）；判定在
│   │                             #  App\RetailExternalUploads::decide（本地已有任务行/成功记录 → 跳过）；
│   │                             #  **计数门卫**（票 04；本次退成一个数）：当日总数与基线一致就跳过整轮并打印
│   │                             #  原因，计数失败视为无基线照常采；判定与基线读写见 App\RetailCollectionGate；
│   │                             #  **状态闭环已停接**（票 03 的那次调用，2026-10-05 起空着——它读同一张表）；
│   │                             #  默认当日；`--all` 为两年窗口的**只统计侦察**（下限 2 年，一个字节都不写）：
│   │                             #  跑完打印"窗口内 N 单、本地已有痕迹 M 单、其余 K 单未建任务"——欠账是
│   │                             #  可查的数；`--dry-run` 预演只统计不落库、**不经过门卫**（票 05；门卫的产物是
│   │                             #  基线，预演写了它下一轮 cron 会少采一轮）；
│   │                             #  **逐行消费**（票 05）：SQL 加 `order by ls.bill_code`，PHP 侧按键切换攒批
│   │                             #  （每批 500 张）落库——内存与窗口规模无关（实测 6–12 MB；旧写法同数据
│   │                             #  实测 84.5 MB，跑得过但余量只剩三成），且每批一次事务（那一条才是硬需求：
│   │                             #  一条一提交 = 一条一次 fsync，4.8 万条记录要写 25–30 分钟）；
│   │                             #  认领走 Enterprise::claim；落库 source=retail / task_status=等待上传——
│   │                             #  与批发**共用一个状态值**（2026-10-01 统一，见 docs/adr/0014；拦住门店单
│   │                             #  不被 cron 取走的是 company 白名单，不是状态值））
│   ├── upload_pending_retail.php # 【**能力已备、暂不启用**，票 06】把「等待上传」的门店单逐条交给现有补传实现
│   │                             #  （App\RetailRetransmit）：`--dry-run` 只列不传（单号/门店/码数）、`--limit=N` 限量；
│   │                             #  空队列**不取锁**秒退，真跑取 logs/upload_pending_retail.lock、逐条隔离（被三关拒的算失败继续）。
│   │                             #  **不进 crontab**——挂上去等于让不可逆的申报在人看不见的时候发出去（见 docs/adr/0011 补注）；
│   │                             #  首次交付一次真申报都没发，验证走项目副本 + 本地离线桩（见票 06 的交付记录）
│   ├── upload_pending.php        # 批量上传队列中等待中的任务（只取批发主体的"等待上传"）；**crontab 里那三条条目当前全部注释着**
│   │                             #  （注释写的是"暂不启用"）——眼下只能手动跑，别照文档以为它在定时跑
│   ├── check_bill_status.php     # 批量查询单据上传状态（来源 1：等待上传任务，高频 8-20 点）
│   ├── check_failed_logs.php     # 复查失败记录（来源 2：upload_logs 未上传成功记录，每天 20:40）
│   ├── check_bill_status_retail.php # 门店单据在平台上再核查一次（2026-10-05 建；**票 02 起已挂 crontab**：
│   │                             #   `20,50 8-21 * * *`，只跑白天）：拿各门店自己的凭据调
│   │                             #   `lsyd.query.upbilldetail` 逐条问平台"这单在不在"，在的翻正
│   │                             #   （任务行翻「已处理」+「上传成功」、失败记录追加一条「外部上传」）。
│   │                             #   两个来源合一（等待上传的门店任务 + 零售企业的补传失败记录）——
│   │                             #   都要按门店分组取凭据、都要限速，拆两个脚本只会重复这套逻辑。
│   │                             #   判定与落库复用 `closureActions()` + `applyActions()`（状态闭环停接后
│   │                             #   仍归它们俩管）；`--dry-run` 只列不查、`--limit=N`
│   │                             #   小步走、`--company=名,名` 限定门店（取回后过滤）。flock 防并发。
│   │                             #   **2026-10-05 起它是门店单「是否已上传」的唯一判据**（ADR 0018，采集不再
│   │                             #   读源库状态表）——取不到凭据的门店（9 家待配 + `未识别`）它整组跳过，
│   │                             #   那批单因此一直挂在「等待上传」（2,376 条，是明知故犯的代价）
│   │                             #   **逐单新鲜度门卫**（30 分钟，与批发同值）：`pendingItems(30)` 下到 SQL；
│   │                             #   被挡下多少条打印在开头。**touch 批量写**（`touchChecked()`，一次事务）：
│   │                             #   平台给了答复的键两张表一起刷 `last_checked_at`，查异常的**不刷**
│   │                             #   （"不知道"不算查过）
│   ├── check_quantity.php        # 数量对账两级流水线（第 1 级 shl 粗筛嫌疑单 → 第 2 级 singlerelation 码级精查，双差异才写"数量不符"）
│   ├── cleanup_logs.php          # 清理 SQLite 历史数据，三条判据各不相同：日志按 created_at 清 3 个月前、已处理任务按
│   │                             #  updated_at 清 3 个月前、**门店（零售）任务按 rq 单据日期清 2 年前**（平台不接受 2 年前的
│   │                             #  单据，见 App\RetailRetention——这条判"单据本身多老"，前两条判"记录存了多久"）
│   ├── backfill_rq.php           # 回填 upload_logs 的单据日期（rq 列；按 djbh 关联处一律限定批发主体——djbh 不是跨企业唯一的）
│   ├── backfill_update_state.php # 【一次性回填，2026-10-02 已执行】把回写功能上线前已补传成功的 8 个零售单号补进
│   │                             #  dyt.bs_msfx.dbo.update_state（判据取自 upload_logs：`source IN ('retail_retry','manual')`
│   │                             #  + 已上传成功/单据重复，再按企业类型剔掉批发的 manual 行）；
│   │                             #  幂等可重跑（重跑即 0 写入、N 跳过），见 docs/adr/0016
│   ├── init_db.php               # 初始化/迁移 SQLite 数据库及表结构（幂等；含 company/credential 列、历史回填、
│   │                             #  ent_list 唯一键重建、三列补传元数据、任务状态取值归一 待补传→等待上传）
│   ├── sqlite_query.php          # 调试工具：直接传 SQL 查询/操作 SQLite（表格输出）
│   ├── migrate_status_fields.php # 【一次性迁移，2026-07-29 已执行】旧 status/success 两列拆为 task_status/request_status/response_status
│   │                             #  （**旧列没删掉**：本机 SQLite 3.7.17 不支持 DROP COLUMN，那一步只打印 SKIP——列还在，
│   │                             #   见下方两张表的字段说明，别去读它们）
│   ├── fix_response_status.php   # 【一次性修复，2026-07-29 已执行】按 resp/response 重解析，修正映射错误、显示为"未确定"的记录
│   └── cron_handle.php           # 空文件（0 字节、全仓无引用）——归档残留，无用途，别指望它有行为
├── data/
│   ├── msfx.db                   # SQLite 本地数据库（3 张表 + 索引）
│   ├── fetch_bill_counter.json   # fetch_bills（批发）变化检测门卫基线（当天单据计数）
│   └── fetch_bill_counter_retail.json # fetch_bills_retail（零售）计数门卫基线（`{date, total}`——2026-10-05
│                                 #   起只有一个数；带 uploaded/unuploaded 的旧版文件会被判非法、照常采一轮）。
│                                 #   **与批发那份各一个**——两个脚本共写一个文件会互相踩（形状不同、彼此都会
│                                 #   把对方的内容读成"格式非法"，门卫时灵时不灵），见 App\RetailCollectionGate
├── tests/
│   ├── trace_splitter_test.php   # TraceSplitter 自包含断言测试（php tests/trace_splitter_test.php；用例 16 是工单 07 验收第 3 条的离线口径——2000 码的 104 在 10000 上限下不拆、4000 码的 321 在 3500 上限下拆 3500+500）
│   ├── quantity_check_test.php   # ApiClient::isBillFound 自包含断言测试（php tests/quantity_check_test.php）
│   ├── api_client_test.php       # App\ApiClient 自包含断言测试（php tests/api_client_test.php）：顶层可重试判据
│   │                             #   isRetryableTopError（限流 code=7 / msg 兜底 / 大小写 / 参数错误与空码不重试）
│   │                             #   + TOP_SDK_WORK_DIR 指向项目根（**先加载装配类**再断言——钉住"与加载顺序无关"，
│   │                             #   只在 ApiClient 一处定义时这条变红）
│   ├── enterprise_config_test.php # App\Enterprise 自包含断言测试：配置解析/门店认领/接口路由/配置自检
│   ├── config_test.php           # App\Config::sqlServer() 自包含断言测试：键集合恰为五字段（**不含 timeout**）、
│   │                             #   未显式 load() 时 password 非空（**唯一能分辨 load 跑没跑**的一条——生产 .env 的
│   │                             #   其余四字段与硬编码默认值逐字相同）、与 Config::get() 逐键对账（抓键名拼错）
│   ├── retail_upload_test.php    # App\RetailRequestAssembler 自包含断言测试：lsyd 入参映射（refUserId 取凭据 ref_ent_id、from/to 照搬源表列、clientType=2、码上限取自路由），判据用请求类自己的 check()
│   ├── retail_retention_test.php # App\RetailRetention 自包含断言测试：2 年截止日的计算与边界（常规/跨年/月末/闰日溢出方向、截止日当天保留、不传参时相对今天滚动）
│   ├── retail_manual_test.php    # App\RetailManualEntry 自包含断言测试：哪两类要往来单位名称、对手方 entId 落在 from 还是 to
│   │                             #   （按发货/收货语义）、prepare() 触网前的全部拒绝分支（含 2 年下限）、手工 104 的端到端装配形状
│   ├── record_query_test.php     # App\RecordQuery 自包含断言测试：三页固定口径（失败页的 quantity_check 豁免与同企业判重）、
│   │                             #   日期参数名→列的映射、`?` 与 params 数量恒等
│   ├── log_source_test.php       # App\LogSource 自包含断言测试：已知取值清单与词表**互为子集**（加一个取值就得写下
│   │                             #   它的出处）、每个取值都有非空标签与徽标色、标签不是机器值也不重复、
│   │                             #   RetailRetransmit::SOURCE 与 RetailManualEntry::LOG_SOURCE 落在词表里、
│   │                             #   未知取值回落原值 / 历史空串回落空串（漏一个取值即变红）
│   ├── retail_external_uploads_test.php # App\RetailExternalUploads 自包含断言测试：落库判定真值表（本地无痕→任务、
│   │                             #   已有任务行/成功记录→跳过）、**幂等**（已有成功记录必须跳过）、记录形状各列取值
│   │                             #   （source/response_status/task_id=0/request_status=null、**reason/judged_by 必须
│   │                             #   由调用方给**）、来源常量落在词表里、表名常量；**翻正动作分类**（翻任务 /
│   │                             #   追加记录 / 跳过三分支、已有成功记录时不追加、回小写照样命中、
│   │                             #   **同名单号跨企业不串号**）；**`--all` 口径**（`buildTasks: false` 时本地无痕
│   │                             #   → ACTION_COUNT_ONLY、默认参数仍是建任务那条护栏）；**统计口径**（tally 的
│   │                             #   四个键、**只计数/跳过的不进 M 码数**、未知动作抛异常）；
│   │                             #   **批量 touch 的语句形状**（票 02：`touchStatements()` 每句都带
│   │                             #   `company = ?`、两张表各一句、501 个单号切成 2 句且每句参数 ≤ 999——
│   │                             #   真落库不进本测试，由副本实测兜着）。
│   │                             #   辨别力：去掉 hasSuccess 检查 → 幂等用例变红；success 判据降成裸单号
│   │                             #   → "同名单号两家各自判"变红；去掉 uploadedBills 检查 → "平台没查到"变红；
│   │                             #   去掉 decide 的 buildTasks 分支 / 默认值改 false / tally 把只计数的也算进
│   │                             #   码数 / 未知动作改为静默 → 那几组用例各自变红；
│   │                             #   **把 ACTION_RECORD 加回来（等于把源库状态表那半分流接回来）→ 决策护栏变红**；
│   │                             #   touchStatements 的 SQL 丢掉 `company = ?` → 4 条红、IN_CHUNK_SIZE 改 1000
│   │                             #   → 参数上限那条红（均已逐条实测）
│   ├── retail_collection_gate_test.php # App\RetailCollectionGate 自包含断言测试：那个数的比对与判定（变化、
│   │                             #   日期不符、手工改成字符串）、基线读写（落盘形状恰为 `{date,total}`、缺失/半截 JSON/缺键/
│   │                             #   日期非法一律"无基线"）、**旧版形状（带 uploaded/unuploaded）也判非法**、
│   │                             #   以及 guard() 的全部分支——**失败不写基线**（落库中途失败时
│   │                             #   旧基线一字未动、新文件压根不建）、**未变就不采集**（采集闭包一次都不跑）、
│   │                             #   **计数失败照常采集**（不写基线）、`--all` 绕过、基线文件与批发那个不是同一个。
│   │                             #   辨别力：write 挪到 collect 之前 / 计数失败改成跳过 / 不比对日期 / 坏基线抛异常 /
│   │                             #   旧版形状当合法 / 未变也照跑——六处各有用例变红（计数 SQL 本身不进测试，靠实测对账：门卫 total
│   │                             #   必须等于采集脚本那句"拉取到 N 张单据"）
│   ├── retail_batch_upload_test.php # App\RetailBatchUpload 自包含断言测试（票 06）：取数口径（只取门店等待上传、
│   │                             #   `未识别` **不**筛在 SQL 里、按 id 升序）、**`--dry-run` 一次都不调上传回调**、
│   │                             #   未识别/批发主体/空名一律跳过并计数、`--limit` 限的是上传条数（跳过的不占额度）、
│   │                             #   逐条隔离（被拒算失败、后续照跑）、子单 failed>0 的一单计失败。
│   │                             #   辨别力：六处各自变红（dry-run 分支去掉 / 门店判据恒真 / 限量挪到分流之前 /
│   │                             #   去掉 try/catch（PHP fatal，断言没跑完）/ SQL 丢掉状态条件 / 子单失败判成成功）
│   ├── retail_platform_check_test.php # App\RetailPlatformCheck 自包含断言测试（2026-10-05，31 条）：分组跳过判据
│   │                             #   （未识别/待配凭据/未声明凭据位/混进来的批发主体）、**企业隔离**
│   │                             #   （同名单号各判各的——一次喂全量集合就是跨门店串号）、
│   │                             #   **dry-run 一次都不调平台**、三种结果的归类与收集（error 不冒充未上传）、
│   │                             #   逐条隔离、限量（跳过的门店不占额度）、**门卫账本 `checked_by_company`**
│   │                             #   （票 02：答复过的进、异常/没轮到的不进、dry-run 为空、
│   │                             #   同名单号按企业各记各的——按裸单号记会连带刷别的门店的门卫）。
│   │                             #   辨别力：七处各自变红（喂全量集合 / 去掉 dry-run 分支 /
│   │                             #   跳过判据去掉 isRetail / error 并进 absent / 去掉限量截断 /
│   │                             #   账本收进 error 那条 / 账本丢企业维度——已逐条实测）
│   ├── search_bill_test.php      # searchbill.detail 查询调试：传单号输出完整返回并另存 searchbill_<单号>.json（tests 目录内；退出码 0=全部成功，1=存在网络/业务错误）
│   ├── singlerelation_test.php   # singlerelation 逐码查询调试（码级对账探针）：验证 Σ 折算系数 == min_pkg_count 核心等式（折算规则 is_smallest=Y→1 忽略 pkg_amount，2026-08-26 加固；设计见 .scratch/quantity-check/singlerelation-tier2.md；避开 8-20 点窗口运行）
│   ├── searchbill_*.json         # search_bill_test.php 的查询结果存档
│   ├── singlerelation_*.json     # singlerelation_test.php（码级对账探针）的查询结果存档
│   └── company.txt               # 门店凭据草稿（**本地文件，不入 git**——.gitignore 里点名排除的明文凭据）
├── logs/                         # API 日志 JSONL 文件
├── docs/                         # adr/（ADR 0001–0018）、agents/（issue-tracker / triage-labels / domain 约定）、
│                                 #   python-client/（桌面版工程提示词）、quantity-check 假阳性报告
├── .scratch/                     # 本地工单与 spec（issue tracker 的落点，见 docs/agents/issue-tracker.md）
├── CONTEXT.md                    # 领域词汇表（单上下文布局）
├── composer.json / composer.lock # 依赖 phpoffice/phpspreadsheet；src/SqlSrvHelper.php 走 classmap 加载
├── .gitignore                    # 排除 vendor/、data/*.db*、data/fetch_bill_counter*.json、logs/*.jsonl、logs/*.lock、
│                                 #   **logs/top_*.log**（SDK 自己写的日志，2026-10-02 起落在这里：日志目录已注入项目内）、
│                                 #   config/.env、config/enterprises.local.php、top_sdk_retail.zip、tests/company.txt、.DS_Store
├── upload_test.php               # 原始上传脚本（旧版，保留参考；**勿运行**——include 路径失效、连的是旧库、ent_list 读写早于多企业改动）
├── get_ent_list_test.php         # 原始往来单位同步脚本（旧版，保留参考；**勿运行**——ent_list 唯一键已改为 (company, ent_name)）
└── bill_info_test.php            # 原始单据查询脚本（旧版）
```

**`top_sdk/` 的 vendored 约定（2026-09-30 工单 04）**：`top_sdk/top/request/` 下现在共存两代请求类——批发用的 `drug.kyt.*`（SDK 2026-02 世代，原有）与零售用的 `drugtrace.top.lsyd.*`（2026-09 世代，从 `top_sdk_retail.zip` 逐类并入的 3 个：`AlibabaAlihealthDrugtraceTopLsydUploadinoutbillRequest` / `...UploadretailRequest` / `...QueryUpbilldetailRequest`——最后一个 2026-10-05 并入，服务零售平台核查）。压缩包**不进仓库**。**跟进新版 SDK 只能逐类挑，不能整包覆盖**：新包里没有一个旧包的 `drug.kyt.*` 方法（它是 `drug.kyt.wes.*`），覆盖会直接砍掉批发链路；`top/domain/` 的 DTO 类在本项目里是惰性的（`TopClient` 走 `$format="xml"` → `simplexml_load_string` → `SimpleXMLElement` 直接返回，domain 类不参与响应解析）。理由与比对数据见 `docs/adr/0009`。

**`TopLogger` 的坑（2026-10-02 实测踩到，同日已修，见 `.scratch/top-sdk-top-error/spec.md`）**：`TopClient` 在两处会写 SDK 自己的日志——`logCommunicationError()`（HTTP 响应不是合法 JSON/XML → `top_comm_err_*.log`）与**顶层返回错误码时**（`top_biz_err_*.log`，**平台限流走这条**）。路径是 `TOP_SDK_WORK_DIR . '/logs/'`，而 `TopSdk.php:18` 把 `TOP_SDK_WORK_DIR` 定义成 **`/tmp/`**；本机 `/tmp/logs/` 属主是 **root:root 755**（9 月用 root 跑时建的），**nginx 用户写不进去** → `getFileHandle()` 的 `fopen` 返回 false → `fwrite(false, …)` 抛 **TypeError**（属 `\Error`，不被 `catch (\Exception)` 捕获）→ 从 `TopClient::execute()` **抛穿整条上传链路**，把真实原因（限流 / 响应不合法）掩盖成一句 `fwrite(): Argument #1 ($stream) must be of type resource, bool given`——而那次平台调用其实已经发出去了。批量补传 2,861 条实测踩中 4 次。

**修法（三处，均不动 vendored 的 `top_sdk/`）**：
- ① **SDK 工作目录钉在项目内**：`src/ApiClient.php` 与 `src/RetailRequestAssembler.php` 在 `require TopSdk.php` **之前**各定义一次 `TOP_SDK_WORK_DIR = dirname(__DIR__)`（SDK 文件头就是这么推荐的，那句 `if (!defined(...))` 支持外部注入）。**两个入口都要写**——它们是 src/ 侧仅有的两个 require TopSdk 的文件，**谁先被自动加载谁定这个常量**，只写一处会随加载顺序**静默**回落 `/tmp/`（`tests/api_client_test.php` 先加载装配类再断言，专钉这条）。SDK 日志因此落到项目 `logs/top_*.log`（`.gitignore` 已加 `/logs/top_*.log`）。
- ② **顶层限流判为可重试**：顶层 `code` 过去一律按「业务错误不重试」处理，于是限流（`code=7` / `App Call Limited`）被静默判成失败并翻「已处理」，只能人工去失败记录页重传。新增纯函数 `ApiClient::isRetryableTopError(code, msg)`（**code=7 或 msg 含 "App Call Limited"**，后者是 code 将来变了不失效的兜底），`execute()` 用它决定 `is_network_error`——限流于是走**既有重试通道**（3 次 / 30 秒；实测封禁只有一两秒）。将来若发现别的可重试顶层码，在**这一处**加。
- ③ **`catch (\Exception)` → `catch (\Throwable)`**：`ApiClient::execute()` 与 `UploadService::uploadSingle()` 各一处。改动 ① 之后理论上不再抛，但 SDK 内部任何别的 `\Error` 也一样会抛穿，归为「网络错误（可重试）」比抛穿安全。

**⚠️ 未修的同类残留（2026-10-05 复核确认，记在 `.scratch/retail-platform-check/issues/02` 的 Comments）**：HTTP 200 但正文不是合法 XML/JSON 时（反代的错误页、维护页、截断的响应），`TopClient` 返回 `{code: 0, msg: 'HTTP_RESPONSE_NOT_WELL_FORMED'}`，而 `ApiClient::execute()` 的判据是 `code != 0` → **`0` 这一格走 success=true、error 为空**；`isBillFound()` 于是把垃圾响应判成"**单据在平台上**"——门店平台核查会凭空翻正一条「上传成功」，批发的 `check_bill_status` 会凭空标记已处理并写一条 `upload_logs` 上传成功。修法只有一处（把 `code == 0 && msg === 'HTTP_RESPONSE_NOT_WELL_FORMED'` 判成错误、走既有重试通道），但 `execute()` 是所有链路（含申报）共用，留给专门一票去做。

**文件级残留：已收口（2026-10-02）**——改动 ① 当时只钉住了**目录**，而**文件级**的同一故障仍会重演：本项目曾有两套身份写 `logs/`（**root 的 crontab** 与 **nginx 的 PHP-FPM**），两者用河药那一个 appkey，root 先建出 `logs/top_biz_err_32367731_<日期>.log`（0644 root）后 nginx 侧同日 `fopen(…, 'a')` 照样失败（改动 ③ 兜住不抛穿，但真实原因被掩盖、白重试 3×30 秒）。**收口做法（root crontab 里本项目 9 条全部改掉，含注释着的 4 条）**：

```
<原调度> su -s /bin/bash nginx -c '/usr/bin/php /usr/share/nginx/mashangfangxin/scripts/xxx.php' >> /var/log/msfx_cron.log 2>&1
```

重定向留在 root 那侧的 shell 里（`/var/log/msfx_cron.log` 仍是 root:root，不必给 nginx 写权限）；并把 `logs/`、`data/` 下历史 root 属主文件（38 个 jsonl、两个 `.lock`、两个门卫基线 json）`chown` 回 `nginx:nginx`——SELinux 上下文 `httpd_sys_rw_content_t` 不受影响。**手工跑脚本必须同样以 nginx 身份**（`su -s /bin/bash nginx -c 'php …'`）：以 root 直跑会再造 root 属主文件，此后 nginx 侧（含 Web 端）写不进去——`logs/api_<日期>.jsonl` 尤其致命，它一天只有一个文件。

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

**上传任务页（工单 03，2026-09-30）**：表格含**"所属企业"列**（零售门店单即为门店名；`未识别` 整行标红 + 红色徽标，表示源库单据认领不到门店——真异常信号，需人工核查）；零售行（`source='retail'`，即采集来的门店单据）走**补传按钮**（工单 06 落地，批发行仍是原来的"重传"）——见"核心数据流 → 零售补传"。工具栏的**"批量重传"同样按行分流**（工单 15）：勾选多行后 `source='retail'` 的逐条走 `RetailRetransmit`、其余仍走 `UploadService`，混批不再被守卫整批拒绝（跨门店勾选允许，凭据仍按行取）；确认框对**补传不可逆 / 含 `104`·`203` 门店单 / 含已申报成功的行**三处点名（是警告不是拦阻），跨页勾选的点名数据取自跨页累积的行索引。来源下拉含 `零售采集`——**任务状态与批发共用 `等待上传`/`已处理` 两个值**（2026-10-01 统一，见 `docs/adr/0014`）：选门店 + 默认状态即可看到门店单据，不必再切状态。编辑弹窗可改"所属企业"（工单 08），**改后需二次确认**——那是"单据申报到哪个主体"的开关（失败记录页那份弹窗同样生效）；**零售行不显示"往来单位名称"**——零售单的对手方是平台 ID（采集的取自源表、手工建的由名称查出并当场落库），补传直接读那两列，留着这个框等于留一处"改了不生效"的静默陷阱。

**手动上传页（工单 07 建页；门店分支 2026-10-01 与批发同构，见 `docs/adr/0015`）**：顶部先选"所属企业"（**凭据填齐的门店加粗黑字、待配凭据的正常字体灰字**，工单 16/17），**两个分支都是"在线新增 + xlsx 批量导入"两张卡片**（批发分支的行为一字未变）。门店分支的差别只有三处：单据类型只列门店那四种（`104`/`203`/`321`/`116`）、**`321`/`116` 不显示"往来单位名称"**（对手是消费者，接口里没有对手方入参）、落库主体是所选门店且凭据由服务端按门店取。原先那份"门店补传清单"已撤——它是上传任务页的第二个实现，而门店行的补传/筛选/导出在那边本就有。批发与零售都有的两类单据类型（`321` 使用出库 / `116` 消费者退货入库，工单 03 起会采集入库）本次补进**三个数据页**的类型标签表：缺了它们这三类零售单在页面上显示成 `-`（手动上传页那份标签表随门店清单一起删了，那边的类型名写在下拉 option 里）。

三个数据页面（upload-tasks / uploaded / failed）均支持筛选：单号、往来单位、状态、**单据日期**（`rq`）、**任务创建时间**（`created_at`）。日期筛选使用 flatpickr 范围选择器，一个输入框同时选起止日期，默认最近 7 天（含当天）。**关键词检索（单号/往来单位）不受默认日期范围限制**：输入关键词时若日期选择器仍是默认 7 天（用户未手动改过），前端自动不传日期参数实现全库检索；用户手动改过日期则关键词+日期正常组合过滤。分页最多显示 10 个页码，超出用省略号。

三个数据页工具栏均有"导出 xlsx"按钮：按当前生效筛选条件全量导出（前端已计算关键词忽略默认日期后的参数）。导出走 `page=api&action=export`（`api/export.php`），**流式生成**（sheet XML 逐行写临时文件 + ZipArchive 打包，不用 PhpSpreadsheet 避免全量驻留内存）；追溯码按字符数拆行（`App\TraceSplitter::splitByCharLimit`，每行 ≤32000 字符 ≈ 1523 码，超限时一单多行、单号加 `_N` 后缀，命名对齐上传拆分、已带后缀的单号追加后缀），拆行兜底（单条码自身超 32000 字符的极端情况）仍截断并追加 `…(共N个码)`，其余列超限追加 `…(已截断)`；无匹配数据时前端拦截提示、后端仍输出带表头的空文件。导出列与页面表格对齐（**「来源」列导出中文标签**——与页面同一份 `App\LogSource`，2026-10-02 前是机器值；单据类型导出归一化 3 位码），文件名 `上传任务/已上传/失败记录_YYYY-MM-DD.xlsx`。（第 4 类 `type=retail_tasks`（门店补传清单导出）随门店分支撤销，见 `docs/adr/0015`。）

**三数据页的"所属企业"筛选与导出列（工单 08；下拉字体区分见工单 16/17）**：三页表格都有"所属企业"列（上传任务页工单 03 加、已上传页工单 06 加、失败记录页工单 08 补齐）。筛选栏都有"所属企业"下拉，选项由 `Enterprise::selectableOptions()` 给出（**企业名 => 凭据就绪态**，另含 `未识别` => `unidentified`；已配 16 家企业共 17 项。`未识别` 不是一个企业，但确实是 `company` 列的一个取值——门店认领失败的那批，必须能单独筛出来）。**按 `company` 去重**——每家恰一套凭据（1:1，见 `docs/adr/0012`），故"一门店多套凭据时显示 `门店名（label）`"这类问题已不存在。xlsx 三类导出都含"所属企业"列（位置与页面表格一致，在"单据类型"之后）。

- **下拉里两类都上字体，互为参照（工单 16 起、工单 17 改口径；全站 6 处一处不落）**：凭据**填齐**的（`ready`）**加粗黑字**，凭据**未配齐**的（`pending` 等密钥 / `no_slot` 配置缺口）**正常字体灰字**——后两者同一档，因为对"现在能不能传"这个问题它们答案一样（这两态在别处是分开的：上传任务页的徽标 `待配凭据` 灰 / `未声明凭据位` 红，那儿问的是"该谁去做哪件事"）。`未识别` 与未知态不上样式（`未识别` 不是企业，它的呈现是表格里那套整行标红）。**只标"不能用"那一类是不够的**（16 票原做法）：人看得出哪些不能用、却看不出哪些能用，17 项的列表里等于没给参照系——这是 17 票改口径的理由。**只看不禁用**——待配凭据的门店仍要能筛出它的行、仍要能把任务改派给它（等密钥到手即可传）。要覆盖的 6 处：三个筛选栏（upload-tasks/uploaded/failed）＋两个编辑弹窗（upload-tasks/failed）＋手动上传页顶部。渲染规则 `companyOptionAttrs()` 在 `views/layout.php`（全站视图都 require 它），default 分支**兜底原样而非高亮**：将来多一个态才不至于让一批正常企业莫名其妙变灰
- **`未识别` 的呈现三页并不相同**：只有上传任务页另加红色徽标 + 整行标红（它是操作页，那批单等着人处置）；已上传/失败两个日志页只作普通文字——工单 06 定的就是日志页不标红，本票未改
- **表格里"所属企业 / 往来单位"两列：字号略小 + 允许换行（2026-10-02；三个数据页一处不落）**：此前是 Bootstrap 的 `text-truncate`（nowrap + 省略号）＋内联 `max-width`，长企业名只剩 `XX大药房连锁有限…`，认全名得逐行 hover——而列表页恰恰是扫一眼的地方。现改为共用类 `.cell-longtext`（定义在 `views/layout.php`，与 `companyOptionAttrs()` 同一处）：`font-size:.875rem`（14px）+ `white-space:normal` + `word-break:break-word`／`overflow-wrap:anywhere`（后者管不含空格的长串——少了它那串会撑住列的 min-content 宽度，浏览器只能给整张表加横向滚动条）。**列宽上限仍由 td 上的内联 `max-width` 兜着**（200/220）：换行而不是把表格撑宽，其余列一格不动；`title` 保留作兜底。**格内的状态徽标不跟着缩**——`.badge` 的字号是 `0.75em`、相对所在单元格算，另有 `.cell-longtext .badge{font-size:.75rem}` 钉回 12px，否则"未识别/待配凭据"那枚徽标会比同行的"来源/状态"徽标小一圈。仪表盘那处 `text-truncate`（"API 返回"列）不属这两列，不在本次范围
- **"企业下拉不算关键词"**：三页都有"输入关键词时丢掉默认 7 天日期范围"的逻辑，那里的关键词**只算单号与往来单位**。企业下拉是筛选维度，算进来会让"选了企业"顺手把默认日期范围也丢掉，日期行为被无声改变
- **三页的默认 7 天维度不同**（上传任务页＝单据日期 `date_from/to`；已上传/失败页＝任务创建时间 `date_from/to`，单据日期改用 `rq_from/to`），映射写在 `RecordQuery::addRange` 的调用处，改参数名时别搞混
- **「来源」列与下拉的单一事实源 `App\LogSource`（2026-10-02 收口，见 `.scratch/retail-collection-split/issues/01-source-label-single-source.md`）**：取值 → 中文标签 / 徽标色 / 下拉选项收在一个类里，两个日志页的视图与 xlsx 导出都从它取；**下拉由它 foreach 渲染**，故天然覆盖全部取值——`quantity_check` 曾因三份标签表都没有它而直出机器值、按来源也筛不出来，这笔欠账本轮还上（标签「数量对账」，徽标红）。新增一个来源取值只改那一处。两处刻意的不统一：① **任务页下拉的成员名单留在视图里**（写死 `cron` / `manual` / `batch_check` / `batch_retry` / `retail` 五个值），标签文本仍取自同一份词表——`retail_retry` / `retail_external` / `quantity_check` 只写日志表，列在那儿只会筛出空结果；名单里那两个取自日志表的 `batch_check` / `batch_retry` 是历史遗留（前者在任务表里只剩 2026-07-29~30 的 40 行旧数据——默认近 7 天窗口下筛出来是 0 条，放宽日期才有；后者一条也没有）；② **未知取值回落成原值**（历史空串因此仍导成空单元格）——显示机器值总比显示一列空白强，漏配标签时还认得出数据是从哪来的
- **筛选构造单一事实源 `App\RecordQuery`**：`tasks/uploaded/failed/export` 四个入口都调它，原先那 4 份拷贝已删。已知的一处漂移顺带消失——`export.php` 的失败记录分支曾无条件走 NOT EXISTS、缺了页面版那句 `source = 'quantity_check' OR` 豁免，结果是失败记录页看得见的数量对账告警、导出的 xlsx 里没有。**仪表盘那张卡片仍是第 5 份拷贝**（`views/dashboard.php`，其 NOT EXISTS 既没限定 `company` 也没有该豁免），本轮明确不动，等零售接入稳定后再统一

## 核心数据流

### 定时上传（fetch_bills.php + upload_pending.php）

> ⚠️ **现状（2026-10-02 票 07 核对 `crontab -l`）：本节的"定时"只对 `fetch_bills` 成立**——`upload_pending.php` 的三条 crontab 条目**全部被注释**（「暂不启用」），批发"等待上传"的任务眼下靠 Web 端手动传（`upload_pending_retail.php` 亦然，见下）。本节描述的是这两个脚本**各自的职责与口径**，不是"它们此刻都在定时跑"。

采集和上传解耦为两个独立脚本，可分别设 cron 规则。

**采集（fetch_bills.php）**：启动时轻量查询 SALEOUTMT/PURINMT 当天单据计数，与 `data/fetch_bill_counter.json` 基线比较——同一日期且计数相同则跳过采集（避免重视图查询空转），基线只在采集成功（视图查询 + SQLite 写入全部完成）后更新；然后 SQL Server 查询当天单据（**仅取已执行单据 `a.is_zx='是'`**，作废/未执行单据不采集，口径与 `config/sql.php` 一致）→ 按 **`(company, djbh)`** 去重（跳过 `upload_tasks` 中已存在的任务，以及 `upload_logs` 中已上传成功/单据重复的单据；同名单号属于别的企业时是另一条记录，不能互相顶掉）→ 写入 SQLite `upload_tasks`（source=cron, task_status=等待上传, bill_type=单据号前缀, **company/credential 取 `App\Enterprise::wholesaleSubject()`**）

**上传（upload_pending.php）**：读取 `upload_tasks` 中 `task_status='等待上传'` **且 `company` = 批发主体**的任务——**白名单取数**，不是"排除零售/其他来源"的排除法（排除法 fail-open：将来任何新增来源漏改条件，就会把门店单据按河药主体申报到平台，不可逆）→ 查 SQLite `ent_list` 缓存（按 `(company, ent_name)`）→ 缓存未命中调码上放心 API 获取 `ent_id` → 超过 3500 追溯码自动拆分为 `单号_1, 单号_2...` → 调 API 上传 → 结果写入 JSONL + SQLite `upload_logs`（关联 task_id，带 company/credential）→ 更新 `upload_tasks` 状态 → 重试 3 次（仅网络错误，间隔 30s）→ API 间隔 0.33s → flock 文件锁防并发

**上传守卫（UploadService::resolveContext，2026-09-29 多企业排雷）**：`upload()` 在取锁与任何平台调用**之前**逐条校验所属企业、接口族与凭据，任一不合规**整批拒绝**（抛 `\RuntimeException`，不发一次调用、不写一条日志；混合批次"传一半才报错"比一开始就拒绝更难收拾）：
- `company` 必须在企业配置中（`App\Enterprise::find`）——`未识别`/空/未知企业名一律拒传
- 该企业在该单据类型上的路由必须落在**本服务支持的批发 kyt 接口**（`Enterprise::route()` 的 class 比对）——零售走 lsyd，一律拒传（零售装配见工单 05/06 与 `docs/adr/0015`）
- 该企业必须取到**填齐的**凭据（任务行的 `credential` 列指定凭据位，取不到或残缺即拒传）；**绝不回落到默认（河药）凭据**

守卫在 UploadService 而非调用方，是刻意的：`upload_pending` / `tasks_retry` / `tasks_batch_retry` 三处（批量那个工单 15 起先按 `source` 分流，只有批发那批进本类）以及将来新增的调用方都会 `new UploadService()`，只把 SQL 写对护不住直接调用的脚本。

手动上传保持立即上传不变，两套上传路径并存：批发两个端点（`manual_create` / `manual_import`）落库主体取 `Enterprise::wholesaleSubject()`；门店两个（`manual_create_retail` / `manual_import_retail`）落库主体是入参里那家门店、凭据由服务端按门店取，上传走 lsyd（`App\RetailManualEntry` → `App\RetailRetransmit`）。

### 零售单据采集（fetch_bills_retail.php，工单 03 建；票 02 加过分流、票 04 加过计数门卫、票 05 加过全量快照；**2026-10-05 起一律建任务——分流、状态闭环与门卫的两个数一并停用**，见 `docs/adr/0018`）

零售单据由外部系统上传，本项目只做"**可见** + 人工补传"（见 `docs/adr/0007`）：本脚本**只采集入库、不上传**，不调任何平台接口，故不受 8-20 点限流窗口约束（cron 与 fetch_bills 同频、错开 5 分钟，见下方 cron 时间表）。

> ⚠️ **2026-10-05 起采集不再读源库状态表**（`update_state`，见 `docs/adr/0018`，票 03 的决策）：外部系统不稳定期间，它写下的痕迹不再是证据——按它分流（已上传的不建任务）或按它闭环（把本地待办翻成「上传成功」）**都会让一张实际没传成的单静默消失**。现在的口径是：**单据一律建「等待上传」任务**（本地已有任务行/成功记录则跳过），是否已上传**统一由平台核查判定**（`check_bill_status_retail.php`，拿门店自己的凭据问平台；已上传的会被翻成「已处理」+「上传成功」，并在已上传页出现一条「外部上传」记录）。**代价**：取不到凭据的 9 家门店 + `未识别` 的单平台核查够不到，会一直挂在「等待上传」（2,376 条）——宁可堆在明面上，也不要被一张假状态悄悄翻掉。那张表的**写侧没停**（补传成功后仍回写，见工单 18）。
>
> **2026-10-02 补（工单 18，见 `docs/adr/0016`）**：本项目**人工补传成功后也回写这张表**（`App\UpdateStateWriter`）——告诉外部系统"别再传"。2026-10-05 起它是那张表在本项目里**唯一活跃的用途**。
>
> **日期：cron 限当日**（2026-09-30 用户定）——不带参数 = 当日；`--all` = 不带等值日期条件的两年窗口。
>
> **`--all` 的口径（2026-10-05 起：只统计、不落库）**：判据去掉之后它写不出任何记录（全部落 `ACTION_COUNT_ONLY`），故退成**只读侦察**——跑完打印"窗口内 N 单，本地已有痕迹 M 单（不重复写），其余 K 单未建任务"，**K 才是那份欠账**（本系统里完全看不见的那些）。它**一个字节都不写**：不落库、不写 JSONL（含逐条认领告警）、不写基线。**跑一次即可、别挂进 cron**；`--dry-run` 与它在行为上等价。历史已由 2026-10-02 那次快照落过（47,813 条记录），本入口留给"欠账有多少"这个问题。
>
> **实测（2026-10-02 首跑，旧口径的记录）**：窗口内**去重后 54,085 单**（`zsm_ls` 行数 105,015，321 平均 2.08 行/单）、原始 558,981 行；当时**将写入 47,813 条外部上传记录 / 220,367 个码**（那是分流口径，现已不再写）；**未上传 6,251 单**，其中 6,245 单本地早有痕迹，**真正"未建任务且看不见"的只有 6 单**。⚠️ 票面原先写的"104,995 张单据 / 约三万七千张未上传"是**行数**口径（105,015 行 − 状态表 67,856 行）——与门卫票 04 撞见的是同一个坑：`count(*)` 数的是 `zsm_ls` 自己的重复行，真相以这里为准。**本口径下的欠账数要看新 `--all` 的输出**（未上传的定义变了：现在问的是"本地有没有痕迹"，不再问那张表）。
>
> **`--dry-run`（票 05）**：预演只读源库、只统计并打印「将新增任务 N 条 / M 码」，**不落任何库、不发任何平台调用**。两处接线是刻意的：① **不经过计数门卫**（票 04 留的欠账）——门卫的产物是基线，预演写了它，下一轮 cron 就会判"总数没变"而**少采一轮**；② **不跑任何翻正**（那是写库）。逐条认领告警也不打印（预演要的是量级，只报计数），真跑照打照写。
>
> **2 年下限（2026-09-30 用户定，平台硬性规定）**：采集 SQL **始终**带 `bill_time >= 截止日`（`App\RetailRetention`，今天是 2026-09-30 则 2024-09-30）——`--all` 靠它截断，故其语义是"**最近 2 年**的窗口"而非全部历史；cron 的当日采集天然满足。**显式指定一个超期日期时直接拒绝并退出 1**（在连源库之前）：静默采回 0 条会被读成"那天真没单据"，而真相是那天即使有单也补传不出去。依据是平台的原话——补传 2023 年的单会返回 `FAIL_BIZ_PARAM_BILL_TIME_BEFORE_ERROR`「系统不支持上传2年前单据」。决策与代价见 `docs/adr/0013`。

- **源**：dyt 链接服务器（`dyt.msfx.dbo.zsm_ls` 单据头 + `zsm_ls_code` 追溯码，**一码一行**），复用 `SqlSrvHelper` 同一条连接直接查 4 段式名；**采集全程只读 SELECT**。源库状态表 `dyt.bs_msfx.dbo.update_state`（表名见 `App\RetailExternalUploads::TABLE`）**本脚本 2026-10-05 起一个字都不读**——写侧仍在补传链路（`App\UpdateStateWriter`）
- **单条 SQL**：`zsm_ls LEFT JOIN zsm_ls_code` + `bill_time >= ?`（保留下限，始终在）+ `bill_time = ?`（默认当日；`--all` 时不加这一段）+ `order by ls.bill_code`（票 05 加的，**只为让同一单号的行连续**——"读完一张收一张、攒够一批落一批"的前提；不是两条查询，见下"逐行消费"）。**必须 LEFT JOIN 而非内连接**——没码的单也要采（它是补传队列里值得看见的一条）。**这里曾经多取一个已上传标志列**（`case when exists(状态表…)`，票 02 分流的判据，当时那条注释写着"必须 EXISTS 不能用 JOIN"——那张表无主键无唯一约束，实测 82 个单号多行，JOIN 会把结果集放大）——**2026-10-05 整列去掉**，源库状态表不再参与采集
- **状态闭环：已停接**（票 03 的那次调用，2026-10-05 起空着——理由同上，它读同一张表）。方法 `App\RetailExternalUploads::closeLoop()` 与它的 docblock 都留着，**恢复时接回采集之前**（顺序红线：闭环在前、门卫在后）。它当年长这样：每轮开跑前拿本地待办清单（`source='retail'` 且「等待上传」的门店任务 ＋ 零售企业的非成功记录）按单号 IN 分块查状态表，命中则把**任务行**翻 `已处理`+`上传成功`（`request_status` 不动——本项目没发起过请求）、**失败记录不改写历史**而是追加一条「外部上传」记录（失败页那条随后被同号判重隐藏）。**这一整套判定与落库没有作废**——平台核查走的就是同一对函数（`closureActions()` + `applyActions()`），只是判据换成了问平台
- **落库判定**（`App\RetailExternalUploads::decide()`）：**本地无痕 → 建「等待上传」任务**；已有任务行**或**成功记录 → 跳过（幂等）。判定里**没有"源库说传没传"这一维**（2026-10-05 起）。已上传的单由**平台核查**翻正——任务行翻「已处理」+「上传成功」（`request_status` 不动），失败记录**追加**一条「外部上传」记录（`source='retail_external'`、`task_id=0`、`request_status` 留 NULL、`response` 写一段出处 JSON，`judged_by` 是平台接口名）
- **去重在 PHP 侧收口**：重复行在**左表 `zsm_ls` 自己**里——`321` 平均 **2.08 行/单**、同一单号最多 120 行、14 列值全同（实测见 `.scratch/retail-chain/probe-findings-2026-09-29.md` 第 3 条；门卫计数也撞见一次：当日 `count(*)` 111 行 vs 55 张单），`LEFT JOIN zsm_ls_code` 在它之上再按码数放大一层——追溯码用关联数组去重（保序），单据头字段取该单首次出现的行（重复行各列本就相同）
- **逐行消费 + 分批落库（票 05）**：窗口内实测 **54,085 单 / 558,981 行**（票面原先按 104,995"单"估的其实是**行数**）。做法：`queryEach` 逐行消费 + `order by bill_code` 保证同单号连续，**按键切换**把收尾的单据攒成一批（`RETAIL_WRITE_BATCH = 500`，与 `IN_CHUNK_SIZE` 同值）就落一批。**它解决了什么（实测，别夸大）**：旧写法（全攒进 `$bills`）在当前规模下内存峰值 **84.5 MB**，128M 上限下**跑得过**——票面"会撞上限"的前提不成立；流式的价值是压到 **6–12 MB** 且**与窗口规模无关**（窗口再长一倍也不会突然溢出）。三条连带的后果：① 内存与批大小同阶，与窗口里有几万单无关，快照与日常**走同一条流式代码**（只有一套实现）；② **落库与读源库交错进行**（旧写法是"源库全读完才开始写"），中途失败会留下已落库的那几批——可接受是因为落库**幂等**（按 `(company, djbh)` 跳过已有的），重跑补齐即可，门卫"基线只在整轮成功后写"也随之兜住（抛异常即不写基线 → 下轮重采）；③ 每批落库包在**一次事务**里（`App\Database::transaction()`）——单条 INSERT 一个隐式事务 = 一次 fsync，本机实测 **21–28 ms/条**，快照那 47,813 条记录按"一条一提交"要等 25–30 分钟磁盘，攒批后整轮写库 ~20 秒
- **`physic_type` 必须显式取**：老 SQL 里没有这列，但补传装配要它（ADR 0010）——漏掉它，`104`/`203` 那些单会被 SDK 的 `check()` 拒掉且**永远补不出去**
- **单据类型写死四种** `104`/`203`/`321`/`116`（`bill_type` 是 int；第五种 `999` 语义未明，用户判定不采）；`bill_time` 是 `varchar(10)` 纯日期
- **计数门卫**（票 04，2026-10-02；**2026-10-05 起只数一个数：当日单据总数**；判定、基线读写与"只在采集成功后才写"的顺序铁律全在 `App\RetailCollectionGate`）：采集之前先数当日总数与基线比对，**没变就跳过整轮并打印原因**——交付给运维的是**日志从此能区分"今天真没新单"与"脚本压根没跑"**（源库那一趟扫描省不掉：门卫计数 47–59ms、采集查询 80–153ms，门卫省的是本地那段空转——去重、认领、写库）。六处刻意：① **原先那两个数（已上传/未上传）读源库状态表，本次去掉**——外部系统写得乱时它们一直在变，门卫会一直判"有变化"而丢掉省轮次的作用；② **`zsm_ls` 必须去重成派生表**（`zsm_ls` **自己**就有完全重复行——321 平均 **2.08 行/单**、最多 120 行、14 列值全同，见 `.scratch/retail-chain/probe-findings-2026-09-29.md` 第 3 条；不去重数出来的是**行数**，首跑实测 111 行 vs 采集的 55 张单，去重后 `total` 与采集脚本那句"拉取到 N 张单据"**逐字相等**）；③ **计数查询失败 = 无基线、照常采集**（门卫跳过的是一整轮采集，"宁可多跑一轮"；与批发那边"计数失败即跳过本次"刻意相反——零售没有第二道兜底，漏采就是整天单据在页面上不存在）；④ **基线只在整轮采集成功后写**（落库中途失败、源库读取失败都不更新，否则一次中途失败会被记成"没变化"从此永久跳过；采集整轮包成闭包交给 `guard()`，这条因此是构造上的性质）；⑤ **基线文件独立**（`data/fetch_bill_counter_retail.json`，形状 `{date,total}`——与批发的 `fetch_bill_counter.json` 各一个，共写一个文件两个脚本会互相踩）；⑥ 缺失 / 损坏 / 日期不符 / **旧版形状（还带 `uploaded`/`unuploaded`）** 一律视为"无基线"照常采集；`--all` 绕过（`date = null`＝门卫不适用，侦察的计数口径是两年窗口，不是当日）；**`--dry-run` 压根不调 `guard()`**（不是"过门卫但采集空转"——那会把基线写掉，下一轮 cron 据此判"总数没变"而少采一轮；票 04 留的接线欠账，票 05 结清）。⚠️ 原先那条"必须插在状态闭环之后"的顺序红线随闭环停接失效——**恢复闭环时记得接回门卫之前**
- **不需要拆单**（实测单张单据码数上限 1,718 < 3500）
- **实测（快照首跑，2026-10-02 票 05，旧口径——当时 `--all` 还落库；先在副本上验写路径、再在生产库真跑，跑前已 `.backup` 到 `/root`，全程只读源库）**：`--all --dry-run` **生产库与副本数字逐字相同** → 拉取 54,085 单（原始 558,981 行）／当时将写入 **47,813 单 / 220,367 码**／跳过 6,266 单／未上传 6,251 单（6,245 已有痕迹），预演耗时 32.8 秒、内存峰值 6 MB；**真跑** 88.5 秒、内存峰值 12 MB、退出码 0，`upload_logs` 44,266 → **92,079**（`retail_external` 13 → 47,826）、`upload_tasks` **48,997 一条没多**、基线文件一字未动、`msfx.db` 与 JSONL 属主仍是 nginx；**原地重跑**（先副本后生产）→ 0 写入、54,079 全跳过（幂等）；**对账**：源库只读直查 `去重单数=54085 / 已上传=47834 / 未上传=6251`，与脚本逐字相等。⚠️ 那一跑写下的 47,826 条 `retail_external` 记录，其 `response.judged_by` 是**源库状态表**——按 ADR 0018 的口径它们**没有被平台核查复核过**（在本地已有成功记录，进不了待办清单），要复核得另开一票。**票面原先的"104,995 张单据 / 约三万七千张未上传"是行数口径**（105,015 行 − 状态表 67,856 行），真相以本行为准——与门卫票 04 撞见的是同一个坑
- **认领**走 `App\Enterprise::claim()`（不另写一套匹配）：`321`/`116` 取 `from_user_id`、`104`/`203` 取 `to_user_id` 命中门店登记过的任一平台 ID，ID 缺失才回退 `oper_ic_name`；都不命中 → `company='未识别'` **照常入库**（丢单比错标更危险；已上传的未识别单同样写成记录）。`name_unmatched`（ID 认到、源库名字对不上任何门店）记一条 JSONL 警告，**只进 JSONL 不进 `upload_logs`**（后者是上传结果日志，写进去会在失败记录页冒出既非上传也非失败的记录，污染唯一告警出口），不改判定
- **落库**：`task_status='等待上传'`——**与批发共用一个状态值**（2026-10-01 统一，见 `docs/adr/0014`）。曾用 `待补传` 独占一个值，代价是上传任务页按门店筛选默认恒为空（"等待上传"+默认近 7 天两条默认叠加）；**拦住门店单不被 cron 取走的是 `upload_pending.php` 的 company 白名单，不是状态值**。其余：`source='retail'`、`company` 取认领结果、`credential` 取 `claim()` 返回的该门店凭据键（**待配凭据的门店同样预填键**，页面据 `credentialConfigured()` 禁用补传）、`ent_name` 留空（零售对手方 ID 直接来自源表，不用 `ent_list`）
- **补传要用的元数据一并落库**（工单 06）：`from_user_id` / `to_user_id` / `physic_type` 照搬源表同名列（单据头字段取该单首次出现的行）。补传装配要这三列，缺一列这条单就永远补不出去——**没有历史回填**（那三列对批发行无意义，零售的值只能从源表现采），工单 06 之前采的零售行已删除并按日期重采；将来遇到缺列的旧行，办法同样是重采（`(company, djbh)` 去重会跳过已存在的行，不重采就补不上值）
- **幂等**：按 `(company, djbh)` 去重（判据见上"落库判定"条）；重跑同一日期不重复建任务，也不产生第二条成功记录
- **失败不写库**：源库不可用时 `SqlSrvHelper::queryEach` 返回 `false` 且错误另存在 `lastError`，脚本据此区分"真没单据"与"查询失败"，后者非零退出。**注（票 05 起）**：旧写法是"源库查询**全部读完才开始写库**，不会产生'读一半写一半'"，改成逐行消费后落库与读源库**交错**——中途失败会留下已落库的那几批，靠落库幂等（重跑按 `(company, djbh)` 跳过已有的，补齐剩下的）兜住，门卫"基线只在整轮成功后写"也随之兜住（抛异常即不写基线 → 下轮重采）
- **页面**（`views/upload_tasks.php`）：表格加"所属企业"列，`未识别` 行标红 + 红色徽标；零售行（`source='retail'`）走**补传按钮**（工单 06 落地，取代工单 03 里那个被关掉的重传按钮）；来源下拉补 `零售采集`（任务状态与批发共用 `等待上传`/`已处理` 两个值，故**选门店 + 默认状态即能看到门店单据**）。**已上传的单也先在这一页出现**（2026-10-05 起一律建任务，由平台核查翻正后才离开队列）；翻正后它的痕迹在**已上传记录页**、来源显示「外部上传」（`App\LogSource` 词表里的 `retail_external`）
- **实测（2026-10-02 首跑，票 02，旧口径）**：当日 45 张门店单 → **4 条外部上传记录 + 41 条跳过**（41 张是此前已采进队列的未上传单）+ 0 条新任务；原地重跑 → 45 条全跳过、0 新增（幂等）；源库核对：那 4 张在状态表里都是 `bill_state=1`、抽样的未上传单都不在表里；页面核对：4 张已上传的单按单号在**任务页搜不到**（0 条）、在**已上传页搜得到**（来源「外部上传」）——**这一格 2026-10-05 起反过来了**（那 4 张会先建任务、等平台核查翻正）
- **实测（闭环，2026-10-02 票 03，旧口径；在项目副本上跑，未动生产库与源库）**：副本库里造 6 条待办痕迹（任务行 / 失败记录 / 两种都有 / 已有成功记录 / 批发对照 / `未识别`）→ 跑一轮：**清单 6252 条（真实积压量，不是几十条）→ 翻正 12 行任务、追加 13 条记录**；逐条核对：任务行翻 `已处理`+`上传成功` 且 `request_status` 仍为 NULL、**已有成功记录的那个键不追加**、**批发那条失败记录一个字段未动**（企业维度筛掉了）、失败页按真页面口径（`App\RecordQuery::TYPE_FAILED`）对三条失败记录**全部 0 行**、已上传页能看见追加的记录（`request_status=NULL`、`task_id=0`）；**原地重跑 → 翻正 0 行、追加 0 条**（幂等）。性能：清单 6252 条 → 13 个 IN 块，**单独跑闭环 6.3 秒**。**这套记录的动作语义没变**（平台核查用的就是同一对函数），变的是判据：源库状态表 → 问平台

- **实测（计数门卫，2026-10-02 票 04，旧口径——当时基线是三个数；在项目副本上跑、连真源库只读，未动生产库与源库）**：**首跑**（副本无基线）→ 照常采集 55 张 → 落基线 `{"date":"2026-10-02","total":55,"uploaded":4,"unuploaded":51}`（`total` 与脚本自己那句"拉取到 55 张单据"**逐字相等**——**这一步顺带揪出票面示例 SQL 的一个坑**：`count(*)` 数的是 `zsm_ls` 的**行数**，当日实测 111 行 vs 55 张单（321 平均 2.08 行/单），左表不去重数字就翻倍）；**二跑** → 打印"与基线一致，跳过本轮采集"、**退出码 0**；**动基线**（total 55→54）→ 重新采并翻正基线；**基线损坏** → 视为无基线照常采、被合法内容覆盖；**采集失败**（副本里把采集 SQL 的列名改错）→ 退出码 1、**基线一字未动**（下一轮据此自动重采）。**2026-10-05 起基线形状是 `{date,total}`、打印的话是"总数 N，与基线一致，跳过本轮采集"**——那三个数的比对与落库不再存在（ADR 0018），上述其余分支（失败不写基线、计数失败照常采、`--all` 绕过）一字未变

### 零售单据上传（补传 + 手工新增，App\RetailRetransmit / App\RetailManualEntry）

零售单据由外部系统上传，本项目只做"可见 + 人工补传"（ADR 0007）。补传**只能人工触发**——没有 cron、没有自动重试：
向平台的每一次申报都不可逆，由人在页面上看清是哪张单再点，比自动重试可靠。落库口径、三列入库与失败算不算处理完的决策见 `docs/adr/0011`；**用哪套凭据不由人给**（门店与凭据 1:1，服务端按门店取）见 `docs/adr/0012`。

`App\RetailRetransmit` 是**"上传一条门店单据"的唯一实现**（补传与手工建单共用）：三关 fail-closed → 拆单 → 调用 → 写日志 → 翻任务状态 → **全部子单成功后回写源库 update_state**。端点各自只做「解析请求 + 流式输出」，流程在类里。手工建单的落库与上传走 `App\RetailManualEntry`，它再把上传交给它。

- **入口**：三个 API 入口——上传任务页零售行（`source='retail'`）的"补传"按钮（`api/tasks_retry_retail.php`，工单 06；**门店手工建出来的行也在这个入口里**，因为它同表同来源）、**上传任务页的"批量重传"**（`api/tasks_batch_retry.php`，工单 15；勾选多行后按 `source` 分流，零售行逐条走本链路）、手动上传页门店分支的在线新增 / xlsx 导入（`api/manual_create_retail.php` / `api/manual_import_retail.php`，2026-10-01）——**另有 CLI 批量上传脚本备而不用**（`scripts/upload_pending_retail.php`，见本节最后一条）。补传那条先弹窗列出单据元数据（单号/日期/类型/门店/码数）→ 确认即传，**页面上没有任何要填的字段**；元数据全部取自采集时落库的记录，不接受调用方传任何单据字段（手工录 4 个平台 ID 几乎必然出错）。**手工新增是它的例外**：没有源表行可取，`from/to/physicType` 只能现场确定——但同样不由人录 ID，人填的是往来单位名称、由服务端查出 ent_id（`App\EntDirectory`），见 `docs/adr/0015`
- **批量补传：清单已撤、能力回到上传任务页**（工单 14 撤清单 → 工单 15 补出口）：撤掉的是手动上传页那份 `tasks_batch_retry_retail.php` + 门店清单（它是上传任务页的第二个实现，由"采集 + 上传任务页按门店筛"覆盖）；**批量补传这条路本身没有取消**——2026-10-01 由上传任务页的"批量重传"按行分流承接（见上条"入口"与工单 15）。**xlsx 导入是另一回事**——它是从零建单，不是对已有任务批量重传
- **日志来源：补传写 `retail_retry`、手工建单写 `manual`**（`RetailRetransmit::retransmit()` 的来源参数）——补传与新建是两件事，来源列上要分得清；两者都用已存在的取值，三个页面的标签/徽标/下拉不必各加一处
- **回写源库 update_state（工单 18，见 `docs/adr/0016`）**：**全部子单成功后**往 `dyt.bs_msfx.dbo.update_state`（表名见 `App\RetailExternalUploads::TABLE`）写一行 `(原始单号, '1')`——告诉外部系统"这单传过了"（这也是它**推翻 ADR 0007"从不回写"**的那一条）。**2026-10-05 起它是那张表在本项目里唯一活跃的用途**：读侧（采集分流、状态闭环、门卫的两个数）已随 ADR 0018 停用，回写保留——它服务的是外部系统，不是本地判据。写的是**原始单号**（拆分的 `_N` 后缀对那张表没有意义）；`上传成功` 与 `单据重复` 都算成功（单据已在平台上），任一子单失败则**不写**——平台上只有半截，写了会让外部系统永不处理它。落点是 `App\UpdateStateWriter`：幂等靠 `INSERT ... WHERE NOT EXISTS`（该表无主键、无唯一约束，**已有同号多行先例**），**绝不能包本地事务**（写链接服务器起不了分布式事务，MSDTC 被禁——2026-10-02 探测实证），**写失败只记 JSONL 警告**（`type=update_state_write_failed`）、不改上传结果与任务状态（上传已不可逆，这是尽力而为的后续动作），警告也**不进 `upload_logs`**（那会污染失败记录页这个唯一告警出口）。连接登录超时取 5s（比 `SqlSrvHelper` 默认的 30s 短）：源库不可达时每条成功单都要卡一次，30s × 一屏单据会让操作者以为页面死了
- **链路**：`Enterprise::route()` 给的接口与码上限 → 超限才拆单（沿用 `单号_1` 约定；实测零售单张码数上限 1,718，不触发）→ `RetailRequestAssembler::assemble()` → `ApiClient::execute()`（间隔 400ms、仅网络错误重试 3 次/30s、业务错误不重试；**顶层限流也算网络错误**，见"业务编码映射 → API 重试"）→ `LogWriter` 写 JSONL + `upload_logs`（`source='retail_retry'`，带 company/credential/task_id）→ 翻 `upload_tasks`：`task_status='已处理'` + `request_status`/`response_status`/`resp`，并把该行 `credential` 覆盖为**这次实际用的那套**（采集预填该门店那套，这里写回的是同一套——单套时代这一写不改变取值，只是把事实记下来）
- **三关 fail-closed 都在第一次平台调用之前**（非零售企业 / 门店无凭据位或未配齐 / 无路由或装配必填项缺失），任一不过即整条拒绝：不发一次调用、不写一条日志、**任务行一个字段都不动**（实测：拒绝后 `updated_at` 不变）。页面上的禁用态（未识别 / 待配凭据）只是显示层提示，真正的关口在链路里——任何直接调端点的路径都拦得住
- **批量入口的分流与口径（工单 15）**：`api/tasks_batch_retry.php` 按行的 `source` 分流，判据**不是企业类型**（`未识别` 行也在 retail 那一份里，会被三关**逐条**拒掉，而不是像分流前那样把整批带下水）。两处刻意的非对称：**批发那批被守卫拒绝即整批打住**——零售那部分一行都不动（不传、不复位），去掉坏行再点一次；**零售逐条隔离**，被拒的行算**失败**并发一条与真实结果同形状的进度行。`_final.result` 是**合并数 + 两段明细**（`total` = 本批任务行数；`success`/`failed` = 批发子单 + 零售单据，与进度流里前端边跑边数的口径一致，跑完数字不跳变，前端因此不分叉）。空批次**不调** `UploadService`——那是给它取 flock 用的，纯门店批次不该被正在跑的 cron 上传挡住。跨门店勾选允许（凭据按行取），聚合数仍是一套。**失败记录页的"重传关联任务"打的是同一个端点**（响应体它不看，跑完就刷新列表），故零售行同样按 `source` 分流——改动前它对零售行是**静默空转**（守卫整批拒绝、页面无任何反馈，且那一抛会把该行的 `request_status`/`response_status` 抹成 NULL）；该页的确认框**没有**这三处点名（本票范围只到上传任务页）
- **异常时不复位任务状态**（批发链路的单条重传会复位、批量重传只复位**批发那批**，本入口一律不复位）：任务行只在平台调用**之后**被写，故异常要么发生在第一次调用之前（没动过，复位是空操作）、要么发生在某个子单已完成之后（那一写就是本次尝试的真实结果，复位反而把结果抹成 NULL）
- **进度里的"成功"按业务结果算，不照搬 `ApiClient::execute` 的 `success`**：后者是网关级（无 `code` 错误即 true），实测平台对"存在已出售的码"这类业务拒绝也返回 `success=true` + `msg_code=FAIL`，照搬会把真实失败显示成绿色 [成功]、汇总写成"成功 1"。口径与已上传页/失败页一致：`上传成功` 与 `单据重复` 都算成功（单据已在平台上），其余算失败
- **实测（2026-09-30 首次真传）**：单条两单 + 批量一批四张（批量入口当时还在）。批量那次是新江分店 4 张 `321`，全部 `上传成功`（4 行 `upload_logs` 带 `source='retail_retry'`/门店/凭据/task_id，4 行任务翻 `已处理` + `上传成功`，该门店未处理单 24 → 20，已上传页按门店名可辨认）。**104/203 刻意未真传**：ADR 0010 的 `fromUserId`/`toUserId` 发货收货语义仍待外部系统工程师确认，传错方向会在平台上留下错误申报（装配正确性目前由工单 05 的纯函数断言兜着）。两个 lsyd 接口的响应都是同一套 TOP 信封（`result.msg_code` / `msg_info` / `response_success`），故 `ApiClient::resolveUploadResponseStatus` 两个接口族通用——`SUCCESS`+`response_success=true` → 上传成功；`msg_info` 含"该单据号已存在" → 单据重复；`msg_code=FAIL` → 上传失败。平台对**已被申报过的销售单**返回业务错误「存在已出售的码」（→ 上传失败），即补传不是"重放"而是真实申报
- **失败也算"已处理"**（与批发链路一致）：任务表是待处理队列，`上传失败`/`未确定`/网络请求失败都翻 `已处理`，`等待上传` 队列不会无限堆积；"补传没成功"的出口是失败记录页（该页第一条条件 `response_status NOT IN ('上传成功','单据重复')` 挡住 `单据重复` 自身，实测零售的 `上传失败` 记录确实可见）。因此补传失败后要再试，是在任务页按"已处理"筛出该行重传，不是等它回到 `等待上传`
- **批量上传脚本备而不用（票 06，2026-10-02）**：`scripts/upload_pending_retail.php` 把「等待上传」的门店单**逐条**交给本链路（取数口径、`--limit` 与 `--dry-run` 的判据都在 `App\RetailBatchUpload`），**默认不挂 cron**、由人手手动跑——它一旦进 crontab，ADR 0011 那条"补传只能人工触发、没有自动重试"即被反转，故**启用与否留给人**（见该 ADR 的补注）。它只扩"一次能走多少条"，不放松任何一关：三关 fail-closed 照旧、逐条隔离（被拒的算失败并继续下一条，与上传任务页"批量重传"里零售那批同口径）、日志来源仍是 `retail_retry`、回写与任务翻转全在 `RetailRetransmit` 里，本脚本一行上传逻辑都不重写。另有 `--company=k1,k2`（2026-10-02 加）按**企业 key** 限定只传哪几家——**不给这个参数就是把队列里所有门店的待办都真传出去**；过滤在**取回之后**做（`PENDING_SQL` 那套取数口径本身不变），未知 key 直接退出 1（打错一个字母与"队列本来就是空的"在日志上长得一样）。**首次交付一次真申报都没发**：验证在项目副本 + 本地离线桩上做（生产库与生产源库零写入已实测），生产环境的首次启用是另一个决定——**2026-10-02 已首次真跑**（用户点名的 4 家门店，2,861 条待办逐条补传，结果见当日 JSONL 与失败记录页）

### 零售单据平台核查（check_bill_status_retail.php，2026-10-05 建；**票 02 起挂 crontab：`20,50 8-21 * * *`**）

**为什么要有它**：门店单"是否已上传"原本只有一条判据——源库状态表（状态闭环，每轮采集前跑）。那张表记的是**外部系统的行为**，不是平台的承诺：外部系统传了单却没写它，本系统永远不知道，任务一直挂在「等待上传」，人只能赌一次补传；而且它**没有企业列**，判据只能按裸单号比对（跨门店同号会串，ADR 0007 记的已知代价）。本脚本补第二条判据：**拿各门店自己的凭据直接问平台**。

> ⚠️ **2026-10-05 起它是唯一判据**（`docs/adr/0018`）：外面那条源库状态表判据（采集分流 + 状态闭环）因外部系统不稳定而停用，采集改成"一律建任务、由本脚本翻正"。**它的覆盖面就是系统的覆盖面**：取不到凭据的门店（9 家待配 + `未识别`）这条判据不成立，那批单会一直挂在「等待上传」——本脚本每轮开头那句"跳过 N 家门店 M 条"因此是运维要看的数。

- **接口**：`alibaba.alihealth.drugtrace.top.lsyd.query.upbilldetail`，参数 `bill_code` + `ref_ent_id`（请求类逐类并入，见上方 vendored 约定）。**带 ref_ent_id 意味着这条判据天然按企业隔离**——同一个单号在甲店查到、在乙店查不到是两个独立问题，这正是状态表做不到的那一格。响应形状与 kyt 的 searchbill.detail **同款**（2026-10-05 实测：已上传 `msg_code=SUCCESS`+`response_success=true`+`model` 有单据详情；未上传 `FAIL_BIZ_NO_PAT_INFO`+`信息不存在`），故判据复用 `ApiClient::isBillFound()`，不另写一份
- **两个来源合一**（等待上传的门店任务 + 零售企业的非成功日志记录）：都要按门店分组取凭据、都要限速，拆成两个脚本只会把这套逻辑写两遍。取数走 `RetailExternalUploads::pendingItems()`——"哪些单还算待办"全仓只有一个答案
- **取不到凭据的门店整组跳过并计数**（`未识别` / 待配凭据 / 未声明凭据位 / 混进来的批发主体——判据含 `Enterprise::isRetail`，fail-closed）：没有 ref_ent_id 这条判据不成立，拿错凭据查是白烧调用。**2026-10-05 起"跳过"就是真的没人管了**（外面那条不要凭据的源库闭环已停用，见 ADR 0018）——这批单会一直挂在「等待上传」，等凭据配齐
- **翻正动作复用既有那对函数**（判定与落库走 `closureActions()` + `applyActions()`）：任务行翻「已处理」+「上传成功」（`request_status` 不动——本项目没发起过上传请求）；失败记录**追加**一条「外部上传」记录、不改写历史（失败页靠既有的同单号判重自动隐藏它）。出处的 `judged_by` 写平台接口名、`reason` 写"平台查询确认…"
- **企业隔离的落点**：`RetailPlatformCheck::actionsByCompany()` **按企业分别调** `closureActions()`——一次喂全量集合就是跨门店串号（停接的闭环当年只能喂扁平的裸单号，那是源库状态表没有企业列的结构限制；平台判据这边不必继承它）
- **查询异常只跳过、不修改**（"不知道"不等于"没上传"，与闭环"源库查不通一条都不翻"同理）；逐条 try/catch 隔离（各门店 AppKey 独立，某家出问题只该影响它自己）；`usleep(500000)` 每条（与批发查询同速）
- **逐单新鲜度门卫 30 分钟 + touch 批量写（票 02，2026-10-05）**：门卫的判据就是 `last_checked_at`，而票 01 一个字节都没写它（当时"不 touch"）——**两者必须同时补**，否则所有行的 `last_checked_at` 恒为 NULL、「NULL 或已过期」永远为真，门卫形同虚设。做法：过滤下在取数侧（`pendingItems(30)`），记账下在落库侧（`RetailExternalUploads::touchChecked()`，**一次事务**批量刷两张表；逐条 UPDATE = 一次 fsync 21–28ms，一趟 1,400 条光等磁盘就要 30 秒往上）。touch 的范围是**平台给了答复的键**（已上传 ＋ 未上传都算）：查异常的**不刷**（"不知道"不算查过，下一轮 cron 自动重查）；`--dry-run` 为空（一次都没查，凭什么记账）。被门卫挡下多少条打印在开头——与采集侧计数门卫同一个立场：日志要分得清"真没待办"与"待办都在门卫窗口内"
- **挂 crontab（票 02，用户拍板）**：`20,50 8-21 * * *`——**只跑白天**。门店单据的流转发生在营业时间，夜里那几轮查到的多半是白天已查过的同一批（白烧调用还可能撞限流）；隔夜新单由次日 8:20 那轮补上（门卫 30 分钟，隔了一夜早过期）。零售用**自己的 AppKey**、不受批发 8-20 点窗口约束，技术上本可全天——**"只跑白天"是刻意的**，要改时段就改这一处。分钟点 20/50 避开 fetch_bills 的 0/30 与 fetch_bills_retail 的 5/35；但一趟约 22 分钟（2026-10-06：可查 2,081 条 × 实测 0.64 秒/条）**会跨过下一个 :30 采集轮**，它真正与人抢锁的只有收尾那次亚秒级 touch 事务，`busyTimeout(30s)` 兜着（详见 cron 表那行）。**可查条数再涨要重新掂量这个组合**——一趟逼近 30 分钟时，下一轮 cron 会撞上 flock 直接退出（打印"已有实例在运行"），轮与轮首尾相接。⚠️ **门卫 30 分钟 + cron 30 分钟 = 同一批未上传单约每小时被查一次**（批发就是这个组合，效果一样）；门卫真正防的是"手动跑过一次后 cron 又来"以及将来频率调密时的重复调用。**挂 cron 不触碰 ADR 0011**——本脚本只查询、不申报（`upbilldetail` 是只读接口）
- **（历史）与状态闭环的分工**：闭环查源库状态表（免费、每 30 分钟一轮、按裸单号、**不受门卫约束**），本脚本查平台本身（带 ref_ent_id、按企业隔离、花调用、受门卫约束）。**闭环 2026-10-05 起停接**（ADR 0018），本脚本成为唯一判据——它的一轮约 22 分钟（2026-10-06）与 30 分钟门卫共同决定了"刚采进来的单多久被翻正"（白天最快约半小时，跨夜的单要等次日 8:20）
- **手动跑仍然可用**：`--dry-run` 看清单（列的是**过完门卫**的那份，与真跑一致）、`--limit=N` 小步走、`--company=名,名` 限定门店。它**只发查询、不发申报**，但仍是真实平台调用
- **实测（2026-10-05，项目副本 + 生产源库只读；生产库零写入）**：`--dry-run` 全量 → 本地待办 **4,497 条 / 14 家企业**，可查 5 家 **1,410 条**、跳过 9 家 2,087 条（含 `未识别` 51 条——它没有凭据）。新江分店真跑 **47 条**（45 条真实待办 + 2 条自造场景）：**已上传 4 / 未上传 43 / 异常 0**，翻正任务行 2 行、追加记录 3 条。逐项核对：自造的两条（平台上确实存在）一条走"翻任务行+追加"、另一条（本地已有成功记录）**只翻任务行不追加**；**真实发现 2 条**"补传失败但平台已有"的单（`XLSA0200100180956` / `...183018`，正是本功能的价值场景）；两条任务行的 `request_status` 均保持 NULL、`resp.judged_by` 是平台接口名；追加记录的 `task_id=0`、`request_status=NULL`。**重跑** → 已上传 2 / 翻正 0 行 / 追加 0 条（幂等：那两条失败记录因"已有成功记录"被判无动作）
- ⚠️ **已知代价**：被翻正的失败记录**仍留在待办清单里**（它的 `response_status` 还是失败，`pendingItems()` 照取），故每轮过了门卫窗口又会被重查一次——判无动作、不会重复追加，但白花一次调用（票 02 的门卫把重查频率从"每次跑"压到"每 30 分钟一轮"，没有根治）。要根治得改写那条历史记录（本项目刻意不做：失败记录是"补传失败过"的事实）

### 批量查询上传状态（check_bill_status.php + check_failed_logs.php）

两脚本共用同一套查询/更新语义，仅调度频率不同，各带独立 flock 锁（`logs/check_bill_status.lock`、`logs/check_failed_logs.lock`，`LOCK_EX|LOCK_NB`，锁被占用直接退出防并发）。**两者一律只查批发主体**（`company` 白名单，同 upload_pending 的理由）：它们用河药凭据查平台，拿门店单号去查只会得到"信息不存在"、白烧调用还可能把状态翻错。

**check_bill_status.php（来源 1：等待上传任务，高频）**：查询 `upload_tasks`（task_status='等待上传' **且 company=批发主体**）带 `last_checked_at` 新鲜度门卫（`last_checked_at IS NULL OR last_checked_at <= 阈值`，阈值常量 `CHECK_INTERVAL_MINUTES = 30`）→ 逐个调 `ApiClient::searchBillDetail()`（API 间隔 0.5s）→ 已上传的标记任务已处理 + 写 upload_logs（source=batch_check）+ JSONL → 未上传（信息不存在）的仅更新 `updated_at` → cron: 8-20 点每 30 分钟一次（与门卫阈值一致）。每轮跑不完是可接受状态（只剩一个队列，下一轮续跑即可）。

**check_failed_logs.php（来源 2：失败记录，低频）**：查询 `upload_logs`（response_status IS NULL 或 NOT IN ('上传成功','单据重复') **且 company=批发主体**）带同样门卫 → 按 `djbh` 去重（首次遇到胜出，同单多条失败记录只查一次 API）→ 逐个 `searchBillDetail`：平台存在 → 记录翻转为"上传成功" + 同步关联 upload_tasks（task_id>0 标已处理）+ 写 JSONL；信息不存在 → 仅更新 `updated_at`/`last_checked_at`；API 异常 → 跳过不修改 → cron: 每天 20:40。作用：外部系统补传后失败记录页自动干净（配合 failed.php 的 NOT EXISTS 逻辑）。

循环内"已确认在平台跳过"（SQLite 已有上传成功/单据重复记录，按 `(company, djbh)` 判重）时不调 API：check_bill_status 对任务直接标记"已处理"（任务目标已达成，避免停留在"等待上传"被反复拉取/重传）；check_failed_logs 保留历史记录不动。

**零售记录刻意留在失败记录页**（`api/failed.php` **不加** company 过滤）：零售的 `upload_logs` 只可能由人工补传产生（三个检查脚本写不出零售日志），所以失败页上出现的零售记录必然是人工补传失败——那是操作者唯一能看见"补传没成功"的聚合出口。见 `docs/adr/0007`。页面与导出（`api/export.php` 的 `type=failed`）的判重口径一致，都限定同一企业。

`last_checked_at` 更新规则（**两个脚本在"已确认在平台跳过"那一格刻意不同**，别把它们当同一条规则）：API 查询成功（含"信息不存在"）**都会 touch**；"已确认在平台跳过"时 `check_bill_status` 会把任务标记 `已处理` 并 touch（任务目标已达成，避免停在"等待上传"被反复拉取），而 `check_failed_logs` 一个字都不写（历史记录不动，见上一段）——**因此它也不 touch**；仅 API 异常不 touch，下次 cron 自动重查。新采集/新建任务的 `last_checked_at` 为 NULL，天然立即查。

### cron 时间表（全部检查类脚本错峰，8-20 点窗口只跑 check_bill_status）

| 脚本 | cron | 说明 |
|------|------|------|
| fetch_bills（批发采集） | `0,30 0,1,2,3,8-23 * * *` | 写库与检查脚本的 SQLite 锁冲突由 busyTimeout(30s) 兜底 |
| fetch_bills_retail（零售采集） | `5,35 0,1,2,3,8-23 * * *` | 与 fetch_bills 同频、**错开 5 分钟**（同为写 SQLite 的进程，同刻写会撞上 `Database::__construct` 里 `PRAGMA journal_mode=WAL` 那道无 busyTimeout 的既有竞态窗口 → Web 端 500 "database is locked"）。**不调平台 API，不受 8-20 点限流窗口约束**，故时段照抄 fetch_bills（含 8-20 点）。**每轮跑计数门卫**——当日总数没变就跳过整轮并打印原因，日志里因此分得清"真没新单"与"脚本没跑"。（**状态闭环 2026-10-05 起停接**，那条"闭环在前、门卫在后"的顺序红线随之失效，见 ADR 0018） |
| check_bill_status（来源 1） | `*/30 8-20 * * *` | 高频确认新单（与门卫阈值 30 分钟一致） |
| check_bill_status_retail（门店平台核查） | `20,50 8-21 * * *` | **2026-10-05 票 02 挂上**：只跑白天（门店流转在营业时间；隔夜新单由次日 8:20 那轮补上）。起点避开 0/30（fetch_bills）与 5/35（fetch_bills_retail）。**只发查询、不发申报**，挂它不触碰 ADR 0011；**逐单门卫 30 分钟**与批发同值。⚠️ 一趟约 22 分钟（2026-10-06：可查 2,081 条 × 实测 0.64 秒/条），**会跨过下一个 :30 采集轮**（8:20 起跑、约 8:42 收尾）——它的写只有收尾那**一次亚秒级事务**（批量 touch），撞锁由双方的 `busyTimeout(30s)` 兜底（与 check_bill_status 同一处境），不是"不重叠"；别照着这条去调别的脚本的时段。**一趟逼近 30 分钟时下一轮 cron 会撞 flock 退出**（首尾相接，不是停摆，但"每 30 分钟一轮"的说法就不再成立） |
| check_failed_logs（来源 2） | `40 20 * * *` | 20:40，fetch_bills 20:30 轮已结束、21:00 轮未到 |
| check_quantity（数量对账） | `10 21 * * *` | **当前未调度**（手动运行）；下表值仅为恢复调度时的建议时间——21:10，fetch_bills 21:00/21:30 两轮之间；数量对比（shl vs min_pkg_count 求和），~650 单约 13 分钟 |
| cleanup_logs | `0 3 * * *` | 三条清理判据不同：日志 3 个月前（`created_at`）、已处理任务 3 个月前（`updated_at`）、**门店超期单据 2 年前（`rq` 单据日期）**——后者是"今天合法的单据两年后就不合法了"的唯一出口，见 `App\RetailRetention` |

**注（2026-09-30 核对 root crontab 现状）**：`fetch_bills`、**`fetch_bills_retail`**（工单 03 交付的条目已人工装上，当日 08:05/08:35/… 的采集记录可见）、`check_bill_status` **都在跑**；`check_quantity` **未调度**、仅手动运行。改动本表前先 `crontab -l` 核对，别照抄文档。

**注（2026-10-02 票 07 核对，`crontab -l` 只读）**：**`upload_pending.php` 也不在跑**——它的三条条目（12:05 / 18:05 / 22:30）在 crontab 里**全部被 `#` 注释着**，上方注释写的是「上传等待中的任务(暂不启用)」。**本表因此不列它**，而"定时上传"那节描述的是**该脚本的行为**，不是"它现在正在定时跑"——批发"等待上传"的任务眼下只能从 Web 端手动传。另：`upload_pending_retail.php` 同样不进本表（见下一注）。

**注（票 06，2026-10-02）**：门店单据的批量上传脚本 `upload_pending_retail.php` **刻意不进本表**——它一旦进了 crontab，`docs/adr/0011` 那条"补传只能人工触发、没有自动重试"就被反转（申报不可逆，得有人在发起之前看得见清单）。**别把它加进去**：要用就手动跑，先 `--dry-run` 看清单、再 `--limit=N` 小步走。

**注（2026-10-02 起：本项目所有 cron 条目以 nginx 身份跑）**：root crontab 里本项目的 10 条（6 条在跑 + 4 条注释着的）全部包成 `su -s /bin/bash nginx -c '/usr/bin/php …'`，重定向留在 root 那侧（`/var/log/msfx_cron.log` 仍 root:root）。**为什么**：root 与 nginx 双身份写 `logs/`、`data/` 会造出对方写不进去的文件（`logs/api_<日期>.jsonl` 一天一个文件，被 root 建出来当天就废；SDK 的 `top_*.log` 同理，见上方 TopLogger 一节）。**加新条目照此办理**；**手工跑脚本也必须同身份**（`su -s /bin/bash nginx -c 'php …'`），跑完用 `find logs data -user root` 复查——有输出就说明又造出了 root 属主文件，`chown nginx:nginx` 归位。

**注（票 02，2026-10-05：挂 cron 前先看锁文件的属主）**：这次要挂的脚本带 `flock`（`logs/check_bill_status_retail.lock`），而那个文件是**票 01 期间以 root 跑出来的**（`root:root 0644`）——nginx 身份下 `fopen($lockFile, 'w+')` **打不开**，脚本会走"已有实例在运行"那条分支**打印一句然后 exit 0**。表现是"cron 装了、日志里天天有一行、却从没真跑过"，比报错难查得多。挂条目时一并 `chown nginx:nginx`（本次已做）。同理适用于任何**新建的、将来要挂 cron 的**脚本：先确认它要写的文件（含锁、基线）在 nginx 名下。

覆盖保证：任何单据最终都会被查到平台状态（等待上传 ≤30 分钟 / 失败记录 ≤24h / SQL Server 全量 ≤24h）。check_quantity 与 check_failed_logs 不得改到 8-20 点窗口内运行（与 check_bill_status 并发调同一 AppKey 立即触发平台限流）。

### 数量对账（check_quantity.php，两级流水线）
定位：外部系统负责上传时，本项目只检查上传情况、不补传。**查询范围仅针对 check_bill_status 已检查过且状态是"上传成功"的批发主体单据**（upload_logs `source='batch_check' AND response_status='上传成功' AND company=批发主体`，按 rq 筛选）——未上传的单据由 check_bill_status 以任务状态（等待上传）反映，数量对账不重复查询/告警；零售没有本地数量基线（`SUM(shl)` 来自 `skwms_new` 明细视图）也不做平台对账，故不参与（见 `docs/adr/0007`）。幂等清理同样限定 company，清理范围与写入范围一致。

**第 1 级（快，全量，SQL Server 聚合）**：逐单依次查询平台原始单号 → `_1` → `_2`...（上限 10 次），跨拆分子单累加平台申报数量（`ApiClient::sumBillDetailCount()`：累加 `min_pkg_count`），与本地应有数量对比（`TaskFetcher::fetchBillQuantitiesByCodes()`：明细视图 `SUM(shl)` 聚合，轻量查询不写库）。**比较口径统一为最小包装单位数**：本地 `shl` 即"已展开的最小包装单位数"（整件行 `shl = baozhshl × jlgg`、零散行 `shl = lingsshl`，见 ADR 0004——推翻早期"两数量纲无法统一"结论）。**基线剔除本地非药品行**（jixing 含商品/食品/消杀/用品/器械/化妆品/消毒剂/敷料/试剂/材料/设备等，spkfk 查不到剂型的行保守保留）——平台是药品追溯平台，外部系统按平台规则不申报非药品。**查询策略"相等即停，不等查尽"**：原始单号查到且数量相等即停（未拆分大头单 1 次调用）；原始单号查不到或数量不等继续查子单，防止"原单号+拆分并存"漏计。**"数量不符"嫌疑单仅收集在内存（不写库）**，其余分支照旧：相等 → 传齐零记录；全序列查不到 → `信息不存在`（防御分支：batch_check 已确认上传成功但平台查不到）；无法核对跳过（不写任何记录）——本地 `SUM(shl)` 为 NULL（明细视图无行）、平台响应解析失败（`sumBillDetailCount` 返回 null），不误报。

**第 2 级（慢，精查，仅嫌疑单，singlerelation 码级口径）**：逐码调 `ApiClient::searchSingleRelation()` 把本地追溯码折算成平台"最小溯源单位"系数（`ApiClient::sumPkgAmount()` 解析，单一事实源），Σ 系数与第 1 级 actual（跨子单 min_pkg_count 累加）同口径对比——**本地零售规格 ≠ 平台注册规格的结构性口径差异（青霉素钠 20 瓶 vs 1 盒、氨咖黄敏胶囊 10粒/盒 vs 500粒/盒等，ADR 0004 判定的"本阶段无更优解"遗留硬伤）在此消除**。**码基线来自 wms_dzjg 现查**（`TaskFetcher::fetchWmsCodesByDjbhList`，2026-08-26 加固——batch_check 快照缺采集后手持扫码补录的大包装箱码（整件只有大码的氯化钠/葡萄糖 40/50/120瓶/箱），实测 25/25 "数量不符"判定全为此类假阳性；check_quantity 21:10 运行晚于发货补录，现查天然规避；现查为空回退快照基线；"数量不符"响应附 `code_source`/`codes_checked`/`base_codes` 便于识别）。**折算规则（2026-08-26 加固）**：`is_smallest="Y"`（该码即平台最小溯源单位）→ 恒取 1、忽略 pkg_amount——反例实测：葡萄糖注射液 120瓶/箱 箱码 is_smallest=Y 但 pkg_amount=120（整件只有大码、内部 120 个最小单位无追溯码，注射液类常见），120 是注册规格非可对账单位数，平台 min_pkg_count 对该码按 1 计，pkg_amount 直取会误判"数量不符"；is_smallest="N"/缺失 → 用 pkg_amount（大包装码=100、中包装码=5/20、最小单位码=1）。核心等式 `Σ singlerelation(本地每个追溯码).折算系数 == min_pkg_count` 2026-08-26 探针实测成立（50/50、240/240 全等），设计见 `.scratch/quantity-check/singlerelation-tier2.md`。判定：**双方案都有差异 → 真问题**，写 `数量不符`（expected=Σ 码级折算，response 存 `{djbh, rq, expected, actual, sub_bills:[{djbh, count}], stopped_early, code_source, codes_checked, base_codes}`）；**单方案有差异（第 2 级相等）→ 规格口径噪声，不写库** → Web 失败记录页零噪声；**码查询失败/无法核对（理论不存在，实测零次）→ 跳过不写库**（Σ 不完整判定不可信，不误报）。**"超过即停"优化**：累计 Σ 一旦 > actual 即确定"本地多于平台"立即停（所有码系数 >0 不可能回落相等；只有相等/偏少才需查完全部码，嫌疑单平均 ~16 码）。第 2 级限速 500ms/次（1 秒 2 次，spec 实测确认；与 searchbill.detail 同 AppKey 限流池）。

**2026-08-15 全量实测**（589 单）：传齐 527 / 第 1 级差异 62 → 码级精查后**真问题 0 / 规格噪声排除 62** / 无法核对 0 / 异常 0（2026-08-27 码基线现查加固后重查结果）。历史结论修正轨迹：旧版快照基线时代实测"真问题 17 / 规格噪声排除 45"，2026-08-26 复核发现 25/25 判定（含 17 条）全为"采集后补录"假阳性（batch_check 快照缺采集后手持补录的大包装箱码，差 1-8 恰等于补录码数）——**差 1-5"平台申报 > 本地码折算"方向的最可能成因是本地码基线缺码而非外部多传**，旧"外部系统多传/混码"方向经验作废；运维看到"数量不符"且 expected < actual 时先核对 wms_dzjg 现查码数（response 的 code_source/codes_checked/base_codes 可辨识），现查折算 == 平台申报即假阳性。

**幂等**：每轮先清理目标日期全部 quantity_check 记录再按新判定写入（限流熔断后下次运行重查不产生重复/残留记录，历史"数量不符"误报随重跑自动清除）。第 1 级 API 间隔 1s；两级任一处平台限流（App Call Limited）时**本轮熔断**，剩余单据下次运行自动重查。**运行时机**：必须避开 check_bill_status（8-20 点每 30 分钟一轮）的调用窗口，否则并发触发平台限流，cron 建议配 21:10 每天一次（详见上方 cron 时间表，**当前未调度**），默认检查昨天（参数可指定日期）。该检查顺带修正 check_bill_status 的盲区：外部系统拆分上传后原始单号查不到被误判"未上传"的场景，数量对账的运行时子单查询能识别子单已传齐。

### 手动上传（Web 端，顶部先选"所属企业"）

页面最上方是**"所属企业"下拉**（选项来自 `App\Enterprise`，**凭据填齐的加粗黑字、未配齐的正常字体灰字**——见"三数据页"那节第 1 条，全站 6 处下拉同一份规则），选定后显示该企业对应的内容——批发与门店的字段、接口、凭据完全不同，混在一个表单里只会让两边都难读。**两个分支同构**：左边"在线新增"、右边"xlsx 批量导入"（2026-10-01 起，见 `docs/adr/0015`）。两边的提交与导入走**同一段前端处理逻辑**（`bindCreateForm` / `bindImportCard`，差别只有端点、是否带 `company`、"往来单位名称"是否必填），各自只把端点与白名单传进去。

- **批发分支**（默认选中批发主体）：**行为一字未变**——在线新增（日期 / 单据类型下拉 / 单号 / 往来单位 / 追溯码，一行一个自动转逗号）写入 SQLite 后立即上传并实时反馈（`manual_create`）；xlsx 导入（同单号多行自动合并为一个任务，取第一个非空的日期/单据类型/往来单位并拼接追溯码）走 `manual_import`。落库主体取 `Enterprise::wholesaleSubject()`，模板下载给批发版
- **门店分支**：落库主体是**所选门店**，凭据由服务端按门店取（页面不让人选，见 ADR 0012）
  - **单据类型只列门店那四种**（`104` 调拨入库 / `203` 调拨出库 / `321` 使用出库 / `116` 消费者退货入库）
  - **`321`/`116` 不显示"往来单位名称"**：这两种走 `lsyd.uploadretail`，接口里根本没有对手方入参（对手是消费者，不是平台注册的往来单位）。选中时该框隐藏、值清空、`required` 摘掉——只藏起来的话浏览器仍会拦"必填项为空"，用户会看到一个看不见的输入框在报错
  - **`104`/`203` 要填"往来单位名称"**：这两种走 `lsyd.uploadinoutbill`，`fromUserId`/`toUserId` 是平台必填。服务端用**该门店的凭据**去平台查 `ent_id`（`App\EntDirectory`，与批发同一套），按 SDK docblock 的发货/收货语义落位——**入库 from=对方/to=本店、出库 from=本店/to=对方**；`physic_type` 取常量 `3`。**查不到即拒绝建单**：库里不留半条、平台也不发一次调用
  - **落库**：`source='retail'`（**与采集单同列**——上传任务页的"补传"按钮、门店徽标、`cleanup_logs` 的 2 年超期清理因此自动覆盖它）、`company`=所选门店、`credential`=该门店凭据键、`task_status` 随上传结果翻（失败也翻"已处理"，同补传链路）。日志来源写 `manual`（补传记 `retail_retry`）
  - **日期早于 2 年截止日的直接拒**（`App\RetailRetention`，与采集同一个口径）——手工建一张平台必拒的单，不如在建单那一刻就说清楚
  - **xlsx 导入**：列与批发同一套（日期 | 单号 | 单据类型 | 往来单位名称 | 追溯码；解析共用 `App\BillSheetParser`），`321`/`116` 的往来单位列**留空即可**；逐条校验、**逐条隔离**（一条坏单只报它自己并跳过，其余照常导入）；模板下载给门店版（示例行是门店类型、`321` 行往来单位留空）
  - **能不能建单看三态**：待配凭据（等密钥，预期内的正常状态）/ 未声明凭据位（配置缺口）/ 不在配置中——页面上写明是**哪一种**并禁用两个提交按钮。页面只是显示层，真正的关口是 `RetailManualEntry::prepare()` 的 fail-closed（绕开按钮直接调端点的路径照样被拦）
- **建出来的门店单在上传任务页可见**（同一张表、同一个 `source`），行内补传/重传、编辑、删除、导出都在那一页——这就是撤掉手动上传页那份清单的前提
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

文件：`data/msfx.db`，通过 `scripts/init_db.php` 初始化（幂等，可重复执行；三类操作的幂等规则各不相同：加列/建索引/重建表按当前结构判断，**历史行回填只在加 `company` 列那一刻做一次**——重复回填会把零售的合法取值（`company='未识别'`、`credential` 为 NULL 表示待配凭据）误标成河药；**取值归一（`待补传` → `等待上传`）每次运行都生效、未命中即 0 行**，从旧备份恢复出的库一跑就自愈——代价是那个旧值退出词表，不可再被赋予新含义，见 `docs/adr/0014`）。

### upload_tasks（上传任务）
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER PK | |
| rq | TEXT | 单据日期（来自 SQL Server） |
| djbh | TEXT | 单号（去重键是 `(company, djbh)`，不是裸 `djbh`） |
| ent_name | TEXT | 往来单位名称。**采集来的零售行恒为空**（对手方 ID 直接来自源表 `from_user_id`/`to_user_id`）；**门店手工建的 `104`/`203` 会写**（人填的名称，用它查出 `from`/`to` 两个 ID；上传任务页对零售行隐藏这一格，改名称不会重解析 ID），`321`/`116` 仍为空 |
| trace_codes | TEXT | 追溯码（逗号分隔） |
| task_status | TEXT | 等待上传（批发：`upload_pending.php` 会取——但它当前**没挂 cron**，见上方注；**零售采集落库也用这个值**——2026-10-01 统一，见 `docs/adr/0014`）/ 已处理。**门店单的"等待上传"不承诺 cron 会取走它**：`upload_pending.php` 按 company 白名单取数，门店单根本进不去，补传始终由人点 |
| source | TEXT | **retail**（门店单据：`fetch_bills_retail` 采集的与手动上传页手工建的**共用这一个值**——上传任务页的补传按钮、门店徽标、`cleanup_logs` 的 2 年清理都认它，见 `docs/adr/0015`；要分辨采集与手工看日志的 `source`）/ cron（批发采集）/ manual / **batch_check**（只在旧数据里有：任务表里那 40 行全是 2026-07-29~30 的，现行三个检查脚本都不 INSERT 任务行）。**`batch_retry` 只写日志表**（本表一条也没有，页面按它筛只会得到空结果），详见 upload_logs 的 source 行 |
| company | TEXT | 所属企业中文全名（页面"所属企业"列的值与筛选键；`未识别` 表示门店认领失败） |
| credential | TEXT | 该企业那套凭据的键（如 `main`，门店与凭据 1:1）；只作审计，不参与任何键；零售待配凭据时为 NULL；零售补传成功后写回**这次实际用的那套**（单套时代即同一取值）；编辑任务把所属企业改成不在配置中的企业时也写 NULL（守卫届时明确拒传，不静默换主体）。**只在企业真的改了时才重设**——页面只在该情形才把 `company` 送上来，改个日期不会把审计值重置 |
| from_user_id | TEXT | 零售专用（补传装配的 `fromUserId`，仅 104/203 用）。**采集来的行**＝源表 `zsm_ls.from_user_id` 照搬；**手工建的行**＝按发货/收货语义现算（入库＝对方 ent_id，见 `RetailManualEntry::endpoints`）。批发行、321/116 行、工单 06 之前采的零售行为空 |
| to_user_id | TEXT | 零售专用（补传装配的 `toUserId`，仅 104/203 用）。采集来的＝源表 `zsm_ls.to_user_id` 照搬；手工建的＝出库时为对方 ent_id、入库时为本店 ent_id。321/116 为空 |
| physic_type | TEXT | 零售专用（补传装配的 `physicType`，仅 104/203 用）：采集来的＝源表 `zsm_ls.physic_type`（实测全表恒为 `3`），手工建的＝常量 `RetailManualEntry::PHYSIC_TYPE`（同一个 `3`） |
| bill_type | TEXT | 单据类型码（3 位数字，兼容旧字母前缀如 XSO；读取时经 `App\BillType::normalize` 归一化） |
| request_status | TEXT | 请求成功/请求失败 |
| response_status | TEXT | 上传成功/单据重复/上传失败/信息不存在/往来单位缺失/未确定（任务表不产生"数量不符"，该状态仅 quantity_check 写 upload_logs） |
| resp | TEXT | API 返回内容 |
| created_at | TEXT | 任务创建时间（写入 SQLite 的时间） |
| updated_at | TEXT | 最后更新时间 |
| last_checked_at | TEXT | 距上次 check_bill_status 成功查询的时间（新鲜度门卫用，NULL=从未查过） |
| status | TEXT | **遗留列，勿读勿写**：`task_status` 的前身（旧值如 `任务失败`/`已上传` 都不在现行词表里）。2026-07-29 的拆分本要删掉它，但本机 SQLite 是 **3.7.17**（< 3.35，不支持 `ALTER TABLE … DROP COLUMN`），迁移脚本那一步只打印 `SKIP` —— 列与旧数据都还在，取值早与 `task_status` 不是一回事（旧值 `任务失败`/`已上传` 都不在现行词表里；只有从未被后写覆盖的历史行上两列还相同）。读状态一律读 `task_status` |

### upload_logs（上传日志）
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER PK | |
| task_id | INTEGER | 关联 upload_tasks.id（0 表示无关联） |
| djbh | TEXT | 单号 |
| ent_name | TEXT | 往来单位名称 |
| trace_codes | TEXT | 追溯码 |
| rq | TEXT | 单据日期（回填自 upload_tasks 或 SQL Server） |
| source | TEXT | cron/manual/batch_check/batch_retry/quantity_check/**retail_retry**（零售人工补传——检查脚本一律只查批发主体，写不出零售日志，见 ADR 0007）/**retail_external**（外部上传：**平台核查确认平台上已有**的门店单，2026-10-05 起这是它唯一的产地——采集分流那半已停用；`task_id=0`、`request_status` 为 NULL、`response.judged_by` 是平台接口名。**只写日志表、从不出现在任务表**；2026-10-02 快照按源库状态表写下的那 47,826 条的 `judged_by` 是表名，属旧口径）；**历史行为空串**（`source` 列上线前的旧记录，实测 2,324 行） |
| company | TEXT | 所属企业中文全名（同 upload_tasks） |
| credential | TEXT | 这次实际用了哪套凭据（采集时预填该门店那套；补传写回同一套）；只作审计 |
| request_status | TEXT | 请求成功/请求失败 |
| response_status | TEXT | 上传成功/单据重复/上传失败/信息不存在/往来单位缺失/未确定/数量不符（quantity_check 专用） |
| response | TEXT | API 返回内容 |
| created_at | TEXT | 任务创建时间（API 调用时间） |
| updated_at | TEXT | 最后更新时间 |
| last_checked_at | TEXT | 距上次 check_bill_status 成功查询的时间（新鲜度门卫用，NULL=从未查过） |
| success | INTEGER | **遗留列，勿读勿写**：0/1 两值，与 `upload_tasks.status` 同一批（2026-07-29 拆分后本应删除，SQLite 3.7.17 不支持 DROP COLUMN 而留下）。成功与否一律看 `response_status`（`上传成功`/`单据重复`） |

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
- **待配凭据**：门店有名字与平台 ID 但没密钥时，单据照常认领，页面标"待配凭据"并禁用补传（15 家中当前已配 6 家——2026-10-06 补入新城分店，见下方"凭据部署"）
- 接口路由 `(企业类型, 单据类型)`：零售 `104`/`203` → `lsyd.uploadinoutbill`（码上限 10000）、`321`/`116` → `lsyd.uploadretail`（3500）；批发一律 kyt `uploadinoutbill`（3500）
- `App\Enterprise` 是唯一入口：`loadFromFiles()` 载入并**强制自检**（平台 ID 不得跨企业重复、凭据的 `ref_ent_id`/`ent_id` 必须属于本企业、**每家企业只允许一套凭据**…），违反即抛异常——这些都是"违反了就会静默把单据传到错误主体"的错误
- **凭据相关的入口**：`credential(企业名, 键)` 按键取（`RetailRequestAssembler` 用它校验凭据归属）、`defaultCredentialKey(企业名)` 返回该企业的凭据键（编辑任务改企业时重设 `credential` 列）、`credentialFor(企业名)` 返回该企业那套凭据（含 `key`，补传链路与端点用；待配凭据返回空凭据而非 null，由 `credentialConfigured()` 判定）、`retailCredentialReady()` 返回 `门店名 => 'ready'|'pending'|'no_slot'`（门店名缺席 = 不在配置中），供两个视图判断"这家能不能补传"——**三态把"待配凭据"（等密钥，正常态）与"没声明凭据位"（配置缺口）分开**，页面不再出凭据位键与 label
- **批发主体入口 `Enterprise::wholesaleSubject()`**：返回 `['key' => 配置key, 'name' => 企业全名, 'credential_key' => 凭据位键]`，批发链路（采集落库 / cron 取数 / 手动上传 / 三个检查脚本）取"本项目的自动上传主体"的唯一入口，免得各脚本各自硬编码企业名与凭据键。**批发企业不是恰好一家时抛异常**而不是静默取第一个——那正是"把单据申报到错误主体"的经典路径。
  - `credential_key` 是**键**（落库到 `upload_tasks.credential` 的值），凭据数组由 `Enterprise::credential(企业名, 键)` 取——两者形状不同，别混用
  - 它与 `scripts/init_db.php` 的迁移回填常量（`BACKFILL_COMPANY` / `BACKFILL_CREDENTIAL`）必须一致，否则历史行会被各处 `company` 白名单静默漏掉；`tests/enterprise_config_test.php` 有断言钉住这个等式，改企业名或换凭据键时两处 + 历史数据要一起动
- **`company`/`credential` 已落库（2026-09-29，工单 02）**：`upload_tasks` / `upload_logs` 两列 + `ent_list` 的 `company` 列与 `UNIQUE(company, ent_name)` 已在生产库完成迁移，历史行回填为河药批发主体。上传侧的 fail-closed 守卫见"核心数据流 → 上传守卫"
- **迁移期**：河药批发凭据仍从 `.env` 读取（`enterprises.local.php` 的 `heyao` 条目引用 `Config::get`，刻意不复制明文以免两份不一致）。待 `ApiClient` 全面改读本配置后，删除 `.env` 的 `APPKEY_HYYY`/`SECRETKEY_HYYY`/`REFENTID_HYYY`/`ENTID_HYYY`

### 凭据部署（拿到某门店的 AppKey 后怎么落地）

门店的**结构与平台 ID 早已在位**（`config/enterprises.php` 的条目 + local 的 `ids` 是一次性探测生成的），"待配凭据"缺的只有四字段。步骤：

1. **只改 `config/enterprises.local.php`**（不入 git）：给该门店 key 的 `credentials.main` 填四字段，取值抄 `tests/company.txt`（同样是本地文件）。`ref_ent_id`/`ent_id` **必须落在该企业 `ids` 里**，否则 `loadFromFiles()` 自检直接抛异常——这条正是防"单据申报到错误主体"
2. **跑自检**：`php tests/enterprise_config_test.php`——末段"真实配置载入"会打印 `已配凭据的门店数：N / 15`，载入本身还带着整套配置自检（平台 ID 不跨企业重复、凭据归属、每企业恰一套）
3. **验凭据真能用**（AppKey/SECRETKEY 抄错只有真调一次才暴露）：`check_bill_status_retail.php --company=<门店全名> --limit=3` 小步真跑——**只读查询**，凭据不可用时平台回顶层错误码，脚本记成 `error` 并跳过（一个字段都不改）。该脚本带 flock，cron 那轮跑着时会被挡下（打印"已有实例在运行"、退出 0）——等一轮，或用一次性探针发同样的调用
4. **无需改数据**：该门店既有任务行的 `credential` 列在采集时就预填了该凭据键（待配凭据同样预填），凭据一到位它们立刻可补传、也立刻进平台核查的覆盖范围

**2026-10-06 部署记录（新城分店 `dyt-xincheng`，第 6 家）**：`tests/company.txt` 新增该店一行 → 抄进 local。自检通过（`已配凭据的门店数：6 / 15`）；真跑验证两单（`XLSA0100100186363` / `WRKA0100026641`）均返回 `FAIL_BIZ_NO_PAT_INFO / 信息不存在`、网关无错误码（= AppKey+SECRETKEY 有效）。效果：该店 759 条「等待上传」从"平台核查够不到"转入可查——全量待办 4,457 条 / 14 家，**可查 2,081 条 / 6 家、跳过 2,376 条 / 8 家**（含 `未识别` 51 条）

## 环境配置

- **Web 服务器**: Nginx，`listen 8188`（server_name `192.168.2.189`），root `public/`；站点配置另有**访问白名单** `allow 192.168.2.0/24; allow 127.0.0.1; deny all;`（`/etc/nginx/conf.d/mashangfangxin.conf`）
- **PHP-FPM**: 池名 `mashangfangxin`，监听 `127.0.0.1:9008`
- **防火墙**: firewalld 需开放 `8188/tcp`（`firewall-cmd --add-port=8188/tcp --permanent`）
- **SELinux**: `data/` 和 `logs/` 需设 `httpd_sys_rw_content_t` 上下文
- **文件权限**: `data/msfx.db` 和 `logs/` 及内容必须属主为 `nginx:nginx`（PHP-FPM 运行用户），否则 Web 端将报 "readonly database" 错误导致空响应。**推论（2026-10-02 起照此执行）**：本项目脚本**一律以 nginx 身份跑**——cron 条目用 `su -s /bin/bash nginx -c '/usr/bin/php …'` 包着（见 cron 时间表那节的注），手工跑同样如此；以 root 直跑会造出 root 属主文件，同名文件此后 nginx 侧写不进去（`msfx.db` 本身不因写入改属主，但 WAL 的 `-wal`/`-shm` 边文件是**谁开的归谁**——混身份照样卡住，别在这条上找例外）

## 关键依赖

- Composer 依赖：`phpoffice/phpspreadsheet`（xlsx 导入/导出）
- 前端资源**本地自托管**（`public/assets/`，随仓库分发、无外网依赖）：Bootstrap 5.3.3 + Bootstrap Icons 1.11.3 + flatpickr 4.6.9（日期范围选择器 + 中文 locale `zh.js`），由 `src/views/layout.php` 按 `/assets/…` 引用
- `db.php`（不在仓库内，位于 Web PHP include_path），提供 `info_log()`、`hht()` 等函数
  - CLI 环境下 `db.php` 不可用，CLI 脚本内部定义了 `info_log()` 桩函数输出到 stderr
- `src/SqlSrvHelper.php` 通过 composer `classmap` 自动加载（非 namespace 类）
  - 它的连接配置由 `App\Config::sqlServer()` 提供（单一来源，见文件树 `Config.php` 一行）；
    四处连接点改调它之前是四处各写一份五字段，漏改一处会静默连到另一个库
- PHP 扩展：`sqlsrv`（SQL Server）、`curl`、`sqlite3`
- 运行环境：PHP 8.1 + Nginx + SQL Server

## 常用命令

```bash
# ⚠️ 本项目脚本一律以 **nginx 身份**跑（cron 条目已如此，手工跑同办）——它们要写 logs/ 与 data/，
#    以 root 直跑会造出 root 属主文件，此后 nginx 侧（含 Web 端）写不进去，见"环境配置 → 文件权限"：
#      su -s /bin/bash nginx -c '/usr/bin/php /usr/share/nginx/mashangfangxin/scripts/xxx.php'
#    下面的命令为便于阅读仍写成 `php …`，实际执行请套上 su；跑完用 find logs data -user root 复查。

# 采集当天单据到上传队列
php /usr/share/nginx/mashangfangxin/scripts/fetch_bills.php

# 采集指定日期的单据
php /usr/share/nginx/mashangfangxin/scripts/fetch_bills.php 2026-07-28

# 采集零售门店单据（dyt 链接服务器；只读源库、不调平台接口，随时可跑）
# ⚠️ **2026-10-05 起：单据一律建「等待上传」任务，采集不再读源库状态表**（ADR 0018）——
#    按那张表分流会让一条实际没传成的单**静默消失**（不进队列，平台上却一片空白）。
#    是否已上传统一由平台核查判定（scripts/check_bill_status_retail.php），它会把已上传的
#    任务翻成「已处理」+「上传成功」并在已上传页留一条「外部上传」记录
# **状态闭环已停接**（票 03 的那次调用，2026-10-05 起空着——它读同一张表）；附带失效的还有
#    那条"闭环在前、门卫在后"的顺序红线。恢复路径见 docs/adr/0018
# **计数门卫**（票 04；2026-10-05 起只数一个数）：当日总数与基线一致就跳过整轮并打印原因
#    （日志从此分得清"真没新单"与"脚本没跑"）；判定与基线读写见 App\RetailCollectionGate，
#    基线 data/fetch_bill_counter_retail.json（形状 {date,total}；带 uploaded/unuploaded 的
#    旧版文件判非法 → 照常采一轮）
# 落库 task_status='等待上传'（与批发共用一个状态值，见 docs/adr/0014）/ source='retail'，
# 需要 nginx 或跑完 chown（同 init_db 的属主注意事项）
# 2 年下限（平台硬性规定，App\RetailRetention）：超期日期会被**拒绝并退出 1**；
#    --all 也只采最近 2 年——平台不接受 2 年前的单据，采进来也补传不出去
php /usr/share/nginx/mashangfangxin/scripts/fetch_bills_retail.php             # 当天（cron 的口径）
php /usr/share/nginx/mashangfangxin/scripts/fetch_bills_retail.php 2026-09-28  # 指定日期

# **预演**（票 05，随时可跑）：只读源库、只统计并打印「将新增任务 N 条 / M 码」，**不落任何库、
#    不写基线、不调平台接口、不跑任何翻正**。可与日期或 --all 组合；参数顺序随意
#    （它**不经过计数门卫**——门卫的产物是基线，预演写了它下一轮 cron 就会少采一轮）
php /usr/share/nginx/mashangfangxin/scripts/fetch_bills_retail.php --dry-run
php /usr/share/nginx/mashangfangxin/scripts/fetch_bills_retail.php --all --dry-run

# **两年窗口侦察**（票 05；2026-10-05 起退成只统计）：跑完打印「窗口内 N 单，本地已有痕迹 M 单
#    （不重复写），其余 K 单未建任务」——K 就是那个"在本系统里不可见"的欠账数。
#    **它一个字节都不写**（不落库、不写 JSONL、不写基线），别再指望它写历史记录；
#    跑一次即可、别挂进 cron；建议错开 :00/:30——那是 fetch_bills 的写库窗口
# 旧口径首跑实测（2026-10-02，当时还落库）：54,085 单 / 558,981 行 → 写 47,813 条记录 /
#    220,367 个码，未上传 6,251 单（其中 6,245 单本地已有痕迹，未建任务的只有 6 单）
php /usr/share/nginx/mashangfangxin/scripts/fetch_bills_retail.php --all

# 批量上传队列中等待上传的任务（只取批发主体的记录：task_status='等待上传' AND company=批发主体）
# 门店单据的状态值也是'等待上传'，但本脚本取不到它们——挡住的是 company 白名单（不是状态值）；
# 即便口径被改错，UploadService 的守卫是第二道
# ⚠️ 它的三条 crontab 条目当前**全部注释着**（"暂不启用"）——现在只能这样手动跑
php /usr/share/nginx/mashangfangxin/scripts/upload_pending.php

# 门店单据的**批量上传**（票 06）：**能力已备、暂不启用**——由人手动跑，**别挂进 crontab**
#    （挂上去等于让不可逆的申报在人看不见的时候发出去，见 docs/adr/0011 的补注）。
#    逐条走现有补传实现（三关 fail-closed、日志来源 retail_retry、任务状态翻转、源库回写全照旧），
#    排除「未识别」（判据是"是不是门店企业"）并在末尾计数；空队列**不取锁**秒退，
#    真跑取 logs/upload_pending_retail.lock。取数口径与限量判据在 App\RetailBatchUpload
#    ⚠️ **默认就是真传、不可逆**：先 --dry-run 看清单，再用 --limit=N 小步走。
#    --dry-run 的数字是**上界**（它不代跑三关）：凭据未配齐的门店会逐条标出来，真跑时被拒、不发调用；
#    --limit 限的是**处理条数**——未识别不占额度，被三关拒的占（它们在 N 条之内被拒，后面的不补位）
#    --company=k1,k2 只处理这些**企业 key**（config/enterprises.php 里的 key）的待办：取数仍走
#    PENDING_SQL（全部门店），过滤在**取回之后**做。**要只传某几家就得给这个参数**——不给就是把
#    队列里所有门店的待办都真传出去；未知 key 直接退出 1（打错一个字母与"队列本来就是空的"
#    在日志上长得一模一样，故 fail-closed）
php /usr/share/nginx/mashangfangxin/scripts/upload_pending_retail.php --dry-run          # 只列不传（单号/门店/码数）
php /usr/share/nginx/mashangfangxin/scripts/upload_pending_retail.php --dry-run --limit=3 # 看前 3 条会轮到什么
php /usr/share/nginx/mashangfangxin/scripts/upload_pending_retail.php --limit=2          # 处理前 2 条（真传，真实申报）
php /usr/share/nginx/mashangfangxin/scripts/upload_pending_retail.php \
    --company=dyt-baoyuan,dyt-puqianlixin,dyt-xinjiang,dyt-dahu   # 只传这 4 家门店的待办（其余门店一条不碰）

# 门店单据在**平台上**再核查一次（2026-10-05）：拿各门店自己的凭据调 lsyd.query.upbilldetail
#    逐条问平台"这单在不在"，在的翻正——任务行翻「已处理」+「上传成功」、失败记录追加一条
#    「外部上传」（判定与落库走 closureActions() + applyActions()，见 src/RetailPlatformCheck.php）
#    ⚠️ **2026-10-05 起它是门店单「是否已上传」的唯一判据**（ADR 0018：采集不再读源库状态表，
#    一律建任务，由本脚本翻正）。**覆盖面就是系统的覆盖面**：取不到凭据的门店它整组跳过，
#    那批单会一直挂在「等待上传」
#    **只发查询、不发申报**（只读接口），但仍是真实平台调用——先 --dry-run，再 --limit 小步走
#    ⚠️ **2026-10-05 票 02 起已挂 crontab**：`20,50 8-21 * * *`（只跑白天；一趟约 22 分钟）。
#    逐单门卫 30 分钟（与批发同值）：`last_checked_at` 在窗口内的单本轮跳过，被挡下多少条
#    打印在开头；跑完**批量 touch** 平台给过答复的键（在／不在都算，查异常的不刷）
#    --dry-run 只列不查（一次调用都不发、一个字节都不写；列的是**过完门卫**的那份）
#    --limit=N 限量（跳过的门店不占额度）；--company=名,名 只查这几家门店（取回之后过滤）
#    取不到凭据的门店（未识别/待配凭据）整组跳过并计数——没有 ref_ent_id 这条判据不成立
php /usr/share/nginx/mashangfangxin/scripts/check_bill_status_retail.php --dry-run
php /usr/share/nginx/mashangfangxin/scripts/check_bill_status_retail.php --limit=5
php /usr/share/nginx/mashangfangxin/scripts/check_bill_status_retail.php \
    --company="大源堂智慧药房（河源）有限公司新江分店"

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

# 回填源库上传状态（2026-10-02 已执行；把回写功能上线前已补传成功的零售单号补进
# dyt.bs_msfx.dbo.update_state，判据取自 upload_logs；**幂等可重跑**，重跑即 0 写入、N 跳过）
# 生产库上跑注意属主：以 nginx 用户执行（su -s /bin/bash nginx -c "php ..."），见 init_db 那条
php /usr/share/nginx/mashangfangxin/scripts/backfill_update_state.php

# 初始化/迁移 SQLite 数据库（幂等，可重复执行；含 company/credential 列、历史回填、ent_list 唯一键重建、
# upload_tasks 的 from_user_id/to_user_id/physic_type 三列——这三列**没有历史回填**，
# 缺列的零售旧行只能删掉重采（见"核心数据流 → 零售单据采集"）；
# 以及任务状态取值归一 待补传→等待上传（2026-10-01 已执行，见 docs/adr/0014））
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
php /usr/share/nginx/mashangfangxin/tests/api_client_test.php
php /usr/share/nginx/mashangfangxin/tests/enterprise_config_test.php
php /usr/share/nginx/mashangfangxin/tests/config_test.php
php /usr/share/nginx/mashangfangxin/tests/retail_upload_test.php
php /usr/share/nginx/mashangfangxin/tests/retail_retention_test.php
php /usr/share/nginx/mashangfangxin/tests/retail_manual_test.php
php /usr/share/nginx/mashangfangxin/tests/record_query_test.php
php /usr/share/nginx/mashangfangxin/tests/log_source_test.php
php /usr/share/nginx/mashangfangxin/tests/retail_external_uploads_test.php
php /usr/share/nginx/mashangfangxin/tests/retail_collection_gate_test.php
php /usr/share/nginx/mashangfangxin/tests/retail_batch_upload_test.php
php /usr/share/nginx/mashangfangxin/tests/retail_platform_check_test.php

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
- API 重试：最多 3 次，间隔 30s；**网络错误**重试、**业务错误**不重试——判据是 `is_network_error`。
  **顶层限流（`code=7` / `App Call Limited`）也走这条重试通道**（2026-10-02 起，`ApiClient::isRetryableTopError`）：
  它过去被当成业务错误、不重试，那张单于是被静默判失败并翻「已处理」，只能人工去失败记录页重传（见 `.scratch/top-sdk-top-error/`）
- API 限速：**批发**每次调用间隔 330ms（`UploadService::API_INTERVAL_US`）；**零售 400ms**
  （`RetailRetransmit::API_INTERVAL_US`，2026-10-02 由 330ms 放宽）。330ms 对应的理论速率是
  **3.03 单/秒**——正好压在平台"1 秒内不超过 3 单"的限流边界上：页面单条补传一天点不了几次，
  贴边跑看不出来；而批量补传会**连续调用上千次**，贴边迟早撞限流，撞了就是成片假失败。
  400ms 对应 2.5 单/秒，加上网络往返实测约 **2 单/秒**

## Agent skills

### Issue tracker

本地 markdown 文件，存储在 `.scratch/<feature-slug>/` 下。详见 `docs/agents/issue-tracker.md`。

### Triage labels

使用默认五个标准 triage 标签。详见 `docs/agents/triage-labels.md`。

### Domain docs

单上下文布局：根目录 `CONTEXT.md` + `docs/adr/`。详见 `docs/agents/domain.md`。
