# 03 - 【排雷】upload_pending.php 企业过滤

- Type: task
- Status: ready-for-agent
- 关联：spec.md §8、docs/adr/0007-retail-no-platform-reconciliation.md

## 问题

`scripts/upload_pending.php:35` 按 `task_status = '等待上传'` 取**全部**任务，无企业过滤；第 61 行 `new UploadService()` 凭据写死河药、请求类写死 kyt。零售单据一旦以 `等待上传` 落库，该 cron 会**用河药 AppKey 把门店单据申报到河药主体名下**——传到平台上的不可逆错误。

**这是本 feature 中唯一一个"上线即可能造成生产数据错误"的点，优先级最高。**

## 实现

- 零售单据落库用 `待补传`（不要用 `等待上传`）——依赖 02
- `upload_pending.php` 取数口径加 **白名单**过滤：`AND company = '河药医药（河源）有限公司'`（用户 2026-09-29 定案；排除法 `source != 'retail'` 被否——它 fail-open，将来任何新增来源漏改条件就是把门店单据报到河药名下）。同时更新文件头注释里"处理所有来源"的说明
- `UploadService` 按任务所属企业取凭据与请求类，并加**兜底断言**：按 `company` 取不到凭据就抛错拒绝上传，**不回落到默认凭据**——不能只靠 cron 的 SQL 写对，将来任何脚本直接 `new UploadService()` 都会绕过它。依赖 01，`App\Enterprise::route($company, $billType)` 已就绪（返回请求类 + 码上限）

## 验收

造一条零售任务（`待补传`）后运行 `upload_pending.php`，确认未被取走、未产生任何 upload_logs；批发任务照常上传
