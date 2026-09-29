# 06 - 三个检查脚本与失败记录页排除零售

- Type: task
- Status: ready-for-agent
- 关联：spec.md §9、docs/adr/0007-retail-no-platform-reconciliation.md

## 问题

`check_bill_status.php` / `check_failed_logs.php` / `check_quantity.php` 均按记录无条件取数；零售记录会拿河药凭据去查门店单号，只会得到"信息不存在"，污染 Web 失败记录页——那是现有唯一的告警出口。

## 实现

- 三个脚本查询加零售排除（按 `company` 或 `source`）
- `api/failed.php` 排除零售记录，保护告警出口
- `check_quantity.php` 与零售完全无关（依赖 `skwms_new` 的 `SUM(shl)` 本地基线），确认排除即可

## 验收

零售记录存在时，三个脚本运行后不产生任何涉及零售单号的 upload_logs
