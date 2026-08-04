# 03 - 已上传单据重复采集与 check_bill_status 无限跳过循环

- Type: task
- Status: resolved
- 关联：02-freshness-guard.md（门卫行为的补充修正）

## 问题

补采历史日期（如 `fetch_bills.php 2026-07-29`）时，已上传成功的单据（`upload_logs` 有"上传成功"记录，但 `upload_tasks` 中的任务已被删除/从未创建）被重新采集为"等待上传"；随后 `check_bill_status` 命中"已确认在平台跳过"分支时只 `continue` 不改任务状态，任务永久停留在"等待上传"且 `last_checked_at` 不 touch，cron 每 5 分钟反复拉取/跳过，永不收敛；`upload_pending` 还会再次尝试上传（返回"单据重复"）。

实测案例：7-29 单据（844 条，829 条上传成功）补采后 785 条重新入队，全部卡在"等待上传"。

## 实现

- `check_bill_status.php`：跳过分支对 `upload_tasks` 来源任务标记"已处理"并 touch `last_checked_at`（`upload_logs` 来源的历史记录不动）
- `fetch_bills.php`：去重集合除 `upload_tasks` 外，纳入 `upload_logs` 中 `response_status IN ('上传成功','单据重复')` 的 djbh（失败记录仍允许重新采集）

## Answer

已实现并实测：运行 `check_bill_status.php 2026-07-29` 一次收敛 784 条补采任务为"已处理"（0 次 API 调用）；`XSOWMS00984201`（任务 4419）由"等待上传"变为"已处理"。反馈循环（拉取 + 去重判断，/tmp 临时脚本）由红转绿。剩余 1 条 `JHOWMS00012659` 在 upload_logs 无记录，是真正未上传的 JHO 单据（7-29 JHO 采集 bug 修复后新增），走正常重查流程。
