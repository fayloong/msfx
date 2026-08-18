<?php
/**
 * searchbill.detail 接口查询调试工具
 * 用法: php tests/search_bill_test.php <单号> [单号2 ...]
 *
 * 调用 alibaba.alihealth.drug.kyt.searchbill.detail 查询单据在码上放心平台的
 * 上传状态：stdout 打印完整返回，并另存为 searchbill_<单号>.json（tests 目录内）。
 *
 * found 判定（与 ApiClient::isBillFound 一致）：仅 msg_code=FAIL_BIZ_NO_PAT_INFO
 * （信息不存在）视为未上传，其余响应均视为单据在平台存在。
 *
 * 退出码：0=全部查询成功，1=存在网络/业务错误（如 App Call Limited 限流）。
 */

require_once __DIR__ . '/../vendor/autoload.php';

// CLI 环境下 db.php 不在 include_path，提供桩函数
if (!function_exists('info_log')) {
    function info_log(string $title, string $msg = '', string $level = 'INFO', array $data = []): void {
        $ts = date('Y-m-d H:i:s');
        $ctx = $data ? ' ' . json_encode($data, JSON_UNESCAPED_UNICODE) : '';
        fwrite(STDERR, "[{$ts}] [{$level}] {$title}{$msg}{$ctx}\n");
    }
}

use App\ApiClient;
use App\Config;
Config::load();

$billCodes = array_slice($argv, 1);
if (empty($billCodes)) {
    fwrite(STDERR, "用法: php tests/search_bill_test.php <单号> [单号2 ...]\n");
    exit(1);
}

$apiClient = new ApiClient();
$exitCode = 0;

foreach ($billCodes as $billCode) {
    $result = $apiClient->searchBillDetail($billCode);

    $out = [
        'bill_code' => $billCode,
        'found' => $result['found'],
        'error' => $result['error'],
        'response' => $result['response'],
    ];

    $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // 另存为 json 文件（tests 目录内）
    $file = __DIR__ . '/searchbill_' . $billCode . '.json';
    file_put_contents($file, $json);

    echo $json . "\n";
    echo "[saved] {$file}\n";

    // 网络/业务错误（如限流）视为测试失败
    if ($result['error'] !== '') {
        $exitCode = 1;
    }

    usleep(300000); // 300ms 调用间隔，多单号时避免过快
}

exit($exitCode);
