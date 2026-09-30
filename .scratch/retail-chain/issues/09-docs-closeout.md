# 09: 文档收尾复核

- Type: task
- Status: done（2026-09-30）
- Blocked by: 02, 03, 04, 05, 06, 07, 08（均已 done）
- 关联：`CLAUDE.md` 的"文档同步规则"

## 要交付的行为

仓库文档与代码现状一致。

**注意**：本票**不是**"文档都堆在这里写"。每个切片的文档随该切片在**同一次提交**里更新（仓库硬规则）；
本票只做**全局视图**与**遗漏复核**——单个切片看不到的那些地方。

## 范围

- `CLAUDE.md`：
  - 架构图与文件树：`config/enterprises*`、`scripts/fetch_bills_retail.php`、零售相关的 api / views 文件、零售 SDK 类并入后的说明
  - Web 路由表与 api 端点清单
  - SQLite 表结构：`company` / `credential` 两列、`ent_list` 的唯一约束、`待补传` 状态值
  - 核心数据流：新增零售采集链路与补传链路（与批发链路并列，写清"零售由外部系统上传、本项目只采集+补传"）
  - cron 时间表：零售采集一行
  - 常用命令：零售采集、补传相关的调试/验证命令
- `CONTEXT.md`：术语与流程章节的复核。"所属企业""凭据""待补传""未识别""待配凭据"已由工单 01 那轮写入，本票核对与代码一致（尤其状态机图与来源值清单）
- `docs/adr/`：0006 / 0007 / 0008 已就位；工单 05 的入参映射 ADR 已就位——核对索引与交叉引用
- `.scratch/retail-chain/spec.md`：把"实施状态"表回填为实际完成情况，并说明「工单 02–09 已由横向切层整体替换为垂直切片，旧的留档在 `issues/_superseded/`」
- `.scratch/retail-chain/issues/`：各票 `Status` 逐条核对
- **双推 Gitee（优先）与 GitHub**

## 验收

- [x] 文档里提到的每个文件、每条命令、每个状态值、每个来源值都能在代码里找到对应（逐个 grep 一遍）
- [x] spec 的"实施状态"表与实际提交一致
- [ ] 两个远程都已推送 —— **本票提交时尚未推送**：按仓库流程，推送是收尾的**最后一步**（本地提交 → `/code-review` 收口 → 双推），故此项由推送动作本身兑现，不在提交那一刻预先勾上

## 验证证据（2026-09-30）

### 怎么核的

**全程静态核对，没有运行 `CLAUDE.md` 里的任何命令**——那些脚本要么碰 SQL Server、要么向码上放心平台发起（批量、不可逆的）申报，
不该为了让文档自洽而执行。唯一的例外是 `crontab -l`（只读；`CLAUDE.md` 的 cron 表自己要求"改动本表前先核对"）。

手段只有三种：文件/命令的存在性遍历、逐项 `grep`、`git ls-files` / `git log` 查入库与历史。

### 四类核对结果（票面验收第 1 条）

| 类别 | 怎么静的 | 结果 |
|---|---|---|
| **文件** | 抽出 `CLAUDE.md`/`CONTEXT.md` 里全部 `*.php` 路径逐个 `test -e`；再按目录双向对照 | 全部命中。`src/` 16 个类 + `api/` 15 个 + `views/` 7 个，与文件树**无多无少**；`db.php` 是文档明写"不在仓库内"的，属预期而非缺口；`top_sdk/top/request/` 内 2 个 lsyd 类在位（该目录共 36 个类） |
| **命令** | `CLAUDE.md`「常用命令」里的 10 条 `php scripts/*.php` 与 6 条 `php tests/*.php` 逐个 `ls` | 全部命中（只核"文件存在"，**未核"能跑通"**，见"未验到的部分"） |
| **状态值** | `task_status` / `request_status` / `response_status` 的每个取值在 `src/` `scripts/` 里反查 | `等待上传`/`待补传`/`已处理` 三值全命中；`请求成功`/`请求失败` 全命中；`上传成功`/`单据重复`/`上传失败`/`信息不存在`/`往来单位缺失`/`未确定`/`数量不符` 七值全命中 |
| **来源值** | `source` 的每个取值在两个表里反查 | `upload_logs`：`cron`/`manual`/`batch_check`/`batch_retry`/`quantity_check`/`retail_retry` 全命中；`upload_tasks`：`retail`/`cron`/`manual`/`batch_check`/`batch_retry` 全命中 |

### 顺带核过的（不在四类里，但同属"文档断言 ↔ 代码事实"）

- **cron 表**（`crontab -l`）：`fetch_bills` `0,30 0,1,2,3,8-23`、`fetch_bills_retail` `5,35 0,1,2,3,8-23`、`check_bill_status` `*/30 8-20`、`check_failed_logs` `40 20`、`cleanup_logs` `0 3` 五条在跑，`check_quantity` **未调度**——与文档的表逐条一致
- **取数白名单**：`upload_pending` / `check_bill_status` / `check_failed_logs` / `check_quantity` 四处 SQL 都带 `company = ?`；`fetch_bills_retail` 全程只 `SELECT`（不写源库）
- **ADR**：0001–0011 编号连续、无缺号；全部 `ADR NNNN` 交叉引用都能落到具体文件，**无悬空引用**
- **测试用例 16** 与 `CLAUDE.md` 对它的描述逐字相符（2000 码的 104 在上限 10000 下不拆、4000 码的 321 在 3500 下拆 3500+500）
- **企业枚举**：`config/enterprises.php` 实为 1 批发 + 15 门店 = 16 家 → 下拉 17 项（+`未识别`）✓；`enterprises.local.php` 已配 **5** 套门店凭据 ✓ —— 与 spec「外部依赖」表的"已有 5 家"一致，**该项文档无需改，复核通过**
- **两个模块的公开口**与文档描述一致（`Enterprise::route/claim/credential/selectableNames/defaultCredentialKey/credentialConfigured/wholesaleSubject`、`RetailRetransmit::retransmit/rejectedProgress` 等均在位）
- **已知欠账仍在**：`views/dashboard.php` 第 5 份 NOT EXISTS 拷贝（既无 `company` 限定也无 `quantity_check` 豁免）、`config/sql.php` 的 `$get_up_task_retail` 残留、根目录三个"勿运行"旧脚本——均与本轮文档描述相符，未动
- `issues/_superseded/` 各票 Status 一律 `ready-for-agent` 是**归档保真**（该目录 README 明写"内容未删改"），不是漏改

### 发现与处置

① **`scripts/` 有三个文件不在文件树里** → 补进树：`migrate_status_fields.php` / `fix_response_status.php` 标"一次性、已执行"，`cron_handle.php` 是 **0 字节空文件、全仓无引用**，标"归档残留"。三个文件本身**一个都没动**。

② **`public/index.php:37` 有 `page=asset` 分支，Web 路由表没列** → 补一行；并修正表下那句"除 login 和 api 外需要登录"（asset 在登录校验**之前**）。

③ **`CONTEXT.md` 的"批量查询上传状态"一节写的是 ADR 0003 之前的形态**——三处都已不符：来源早已**拆成两个脚本**（`check_bill_status` / `check_failed_logs`，各带独立 flock），而该节写"双源合并"；门卫阈值是 `CHECK_INTERVAL_MINUTES = 30` 且 crontab 是 `*/30`，而该节写"建议 cron 8-20 点每 5 分钟一次"；该节写"已确认在平台跳过**不** touch"，而 `scripts/check_bill_status.php:104` 的跳过分支**确实会** touch（`check_failed_logs.php:122` 的对应分支才是 `continue` 不 touch）。→ **据实重写该节**（拆成两个子条目 + 把 touch 规则写明）。

④ **`CONTEXT.md` 写 `upload_pending.php` "读取所有等待上传任务"** → 改为"只读取**批发主体的**"（代码是 `company` 白名单）。

⑤ **`CONTEXT.md` 全篇没有零售采集流程**（`fetch_bills_retail.php` 一次都没出现）→ 补一条流程。

⑥ **`CONTEXT.md` 的门店命名"形如「<城市> <品牌> 药房有限公司 <门店> 分店」"与实际不符**（实为「大源堂智慧药房（河源）有限公司新江分店」）→ 据实改写。

⑦ **`CONTEXT.md` 的 `response_status` 清单缺 `数量不符`**（`CLAUDE.md` 有）→ 补上并注明"仅 `upload_logs`，quantity_check 专用"。

⑧ **ADR 0006–0011 头部缺 `- 状态：已接受（日期）`**（0001–0005 都有）→ 六份各补一行，日期取各自**首次提交日**（0006–0008 为 2026-09-29，0009–0011 为 2026-09-30）。

⑨ **失败记录页的 `quantity_check` 来源没有标签、来源下拉里也没这一项** → 该页"数量不符"这类行（该页唯一的告警出口）在"来源"列**直出机器值**、且按来源筛不出来，违反该页注释自陈的约定。**判定为代码侧缺口，本票不改代码**，只在 `CLAUDE.md` 的 `api/failed.php` 条目里记为已知缺口。

### 本票未做（有意）

- **没改任何代码**：`src/`、`scripts/`、`config/` 一字未动，也没写 `data/msfx.db`
- **第 ⑨ 项只记欠账、不修**（票面明令：收尾票不顺手改生产链路）
- **`docs/python-client/engineering-prompt.md` 经查已在版本控制中**（`ebe1f90`，"没入库"的前提不成立），故 `CLAUDE.md:20` 的引用**无需改动**
- ADR 0006–0011 的 H1 **没有 `ADR NNNN:` 前缀**（0001–0005 有）——同类格式差异，但本轮点的是"状态行"，故未动，留作后续
- `views/dashboard.php` 第 5 份筛选拷贝、`upload_tasks` 的 `(company, djbh)` 重复行、`config/sql.php` 调试残留、根目录三个旧脚本：**已入档的已知欠账**，本票不动

### 未验到的部分（如实记录）

- **四类核对是"能在代码里找到对应"，不是"代码行为与文档描述逐句相符"**。文档里大量**行为性断言**（"上传守卫整批拒绝""零售补传三关 fail-closed""导出行数与页面一致"）是各切片自己的验收验过的；本票只抽查了其中少数几条（`last_checked_at` touch 规则、四个脚本的白名单 SQL、测试用例 16），**没有逐句复验**
- **页面渲染只做了代码级 grep**（类名/文案在位），**没有打开浏览器看**。"`未识别` 标红只在任务页""补传按钮禁用态""来源下拉选项"这些结论都是读代码得出的
- **未运行任何脚本**，所以"命令能跑通"不在本票证据范围内——只核了"命令对应的文件存在"
- **`CONTEXT.md` 只复核了术语与流程两章**；其余章节（如"日志链"的小节表述）未逐句复核，发现的分歧仅限上文所记
- `src/` 与 `scripts/` 中未被本票 grep 触达的代码路径，其文档描述**未复核**

## Comments

### 2026-09-30 开工前的三处判断（用户拍板）

- `scripts/` 三个游离文件：**补文件树说明，三个都不动**（不删 0 字节的 `cron_handle.php`——那是代码改动）
- ADR 0006–0011：**补齐状态行**
- 失败记录页 `quantity_check` 来源标签/下拉缺失：**只记欠账、代码不动**
