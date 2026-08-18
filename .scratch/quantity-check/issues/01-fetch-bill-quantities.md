# 01 — TaskFetcher 新增数量基线聚合（fetchBillQuantities）

**What to build:** 一次批量查询返回指定日期所有单据的"本地应有数量"——从 SQL Server 明细视图聚合每个单据的最小包装单位总数（出库侧按单号分组、入库侧按关联单号分组），作为数量对账的期望值基线；基线单号在明细视图中无行时标记为"无法核对"，供上层跳过而不误报。

**Blocked by:** None — can start immediately

**Status:** resolved

- [x] 出库侧从出库明细视图按 `djbh` 分组聚合 `SUM(shl)`，入库侧从入库明细视图按关联单号分组聚合，覆盖两类单据前缀（含 XSO/JHO 出库与 JHG 入库的现有 join 模式）
- [x] 用基线单号 `IN` 列表聚合而非 `rq` 日期过滤（`rq` 字符串比较在明细视图上不可靠，`IN` 与单据基线天然一致）
- [x] 返回结构与单据基线一一对应；基线单号在明细视图无行（`SUM` 为 NULL）时该单标记"无法核对"，不参与后续对比
- [x] 实测 4 样本验证：零散出库=50、件码出库=5、整件入库=600（2 件 × 件规格 300）、真实拆分出库=7168，均与平台 `min_pkg_count` 求和一致
- [x] （实现时新增）剔除非药品行（spkfk.jixing 关键词），实测 08-15 消除 16 条非药品假阳性、4 样本不变；拆出 `fetchBillQuantitiesByCodes()` 供 check_quantity 按 batch_check 成功单列表聚合
