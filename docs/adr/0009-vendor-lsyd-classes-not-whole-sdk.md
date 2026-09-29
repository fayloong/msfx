# 零售接口按类并入现有 `top_sdk/`，不引入第二套 SDK、也不整体覆盖

零售要用的两个接口（`alibaba.alihealth.drugtrace.top.lsyd.uploadinoutbill` / `...uploadretail`）以**逐类并入**的方式进入仓库里已有的 `top_sdk/top/request/`：只新增这两个请求类文件，`top_sdk/` 既有文件一个字节都不动（`TopClient.php` 的 `sdkVersion` 也不动）。**批发链路（`drug.kyt.*`）继续用现在这套请求类。**

## Context

`top_sdk_retail.zip`（阿里健康提供，2026-09-29 拿到）与仓库内 `top_sdk/` 的实测比对结论（2026-09-30 工单 04）：

| | 数量 |
|---|---|
| 同名文件 | 68（完全相同 37 / 内容不同 31） |
| 仅旧包（仓库内）有 | **40** |
| 仅新包（压缩包）有 | 260 |

三条决定性事实：

1. **新包不是"同一套 SDK 的新版本"，是另一 API 世代。** 新包的 kyt 类全部是 `drug.kyt.wes.*`，旧包是 `drug.kyt.*`；旧包 `top/request/` 的 **34 个接口方法**（批发链路在用的 `AlibabaAlihealthDrugKytUploadinoutbillRequest` / `...SearchbillDetailRequest` / `...SinglerelationRequest` / `...ListpartsRequest` 等四个请求类都在其中），**新包一个都没有**。所谓"整体覆盖升级"实际是"删掉批发链路全部请求类"——`ApiClient` 里 `new \AlibabaAlihealthDrugKytSearchbillDetailRequest` 之类会立刻 Fatal error。
2. **`top/domain/` 下的 DTO 类在本项目里是完全惰性的。** `TopClient` 里没有任何 domain 相关代码：`$format = "xml"`，`execute()` 把响应经 `simplexml_load_string` 得到的 **`SimpleXMLElement`** 直接返回（`exec()` 也只按方法名反推 request 类）。`src/` `scripts/` `tests/` `config/` `public/` 对这些类零引用。所以"某个 domain 有字段变化就得升级 SDK"这个触发条件不成立——31 个差异 domain 里 21 个只是增删属性（`CodeRelationDto` 新增 `query_code_mix_flag`/`top_code_mix_flag`），对运行时零影响。
3. **两个 lsyd 请求类零依赖**：不 `new` 任何 domain 类、无 `extends`，只用到 `RequestCheckUtil`（旧包已有，两包该文件逐字节相同）。

## 排除的方案

- **独立目录 `top_sdk_retail/`**：两个 `Autoloader.php` 都声明全局 `class Autoloader`（PHP 无 namespace，重声明 Fatal error），且 `TopSdk.php` 的 `TOP_AUTOLOADER_PATH` 常量第二个 SDK 会因已定义而跳过、自动加载指向错误目录。要修就得改 SDK 文件，与 `top_sdk/` 不可修改的约定冲突。
- **整体覆盖 `top_sdk/`**：见 Context 第 1 条，等于砍掉批发链路。
- **只覆盖 30 个同名 domain 类（保留旧 kyt 请求类）**：收益为零（domain 惰性，见第 2 条），却会让 `Model` / `PageInfoDTO` / `Billchkinoutdetaillistdtolist` / `PUserEntInfoDto` 四个类**丢字段**（如 `PageInfoDTO` 的 `page`/`page_size`/`pages` 消失、`result` 改名 `result_list`）——纯风险。
- **压缩包 `top_sdk_retail.zip` 进仓库**：不进。仓库只保留用到的两个类。

## Consequences

- 新老两代请求类**共存**于 `top_sdk/top/request/`：`drug.kyt.*`（批发，本项目在用）与 `drugtrace.top.lsyd.*`（零售，工单 05/06 起用）。类名与文件路径均不冲突，自动加载不受影响。
- **将来若要跟进新版 SDK，只能逐类挑**，不能整包覆盖——本 ADR 与工单 04 的比对表就是"哪些类能换"的依据。换 `TopClient.php` 时注意 `sdkVersion` 差异（`top-sdk-php-20260203` → `20260929`），它会作为 `partner_id` 随会话请求发出。
- **两个 lsyd 请求类的 `check()` 是"这个接口哪些参数必填、码上限多少"的权威表述**（由平台"是否必填"元数据生成）：`uploadinoutbill` 强制非空 9 项（含 `clientType`） + `checkMaxListSize(traceCodes, 10000)`；`uploadretail` 强制非空 5 项（**无 `clientType` 字段**） + `checkMaxListSize(traceCodes, 3500)`。工单 05 直接拿它当判据，不在文档里另抄一份。
