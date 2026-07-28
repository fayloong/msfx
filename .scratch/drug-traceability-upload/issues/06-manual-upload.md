# 06 — 手动上传

**What to build:** 在线新增和 xlsx 导入两种手动上传方式，提交后立即触发上传，结果实时反馈。完成后用户可以不依赖 cron 自行创建和上传单据。

**Blocked by:** 04 — 上传任务页面

**Status:** ready-for-agent

- [ ] 在线新增表单：日期、单号、往来单位名称、追溯码（textarea，逗号分隔）
- [ ] 前端验证：日期格式、单号必填、往来单位必填、追溯码非空
- [ ] 提交后写入 `upload_tasks`（source=manual, status=pending），立即调用 UploadService 上传
- [ ] 上传结果实时反馈（成功/失败提示）
- [ ] xlsx 导入：上传文件 → PhpSpreadsheet 解析 → 验证每行数据 → 批量写入 `upload_tasks` → 立即上传
- [ ] xlsx 导入错误处理：某行验证失败不影响其他行，返回错误行号和原因
- [ ] 模板下载按钮：生成包含表头（日期、单号、往来单位名称、追溯码）的 xlsx 文件并下载
- [ ] 导入进度提示（如超过 50 条显示处理进度）
- [ ] API 端点：`POST /api/manual/create`、`POST /api/manual/import`、`GET /api/template/download`
- [ ] 手动上传的单据同样经过 >3500 追溯码拆分逻辑
