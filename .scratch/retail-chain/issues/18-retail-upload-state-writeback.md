# 18: 零售门店上传成功后回写源库 update_state

- Type: task
- Status: done（2026-10-02）
- Blocked by: 无（17 已完成）
- 关联：`docs/adr/0007`（本票**推翻**它的一条：从"从不回写"改为"上传成功后回写"）、
  `docs/adr/0016`（本票落下的决定）、`src/RetailRetransmit.php`（唯一落点）、
  `scripts/fetch_bills_retail.php`（读同一张表做采集过滤）、`scripts/backfill_update_state.php`（存量回填）

## 背景

零售单据由外部系统上传，本项目只做"可见 + 人工补传"（ADR 0007）。外部系统用
`dyt.bs_msfx.dbo.update_state`（`bill_code` + `bill_state` 两列）判断"这条单传过了没有"——
本项目采集侧的 `NOT EXISTS(update_state)` 过滤（2026-09-30 测试阶段口径）读的就是它。

但**人工补传成功后本项目不回写它**：外部系统不知道这单已经上了平台，会再传一次；
本项目采集侧又靠 `upload_logs` 判重不入队——两边认知不一致。

用户 2026-10-02 要求：**零售门店上传成功后，插入 `(bill_code, 1)`**。

## 探测结论（2026-10-02，只读 SELECT + 试写）

| 事实 | 结论 |
|---|---|
| 列结构 | `bill_code` varchar(200) 可空、`bill_state` varchar(200) 可空，**仅两列** |
| 约束 | **无主键、无唯一约束**（只有一个非唯一非聚集索引 `(bill_code, bill_state)`）——重复插入不报错 |
| 存量 | 67,842 行，`bill_state` **全为 `'1'`**；**已有同号多行**（`XLSC5700100153` 出现 3 次，外部系统自己也重复写） |
| 显式事务 | **写不了**：`BEGIN TRAN` 里写链接服务器要起分布式事务，MSDTC 被禁（`SQLNCLI10` 报"无法启动分布式事务"） |
| 单条自动提交 INSERT | **可行**（真写一条 `__PROBE_` 假单号，受影响 1 行，随后已 DELETE，表已复原） |
| `INSERT ... SELECT ... WHERE NOT EXISTS` | **被接受**（同号第二次受影响 0 行）——幂等写法可用，且不需要本地事务 |

**两条硬约束由此定下**：① 写操作**绝不能包在本地事务里**（必然失败）；② 幂等靠
`INSERT ... SELECT ... WHERE NOT EXISTS`，不依赖表约束（表根本没有约束）。

## 要交付的行为

| 情形 | 行为 |
|---|---|
| 上传结果 = `上传成功` 或 `单据重复` | 写 `(原始单号, '1')` |
| 上传失败 / 未确定 / 网络失败重试耗尽 | **不写** |
| 拆单（零售实测不触发）且**全部子单成功** | 写**原始单号**（`_N` 后缀剥掉，即 `task['djbh']`） |
| 拆单且任一子单失败 | **不写**——平台上只有半截，外部系统仍须处理它 |
| `update_state` 里已有该单号 | 跳过（幂等，不产生第二行） |
| 批发单据 | **不写**（本票只管零售链路；`RetailRetransmit` 本身也只处理零售） |
| 写失败（源库不可用等） | **不影响上传结果**（上传已不可逆）：记一条 JSONL 警告，不抛异常 |

**为什么"单据重复"也写**：它和"上传成功"对 `update_state` 是同一个事实——**单据已在平台上**，
外部系统都不该再传它。这与 `RetailRetransmit` 内部的 success 判据一致（也是这两个值），
与已上传页/失败页的口径一致。

**为什么写失败不能抛异常**：抛出去会让端点报出与实际不符的"被拒"进度行，而任务行的状态
早已翻成"已处理/上传成功"——上传本身不可逆，回写只是尽力而为的后续动作。

## 决策

| 决策点 | 选择 | 理由 |
|---|---|---|
| 落点 | `App\RetailRetransmit`（chunks 循环之后） | 它是"上传一条门店单据"的唯一实现，三个入口（单条补传 / 批量重传的零售那批 / 门店手工建单）自动全覆盖；放在端点里就要写三份 |
| 新类 `App\UpdateStateWriter` | 单独一个类，不塞进 `RetailRetransmit` | 它连的是 SQL Server（本链路其余部分只连 SQLite 与平台）；连接按需惰性建立——三关被拒的单不该先白连一次源库 |
| 幂等 | `INSERT ... SELECT ?, ? WHERE NOT EXISTS (SELECT 1 FROM ... WHERE bill_code = ?)` | 探测已验证被链接服务器接受；表无约束，靠 SQL 自身保证幂等。**不先查后插**：两步之间有竞态，且多一次往返 |
| `bill_state` 取值 | 字符串 `'1'`（与表内存量一致） | 列是 varchar，存量 67,842 行全是 `'1'`；用户给的格式也是 1 |
| 写失败出口 | JSONL 警告（`type=update_state_write_failed`） | 与采集脚本的 `name_unmatched` 同款出口。**不进 `upload_logs`**——那是上传结果日志，写进去会在失败记录页冒出既非上传也非失败的记录，污染唯一告警出口（ADR 0007 已定的口径） |
| 存量回填 | `scripts/backfill_update_state.php` 一次性脚本（幂等、可重跑） | 8 个历史单号（新江 5 / 宝源 2 / 埔前立信 1）已补传成功但表里没有；不回填则外部系统仍会重传它们。判据从 `upload_logs` 取（零售企业 + `上传成功`/`单据重复`），不另录清单 |

## 范围

### 新增

- `src/UpdateStateWriter.php`：`mark(string $billCode): bool`。
  构造接受可选 `?array $config`（同 `TaskFetcher` 的风格），**不在构造时连库**——首次 `mark()` 才建
  `SqlSrvHelper` 并缓存；失败记 JSONL 警告、返回 false，不抛。
- `scripts/backfill_update_state.php`：一次性回填，幂等可重跑，输出"写入 N / 跳过 M"。

### 连带

- `src/RetailRetransmit.php`：chunks 循环后按 `$failed === 0 && $success > 0` 判定并调 `mark()`；
  writer 实例惰性缓存在属性上（批量补传逐条调用时只连一次源库）
- `docs/adr/0016-retail-upload-state-writeback.md`：新增（决定 + 被排除的方案 + 与 ADR 0007 的关系）
- `docs/adr/0007`：加修订注（"从不回写"一条被本票推翻）
- `CLAUDE.md`：文件树（新类 + 新脚本 + `fetch_bills_retail.php` 的 update_state 说法）、
  "零售单据上传"章节加回写一段、"零售单据采集"章节的 update_state 从"只读"改口径、常用命令加回填脚本
- `CONTEXT.md`：`update_state` 词条（"本项目对源库只读、从不回写" → 改）
- `spec.md`：票表加第 18 行；Out of Scope 那两条划掉并注明

### 明确不做

- **不改批发链路**（`UploadService` 一行不碰）——批发的单进的是 `hyyy_zyscm` 那条链路，与
  `dyt` 源库的 update_state 无关
- **不做本地事务包裹**（MSDTC 被禁，包了就必失败）
- **不回填批发单**（97 条河药手工上传日志与本表无关）
- **不新增测试接缝**：写入是纯 IO（连库 + 一条 SQL），无纯函数可测；既有测试保持全绿即可，
  行为验收见下

## 验收

1. ✅ 七个自包含测试脚本全绿（`search_bill` / `singlerelation` 两个探针要传单号且会调平台，未跑）
2. ✅ **真实补传一条零售单**：`XLSK5600100185001`（埔前立信分店 `321`、`2026-09-28`、1 个码）→
   平台返回 `上传成功`（`SUCCESS` + `response_success=true`），`dyt.bs_msfx.dbo.update_state`
   从 **0 行变 1 行**
3. ✅ **同一条再补传一次**：平台返回 `单据重复`（原话"该单据号已存在（上传时间：2026-10-02 09:11:31）"
   ——正是第 2 条那次的时间）→ 表里**仍只有一行**（`WHERE NOT EXISTS` 幂等生效），进度按业务结果算成功
4. ✅ **写失败不影响上传**（副本 `/tmp/verify18` + 离线桩端到端）：上传成功但源库连不上时，
   `retransmit` 照常返回 `success=1`、任务行照常翻"已处理/上传成功"，JSONL 记一条
   `type=update_state_write_failed`（带真实报错），且**没有**进 `upload_logs`（副本库复查 0 条）
5. ✅ **存量回填**：`php scripts/backfill_update_state.php` → 写入 8 / 跳过 0；**重跑 → 写入 0 / 跳过 8**；
   生产表内 8 个单号各 1 行（无重复行）
6. ✅ 文档同步：`CLAUDE.md` / `CONTEXT.md` / `docs/adr/0007`（修订注二）/ `docs/adr/0016` / `spec.md`
   （票表 + Out of Scope + User Story 15）与代码同一次提交

## 验证证据

### 离线端到端（副本 + 离线桩，不碰生产库、不碰平台）

`/tmp/verify18`（项目副本，网关地址改指本地桩 `127.0.0.1:8299`；`UpdateStateWriter` 首轮用同签名桩
记录调用、次轮换回真实现并把源库地址指错以验失败路径），四场景 **0 失败**：

| 场景 | retransmit 返回 | 任务行 | mark 调用 |
|---|---|---|---|
| 桩返回上传成功 | `total=1 success=1 failed=0` | 已处理 / 上传成功 | **是**（原始单号） |
| 桩返回单据重复 | `total=1 success=1 failed=0` | 已处理 / 单据重复 | **是** |
| 桩返回上传失败 | `total=1 success=0 failed=1` | 已处理 / 上传失败 | **否** |
| 上传成功但回写失败 | `total=1 success=1 failed=0` | 已处理 / 上传成功 | 是（记 JSONL 警告） |

顺带量到一处并已修：`SqlSrvHelper` 默认登录超时 30s，源库不可达时**每条成功单**都要卡一次——
`UpdateStateWriter` 改成 5s（源库在内网，连不上就是不可用），副本实测 4 条场景从 ~90s 降到 ~17s。

### 真实补传（生产，用户 2026-10-02 授权）

```
补传 XLSK5600100185001 → 上传成功（request_id 16kpslr37nfg1）→ update_state 0 → 1 行
再传同一条           → 单据重复（"该单据号已存在（上传时间：2026-10-02 09:11:31）"）→ 仍 1 行
```

日志侧：该单在 `logs/api_2026-10-02.jsonl` 只有两条上传记录（`source=retail_retry`），
**没有** `update_state_write_failed`——回写一次成功、一次幂等跳过。

### 存量回填（生产，2026-10-02 已执行）

首次：`待回填 8 个单号 → 写入 8, 已存在跳过 0, 失败 0`；重跑：`写入 0, 已存在跳过 8, 失败 0`；
表内复查 8 个单号**各 1 行**。探测期真写验证用的 `__PROBE_` 假单号已 DELETE，表内无残留。

## Comments

### 2026-10-02 开工前问过用户的三件事

1. **"单据重复"要不要也写** → 写（推荐口径）：与"上传成功"是同一个事实。
2. **探测授权** → 允许只读 SELECT + 试写；事务回滚被 MSDTC 拦下后，用户追加授权"真写一条带标记的
   测试行、验证后立即删掉"（已执行，表已复原）。
3. **门店手工建单也写吗** → 也写：语义一致，三个入口一处落点全覆盖。
4. **8 个历史单号回填吗** → 回填（推荐口径）：做成可重跑的一次性脚本。
