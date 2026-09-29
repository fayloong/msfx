# 05: 零售补传的请求装配 + 测试接缝

- Type: task
- Status: ready-for-agent
- Blocked by: 04
- 关联：spec.md §3、Testing Decisions（本 feature 收敛为 2 个接缝，这一票落地第 2 个）、docs/adr/0008-retail-claim-by-platform-id.md

## 要交付的行为

给定一条**已落库的零售单据 + 一套门店凭据**，能装配出一个参数完全正确的 lsyd 请求对象——
且装配过程**不发起任何网络调用**，可以在测试里断言。

这是本 feature **唯一新增的测试接缝**（第 1 个 `App\Enterprise` 已随工单 01 落地）。**不新增第三个接缝**：
采集 SQL 的去重与码拼接、Web 页面交互都不写单元测试，前者靠"指定日期采集后与源库去重单号数比对"的人工验收（工单 03 的验收标准），后者靠页面实测。

## 范围

**装配（纯函数，无 IO、无网络）**

- 输入：落库行（单号 / 日期 / 单据类型 / 追溯码）+ 一套凭据 + 路由结果 → 返回**已填充的请求对象**
- 接口由 `(企业类型, 单据类型)` 路由决定，走 `App\Enterprise::route()`（工单 01 已就绪），**不硬编码请求类**
- 追溯码上限也取自路由：`lsyd.uploadinoutbill` **10000** / `lsyd.uploadretail` 3500 / 批发 kyt 3500——不再用现在那个全局常量 3500

**参数映射（写错任意一项都会把单据申报到错误主体，且在平台上不可逆）**

- `refUserId` ← **凭据里的 `ref_ent_id`**，**不是**源表 `zsm_ls.ref_ent_id`——那一列全表单一值、属总部主体
- `fromUserId` / `toUserId` ← **源表同名列**（`zsm_ls.from_user_id` / `to_user_id`），**不是**凭据的 `ent_id`
  - 依据：这两列不随调拨方向翻转（`104` 门店收货与 `203` 门店发货的 `from_user_id` 都只有总部一个值、`to_user_id` 才是门店），观察到的规律是 **from = 单据发起方、to = 对方主体**，与列名语义自洽；`321`/`116` 由门店发起（`from_user_id` = 门店、`to_user_id` 空）也落在这个规律里
  - 用户 2026-09-29 定案：照搬源表同名列
- `clientType` 恒为 `"2"`；`physicType` 必须填
- 两个接口的必填集不同（`lsyd.uploadretail` 只要 billCode / billTime / billType / refUserId / traceCodes）——
  **可选字段一律不填**：少填比填错安全，等与外部系统工程师对齐后再决定是否补齐

**测试**：新增 `tests/retail_upload_test.php`

- 自包含断言脚本、无框架、失败非零退出，对齐 `tests/enterprise_config_test.php` 的风格
- fixture 用**固化数组**：不连生产库、不依赖部署机的真实凭据文件（`config/enterprises.local.php`）
- 断言 `getApiParas()`，不 mock 平台 API

**ADR**：落一条，记零售上传入参的映射规则（尤其 `refUserId` 取凭据而非源表、`from/toUserId` 照搬源表列）

## 验收

- [ ] 断言 `getApiParas()`：`refUserId == 凭据.ref_ent_id`、`fromUserId`/`toUserId` == 源表对应列、`clientType == "2"`、`physicType` 非空
- [ ] **必须包含一个 `ref_ent_id ≠ ent_id` 的用例**：把两个字段对换，测试必须变红
  - 新江分店是 15 家里唯一这样的门店（旧 entId 用到 2025-07、新 refEntId 自 2024-03 起，两个 ID 都在源库出现）——用例的**形状**照它构造，**取值用占位符**（平台 ID 属凭据级信息，不入仓）
  - 用 `ref_ent_id == ent_id` 的门店做用例，互换了也测不出来，等于没测
- [ ] 两个接口各一个用例（`104`/`203` 走 `uploadinoutbill`、`321`/`116` 走 `uploadretail`），断言路由确实按 `(企业类型, 单据类型)` 走而不是硬编码
- [ ] 码上限断言取自路由：对 `104` 断言上限 10000（而不是全局常量 3500）
