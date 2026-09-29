# 04 - 提取零售 SDK 的 lsyd 请求类并入 top_sdk/

- Type: task
- Status: ready-for-agent
- 关联：spec.md §4

## 问题

零售需要 `AlibabaAlihealthDrugtraceTopLsydUploadinoutbillRequest`（104/203）与 `...UploadretailRequest`（321/116），它们只存在于 `top_sdk_retail.zip`（未入仓）。

## 实现

- **先比对**：`top_sdk_retail.zip` 与现有 `top_sdk/` 有 67 个同名文件、31 个内容不同（30 个 domain + `TopClient.php`，而 `TopClient.php` 差异只有 `sdkVersion` 一行）。逐个确认差异是纯注释还是字段新增/重命名
- **若差异仅为注释** → 只把两个 lsyd 请求类 + 其依赖 domain 类拷入 `top_sdk/top/request/`、`top/domain/`
- **若 `CodeRelationDto` 等码级对账在用的 domain 有字段变化** → 改为整体覆盖升级到新版 SDK（连 `TopClient` 版本号一并处理）
- **不要**建独立目录 `top_sdk_retail/`：两个 `Autoloader.php` 都声明全局 `class Autoloader`（PHP 无 namespace，重声明 Fatal error），且 `TopSdk.php` 的 `TOP_AUTOLOADER_PATH` 常量第二个 SDK 会因已定义而跳过，自动加载指向错误目录

## 验收

`php -r` 能 `new` 两个 lsyd 请求类并取到正确的 `getApiMethodName()`；现有批发上传与数量对账测试全部通过（`tests/quantity_check_test.php`、`tests/trace_splitter_test.php`）
