**Status:** verified（2026-08-26 实测核心等式成立，两级流水线已实现并通过 08-15 全量实测；实现见 scripts/check_quantity.php + src/ApiClient.php）

# 数量对账第二级：singlerelation 码级精查（两级流水线）

## Problem Statement

现有数量对账（check_quantity.php，ADR 0004 定稿）比较口径：
`expected = 本地 SQL Server SUM(shl)`（本地"最小包装"口径，按零售规格）vs
`actual = 平台 min_pkg_count`（平台口径）。实测 08-15 的 62 条"数量不符"
**全部可归因于本地零售规格 ≠ 平台注册规格**（注射剂按瓶卖 vs 平台按盒算，
青霉素钠 20 瓶 vs 1 盒差 19 等）——ADR 0004 判定为"结构性硬伤，本阶段无更优解"。

2026-08-25 grilling 会话发现 `alibaba.alihealth.drug.kyt.singlerelation` 接口
（SDK 已含请求类，2025.12.04 版）可解：**它把任意追溯码折算成平台的"最小溯源单位"**，
使本地码列表也能按平台口径求和，从根上消除规格口径差异。

## 核心等式（待探针验证）

```
Σ singlerelation(本地每个追溯码).pkg_amount  ==  searchbill.detail 的 min_pkg_count
```

- `pkg_amount` = 该码折算成平台"最小溯源单位"的系数。**用户已实测**：
  大包装码 = 100（一个大包装含 100 个最小溯源单位）、中包装码类似（5/20）、
  本身就是最小溯源单位的码 = 1
- `min_pkg_count` = 平台对该单据申报码按最小溯源单位统计的总数（**不是外部系统
  申报时填的包装数**，与 pkg_amount 同口径）
- 相等 = 本地单据的码全部申报且折算数一致 = **没漏传**（漏传 → 平台统计的码少
  → min_pkg_count 少 → 不等）
- 关键语义：**expected 完全来自平台码库**（码的固有属性），不依赖本地 shl 的
  零售规格口径——规格差异（如青霉素钠 20 瓶 vs 1 盒）在新口径下应自然消失

## Solution：两级流水线（check_quantity.php 演进）

```
第 1 级（快，全量）: 现有 shl 方案全量跑（~650 单 ≈ 13 分钟，SQL Server 聚合）
    ↓ 筛出"数量不符"嫌疑单（内存中，不写库）
第 2 级（慢，精查）: 仅对嫌疑单逐码调 singlerelation（0.5s/次）
    ↓
双方案都有差异 → 真问题，写 quantity_check 记录（数量不符，含明细）
单方案有差异 → 规格口径噪声（如青霉素钠），不写库 → Web 失败记录页零噪声
```

- **第 1 级不写库**（内存筛嫌疑单）：62 条规格差异噪声从 Web 失败记录页消失，
  幂等"整日清理"语义不变（每轮重跑天然干净）
- **第 2 级精查的"超过即停"优化**：逐码累计 Σ pkg_amount，一旦累计 > actual
  （平台申报总数）即确定"本地多于平台"→ 立即停（大单 1.2 万码的真实差异单
  不必查完；只有"相等"的嫌疑单才需查完全部码，而嫌疑单平均才 ~16 码）
- **第 1 级 actual（跨子单 min_pkg_count 累加）复用于第 2 级**（同轮内数据一致；
  若要跨轮复用需重查，暂不设计）
- **查不到的码**：理论不存在（batch_check 成功单 = 单在平台存在 = 码必在码库），
  探针会验证；若出现则记入响应明细待排查

## Implementation Decisions（已确认）

1. `des_ref_ent_id` = 河药自己（`REFENTID_HYYY`），与 `ref_ent_id` 相同
2. singlerelation 限速 **1 秒 2 次（500ms）**（用户实测确认；与 searchbill.detail
   同一 AppKey 限流池）
3. 平台限流（App Call Limited）→ **本轮熔断，下次运行自动重查**（沿用现有幂等模式）
4. 旧 shl 路径（TaskFetcher::fetchBillQuantitiesByCodes）**保留**为第 1 级粗筛——
   singlerelation 逐码太慢（1.2 万码单 ≈ 100 分钟），不能全量逐码
5. 运行时机不变：21:10 cron，避让 check_bill_status 8-20 点窗口
6. 精查确认的"数量不符"记录复用现有 quantity_check 语义（response 存
   {djbh, rq, expected, actual, sub_bills}，expected 改为 Σ pkg_amount）
7. 非药品码跳过语义：以码库查询结果为准（查不到 → 记明细，不按 0 计）

## Verification（2026-08-26 实测完成）

探针：`tests/singlerelation_test.php <单号> [--limit N]`（避开 8-20 点窗口）

| 样本 | 单号 | 码数 | 验证目标 | 结果 |
|------|------|------|----------|------|
| 传齐样本 | XSOWMS00998311 | 50 | Σ pkg_amount == 50 == min_pkg_count | ✓ 50 == 50 |
| 规格差异单 | XSOWMS00997406 | 213 | Σ pkg_amount == min_pkg_count（青霉素钠差 19 假阳性消除） | ✓ 240 == 240（旧口径 259 vs 240 差 19） |

**核心等式成立，按 spec 实现**。关键实测事实：
- `pkg_amount` 真实路径 `result.model_list.code_relation_dto.produce_info_list.produce_info_dto.pkg_amount`（与 SDK DTO 推断不同，探针存档确认；解析已收敛到 `ApiClient::sumPkgAmount` 单一事实源）
- `is_smallest="Y"` 字段确认查询码是否即最小溯源单位；213 码 → Σ 240 证明存在系数 >1 的大包装码
- 263/263 码全部可查（"batch_check 成功单的码必然可查"假设成立），接口权限无问题

### 全量实测（check_quantity.php 2026-08-15，589 单）

```
传齐 527 / 第1级差异 62（码级精查后: 真问题 17 / 规格噪声排除 45 / 无法核对 0） / 信息不存在 0 / 异常 0
```

- **45 条规格口径噪声被第 2 级消除**（第 1 级差异但码级相等），Web 失败记录页从 62 条降到 17 条真问题
- 17 条真问题全部为**"平台申报 > 本地码折算"方向**（差 1-5，多为 1），无一条"漏传（平台少）"方向——
  与预想相反：本地码全部可查、折算正确，平台统计了本地码列表之外的 1-5 个单位
  （外部系统多传/混入他单码，或本地单据在 ERP 之外还有码）。**两级流水线设计外的额外
  能力**：spec Out of Scope 曾断言"多传检测不到"，但数量方向自然暴露"平台多于本地"。
  处置：按"疑似多传/混码"告警保留，人工复核优先怀疑外部系统上传了非本地单据的码
- 幂等验证：每轮先 DELETE 目标日期 quantity_check 再写入（代码层确认，未单独重跑）

## Out of Scope

- 多传检测（外部系统传了本地没有的码）——本地码列表无此信息，检测不到
- 药品级规格映射（本地 spkfk.shpgg ↔ 平台 temp_pkg_spec）——被本方案取代，
  backlog 移除
- 补传缺码 / 通知渠道——沿用"只检查告警"

## Comments

### 2026-08-25 grilling 会话（设计方案落定）

- 用户修正方案本质：不是"换 expected 来源"，而是**统一以平台最小溯源单位求和对比**，
  singlerelation 是"码 → 最小溯源单位"的转换器
- 用户确认 min_pkg_count 本身就是最小溯源单位口径（非申报时填的数量）
- 用户实测过 singlerelation（大包装 100/最小单位 1），接口权限无问题
- 用户明确两级流水线：旧方案粗筛（快）→ 新方案精查（准）→ 双差异确认真问题
- 调用量评估（实测库数据）：08-15 共 589 单 / ~9,700 码（平均 16 码/单，最大 213 码）
  ——全量逐码 ~1 万次调用虽可行，但单日大头单（1.2 万码）存在，故仍采用两级
- 探针脚本已就绪（tests/singlerelation_test.php），等待 8-20 点窗口外实测
