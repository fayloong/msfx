# 02 — 核心上传管线 (CLI)

**What to build:** 核心上传逻辑，从 SQL Server 取数据 → 拼装追溯码 → 调码上放心 API → 写日志，全程在 CLI 下可运行。完成后 `php scripts/cron_upload.php` 可以跑通完整流程。

**Blocked by:** 01 — 基础与登录

**Status:** ready-for-agent

- [ ] ApiClient：封装 TopClient，处理网络超时 vs 业务错误分类（NetworkException / BusinessException）
- [ ] TaskFetcher：封装 `config/sql.php` 查询逻辑，支持日期参数（`GETDATE()` 当天），返回标准化数组
- [ ] UploadService：核心上传函数（cron 和 Web 共用），入参单据列表
  - [ ] 按单号拼接追溯码（逗号分隔）
  - [ ] 单号 >3500 追溯码自动拆分为 `单号_1, 单号_2...`
  - [ ] 查 ent_list 缓存，未命中调 API 获取并写入缓存
  - [ ] 调用 ApiClient 上传
  - [ ] 重试：最多 3 次，间隔 30s，仅网络错误重试，业务错误不重试
  - [ ] API 间隔 0.33s
  - [ ] 文件锁防并发（flock）
- [ ] LogWriter：JSONL 追加写入 `logs/api_YYYY-MM-DD.jsonl` + SQLite 同步写入 `upload_logs`
- [ ] `scripts/cron_upload.php`：cron 入口，串联 TaskFetcher → UploadService → LogWriter
- [ ] `scripts/cleanup_logs.php`：定时清理 SQLite 超过 3 个月的记录
- [ ] 手动测试：`php scripts/cron_upload.php` 成功上传并产生 JSONL + SQLite 记录
