# 01 - 企业配置与凭据模型

- Type: task
- Status: done（2026-09-29）
- 关联：spec.md §1/§2/§3/§6、docs/adr/0006-credential-as-routing-subject.md、docs/adr/0008-retail-claim-by-platform-id.md

## 问题

凭据写死在代码里（`ApiClient` 构造函数默认 `*_HYYY`、`searchBillDetail`/`queryEntInfo`/`searchSingleRelation` 硬编码 `REFENTID_HYYY`、`UploadService::uploadSingle` 写死 kyt 请求类），无法支持多企业。

## 实现（已完成）

- `config/enterprises.php`：`Company { key, name, type, ids, credentials }`、`Credential { key, label, primary, appkey, secretkey, ref_ent_id, ent_id }`
- 平台 ID 与凭据明文读 `config/enterprises.local.php`（`.gitignore`）；提交 `config/enterprises.example.php` 模板
- 河药批发迁入该结构（`heyao` 条目，**凭据引用 `.env` 而不复制明文**——迁移期避免两份不一致；待 `ApiClient` 全面改读配置后再删 `.env` 旧键）
- `src/Enterprise.php`：载入合并 + `claim()`（平台 ID 优先 / 名字回退 / 未识别）+ `route()`（请求类 + 码上限）+ `validate()`（配置自检，违反即抛异常）
- 15 家门店 `ids` 由 2026-09-29 探测直接生成（含历史 ID 与源库错值），写入 local 文件

**与初稿的三处出入（依据见 ADR 0008）**：

1. `bill_types` **删除**（成因是限流备用、与单据类型无关）；新增 `primary`，且**声明在结构文件**而非凭据文件——"哪套是默认授权"不随密钥是否到手变化
2. `source_names`（名字别名）**退化为 ID 缺失时的回退匹配**：认领主键改为平台 ID（`oper_ic_name` 在 321/116 上全空，占 84.7%）
3. 门店需要**多个**平台 ID（新江 2 个、雅居乐 4 个，含源库错值）

## 验收（已通过）

`php tests/enterprise_config_test.php` —— **49 项断言全绿**，覆盖：1:N 凭据解析、待配凭据合法、认领三分支与选列规则、历史 ID 别名、路由与码上限（含"未知类型不猜"）、9 类配置自检拒绝、真实配置文件载入自检（16 家企业 / 15 家门店 / 5 家已配凭据）
