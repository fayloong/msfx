# 02: 多企业落库 + 零售对自动链路不可见（排雷）

- Type: task
- Status: done（2026-09-29）
- Blocked by: None（工单 01 已完成）
- 关联：spec.md §7/§8/§9、docs/adr/0006-credential-as-routing-subject.md、docs/adr/0007-retail-no-platform-reconciliation.md、docs/adr/0008-retail-claim-by-platform-id.md

## 要交付的行为

库里**第一次出现"不属于河药"的记录时，整套自动链路对它们零动作**——没有一次平台调用、没有一条 `upload_logs` 新增；
同时每条记录都带上"它属于哪家企业"。

这是纯 prefactor，但必须排在零售数据落库（工单 03）之前。

## 为什么排第一

现有自动链路的凭据是写死的：`UploadService` 用河药 AppKey、构造 kyt 请求类。而**不止一处会取走任务去上传**：

- `scripts/upload_pending.php`（cron 采集后自动上传）
- `src/api/tasks_retry.php`（页面单条重传）
- `src/api/tasks_batch_retry.php`（页面批量重传）

三处都是 `new UploadService()->upload()`，都不传企业。零售单据一旦落库，其中任何一条路径都会**用河药凭据、走批发接口把门店单据申报到河药主体名下**——传到平台上的不可逆错误。
所以排雷不能只改 SQL，必须在 `UploadService` 里放一道 **fail-closed** 的关口，一次护住全部调用方与将来新增的调用方。

## 范围

**schema（幂等迁移，可重复执行）**

- `upload_tasks` / `upload_logs` 各加两列：`company`（企业中文全名，页面"所属企业"列的值与筛选键）、`credential`（该企业 primary 凭据键，如 `main`；只作审计，不参与任何键）
- `ent_list` 加 `company` 列，唯一约束 `ent_name UNIQUE` → `UNIQUE(company, ent_name)`（SQLite 需重建表）；选择在**只有一家批发企业时**改，是因为此时成本最低，第二个批发主体进来再改就要停机洗数据
- 历史 `upload_tasks` / `upload_logs` 行回填 `河药医药（河源）有限公司`（批发主体全名，全角括号）
- 沿用 `scripts/init_db.php` 既有的 try/catch 逐列加列风格；索引（含 `idx_upload_logs_djbh_response`）在 `company` 进 WHERE 后复核一次适用性

**守卫（这是本票的核心）**

- `UploadService` 改为**按任务所属企业取凭据**，判定走 `App\Enterprise`（工单 01 已就绪），不硬编码企业名：
  - 路由结果取不到、或不是批发（kyt）那一族 → **抛错拒绝上传**
  - 取不到该企业的凭据 → 抛错拒绝上传
  - **绝不回落到默认凭据**——只靠 cron 的 SQL 写对是不够的，任何脚本直接 `new UploadService()` 都会绕过它
- `scripts/upload_pending.php` 取数口径加**白名单** `AND company = '河药医药（河源）有限公司'`，并改掉文件头注释里"处理所有来源"的说法
  - 排除法 `source != 'retail'` 已被否：它 fail-open，将来任何新增来源漏改条件就是把门店单据报到河药名下
- `ent_list` 的两处读写（都在 `UploadService`）带上 `company`
- 批发链路的既有 `djbh` 去重查询（`fetch_bills.php` 与其他按 `djbh` 判重的地方）一律限定河药——去重键是 `(company, djbh)`，不是裸 `djbh`

**自动检查链路对零售零动作**

- `check_bill_status.php` / `check_failed_logs.php` / `check_quantity.php` 一律排除零售记录
- 其中 `check_failed_logs.php` 是**必须**排除的：它拿河药凭据去查门店单号只会得到"信息不存在"，白烧调用还可能翻错状态
- **反向注意**：零售的失败记录本身要**保留在失败记录页可见**（`api/failed.php` 不要顺手排掉）。零售的 `upload_logs` 只可能由人工补传产生（检查脚本根本写不出零售日志），所以失败页上出现的零售记录必然是人工补传失败——那是操作者唯一能看见"补传没成功"的聚合出口
- `task_status` 取值集合确认为：`等待上传`（批发，cron 会取）/ `待补传`（零售，仅人工）/ `已处理`

**本轮不动的部分（明确留债，避免这张票膨胀）**

- `ApiClient` 的查询类凭据（`searchBillDetail` / `queryEntInfo` / `searchSingleRelation`）仍读 `.env`，因为它们只服务批发链路；等 `.env` 旧键下线那次一并处理
- 仪表盘统计卡片

## 验收

- [x] 迁移脚本可重复执行；回填后三个数据页与既有筛选/查询行为完全不变
  - 迁移脚本在副本连跑 3 次、生产库连跑 2 次，第 2 次起零输出（幂等）；行数 41626 / 42894 / 773 前后一致，回填后无 `company=''` 残留
  - 三个数据页 HTML、三个列表 API、手动上传页、仪表盘、导出 xlsx（限定单号 2 行 / 09-26 已上传 664 行）全部 200 且数据正常
  - 索引复核：加 `company` 后 `idx_upload_logs_djbh_response` 仍适用（迁移前 48–69ms / 迁移后 46–56ms，无劣化），不新增索引
- [x] **批发链路回归**：一条批发任务经 `upload_pending.php` 上传成功；`ent_list` 缓存命中与回填正常
  - **实测改走已上传单号重传**（用户 2026-09-29 决定）：未跑全量 `upload_pending.php`——库里 311 条真实待上传单（306 条当天、均无平台成功记录）会被一起申报，与本系统"外部系统负责上传、本项目只检查不补传"的定位冲突。改用 `tasks_retry` 打 `XSOWMS01032449`（平台已验证存在）→ 平台返回"该单据号已存在（上传时间 2026-09-29 21:40:03）"→ 落库 `batch_retry / 单据重复 / 河药 / main`，凭据取用、签名、路由、装配、响应解析全链路验证
  - `ent_list` 缓存按 `(company, ent_name)` 读写：同企业命中、跨企业不命中、`INSERT OR REPLACE` 回填正常（生产库实测，测试行已清）
- [x] 造一条 `company=大源堂…某门店`、`task_status=待补传`、`source=retail` 的任务，然后：
  - [x] 跑 `upload_pending.php` → 该任务未被取走
    - 取数 SQL 等价验证（未跑脚本，理由同上）：白名单取到 311 条不含它
    - **再加测最坏情况**：把该任务状态临时改成 `等待上传` → 不带白名单取 312 条（含它）、带白名单仍 311 条（不含）→ 证明是 `company` 白名单本身挡住的（这正是否掉 `source != 'retail'` 排除法的理由），验后状态已还原
  - [x] 调一次单条重传与一次批量重传接口 → 返回明确错误，**零平台调用、零 `upload_logs` 新增**
    - 两接口均返回 `{"_final":true,"error":"单号 DBSTEST0001: 企业「大源堂智慧药房（河源）有限公司宝源店」的单据类型 201 不走批发上传接口，本服务拒绝上传"}`
    - `upload_logs` 42894 → 42894、JSONL 1331 → 1331；任务状态未被异常分支篡改（仍 `待补传`）
  - [x] 跑三个检查脚本 → 不产生任何涉及该单号的记录
    - `check_bill_status`（311 单 → 270 已上传 / 41 未上传 / 0 异常）、`check_failed_logs`（39 条失败记录 → 34 单复查）、`check_quantity 2026-07-30`（2 单 → 真问题 0）三份输出中 `DBSTEST0001` 出现 **0 次**，库中该单号 `upload_logs` 记录 **0 条**
    - `check_quantity` 用 07-30 而非默认昨天：07-30 仅 2 单（09-28 有 1398 单 ≈ 23 分钟），代价最小且足以验证脚本跑通
- [x] `ent_list` 同企业内仍唯一、跨企业可同名
  - 生产库实测：同企业重复插入被唯一约束拒绝（`columns company, ent_name are not unique`）、跨企业同名可共存

## 实现笔记（超出票面的必要处理）

- **`tasks_retry` / `tasks_batch_retry` 的异常恢复分支**原先把任务状态一律写回 `等待上传`。守卫是 fail-closed，抛错时零售任务会被推成 `等待上传`（语义是"cron 会来取走"，与 `待补传` 冲突）。改为**恢复为调用前的状态**（单条取该行原值，批量逐条取各自原值）
- **`check_quantity` 写入的日志必须带 `company`**：它的幂等清理是 `DELETE ... WHERE source='quantity_check' AND rq=? AND company=?`，若写入不带 company，清理就删不到，记录会逐轮堆积
- **`ApiClient` 的查询类凭据**（`searchBillDetail` / `queryEntInfo` / `searchSingleRelation`）仍读 `.env`，按票面留债；因本服务只放行批发单据，与传入凭据一致
- **`Enterprise::wholesaleSubject()`**（新增）：批发链路取"本项目自动上传主体"的唯一入口，避免各脚本硬编码企业名与凭据键；**批发企业不是恰好一家时抛异常**，不静默取第一个。返回键用 `credential_key`（**键**）以免与 `Enterprise::credential()` 返回的**凭据数组**混用。已在 `tests/enterprise_config_test.php`（既有接缝）补 8 项断言，未新增测试接缝
- **`init_db.php` 用 PRAGMA 探测而非票面写的 try/catch 逐列加列**：PHP 8.1 的 SQLite3 默认不抛异常，`ALTER` 失败只返回 false，try 块里后续的"回填"语句会照常执行——回填一旦重复执行就会把零售的合法取值（`company='未识别'`、`credential` 为空表示待配凭据）误标成河药。改探测后"回填只在列刚加时做一次"才真正成立

## code-review 后的收口（同日）

两轴审查（Standards / Spec）提出后逐条处理：

- **ADR 0007 修订同步**（硬性规范）：决策已改为"零售记录保留在失败记录页"，ADR 仍写"也不出现在 `api/failed.php`"——按 `docs/adr/0006` 的内联批注体例补了修订说明
- **`init_db.php` 半迁移态可自愈**（真实隐患）：原先只按 `company` 一列判断整块跳过，一旦第一列加成功、第二列加失败就永久跳过，而 `LogWriter` 无条件 INSERT `credential` → 该库所有上传日志写入失败。改为两列各自独立判断；已用"`company` 有、`credential` 无"的库实测自愈
- **`api/failed.php` / `api/export.php` 的 `NOT EXISTS` 补 company 限定**：票面要求"其他按 djbh 判重的地方一律限定河药"，这两处仍是裸 `djbh`——裸判重会让零售失败记录被同号批发成功单顶掉，而"零售失败记录必然可见"正是本票的另一条要求（两处同时改，保证页面与导出口径一致）
- **`scripts/backfill_rq.php` 限定批发主体**：Step 2 的 `JOIN ... ON l.djbh = t.djbh` 与 Step 3 的 `UPDATE ... WHERE djbh = ?` 都是裸 djbh，零售落库后会跨企业串 rq（Step 1 走 task_id 主键，无须限定）
- **`check_failed_logs.php` 的 JSONL 记录补 `credential`**：与 `check_bill_status.php` 的同名记录对齐
- **`Enterprise::wholesaleSubject()` 返回键 `credential` → `credential_key`**：同一文件里 `credential` 既指键（wholesaleSubject）又指数组（Enterprise::credential），消除歧义
- **测试补 2 项断言钉住"配置里的批发主体 == 迁移回填常量"**：两者不一致时历史行会被 company 白名单静默漏掉、批发链路整条停摆，原先只有代码注释没有断言
- **未采纳（记录理由）**：`failed.php` / `export.php` 判重里 `quantity_check` 豁免只在页面有、导出没有，是既有不一致（`spec.md` 已归工单 08 的筛选与导出）；`fetch_bills.php` 与三个检查脚本里 `AND company = ?` 的分散写法，抽公共查询层超出本票范围，同样留待工单 08 一并处理
