**Status:** ready-for-agent

# 拆分 cron_upload 为采集和上传

## Problem Statement

当前 `cron_upload.php` 将「从 SQL Server 拉取单据写入 upload_tasks」和「调用码上放心 API 执行上传」绑定在一次执行中。这两个操作的节奏需求不同——采集需要高频以尽早捕获新单据，上传则可以集中在固定时段执行以统一管理 API 调用。绑在一起导致：要么采集不够及时（每天只跑一次），要么上传过于频繁（API 压力分散）。

另外，`check_bill_status.php` 查到的未上传单据（source=batch_check）写入 upload_tasks 后需要用户在网页上手动重传，缺乏自动化机制。

## Solution

将 cron_upload 拆分为两个独立脚本，各自有独立的 cron 调度：

- `fetch_bills.php`（采集）：从 SQL Server 拉取当天单据 → 去重后写入 upload_tasks
- `upload_pending.php`（上传）：读取所有 `task_status='等待上传'` 的任务 → 调用 UploadService 统一上传

手动上传（manual_create、manual_import）保持立即上传不变，两套上传路径并存但互不干扰。

## User Stories

1. 作为运维人员，我想要每小时自动从 ERP 采集新单据到上传队列，以便及时发现和处理当天产生的新出入库单
2. 作为运维人员，我想要上传操作集中在每天固定时段执行（12:05、18:05、22:30），以便统一管理 API 调用和降低平台限流风险
3. 作为运维人员，当重复采集同一单据时，系统应自动跳过已存在的单号，避免重复插入
4. 作为运维人员，我想要所有未上传的任务（不论来源是 cron 还是 batch_check）在同一上传流程中被处理，不需要手动重传 batch_check 产生的任务
5. 作为运维人员，当上传失败时，任务应在队列中保留并在下次上传时重试
6. 作为操作员，手动上传仍然能立即看到 API 返回结果，不受批量上传调度的影响
7. 作为操作员，在采集和上传之间的时段，我可以在上传任务页面看到待上传的单据列表
8. 作为开发人员，旧 cron_upload 遗留的无 bill_type 任务在新上传脚本中应能通过 djbh 前缀 fallback 正确处理

## Implementation Decisions

### 1. 新建 fetch_bills.php（采集脚本）

- 接受可选日期参数 `$argv[1]`（Y-m-d），默认当天
- 调用 `TaskFetcher::fetchBills($date)` 获取 SQL Server 单据
- 去重逻辑：查询 `upload_tasks` 中 `djbh` 是否已存在（不限 status 和 source），存在则跳过
- 新单 INSERT 时写入 `bill_type` 字段（TaskFetcher 返回的 `type`，即单据号前 3 位字母前缀如 "JHG"、"XSO"）
- INSERT 时 `task_status='等待上传'`、`source='cron'`
- 不调 UploadService，不接触 API

### 2. 新建 upload_pending.php（上传脚本）

- 不接受参数
- 查询 `upload_tasks WHERE task_status = '等待上传'`——不限 source、不限日期
- 将 upload_tasks 行映射为 UploadService 需要的入参格式：
  - `bill_type`（非空）→ `type`；`bill_type` 为空时 fallback 为 `substr(djbh, 0, 3)`
  - `rq` → `rq`
  - `djbh` → `djbh`
  - `ent_name` → `ent_name`
  - `trace_codes` → `sn`
  - `id` → `task_id`（int）
- 调用 `UploadService::upload($bills)`，上传后 UploadService 自动更新各 task 的 task_status / request_status / response_status
- 文件锁由 UploadService 内部的 flock 处理，upload_pending 不额外加锁

### 3. bill_type 兼容性

两种脚本写入 upload_tasks 的 bill_type 格式不同，需统一处理：

- 手动上传（manual_create/manual_import）：`bill_type` = 3 位数字码（如 "201"、"102"）
- fetch_bills.php（新 cron）：`bill_type` = 字母前缀（如 "JHG"、"XSO"）
- 旧 cron_upload.php（遗留）：`bill_type` = ""（空）

upload_pending.php 的 fallback 逻辑 `!empty(bill_type) ? bill_type : substr(djbh, 0, 3)` 覆盖全部三种情况。UploadService 内部已支持两种格式（数字码直接使用，字母前缀通过 `$billTypeMap` 映射）。

### 4. 删除 cron_upload.php

完全被两个新脚本取代，删除。同步更新 CLAUDE.md 和 CONTEXT.md 中的脚本引用。

### 5. 不动范围

- `src/UploadService.php`：接口和逻辑不变，已支持文件锁 + 双格式 bill_type
- `src/TaskFetcher.php`：fetchBills 接口不变
- `src/api/manual_create.php`：保持 INSERT + 立即上传 + 实时反馈
- `src/api/manual_import.php`：同上
- `scripts/check_bill_status.php`：不变（其产生的 batch_check 任务由 upload_pending 统一上传）
- `data/msfx.db` schema：bill_type 列已存在，无需迁移

### 6. 调度配置

```
0 8-22 * * * php scripts/fetch_bills.php        # 8:00-22:00 每小时
5 12 * * *   php scripts/upload_pending.php      # 12:05
5 18 * * *   php scripts/upload_pending.php      # 18:05
30 22 * * *  php scripts/upload_pending.php      # 22:30
```

## Testing Decisions

### 测试原则

- 只验证脚本执行后的数据库状态，不测试中间步骤
- 最高层 seam 是 CLI 入口点：`php scripts/fetch_bills.php [date]` 和 `php scripts/upload_pending.php`

### 测试场景

| 场景 | 脚本 | 验证点 |
|------|------|--------|
| 正常采集 | fetch_bills | upload_tasks 新增行 source='cron', task_status='等待上传', bill_type 非空 |
| 同日期重复采集 | fetch_bills ×2 | 第二次全部跳过（djbh 已存在） |
| 无新单据 | fetch_bills | exit 0，无新增 |
| 正常上传 | upload_pending | task_status 变为已处理，upload_logs 有对应记录 |
| 无待上传任务 | upload_pending | exit 0，不调 API |
| 遗留任务（无 bill_type）| upload_pending | 通过 djbh 前缀 fallback 正确上传 |
| 手动任务崩溃（留在等待上传）| upload_pending | 被正常处理 |

### 测试先例

项目无自动化测试框架。通过以下方式验证：
- PHP CLI 直接执行脚本，观察 stdout 输出
- `sqlite3` 查询 SQLite 数据库验证状态变化

## Out of Scope

- 修改 UploadService 或 ApiClient 的接口
- 改变手动上传的行为
- 新增自动化测试框架
- 修改 Web 前端页面
- 修改 check_bill_status.php 的数据来源
- 实施 crontab 变更（由运维人员自行配置）

## Further Notes

- 采集到上传之间存在时间窗口，已采集但未上传的单据会出现在上传任务页面，用户可以看到队列状态
- upload_pending 处理所有 `等待上传` 任务，若某 task 在采集后被用户手动重传（已变为已处理），upload_pending 不会再处理它
- 旧 cron_upload.php 的「过滤已上传成功的单据」逻辑被 fetch_bills 的更严格去重取代（只要 djbh 存在就跳过），避免重复插入
