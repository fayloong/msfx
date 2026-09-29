# 02 - SQLite 多企业迁移（列 + 去重键 + ent_list + 新状态）

- Type: task
- Status: ready-for-agent
- 关联：spec.md §7/§8、docs/adr/0006-credential-as-routing-subject.md

## 问题

`upload_tasks` / `upload_logs` 无企业概念；去重键是单 `djbh`；`ent_list.ent_name` 是全局 UNIQUE。

## 实现

- `upload_tasks` / `upload_logs` 各加 `company`（门店中文名）、`credential`（凭据标识/AppKey）两列，`init_db.php` 幂等 `ALTER TABLE` 风格迁移
- **去重键 `djbh` → `(company, credential, djbh)`**：一门店多主体时，主体 A 传成功不代表主体 B 传成功
- `ent_list` 加 `company` 列，唯一约束 `ent_name UNIQUE` → `UNIQUE(company, ent_name)`（需重建索引/表），现有数据回填河药
- 历史 `upload_tasks` / `upload_logs` 行回填河药批发
- `task_status` 新增取值 `待补传`（零售专用，见 03）

## 验收

迁移脚本可重复执行；现有数据回填后页面与既有查询不受影响；`ent_list` 同企业内仍唯一、跨企业可同名
