# 02: 采集分流——外部已上传的单进「已上传记录」

**What to build:** 采集不再把外部系统已上传的门店单挡在门外（结束 2026-09-30 的测试阶段临时口径）。这些单落成一条「**外部上传**」记录，进已上传记录页（含所属企业、单据日期、追溯码、来源说明），**不再进补传队列**；未上传的照旧建「等待上传」任务，由人补传。判据只有一处：源库对接状态表（`bill_code` + `bill_state`，成功为 `'1'`）。

**Blocked by:** 01（新来源要有标签可放）

**Status:** done（2026-10-02）

- [x] 采集查询去掉已上传过滤，改取一个「已上传」判据列；判据用 `EXISTS` 子查询——状态表无唯一约束、实测 82 个单号多行，`JOIN` 会把结果集放大
  - 落点 `scripts/fetch_bills_retail.php`：`case when exists(select 1 from <状态表> us where us.bill_code=ls.bill_code) then 1 else 0 end as uploaded`。SQL Server 不允许在**聚合**里套子查询（票 04 的门卫计数因此要换写法），非聚合的 `case when` 是允许的——脚本注释里记了这一点
- [x] 已上传的单：写一条上传日志（来源=**外部上传**、响应状态=上传成功、所属企业与凭据取认领结果、单据日期取源表、追溯码照写、`task_id=0`、请求状态留空、返回内容写一段来源说明），**不建任务行**
  - 落点 `App\RetailExternalUploads::buildRecord()`（纯函数，逐列可断言）＋ `record()`（薄写入）
  - 实测 4 条记录逐列核对：`source=retail_external`、`response_status=上传成功`、`task_id=0`、`ent_name` 空、`request_status` **是真 NULL**（不是空串）、company/credential 取认领结果、`response` 是出处 JSON；这 4 个单号在 `upload_tasks` 里 **0 行**
- [x] 未上传的单：建任务逻辑与改动前**逐字段一致**（认领、2 年下限、三列元数据、来源=零售采集、状态=等待上传）
  - `INSERT INTO upload_tasks (...)` 与认领块逐字未动；实测当次新增任务 0 条、跳过 41 条（都是"任务行已在"的既有行）
- [x] 幂等：同一 `(company, djbh)` 不产生第二条成功记录；重跑同一日期不重复写入
  - 三条路径：`decide()` 的 `$hasSuccess` 分支（单测用例 3）、采集侧按"已上传分支只看**成功记录**、未上传分支看两种痕迹"分两张查（合成一张会让已上传的误跳过、记录写不出来）、实测**原地重跑 → 45 张全跳过、0 新增**（记录总数仍为 4）
- [x] 状态表名收成一处常量，供采集、闭环、门卫共用，仓内不再有第二处硬编码
  - `App\RetailExternalUploads::TABLE`；`UpdateStateWriter` 改为引用它（写侧不再自己写一遍表名），采集 SQL 用它拼 EXISTS 子查询
  - **可执行代码里的字面量只剩常量那一处**：`grep -rn 'dyt.bs_msfx.dbo.update_state' src/ scripts/ tests/ config/` 命中三处——常量定义、测试里的断言字面量（刻意的钉子，`log_source_test` 的"独立陈述"同款）、`config/sql.php` 的调试残留 SQL 文本（该文件 CLAUDE.md 已声明"口径以脚本为准"）；另有几处**注释**里提到表名（`RetailRetransmit` 的类注释、`backfill_update_state.php` 的用法注释），那是叙述，不是可执行的字面量
- [x] 自包含测试：记录各列取值、成功记录不变量、已上传/未上传两条分支的判定
  - 新增 `tests/retail_external_uploads_test.php`（30 条断言，无 PHPUnit）：`decide()` 的 8 种组合真值表、记录形状逐列、幂等不变量、来源常量落在 `App\LogSource` 词表里、表名常量
  - **辨别力实测**（改坏 → 红 → 恢复 → 绿）：去掉 `$hasSuccess` 检查 → 2 条变红；`RESPONSE_STATUS` 改成"上传失败" → 2 条变红；`SOURCE` 改成词表没有的值 → 3 条变红
- [x] 实测一次当日采集：已上传的只出现在已上传记录页（来源显示「外部上传」）、未上传的只出现在任务页；既有测试全部跑通
  - 2026-10-02 11:01 以 nginx 身份跑，跑前已备份（`sqlite3 data/msfx.db ".backup '/root/msfx-backup-2026-10-02-1101.db'"`，备份在仓库外）：**45 张单据 → 4 条外部上传记录 ＋ 41 条跳过 ＋ 0 条新任务**
  - 页面核对走**真端点**（伪造已登录会话 require `public/index.php`，不是直查库）：已上传页 `source=retail_external` → `total=4`（`request_status=NULL`、`task_id=0`）；那 4 个单号在任务页按单号搜 → **全部 `total=0`**；抽一张未上传的单 → `total=1`（等待上传）。视图 HTML 里 `"retail_external":"外部上传"` 与 `<option value="retail_external">外部上传</option>` 都在
  - 源库只读对账（判据不是听脚本自己说的）：4 张在状态表里都是 `bill_state=1`，抽样的 3 张未上传单都不在表里
  - 既有测试脚本全部通过（`search_bill_test` / `singlerelation_test` 两个探针需传单号，非失败）
- [x] CLAUDE.md / CONTEXT.md 与 ADR 0007（修订注三：过滤 → 分流）、ADR 0016（读侧口径随之扩展）同步
  - CLAUDE.md：文件树（新类 + 新测试 + 脚本行 + `sql.php` 行）、"零售单据采集"整节（含实测数字）、`upload_logs.source` 取值表、常用命令、测试命令清单
  - CONTEXT.md：「零售采集」词条改分流、新增「**外部上传**」词条（含"来源名说的是**分流结果**、不保证一定是外部系统传的"这条语义边界）、`dyt 链接服务器` 词条
  - ADR 0007 修订注三（含"原始判断以另一种形式兑现"与两条代价的现状）；ADR 0016 的 Consequences 补两条：读侧口径扩展、来源名的语义边界

**票面外的一处改动（如实记下）**：`--all` 分支加了两行警告输出，**没有**加拒绝退出。理由：过滤去掉后 `--all` 会把最近两年窗口内约三万七千张未上传的历史单全建成任务，而"只写已上传记录、历史未上传的不建任务"这条新语义是**票 05** 的；本票不该改变那个入口的行为，但必须让人在输出里看得见后果（用户已明确本票不跑 `--all`）。

**code-review 收口（本票已改）**：
- `RetailExternalUploads` 删掉**无人传参**的 `LogWriter` 构造注入（Standards 轴：为将来准备的钩子），`record()` 随类里其余方法改静态——这个类不带状态，判定与形状都是纯函数
- 采集脚本的 `decide()` 调用改**命名参数**（`uploaded:` / `hasTask:` / `hasSuccess:`）：后两个都是同型 bool，位置传参写反了没有任何东西会拦
- 统计行措辞订正：`本批认领不到门店的共 M 条`——M 的范围是**拉取到的整批**（含被跳过与写成记录的），原先的括号写法把它窄化成了"新增任务里"
- 收口后复跑：测试全绿；当日采集 49 张（源库已多出几张、cron 期间也采过）**全跳过、0 新增**——幂等仍成立，新措辞生效

**code-review 提出、本票未处理（记欠账）**：
- **"成功口径"在仓内仍是多份拷贝**：采集脚本那句 `response_status IN ('上传成功', '单据重复')`（本票只是把它从 `$existingSet` 挪到 `$successSet`，**逐字未变**）与 `App\RecordQuery`、`views/dashboard.php`、`RetailRetransmit::uploadSingle` 各有一份。本仓库有拷贝漂移的前科（export 曾漏 `quantity_check` 豁免），宜收成一处；但那涉及 5 处、且 SQL 的 `IN` 与 PHP 的 `in_array` 形状不同，超出本票范围
- **未识别门店的记录 `credential` 落空串而非 NULL**：`LogWriter::write()` 的 `$entry['credential'] ?? ''` 是既有行为，而 `upload_tasks` 那边未识别行的同一列是 NULL。无查询影响（仓内没有按 `credential` 筛选的口径），本次实测的 4 条又都是认领成功的，暂不动

**决策记录（票面没写、实现时定的）**：
- **已上传 + 本地已有任务行 → 仍写记录**（只有"已有**成功记录**"才整条跳过）：那条任务行是本地待办痕迹，翻正是票 03 的闭环；若在这里因为它的存在而跳过，这张单在本系统里会凭空消失。单测用例 2 的第七行钉住这一格
- **表名常量放读侧类**（`RetailExternalUploads::TABLE`）而不是写侧：读侧消费者更多（采集、闭环、门卫），写侧一个
