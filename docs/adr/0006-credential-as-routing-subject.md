# 多企业支持：凭据（AppKey）是路由主体，门店与凭据 1:N

- 状态：已接受（2026-09-29）；**"1:N"与 `primary`/备用凭据部分已被 [ADR 0012](0012-credential-one-per-store.md) 取代（2026-09-30）**

> **2026-09-30 修订（ADR 0012）**：**"凭据是路由主体"这条核心结论不变**，但**门店与凭据的关系改为 1:1**——用户确认每个门店在本项目中只有一套凭据，故配置层拒绝多套（`validate()` 直接报错），页面与端点也不再由人指定凭据。下方"1:N"、"`primary` 标默认"、"由用户在页面下拉显式选择用哪套凭据"的表述以 ADR 0012 为准；`primary` 字段仍在结构里解析，只是不再参与任何决策。

接入零售连锁后系统从单企业变为多企业。决定引入两个领域概念——**企业（Company，门店）**与**凭据（Credential，一套 AppKey/SecretKey/ref_ent_id/ent_id）**，**以凭据而非门店作为"用哪套凭据调哪个接口"的路由主体**，门店与凭据是 1:N 关系（**→ 已改为 1:1，见文首修订**）。

> **2026-09-29 修订**：本 ADR 的**核心结论不变**（凭据是路由主体、门店与凭据 1:N、路由键是 `(企业, 单据类型)`），但下面两点已被 [ADR 0008](0008-retail-claim-by-platform-id.md) 推翻：
> ① **去重键三元组 → 退回二元组 `(company, djbh)`**（多套凭据实为限流备用，不是两个主体各传一遍；三元组反而允许同一张单被传两次）；
> ② **`Credential.bill_types` 删除**、新增 `primary`（声明在结构文件里）。
> 另：门店认领键由"`oper_ic_name` 精确匹配"改为"平台 ID 优先、名字回退"，`source_names` 相应退化为回退用别名。

## Context

设计过程中出现过两个被推翻的模型：

1. **按门店建模**（第 1 轮）：假设"每个物理门店一套 AppKey"。第 2 轮拿到门店凭据草稿后发现 9 家门店中有 8 家共用同一个 AppKey，该假设不成立。
2. **按 AppKey 建模**（第 2 轮推荐）：假设 AppKey 是企业主体级、门店靠 `ref_ent_id` 区分。第 3 轮用户澄清最终形态是**"每个门店的 appkey、SECRETKEY 都不相同，每个门店也可能存在多套 appkey、SECRETKEY"**，该假设同样不成立——AppKey 与门店没有函数依赖关系。

同时，"同一个单据类型码在不同企业下走不同接口"已确认（零售 `104`/`203` → `lsyd.uploadinoutbill`、`321`/`116` → `lsyd.uploadretail`、批发走 `kyt.uploadinoutbill`），故路由键必须至少是 `(企业, 单据类型)` 二元组；再叠加"一门店多套凭据"后，落库记录的去重键必须是三元组 `(company, credential, djbh)`——**同一门店的两个主体，主体 A 传成功不代表主体 B 传成功**，用二元组去重会出现"B 主体漏传但被 A 主体的成功记录挡住"。

> （**此段的三元组结论已被 ADR 0008 推翻**：多套凭据的成因是"限流时顶替"，不是"两个主体各传一遍"，故不存在"B 主体需另传一次"的情形。）

## Consequences

- `config/enterprises.php` 的结构是 `Company { name, credentials: Credential[], source_names }`，`Credential { appkey, secretkey, ref_ent_id, ent_id, label, bill_types? }`；`label` 供下拉框显示，`bill_types` 是该凭据适用的单据类型白名单（缺省全集）
- `upload_tasks` / `upload_logs` 各加 `company` 与 `credential` 两列；`ent_list` 加 `company` 列并把 `ent_name UNIQUE` 改为 `UNIQUE(company, ent_name)`
- **本轮不实现自动分发规则**：零售侧只有人工手动补传会真正用到凭据，由用户在页面下拉显式选择用哪套凭据，比猜一套规则可靠。自动分发规则等恢复平台状态查询/对账、有真实数据可依时再补
- 选择在**只有一家批发企业时**就改 `ent_list` 的唯一约束，是因为此时成本最低——等第二个批发主体进来再改就要停机洗数据
- 凭据明文进 `config/enterprises.local.php`（gitignore），仓库只留 `config/enterprises.example.php` 模板；河药批发一并迁入该结构，不留两套机制（避免"新代码读配置、旧代码读 `.env`"的长期分裂）
