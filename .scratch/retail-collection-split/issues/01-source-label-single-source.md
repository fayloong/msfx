# 01: 来源标签收口（前置重构）

**What to build:** 现在三份「来源」标签表各写各的、键集互不相同，且数量对账（`quantity_check`）三页都没有标签——页面上直出机器值。把取值 → 中文标签 / 徽标色 / 筛选下拉选项收成**一份**，两个日志页的视图与 xlsx 导出共用它；顺带把数量对账的标签补上。交付给运维：导出的「来源」列由机器值变成中文标签，页面上不再有来源直出机器值。

本票是**前置重构**——下一票要加的新来源没地方放，先把这个坑填了。除"导出变中文"与"数量对账显示中文"两处，其余行为逐项不变。

**Blocked by:** None（可立即开始）

**Status:** done（2026-10-02；提交 `dcdf5d7`、收口 `0990829`，gitee 与 origin 均已跟上）

- [x] 新增一份来源标签常量（中文标签 + 徽标色 + 下拉选项），成为该口径的**唯一事实源**；两个日志页视图与导出都从它取（页面输出成 JS map，导出直接用）
  - 落点 `src/LogSource.php`：一份 `MAP`（取值 => [标签, 徽标色]），对外三个访问器 `labels()` / `badges()` / `label()`——两个日志页的下拉直接 foreach `labels()` 渲染，JS map 与导出各取所需
  - **第三份拷贝（上传任务页）一并收口**：标签/徽标改取同一份词表；它的下拉成员仍是原有的五个值、留在视图里（开工前与用户确认，见「决策」）
  - 全仓 grep 带引号的标签字面量（`'定时采集'` / `'零售补传'` / `'批量核查'`…）：只剩 `LogSource.php` 一处（`tests/log_source_test.php` 里那份"既有标签不变"的清单是刻意的独立陈述，不是拷贝）。**不限定的散文/注释里仍有"零售补传"这类说法**（弹窗标题、docblock、CLAUDE.md），那是叙述不是标签表，不算拷贝
- [x] 已上传记录页、失败记录页的「来源」列与来源下拉，对既有全部取值逐项与改动前一致
  - 机械比对（从 `git show HEAD:` 取改动前的两份 JS map 与下拉，与新模块逐项对照）：两页 × 5 个既有取值 × 标签 + 徽标 = **逐项全等**；旧下拉是新下拉的子序列（新取值插在中间，既有取值顺序与内容未变）
- [x] `quantity_check` 在页面上显示中文标签、并能按它筛选（消掉已知缺口）
  - 标签「数量对账」、徽标 `bg-danger`；下拉由 `labels()` 渲染，故天然含它
  - 副本库造一条 `quantity_check` 行 → 真跑 `api/failed.php?source=quantity_check` → `total=1`，该行返回（本页判定口径一字未动）
  - 另按 `source=retail_external` 跑 `api/uploaded.php` → `total=1`（下一票要写的数据，标签已就位）
- [x] 导出文件的「来源」列是中文标签
  - 三类导出真跑（`/tmp` 项目副本 + 副本库 + 伪造会话）：`tasks` → 定时采集 / 零售采集 / 手动上传；`uploaded` → 批量核查 / 批量重传 / 零售补传 / 手动上传；`failed` → 批量重传 / 零售补传
  - 另造三行合成数据钉边界：`quantity_check` → 「数量对账」、`retail_external` → 「外部上传」、**历史空串 → 仍导出空单元格**（回落不吞数据）
- [x] 自包含测试：断言每个已知来源取值都有标签、下拉覆盖全部取值（新增取值时测试变红）
  - `tests/log_source_test.php` **19 条断言**全绿；**辨别力实测**（变异测试）：词表里删掉 `quantity_check` → 2 条变红；把 `RetailRetransmit::SOURCE` 改名 → 1 条变红；MAP 某行少写徽标色 → 1 条变红（这条是 code-review 收口时补的，见下）
  - 「下拉覆盖全部取值」由两条性质保证并断言：已知取值 ⊇ 词表键集 且 词表键集 ⊇ 已知取值（见该测试用例 1）；标签非空（用例 2）
- [x] 既有测试全部跑通
  - 8 个既有离线测试 + 新增 1 个，逐个退出码 0、「全部通过 ✓」；`search_bill` / `singlerelation` 两个探针要传单号且会调平台，**未跑**（本票全程离线）
- [x] CLAUDE.md 里关于"标签表多处拷贝 / `quantity_check` 缺口"的表述同步更新
  - 文件树加 `LogSource.php` 与 `tests/log_source_test.php`；失败记录页那条"已知缺口"改为已消；导出段"来源列导出机器值"改为中文标签；三数据页新增一节写唯一事实源与两处刻意的不统一；常用命令补新测试

## 决策（开工前与用户确认）

| 决策点 | 选择 | 理由 |
|---|---|---|
| 上传任务页那份拷贝收不收 | **收**（标签/徽标取模块，**下拉成员不动**） | 票面点名的消费者只有"两个日志页 + 导出"，但票面第一句抱怨的正是"三份各写各的"。全收则标签文本全仓一份；任务页下拉仍只列任务表会出现的五个值——`retail_retry` / `retail_external` / `quantity_check` 只写日志表，列在那儿只会筛出空结果。用户拍板取此方案 |
| 两个日志页的下拉取全量还是按页裁剪 | **取全量**（`labels()` 8 项） | 票面要求"下拉选项收成一份"、测试要断言"下拉覆盖全部取值"。代价是两页各会多出几个本页筛不出行的取值——上传页的 `零售采集`·`数量对账`、失败页的 `零售采集`·`外部上传`（`retail` 是任务表的来源值，日志里没有；`外部上传` 要等 02 票有数据），换来的是"认得却筛不出来"这类缺口不再有 |
| 未知取值与历史空串怎么显示 | **回落原值**（空串仍空） | 显示机器值总比显示一列空白强：漏配一个标签时，页面/导出上还认得出数据是从哪来的。历史空串（`source` 列上线前的 2,324 行）导出成空单元格，与改动前逐字一致 |
| 两个新取值的徽标色 | `retail_external` 灰（`bg-secondary`）、`quantity_check` 红（`bg-danger`） | 都是给"人眼分辨"用，不表示严重程度。外部上传是本项目**唯一不经过自己上传**的来源，灰与零售补传的黑一眼分得开；数量对账只写"数量不符"的告警行，出现在失败记录页上就是让人去看的。其余六色沿用收口前各自的取值 |
| 要不要 ADR | **不要**（本票不动 `docs/adr/`） | 纯重构、行为仅"导出变中文"一处可见变化，可逆；本轮口径反转的整体决策由 07 票统一记 |
| 测试接缝 | **只有词表一个** | 两个视图与导出不进测试（票面补充约束）；视图侧用"伪造会话 require 真视图 + 真跑 API/导出"验证，不是断言 |

## 验证证据

### 逐项比对：改动前 vs 改动后（`git show HEAD:` 取旧表，不是人工眼校）

```
== src/views/uploaded.php ==        == src/views/failed.php ==
  标签/徽标 cron/manual/batch_check/batch_retry/retail_retry  各 5×2 项：旧=新 ✓
  下拉既有取值全部保留且顺序未变：cron, manual, batch_check, batch_retry, retail_retry
  新下拉（模块全量）：cron, manual, batch_check, batch_retry, retail, retail_retry, retail_external, quantity_check

== src/views/upload_tasks.php ==
  标签/徽标 cron/manual/batch_check/batch_retry/retail        各 5×2 项：旧=新 ✓
  下拉（成员留在视图里）：cron, manual, batch_check, batch_retry, retail —— 与改动前逐项一致

逐项比对：全部一致 ✓
```

### 三个视图真渲染（伪造会话 require 真视图，非静态读代码）

```
uploaded.php / failed.php：下拉 8 项（原有 5 项逐字不变 + 零售采集 / 外部上传 / 数量对账），
                           sourceLabels / sourceBadges 与 LogSource 输出逐字相同
upload_tasks.php：下拉仍是 5 项（成员未变），两份 JS map 同样取自模块
```

### 导出与列表 API 真跑（`/tmp` 项目副本 + 副本库 `sqlite3 .backup`，生产库只读未动）

```
三类导出的「来源」列实际取值（unzip 后逐格统计）：
  tasks    4167 定时采集 / 993 零售采集 / 2 手动上传
  uploaded 4066 批量核查 / 19 批量重传 / 11 零售补传 / 2 手动上传
  failed   5 批量重传 / 1 零售补传
合成三行后的边界（插进副本库，非生产库）：
  quantity_check  →「数量对账」      retail_external →「外部上传」      空串 → 空单元格
按新取值筛选（真跑 API）：
  api/failed.php?source=quantity_check   → total=1（TEST-QC-001 / 数量不符）
  api/uploaded.php?source=retail_external→ total=1（TEST-EXT-001）
```

### 测试辨别力实测（"跑绿"本身不算证据）

```
变异 1  词表删掉 'quantity_check' 一行  → FAIL 每个已知来源取值都有中文标签（漏了: quantity_check）
                                        → FAIL 数量对账（quantity_check）有中文标签
变异 2  RetailRetransmit::SOURCE 改名   → FAIL RetailRetransmit::SOURCE 在词表里（retail_resend）
两次变异都在 /tmp 副本里做、做完还原（与仓库文件 diff 为空）；仓库文件全程未被变异
```

## code-review 收口（2026-10-02，fixed point `93b7420`）

两轴（Standards / Spec）各一个子代理并行审 `git diff 93b7420...HEAD`。findings 与处置：

**改了**

| 轴 | 发现 | 处置 |
|---|---|---|
| Spec (c) | **徽标断言名不副实**：用例 2 只断言"徽标表的键与标签表的键对齐"。MAP 某行少写一截（`['零售采集']` 而非 `['零售采集', 'bg-dark']`）时键还在、值是 null，断言照样绿、徽标渲染成空 class（只有一条 PHP Warning，脚本不会因此变红） | 补一条**逐值**断言：每个徽标色都是非空字符串。变异实测：去掉 `retail` 的徽标色 → 变红（`空值: retail`）；CLAUDE.md 文件树那句"非空标签与徽标色"至此才名副其实 |
| Standards（文档） | `LogSource.php` 与票面都引 `.scratch/retail-chain/issues/09-docs-closeout.md` 为欠账出处，而那票仍写着"只记欠账、代码不动" | 在那票的 Comments 补一条**修订注**（欠账已还、原话不再代表现状），照该票既有的修订注惯例 |
| Spec（措辞） | 票面两处读起来与实现有出入：① grep 结论"只剩一处"比事实强（散文/注释里仍有"零售补传"这类说法）② 决策 2 只举了 `数量对账` / `外部上传` 两项多出来的下拉取值，漏了 `零售采集` | 两处措辞都订正 |

**没改（附理由）**

- **Spec (c) 辨别力只做了一半**（中）：用例 4 只钉得住以**类常量**声明来源的两个写入方（`RetailRetransmit::SOURCE` / `RetailManualEntry::LOG_SOURCE`）。其余写入方是脚本里的字面量（`fetch_bills` 的 `'cron'`、`check_quantity` 的 `'quantity_check'`、`check_bill_status` 的两处 `'batch_check'`、两处 `'batch_retry'`、`'retail'`），**改这些字面量不会让本测试变红**。要真兜住得扫源码解析字面量。**不修**：本仓库的测试只断言外部行为、不解析源码（spec 的 Testing Decisions 明写），加一个源码扫描器属于另一类测试且很脆（子串匹配下 `'manual'` 在 `'manual-upload'` 里也命中——假绿比没测更糟）。已把测试注释里"真闸门"的说法改成**写明覆盖范围**，不再夸大。**残留风险如实记在这里**：重命名脚本里的来源字面量时，得有人记得同步词表——目前靠人工，不靠断言。
- **Standards（Duplicated Code）：三个视图各一份 `json_encode(...)` 两行**（判断）：数据源已单一，剩下的是渲染惯用法。**不修**：仓库先例把渲染规则放在视图层（`companyOptionAttrs()` 在 `layout.php` 而非 `Enterprise`），把它塞进 `LogSource` 等于让词表类反过来管 JSON 输出。
- **Standards（Duplicated Code）：任务页下拉在视图里第二次枚举键集**（判断）：**这正是本票与用户拍板定的设计**（见「决策」表第一行），不是漏改。
- **Standards（Mysterious Name）：`LogSource` 也服务 `upload_tasks.source`，名字偏窄**（判断）：模块名由 spec §5 定（"新模块 `App\LogSource`"），改名要同步 spec/票面/文档三处；类注释首句已把两个表都点明。
- **Spec (b)：提交里带上了 `.scratch/retail-collection-split/` 的 spec 与 02–07 六份票面，票 01 没要求**（低）：那批文件本就未入库，而票 01 自己的票面也在其中——拆开提交会让票面与它的 spec 分家。作为本轮计划的首次入库，随本票一起进来。

## 未验到的部分（如实记录）

- **浏览器端未真机点过**：页面渲染是"服务端 require 真视图 + 核对产出的 HTML/JS"与"真跑 API/导出"两头夹住的，内联 JS 本身跑不起来（本机没有 JS 引擎，见 `.claude` 既有约定）。首次真机使用时看一眼：失败记录页选「数量对账」能否筛出告警行、下拉里新增的三项是否符合预期观感
- **徽标色是主观选择**：`bg-secondary` / `bg-danger` 两个新配色没有客观判据（票面也没规定），只保证"同页不撞色、含义说得通"；改色是改 `MAP` 一行的事

## Comments

### 2026-10-02 开工前与用户确认的范围问题

票面点名的消费者是"两个日志页视图与 xlsx 导出"，但被收口的三份标签表里有一份在上传任务页，且它的键集与日志页不同（有 `retail`、没有 `retail_retry`）。三种收法（三页都收但任务页下拉不变 / 严格只改两页 + 导出 / 三页统一用全量下拉）摆给用户后，选定**第一种**：模块成为标签/徽标的唯一来源，任务页下拉成员留在视图里、行为零变化。故本票有一处**超出票面字面范围的外溢**：上传任务页的标签/徽标映射改为取模块（纯重构，无可见变化）。

### 本票的补充约束（用户给定）

本票全程离线：不写 `data/msfx.db`、不调码上放心平台、不探源库；测试接缝只有词表一个模块；两个视图与导出不进测试。**验证时用 `/tmp` 项目副本 + `sqlite3 .backup` 出的副本库**跑真导出与真 API，合成数据只插副本库——生产库改动前后 `mtime` / 大小均未变，且全程无 `-wal` / `-shm` 产生。
