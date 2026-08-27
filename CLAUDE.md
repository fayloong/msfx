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
├── top_sdk/              # 阿里健康 TOP SDK，不可修改
│   ├── TopSdk.php        # SDK 入口
│   └── top/
│       ├── TopClient.php          # HTTP 客户端（cURL + MD5 签名）
│       ├── request/*.php          # API 请求类
│       └── domain/*.php           # 返回结果的 DTO
├── src/                  # 项目自建类（namespace App\，PSR-4 自动加载）
│   ├── Config.php                # .env 配置加载
│   ├── Database.php              # SQLite 数据库封装（单例）
│   ├── Auth.php                  # 单用户 session 认证
│   ├── ApiClient.php             # 封装 TopClient（上传/查询/搜索/singlerelation 码级折算），区分网络/业务错误
│   ├── TaskFetcher.php           # 从 SQL Server 拉取/统计待上传单据（含 fetch_bills 门卫计数、fetchBillQuantities 数量基线聚合、fetchWmsCodesByDjbhList 第 2 级码基线现查）
│   ├── UploadService.php         # 核心上传逻辑（cron 和 Web 共用）
│   ├── TraceSplitter.php         # 导出拆行：追溯码按字符数拆多行（每行 ≤32000 字符）
│   ├── LogWriter.php             # JSONL + SQLite 双写日志
│   ├── SqlSrvHelper.php          # SQL Server 数据库操作封装（根命名空间，classmap 加载）
│   ├── LockManager.php           # 未使用（预留）
│   ├── Logger.php                # 未使用（预留）
│   ├── api/                      # AJAX API 端点
│   │   ├── tasks.php             # 上传任务 CRUD（GET 列表/单条, PUT 编辑, DELETE 删除）
│   │   ├── tasks_retry.php       # 单条重传
│   │   ├── tasks_batch_delete.php # 批量删除上传任务
│   │   ├── tasks_batch_retry.php  # 批量重传
│   │   ├── uploaded.php          # 已上传记录列表（upload_logs success=1）
│   │   ├── failed.php            # 失败记录列表（排除该单号已有上传成功/单据重复记录的日志行；quantity_check 来源记录豁免——数量对账仅查已上传成功单，若不豁免会被 NOT EXISTS 全隐藏，告警出口失效）
│   │   ├── logs_delete.php       # 删除单条日志记录
│   │   ├── logs_batch_delete.php # 批量删除日志记录
│   │   ├── manual_create.php     # 手动创建任务并立即上传
│   │   ├── manual_import.php     # xlsx 导入批量创建并上传
│   │   ├── template_download.php # 下载 xlsx 导入模板
│   │   └── export.php            # 按当前筛选条件导出 xlsx（流式生成，内存 O(1)）
│   └── views/                    # 页面视图（PHP 模板）
│       ├── layout.php            # 全局布局（左侧菜单 + 顶栏）
│       ├── login.php             # 登录页
│       ├── dashboard.php         # 首页仪表盘（4 个统计卡片）
│       ├── upload_tasks.php      # 上传任务管理页（表格 + CRUD + 批量操作）
│       ├── uploaded.php          # 已上传记录页
│       ├── failed.php            # 失败记录页
│       └── manual_upload.php     # 手动上传（在线表单 + xlsx 导入）
├── config/
│   ├── .env                      # 数据库连接 + API 凭证 + 管理员密码
│   └── sql.php                   # SQL Server 原始查询（参考用；采集口径含 a.is_zx='是' 已执行单据过滤，2026-08-27）
├── public/
│   ├── index.php                 # Web 单入口（page 参数分发路由）
│   └── favicon.svg               # SVG 网站图标
├── scripts/
│   ├── fetch_bills.php           # cron 从 SQL Server 采集单据写入上传队列
│   ├── upload_pending.php        # cron 批量上传队列中等待中的任务
│   ├── check_bill_status.php     # 批量查询单据上传状态（来源 1：等待上传任务，高频 8-20 点）
│   ├── check_failed_logs.php     # 复查失败记录（来源 2：upload_logs 未上传成功记录，每天 20:40）
│   ├── check_quantity.php        # 数量对账两级流水线（第 1 级 shl 粗筛嫌疑单 → 第 2 级 singlerelation 码级精查，双差异才写"数量不符"）
│   ├── cleanup_logs.php          # 清理超过 3 个月的 SQLite 日志与已完成任务
│   ├── backfill_rq.php           # 回填 upload_logs 的单据日期（rq 列）
│   ├── init_db.php               # 初始化 SQLite 数据库及表结构
│   └── sqlite_query.php          # 调试工具：直接传 SQL 查询/操作 SQLite（表格输出）
├── data/
│   ├── msfx.db                   # SQLite 本地数据库（3 张表 + 索引）
│   └── fetch_bill_counter.json   # fetch_bills 变化检测门卫基线（当天单据计数）
├── tests/
│   ├── trace_splitter_test.php   # TraceSplitter 自包含断言测试（php tests/trace_splitter_test.php）
│   ├── quantity_check_test.php   # ApiClient::isBillFound 自包含断言测试（php tests/quantity_check_test.php）
│   ├── search_bill_test.php      # searchbill.detail 查询调试：传单号输出完整返回并另存 searchbill_<单号>.json（tests 目录内；退出码 0=全部成功，1=存在网络/业务错误）
│   ├── singlerelation_test.php   # singlerelation 逐码查询调试（码级对账探针）：验证 Σ 折算系数 == min_pkg_count 核心等式（折算规则 is_smallest=Y→1 忽略 pkg_amount，2026-08-26 加固；设计见 .scratch/quantity-check/singlerelation-tier2.md；避开 8-20 点窗口运行）
│   └── searchbill_*.json         # search_bill_test.php 的查询结果存档
├── logs/                         # API 日志 JSONL 文件
├── upload_test.php               # 原始上传脚本（旧版，保留参考）
├── get_ent_list_test.php         # 原始往来单位同步脚本（旧版）
└── bill_info_test.php            # 原始单据查询脚本（旧版）
```

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

所有页面（除 login 和 api）需要登录。API 端点内部自行处理认证。

三个数据页面（upload-tasks / uploaded / failed）均支持筛选：单号、往来单位、状态、**单据日期**（`rq`）、**任务创建时间**（`created_at`）。日期筛选使用 flatpickr 范围选择器，一个输入框同时选起止日期，默认最近 7 天（含当天）。**关键词检索（单号/往来单位）不受默认日期范围限制**：输入关键词时若日期选择器仍是默认 7 天（用户未手动改过），前端自动不传日期参数实现全库检索；用户手动改过日期则关键词+日期正常组合过滤。分页最多显示 10 个页码，超出用省略号。

三个数据页工具栏均有"导出 xlsx"按钮：按当前生效筛选条件全量导出（前端已计算关键词忽略默认日期后的参数）。导出走 `page=api&action=export`（`api/export.php`），**流式生成**（sheet XML 逐行写临时文件 + ZipArchive 打包，不用 PhpSpreadsheet 避免全量驻留内存）；追溯码按字符数拆行（`App\TraceSplitter::splitByCharLimit`，每行 ≤32000 字符 ≈ 1523 码，超限时一单多行、单号加 `_N` 后缀，命名对齐上传拆分、已带后缀的单号追加后缀），拆行兜底（单条码自身超 32000 字符的极端情况）仍截断并追加 `…(共N个码)`，其余列超限追加 `…(已截断)`；无匹配数据时前端拦截提示、后端仍输出带表头的空文件。导出列与页面表格对齐（来源列导出机器值 cron/manual/...，单据类型导出归一化 3 位码），文件名 `上传任务/已上传/失败记录_YYYY-MM-DD.xlsx`。

## 核心数据流

### 定时上传（fetch_bills.php + upload_pending.php）

采集和上传解耦为两个独立脚本，可分别设 cron 规则。

**采集（fetch_bills.php）**：启动时轻量查询 SALEOUTMT/PURINMT 当天单据计数，与 `data/fetch_bill_counter.json` 基线比较——同一日期且计数相同则跳过采集（避免重视图查询空转），基线只在采集成功（视图查询 + SQLite 写入全部完成）后更新；然后 SQL Server 查询当天单据（**仅取已执行单据 `a.is_zx='是'`**，作废/未执行单据不采集，口径与 `config/sql.php` 一致）→ 按 `djbh` 去重（跳过 `upload_tasks` 中已存在的任务，以及 `upload_logs` 中已上传成功/单据重复的单据）→ 写入 SQLite `upload_tasks`（source=cron, task_status=等待上传, bill_type=单据号前缀）

**上传（upload_pending.php）**：读取 `upload_tasks` 中所有 `task_status='等待上传'` 的任务（不限来源）→ 查 SQLite `ent_list` 缓存 → 缓存未命中调码上放心 API 获取 `ent_id` → 超过 3500 追溯码自动拆分为 `单号_1, 单号_2...` → 调 API 上传 → 结果写入 JSONL + SQLite `upload_logs`（关联 task_id）→ 更新 `upload_tasks` 状态 → 重试 3 次（仅网络错误，间隔 30s）→ API 间隔 0.33s → flock 文件锁防并发

手动上传（manual_create / manual_import）保持立即上传不变，两套上传路径并存。

### 批量查询上传状态（check_bill_status.php + check_failed_logs.php）

两脚本共用同一套查询/更新语义，仅调度频率不同，各带独立 flock 锁（`logs/check_bill_status.lock`、`logs/check_failed_logs.lock`，`LOCK_EX|LOCK_NB`，锁被占用直接退出防并发）。

**check_bill_status.php（来源 1：等待上传任务，高频）**：查询 `upload_tasks`（task_status='等待上传'）带 `last_checked_at` 新鲜度门卫（`last_checked_at IS NULL OR last_checked_at <= 阈值`，阈值常量 `CHECK_INTERVAL_MINUTES = 30`）→ 逐个调 `ApiClient::searchBillDetail()`（API 间隔 0.5s）→ 已上传的标记任务已处理 + 写 upload_logs（source=batch_check）+ JSONL → 未上传（信息不存在）的仅更新 `updated_at` → 建议 cron: 8-20 点每 5 分钟一次。每轮 5 分钟跑不完是可接受状态（只剩一个队列，下一轮续跑即可）。

**check_failed_logs.php（来源 2：失败记录，低频）**：查询 `upload_logs`（response_status IS NULL 或 NOT IN ('上传成功','单据重复')）带同样门卫 → 按 `djbh` 去重（首次遇到胜出，同单多条失败记录只查一次 API）→ 逐个 `searchBillDetail`：平台存在 → 记录翻转为"上传成功" + 同步关联 upload_tasks（task_id>0 标已处理）+ 写 JSONL；信息不存在 → 仅更新 `updated_at`/`last_checked_at`；API 异常 → 跳过不修改 → cron: 每天 20:40。作用：外部系统补传后失败记录页自动干净（配合 failed.php 的 NOT EXISTS 逻辑）。

循环内"已确认在平台跳过"（SQLite 已有上传成功/单据重复记录）时不调 API：check_bill_status 对任务直接标记"已处理"（任务目标已达成，避免停留在"等待上传"被反复拉取/重传）；check_failed_logs 保留历史记录不动。

`last_checked_at` 更新规则（两脚本一致）：API 查询成功（含"信息不存在"）和"已确认在平台跳过"（标记任务已处理时）都会 touch；仅 API 异常不 touch，下次 cron 自动重查。新采集/新建任务的 `last_checked_at` 为 NULL，天然立即查。

### cron 时间表（全部检查类脚本错峰，8-20 点窗口只跑 check_bill_status）

| 脚本 | cron | 说明 |
|------|------|------|
| fetch_bills（采集） | `0,30 0,1,2,3,8-23 * * *` | 写库与检查脚本的 SQLite 锁冲突由 busyTimeout(30s) 兜底 |
| check_bill_status（来源 1） | `*/5 8-20 * * *` | 高频确认新单 |
| check_failed_logs（来源 2） | `40 20 * * *` | 20:40，fetch_bills 20:30 轮已结束、21:00 轮未到 |
| check_quantity（数量对账） | `10 21 * * *` | 21:10，fetch_bills 21:00/21:30 两轮之间；数量对比（shl vs min_pkg_count 求和），~650 单约 13 分钟 |
| cleanup_logs | `0 3 * * *` | 清理 3 个月前的日志 |

**注（2026-08-26 现状）**：check_bill_status 与 check_quantity 的 cron 条目当前已注释停用（两级流水线调试期），仅手动运行；恢复调度时按表配置并同步更新本节。

覆盖保证：任何单据最终都会被查到平台状态（等待上传 ≤30 分钟 / 失败记录 ≤24h / SQL Server 全量 ≤24h）。check_quantity 与 check_failed_logs 不得改到 8-20 点窗口内运行（与 check_bill_status 并发调同一 AppKey 立即触发平台限流）。

### 数量对账（check_quantity.php，两级流水线）
定位：外部系统负责上传时，本项目只检查上传情况、不补传。**查询范围仅针对 check_bill_status 已检查过且状态是"上传成功"的单据**（upload_logs `source='batch_check' AND response_status='上传成功'`，按 rq 筛选）——未上传的单据由 check_bill_status 以任务状态（等待上传）反映，数量对账不重复查询/告警。

**第 1 级（快，全量，SQL Server 聚合）**：逐单依次查询平台原始单号 → `_1` → `_2`...（上限 10 次），跨拆分子单累加平台申报数量（`ApiClient::sumBillDetailCount()`：累加 `min_pkg_count`），与本地应有数量对比（`TaskFetcher::fetchBillQuantitiesByCodes()`：明细视图 `SUM(shl)` 聚合，轻量查询不写库）。**比较口径统一为最小包装单位数**：本地 `shl` 即"已展开的最小包装单位数"（整件行 `shl = baozhshl × jlgg`、零散行 `shl = lingsshl`，见 ADR 0004——推翻早期"两数量纲无法统一"结论）。**基线剔除本地非药品行**（jixing 含商品/食品/消杀/用品/器械/化妆品/消毒剂/敷料/试剂/材料/设备等，spkfk 查不到剂型的行保守保留）——平台是药品追溯平台，外部系统按平台规则不申报非药品。**查询策略"相等即停，不等查尽"**：原始单号查到且数量相等即停（未拆分大头单 1 次调用）；原始单号查不到或数量不等继续查子单，防止"原单号+拆分并存"漏计。**"数量不符"嫌疑单仅收集在内存（不写库）**，其余分支照旧：相等 → 传齐零记录；全序列查不到 → `信息不存在`（防御分支：batch_check 已确认上传成功但平台查不到）；无法核对跳过（不写任何记录）——本地 `SUM(shl)` 为 NULL（明细视图无行）、平台响应解析失败（`sumBillDetailCount` 返回 null），不误报。

**第 2 级（慢，精查，仅嫌疑单，singlerelation 码级口径）**：逐码调 `ApiClient::searchSingleRelation()` 把本地追溯码折算成平台"最小溯源单位"系数（`ApiClient::sumPkgAmount()` 解析，单一事实源），Σ 系数与第 1 级 actual（跨子单 min_pkg_count 累加）同口径对比——**本地零售规格 ≠ 平台注册规格的结构性口径差异（青霉素钠 20 瓶 vs 1 盒、氨咖黄敏胶囊 10粒/盒 vs 500粒/盒等，ADR 0004 判定的"本阶段无更优解"遗留硬伤）在此消除**。**码基线来自 wms_dzjg 现查**（`TaskFetcher::fetchWmsCodesByDjbhList`，2026-08-26 加固——batch_check 快照缺采集后手持扫码补录的大包装箱码（整件只有大码的氯化钠/葡萄糖 40/50/120瓶/箱），实测 25/25 "数量不符"判定全为此类假阳性；check_quantity 21:10 运行晚于发货补录，现查天然规避；现查为空回退快照基线；"数量不符"响应附 `code_source`/`codes_checked`/`base_codes` 便于识别）。**折算规则（2026-08-26 加固）**：`is_smallest="Y"`（该码即平台最小溯源单位）→ 恒取 1、忽略 pkg_amount——反例实测：葡萄糖注射液 120瓶/箱 箱码 is_smallest=Y 但 pkg_amount=120（整件只有大码、内部 120 个最小单位无追溯码，注射液类常见），120 是注册规格非可对账单位数，平台 min_pkg_count 对该码按 1 计，pkg_amount 直取会误判"数量不符"；is_smallest="N"/缺失 → 用 pkg_amount（大包装码=100、中包装码=5/20、最小单位码=1）。核心等式 `Σ singlerelation(本地每个追溯码).折算系数 == min_pkg_count` 2026-08-26 探针实测成立（50/50、240/240 全等），设计见 `.scratch/quantity-check/singlerelation-tier2.md`。判定：**双方案都有差异 → 真问题**，写 `数量不符`（expected=Σ 码级折算，response 存 `{djbh, rq, expected, actual, sub_bills:[{djbh, count}], stopped_early, code_source, codes_checked, base_codes}`）；**单方案有差异（第 2 级相等）→ 规格口径噪声，不写库** → Web 失败记录页零噪声；**码查询失败/无法核对（理论不存在，实测零次）→ 跳过不写库**（Σ 不完整判定不可信，不误报）。**"超过即停"优化**：累计 Σ 一旦 > actual 即确定"本地多于平台"立即停（所有码系数 >0 不可能回落相等；只有相等/偏少才需查完全部码，嫌疑单平均 ~16 码）。第 2 级限速 500ms/次（1 秒 2 次，spec 实测确认；与 searchbill.detail 同 AppKey 限流池）。

**2026-08-15 全量实测**（589 单）：传齐 527 / 第 1 级差异 62 → 码级精查后**真问题 0 / 规格噪声排除 62** / 无法核对 0 / 异常 0（2026-08-27 码基线现查加固后重查结果）。历史结论修正轨迹：旧版快照基线时代实测"真问题 17 / 规格噪声排除 45"，2026-08-26 复核发现 25/25 判定（含 17 条）全为"采集后补录"假阳性（batch_check 快照缺采集后手持补录的大包装箱码，差 1-8 恰等于补录码数）——**差 1-5"平台申报 > 本地码折算"方向的最可能成因是本地码基线缺码而非外部多传**，旧"外部系统多传/混码"方向经验作废；运维看到"数量不符"且 expected < actual 时先核对 wms_dzjg 现查码数（response 的 code_source/codes_checked/base_codes 可辨识），现查折算 == 平台申报即假阳性。

**幂等**：每轮先清理目标日期全部 quantity_check 记录再按新判定写入（限流熔断后下次运行重查不产生重复/残留记录，历史"数量不符"误报随重跑自动清除）。第 1 级 API 间隔 1s；两级任一处平台限流（App Call Limited）时**本轮熔断**，剩余单据下次运行自动重查。**运行时机**：必须避开 check_bill_status（8-20 点每 5 分钟一轮）的调用窗口，否则并发触发平台限流，cron 配 21:10 每天一次（详见上方 cron 时间表），默认检查昨天（参数可指定日期）。该检查顺带修正 check_bill_status 的盲区：外部系统拆分上传后原始单号查不到被误判"未上传"的场景，数量对账的运行时子单查询能识别子单已传齐。

### 手动上传（Web 端）

**在线新增**：选择单据类型（下拉菜单，必选）→ 填写日期/单号/往来单位 → 粘贴追溯码（一行一个，JS 自动转逗号分隔）→ 写入 SQLite → 立即上传 → 结果实时反馈

**xlsx 导入**：xlsx 列: 日期 | 单号 | 单据类型 | 往来单位名称 | 追溯码。同单号多行自动合并为一个任务（取第一个非空的日期/单据类型/往来单位，追溯码拼接）。也支持传统的一行一个单据格式。

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
```

## SQLite 本地数据库

文件：`data/msfx.db`，通过 `scripts/init_db.php` 初始化。

### upload_tasks（上传任务）
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER PK | |
| rq | TEXT | 单据日期（来自 SQL Server） |
| djbh | TEXT | 单号 |
| ent_name | TEXT | 往来单位名称 |
| trace_codes | TEXT | 追溯码（逗号分隔） |
| task_status | TEXT | 等待上传/已处理（上传完成后统一标记） |
| source | TEXT | cron/manual/batch_check/batch_retry |
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
| source | TEXT | cron/manual/batch_check/batch_retry/quantity_check |
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
| ent_name | TEXT UNIQUE | 企业名称 |
| ent_id | TEXT | 阿里健康企业 ID |
| ref_ent_id | TEXT | 企业编码 |
| created_at | TEXT | |

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

# 批量上传队列中等待上传的任务
php /usr/share/nginx/mashangfangxin/scripts/upload_pending.php

# 批量查询单据上传状态（来源 1：等待上传任务；新鲜度门卫：距上次查询不足 30 分钟的单据自动跳过）
# 注：日期参数仅打印在日志中，查询范围不受日期限制（按门卫规则扫描全部待查单据）
php /usr/share/nginx/mashangfangxin/scripts/check_bill_status.php

# 复查失败记录（来源 2：upload_logs 未上传成功记录；每天 20:40 由 cron 调用，错峰避开 check_bill_status）
php /usr/share/nginx/mashangfangxin/scripts/check_failed_logs.php

# 清理超过 3 个月的 SQLite 日志与已完成任务
php /usr/share/nginx/mashangfangxin/scripts/cleanup_logs.php

# 回填 upload_logs 的单据日期（首次部署后执行一次即可）
php /usr/share/nginx/mashangfangxin/scripts/backfill_rq.php

# 初始化/迁移 SQLite 数据库
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
# 正常情况下 cron 已配 21:10——当前 cron 已注释停用（2026-08-26 调试期），仅手动运行）
php /usr/share/nginx/mashangfangxin/scripts/check_quantity.php
php /usr/share/nginx/mashangfangxin/scripts/check_quantity.php 2026-08-16

# 运行单元测试
php /usr/share/nginx/mashangfangxin/tests/trace_splitter_test.php
php /usr/share/nginx/mashangfangxin/tests/quantity_check_test.php

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
