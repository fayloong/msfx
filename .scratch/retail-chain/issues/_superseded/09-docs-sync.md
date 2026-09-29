# 09 - 文档同步

- Type: task
- Status: ready-for-agent
- 关联：CLAUDE.md 的"文档同步规则"

## 实现

- `CLAUDE.md`：架构图（新增 `config/enterprises*`、`scripts/fetch_bills_retail.php`、零售 api/views 文件）、Web 路由表、表结构（`company`/`credential`/`ent_list` 唯一约束/`待补传` 状态）、核心数据流（新增零售采集链路）、常用命令
- `CONTEXT.md`：补"所属企业 / 往来单位"术语对、"凭据（Credential）"、"待补传"、"未识别门店"、"零售连锁"
- `docs/adr/`：0006、0007 已就位
- `.scratch/retail-chain/spec.md`：状态从 designing 更新为 implemented 并追加实现记录

## 验收

文档与代码现状一致，随代码同一提交
