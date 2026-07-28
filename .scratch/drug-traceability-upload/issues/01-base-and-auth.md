# 01 — 基础与登录

**What to build:** 搭建项目目录结构、依赖、数据库、Web 入口和单用户登录认证。完成后可以访问登录页并成功登录/登出。

**Blocked by:** None — can start immediately。

**Status:** ready-for-agent

- [ ] 目录结构就绪：`src/`、`public/index.php`（单入口）、`scripts/`、`data/`（SQLite）、`logs/`
- [ ] 通过 Composer 安装 PhpSpreadsheet 依赖
- [ ] `.env` 增加 `ADMIN_PASSWORD` 和 `ADMIN_PASSWORD_HASH`（bcrypt）
- [ ] SQLite `data/msfx.db` 建表：`upload_tasks`、`upload_logs`、`ent_list`（按 spec schema）
- [ ] Nginx 配置：`root public/`，PHP 请求转发 `127.0.0.1:9008`，监听 `192.168.2.189:8188`
- [ ] SELinux：`data/` 和 `logs/` 设 `httpd_sys_rw_content_t`
- [ ] `public/index.php` 单入口路由骨架（`page` 参数分发）
- [ ] Auth 模块：登录页、POST 验证、session 管理、登出
- [ ] 未登录时任何页面请求重定向到登录页
- [ ] 登录页 UI 简洁大气，居中表单，Bootstrap 5 风格
