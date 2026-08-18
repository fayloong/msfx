# 02 — ApiClient 恢复 sumBillDetailCount（平台申报数量解析）+ 测试

**What to build:** 给定 `searchbill.detail` 的响应数组，返回平台申报的最小包装单位总数（累加各药品行的 `min_pkg_count`），供数量对账与本地期望值对比；无法核对时返回 `null`（而不是 0），防止解析异常伪装成数量差异。

**Blocked by:** None — can start immediately

**Status:** resolved

- [x] 单药品明细（关联数组结构）累加正确
- [x] 多药品明细（列表结构）累加正确
- [x] 真实拆分子单样本（JHOWMS00012659 的 `_1`=3500、`_2`=3668）各自累加正确
- [x] 无明细结构 → `null`；任一行缺 `min_pkg_count` → `null`（缺字段不按 0 处理）；信息不存在响应 → `null`
- [x] 扩展现有数量对账自包含断言测试（真实响应内嵌 JSON，无框架、无 mock，直接运行退出码 0），原 isBillFound 断言原样保留（18 项断言全部通过）
