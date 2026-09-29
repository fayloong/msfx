# 01 - 企业配置与凭据模型

- Type: task
- Status: ready-for-agent
- 关联：spec.md §1/§2、docs/adr/0006-credential-as-routing-subject.md

## 问题

凭据写死在代码里（`ApiClient` 构造函数默认 `*_HYYY`、`searchBillDetail`/`queryEntInfo`/`searchSingleRelation` 硬编码 `REFENTID_HYYY`、`UploadService::uploadSingle` 写死 kyt 请求类），无法支持多企业。

## 实现

- 新建 `config/enterprises.php`：`Company { name, credentials: Credential[], source_names }`，`Credential { appkey, secretkey, ref_ent_id, ent_id, label, bill_types? }`
- 凭据明文读 `config/enterprises.local.php`（已在 `.gitignore`）；提交 `config/enterprises.example.php` 模板
- 河药批发迁入该结构，逐步下线 `Config::get('APPKEY_HYYY')` 等旧写法（`.env` 保留数据库连接与管理员密码）
- 新增企业/凭据枚举与查询能力的类（如 `App\Enterprise`），供页面下拉与采集匹配复用

## 验收

`tests/enterprise_config_test.php`：1:N 凭据解析、`bill_types` 缺省全集、门店名字典、别名（`source_names`）命中
