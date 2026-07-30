# CONTEXT.md

## 领域词汇表

### 核心实体

- **上传任务 (Upload Task)**：待上传到码上放心平台的出入库单据。包含单据日期（`rq`，来自 SQL Server）、单号、往来单位、追溯码列表、任务状态（`task_status`）、API 响应状态（`request_status` / `response_status`）、任务创建时间（`created_at`）。来源可能是 cron 定时从 SQL Server 抓取，也可能是用户在 Web 端手动创建。存储在 SQLite `upload_tasks` 表。

- **上传日志 (Upload Log)**：每次 API 调用的结果记录。与上传任务通过 `task_id` 关联。包含单据日期（`rq`，回填自 upload_tasks 或 SQL Server）、单号、往来单位、追溯码、请求状态（`request_status`）、响应状态（`response_status`）、API 返回内容、任务创建时间（`created_at`，即 API 调用时间）。存储在 SQLite `upload_logs` 表，同时写入 JSONL 文件永久保存。

- **单据 (Bill)**：ERP 系统中的出入库单。类型分入库（1xx：102 采购入库, 103 退货入库, 104 调拨入库, 107 供应入库, 108 召回入库, 110 赠品入库, 111 盘盈入库, 112 报废入库, 113 其他入库）和出库（2xx：201 销售出库, 202 退货出库, 203 调拨出库, 204 返工出库, 205 销毁出库, 206 抽检出库, 207 直调出库, 209 供应出库, 211 召回出库, 212 赠品出库, 214 盘亏出库, 215 损坏出库, 216 报废出库, 217 其他出库, 237 直调退货）。在 SQL Server 中以 `djbh` 作为唯一标识。cron 上传时类型由 `djbh` 前 3 位（如 XSO/XST/JHG/JHO）经映射表转为数字码；手动上传时用户直接在下拉菜单选择数字类型码。

- **追溯码 (Trace Code)**：药品电子监管码，字符串类型。一个单据对应多个追溯码，以英文逗号分隔拼接为长文本。API 限制单次上传最多 3500 个追溯码，超出时自动拆分为 `单号_1, 单号_2...`。

- **往来单位 (Partner Enterprise)**：单据的对方企业（供应商或客户）。具有 `ent_name`（企业名称）、`ent_id`（阿里健康企业 ID）、`ref_ent_id`（企业编码）属性。首次遇到的往来单位通过 API 在线查询并缓存到本地 SQLite `ent_list` 表。

- **单号 (Bill Code)**：单据编号，如 `JHGWMS00061116`。cron 上传时前 3 位标识单据类型（XSO/XST/JHG/JHO）；手动上传时单据类型由用户从下拉菜单独立选择。拆分时衍生为 `单号_1, 单号_2...`。

### 核心流程

- **定时上传 (Cron Upload)**：每天执行 `scripts/cron_upload.php` → TaskFetcher 查询 SQL Server → 按单号拼装追溯码 → 写入 upload_tasks（source=cron）→ 拆分超 3500 的单号 → UploadService 调码上放心 API（含 ent_list 缓存查找、3 次重试、0.33s 限速）→ LogWriter 写 JSONL + SQLite。总耗时约 6-7 分钟（530 条单据）。

- **批量查询上传状态 (Batch Check)**：执行 `scripts/check_bill_status.php` → TaskFetcher 查询 SQL Server → 逐个调 `ApiClient::searchBillDetail()` 查询单据是否在平台存在 → 已上传的通过 LogWriter 写入 upload_logs → 未上传的写入 upload_tasks（source=batch_check, status=任务失败）+ upload_logs（关联 task_id）方便重传。API 间隔 0.5s。

- **手动上传 (Manual Upload)**：

  - **在线新增**：用户选择单据类型（必选下拉，分组展示全部入库/出库类型）→ 填写日期/单号/往来单位 → 粘贴追溯码（支持一行一个或逗号分隔，JS 自动转逗号）→ 写入 SQLite → 立即上传。
  - **xlsx 导入**：下载模板（5 列：日期/单号/单据类型/往来单位/追溯码），同单号多行自动合并为一个任务（取首个非空的日期/单据类型/往来单位，合并所有追溯码）。也兼容传统一行一个单据格式。
  - 上传逻辑与 cron 共用 UploadService。

- **Web 管理**：Bootstrap 5 + flatpickr，左侧可折叠菜单，AJAX 交互。三个数据页面均支持按单号/往来单位/状态/单据日期/任务创建时间筛选，日期使用 flatpickr 范围选择器（一个输入框选起止日期），分页最多 10 个页码。管理上传任务（查看/编辑/删除/重传/批量操作）、浏览上传日志（已上传/失败记录）、手动新增单据。

- **重传 (Retransmit)**：对失败任务重新发起上传调用。区分网络超时（重试最多 3 次，间隔 30s）和 API 业务错误（不重试，直接标记失败）。

### 任务状态机

```
等待上传 → 已处理
```

- **等待上传**：任务已创建，尚未发起上传
- **已处理**：上传完成（不论成功或失败），具体结果见 `request_status` 和 `response_status` 字段

`request_status`（请求状态）：请求成功 / 请求失败
`response_status`（响应状态）：上传成功 / 单据重复 / 上传失败 / 信息不存在 / 往来单位缺失 / 未确定

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

- **SQL Server (192.168.2.133)**：ERP 数据库，`hyyy_zyscm` 库 + `skwms_new` 库。cron 定时查询源。通过 `TaskFetcher` 访问。
- **码上放心 API (gw.api.taobao.com)**：阿里健康药品追溯平台，通过 TOP SDK 调用。凭据配置在 `config/.env`（APPKEY_HYYY / SECRETKEY_HYYY / ENTID_HYYY / REFENTID_HYYY）。
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
