# TOP SDK 顶层错误的处理：日志目录不可写导致崩溃、限流被误判为业务失败

**What to build:** 把「平台返回顶层错误码」这条路径处理对。现在有两个毛病，都不在 SDK 里、都在我们自己的适配层：

1. **崩**：SDK 写自己的错误日志失败时抛 `TypeError`，异常抛穿整条上传链路，把真实的平台错误掩盖成一句类型错误；
2. **误判**：限流（`code=7` / `App Call Limited`）被当成「业务错误、不重试」，那张单于是被判失败并翻「已处理」——**不会自动重试，只能人工去失败记录页重传**。

第 2 条比第 1 条严重：第 1 条至少让单留在队列里可重试（虽然日志上看不出为什么），第 2 条是**静默的假失败**——2026-09 的批发链路一直这么跑。

**Blocked by:** None（可立即开始）

**Status:** done——提交 `44040bb`、收口 `6388c8a`，gitee 与 origin 均已跟上

---

## 背景：2026-10-02 批量补传实测踩到的

### 现象

对 4 家门店（宝源/埔前立信/新江/大湖）跑 `scripts/upload_pending_retail.php` 共 2,861 条，其中 4 条被「拒绝」，错误是：

```
[拒绝] 单号 XLSK5600100184473 | 门店 大源堂智慧药房（河源）有限公司埔前立信分店 | 1 码
       —— fwrite(): Argument #1 ($stream) must be of type resource, bool given
```

这 4 条**状态一个字没动**（仍是「等待上传」、无任何日志）——说明异常发生在上传之前，平台那次调用其实已经发出去了（只是被拒）。补跑一遍即全部成功。

### 根因链

1. `TopClient::execute()` 在**两种**情况下会写 SDK 自己的日志：
   - HTTP 响应不是合法 JSON/XML → `logCommunicationError()`（`top_sdk/top/TopClient.php:200-218`）
   - **顶层返回错误码**（`top_sdk/top/TopClient.php:330-338` 的 `isset($respObject->code)`）——**平台限流走这条**
2. 日志路径 = `TOP_SDK_WORK_DIR . '/logs/top_*_err_<appkey>_<date>.log'`，而 `TOP_SDK_WORK_DIR` 在 `top_sdk/TopSdk.php:18` 被定义成 **`/tmp/`**
3. 本机 `/tmp/logs/` 属主是 **root:root 755**（9 月用 root 跑时建的）→ nginx 用户 `fopen` 失败 → `TopLogger::getFileHandle()` 返回 `false` → `fwrite(false, …)` 抛 **TypeError**
4. `src/ApiClient.php:57` 只有 `catch (\Exception $e)`——**`TypeError` 继承自 `\Error`，捕不到** → 异常抛穿 `RetailRetransmit::retransmit()` → 被 `RetailBatchUpload::run()` 的 try/catch 记成「失败/被拒」

### 证据：掩盖掉的真实错误是限流

`/tmp/logs/top_biz_err_32367731_2026-09-21.log` 里全是这个（32367731 是河药那个 appkey）：

```xml
<error_response>
  <code>7</code><msg>App Call Limited</msg>
  <sub_code>accesscontrol.limited-by-app-api-access-count</sub_code>
  <sub_msg>This ban will last for 2 more seconds</sub_msg>
</error_response>
```

所以：**限流的错误码是 `7`，msg 是 `App Call Limited`**，封禁只有一两秒。

而即便日志写得进去（9 月 root 跑时就是如此），还有第二个问题：`ApiClient::execute()` 对顶层 code 一律返回 `is_network_error => false`，于是
- `RetailRetransmit::uploadSingle()` 的 `if ($result['success'] || !$result['is_network_error'])` 直接返回失败，**不重试**；
- `UploadService`（批发，`src/UploadService.php:208` 那句 `// 业务错误不重试`）同理。

限流是典型的「等一两秒就好」，按业务失败处理是错的。

---

## 要改的三处

### 改动 1（治本）：把 SDK 的工作目录指到项目内

**文件**：`src/ApiClient.php`，在 `require_once __DIR__ . '/../top_sdk/TopSdk.php';` **之前**加：

```php
// TOP SDK 的工作目录（TopLogger 用它拼 <WORK_DIR>/logs/top_*_err_*.log）。
// 默认值是 /tmp/（见 top_sdk/TopSdk.php:18），而 /tmp/logs 在本机是 root:root 755——以 nginx
// 用户跑时 fopen 失败 → getFileHandle() 返回 false → fwrite(false, …) 抛 TypeError，从
// TopClient::execute() 抛穿整条上传链路，把真实的平台错误（限流 code=7 / 响应不合法）
// 掩盖成一句类型错误（2026-10-02 实测，2,861 条里踩中 4 次）。
// TopSdk.php 那句是 `if (!defined(...))`，文件头也注明"在 include 之前定义这些常量，
// 不要直接修改本文件"——所以在 require 之前定义即可，不需要动 vendored 的 SDK。
if (!defined('TOP_SDK_WORK_DIR')) {
    define('TOP_SDK_WORK_DIR', dirname(__DIR__));
}
```

效果：SDK 日志落到 `logs/top_comm_err_*.log` / `logs/top_biz_err_*.log`（该目录属主 `nginx:nginx`，可写）。

**配套**：`.gitignore` 加一行 `/logs/top_*.log`（现在只排除了 `*.jsonl` 与 `*.lock`，不加这行 SDK 日志会被 git 跟踪）。

### 改动 2（治本）：限流判为可重试

**文件**：`src/ApiClient.php`。先加一个**纯函数**（便于自包含断言，与 `resolveUploadResponseStatus` 同处）：

```php
/**
 * 顶层错误码是否属于「调用级、等一会儿重试就能成」的那一类。
 *
 * 与 `resolveUploadResponseStatus()` 的区别：那个读的是**业务响应**（success=true + data 里的
 * msg_code），本方法读的是**顶层 code**（网关级错误，SDK 在 TopClient.php:330 专门为它写日志）。
 * 顶层 code 过去一律按「业务错误不重试」处理，但限流是典型的可重试错误——实测封禁只有一两秒
 * （sub_msg: "This ban will last for N more seconds"），而重试间隔是 30 秒。
 * 判据：code=7（App Call Limited）或 msg 含 "App Call Limited"（code 变了也能兜住）。
 *
 * @param mixed $code 顶层错误码（string|int）
 */
public static function isRetryableTopError($code, string $msg): bool
{
    if ((string)$code === '7') {
        return true;
    }
    return stripos($msg, 'App Call Limited') !== false;
}
```

然后 `execute()` 里那一格改成（其余不动）：

```php
if (isset($resp->code) && $resp->code != 0) {
    return [
        'success' => false,
        'data' => $resp,
        'error' => $resp->msg ?? 'Unknown API error',
        'is_network_error' => self::isRetryableTopError($resp->code, (string)($resp->msg ?? '')),
    ];
}
```

效果：限流 → `is_network_error = true` → 走**既有重试通道**（`RetailRetransmit` / `UploadService` 都是 3 次、间隔 30 秒）→ 单不再被误判失败。

### 改动 3（兜底）：`catch (\Exception)` → `catch (\Throwable)`

两处：
- `src/ApiClient.php:57`（`execute()` 里包 cURL 那个）
- `src/UploadService.php:217`（批发链路的重试循环）

理由：`TypeError` 等 `\Error` 不被 `catch (\Exception)` 捕获。改动 1 之后理论上不会再抛，但 SDK 内部任何别的 Error 仍会被漏出去、抛穿整条链路。归为「网络错误（可重试）」比抛穿安全。

---

## 验收

- [x] `src/ApiClient.php` 顶部定义了 `TOP_SDK_WORK_DIR`（在 require TopSdk 之前），值为项目根目录；`.gitignore` 加了 `/logs/top_*.log`
- [x] `isRetryableTopError()` 是纯函数，`execute()` 用它决定 `is_network_error`
- [x] 新增 `tests/api_client_test.php` 自包含断言，至少覆盖：
  - `('7', 'App Call Limited')` → true
  - `('7', '别的消息')` → true（code 优先）
  - `('15', 'App Call Limited')` → true（msg 兜底，code 将来变了不失效）
  - `('11', 'Invalid parameters')` → false
  - `(0, '')` → false
- [x] 以 **nginx 用户**跑一段探针，断言 `TOP_SDK_WORK_DIR` 指向项目根、且能在 `logs/` 下写出 `top_probe_<date>.log`（这是"崩溃已消除"的直接证据）
- [x] 既有离线测试全部跑通（`php tests/*.php` 逐个退出码 0；`search_bill_test.php` / `singlerelation_test.php` 两个探针要调平台，按需）
- [x] `CLAUDE.md`：「top_sdk 的 vendored 约定」那节里记的 TopLogger 坑，从"本次刻意不修"改成"已修"+ 一句话说明改法；业务编码映射那节若有涉及一并同步

## Comments

### 2026-10-02 实现完成——三处改动 + 一处与票面的偏差

**改了 4 个文件 + 1 个新测试**：`src/ApiClient.php`（三处改动全在这）、`src/RetailRequestAssembler.php`（常量守卫，见下"与票面的偏差"）、`src/UploadService.php`（catch Throwable）、`.gitignore`、`tests/api_client_test.php`（新增，12 条断言）。

**与票面的偏差（1 处，已确认是必要的）**：改动 1 的常量守卫**两处都要写**，只写 `ApiClient.php` 会随加载顺序静默失效。实证：写测试时**先加载装配类**再断言，常量实测为 `/tmp/`（`src/` 里有两个文件 require TopSdk.php，`RetailRequestAssembler.php` 也在内——谁先被自动加载谁定这个常量）。故 `RetailRequestAssembler.php` 也加了同样的 `if (!defined(...))` 守卫，`tests/api_client_test.php` 把"先加载装配类"这条顺序钉住。

**验证证据**：
- **探针 A（nginx 身份，`/tmp/probe_workdir.php` 一次性脚本）**：`TOP_SDK_WORK_DIR = /usr/share/nginx/mashangfangxin` ✓、在项目 `logs/` 写出 `top_probe_2026-10-02.log`（未抛异常）✓；对照实验同时**在线复现了旧行为**——同身份写 `/tmp/logs` 报 `TypeError: fwrite(): Argument #1 ($stream) must be of type resource, bool given`（`/tmp/logs` 是 root:root 755，nginx 不可写）。产物留证：`logs/top_probe_2026-10-02.log` 内容 `probe ok … euid=997 uname=nginx`、属主 `nginx:nginx`。（**初版探针那行写的是 `uid=0`**——`getmyuid()` 返回的是**脚本文件属主**、不是当前进程用户，red herring 已纠正后重跑）
- **探针 B（离线桩，`/tmp/top_stub.php` + `/tmp/probe_top_error_e2e.php`，一次调用只打到 127.0.0.1）**：桩返回 `code=7 / App Call Limited` → `ApiClient::execute()` 返回 `is_network_error=true` ✓、`error` 仍是平台真实原因 ✓、SDK 业务错误日志写进项目 `logs/top_biz_err_probe0000001_2026-10-02.log` 且未抛 TypeError ✓、没有落到 `/tmp/logs` ✓。**全程零平台调用**
- **辨别力（逐处改坏 → 对应用例/探针变红，再逐字还原）**：去掉 msg 兜底 → 2 条红；去掉 `code=7` 判据 → 2 条红；去掉装配类的常量守卫 → 常量断言红（实际 `/tmp/`）；`execute()` 那格退回恒 `false` → 探针 B 红
- 13 个离线测试全部退出码 0；`search_bill_test.php` / `singlerelation_test.php` **本轮未跑**（要调平台，且当前 15:25 在 8-20 点限流窗口内——与 `check_bill_status` 并发会触发平台限流，按票面"按需"跳过；改动 2 的接线已由探针 B 端到端覆盖）

**实现时发现、本票未修（2 处，记在这里备查）**：

1. **`error` 存的是 SimpleXMLElement 对象**：顶层错误时 `$resp` 是 `SimpleXMLElement`，`'error' => $resp->msg` 存进去的是**对象**（票面说这一格"其余不动"，故保持原样）。后果：`json_encode($result)` 会写成 `{"error":{"0":"App Call Limited"}}` 而不是字符串——日志/`resp` 列里的形状不干净。下游不炸（`str_contains` 与字符串插值都走 `SimpleXMLElement::__toString`），要修就在那一行加 `(string)`，但那会改变 `resp` 列既有数据的形状，值得单独一票。
2. **文件级残留：混合身份写同一个 appkey 的日志文件**（code-review 的 Spec 轴发现）。改动 1 只把**目录**钉进项目内，而写这个库的有两套身份——**root 的 crontab**（`check_bill_status.php` 等）与 **nginx 的 PHP-FPM**（Web 端上传），两者共用河药那一个 appkey：root 先建出 `logs/top_biz_err_32367731_<日期>.log`（0644 root）后，nginx 侧同日再写同名文件时 `fopen(…, 'a')` 依旧失败，同一个 TypeError 会**按 appkey 逐文件**重演（改动 3 之后不再抛穿链路，但真实原因被掩盖、白重试 3×30 秒）。**根治是统一写日志的身份**——把本项目的 cron 条目改以 nginx 身份跑（或日志文件统一 chgrp/chmod），属运维决定，留给用户；已记进 CLAUDE.md 同一节。

## 注意事项

- **不要改 `top_sdk/`**（vendored 约定，见 CLAUDE.md 与 `docs/adr/0009`）——改动 1 正是 SDK 官方推荐的注入方式，不需要动它
- 常量值用 `dirname(__DIR__)`（= 项目根）而不是硬编码绝对路径
- `/tmp/logs/` 里的旧文件可以留着或清掉，改动后不会再写入那里
- 改动 2 的判据将来若发现别的可重试顶层码（系统繁忙之类），在 `isRetryableTopError` **一处**加，别在调用方各写一份
- 批发链路（`UploadService`）与零售链路共用 `ApiClient`，这次改动**两条链路同时受益**，不必分别改
