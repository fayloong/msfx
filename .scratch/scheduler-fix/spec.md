**Status:** ready-for-agent

# 调度缺陷修复：check_bill_status 拆分 + 防并发 + 错峰 cron

## Problem Statement

`check_bill_status.php` 把两类不同性质的检查任务塞进同一个脚本、同一个循环、同一把时间预算：

1. **来源 1**：`upload_tasks` 中等待上传的任务（每天新注入几百个，`last_checked_at` 门卫放行新单）——高频、量大
2. **来源 2**：`upload_logs` 中的失败记录（请求失败 / 信息不存在 / 数量不符等）——低频、量小

来源 2 的记录被 push 在合并队列末尾，而来源 1 的待查队列长期是满的（fetch_bills 每半小时注入新任务，`last_checked_at IS NULL` 无条件立即查，每单 API 耗时 1s+，一轮 5 分钟占满）——**来源 2 永远轮不到**。实测证据：quantity_check 写入的 90 条失败记录（`last_checked_at=NULL`，按门卫天然立即查）从 14:48 到 16:30 原封未动，从未被复查。

另有三个关联缺陷：

- **无 flock 防并发**：一轮 5 分钟跑不完时 cron 会再起一个实例，多实例并发调同一 AppKey 是触发平台限流（`App Call Limited`）的放大器
- **check_quantity 与 check_bill_status 并发撞限流**：两者同时段运行必然触发平台限流（实测第一单即熔断），运行窗口必须错开
- （已修复）`database is locked`：check_quantity 与 fetch_bills 写库时间窗重叠，busyTimeout(30s) 已解决

业务后果：外部系统补传后，失败记录页的"信息不存在"/失败记录**不会自动消失**（需要新的"上传成功"记录才能被 failed.php 的 NOT EXISTS 逻辑隐藏，而新记录依赖来源 2 复查）。

## Solution

把两个队列拆成两个独立脚本，各自独立调度、独立 flock；全部分检查类脚本错峰到 check_bill_status 停跑的 20 点后。**不引入消息队列**（规模评估：日 ~725 单、单机、单消费者，SQLite upload_tasks 即现成队列，瓶颈在平台 API 限流而非本地处理能力；MQ 能解决的削峰/解耦/优先级本系统均不需要）。**不做优先级调度**（用户决策：新单与积压单先到先得即可，只要所有单据最终都能查到平台状态）。

- `check_bill_status.php`：只保留来源 1（等待上传任务），加 flock
- `check_failed_logs.php`（新）：来源 2 逻辑原样搬迁，加 flock，每天 20:40 跑一次（几十条，2 分钟内完成）
- `check_quantity.php`：cron 配 21:10（725 单 × 1s ≈ 12 分钟）
- 覆盖保证：任何单据最终都会被查到平台状态（等待上传 ≤30 分钟 / 失败记录 ≤24h / SQL Server 全量 ≤24h）

## User Stories

1. 作为运维人员，我想要失败记录（含 quantity_check 的"信息不存在"）每天能被复查平台状态，以便外部系统补传后失败记录页自动干净
2. 作为运维人员，我想要等待上传任务的复查频率不受失败记录复查影响，以便新单仍能 30 分钟内确认状态
3. 作为运维人员，我想要 check_bill_status 不会因一轮跑不完而启动多个并发实例，以便不叠加触发平台限流
4. 作为运维人员，我想要 check_quantity 的运行窗口与 check_bill_status 错开，以便全量检查不再撞 App Call Limited 熔断
5. 作为运维人员，我想要 check_failed_logs 有独立的运行频率可调（每天一次起步），以便未来失败记录增多时单独调整而不动高频检查
6. 作为运维人员，我想要所有检查类脚本的 cron 时间表集中记录在文档中，以便部署新环境时一次配齐
7. 作为运维人员，我想要拆出的 check_failed_logs 与 check_bill_status 保持相同的写入语义（更新失败记录/同步任务表/写 JSONL），以便不引入行为差异
8. 作为运维人员，我想要已知的 312 个积压任务继续按先到先得被复查（不插队），以便积压自然消化而不阻塞新单
9. 作为运维人员，我想要单脚本 flock 锁命名相互独立（check_bill_status / check_failed_logs / upload），以便不同任务可并行、同类任务不重入

## Implementation Decisions

### 1. 新脚本 check_failed_logs.php（来源 2 独立）

- 从 check_bill_status.php 原样搬迁来源 2 逻辑：查询 `upload_logs WHERE (response_status IS NULL OR response_status NOT IN ('上传成功','单据重复')) AND (last_checked_at IS NULL OR last_checked_at <= 阈值)` → 合并去重（按 djbh 首次遇到胜出）→ 逐个 `searchBillDetail`：
  - 平台存在 → `UPDATE upload_logs SET response_status='上传成功'` + 同步关联 upload_tasks（task_id>0 时标已处理）+ 写 JSONL（沿用 `_writeJsonl` 模式）
  - 信息不存在 → `UPDATE upload_logs SET updated_at, last_checked_at`（保持状态）
  - API 异常 → 跳过不修改
- 循环内"已确认在平台跳过"去重保留（`upload_logs` 已有上传成功/单据重复记录时不调 API）
- 加 flock（`logs/check_failed_logs.lock`，LOCK_EX|LOCK_NB，获取失败直接退出）
- 保留 30 分钟门卫常量（每天一次运行时形同虚设但无害，避免行为差异）

### 2. check_bill_status.php 缩减 + flock

- 删除来源 2 的拉取与处理分支（约 60 行），只保留来源 1（等待上传任务）
- 加 flock（`logs/check_bill_status.lock`）
- 循环开头"已确认在平台跳过"去重判断保留（upload_tasks 来源标记已处理）
- 来源 1 每轮 5 分钟跑不完是**可接受状态**（只剩一个队列，下一轮续跑即可，不再饿死其他队列），不加限量

### 3. cron 时间表（错峰设计，全部避开 check_bill_status 8-20 点窗口）

| 脚本 | cron | 说明 |
|------|------|------|
| fetch_bills（采集） | `0,30 0,1,2,3,8-23 * * *`（现状不变） | 写库与检查脚本的 SQLite 锁冲突由 busyTimeout(30s) 兜底 |
| check_bill_status（来源 1） | `*/5 8-20 * * *`（现状不变） | 高频确认新单 |
| check_failed_logs（来源 2） | `40 20 * * *`（新增） | 20:40，fetch_bills 20:30 轮已结束、21:00 轮未到 |
| check_quantity | `10 21 * * *`（新增，当前未配 cron） | 21:10，fetch_bills 21:00/21:30 两轮之间 |

### 4. 决策记录（不引入 MQ、不做优先级）

- **不引入消息队列**：日 ~725 单、单机、单消费者；瓶颈是平台 API 限流（usleep + 错峰已等效）；SQLite upload_tasks 即现成持久化队列（天然"至少一次"语义）；引入 MQ 的成本（新服务部署/运维/幂等/死信处理）远超收益
- **不做优先级调度**：新单与积压单先到先得，312 个积压任务自然消化
- **不处理业务积压**：312 个等待上传任务（07-27 至 08-17）是外部系统未上传导致，属业务问题，由业务侧确认，不在本 spec 范围

## Testing Decisions

- **不新增测试接缝**（用户确认）：新脚本是纯编排逻辑（查 SQL → 循环调 API → 写库），唯一可单测的判定 `ApiClient::isBillFound` 已有 `tests/quantity_check_test.php` 覆盖；脚本行为用真实运行回归验证
- **复用现有测试**：`tests/quantity_check_test.php`（isBillFound，7 项断言）——不新增测试文件
- **真实运行回归**（延续本次会话模式）：拆分后手动触发 `check_failed_logs.php`，验证：失败记录被复查（平台存在的翻成"上传成功"、不存在的保持"信息不存在"并 touch）、flock 生效（锁被持有时代码退出而非并发）、错峰窗口内不与 check_bill_status 冲突
- **验证断言**：quantity_check 的"信息不存在"记录在外部已上传单上消失（被翻成"上传成功"或同单上传成功记录隐藏）；锁文件存在时第二次运行立即退出

## Out of Scope

- 引入消息队列 / 优先级队列（已决策不引入）
- 312 个积压等待上传任务的处理（业务问题，外部系统确认）
- check_bill_status 来源 1 每轮超窗口（可接受状态，不加限量）
- 平台限流配额的精确探测（App Call Limited 阈值未知，以熔断+错峰策略规避）
- 失败记录复查频率提升（每天一次起步，未来按需调整 cron）
- 通知渠道（告警出口仍为 Web 失败记录页）

## Further Notes

- 领域语言精确化（grilling 结论）：本问题是**调度（何时跑什么）+ 并发控制（防重入 flock）+ 节流（对齐平台限速）的复合问题**，不是单一"调度问题"；业务积压不属于技术问题
- 与 ADR 0002 的关系：quantity_check 的"信息不存在"记录依赖 check_failed_logs 复查翻转为"上传成功"——两者配合完成"未上传 → 补传 → 记录自动干净"的闭环
- 拆脚本时注意保持 `_writeJsonl` 行写函数（LogWriter 只支持 INSERT，UPDATE 场景沿用行写模式）
- 实施顺序建议：拆 check_failed_logs → 减 check_bill_status → 两脚本加 flock → 配 crontab（3 行：check_failed_logs 20:40、check_quantity 21:10；check_bill_status 保留）→ CLAUDE.md 同步 → 手动触发回归
- CLAUDE.md 同步点：架构图 scripts/ 列表、核心数据流"批量查询上传状态"章节、常用命令、cron 时间表
