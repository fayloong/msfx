# 02: 多企业落库 + 零售对自动链路不可见（排雷）

- Type: task
- Status: ready-for-agent
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

- [ ] 迁移脚本可重复执行；回填后三个数据页与既有筛选/查询行为完全不变
- [ ] **批发链路回归**：一条批发任务经 `upload_pending.php` 上传成功；`ent_list` 缓存命中与回填正常
- [ ] 造一条 `company=大源堂…某门店`、`task_status=待补传`、`source=retail` 的任务，然后：
  - 跑 `upload_pending.php` → 该任务未被取走
  - 调一次单条重传与一次批量重传接口 → 返回明确错误，**零平台调用、零 `upload_logs` 新增**
  - 跑三个检查脚本 → 不产生任何涉及该单号的记录
- [ ] `ent_list` 同企业内仍唯一、跨企业可同名
