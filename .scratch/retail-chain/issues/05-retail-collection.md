# 05 - 零售单据采集（dyt 源库 + 门店认领）

- Type: task
- Status: needs-info（阻塞于开放问题 C、D：dyt 链接服务器可用性、zsm_ls/zsm_ls_code 列与索引）
- 关联：spec.md §5/§6、config/sql.php 的 $get_up_task_retail

## 问题

零售单据需从 `dyt` 链接服务器采集入库，并按 `oper_ic_name` 认领到门店。

## 实现

- 采集 SQL 定稿口径：`bill_type in ('104','203','321','116')` 写死、按 `bill_time` 日期范围、**去掉 `NOT EXISTS(dyt.bs_msfx.dbo.update_state)`**（见 ADR 0007）
- 门店认领：`oper_ic_name` 与配置门店名**精确相等**匹配，配置保留 `source_names` 容纳别名
- 匹配不上 → `company = '未识别'` **照常入库**，页面显著提示，**禁用其手动补传**
- 落库 `task_status = '待补传'`、source 新增 `retail`
- 独立脚本（如 `scripts/fetch_bills_retail.php`），计数门卫按企业分开（不复用 `data/fetch_bill_counter.json`）

## 阻塞项

需先确认：`dyt` 能否被现有 `SqlSrvHelper` 连接复用；`zsm_ls` / `zsm_ls_code` 的列与索引（尤其 `bill_time` 类型）
