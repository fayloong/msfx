**Status:** ready-for-agent

# fetch_bills 变化检测门卫

## Problem Statement

`fetch_bills.php` 的采集查询基于 `v_pf_phlrhz` / `v_jzorder_hz` 视图 + 多表 join（含追溯码明细 `wms_dzjg`），是重查询，耗时显著。当前 cron 每小时跑一次（8-22 点），即使没有新单据也会执行完整视图查询：

- 提高 cron 频率（如半小时一次）会放大重查询次数
- 休息日 SALEOUTMT/PURINMT 无变动时，cron 定时执行纯属浪费 SQL Server 资源

## Solution

启动时先执行轻量计数查询（SALEOUTMT/PURINMT 两张基表当天行数），与本地基线 `data/fetch_bill_counter.json` 比较：

- 同一日期且计数相同 → 无新单据 → 直接退出，不执行重查询
- 其他情况 → 正常采集，采集成功后才更新基线

## User Stories

1. 作为运维人员，我想要 cron 频率提高到半小时时，无新单据的运行不触发重查询，以降低 SQL Server 负载
2. 作为运维人员，休息日（无单据变动）时 cron 全部运行都被门卫拦截，零重查询
3. 作为运维人员，采集失败时基线不更新，下次运行计数仍不等，自动重试
4. 作为运维人员，补采历史日期（`fetch_bills.php 2026-07-28`）时门卫按该日期比较，不与当天混淆

## Implementation Decisions

### 1. 门卫信号与比较逻辑

- 计数 SQL（参数化传 `$date`，与采集用同一日期值，不用 GETDATE()）：
  ```sql
  SELECT (SELECT COUNT(1) FROM SALEOUTMT WHERE Dates = ?) + (SELECT COUNT(1) FROM PURINMT WHERE Dates = ?) AS cnt
  ```
- 状态文件 `data/fetch_bill_counter.json`，结构 `{"date": "Y-m-d", "count": int}`
- 比较规则：**日期不同 → 放行**（跨天必跑一次）；日期相同且计数相同 → 拦截
- 安全前提（已与业务确认）：SALEOUTMT/PURINMT 记录**只增不减**，且采集视图结果的变化**仅来源于这两张表**。计数相等 ⟹ 无新行 ⟹ 无新单可采

### 2. 基线写入时机

- **采集成功**（视图查询 + SQLite 写入全部完成，含"无单据"分支）后才更新基线
- 采集失败 / 计数查询失败：基线保持旧值，下次计数仍不等，自动重试
- 视图查询成功但结果为空（当天 0 单）同样写基线，避免当日后续空转

### 3. 失败策略

- 计数查询异常（SQL Server 不可用）→ 打日志后 `exit(1)`。采集同样依赖该连接，失败时采集也无法进行
- 状态文件不存在 / JSON 损坏 / 字段缺失 / 日期格式非法 → 视为无基线，放行采集
- 原则：任何异常向"放行"倾斜，不向"拦截"倾斜

### 4. 不动范围

- `src/TaskFetcher.php`：仅新增 `countBills()` 轻量计数方法（复用现有连接配置，避免重复配置与双连接），现有 `fetchBills()` / `countPending()` 接口不变
- 采集去重、INSERT 逻辑：不变
- 其他脚本：不变

## Testing Decisions

### 测试原则

- 项目无自动化测试框架。门卫比较逻辑为纯逻辑（无 I/O 依赖），用临时 PHP 脚本模拟验证分支
- 不连真实 SQL Server 执行（生产环境，避免写入 upload_tasks）

### 测试场景（已模拟验证 7/7 通过）

| 场景 | 期望 |
|------|------|
| 无状态文件 | 放行采集 |
| 日期相同 + 计数相同 | 拦截跳过 |
| 日期相同 + 计数不同 | 放行采集 |
| 日期不同（跨天） | 放行采集 |
| JSON 损坏 | 放行采集 |
| 缺字段 | 放行采集 |
| 日期值非法 | 放行采集 |

## Out of Scope

- 实际 crontab 调度配置（由运维自行配置；脚本头注释给出半小时一次的建议频率）
- 优化视图查询本身（`v_pf_phlrhz` / `v_jzorder_hz` 的执行计划）
- 修改 Web 前端

## Further Notes

- 门卫只拦截"无变化"的空转；单据持续新增的时段仍会执行重查询，频率提高后重查询次数取决于出单分布。上线后通过日志（"跳过采集"出现次数）评估门卫收益，再决定是否维持半小时频率
- 追溯码明细表 `wms_dzjg` 的变化不在基表计数内（ERP 补码/改码时视图明细变、基表计数不变），但 fetch_bills 按 djbh 去重、本来就不更新已有任务，此盲区影响有限
- 状态文件属主需与 CLI 运行用户一致；若 Web 端未来需要读取，注意 nginx:nginx 权限
