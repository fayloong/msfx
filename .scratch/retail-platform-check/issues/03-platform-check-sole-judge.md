# 03: 暂停源库状态表判据，统一以平台核查为准（暂时）

**What to build:** `scripts/fetch_bills_retail.php` **不再以「单号在不在 `dyt.bs_msfx.dbo.update_state` 里」判断这张单传没传**——采集不分流（单据一律建「等待上传」任务）、状态闭环停跑、计数门卫退成只数当日总数。门店单「是否已上传」的判据**统一到 `check_bill_status_retail.php`**（平台核查：拿各门店自己的凭据问平台）。**回写保留**（补传成功后仍写 `update_state`，那是给外部系统看的，不是本地判据）。

**Blocked by:** 02（已 done）

**Status:** done（2026-10-05）

---

## 为什么（用户 2026-10-05 拍板）

外部系统近期不稳定（2026-10-02 的源库探测已确认它在故障中：当日 94 张单只有 3 张被标已上传，而两年窗口整体约 65% 有标记）。**它写进状态表的痕迹不再是可信证据**：

- **分流**（票 02）拿它决定"建不建任务"——它写一条假状态，那张单就**从头到尾不进补传队列**，只在已上传页上留一条假的「外部上传」记录。**这是静默丢单**，是本票要堵的洞。
- **状态闭环**（票 03）拿它决定"翻不翻正本地待办"——同一张假状态，单子会**先入队、再被翻掉**，绕一圈还是消失。**只拆分流不拆闭环等于没改**。

平台核查（票 01/02）问的是**平台自己**，天然按企业隔离（带 `ref_ent_id`），是这堆判据里唯一不依赖外部系统行为的那个。

## 决策（开工前与用户逐条确认，2026-10-05）

| 决策点 | 选择 | 理由与代价 |
|---|---|---|
| 状态闭环 | **一并停用** | 与分流同一个判据、同一种失效方式（见上）。**代价**：10 家未配凭据的门店 + `未识别` 的单平台核查够不到（`groupByCompany()` 整组跳过），会**一直挂在「等待上传」**——当前这类待办约 3,000 条。这是明知故犯：宁可让它们堆在明面上，也不要被一张假状态悄悄翻掉 |
| `--all` 全量快照 | **保留入口、退成只统计** | 判据没了之后它写不出任何记录（全部落 `ACTION_COUNT_ONLY`）。留着一个**只读侦察**用途：打印"窗口内 N 单未建任务"，欠账仍是个可查的数。它从此**一个字节都不写**（含认领告警的 JSONL） |
| 回写 `update_state` | **保留** | 写出去是告诉外部系统"别再传"，与本地判据无关。停掉它会让外部系统重复上传本项目已补传成功的单 |
| 计数门卫 | **退成一个数（当日总数）** | 后两个数（已上传/未上传）读的正是那张表：外部系统写得乱时它们一直在变，门卫会一直判"有变化"而丢掉省轮次的作用。基线文件形状随之变 `{date, total}` |

## 要做的事

### 1. 采集不再读状态表（`scripts/fetch_bills_retail.php`）

- SQL 去掉那个 `case when exists(...) as uploaded` 标志列（连同它上面那段"必须 EXISTS 不能 JOIN"的注释——没有它就不存在那个坑）。**整条 SQL 从此不碰 `update_state`**。
- `flushRetailBatch()` 去掉 `ACTION_RECORD` 落库分支，去掉 `uploaded` / `unuploaded` 两个事实计数。
- 输出句跟着改：日常是"新增任务 N 条, 跳过 M 条"；`--all` 是"窗口内 N 单（其中 M 单本地已有痕迹），其余 K 单未建任务——**本口径只统计、不落库**"。

### 2. `App\RetailExternalUploads`

- `decide()` **去掉 `$uploaded` 参数与 RECORD 分支**——签名变成 `decide(bool $hasTask, bool $hasSuccess, bool $buildTasks = true)`；`ACTION_RECORD` 常量与 `tally()` 的 `records` 一格随之删除（没有生产者，留着就是输出里一个永远为 0 的数）。`record()` 一并删除（调用方是那个被删的分支；`buildRecord()` 留着——平台核查的 `applyActions()` 走它）。
- **`closeLoop()` 保留但停接**（照 `RetailBatchUpload` "能力已备、暂不启用"的先例）：本票只摘掉 `fetch_bills_retail.php` 里那次调用，方法与它的 `$source` 参数一个字不动，docblock 写明"2026-10-05 起暂时不接线 + 为什么 + 恢复时接哪一行"。`closureActions()` / `applyActions()` **仍在用**（平台核查走它们），一行不改。
- 类注释重写：判据那段（"判据只有一处：源库状态表"）换成"判据统一在平台核查（ADR 0018），状态表只剩写侧"。

### 3. 计数门卫（`App\RetailCollectionGate`）

- `counts()` 的 SQL 退成一句话：`select count(*) as total from (select distinct bill_code from zsm_ls where bill_type in (…) and bill_time >= ? and bill_time = ?) ls`——**不再 JOIN 状态表**（去重派生表那条坑随之消失，但保留注释说明它为什么曾经在）。
- `unchanged()` 只比 `total` + 日期；`read()` / `write()` 的基线形状是 `{date, total}`。
- **旧形状拒收**：基线里若还带着 `uploaded` / `unuploaded`，判为旧版基线 → 视为无基线、照常采一轮。那句"三个数"的日志话术全部改掉。

### 4. 判据的唯一出口

平台核查**一行不改**（它本来就是问平台的）。它是本票之后唯一能翻正门店单的路径——`check_bill_status_retail.php` 的 cron 时段（`20,50 8-21 * * *`，只跑白天）与逐单 30 分钟门卫照旧。

## 验收项

- [x] 采集 SQL 里再无 `update_state`（`grep` 只剩两处**注释**在说明它为什么被去掉）
- [x] 日常采集：同一日期连跑两次，第一次建任务、第二次全跳过（幂等）；`--dry-run` 报的"新增任务 N 条"与真跑落库行数逐字相等（生产实测：预演 9 条 → 那一轮 cron 落了 9 条）
- [x] `--all`：**跑完 `upload_tasks` / `upload_logs` / JSONL 一处都没多**（含认领告警的 JSONL），打印的欠账数与本地库对得上
- [x] 门卫：连跑两次，第二次打印"总数 140，与基线一致，跳过本轮采集"；基线文件落盘形状恰为 `{date, total}`
- [x] 旧形状基线（带 `uploaded`/`unuploaded`）→ 判为无基线、照常采集并覆盖（**生产上真撞上了这一次转换**）
- [x] 状态闭环不再跑（输出里没有"状态闭环"那行）
- [x] 平台核查的翻正落库不受影响：副本上把"平台说有"直接喂进 `closureActions()` + `applyActions()`，任务行翻「已处理」+「上传成功」、`request_status` 保持 NULL、追加记录 `source=retail_external`/`task_id=0`——与票 02 逐项一致
- [x] 回写仍在：`RetailRetransmit` 里 `UpdateStateWriter` 的调用点一个字未动
- [x] 全部离线测试逐个退出码 0（14 个）
- [x] 文档：新 ADR 0018（含**恢复路径**）、CLAUDE.md 的采集/门卫/cron/常用命令/文件树、CONTEXT.md 的四个词条、两个 spec 与 ADR 0007/0016/0017 的口径订正

## 前提与坑

- **本票是"暂时"的**：ADR 0018 必须写明恢复路径（哪几处、按什么顺序加回去），否则一个月后没人知道怎么退回来
- 写生产库 `data/msfx.db`（建任务）：先 `.backup`（`sqlite3 data/msfx.db ".backup '/root/msfx-backup-$(date +%F-%H%M).db'"`，备份别放仓库）
- 脚本一律**以 nginx 身份**跑（`su -s /bin/bash nginx -c '…'`），跑完 `find logs data -user root` 复查
- 本票**不调平台**（采集不调、门卫不调；平台核查的验证在副本上做，用 `--limit` 或 `--dry-run`）
- **历史欠账不在本票范围**：此前按旧判据写下的 47,826 条 `retail_external` 记录（`response` 里 `judged_by` 是那张表）**不会被重新核查**——它们在本地已有成功记录，进不了 `pendingItems()`。要重验得另开一票（可按 `judged_by` 筛出那批、分批走平台核查）

## Comments

### 实现（每处与票面一致）

| 落点 | 做法 |
|---|---|
| `RetailExternalUploads::decide()` | 去掉 `$uploaded` 参数与 RECORD 分支；`ACTION_RECORD` 常量、`record()`、`tally()` 的 `records` 一格一并删除（它们只服务那半分流）。测试加了条**决策护栏**：`ACTION_RECORD` 一旦被加回来就变红 |
| `buildRecord(单据, reason, judged_by)` | 后两个参数**改成必传**（原来的默认值是"源库状态表判的"那句老话术，留着就是让每条记录看起来都像同一个来源）。调用方只有平台核查一处，它本来就显式传 |
| `closeLoop()` | **保留、不接线**（照 `RetailBatchUpload`"能力已备、暂不启用"的先例）：docblock 写明为什么停、恢复时接哪一行。`closureActions()` / `applyActions()` 一字未动——平台核查走的就是它们 |
| `scripts/fetch_bills_retail.php` | SQL 去掉 EXISTS 列；`flushRetailBatch()` 去掉 RECORD 落库分支与 `uploaded`/`unuploaded` 计数；新增 `$readOnly`（`--dry-run` 或 `--all`）控制"一个字节都不写"；`--all` 退成只统计。**参数 `$dryRun` 单列**——它只管进度行的标签（两者在 `--all` 下不同） |
| `RetailCollectionGate` | 只数 `total`：SQL 不再 JOIN 状态表（去重派生表保留）；`unchanged`/`read`/`write` 随形状改；**带旧键的基线单列一条"旧版形状"判非法**（本类一贯的立场：形状对不上就整份作废，宁可多采一轮） |
| 一处顺带 | `--all` 的输出从"预演 vs 真跑"两份收成一份——它现在两种调用行为相同，打印也该相同（原先 `--all --dry-run` 会漏掉欠账那句话） |

### 验证（副本 `/tmp/verify-judge` + 生产实测；源库全程只读、平台一次都没调）

**副本**（`rsync` 一份项目 + 库到 `/tmp/verify-judge`，`chown nginx`）：

```
--dry-run（当天） → 拉取 140 张，将新增任务 9 条 / 28 码，跳过 131 单（0.5 秒 / 2 MB）
真跑             → 新增任务 9 条, 跳过 131 条；任务表 51082 → 51091、日志表**一条没多**
                   门卫：无基线（基线是旧版形状（含 uploaded/unuploaded…）），执行采集
                   基线落盘 {"date":"2026-10-05","total":140}
二跑             → 计数门卫: 总数 140，与基线一致，跳过本轮采集
--all            → 窗口内 54573 单：本地已有痕迹 54563 单，其余 10 单未建任务（欠账）
                   **零写入**：任务/日志/JSONL 行数/基线 四处跑前跑后逐字相同
--all --dry-run  → 与上面同一个输出（收成一份之后）
翻正探针         → 任务行 等待上传 → 已处理 + 上传成功（request_status 仍 NULL）、
                   追加记录 source=retail_external / task_id=0 / request_status=NULL
check_bill_status_retail --dry-run → 待办 4350 条 / 14 家；跳过 9 家（含未识别），将核查 7 条
```

**生产**（先 `.backup` 到 `/root/msfx-backup-2026-10-05-1738.db`，全程 nginx 身份）：

- ⚠️ **17:35 那轮 cron 撞上了开发中的中间态**：当时脚本刚重写完、`flushRetailBatch()` 里还引着作用域外的 `$dryRun`（任何一批落库都会 fatal）。cron 落库 9 条后抛 `TypeError` 退出 1、**基线没写**——恰好现场演示了那两条铁律（**落库幂等** + **基线只在整轮成功后写**）：18 分钟后的手工跑把这 9 条全跳过了，一条没重复。**教训**：这个仓库的脚本是**活的**（cron 每 30 分钟来取），重写整个文件等于把半成品推上生产
- 手工跑（修复版，17:39）→ 拉取 140 张、**新增任务 0 条**、跳过 140 条；日志表 96838 与 `retail_external` 47901 **一字未动**；门卫把旧形状基线判非法 → 采集 → 落 `{"date":"2026-10-05","total":140}`
- 再跑 → `总数 140，与基线一致，跳过本轮采集`
- 17:35 那 9 条任务形状逐列核对（`source=retail`、`task_status=等待上传`、`company` 是认领到的门店、`credential=main`、`321` 行 `to_user_id` 为空而 `104` 行两列都有、`physic_type=3`）
- `find logs data -user root` → **空**

### 测试与辨别力

`retail_external_uploads_test.php`：decide 真值表删到"本地无痕/已有任务行/已有成功记录"三格，tally 四键、`--all` 那组照旧；**新增决策护栏**（`ACTION_RECORD` 不得存在）。`retail_collection_gate_test.php`：一个数、`{date,total}` 形状、**新增用例 12（旧版形状 → 无基线、照采、覆盖）**。

两处变异各跑过：**把 `ACTION_RECORD` 加回来** → 决策护栏变红；**把旧版形状当合法** → 用例 12 五条红。

### 遗留（都不在本票范围）

1. **无凭据的门店真的没人管了**：10 家待配凭据 + `未识别`（副本实测 3,090 条）平台核查够不到，会一直挂在「等待上传」。这是决策表里那条代价的兑现，凭据配齐之日自愈
2. **47,826 条旧记录没复核过**：2026-10-02 快照按状态表写下的 `retail_external` 记录，`judged_by` 是表名——判据已不可信，但它们进不了待办清单。要复核得另开一票（按 `judged_by` 筛）
3. **`upload_pending_retail.php`（备而不用）的队列里现在会混入"外部系统已上传"的单**：取数口径没变（等待上传的门店任务），但那些单不再被分流挡在门外。**真跑之前先跑一轮平台核查**把已上传的翻掉，否则会白申报一批（平台回 `单据重复`，不算错但没必要）
4. **队列会变长**：外部系统已上传的单也先入队、等平台核查翻正（白天最快约半小时，跨夜要等次日 8:20）
