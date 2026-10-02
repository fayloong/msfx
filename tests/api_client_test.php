<?php
/**
 * App\ApiClient 单元测试（自包含断言脚本，无框架依赖）
 *
 * 运行: php tests/api_client_test.php
 *
 * 测试目标:
 * 1. isRetryableTopError: 顶层错误码是否属于「调用级、等一会儿重试就能成」的错误。
 *    平台限流（code=7 / msg 含 "App Call Limited"）→ true：走既有重试通道（3 次 / 30 秒），
 *    而不是被判「业务错误、不重试」后翻「已处理」（那不重试的假失败只能人工去失败记录页重传）。
 *    其余顶层错误码（参数错误等）→ false，维持「业务错误不重试」的原行为。
 *    见 .scratch/top-sdk-top-error/spec.md 改动 2。
 * 2. TOP_SDK_WORK_DIR: SDK 工作目录必须被钉在**项目内**（TopLogger 拼 <WORK_DIR>/logs/top_*.log）。
 *    SDK 默认值是 /tmp/，而本机 /tmp/logs 是 root:root 755 —— nginx 用户 fopen 失败 →
 *    getFileHandle() 返回 false → fwrite(false, …) 抛 TypeError，从 TopClient::execute() 抛穿整条
 *    上传链路（真实平台错误被掩盖成一句类型错误）。
 *    src/ 侧有两个入口 require TopSdk.php（ApiClient / RetailRequestAssembler），谁先被自动加载
 *    谁定这个常量——故本文件**先加载装配类**再断言，钉住「与加载顺序无关」这条性质。
 */

require __DIR__ . '/../vendor/autoload.php';

use App\ApiClient;

$failures = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    global $failures;
    if ($cond) {
        echo "PASS  $name\n";
    } else {
        $failures++;
        echo "FAIL  $name  $detail\n";
    }
}

// ================= TOP_SDK_WORK_DIR: 常量注入 =================

// 先加载装配类：它在 ApiClient 之前 require TopSdk.php 时，SDK 会把工作目录定成默认的 /tmp/。
// 本句必须在 class_exists(App\ApiClient::class) 之前——否则常量已由 ApiClient 定好，
// 这条「加载顺序无关」的断言就变成空转。
class_exists(\App\RetailRequestAssembler::class);

$projectRoot = dirname(__DIR__);
$workDir = defined('TOP_SDK_WORK_DIR') ? (string)TOP_SDK_WORK_DIR : '';

check('TOP_SDK_WORK_DIR 已定义', $workDir !== '');
check(
    'TOP_SDK_WORK_DIR 指向项目根（先加载装配类也不变）',
    rtrim($workDir, '/\\') === $projectRoot,
    "实际: {$workDir}"
);
check(
    'SDK 日志目录落在项目 logs/ 下',
    $workDir !== '' && is_dir(rtrim($workDir, '/\\') . '/logs'),
    "实际: {$workDir}"
);

// ================= isRetryableTopError: 顶层错误码可重试判定 =================

// ---------- 真实样例: 平台限流（2026-09-21 /tmp/logs/top_biz_err_32367731_2026-09-21.log 实录） ----------
// <error_response><code>7</code><msg>App Call Limited</msg>
//   <sub_code>accesscontrol.limited-by-app-api-access-count</sub_code>
//   <sub_msg>This ban will last for 2 more seconds</sub_msg></error_response>
check('限流 code=7 → 可重试', ApiClient::isRetryableTopError('7', 'App Call Limited') === true);

// ---------- 边界 1: code 优先——code=7 即判可重试，不看 msg ----------
check('code=7 且 msg 无关 → 可重试', ApiClient::isRetryableTopError('7', '别的消息') === true);

// ---------- 边界 2: msg 兜底——平台若改了 code，含 "App Call Limited" 仍判可重试 ----------
check('msg 兜底（code 变了不失效）', ApiClient::isRetryableTopError('15', 'App Call Limited') === true);
check('msg 兜底大小写不敏感', ApiClient::isRetryableTopError('', 'app call limited') === true);

// ---------- 边界 3: 错误码类型（SimpleXMLElement 强转后是 string，但 int 也要认） ----------
check('int 7 → 可重试', ApiClient::isRetryableTopError(7, '') === true);

// ---------- 边界 4: 业务/参数类顶层错误不重试（维持原行为） ----------
check('参数错误 → 不可重试', ApiClient::isRetryableTopError('11', 'Invalid parameters') === false);
check('code=0 → 不可重试', ApiClient::isRetryableTopError(0, '') === false);
check('空 code 空 msg → 不可重试', ApiClient::isRetryableTopError('', '') === false);
check('null code → 不可重试', ApiClient::isRetryableTopError(null, '') === false);

echo "\n";
if ($failures === 0) {
    echo "全部通过 ✓\n";
    exit(0);
}
echo "失败 $failures 项 ✗\n";
exit(1);
