# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

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
│   ├── ApiClient.php             # 封装 TopClient，区分网络/业务错误
│   ├── TaskFetcher.php           # 从 SQL Server 拉取待上传单据
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
│   │   ├── failed.php            # 失败记录列表（upload_logs success=0）
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
│   └── index.php                 # Web 单入口（page 参数分发路由）
├── scripts/
│   ├── cron_upload.php           # cron 定时上传入口
│   ├── cleanup_logs.php          # 清理超过 3 个月的 SQLite 日志
│   └── init_db.php               # 初始化 SQLite 数据库及表结构
├── data/
│   └── msfx.db                   # SQLite 本地数据库（3 张表 + 索引）
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

## 核心数据流

### 定时上传（cron_upload.php）
SQL Server `skwms_new.dbo` 查询当天单据 → 按 `djbh` 聚合追溯码 → 查 SQLite `ent_list` 缓存 → 缓存未命中调码上放心 API 获取 `ent_id` → 超过 3500 追溯码自动拆分为 `单号_1, 单号_2...` → 调 `AlibabaAlihealthDrugKytUploadinoutbillRequest` API → 结果写入 JSONL + SQLite `upload_logs` → 重试 3 次（仅网络错误，间隔 30s）→ API 间隔 0.33s → flock 文件锁防并发

### 手动上传（Web 端）
用户填写/导入单据 → 写入 SQLite `upload_tasks`（source=manual, status=等待上传） → 立即调用 UploadService 上传 → 结果实时反馈

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
| rq | TEXT | 单据日期 |
| djbh | TEXT | 单号 |
| ent_name | TEXT | 往来单位名称 |
| trace_codes | TEXT | 追溯码（逗号分隔） |
| status | TEXT | 等待上传/上传中/已上传/任务失败/部分上传成功 |
| source | TEXT | cron/manual |
| resp | TEXT | API 返回内容 |
| created_at | TEXT | |
| updated_at | TEXT | |

### upload_logs（上传日志）
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER PK | |
| task_id | INTEGER | 关联 upload_tasks.id（0 表示无关联） |
| djbh | TEXT | 单号 |
| ent_name | TEXT | 往来单位名称 |
| trace_codes | TEXT | 追溯码 |
| success | INTEGER | 1=成功, 0=失败 |
| response | TEXT | API 返回内容 |
| created_at | TEXT | |

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

## 关键依赖

- Composer 依赖：`phpoffice/phpspreadsheet`（xlsx 导入/导出）
- `db.php`（不在仓库内，位于 Web PHP include_path），提供 `info_log()`、`hht()` 等函数
  - CLI 环境下 `db.php` 不可用，`cron_upload.php` 内部定义了 `info_log()` 桩函数输出到 stderr
- `src/SqlSrvHelper.php` 通过 composer `classmap` 自动加载（非 namespace 类）
- PHP 扩展：`sqlsrv`（SQL Server）、`curl`、`sqlite3`
- 运行环境：PHP 8.1 + Nginx + SQL Server

## 常用命令

```bash
# 定时上传（当天）
php /usr/share/nginx/mashangfangxin/scripts/cron_upload.php

# 定时上传（指定日期）
php /usr/share/nginx/mashangfangxin/scripts/cron_upload.php 2026-07-28

# 清理超过 3 个月的 SQLite 日志
php /usr/share/nginx/mashangfangxin/scripts/cleanup_logs.php

# 初始化/迁移 SQLite 数据库
php /usr/share/nginx/mashangfangxin/scripts/init_db.php

# 网页访问（需要登录，密码见 .env ADMIN_PASSWORD）
http://192.168.2.189:8188
```

## 业务编码映射

- 单据类型：`XSO`=201 销售出库, `XST`=103 退货入库, `JHG`=102 采购入库, `JHO`=202 采购退出
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
