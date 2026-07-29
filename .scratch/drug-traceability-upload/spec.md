# 药品追溯码上传管理系统

**Status: ready-for-agent**

## Problem Statement

河药需要将 ERP 系统中的药品出入库单据上传至阿里健康"码上放心"平台，但当前的上传方式是手动运行 PHP 脚本，存在以下问题：

- 无 Web 界面，无法查看上传进度和结果
- 上传日志是文本格式，难以检索和统计
- 无失败重试机制，网络波动后需人工介入
- 无法手动新增或补传单据
- 无用户认证，任何能访问服务器的人都能执行脚本

需要一个轻量级 Web 管理系统，实现定时自动上传 + 手动上传 + 结果查询 + 批量状态核对 + 失败重传的完整闭环。

## Solution

基于现有 TOP SDK 和 SqlSrvHelper，构建一个单页 Web 应用：

- **后端**：纯 PHP（无框架），SQLite 存储任务和日志，文件锁防并发
- **前端**：Bootstrap 5 + 原生 JavaScript，响应式布局，左侧可折叠菜单
- **核心流程**：Cron 定时触发 → SQL Server 查询 → 码上放心 API 上传 → JSONL 永久日志 + SQLite 查询库
- **安全**：单用户 session 登录，密码 bcrypt 哈希存储在 .env

## User Stories

### 认证
1. 作为管理员，我想要登录页面，输入密码后才能访问系统，以保证系统安全
2. 作为管理员，我想要 session 过期后自动跳转到登录页

### 首页仪表盘
3. 作为管理员，我想在首页看到今日上传总数、成功数、失败数、待处理数四个统计卡片，以便快速了解当天状态
4. 作为管理员，我希望统计卡片的数据在每次打开页面时实时计算（成功/失败/总数从 SQLite 查，待处理从 SQL Server 查）

### 上传任务管理
5. 作为管理员，我想在"上传任务"子页面看到所有待处理和进行中的任务（日期、单号、往来单位、追溯码、任务状态），以便了解当前队列
6. 作为管理员，我想要表格每个列都有筛选功能，以便快速定位特定记录
7. 作为管理员，我想要搜索框支持全文搜索，以便模糊查找
8. 作为管理员，我想要"刷新"按钮手动刷新任务状态
9. 作为管理员，我想要编辑按钮修改任务的日期、单号、往来单位、追溯码
10. 作为管理员，我想要删除按钮删除单个任务
11. 作为管理员，我想要手动重传按钮对单个失败任务重新发起上传
12. 作为管理员，我想要全选框 + 批量删除和批量重传操作，以便高效处理多个任务

### 已上传记录
13. 作为管理员，我想在"已上传"子页面查看所有上传成功的记录，包括 API 返回详情
14. 作为管理员，我想要表格每个列都有筛选功能和搜索框

### 失败记录
15. 作为管理员，我想在"失败记录"子页面查看所有失败任务，包括 API 错误详情
16. 作为管理员，我想对失败记录执行编辑、删除、重传、批量操作，与上传任务页面一致

### 手动上传
17. 作为管理员，我想通过 Web 表单在线新增上传任务（填写日期、单号、往来单位、追溯码）
18. 作为管理员，我想导入 xlsx 文件批量创建上传任务
19. 作为管理员，我想下载 xlsx 模板文件，以便按格式准备数据
20. 作为管理员，我想手动新增/导入的任务立即触发上传，不等 cron

### 批量查询上传状态
21. 作为管理员，我想通过脚本批量查询当天单据在码上放心平台的上传状态，以便发现漏传的单据
22. 作为管理员，我希望已确认在平台存在的单据展示在"已上传"页面，未上传的自动进入"失败记录"并支持重传
23. 作为系统，我想查询结果通过 LogWriter 写入 upload_logs，与已有上传记录统一展示
24. 作为系统，我想未上传的单据自动创建 upload_tasks 记录（source=batch_check），包含完整追溯码和往来单位信息，以便直接重传

### 去重保护
25. 作为系统，我想 cron_upload 在执行前检查 upload_tasks 中是否已有同单据的成功记录，已上传的跳过避免重复调 API
26. 作为系统，我想 check_bill_status 在执行前检查 upload_logs 中是否已有同单据的成功记录，已确认在平台的跳过避免重复查询

### 自动化上传
27. 作为系统，我想每天 20:00 自动执行 SQL 查询从 ERP 获取当天单据并上传
28. 作为系统，我想 cron_upload 先为每笔单据创建 upload_tasks 记录（source=cron），使上传日志与任务关联，失败记录可追溯和重传
29. 作为系统，我想在 API 无响应时自动重试最多 3 次（间隔 30 秒），网络超时重试但业务错误不重试
30. 作为系统，我想上传 API 调用间隔保持 0.33 秒，避免触发平台限流
31. 作为系统，我想查询 API 调用间隔保持 0.5 秒（searchBillDetail 端点限速较宽松）
32. 作为系统，我想使用文件锁防止 cron 任务并发执行

### 追溯码处理
33. 作为系统，我想将同一单号的追溯码用英文逗号拼接为一个字符串
34. 作为系统，当一个单号的追溯码超过 3500 个时，自动拆分为 单号_1、单号_2、单号_3... 分批上传

### 日志
35. 作为系统，我想将每次 API 调用的请求快照和响应结果写入 JSONL 文件永久保存
36. 作为系统，我想将每次 API 调用的结果实时同步到 SQLite 便于 Web 查询
37. 作为系统，我想定时清理 SQLite 中超过 3 个月的记录，控制数据库体积

### 导航与UI
38. 作为用户，我想要左侧菜单栏默认收起（仅显示 SVG 图标），点击展开后显示图标+文字
39. 作为用户，我想要响应式布局，在不同屏幕尺寸下都能正常使用

## Implementation Decisions

### 技术选型
- 后端：PHP 8.1，无框架，过程式 + 少量类
- 数据库：SQLite（本地 `data/msfx.db`），通过 PDO 访问
- 前端：Bootstrap 5 CDN + 原生 JavaScript，不引入前端构建工具
- xlsx 处理：`PhpSpreadsheet`（通过 Composer 安装，唯一外部依赖）
- 密码存储：`.env` 中 `ADMIN_PASSWORD=xxx`，bcrypt 哈希后存 `.env` 的 `ADMIN_PASSWORD_HASH`
- Session：PHP 原生 session，登录态 24 小时有效

### 模块设计

**TaskFetcher** — 从 SQL Server 获取待上传单据
- 封装 `config/sql.php` 的查询逻辑
- 返回标准化数组：`[{type, rq, djbh, erpbillcode, ent_name, trace_codes}]`
- 日期参数化，接受日期范围

**UploadService** — 核心上传逻辑（cron 和 Web 共用）
- 入参：单据列表（已按单号拼接好追溯码）
- 内部流程：查本地 ent_list 缓存 → 未命中则调 API 获取 → 调上传 API → 写日志
- 单号 >3500 追溯码自动拆分
- 重试：最多 3 次，固定 30s 间隔，仅网络错误重试，业务错误不重试
- API 间隔 0.33s（`usleep(330000)`）
- 文件锁防并发（`flock`）

**ApiClient** — TOP SDK 封装
- 封装 TopClient 调用，处理错误分类
- `execute($request)` — 通用执行，返回 `{success, data, error, is_network_error}`
- `queryEntInfo($entName)` — 查询往来单位信息，返回 `{ent_name, ent_id, ref_ent_id}` 或 null
- `searchBillDetail($billCode)` — 查询单据在平台的详情，返回 `{found, response, error}`。内部调用 `AlibabaAlihealthDrugKytSearchbillDetailRequest`，通过 `result.msg_code == 'FAIL_BIZ_NO_PAT_INFO'` 判断单据是否存在
- 网络超时：curl 超时、连接失败 → is_network_error=true
- 业务错误：API 返回错误码 → is_network_error=false

**LogWriter** — 日志写入
- JSONL：追加写入 `logs/api_YYYY-MM-DD.jsonl`，每行一条 JSON
- SQLite：INSERT 到 `upload_logs` 表
- 清理：Cron 每天凌晨执行，DELETE 3 个月前的记录

**check_bill_status.php** — 批量查询单据上传状态
- 从 SQL Server 拉取当天单据（复用 TaskFetcher）
- 逐个调用 ApiClient::searchBillDetail() 查询平台状态
- 去重：查询前检查 upload_logs 是否有同 djbh 的 success=1 记录，有则跳过 API 调用
- 已上传（found=true）：通过 LogWriter 写入 upload_logs（success=1）
- 未上传（found=false）：创建 upload_tasks（source=batch_check, status=任务失败）+ LogWriter 写入 upload_logs（success=0, 关联 task_id），去重检查已有 batch_check 任务避免重复创建
- API 间隔 0.5s（usleep(500000)）
- 支持命令行日期参数

**cron_upload.php 去重逻辑**
- 插入 upload_tasks 前检查：同 djbh 是否已有 status='已上传' 的记录
- 已上传的跳过（不重复调 API），未上传或失败的继续处理
- 确保 cron 任务创建 upload_tasks 记录（source=cron），使每条上传日志有 task_id 可追溯

**Auth** — 认证
- 检查 `$_SESSION['admin']`，未登录跳转 `/login.php`
- 登录页 POST 验证密码，bcrypt 比对

**Cron 调度**
- 20:00 — `cron_upload.php`：上传当天单据
- 20:30 — `check_bill_status.php`：核对上传结果，漏传的自动进入失败记录
- 03:00 — `cleanup_logs.php`：清理 3 个月前的 SQLite 日志
- 所有脚本支持命令行日期参数（`php script.php Y-m-d`），方便补跑历史数据

### 数据库设计（SQLite）

```sql
-- 上传任务表
CREATE TABLE upload_tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    bill_code TEXT NOT NULL,        -- 单号（含拆分后缀如 _1）
    bill_date TEXT NOT NULL,         -- 单据日期
    ent_name TEXT NOT NULL,          -- 往来单位名称
    trace_codes TEXT NOT NULL,       -- 追溯码（逗号分隔）
    status TEXT NOT NULL DEFAULT 'pending',  -- pending/uploading/success/failed/partial
    source TEXT NOT NULL DEFAULT 'cron',     -- cron/manual/batch_check
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- 上传日志表
CREATE TABLE upload_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER,                -- 关联 upload_tasks.id
    bill_code TEXT NOT NULL,
    ent_name TEXT NOT NULL,
    trace_codes TEXT NOT NULL,
    request_time TEXT NOT NULL,     -- API 调用时间
    response_body TEXT,             -- API 响应（JSON）
    success INTEGER NOT NULL DEFAULT 0,  -- 1=成功 0=失败
    error_type TEXT,                -- network/business/null
    error_message TEXT,
    retry_count INTEGER DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- 往来单位缓存表
CREATE TABLE ent_list (
    ent_name TEXT PRIMARY KEY,
    ent_id TEXT NOT NULL,
    ref_ent_id TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
```

### 路由设计

单入口 `/public/index.php`，通过 `page` 参数路由：

| page | 页面 | 需要登录 |
|------|------|----------|
| `login` | 登录页 | 否 |
| `dashboard` | 首页仪表盘 | 是 |
| `tasks` | 上传任务 | 是 |
| `uploaded` | 已上传 | 是 |
| `failed` | 失败记录 | 是 |
| `manual` | 手动上传 | 是 |
| `api/*` | AJAX API 端点 | 部分（login 除外） |

### 菜单结构

```
├── 首页 (dashboard)
├── 单据上传
│   ├── 上传任务 (tasks)
│   ├── 已上传 (uploaded)
│   └── 失败记录 (failed)
└── 手动上传 (manual)
```

### API 端点

```
POST /api/login              — 登录
POST /api/logout             — 登出
GET  /api/dashboard/stats    — 统计卡片数据
GET  /api/tasks              — 上传任务列表（分页+筛选+搜索）
GET  /api/uploaded           — 已上传列表
GET  /api/failed             — 失败记录列表
PUT  /api/tasks/:id          — 编辑任务
DELETE /api/tasks/:id        — 删除任务
POST /api/tasks/:id/retry    — 手动重传
POST /api/tasks/batch-delete — 批量删除
POST /api/tasks/batch-retry  — 批量重传
POST /api/manual/create      — 手动新增
POST /api/manual/import      — xlsx 导入
GET  /api/template/download  — 下载 xlsx 模板
```

### SELinux

- `data/` 和 `logs/` 目录需要设置 `httpd_sys_rw_content_t` 上下文
- `config/.env` 需要 `httpd_sys_content_t`（只读）

### Nginx 配置要点

- `root /usr/share/nginx/mashangfangxin/public`
- PHP 请求转发到 `127.0.0.1:9008`
- 静态资源直接返回
- 监听 `192.168.2.189:8188`

## Testing Decisions

### 测试原则
- 只测试外部行为，不测试实现细节
- 每个测试对应一个用户故事中的验收条件
- 使用 PHP 内置 web server + SQLite 内存数据库进行集成测试

### 测试范围
- **UploadService**：核心上传逻辑，mock ApiClient 和 LogWriter。验证单号拼接、超量拆分、重试逻辑、并发锁
- **ApiClient**：错误分类测试（NetworkException vs BusinessException）
- **TaskFetcher**：SQL 查询结果标准化
- **LogWriter**：JSONL 写入格式、SQLite 同步正确性
- **Auth**：登录成功/失败、session 过期
- **Web API**：各端点返回正确 HTTP 状态码和 JSON 结构

### 测试方法
- 在 `tests/` 目录下手写 PHP 测试脚本
- 运行 `php tests/run.php` 执行全部测试
- 每个测试用例打印 `PASS` 或 `FAIL + 原因`

## Out of Scope

- 多用户/角色权限管理
- 邮件/钉钉通知
- Docker 容器化部署
- CI/CD 集成
- 数据库迁移工具
- Redis 缓存
- 前端构建工具（Webpack/Vite）

## Further Notes

- 现有 `upload_test.php`、`get_ent_list_test.php`、`bill_info_test.php` 作为参考代码保留，不纳入新系统
- `top_sdk/` 目录不修改，仅通过 ApiClient 封装调用
- `src/SqlSrvHelper.php` 现有代码继续使用，必要时小幅扩展
- 系统需支持 SELinux Enforcing 模式下的文件读写
