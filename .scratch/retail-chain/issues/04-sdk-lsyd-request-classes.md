# 04: 把 lsyd 请求类并入 top_sdk/

- Type: task
- Status: ready-for-agent
- Blocked by: None（可与 02/03 并行）
- 关联：spec.md §4

## 要交付的行为

零售两个接口（`alibaba.alihealth.drugtrace.top.lsyd.uploadinoutbill` / `...uploadretail`）的请求类在本仓库内可用、
与零售 SDK 压缩包解耦；**批发链路一行代码都没变**。

## 范围

- **先比对**：`top_sdk_retail.zip` 与现有 `top_sdk/` 有 67 个同名文件，其中 31 个内容不同（30 个 domain + `TopClient.php`，而 `TopClient.php` 的差异只有 `sdkVersion` 一行）。逐个确认差异是**纯注释**还是**字段新增/重命名**
- 若差异仅为注释 → 只把 `AlibabaAlihealthDrugtraceTopLsydUploadinoutbillRequest`、`AlibabaAlihealthDrugtraceTopLsydUploadretailRequest` 及其依赖 domain 类并入 `top_sdk/top/request/`、`top/domain/`
- 若 `CodeRelationDto` 这类**码级对账已在用的** domain 有字段变化 → 升级为整体覆盖升级（连 `TopClient` 版本号一并处理），并在本票 Comments 里记下比对结论
- `TopClient` 是通用的，走哪个 API 完全由请求类的 `getApiMethodName()` 决定——**零售接口不需要"零售 SDK"**
- **不要**建独立目录 `top_sdk_retail/`：两个 `Autoloader.php` 都声明全局 `class Autoloader`（PHP 无 namespace，重声明 Fatal error），且 `TopSdk.php` 的 `TOP_AUTOLOADER_PATH` 常量第二个 SDK 会因已定义而跳过、自动加载指向错误目录；要修就得改 SDK 文件，而项目约定 `top_sdk/` 不可修改
- 压缩包本身**不进 git**

## 验收

- [ ] 两个 lsyd 请求类能实例化，`getApiMethodName()` 分别为 `alibaba.alihealth.drugtrace.top.lsyd.uploadinoutbill` 与 `alibaba.alihealth.drugtrace.top.lsyd.uploadretail`
- [ ] `php tests/trace_splitter_test.php` 与 `php tests/quantity_check_test.php` 全绿
- [ ] 批发上传路径无改动：`UploadService` 的 kyt 请求类仍能正常构造并取到参数
