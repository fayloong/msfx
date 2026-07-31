**Status:** ready-for-agent

# 批量查询单据状态 — 扩大检查范围

## Problem Statement

当前 `check_bill_status.php` 只检查 SQL Server 中当天产生的单据是否已上传到码上放心平台。但实际上，"上传任务"页面和"失败记录"页面中也有大量待确认的单据——它们可能后来被其他途径上传了，或者因为临时的网络问题被标记为失败但实际已上传成功。

运维人员需要一种方式，能够对这三个来源的所有待确认单据统一执行一次平台查询，把已上传的转移走，把确实还不存在的保留并更新检查时间。

## Solution

扩展 `check_bill_status.php` 的数据来源，从单一的 SQL Server 扩展为三个来源合并。对合并去重后的每条单据调码上放心查询 API，根据查询结果和单据来源执行不同的更新策略。

## User Stories

1. 作为运维人员，我想要对"上传任务"页面中所有等待上传的单据执行平台查询，以便发现那些已经通过其他途径上传成功的单据并移出待处理列表
2. 作为运维人员，我想要对"失败记录"页面中的所有记录执行平台查询，以便发现那些实际已上传成功但被标记为失败的单据，将其转移到已上传记录
3. 作为运维人员，我想要一次脚本运行就能同时检查 SQL Server 当天数据、上传任务和失败记录，而不是分别执行不同的脚本
4. 作为运维人员，当查询结果显示单据在平台上仍不存在时，我想要记录更新为当前时间，以便了解这条记录最后一次被检查是什么时候
5. 作为运维人员，当 API 调用出现网络错误时，我不希望原有数据被修改，以便保留原始状态等网络恢复后重新检查
6. 作为操作员，已经通过上传任务或重传成功上传的单据，不应该在批量检查时被重复处理（已被已有去重逻辑覆盖）

## Implementation Decisions

### 1. 数据来源扩展

脚本从单一来源扩展为三个来源：

- **来源 1 — SQL Server**（现有逻辑）：通过 `TaskFetcher::fetchBills($date)` 获取指定日期的单据，日期参数 `$argv[1]` 仅作用于此处
- **来源 2 — upload_tasks**：查询所有 `task_status = '等待上传'` 的记录，不限日期
- **来源 3 — upload_logs**：查询所有 `success = 0`（即 response_status 不为 '上传成功'）的记录，不限日期（受 SQLite 3 个月保留窗口约束）

每个来源产出的数据结构统一为包含 `djbh`、`ent_name`、`trace_codes`、`rq` 和一个标识来源的标记字段。

### 2. 合并去重

三个来源的数据按顺序（SQL Server → upload_tasks → upload_logs）合并到一个数组，按 `djbh` 去重。首次遇到的记录胜出，其 `ent_name`、`trace_codes`、`rq` 和来源标记作为该 djbh 的主记录。后续重复的 djbh 被丢弃，其关联的二级记录不做处理。

合并完成后，现有的"已确认上传成功则跳过"查询（检查 upload_logs 中是否已有 response_status = '上传成功' 的记录）在合并后的统一列表上执行。

### 3. 按来源分化的结果处理

对每条合并后的记录调用 `ApiClient::searchBillDetail($djbh)`，根据 API 返回结果和记录的来源标记执行不同操作：

**API 返回成功（单据在平台存在）：**

| 来源 | 操作 |
|------|------|
| SQL Server | LogWriter::write() 写新 upload_logs（request_status=请求成功, response_status=上传成功, task_id=0）。与现有逻辑一致。 |
| upload_tasks | UPDATE upload_tasks SET task_status='已处理', response_status='上传成功', request_status='请求成功', updated_at=now；LogWriter::write() 写新 upload_logs（关联 task_id）。 |
| upload_logs | UPDATE upload_logs SET response_status='上传成功', request_status='请求成功', updated_at=now；同时手动写 JSONL 记录本次状态变更。若该 log 的 task_id > 0，则同步 UPDATE 关联的 upload_tasks SET task_status='已处理', response_status='上传成功'。 |

**API 返回「信息不存在」（单据在平台不存在）：**

| 来源 | 操作 |
|------|------|
| SQL Server | INSERT INTO upload_tasks（task_status='等待上传', source='batch_check', request_status='请求成功', response_status='信息不存在'）+ LogWriter::write() 写 upload_logs（success=0, 关联 task_id）。与现有逻辑一致。 |
| upload_tasks | UPDATE upload_tasks SET updated_at=now（保留原始 created_at 和其他字段不变）。 |
| upload_logs | UPDATE upload_logs SET updated_at=now（保留原始 created_at 和其他字段不变）。 |

**API 异常（网络超时/错误）：**

| 来源 | 操作 |
|------|------|
| SQL Server | LogWriter::write() 写 upload_logs（response_status=null）。与现有逻辑一致。 |
| upload_tasks | 跳过，不修改原记录。 |
| upload_logs | 跳过，不修改原记录。 |

### 4. Schema 变更

`upload_logs` 表新增 `updated_at` 字段：

```sql
ALTER TABLE upload_logs ADD COLUMN updated_at TEXT DEFAULT NULL;
```

`init_db.php` 建表语句同步更新，确保新部署时包含此字段。

### 5. JSONL 写入

LogWriter 现有 `write()` 方法只支持 INSERT。UPDATE upload_logs 的场景需要单独写 JSONL 行（保持永久日志完整），格式与 LogWriter 输出一致，额外标记 `action: 'update'` 以便区分新增和更新。

### 6. 执行流程

```
1. 从 SQL Server 拉取当天单据（日期参数）
2. 从 upload_tasks 拉取 task_status='等待上传' 的记录
3. 从 upload_logs 拉取 success=0 的记录
4. 三源合并，按 djbh 去重，记录来源
5. 过滤已确认上传成功的 djbh（现有去重逻辑）
6. 逐条调 searchBillDetail API
7. 根据来源 + API 结果 → 执行对应的 UPDATE/INSERT/跳过
8. 输出统计：已上传 / 未上传 / 跳过 / 异常（保持现有输出格式）
```

API 限速保持现有 500ms 间隔不变。无需文件锁（与 cron_upload 不冲突）。

## Testing Decisions

### 测试原则

- 只验证数据库最终状态，不测试中间步骤
- 对每种来源 × API 结果的组合，验证对应的数据库变更是否正确

### 测试 seam

最高层 seam 是 CLI 入口 `php scripts/check_bill_status.php [date]`。通过以下方式验证：

1. 准备 SQLite 测试数据：在 upload_tasks 和 upload_logs 中插入已知状态的记录
2. 执行脚本（可指定日期或使用当天）
3. 检查 SQLite 最终状态是否符合预期

### 测试场景

| 来源 | API 结果 | 验证点 |
|------|---------|--------|
| SQL Server | 上传成功 | upload_logs 新增 success=1 记录 |
| SQL Server | 信息不存在 | upload_tasks 新增 task_status=等待上传 记录 + upload_logs 新增 success=0 记录 |
| upload_tasks | 上传成功 | task 更新为已处理/上传成功 + upload_logs 新增 success=1 记录 |
| upload_tasks | 信息不存在 | task 的 updated_at 更新为当前时间 |
| upload_tasks | API 异常 | task 记录不变 |
| upload_logs | 上传成功 | log 更新为上传成功 + 关联 task（如有）更新为已处理 |
| upload_logs | 信息不存在 | log 的 updated_at 更新为当前时间 |
| upload_logs | API 异常 | log 记录不变 |
| 任意 | 已确认上传成功 | 跳过，不调 API |

### 测试先例

项目无自动化测试框架。验证方式：
- 在测试/ staging SQLite 数据库上手动构造数据后执行脚本
- `sqlite3 data/msfx.db "SELECT ..."` 检查结果
- 浏览器访问对应页面确认显示正确

## Out of Scope

- 给 upload_tasks 或 upload_logs 增加重试次数追踪
- 新增自动化测试框架
- 修改 cron_upload.php 或其他上传脚本的行为
- 前端页面改动
- 新增 API 端点
- upload_logs 的 `action` 字段（仅 JSONL 行中标记）

## Further Notes

- `upload_logs` 的 `task_id = 0` 记录（当前 check_bill_status.php 对 SQL Server 已上传结果产生的日志）在扩展后仍会出现，处理逻辑不变
- 合并顺序（SQL Server > upload_tasks > upload_logs）决定了数据优先级，SQL Server 数据最新
- 「信息不存在」只更新 updated_at 不更新 created_at，所以页面上的"任务创建时间"保持不变，但后台可以区分"创建时间"和"最后检查时间"
- 脚本仍然接受可选的日期参数，行为和之前完全兼容
