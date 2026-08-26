# ADR 0005: 数量对账两级流水线（singlerelation 码级精查消除规格口径差异）

- 状态：已接受（2026-08-26）
- 关联代码：`scripts/check_quantity.php`（两级流水线）、`src/ApiClient.php`（`searchSingleRelation`/`sumPkgAmount`）、`src/TaskFetcher.php`（`fetchBillQuantitiesByCodes` 第 1 级保留）
- 推翻：ADR 0004 的"本阶段无更优解"结论（药品级规格映射 backlog 被本方案取代——不需要规格映射表，直接以平台码库口径折算）
- 对应 spec：`.scratch/quantity-check/singlerelation-tier2.md`（含实测数据与探针）

## 背景

ADR 0004 定稿的数量对账（第 1 级）比较口径为"最小包装单位数"：本地 `SUM(shl)`（按本地零售规格展开）vs 平台 `min_pkg_count`（按平台注册规格申报）。实测 08-15 的 62 条"数量不符"**全部可归因于本地零售规格 ≠ 平台注册规格**（如青霉素钠 20 瓶 vs 1 盒差 19）——同数量不同包装数，机制本身精确，但规格差异使"数量不符"成为疑似差异告警而非精确判定。ADR 0004 判定"本阶段无更优解"，提出药品级规格映射列入 backlog。

2026-08-25 grilling 会话发现 `alibaba.alihealth.drug.kyt.singlerelation` 接口（SDK 已含请求类，2025.12.04 版）：**它把任意追溯码折算成平台的"最小溯源单位"系数 `pkg_amount`**（最小单位码=1、大包装码=100），使本地码列表也能按平台口径求和，从根上消除规格口径差异——不需要规格映射表。

## 核心等式（2026-08-26 实测成立）

```
Σ singlerelation(本地每个追溯码).pkg_amount  ==  searchbill.detail 的 min_pkg_count
```

- `pkg_amount` = 该码折算成平台"最小溯源单位"的系数（码的固有属性，来自平台码库）
- `min_pkg_count` = 平台对单据申报码按最小溯源单位统计的总数（与 pkg_amount 同口径）
- 相等 = 本地单据的码全部申报且折算数一致（没漏传）
- 关键语义：**expected 完全来自平台码库**，不依赖本地 shl 的零售规格口径——规格差异（青霉素钠 20 瓶 vs 1 盒）在新口径下自然消失
- 响应结构实测：`result.model_list.code_relation_dto.produce_info_list.produce_info_dto.pkg_amount`，`is_smallest="Y"` 标识最小溯源单位码（探针存档 tests/singlerelation_<单号>.json 确认）

## 决策

1. **两级流水线**：第 1 级（快，全量）保留现有 shl 方案全量跑（~650 单 ≈ 13 分钟）——第 1 级"数量不符"嫌疑单仅收集在内存（**不写库**），交由第 2 级；第 2 级（慢，精查）仅对嫌疑单逐码调 singlerelation（0.5s/次，嫌疑单平均 ~16 码 ≈ 8 秒/单）。**双方案都有差异 → 真问题**，写 `数量不符`（expected 改为 Σ pkg_amount，response 存 `{djbh, rq, expected, actual, sub_bills, stopped_early}`）；**单方案有差异（第 2 级相等）→ 规格口径噪声，不写库** → Web 失败记录页零噪声；**码查询失败/无 pkg_amount（理论不存在，实测零次）→ 无法核对，跳过不写库**（Σ 不完整判定不可信，与"解析失败不误报"同哲学）。第 1 级其余分支（信息不存在/传齐零记录/无法核对）照旧。
2. **"超过即停"优化**：逐码累计 Σ pkg_amount 一旦 > actual（平台申报总数）即确定"本地多于平台"立即停（所有码系数 >0 不可能回落相等）；只有相等/偏少才需查完全部码。大单（1.2 万码 ≈ 100 分钟）的真实差异单不必查完。
3. **第 1 级 actual（跨子单 min_pkg_count 累加）复用于第 2 级**（同轮内数据一致；跨轮复用需重查，暂不设计）。
4. **singlerelation 限速 1 秒 2 次（500ms）**（用户实测确认；与 searchbill.detail 同一 AppKey 限流池）。平台限流 → **本轮熔断，下次运行自动重查**（沿用幂等模式：每轮先 DELETE 目标日期 quantity_check 再写入）。
5. **旧 shl 路径（fetchBillQuantitiesByCodes）保留为第 1 级粗筛**——singlerelation 逐码太慢（1.2 万码单 ≈ 100 分钟），不能全量逐码。
6. **运行时机不变**：21:10 cron，避让 check_bill_status 8-20 点窗口。
7. **非药品码跳过语义**：以码库查询结果为准（查不到 → 跳过不写库，不按 0 计）。

## 实测验证（2026-08-26，08-15 全量 589 单）

- 探针两样本核心等式成立：XSOWMS00998311（50 码）50 == 50；XSOWMS00997406（213 码）240 == 240（旧口径 259 vs 240 差 19 假阳性消除）
- 全量结果：传齐 527 / 第 1 级差异 62 → **真问题 17 / 规格噪声排除 45** / 无法核对 0 / 异常 0
- **实测方向经验**：17 条真问题全部为"平台申报 > 本地码折算"方向（差 1-5，多为 1，无一条漏传方向）——本地码全部可查、折算正确，平台统计了本地码列表之外的 1-5 个单位（外部系统多传/混入他单码，或本地单据在 ERP 之外还有码）。spec 曾断言"多传检测不到"（Out of Scope），两级流水线通过数量方向自然暴露该异常——设计外额外收获。运维看到 expected < actual 时优先怀疑**外部系统多传/混码**。

## 后果

- 正向：Web 失败记录页"数量不符"从 62 条噪声降到 17 条真问题（08-15 实测）；青霉素钠类规格差异假阳性从根上消除（不需要规格映射表，backlog 移除）；"数量不符"从"疑似差异告警"升级为"码级口径精确判定"（差 1-5 的余量待业务确认外部系统行为）。
- 代价：第 2 级新增 API 调用（嫌疑单 × 码数 × 0.5s；08-15 为 62 单 ~2100 次 ≈ 18 分钟，叠加第 1 级 13 分钟，21:10 开跑 ~21:40 结束，仍在 22 点前）。
- 注意事项：`pkg_amount` 解析路径（`result.model_list.code_relation_dto.produce_info_list.produce_info_dto.pkg_amount`）为实测确认（与 SDK DTO 推断不同），已收敛到 `ApiClient::sumPkgAmount` 单一事实源（探针与 check_quantity 共用）；若平台接口结构变更需以探针存档重新确认。
