**Status:** ready-for-agent

# 状态字段拆分

## Problem Statement

当前 `upload_tasks` 和 `upload_logs` 表各自只有一个字段来描述"状态"（分别是 `status` TEXT 和 `success` INTEGER），导致两种不同性质的信息被混在一起：

1. **HTTP 请求是否成功** — 能否成功调用码上放心 API 并拿到响应（只有成功/失败两种）
2. **API 响应的业务结果** — 上传成功、单据重复、信息不存在等（取决于返回 JSON 的 `msg_code` 等字段）

这种混杂导致：
- 无法单独查询"请求失败"的记录进行网络问题排查
- `upload_tasks.status` 同时承担工作流阶段（等待上传/上传中）和最终结果（已上传/任务失败）两种语义
- `上���中` 只在内存中短暂存在就被覆盖，持久化意义不大
- `部分上传成功` 在 UI 中显示但从被后端写入过
- `upload_logs.success` 是 0/1 整型，与 `upload_tasks.status` 的文本类型不一致

## Solution

将单一状态拆分为三个独立字段，各司其职：

| 字段 | 作用 | 表 |
|------|------|----|
| `task_status` | 工作流阶段（尚未上传 / 已处理） | `upload_tasks` |
| `request_status` | HTTP 请求是否成功 | `upload_tasks` + `upload_logs` |
| `response_status` | API 响应的业务结果 | `upload_tasks` + `upload_logs` |

两个表的命名和取值完全统一，消除 `success` 整型与 `status` 文本的不一致。

## User Stories

1. 作为运维人员，我想要区分"网络不通导致的失败"和"业务层面的失败"，以便针对性地排查问题
2. 作为运维人员，我想要在已处理的任务记录中看到 API 的具体响应状态（上传成功/单据重复/信息不存在等），以便了解失败原因
3. 作为操作员，我在上传任务页面点击刷新后，想要看到所有"等待上传"的单据，以便发起上传
4. 作为操作员，我想要了解每个任务的最新处理结果——是请求就没发出去，还是请求成功了但业务处理失败
5. 作为系统管理员，我想要日志表（`upload_logs`）和任务表（`upload_tasks`）对同一次 API 调用记录的状态字段语义一致，以便跨表查询
6. 作为开发人员，我想要查询所有 `response_status = '单据重复'` 的记录来评估 cron 去重逻辑是否有效
7. 作为开发人员，我想要查询所有 `request_status = '请求失败'` 的记录来分析网络稳定性
8. 作为开发人员，我想要历史数据通过一次性迁移脚本完成映射，迁移后旧 `status` 和 `success` 字段被删除
9. 作为操作员，我在查看 `check_bill_status` 脚本产出的未上传记录时，想要看到这些记录出现在待上传列表中（`task_status = '等待上传'`），同时能知道它们是从平台查回来的（`response_status = '信息不存在'`）
10. 作为操作员，我不想要在状态筛选中看到"部分上传成功"（从未实现过），我想要未来看到"部分解析成功"（一个单 100 个码平台只认 90 个）

## Implementation Decisions

### 1. 数据库 schema 变更

**`upload_tasks` 表：**
- 删除 `status` 列
- 新增 `task_status TEXT DEFAULT '等待上传'` — 工作流阶段，取值：`等待上传`、`已处理`
- 新增 `request_status TEXT DEFAULT NULL` — API 请求结果，取值：`请求成功`、`请求失败`、NULL（尚未发起请求）
- 新增 `response_status TEXT DEFAULT NULL` — API 响应业务结果，取值：`上传成功`、`单据重复`、`上传失败`、`信息不存在`、`往来单位缺失`、`未确定`、NULL（请求失败或无请求）
- 保留 `resp` 列不变（存储 API 原始 JSON，用于排查和迁移）

**`upload_logs` 表：**
- 删除 `success` 列
- 新增 `request_status TEXT DEFAULT NULL` — 取值同上
- 新增 `response_status TEXT DEFAULT NULL` — 取值同上

两个表的新字段共享相同的取值集合，类型均为 TEXT。

### 2. 字段语义约定

- `request_status = NULL` 且 `response_status = NULL` ⇒ 尚未发起过 API 请求
- `request_status = '请求失败'` ⇒ `response_status` 必须为 NULL（没有响应可解析）
- `request_status = '请求成功'` ⇒ `response_status` 必须有值（成功拿到了 JSON 响应，不管是 SUCCESS 还是 FAIL）
- `task_status` 仅在 `upload_tasks` 中存在，表示该任务在工作流中是否已处理完毕

### 3. `UploadService` 改造

`updateTaskStatus()` 方法需改为接受三个参数（task_status、request_status、response_status），在收到 ApiClient 返回结果后：

- 判断 `$result['is_network_error']` 来设置 `request_status`
- 解析 `$result['data']` 中的 `msg_code` 和 `msg_info` 来设置 `response_status`
- 无论成功与否，`task_status` 设为 `已处理`

`response_status` 的判定逻辑：

- `msg_code == 'SUCCESS'` 且 `response_success == 'true'` → `上传成功`
- `msg_info` 包含 `该单据号已存在` → `单据重复`
- `msg_code == 'FAIL_BIZ_NO_PAT_INFO'` → `信息不存在`
- `msg_code == 'FAIL'` 且非重复 → `上传失败`
- 其他无法识别 → `未确定`

涉及拆分上传（`splitBillCodes`）时，每个子单独立设置自己的状态，不做聚合。

### 4. `LogWriter` 改造

`write()` 方法不再接收 `$success` 布尔参数，改为接收 `$requestStatus` 和 `$responseStatus` 字符串参数。写入 `upload_logs` 时直接存储这两个字段。

### 5. 调用方改造

所有调用 `UploadService::updateTaskStatus()` 和 `LogWriter::write()` 的地方需更新参数：
- `scripts/cron_upload.php`
- `src/api/tasks_retry.php`
- `src/api/tasks_batch_retry.php`
- `src/api/manual_create.php`
- `src/api/manual_import.php`
- `src/api/check_bill_status.php`

手动创建任务时（manual_create.php、manual_import.php），新记录 `task_status = '等待上传'`，`request_status = NULL`，`response_status = NULL`。

`check_bill_status.php` 查到未上传的单据：`task_status = '等待上传'`，`request_status = '请求成功'`，`response_status = '信息不存在'`。

### 6. 重传逻辑适配

重传前状态判断改为检查 `task_status`：
- 可重传：`task_status = '等待上传'` 或 `task_status = '已处理'`（排除正在上传中的）
- 由于 `task_status` 不再有 `上传中` 这个值，用 `request_status = NULL` + `task_status = '等待上传'` 来识别未处理的任务

### 7. 前端改造

- `upload_tasks.php` 页面的状态筛选下拉框替换为 `task_status` + `response_status` 两个筛选维度
- 删除 `部分上传成功` badge，新增 `部分解析成功` badge（预留，暂无后端数据）
- `dashboard.php` 的"待处理"计数改为 `WHERE task_status = '等待上传'`
- `uploaded.php` 页面筛选改为 `response_status = '上传成功'`
- `failed.php` 页面筛选改为 `response_status != '上传成功'` 或 `request_status = '请求失败'`
- `statusBadges` 颜色映射：`upload_tasks` 的 `task_status` 单独配色，`response_status` 在每个视图有自己的配色

### 8. 数据迁移脚本

独立的 PHP 脚本，一次性执行，完成以下步骤：

1. ALTER TABLE `upload_tasks` ADD COLUMN `task_status`、`request_status`、`response_status`
2. ALTER TABLE `upload_logs` ADD COLUMN `request_status`、`response_status`
3. 遍历 `upload_tasks`，根据旧 `status` + `resp` JSON 解析填充新字段
4. 遍历 `upload_logs`，根据旧 `success` + `response` JSON 解析填充新字段
5. 数据校验（计数对比）
6. ALTER TABLE 删除旧列（`status`、`success`）

旧到新的映射规则：

| 旧 status | task_status | request_status | response_status |
|-----------|-------------|----------------|-----------------|
| 等待上传 | 等待上传 | NULL | NULL |
| 上传中 | 等待上传 | NULL | NULL |
| 已上传 | 已处理 | 请求成功 | 从 resp 解析 |
| 任务失败 | 已处理 | 请求成功 | 从 resp 解析 |
| 部分上传成功 | 已处理 | 请求成功 | 从 resp 解析 |

resp JSON 解析 → response_status 映射：

| resp 特征 | response_status |
|-----------|-----------------|
| `msg_code == 'SUCCESS'` 且 `response_success == 'true'` | 上传成功 |
| `msg_info` 包含 `该单据号已存在` | 单据重复 |
| `msg_code == 'FAIL'` 且非重复 | 上传失败 |
| `msg_code == 'FAIL_BIZ_NO_PAT_INFO'` | 信息不存在 |
| 包含 `无法获取往来单位ent_id` | 往来单位缺失 |
| 其他/无法判断 | 未确定 |

### 9. `upload_logs` 旧日志迁移

`upload_logs` 表中的 `response` 列是 JSONL 格式（ApiClient 返回值序列化的 JSON），结构与 `upload_tasks.resp` 一致，可以复用相同的解析逻辑。

旧 `success = 1` → `request_status = '请求成功'`（API 调用成功拿到响应）
旧 `success = 0` → 需根据 `response` 内容判断：
- `is_network_error = true` → `request_status = '请求失败'`
- 其他 → `request_status = '请求成功'`

## Testing Decisions

### 测试原则

- 只测试外部可观察行为（数据库中的字段值、API 返回的 JSON 结构），不测试内部实现细节
- 迁移脚本需在真实 SQLite 数据库备份上验证
- `UploadService` 的单元测试重点验证 response_status 各分支的判定正确性

### 测试 seam

1. **`UploadService::updateTaskStatus()`** — 上传后状态写入的集中点，修改此方法即可同时影响 cron / 手动 / 重传 / 批量重传路径
2. **`LogWriter::write()`** — 日志写入的集中点，所有 `upload_logs` 记录都经过此方法

这两个是现有的高层 seam，不需要新建接口。

### 测试范围

- 迁移脚本：在 SQLite 数据库备份上验证 ALTER TABLE + 数据映射的正确性
- `UploadService` 状态判定：针对每种 response_status 类型构造 ApiClient 返回值，验证写入的字段值
- `LogWriter` 参数变更：验证新的 request_status / response_status 参数正确写入
- 前端视图：验证页面筛选显示正确，不再出现旧 status 字段引用

### 测试先例

现有代码库无自动化测试框架。测试通过以下方式进行：
- PHP CLI 脚本直接执行并检查 SQLite 数据
- 浏览器访问页面验证 UI 显示和筛选功能

## Out of Scope

- `部分解析成功` 的自动判定逻辑 — 当前日志中没有实例，等遇到后再补充
- 追溯码拆分场景下的聚合统计（子单独立，不做部分成功汇总）
- 新增自动化测试框架
- `upload_tasks` 的重试次数/重试历史记录
- 修改 `ApiClient` 的返回值结构

## Further Notes

- `upload_logs` 中 `task_id = 0` 的记录（check_bill_status.php 产生的已上传记录）在迁移时需要一并处理
- `init_db.php` 需同步更新建表语句，确保新部署时 schema 正确
- UI 中的 `statusBadges` 颜色映射需分拆为 `taskStatusBadges` 和 `responseStatusBadges` 两组
- 前端筛选器需从单一 `status` 下拉升级为组合筛选（task_status + response_status），或保留单一筛选但查询条件改为对应到新字段
