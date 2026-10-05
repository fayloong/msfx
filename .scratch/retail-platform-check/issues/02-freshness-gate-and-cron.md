# 02: 加逐单新鲜度门卫并挂 crontab（从"手动跑"升级为"定时跑"）

**What to build:** 给票 01 交付的 `scripts/check_bill_status_retail.php` 补**逐单新鲜度门卫**（`last_checked_at`，30 分钟，口径照 `check_bill_status.php`），然后把脚本挂进 root crontab。

**Blocked by:** 01（已 done）

**Status:** done——提交 `29cf2f9`、收口 `aecf937`，gitee 与 origin 均已跟上（2026-10-05）

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

**分钟点要避开已占用的**：`fetch_bills` 0/30、`fetch_bills_retail` 5/35、`check_bill_status` 8-20 点的 0/30。**约定 `20,50 8-21 * * *`**——起点避开上面那几个分钟点。⚠️ **一趟约 12 分钟，会跨过下一个 :30 采集轮**（8:20 起跑、8:32 收尾），票面初稿那句"与采集的写库窗口不重叠"**是错的**（code-review 的 Spec 轴 2026-10-05 抓到）：它真正的写只有收尾那**一次亚秒级事务**（批量 touch），撞锁由双方的 `busyTimeout(30s)` 兜底——与 `check_bill_status` 等既有脚本同一处境，可以接受，但不能说成不重叠。

- **时段：用户拍板「只跑白天」（2026-10-05）** → 上表那个 `8-21`。零售用**自己的 AppKey**、不受批发 8-20 点窗口约束，技术上本来可以全天——**刻意不跑夜间**：门店单据的流转发生在营业时间，夜里那几轮查到的多半是白天已查过的同一批（白烧调用还可能撞限流）；隔夜的新单由次日 8:20 那轮补上（门卫 30 分钟，隔了一夜早过期）。**要改时段就改这一处**（`8-21` 三段），别只改注释
- **门卫 30 分钟 + cron 30 分钟 = 每轮清单基本全量**（批发就是这个组合，效果一样）；门卫真正防的是"手动跑过一次后 cron 又来"以及将来频率调密时的重复调用
- 与 ADR 0011 的关系：**本脚本只查询、不申报**（`upbilldetail` 是只读接口），挂 cron **不**触碰"补传只能人工触发"那条决策——别去改那个 ADR

安装（**先试着装，被拦再交给用户**；见记忆里"权限策略时灵时不灵"那条）：

```bash
crontab -l > /tmp/crontab-backup-$(date +%F-%H%M).txt   # 先备份
# 只加本项目这一行，其它项目的条目逐字保留
crontab <新文件> && crontab -l | grep check_bill_status_retail
```

## 验收项

- [x] 门卫生效：连跑两次，第二次**一次 API 都不调**（输出里看得出被挡下多少条）
- [x] `last_checked_at` 真被写：跑完直查两张表，`ABSENT` 的行时间戳更新、**`ERROR` 的行没动**
- [x] 状态闭环**不受影响**：`fetch_bills_retail.php` 跑一轮，闭环仍处理全部待办（门卫不作用于它）
- [x] 批量 touch 的形状有自包含断言（`tests/retail_platform_check_test.php` 扩用例；门卫过滤是 SQL，靠副本实测）
- [x] 全部离线测试绿（14 个脚本逐个退出码 0）
- [x] crontab 装好并 `crontab -l` 核对——条目形如
      `20,50 8-21 * * * su -s /bin/bash nginx -c '/usr/bin/php /usr/share/nginx/mashangfangxin/scripts/check_bill_status_retail.php' >> /var/log/msfx_cron.log 2>&1`；
      真跑一轮后 `find logs data -user root` 为空
- [x] 文档：CLAUDE.md 的 cron 时间表加一行（含"已挂"注记）、票 01 那节与 spec 里"刻意不做门卫/touch"改口径并指向本票、常用命令补 cron 说明

## 前提与坑（开工先读）

- 本票改的是票 01 的产物：先读 `.scratch/retail-platform-check/spec.md` 与票 01（**含 Comments 里的 code-review 收口**——那里记了四条"不修"的判定，别推翻）
- 脚本与所有验证**一律以 nginx 身份**跑（`su -s /bin/bash nginx -c '…'`）：属主铁律，`logs/api_<日期>.jsonl` 一天一个文件，被 root 建出来当天就废
- **副本验证要重建**：票 01 留的 `/tmp/verify-pc` 里的代码已落后（收口改动没同步全），别在旧副本上验；重建后 `chown -R nginx:nginx`
- 平台调用最小化：门卫验证不需要真跑 1,410 条——用 `--limit=N` 或副本 + 少量单号（记忆里"平台写入最小化"那条：只读查询正常跑，但没必要空烧额度）
- 写生产库 `data/msfx.db`（touch）前先 `.backup`：`sqlite3 data/msfx.db ".backup '/root/msfx-backup-$(date +%F-%H%M).db'"`

## Comments

### 实现（三处落点，与票面一致）

| 落点 | 做法 |
|---|---|
| `RetailExternalUploads::pendingItems(?int $freshnessMinutes = null)` | 门卫条件下到 SQL，两张表**逐字共用同一句**（` AND (last_checked_at IS NULL OR last_checked_at <= ?)`）；`null` = 不过滤——**状态闭环不传**，平台核查传 30 |
| `RetailExternalUploads::touchChecked(array $checkedByCompany)` | 按企业分块 `IN`（复用 `chunkedIn`，500/块 → 502 个参数，**避开了本机 SQLite 3.7.17 的 999 参数上限**——`(company,djbh)` 两两一对的话 500 块正好 1000 个参数，会撞上限）、整批包进**一次** `Database::transaction()` |
| `RetailPlatformCheck::run()` | 新增 `checked_by_company`：本轮**平台给过答复**的键（已上传 ＋ 未上传都算） |

脚本侧：`CHECK_INTERVAL_MINUTES = 30`；两次 `pendingItems()`（全量 − 过门卫的 = 被挡下的条数，`--company` **两份都过滤**，否则那个数里会混进别的门店）；跑完批量 touch 并打印行数。

**一处与票面不同的取舍**：票面 touch 表里写「`OUTCOME_UPLOADED`（会翻正，`applyActions()` 里顺带写 `last_checked_at`）」，实现改成**统一走批量 touch**、`applyActions()` 一行没动——它是状态闭环**共用**的落库函数，往里塞门卫语义会把"闭环不设门卫"这条铁律搅浑，而且那样也覆盖不到失败记录那半边的行。票面自己那句"建议批量 touch"就是这个方向。

### 验证（副本 `/tmp/verify-pc` 重建 + 本地离线桩；生产库全程只读）

桩：`php -S 127.0.0.1:8197`，按单号回三种应答（`SUCCESS` / `FAIL_BIZ_NO_PAT_INFO` / 顶层错误信封），**逐次记调用**到 `calls.jsonl`——"第二次一次 API 都不调"靠数它。副本只改了 `ApiClient::forCredential()` 一处（读 `MSFX_TOP_GATEWAY` 设 `gatewayUrl`；仓库版是 `return new self(...)` 一行），跑完已还原。

**场景 A（门卫 + touch）**：把全部待办行刷成"刚查过"，只留 3 个键过门卫。

```
第一轮  本地待办 4513 条（涉及 14 家企业）；其中 4510 条在 30 分钟门卫窗口内已查过，本轮跳过
        将核查 3 条（1 家门店）→ 已上传 1 / 未上传 2 / 异常 0
        翻正: 任务行 1 行、追加外部上传记录 1 条 | 门卫记账: 刷新 last_checked_at 5 行
        桩：3 次调用（呼叫的 ref_ent_id 都是新江分店自己那套 61873868e4b032c2577ada38）
第二轮  本地待办 4512 条（涉及 14 家企业）；其中 4512 条在 30 分钟门卫窗口内已查过，本轮跳过
        没有需要核查的待办（都在门卫窗口内，等下轮）
        桩：调用数**仍是 4**（含冒烟 1 次）——一个字节都没发 ✅ 验收项 1
```

（4513 → 4512 是上一轮把那张「已上传」的任务行翻成了「已处理」，它自然地离开了待办清单。）

直查副本库（验收项 2）——⚠️ **下表是"场景 A 第一轮跑完那一刻"的直查值**（2026-10-05 16:54）。
副本随后又跑过：括号变异两轮、真平台冒烟一轮、以及我自己的场景布置脚本（它把全部待办行刷成"刚查过"，
库里会留下一枚 ~16:57:27 刷 4,546 行的戳），收尾还删掉了造的那行跨企业待办、复原了 K2 的
`request_status`——所以**此刻**再打开 `/tmp/verify-pc` 会看到不同的时间戳与一行不存在的造行，
那不是"记录与事实不符"：下面的数各自在它标注的那一刻成立，要复现就按本节步骤重跑场景。

| 键 | 场景 | 落点（跑完那一刻） |
|---|---|---|
| `XLSA0200100185757` | 任务行 / 桩答未上传 | 任务行 `last_checked_at = 16:54:23`，`task_status` 仍是「等待上传」 |
| `XLSA0200100185881` | 任务行 / 桩答已上传 | 任务行翻「已处理」+「上传成功」、`request_status` **保持 NULL**；追加记录 `#97279`（`source=retail_external`、`task_id=0`、`request_status=NULL`）；该键的**任务行与这条新追加的日志行都被刷**（这也是那句"刷新 5 行"的来源：K1 任务 1 ＋ K2 任务与日志 2 ＋ K3 任务与新记录 2） |
| `XLSA0200100180956` | **只有失败记录**（无任务行）/ 未上传 | `upload_logs` 那行被刷——`upload_logs` 那半边的门卫确实在起作用 |
| 大湖分店 / **同一个单号** `…185757`（造的） | 本轮被门卫挡下、压根没查 | `last_checked_at` **停在造它时的 `2026-10-05 16:49:13` 一字未动**——touch 的 SQL 带 `company = ?`，按裸单号刷就会把它一起改成"刚刚" ✅（收尾已把这行删掉，副本库里不再有它） |

**场景 B（查询异常不 touch）**：桩对 `XLSA0200100185901` 回顶层错误信封 → 输出 `查询异常：App Call Limited`、`门卫记账: 刷新 last_checked_at 0 行`；直查那行**仍为 NULL**，**再跑一轮它又被查了一次**（桩计数 +1）——确实没被记账，下轮 cron 自动重查 ✅ 验收项 2 的后半条。

**闭环不受影响**（验收项 3）：副本跑 `fetch_bills_retail.php` →
`状态闭环: 核对 4512 条待办 → 翻正任务 8 行, 追加外部上传记录 8 条`——**全量**，不是门卫后的 4,511 ✅

**括号是承重的（辨别力）**：`upload_logs` 那段原本是 `A OR B OR C` 三个条件，门卫条件拼上去必须给整个 OR 组加括号。去掉括号后副本 `--dry-run` 从「将核查 1 条」变成 **2 条**（多出的正是那条"`请求失败` 且刚查过"的行），还原后回到 1 条。
⚠️ **第一次变异没测出来**：副本里 `request_status='请求失败'` 的日志行**全属批发主体**（河药），被 `pendingItems()` 的 `isRetail` 挡在清单外——SQL 层多出 13 个键、清单层一个不多。造一条零售的才暴露。这条记在这里是因为它说的是一件更大的事：**这类"条件拼错"的 bug 会被别的判据掩盖，验证要造出能暴露它的数据**。

### 生产冒烟（按用户约束：先备份、nginx 身份、跑完复查属主）

- `sqlite3 data/msfx.db ".backup '/root/msfx-backup-2026-10-05-1700.db'"`（225 MB）
- 照 crontab 那条**逐字**跑、只多一个 `--limit=3` 压平台调用：
  `已上传 0 / 未上传 3 / 异常 0 / 跳过 9 家门店 3090 条（共 3 条，另有 1418 条未查（--limit））`、`门卫记账: 刷新 last_checked_at 3 行`
- 直查生产库：`source='retail'` 且 `last_checked_at >= 17:00` 的**恰好那 3 行**（徐洞分店 `WRKQG100024668/…4424/…4669`，都仍是「等待上传」——平台答的是未上传）；`upload_logs` 那一侧 **0 行**
- `find logs data -user root` → **空**；`logs/api_2026-10-05.jsonl`、`logs/check_bill_status_retail.lock` 属主都是 nginx ✅ 验收项 6

### 挂 cron 时踩到的一处坑（已修，已入档）

`logs/check_bill_status_retail.lock` 是**票 01 期间以 root 跑出来的**（`root:root 0644`）。nginx 身份下 `fopen($file, 'w+')` 打不开 → 脚本走"已有实例在运行"那条分支**打印一句然后 `exit 0`**：表现为 **cron 装了、日志里天天有一行、却从没真跑过**。已 `chown nginx:nginx`，并把"挂 cron 前先看要写的文件（含锁、基线）的属主"写进 CLAUDE.md 的 cron 注。

crontab 改动：先 `crontab -l > /tmp/crontab-backup-2026-10-05-1659.txt`，只在 `check_bill_status` 那条后面插了 3 行注释 + 1 行条目；`diff` 除新增外**逐行无差异**（其它项目那 20 来条一字未动），`crontab -l | grep check_bill_status_retail` 见第 34 行。

### 测试

`tests/retail_platform_check_test.php` **25 → 31 条**（新增门卫账本 6 条：答复过的进、异常的不进、没轮到的不进、dry-run 为空、已上传的两本账都有、同名单号按企业各记各的）。两处变异各自变红：账本收进 `error` 那条 → 2 条红；账本丢企业维度（`$checkedByCompany[$djbh]`）→ 3 条红。14 个离线测试脚本逐个退出码 0。

### code-review 收口（2026-10-05，两轴并行）

**两轴各自的结论**：Standards——**无硬违规**，四条判断题；Spec——(a) 未做的**无**、(b) 范围蔓延**无**，三条要处置（下面 1、3、4）。

- ✅ **修：那句"与采集的写库窗口不重叠"是错的**（Spec 轴）。一趟约 12 分钟，`:20` 那轮跑到 `:3x`，**正压 fetch_bills 的 `:30` 轮**。真正的写只有收尾那**一次亚秒级事务**（批量 touch），撞锁由双方 `busyTimeout(30s)` 兜底——与 `check_bill_status` 等既有脚本同一处境，可以接受，但不能说成不重叠。CLAUDE.md 的 cron 表与本节都已订正（条目本身照票面装的，是理由句错）
- ✅ **修：`touchChecked()` 的语句形状抽成纯函数可断言**（两轴都指到：验收项说"批量 touch 的形状有自包含断言"，此前那 6 条只断了**账本** `checked_by_company`，真写下 SQL 的那层没断言）。新增 `RetailExternalUploads::touchStatements(键, 时间)`（纯函数）+ `tests/retail_external_uploads_test.php` **用例 10 共 8 条**：每句都带 `company = ?`、两张表各一句、参数形状 `[时间, 企业名, …单号]`、乙店单独成句、501 个单号切成 2 句且每句参数 **≤ 999**（本机 SQLite 3.7.17 上限）。变异实测：SQL 丢掉 `company = ?` → 4 条红；`IN_CHUNK_SIZE` 改 1000 → 参数上限那条红（1002 个）。真落库仍由副本实测兜着（票面原话）
- ✅ **修：取差计数的重复 foreach 收成 `pendingCount()`**（Standards 轴：`closeLoop()` 与脚本各一份）——`pendingCount()` 与 `pendingDjbhList()` 同处，三处要报"N 条待办"的地方共用一份
- ✅ **修：`touchChecked()` 补了"为什么这里可以整轮一个事务"**（Standards 轴：与 `Database::transaction()` 的"别开太大"字面不符）——那条告诫针对零售快照那种"几万条 INSERT 一个大事务"，这里是"企业数 × 分块数 × 2"条语句、亚秒级
- ✅ **修：证据表加了时点标注**（Spec 轴 (d)：直查值对不上副本现状）。表里那些数是"场景 A 第一轮跑完那一刻"的，副本随后被括号变异、真平台冒烟与场景重布置复用（含一枚 4,546 行的戳与收尾的清理），此刻数字不同——已写明，并说清哪一步是脚本、哪一步是人工
- ⏸️ **不修：门卫常量三份副本**（`check_bill_status` / `check_failed_logs` / 本脚本各一份 `CHECK_INTERVAL_MINUTES = 30`）。票面明写"照批发"；要收成一处得三条脚本一起动（其中两条是批发链路），不是本票该顺手改的
- ⏸️ **不修：`touchChecked()` 直接对真库的断言**——`Database::getInstance()` 把库路径写死在类里，给它开接缝是本票之外的一层抽象；票面也写明 SQL 靠副本实测。可测的那半已按上面第 2 条补上

### ⚠️ 范围外的发现：畸形响应会被判成"平台上有"（本票不修，记在这里）

复核时确认的一条既有缺陷（票 01 引入、本票只是把它的暴露面放大了——从"手动跑"变成一天 28 轮无人值守）：

1. `TopClient` 在"HTTP 响应不是合法 XML/JSON"时返回 `stdClass{code: 0, msg: 'HTTP_RESPONSE_NOT_WELL_FORMED'}`（并写 `top_comm_err_*.log`）；
2. `ApiClient::execute()` 的判据是 `isset($resp->code) && $resp->code != 0` → **`0` 不算错误**，这一格走 `success=true, error=''`；
3. 于是 `isBillFound(['code'=>0,'msg'=>'HTTP_RESPONSE_NOT_WELL_FORMED'])` 返回 **`true`**（实测）——脚本会把待办翻成「已处理 + 上传成功」并追加一条「外部上传」记录，**一条凭空的成功记录**。批发侧的 `searchBillDetail` 同理（`check_bill_status` 会把任务标成已处理 + 写 `upload_logs` 上传成功）。

触发条件是**网关回了 HTTP 200 但正文不是 XML**（反代的错误页、机房维护页、截断的响应）。建议的修法只有一处：`ApiClient::execute()` 把 `code == 0 && msg === 'HTTP_RESPONSE_NOT_WELL_FORMED'` 判成错误（`is_network_error = true`，走既有重试通道）。**本票不修**——`execute()` 是所有链路（含申报）共用，用户明确要求本票不碰申报路径；留给下一票。
