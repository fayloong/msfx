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
│   ├── ApiClient.php             # 封装 TopClient（上传/查询/搜索），区分网络/业务错误
│   ├── TaskFetcher.php           # 从 SQL Server 拉取/统计待上传单据（含 fetch_bills 门卫计数）
│   ├── UploadService.php         # 核心上传逻辑（cron 和 Web 共用）
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
│   │   ├── failed.php            # 失败记录列表（排除该单号已有上传成功/单据重复记录的日志行）
│   │   ├── logs_delete.php       # 删除单条日志记录
│   │   ├── logs_batch_delete.php # 批量删除日志记录
│   │   ├── manual_create.php     # 手动创建任务并立即上传
│   │   ├── manual_import.php     # xlsx 导入批量创建并上传
│   │   └── template_download.php # 下载 xlsx 导入模板
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
│   └── sql.php                   # SQL Server 原始查询（参考用）
├── public/
│   ├── index.php                 # Web 单入口（page 参数分发路由）
│   └── favicon.svg               # SVG 网站图标
├── scripts/
│   ├── fetch_bills.php           # cron 从 SQL Server 采集单据写入上传队列
│   ├── upload_pending.php        # cron 批量上传队列中等待中的任务
│   ├── check_bill_status.php     # 批量查询单据上传状态
│   ├── cleanup_logs.php          # 清理超过 3 个月的 SQLite 日志
│   ├── backfill_rq.php           # 回填 upload_logs 的单据日期（rq 列）
│   └── init_db.php               # 初始化 SQLite 数据库及表结构
├── data/
│   ├── msfx.db                   # SQLite 本地数据库（3 张表 + 索引）
│   └── fetch_bill_counter.json   # fetch_bills 变化检测门卫基线（当天单据计数）
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
| `api` | `api/{action}.php` | AJAX API 端点 |

所有页面（除 login 和 api）需要登录。API 端点内部自行处理认证。

三个数据页面（upload-tasks / uploaded / failed）均支持筛选：单号、往来单位、状态、**单据日期**（`rq`）、**任务创建时间**（`created_at`）。日期筛选使用 flatpickr 范围选择器，一个输入框同时选起止日期，默认最近 7 天（含当天）。分页最多显示 10 个页码，超出用省略号。

## 核心数据流

### 定时上传（fetch_bills.php + upload_pending.php）

采集和上传解耦为两个独立脚本，可分别设 cron 规则。

**采集（fetch_bills.php）**：启动时轻量查询 SALEOUTMT/PURINMT 当天单据计数，与 `data/fetch_bill_counter.json` 基线比较——同一日期且计数相同则跳过采集（避免重视图查询空转），基线只在采集成功（视图查询 + SQLite 写入全部完成）后更新；然后 SQL Server 查询当天单据 → 按 `djbh` 去重（跳过 `upload_tasks` 中已存在的任务，以及 `upload_logs` 中已上传成功/单据重复的单据）→ 写入 SQLite `upload_tasks`（source=cron, task_status=等待上传, bill_type=单据号前缀）

**上传（upload_pending.php）**：读取 `upload_tasks` 中所有 `task_status='等待上传'` 的任务（不限来源）→ 查 SQLite `ent_list` 缓存 → 缓存未命中调码上放心 API 获取 `ent_id` → 超过 3500 追溯码自动拆分为 `单号_1, 单号_2...` → 调 API 上传 → 结果写入 JSONL + SQLite `upload_logs`（关联 task_id）→ 更新 `upload_tasks` 状态 → 重试 3 次（仅网络错误，间隔 30s）→ API 间隔 0.33s → flock 文件锁防并发

手动上传（manual_create / manual_import）保持立即上传不变，两套上传路径并存。

### 批量查询上传状态（check_bill_status.php）
新鲜度门卫：两个来源查询均带 `last_checked_at` 条件（`last_checked_at IS NULL OR last_checked_at <= 阈值`，阈值常量 `CHECK_INTERVAL_MINUTES = 30`）——距上次成功查询不足 30 分钟的单据不拉出，避免高频 cron 下对无变化单据重复调 API（建议 cron: 8-20 点每 5 分钟一次）。

双源合并：`upload_tasks`（task_status='等待上传'）+ `upload_logs`（response_status != '上传成功'）→ 按 `djbh` 去重 → 逐个调 `ApiClient::searchBillDetail()` → 已上传的按来源分别更新状态或写日志 → 未上传（信息不存在）的按来源更新 `updated_at` → API 间隔 0.5s。

循环内"已确认在平台跳过"（SQLite 已有上传成功/单据重复记录）时不调 API：`upload_tasks` 来源的任务直接标记为"已处理"（任务目标已达成，避免停留在"等待上传"被反复拉取/重传）；`upload_logs` 来源的历史记录不动。

`last_checked_at` 更新规则：API 查询成功（含"信息不存在"）和"已确认在平台跳过"（标记任务已处理时）都会 touch；仅 API 异常不 touch，下次 cron 自动重查。新采集/新建任务的 `last_checked_at` 为 NULL，天然立即查。

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
    ↓ 定时清理（cleanup_logs.php）
  删除 3 个月前的记录
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
| response_status | TEXT | 上传成功/单据重复/上传失败/信息不存在/往来单位缺失/未确定 |
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
| source | TEXT | cron/manual/batch_check/batch_retry |
| request_status | TEXT | 请求成功/请求失败 |
| response_status | TEXT | 上传成功/单据重复/上传失败/信息不存在/往来单位缺失/未确定 |
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

# 批量查询单据上传状态（新鲜度门卫：距上次查询不足 30 分钟的单据自动跳过）
# 注：日期参数仅打印在日志中，查询范围不受日期限制（按门卫规则扫描全部待查单据）
php /usr/share/nginx/mashangfangxin/scripts/check_bill_status.php

# 清理超过 3 个月的 SQLite 日志
php /usr/share/nginx/mashangfangxin/scripts/cleanup_logs.php

# 回填 upload_logs 的单据日期（首次部署后执行一次即可）
php /usr/share/nginx/mashangfangxin/scripts/backfill_rq.php

# 初始化/迁移 SQLite 数据库
php /usr/share/nginx/mashangfangxin/scripts/init_db.php

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
