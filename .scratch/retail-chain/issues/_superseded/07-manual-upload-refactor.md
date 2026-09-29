# 07 - 手动上传模块重构（顶部企业下拉 + 按企业切换表单）

- Type: task
- Status: ready-for-agent
- 关联：spec.md §11

## 问题

批发与零售的手动上传字段需求、接口、凭据完全不同，现有 `manual_create.php` / `manual_import.php` / `template_download.php` 只服务批发。

## 实现

- `manual_upload.php` **页面最上方加"所属企业"下拉**，选定后显示对应表单内容
- **批发表单保持现状**：日期 / 单号 / 单据类型下拉 / 往来单位 / 追溯码
- **零售不提供从零手工录入**：入口是数据页上的"重传"，元数据（from/toUserId、refUserId、日期、类型、追溯码）取自落库记录，用户只选门店 + **凭据** + 单号
- 凭据由用户**显式选择**（下拉显示 `门店名（label）`；门店仅一套时显示门店名）——见 ADR 0006
- 接口由 `(企业类型, 单据类型)` 路由决定：104/203 → `lsyd.uploadinoutbill`、321/116 → `lsyd.uploadretail`
- **xlsx 导入只保留批发**；`template_download.php` 不分叉
- **拆独立文件**（如 `api/manual_create_retail.php`），不改造现有批发文件；`public/index.php` 按企业类型分发
- 追溯码拆分阈值按接口取：`lsyd.uploadinoutbill` 10000、`lsyd.uploadretail` 3500、kyt 3500（现 `UploadService::MAX_TRACE_CODES = 3500` 是全局常量，需参数化）

## 验收

`tests/retail_upload_test.php` 断言请求装配：`refUserId` 必须是 ref_ent_id、`from/toUserId` 必须是 entId、`clientType` 必须是 `"2"`、`physicType` 必须填——混淆这几项会导致上传到错误主体
