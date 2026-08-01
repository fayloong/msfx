# 02 - 批量查询新鲜度门卫

- Type: task
- Status: resolved
- 关联：spec.md（本 feature 的后续增强）、docs/adr/0001-check-bill-status-freshness-guard.md

## 问题

`check_bill_status.php` 每次 cron 对全部待查单据（约 300 条）逐个调码上放心 API，重复请求量大、有限流风险；提高 cron 频率以更快发现"被其他路径上传"的单据不可行。

## 实现

- `upload_tasks` / `upload_logs` 各加 `last_checked_at` 列（`init_db.php` 幂等迁移）
- 查询带门卫条件：`last_checked_at IS NULL OR last_checked_at <= 30 分钟前`（常量 `CHECK_INTERVAL_MINUTES = 30`）
- 查询成功（含"信息不存在"）touch `last_checked_at`；API 异常与"已确认在平台跳过"不 touch
- cron 建议改为 8-20 点每 5 分钟一次（待运维改 crontab）

## Answer

已实现并实测：首次全量建立基线 → 立即重跑全部跳过 → 手动改一条为 31 分钟前只查该条。已提交 8973227。
