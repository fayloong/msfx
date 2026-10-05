# 02: 加逐单新鲜度门卫并挂 crontab（从"手动跑"升级为"定时跑"）

**What to build:** 给票 01 交付的 `scripts/check_bill_status_retail.php` 补**逐单新鲜度门卫**（`last_checked_at`，30 分钟，口径照 `check_bill_status.php`），然后把脚本挂进 root crontab。

**Blocked by:** 01（已 done）

**Status:** ready-for-agent（2026-10-05 开票，用户拍板要挂 cron）

---

## 为什么票 01 没做、现在为什么必须做

票 01 的 spec 明写「**不做 `last_checked_at` 门卫、也不 touch**」，理由是当时它**手动跑**：清单随翻正自然收敛，"查过但没传"下次跑重查是期望行为。**挂 cron 让那个前提消失了**——定时跑会反复查同一批未上传单，门卫就是为这个场景存在的。

⚠️ **一处硬依赖，顺序不能反**：门卫的判据是 `last_checked_at`，而票 01 **一个字节都没写它**（决策就是"不 touch"）。所以本票**必须同时补 touch**，否则门卫形同虚设——所有行的 `last_checked_at` 恒为 NULL，「NULL 或已过期」永远为真，每轮照样全查。

## 要做的事

### 1. 逐单门卫（口径照批发，别自创）

`check_bill_status.php:64` 那句条件：

```sql
AND (last_checked_at IS NULL OR last_checked_at <= ?)
```

阈值 `CHECK_INTERVAL_MINUTES = 30`（与批发两个脚本同值——运维不该记两套门卫语义）。

- 两张表都有 `last_checked_at`（`upload_tasks` 与 `upload_logs`，见 CLAUDE.md 表结构）
- ⚠️ **不能直接加在 `RetailExternalUploads::pendingItems()` 里**：那个方法**状态闭环共用**，而闭环刻意**每轮都跑**（票 03 的铁律——它抓"外部系统后来才传成"的跨日翻转，被门卫挡住就漏了）。做法：给 `pendingItems()` 加可选参数（`?int $freshnessMinutes = null`，`null` = 不过滤 → 闭环不传，平台核查传 30），**别另写一段取数**（口径要一处）
- 参数解析：要不要给脚本加 `--ignore-freshness`？**不要**（批发没有；需要强制重查时用 `--company` 缩小范围，或删 `last_checked_at`——别为想象中的需求加开关）

### 2. touch（照批发的规则，三个分支各有各的口径）

`check_bill_status.php` / `check_failed_logs.php` 的既定规则：

| 分支 | 批发怎么做 | 本脚本对应 |
|---|---|---|
| API 查询成功（含"信息不存在"） | **touch** | `OUTCOME_ABSENT` → touch |
| 已确认在平台（本地已有成功记录，没调 API） | `check_bill_status` 翻任务行并 touch；`check_failed_logs` **一个字都不写、也不 touch** | `OUTCOME_UPLOADED`（会翻正，`applyActions()` 里顺带写 `last_checked_at`） |
| API 异常 | **不 touch**（下次 cron 自动重查） | `OUTCOME_ERROR` → 不写 |

⚠️ **写放大要先算**：本机 SQLite 单条 UPDATE = 一次 fsync（**21–28ms**，见 `Database::transaction()` 的注释）。一趟 1,410 条若逐条 UPDATE，光 touch 就 **60–79 秒**白等。**建议批量 touch**：`run()` 收集本轮的 `(company, djbh)`（照 `uploaded_by_company` 的样式），跑完按表分组、`IN` 分块（复用 `RetailExternalUploads::IN_CHUNK_SIZE`）、包进 `Database::transaction()` **一次提交**。**别照抄批发的逐条写法**——它一趟只有几十条，本脚本一趟上千条。量级先实测再定。

### 3. 挂 crontab

**格式铁律**（CLAUDE.md「cron 时间表」注 +「环境配置 → 文件权限」）：

```
<调度> su -s /bin/bash nginx -c '/usr/bin/php /usr/share/nginx/mashangfangxin/scripts/check_bill_status_retail.php' >> /var/log/msfx_cron.log 2>&1
```

重定向留在 root 那侧（`/var/log/msfx_cron.log` 仍是 root:root，不给 nginx 写权限）。

**分钟点要避开已占用的**：`fetch_bills` 0/30、`fetch_bills_retail` 5/35、`check_bill_status` 8-20 点的 0/30。**建议 `20,50 * * * *`**——一趟约 12 分钟（20:00–20:12、20:50–21:02），与下一轮、与采集的写库窗口都不重叠。

- **时段**：零售用**自己的 AppKey**，不受批发 8-20 点窗口约束 → 技术上可全天；只跑白天还是全天，**开工时问用户**（别自己定）
- **门卫 30 分钟 + cron 30 分钟 = 每轮清单基本全量**（批发就是这个组合，效果一样）；门卫真正防的是"手动跑过一次后 cron 又来"以及将来频率调密时的重复调用
- 与 ADR 0011 的关系：**本脚本只查询、不申报**（`upbilldetail` 是只读接口），挂 cron **不**触碰"补传只能人工触发"那条决策——别去改那个 ADR

安装（**先试着装，被拦再交给用户**；见记忆里"权限策略时灵时不灵"那条）：

```bash
crontab -l > /tmp/crontab-backup-$(date +%F-%H%M).txt   # 先备份
# 只加本项目这一行，其它项目的条目逐字保留
crontab <新文件> && crontab -l | grep check_bill_status_retail
```

## 验收项

- [ ] 门卫生效：连跑两次，第二次**一次 API 都不调**（输出里看得出被挡下多少条）
- [ ] `last_checked_at` 真被写：跑完直查两张表，`ABSENT` 的行时间戳更新、**`ERROR` 的行没动**
- [ ] 状态闭环**不受影响**：`fetch_bills_retail.php` 跑一轮，闭环仍处理全部待办（门卫不作用于它）
- [ ] 批量 touch 的形状有自包含断言（`tests/retail_platform_check_test.php` 扩用例；门卫过滤是 SQL，靠副本实测）
- [ ] 全部离线测试绿
- [ ] crontab 装好并 `crontab -l` 核对；真跑一轮后 `find logs data -user root` 为空
- [ ] 文档：CLAUDE.md 的 cron 时间表加一行（含"已挂"注记）、票 01 那节与 spec 里"刻意不做门卫/touch"改口径并指向本票、常用命令补 cron 说明

## 前提与坑（开工先读）

- 本票改的是票 01 的产物：先读 `.scratch/retail-platform-check/spec.md` 与票 01（**含 Comments 里的 code-review 收口**——那里记了四条"不修"的判定，别推翻）
- 脚本与所有验证**一律以 nginx 身份**跑（`su -s /bin/bash nginx -c '…'`）：属主铁律，`logs/api_<日期>.jsonl` 一天一个文件，被 root 建出来当天就废
- **副本验证要重建**：票 01 留的 `/tmp/verify-pc` 里的代码已落后（收口改动没同步全），别在旧副本上验；重建后 `chown -R nginx:nginx`
- 平台调用最小化：门卫验证不需要真跑 1,410 条——用 `--limit=N` 或副本 + 少量单号（记忆里"平台写入最小化"那条：只读查询正常跑，但没必要空烧额度）
- 写生产库 `data/msfx.db`（touch）前先 `.backup`：`sqlite3 data/msfx.db ".backup '/root/msfx-backup-$(date +%F-%H%M).db'"`

## Comments

（新会话把实现过程中的决策与证据追加在这里）
