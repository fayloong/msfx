# 19: SQL Server 连接配置收敛成单一来源

- Type: task
- Status: done（2026-10-02）
- Blocked by: 无（18 已完成）
- 关联：**工单 18 的 code-review 遗留**（18 票「验证证据 → code-review 收口」表最后一行：
  "连接配置五字段第四次复制 → 不修：本票沿用既有写法，收敛要动三个既有调用点，另开一票更合适"）、
  `src/Config.php`（本票的落点）、`src/SqlSrvHelper.php`（消费方，本票不动它）、
  四个连接点（下表）、`CLAUDE.md`（文件树 `Config.php` 一行）

## 背景

`server` / `port` / `database` / `username` / `password` 五字段从 `Config::get()` 读 `.env` 的这段
配置，在仓库里各写了一份，现有 4 处：

| # | 连接点 | 位置 | 何时建连接 | timeout | 谁负责 `Config::load()` |
|---|--------|------|-----------|---------|------------------------|
| 1 | `App\TaskFetcher` | `src/TaskFetcher.php:16-22` | 构造即连 | 默认 30s | **靠调用方**（`fetch_bills.php:32` / `check_quantity.php:60` 各自 load） |
| 2 | `App\UpdateStateWriter` | `src/UpdateStateWriter.php:91-101` | 首次 `markUploaded()`（惰性） | **5s**（工单 18 专门修的） | **靠调用方**（Web 路径 `public/index.php:11` load） |
| 3 | `scripts/backfill_rq.php` | `scripts/backfill_rq.php:73-79` | 进 Step 3 时 | 默认 30s | 脚本自己（`:30`） |
| 4 | `scripts/fetch_bills_retail.php` | `scripts/fetch_bills_retail.php:96-102` | 采集前 | 默认 30s | 脚本自己（`:60`） |

改前四处**有效配置逐字相同**（五字段的来源与默认值都一致，仅 #2 多一个 `timeout=5`）：

| 键 | `.env` 键名 | 缺省值 | 四处是否相同 |
|---|---|---|---|
| server | `DB_SERVER` | `192.168.2.133` | 相同 |
| port | `DB_PORT` | `1433` | 相同 |
| database | `DB_DATABASE` | `hyyy_zyscm` | 相同 |
| username | `DB_USERNAME` | `sa` | 相同 |
| password | `DB_PASSWORD` | `''` | 相同 |
| timeout | —（不看 .env） | `SqlSrvHelper` 默认 30 | **#2 = 5，其余 30** |

两个风险：

1. **改 `.env` 键名或换实例要四处同改**。漏改不会报错——连到同构的另一个库是最坏的形态：
   `fetch_bills_retail` 采错库、`backfill_rq` 回填错值，都看不出来。
2. **`Config::load()` 不被强制**。`Config::load()` 不是自动跑的，某个新调用点忘了先 load，
   `Config::get()` 会**静默**返回硬编码默认值。今天看不出来是因为**生产 `.env` 的值恰好与
   硬编码默认值逐字相同**（见上表）——这正是它危险的地方：换实例的那天，漏 load 的那一处
   会连到 `192.168.2.133` 旧实例，而其余三处连新实例，两边都"连得上"。

## 要交付的行为

`App\Config` 新增一个方法，成为这五字段的唯一来源：

```php
public static function sqlServer(): array   // ['server','port','database','username','password']
```

- 方法内首行 `self::load()`——**调用点忘了 load 也不会静默用默认值**（`load()` 幂等：
  同值覆盖写 `self::$config`，`define` 有 `!defined` 保护）
- 返回**只有五字段**，不含 `timeout`、不含 `charset`/`options`
- 四个连接点改为调它；**注入点 `?array $config` 两个都保留**（TaskFetcher / UpdateStateWriter），
  注入时原样交给 `SqlSrvHelper`，不做任何叠加

**行为一字不变**，含：

| 连接点 | 改后 | timeout |
|--------|------|---------|
| `TaskFetcher` | `new \SqlSrvHelper($config ?? Config::sqlServer())` | 30（`SqlSrvHelper` 默认，不显式传） |
| `UpdateStateWriter` | `new \SqlSrvHelper($this->config ?? (Config::sqlServer() + ['timeout' => 5]))` | **5** |
| `backfill_rq` | `new \SqlSrvHelper(Config::sqlServer())` | 30 |
| `fetch_bills_retail` | `new \SqlSrvHelper(Config::sqlServer())` | 30 |

## 决策

| 决策点 | 选择 | 理由 |
|---|---|---|
| 落点 | `App\Config::sqlServer()` | "读 .env 拿连接配置"本就是 Config 的职责，四处重复的正是这一步。**不放 `SqlSrvHelper`**：那会让通用 DB 封装反过来依赖项目的 `.env` 键名（它现在对 `Config` 零依赖，这个方向要保住） |
| 返回形状 | 五字段，**不含 timeout** | timeout 不是"SQL Server 连接配置"，是**连接点的特性**（回写在 Web 请求里要短超时，工单 18 专门修过）。不含它，`SqlSrvHelper` 的默认 30s 仍只有一份；含了就要在四处分别决定"要不要被 Config 覆盖" |
| `UpdateStateWriter` 的 5s | 调用点自己叠加 `+ ['timeout' => 5]` | 显式、就地、可读；且**注入 `$config` 时不叠加**——保留注入点原语义（注入数组原样下传） |
| 强制 `load()` | `sqlServer()` 内无条件 `self::load()` | 幂等，重复调用只多读一次十几行的文件（微秒级）；不做静态标志位——`load()` 收 `$envPath` 参数，加标志会让"先 load 了 A 路径、再 load B 路径"静默失效 |
| 键名/默认值 | 原样搬进 `sqlServer()`，一个字符不改 | 本票是纯重构；改默认值属于另一件事 |
| 要不要 ADR | **不要** | 决策可逆（把方法拆回四处即可）、不改变对外行为，够不上"难以逆转" |

## 范围

### 改动

- `src/Config.php`：加 `sqlServer()`（新方法 + docblock 写明"四处连接点的唯一来源"）
- `src/TaskFetcher.php`、`src/UpdateStateWriter.php`、`scripts/backfill_rq.php`、
  `scripts/fetch_bills_retail.php`：各自的五字段字面量换成 `Config::sqlServer()`
  （`UpdateStateWriter` 保留 `timeout=5` 叠加）
- `tests/config_test.php`（新增，自包含断言，无框架）：
  1. 键集合**恰为**五字段（多一个 `timeout` 都算失败——钉住"timeout 不属于这一层"）
  2. 五个值都是 string
  3. **未显式 `load()` 的进程里，`password` 非空**——这条有辨别力：硬编码默认值是 `''` 而
     `.env` 里是真实密码，若将来有人把 `self::load()` 从方法里删掉，它当场变红
     （`.env` 不存在时跳过该断言并打印 SKIP）
  4. 与 `Config::get()` 逐键一致（透传关系）
  > "四处取到的配置一致"这条**不再单测**：收敛后四处都调同一个方法，一致是构造上的性质，
  > 测它等于测 PHP 的赋值语句。测的是上表里那些**会真的写错**的点。
- 文档同步：`CLAUDE.md` 文件树 `Config.php` 一行（补 `sqlServer()` 是什么）、
  `CLAUDE.md`"环境配置"或"关键依赖"章节点一句"SQL Server 连接配置的唯一来源"；
  `.scratch/retail-chain/spec.md` 票表加第 19 行

### 明确不做

- **不动 `SqlSrvHelper`**（它是通用封装，本票只改"谁提供配置"）
- **不动 `config/sql.php` 与 `upload_test.php`**：前者是调试残留、后者是旧版"勿运行"
  （CLAUDE.md 已各自注明），它们的硬编码连接是它们自己的历史包袱
- **不改 `.env` 键名与默认值**（纯重构，行为一字不变）
- **不碰码上放心平台**、**不写生产数据**（`data/msfx.db` 与 `dyt.bs_msfx.dbo.update_state` 都不动）
- 不给四处加新接缝（如 `Config::sqlServer()` 传参覆盖）；将来真要按连接点分叉时再说

## 验收

1. ✅ **改前/改后逐点比对**：从 `git show HEAD:<file>` 取四处旧字面量，与
   `Config::sqlServer()` 逐键机械对照——**4 处 × 5 对全等**（键名 + 兜底默认值逐字）；
   `timeout`：`UpdateStateWriter` 旧 `5` / 新 `5`，其余三处两边都"未给 → `SqlSrvHelper` 默认 30"
2. ✅ **只读连源库**（用户已授权）：实测 `@@SERVERNAME=XTY1`、`DB_NAME()=hyyy_zyscm`、
   `SUSER_SNAME()=sa`——五字段拼出来的连接确实指向该实例该库
3. ✅ **未 load 场景**：新进程里不调 `Config::load()`，直接 `Config::sqlServer()` → `password`
   7 字符（非空）。**并实测了这条测试的辨别力**（见「验证证据」）
4. ✅ **测试全绿**：新增 `tests/config_test.php` 4/4 + 既有 7 个自包含测试脚本全部"全部通过 ✓"
   （`search_bill` / `singlerelation` 两个探针要传单号且会调平台，**未跑**）
5. ✅ `php -l`：四个改动文件 + 新增测试，全部 `No syntax errors`
6. ✅ 文档同步：`CLAUDE.md`（文件树 `Config.php` 一行 + 「关键依赖」补一条）/ `spec.md`
   （票表第 19 行）与代码**同一次提交**
7. ✅ 票面收尾：Status 改 `done`、验收项勾上（**提交号回填是另一次提交**，见 Comments）
8. ⬜ 双推 gitee（优先）与 origin——**push 之后才能勾**，故本项留到第三次提交
   （仓先例：工单 09/18 的"已推送"类条目一律晚于 push 勾选，不写下与事实不符的内容）

## 验证证据

### 改前/改后逐点机械比对（`git show HEAD:` 取旧字面量，非人工眼校）

```
A. 四处旧字面量 vs Config::sqlServer() 新字面量
   OK  src/TaskFetcher.php / src/UpdateStateWriter.php / scripts/backfill_rq.php / scripts/fetch_bills_retail.php
   新（唯一一份）：[('server','DB_SERVER','192.168.2.133'), ('port','DB_PORT','1433'),
                    ('database','DB_DATABASE','hyyy_zyscm'), ('username','DB_USERNAME','sa'),
                    ('password','DB_PASSWORD','')]
B. timeout
   OK  src/TaskFetcher.php             旧=(未给→默认 30)  新=(未给→默认 30)
   OK  src/UpdateStateWriter.php       旧=['5']            新=['5']
   OK  scripts/backfill_rq.php         旧=(未给→默认 30)  新=(未给→默认 30)
   OK  scripts/fetch_bills_retail.php  旧=(未给→默认 30)  新=(未给→默认 30)
C. 代入同一份 .env 后的有效值
   旧四处：{'server':'192.168.2.133','port':'1433','database':'hyyy_zyscm','username':'sa','password':<7字符>}
   新探针实测：同上
总判定: 全部一致 ✓
```

### 只读连源库（`/tmp/probe19_config.php`——不写 SQLite、不写源库、不调平台）

```
Config::sqlServer()    server=192.168.2.133 port=1433 database=hyyy_zyscm username=sa password=(非空,7字符)
TaskFetcher            server=192.168.2.133 port=1433 database=hyyy_zyscm username=sa password=(非空,7字符) timeout=30
UpdateStateWriter      server=192.168.2.133 port=1433 database=hyyy_zyscm username=sa password=(非空,7字符) timeout=5
实际连上: @@SERVERNAME=XTY1  DB_NAME()=hyyy_zyscm  SUSER_SNAME()=sa
```

前两行是**反射读**（TaskFetcher 内部 `SqlSrvHelper` 的私有 `$config`）；`UpdateStateWriter`
那行是反射调 private `db()`——**只建连接**，没调 `markUploaded()`，故源库一行未写。

### 测试辨别力实测（"跑绿"本身不算证据）

临时摘掉 `sqlServer()` 里的 `self::load()` 再跑：**用例 3（password 非空）与用例 4（与
`Config::get()` 逐键一致）当场变红**，用例 1/2 仍绿——证明这两条真在测"load 到底跑没跑"，
不是恒真断言（生产 `.env` 的 server/port/database/username 与硬编码默认值逐字相同，
只有 password 能分辨）。还原备份后重新全绿。

## Comments

### 2026-10-02 开工前与用户确认的两件事

1. **本票是纯重构，不加测试接缝也行**；若加，只加"自包含断言"这类——故 `tests/config_test.php`
   只做上表那四条，不引入新的抽象层。
2. **允许只读连一次源库**验证配置正确（验收第 2 条）；临时 ad-hoc 探测仍要先问。
   本票不需要任何源库结构探测——四个连接点的现状一行 `sed` 就能看全。
