# 多企业支持：凭据（AppKey）是路由主体，门店与凭据 1:N

接入零售连锁后系统从单企业变为多企业。决定引入两个领域概念——**企业（Company，门店）**与**凭据（Credential，一套 AppKey/SecretKey/ref_ent_id/ent_id）**，**以凭据而非门店作为"用哪套凭据调哪个接口"的路由主体**，门店与凭据是 1:N 关系。

## Context

设计过程中出现过两个被推翻的模型：

1. **按门店建模**（第 1 轮）：假设"每个物理门店一套 AppKey"。第 2 轮拿到门店凭据草稿后发现 9 家门店中有 8 家共用同一个 AppKey，该假设不成立。
2. **按 AppKey 建模**（第 2 轮推荐）：假设 AppKey 是企业主体级、门店靠 `ref_ent_id` 区分。第 3 轮用户澄清最终形态是**"每个门店的 appkey、SECRETKEY 都不相同，每个门店也可能存在多套 appkey、SECRETKEY"**，该假设同样不成立——AppKey 与门店没有函数依赖关系。

同时，"同一个单据类型码在不同企业下走不同接口"已确认（零售 `104`/`203` → `lsyd.uploadinoutbill`、`321`/`116` → `lsyd.uploadretail`、批发走 `kyt.uploadinoutbill`），故路由键必须至少是 `(企业, 单据类型)` 二元组；再叠加"一门店多套凭据"后，落库记录的去重键必须是三元组 `(company, credential, djbh)`——**同一门店的两个主体，主体 A 传成功不代表主体 B 传成功**，用二元组去重会出现"B 主体漏传但被 A 主体的成功记录挡住"。

## Consequences

- `config/enterprises.php` 的结构是 `Company { name, credentials: Credential[], source_names }`，`Credential { appkey, secretkey, ref_ent_id, ent_id, label, bill_types? }`；`label` 供下拉框显示，`bill_types` 是该凭据适用的单据类型白名单（缺省全集）
- `upload_tasks` / `upload_logs` 各加 `company` 与 `credential` 两列；`ent_list` 加 `company` 列并把 `ent_name UNIQUE` 改为 `UNIQUE(company, ent_name)`
- **本轮不实现自动分发规则**：零售侧只有人工手动补传会真正用到凭据，由用户在页面下拉显式选择用哪套凭据，比猜一套规则可靠。自动分发规则等恢复平台状态查询/对账、有真实数据可依时再补
- 选择在**只有一家批发企业时**就改 `ent_list` 的唯一约束，是因为此时成本最低——等第二个批发主体进来再改就要停机洗数据
- 凭据明文进 `config/enterprises.local.php`（gitignore），仓库只留 `config/enterprises.example.php` 模板；河药批发一并迁入该结构，不留两套机制（避免"新代码读配置、旧代码读 `.env`"的长期分裂）
