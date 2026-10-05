# 01: 门店单据在平台上再核查一次（脚本 + 判据 + 复用闭环落库）

**What to build:** 见 `spec.md`。一句话：新增 `scripts/check_bill_status_retail.php`，拿**各门店自己的凭据**调 `lsyd.query.upbilldetail` 问平台「这单在不在」，在的翻正（任务行翻「已处理」+「上传成功」、失败记录追加一条「外部上传」）。判据与落库复用状态闭环那一套（`RetailExternalUploads`），平台核查只是喂给它**另一条判据**。

**Blocked by:** None（可立即开始）

**Status:** done（2026-10-05）

- [x] 请求类 `AlibabaAlihealthDrugtraceTopLsydQueryUpbilldetailRequest` 逐类并入 `top_sdk/top/request/`，与 `top_sdk_retail.zip` 内**逐字一致**
  - `unzip -p … > …` 后 `diff` 两份为空（逐字一致）；`top/domain/` 的 DTO 不需要（本项目走 xml → SimpleXMLElement，见 ADR 0009）
- [x] `ApiClient::queryUpbillDetail(string $billCode, string $refEntId)`：入参 `ref_ent_id` **必传、无默认值**（照 `queryEntInfo` 的先例）；返回形状与 `searchBillDetail()` 对齐（`found`/`response`/`error`）；查询失败**不冒充"未上传"**（`found=false` 且 `error` 非空，docblock 写明"调用方必须先看 error"）
- [x] `isBillFound()` 的判据与 docblock 扩到两个接口共用（searchbill.detail + lsyd.query.upbilldetail），行为不变
  - 判据一个字没改（`msg_code === 'FAIL_BIZ_NO_PAT_INFO'` 视为未上传）——探测实证两个接口的响应形状同款
- [x] `RetailExternalUploads` 抽出 `pendingItems()` / `successKeys()` / `applyActions()`；`closeLoop()` 改调它们，**行为逐项不变**
  - `tests/retail_external_uploads_test.php` 全绿（闭环的纯函数用例逐条通过）；`applyActions` 的出处信息参数化（`reason`/`checker`/`log_type`/`judged_by`），`closeLoop()` 传的就是原来那两句措辞与 `retail_status_closure` 这个 type
  - `buildRecord()` / `provenanceJson()` 加了**带默认值**的两个参数（默认值即原话术），采集那条路径的调用一个字没改
- [x] `App\RetailPlatformCheck`：按企业分组、跳过 `未识别` 与取不到凭据的门店并计数、逐条查平台（500ms）、查询异常只跳过、`--dry-run` 不调平台不写库
  - 跳过判据含 `Enterprise::isRetail`（fail-closed：混进来的批发主体也挡掉——拿河药凭据查门店单号只会得到"信息不存在"，白烧调用）
  - `run()` 收 `callable $query` + `int $intervalUs`（测试传 0 就不必等，与 `RetailBatchUpload::run()` 同一接缝思路）
- [x] **企业隔离**：甲店查到的单号不翻乙店的同名待办（按企业分组调 `closureActions()`，不是全局扁平集合）
  - 落点 `RetailPlatformCheck::actionsByCompany()`；辨别力实测：改成"一次喂全量集合"→ 2 条断言变红
- [x] `scripts/check_bill_status_retail.php`：两个来源、flock、`--dry-run`、`--limit`、`--company`、进度与统计输出
  - 新鲜度门卫与 touch **刻意不做**（手动跑的脚本，理由与代价见 `spec.md`「开销与边界」）
- [x] `tests/retail_platform_check_test.php` 自包含断言：**27 条全绿**
  - 分组跳过判据（未识别/待配凭据/未声明凭据位/批发主体）、企业隔离（含反向与"已有成功记录时只翻任务行"）、dry-run 一次都不调平台、三种结果归类与收集（error 不冒充未上传）、逐条隔离、限量（跳过的门店不占额度）、事件回调
- [x] 既有全部测试跑绿（离线，不调平台）
  - 13 个离线测试逐个退出码 0、「全部通过」；`search_bill_test` / `singlerelation_test` 两个探针不跑（会调平台）
- [x] 文档同步：CLAUDE.md（文件树 5 处、核心数据流新增一节、常用命令、测试清单、vendored 约定）、CONTEXT.md（「外部上传」词条加第三条写入路径）、ADR 0016 修订注一
- [x] 项目副本上真跑一次（`--dry-run` 全量 + 小限量真查），核对翻正行数与平台响应——证据见下

## 验证证据

### 探测（2026-10-05，新江分店凭据，2 次只读查询）

| 情形 | 单号 | `result.msg_code` | `msg_info` | `response_success` |
|---|---|---|---|---|
| 已上传 | `XLSA0200100185242` | `SUCCESS` | 调用成功 | `true`（附单据详情 model） |
| 未上传 | `XLSA0200100185757` | `FAIL_BIZ_NO_PAT_INFO` | 信息不存在 | `false` |

两个接口响应形状同款 → `isBillFound()` 直接复用，不另写判据。

### 副本真跑（`/tmp/verify-pc`，生产库与生产源库零写入）

**预演全量**：本地待办 **4,497 条 / 14 家企业** → 可查 5 家 **1,410 条**、跳过 9 家 **2,087 条**（未识别 51 条 + 待配凭据 8 家）。**一次平台调用都没发**。

**新江分店真跑 47 条**（45 条真实待办 + 2 条自造场景）：

```
核查完成: 已上传 4 / 未上传 43 / 异常 0 / 跳过 0 家门店 0 条（共 47 条）
翻正: 任务行 2 行、追加外部上传记录 3 条
```

自造的两条（平台确定存在，用于验证两条分支）：

| 场景 | 造法 | 期望 | 实测 |
|---|---|---|---|
| A `XLSA0200100185242` | 任务行改回「等待上传」+ 删掉本地成功记录 | 翻任务行 + 追加记录 | ✓（`#97277` 追加） |
| B `XLSA0200100185245` | 任务行改回「等待上传」，**保留**成功记录 | 只翻任务行 | ✓（未追加） |

逐项核对（副本库直查）：
- 两条任务行 → `task_status='已处理'`、`response_status='上传成功'`、**`request_status` 保持 NULL**（本项目没发起过上传请求）；`resp.judged_by = alibaba.alihealth.drugtrace.top.lsyd.query.upbilldetail`、`resp.reason = 平台查询确认该单据已上传（lsyd.query.upbilldetail），本行任务由平台核查翻正；本项目未发起任何平台请求`
- 追加 3 条「外部上传」记录（`#97277` 自造 A + `#97278`/`#97279` **真实发现**）：`source='retail_external'`、`task_id=0`、`request_status=NULL`、`response_status='上传成功'`、`judged_by` 是平台接口名
- `upload_logs` 96,492 → **96,495**（+3）；新江待办任务行 43 → **41**
- **真实发现 2 条**"补传失败但平台已有"的单（`XLSA0200100180956` / `XLSA0200100183018`）——正是本功能的价值场景

**重跑（幂等）**：`已上传 2 / 未上传 43 / 异常 0`，**翻正 0 行、追加 0 条**，`upload_logs` 总数不变（那两条失败记录因"已有成功记录"被 `closureActions()` 判无动作）。

### 辨别力（变异测试，逐条改一处跑一遍再还原）

| 变异 | 变红的断言 |
|---|---|
| 企业隔离：`actionsByCompany` 一次喂全量集合 | 2 条（乙店同名待办被误翻） |
| dry-run 分支去掉（照查） | 2 条（假回调被调 2 次） |
| 跳过判据去掉 `isRetail` | 2 条（批发主体进了可查列表） |
| `error` 非空并进 `absent` | 2 条（异常被当成"未上传"） |
| 限量截断去掉 | 2 条（限不住） |

还原后逐字一致（`diff` 空）+ 27 条全绿。

## Comments

- **开工时用户拍了三个决策**（探测 / 调度 / 覆盖范围），见 `spec.md` 决策表。
- **实现中改了 spec 的一条口径**：门卫从"照批发做 30 分钟"改成"**不做**"——手动跑的脚本没有那个场景，且清单随翻正自然收敛；代价（被翻正的失败记录每次仍会被重查一次）写进了 spec 与 CLAUDE.md。
- **`--company` 是开工后加的**（票面最初没列）：排查单店是真实需求（"只看大湖那 627 条"），与 `upload_pending_retail.php` 的 `--company` 同语义（取回之后过滤）。
