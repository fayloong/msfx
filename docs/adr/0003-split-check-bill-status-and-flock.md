# ADR 0003: check_bill_status 拆分来源 + flock 防并发 + 错峰 cron

- 状态：已接受（2026-08-17）
- 关联代码：`scripts/check_bill_status.php`、`scripts/check_failed_logs.php`、`scripts/check_quantity.php`

## 背景

`check_bill_status.php` 把两类不同性质的检查任务塞进同一个脚本、同一个循环、同一把时间预算：

1. **来源 1**：`upload_tasks` 中等待上传的任务（每天新注入几百个，`last_checked_at` 门卫放行新单）——高频、量大
2. **来源 2**：`upload_logs` 中的失败记录（请求失败 / 信息不存在 / 数量不符等）——低频、量小

来源 2 的记录被 push 在合并队列末尾，而来源 1 的待查队列长期是满的（fetch_bills 每半小时注入新任务，`last_checked_at IS NULL` 无条件立即查，每单 API 耗时 1s+，一轮 5 分钟占满）——**来源 2 永远轮不到**。实测证据：quantity_check 写入的 90 条失败记录（`last_checked_at=NULL`，按门卫天然立即查）从 14:48 到 16:30 原封未动，从未被复查。

另有三个关联缺陷：

- **无 flock 防并发**：一轮 5 分钟跑不完时 cron 会再起一个实例，多实例并发调同一 AppKey 是触发平台限流（`App Call Limited`）的放大器
- **check_quantity 与 check_bill_status 并发撞限流**：两者同时段运行必然触发平台限流（实测第一单即熔断），运行窗口必须错开
- （已修复）`database is locked`：check_quantity 与 fetch_bills 写库时间窗重叠，busyTimeout(30s) 已解决

业务后果：外部系统补传后，失败记录页的"信息不存在"/失败记录**不会自动消失**（需要新的"上传成功"记录才能被 failed.php 的 NOT EXISTS 逻辑隐藏，而新记录依赖来源 2 复查）。

## 决策

1. **拆分为两个独立脚本**：`check_bill_status.php` 只保留来源 1（等待上传任务），来源 2 原样搬迁到新脚本 `check_failed_logs.php`，各自独立调度、独立 flock。保持来源 2 的写入语义不变（平台存在 → 记录翻转为"上传成功" + 同步关联 upload_tasks + 写 JSONL；信息不存在 → 仅 touch；API 异常 → 不修改），保留 30 分钟门卫常量（每天一次运行时形同虚设但无害，避免行为差异）。
2. **check_bill_status / check_failed_logs 各加 flock 防并发**（`logs/check_bill_status.lock`、`logs/check_failed_logs.lock`，`LOCK_EX|LOCK_NB`，获取失败直接退出），锁命名相互独立——不同任务可并行、同类任务不重入。检查类脚本与上传锁（`logs/upload.lock`）互不干扰。check_quantity 不在本次范围（单实例每天一次、有熔断兜底，暂不加锁）。
3. **错峰 cron**：全部检查类脚本错峰到 check_bill_status 停跑的 20 点后——check_failed_logs 每天 20:40、check_quantity 每天 21:10，覆盖保证：任何单据最终都会被查到平台状态（等待上传 ≤30 分钟 / 失败记录 ≤24h / SQL Server 全量 ≤24h）。
4. **不引入消息队列**：日 ~725 单、单机、单消费者；瓶颈是平台 API 限流（usleep + 错峰已等效）；SQLite upload_tasks 即现成持久化队列（天然"至少一次"语义）；引入 MQ 的成本（新服务部署/运维/幂等/死信处理）远超收益。
5. **不做优先级调度**：新单与积压单先到先得即可，312 个积压任务自然消化，只要所有单据最终都能查到平台状态。
6. **来源 1 每轮 5 分钟跑不完是可接受状态**（只剩一个队列，下一轮续跑即可，不再饿死其他队列），不加限量。

## 后果

- 正向：失败记录（含 quantity_check 的"信息不存在"）每天被复查，外部系统补传后失败记录页自动干净，与 ADR 0002 配合完成"未上传 → 补传 → 记录自动干净"的闭环；同类任务不重入，不再叠加触发平台限流。
- 代价：来源 2 复查延迟从"理论 ≤30 分钟"放宽为"≤24h"（每天一次）；多一个脚本需要维护（与 check_bill_status 共享查询/更新语义）。
- 注意事项：cron 时间表集中记录在 CLAUDE.md，部署新环境时一次配齐；check_quantity 与 check_failed_logs 不得改到 8-20 点窗口内运行。
