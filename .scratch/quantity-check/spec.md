**Status:** ready-for-agent

# 数量对账检查（check_quantity.php）

## Problem Statement

外部系统负责将单据的追溯码上传到码上放心平台，本项目上传 cron 未启用，现阶段定位为"检查外部系统上传情况"。但现有检查只能到**单据级**：`check_bill_status.php` 调 `searchbilldetail` 确认"这张单在不在平台上"——该接口返回单据头 + 药品数量（`min_pkg_count`），**不含追溯码明细**。

因此，如果某一单有 100 个追溯码、实际只传了 50 个（其余 50 个因意外未上传），现有检查**发现不了**：单号在平台上存在即判"已上传"。外部系统按 3500 码/批拆分上传（`单号_1`/`单号_2`/...）进一步加剧盲区——原始单号可能根本查不到，被误判"未上传"。

运维人员需要一种能发现"整单数量未传齐"的检查手段。

## Solution

新建独立脚本 `scripts/check_quantity.php`（数量对账），以 SQL Server 为"应有码"基线，逐单调平台 `searchbilldetail` 查询**原始单号及其拆分子单**的申报数量，与本地应有码数对比；数量不符时写入 `upload_logs`（`response_status='数量不符'`）＋ JSONL 日志，Web 端失败记录页天然可见。每天 cron 运行一次，默认检查前一天，只告警不补传。

## User Stories

1. 作为运维人员，我想要每天自动检查前一天所有单据的追溯码数量是否完整上传，以便及时发现"整单没传齐"而不必逐单核对
2. 作为运维人员，当一单 100 码只传了 50 码时，我想要检查能识别出来并告警，以便通知外部系统补传
3. 作为运维人员，当外部系统拆分上传（`单号_1`/`单号_2`/...）时，我想要各子单的申报数量被汇总后与整单应有码数对比，以便拆分上传不误报
4. 作为运维人员，当单据在平台上完全查不到时，我想要检查判定为数量不符（平台数量 0），以便区分"没传"与"传了但少传"
5. 作为运维人员，我想要检查结果在 Web 端失败记录页可见（与现有失败日志同入口），以便不用登录服务器看日志
6. 作为运维人员，我想要数量相符的单据不产生任何记录，以便检查零噪声、只看有问题的
7. 作为运维人员，我想要检查基线来自 SQL Server 实时拉取，以便本地采集/上传 cron 是否运行都不影响检查
8. 作为运维人员，我想要检查脚本与 `check_bill_status.php` 完全独立，以便各自按自己的 cron 节奏运行、互不干扰
9. 作为运维人员，我想要脚本支持手动指定日期补查，以便漏跑 cron 后能补查历史日期
10. 作为运维人员，我想要单条单据 API 查询异常时跳过该单并计入异常统计，以便网络抖动不影响整批检查
11. 作为运维人员，我想要单药品单据和多药品单据的数量都能正确汇总，以便汇总结果可信
12. 作为运维人员，我想要"数量不符"记录中包含预期数、平台数与子单明细，以便人工复核时能定位到具体差距
13. 作为运维人员，我想要 API 调用保持限速（0.5s/次），以便不触发平台限流
14. 作为运维人员，我想要拆分子单的查询有数量上限，以便异常数据不会导致脚本死循环
15. 作为运维人员，我想要一天 ~650 单的检查在几分钟内完成，以便每天 cron 窗口不与其他任务冲突

## Implementation Decisions

### 1. 新脚本，不改动 check_bill_status.php

新建 `scripts/check_quantity.php`，独立 cron（每天一次，建议凌晨或早上低峰期）。`check_bill_status.php` 一行不动——两者的数据基线（本地任务表 vs SQL Server）、cron 节奏（5 分钟高频+门卫 vs 每天一次固定量）、写入语义（状态机更新 vs 告警日志）均不同，合并会使高频检查的 API 量爆炸。项目已有采集/上传解耦先例（`fetch_bills.php` / `upload_pending.php`）。

### 2. 基线复用 TaskFetcher::fetchBills()

`TaskFetcher::fetchBills($date)` 只做查询与按单聚合（`djbh` + 逗号分隔 `sn`），写库发生在脚本层（`fetch_bills.php`），可直接复用。新脚本取 `djbh` 与码数（`explode(',', $sn)` 计数），不取码内容。脚本接收日期参数 `$argv[1]`，默认昨天（`date('Y-m-d', strtotime('-1 day'))`）。

### 3. 单号查询策略：原始单号 + 拆分子单

对每单依次查询：`searchbilldetail(单号)` → `单号_1` → `单号_2`...（**原始单号查不到仍继续查拆分子单**——外部系统拆分上传时原始单号在平台不存在；拆分子单查不到则序列结束；原始单号已传齐时提前结束节省调用）；上限 10 次（3500 码/批 × 10 = 35000 码，现实单据不会超出，防死循环）。平台申报总数 = 各次查询 `sum(min_pkg_count)` 之和。此策略同时修正"拆分子单已传但原始单号查不到被判未上传"的盲区。

### 4. ApiClient 新增数量解析方法

`ApiClient::sumBillDetailCount(array $respArray): int`——解析 `searchbilldetail` 返回：
- 结构：`result.model.bill_chk_in_out_detail_list_d_t_o_list.billchkinoutdetaillistdtolist`，**单药品为关联数组**（键为字段名，如 `physic_name`）、**多药品为列表**（键 0,1,2...）
- 单药品取 `min_pkg_count`，多药品累加各项 `min_pkg_count`
- 单据不存在（`FAIL_BIZ_NO_PAT_INFO`）返回 0

### 5. 数量不符写入 upload_logs

- `response_status = '数量不符'`（新状态值，非成功状态，失败记录页天然可见）
- `source = 'quantity_check'`（新来源值）
- `task_id = 0`、`rq` = 单据日期、`djbh` = 原始单号
- `response` 存数量对比 JSON：`{djbh, rq, expected, actual, sub_bills: [{djbh, count}, ...]}`
- 同步写 JSONL（`logs/api_YYYY-MM-DD.jsonl`），脚本自带 `_writeJsonl` 行写函数（沿用 check_bill_status.php 内同名函数的模式，不改 LogWriter——其语义是 INSERT）

### 6. 判定与异常语义

- 平台总数 == 本地码数 → 正常，不写任何记录
- 不相等（含完全查不到 → actual=0）→ 数量不符
- API 异常（网络/业务错误）→ 跳过该单，计入异常统计并打印错误信息，不写"数量不符"（避免误报）
- **限流熔断**（实测发现：0.5s 间隔连续查询几百次会触发平台 `App Call Limited`，code=7；冷却后可恢复）：识别到该错误时本轮立即停止查询，剩余单据下次运行自动重查（数量对账天然幂等——每次从 SQL Server 全量拉基线重新对比，限流期间未查的单下次自动补上）
- **幂等写入**：同单**同日期**已有 `数量不符` 记录（source=quantity_check）时 UPDATE 更新 response/updated_at 而非重复 INSERT，避免限流重查产生重复告警（rq 过滤防御同单号跨日期）
- 限速 `usleep(1000000)`（1s，比 check_bill_status 的 0.5s 保守——数量对账单次量大）
- 汇总输出：已查数 / 相符 / 数量不符 / 异常 / 限流提示

### 7. 文档同步

CLAUDE.md 更新：架构图 `scripts/` 列表、常用命令新增 `check_quantity.php` 条目、核心数据流"批量查询上传状态"章节旁补充数量对账说明。

## Testing Decisions

- **原则**：只测外部行为（解析函数对真实 API 响应的汇总结果），不测实现细节；不 mock API 调用
- **测试模块**：`ApiClient::sumBillDetailCount()`
- **测试文件**：`tests/quantity_check_test.php`，自包含断言（无框架），对齐现有 `tests/trace_splitter_test.php` 风格：`php tests/quantity_check_test.php` 直接运行，失败非零退出
- **测试数据**：从 `data/msfx.db` 提取真实 `searchbilldetail` 响应样例（响应已存于 upload_logs.response）：
  - 单药品（关联数组结构，min_pkg_count=12）
  - 多药品 2 项（列表结构，sum=15）、多药品 10+ 项（列表结构，如 XSOWMS00998402: 10 项 sum=27）
  - 单据不存在（`FAIL_BIZ_NO_PAT_INFO` → 0）
- **断言**：汇总数与人工核对值一致；不存在单据返回 0；`min_pkg_count` 缺省项按 0 处理

## Out of Scope

- 追溯码级明细对账（`querycodeactive` 逐码查询）——数量对账发现不符后由人工处理
  - **实测结论（2026-08-17）**：`upbill.detail.withcode` 与 `querycodeactive` 均无法返回码级明细：
    - `upbill.detail.withcode` 是收货企业视角（`refEntId` 必须 = `toRefUserId`）；查外部系统上传的单（收货≠自己）报 `FAIL_BIZ_AUTH_ERROR`（收货企业未授权本 AppKey）；查本地上传的入库单（收货=自己）返回 SUCCESS 但无 model 明细（疑似委托业务 `agentRefEntId` 数据域，与 Uploadinoutbill 上传的数据不互通）
    - `querycodeactive` 对真实已上传的码返回 SUCCESS 但无状态列表
    - 如需码级核对：先向阿里健康开放平台确认上述两接口的正确调用方式（可能需轮询/下载机制或新增参数），或让外部系统提供其上传的码清单做本地 diff
- 补传缺码（Web 一键补传 / 自动补传）——定位为"只检查告警"，避免与外部系统并发上传冲突
- 修改 `check_bill_status.php` / LogWriter / 前端页面——零 UI 改动
- 拆分映射表（原始单号 ↔ 子单持久化）——由"逐次查询直到查不到"的运行时策略替代
- 通知渠道（企业微信/邮件等）——当前告警出口为 Web 失败记录页

## Further Notes

- 该检查顺带解决 `check_bill_status.php` 的已知盲区：外部系统拆分上传（`单号_1/2/3`）后原始单号查询返回"信息不存在"、被无限误判"未上传"的场景——数量对账的运行时子单查询能在判定"未上传"前汇总子单数量，达标即不告警
- 每日单量约 350~1000 单（均值 ~650），按 0.5s 限速，单日检查约 5-7 分钟
- `searchbilldetail` 的 `min_pkg_count` 语义经真实数据验证：单药品返回对象、多药品返回数组；字段值即该药品追溯码数量
- 完全未上传与部分缺失统一归入"数量不符"，靠 response 中 `actual=0` 区分
