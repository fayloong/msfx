# ADR 0002: 数量对账退化为"是否上传"检查

- 状态：已接受（2026-08-17）
- 关联代码：`scripts/check_quantity.php`、`src/ApiClient.php`、`src/TaskFetcher.php`

## 背景

`check_quantity.php`（数量对账）初版以 SQL Server 追溯码数（expected）对比平台申报数（`min_pkg_count` 之和，actual），不一致写 `response_status='数量不符'`。首次实际运行（2026-08-17，检查 08-15 单据）产出 90 条"数量不符"，其中：

- 28 条：单据未上传（平台 `FAIL_BIZ_NO_PAT_INFO`），actual=0 —— 未上传被混入"数量不符"告警，掩盖"根本没上传"的事实；
- 62 条：单据已上传但 actual > expected（比值 1.1~400 倍，无一相符）——**量纲错误**。

## 根因

1. **未上传也报"数量不符"**：判定只比较 `actual === expected`，未上传（actual=0）必然不符。
2. **量纲不匹配**：`sumBillDetailCount` 解析 `min_pkg_count` 并当作"追溯码数"。实测平台响应（XSOWMS00997350，SQL Server 存 1 个中包装码）：`min_pkg_count=5`、`preparations_unit=瓶`、`temp_pkg_spec=1瓶/盒`——`min_pkg_count` 是**最小包装数**，中包装码（件码，`wms_dzjg.dzjgm_type='中'`）在平台按件内最小包装数展开申报（1 件码=5/10/200 盒）。平台接口不返回追溯码数，SQL Server 侧也没有件码→盒数的映射数据，**两数量纲无法统一**，含中包装码的单据必然误报。

## 决策

1. **数量对账退化为"是否上传"检查**：逐单查询原始单号 → `_1` → `_2`...（上限 10 次，原始单号查到即提前结束），仅用 `ApiClient::isBillFound()` 判定（`FAIL_BIZ_NO_PAT_INFO` = 未上传，其余视为已上传），不再比对申报数量。
2. **未上传写 `response_status='信息不存在'`**（source=quantity_check），与 check_bill_status 的判定口径一致，Web 失败记录页可见、与"数量不符"明确区分；已上传不写记录。
3. **幂等升级为"先删后写"**：每单先 `DELETE` 该单旧的 quantity_check 记录再按新判定写入——限流熔断重跑不产生残留，历史"数量不符"误报随重跑自动清除，无需手工清理脚本。
4. **移除 `sumBillDetailCount`**：语义错误的解析方法连同其测试一并删除，测试改为覆盖 `isBillFound`（真实平台响应样本）。
5. **基线改用 `TaskFetcher::fetchBillsMeta()`**（#bill_list 轻量查询），不再拉取追溯码明细（对账已不需要码数）。

## 后果

- 正向：未上传单据以"信息不存在"明确呈现（不再伪装成"数量不符"）；含中包装码单据不再误报；历史误报数据被自动清理。
- 代价：失去"数量差异"检测能力（平台/本地数量不一致的异常不再发现——前提是量纲问题无法解决，接口不返回码数）。
- 注意事项：**运行时机必须避开 check_bill_status**（8-20 点每 5 分钟一轮）——两者并发调用同一 AppKey 立即触发平台限流（实测第一单即 `App Call Limited`）。cron 建议 20 点后每天一次。另观察到 check_bill_status 来源 1（等待上传任务）每轮占满 5 分钟窗口，来源 2（失败日志）长期轮不到，属遗留积压问题，另行处理。
