# 05 - 零售单据采集（dyt 源库 + 门店认领）

- Type: task
- Status: ready-for-agent（原阻塞项 C、D 已由 2026-09-29 只读探测解除）
- 关联：spec.md §5/§6、docs/adr/0007、docs/adr/0008、`.scratch/retail-chain/probe-findings-2026-09-29.md`

## 问题

零售单据需从 `dyt` 链接服务器采集入库，并认领到 15 家门店。

## 实现

**连接**：复用 `TaskFetcher` 那套 `SqlSrvHelper` 连接即可查 4 段式 `dyt.msfx.dbo.zsm_ls`（实测 638ms），不需要新封装。

**采集 SQL（两步，先头后码）**：

```sql
-- 第一步：单据头，必须先按 bill_code 去重（321 有完全重复行，最多 120 行/单、14 列全同）
select bill_code, min(bill_time) as bill_time, min(bill_type) as bill_type,
       min(from_user_id) as from_user_id, min(to_user_id) as to_user_id,
       min(oper_ic_name) as oper_ic_name
from dyt.msfx.dbo.zsm_ls
where bill_type in (104, 203, 321, 116)
  and bill_time >= ? and bill_time < ?      -- varchar(10) 的 'YYYY-MM-DD'，字符串比较即日期比较
group by bill_code

-- 第二步：按单号批量取码（一码一行；group by 去重 + order by 保证拼接确定——表里没有排序列）
select bill_code, trace_codes from dyt.msfx.dbo.zsm_ls_code
where bill_code in (...) group by bill_code, trace_codes order by bill_code, trace_codes
```

- 不做第一步的去重，`left join` 会把追溯码放大最多 120 倍
- **不设计数门卫**：一次全表扫 ~0.6s + `(company, djbh)` 幂等，门卫只省 0.6s 却多一份状态文件（`data/fetch_bill_counter.json` 保持批发专用）
- **不拆单**：实测单张单据码数上限 1,718 < 3,500
- `bill_type=999` **不采**（10,081 行，用户判定语义未明）

**门店认领**（`App\Enterprise::claim()`，工单 01 已实现并测试）：

- 按类型选 ID 列（`321`/`116` → `from_user_id`；`104`/`203` → `to_user_id`）→ 命中该门店登记过的任一平台 ID 即认领
- ID 为空/未命中 → 回退 `oper_ic_name` 与配置门店名精确匹配
- 都不命中 → `company='未识别'` **照常入库**，页面显著提示，**禁用其手动补传**
- `claim()` 返回 `name_unmatched` 时记一条 JSONL 警告（ID 认到了、源库名字却对不上任何门店 → 疑似错名/改名/已关店）

**落库**：`task_status = '待补传'`、`source = 'retail'`、`company` 取认领结果、`credential` 取该企业 primary 凭据键（可能为 null，见"待配凭据"）。

## 验收

- 指定日期跑一次采集，单据数与 `zsm_ls` 按 `bill_type` + 日期范围的去重单号数一致（**不是行数**）
- 抽样核对：321/116 的单据按 `from_user_id` 认领到正确门店（源库这些行没有门店名，只能靠 ID）；104/203 按 `to_user_id`
- 断网/源库不可用时脚本非零退出且不写库
