<?php
/**
 * API: POST /api/manual/import_retail — 门店 xlsx 批量导入并上传
 *
 * xlsx 列与批发同一套：日期 | 单号 | 单据类型 | 往来单位名称 | 追溯码
 * （同单号多行自动合并为一条、一行一个码也认——读表与分组在 App\BillSheetParser，与批发共用）。
 *
 * 与批发的差别只有口径：
 *   - `company` 由表单给，必须是配置里的零售企业（落库主体是它，不是河药）
 *   - 单据类型只认门店那四种（104/203/321/116）
 *   - `往来单位名称` **只对 104/203 必填**（321/116 留空即可）
 *   - 逐条校验 + 逐条隔离：一条坏单只报它自己，其余照常导入（与批发同款）
 *
 * 入参：multipart — file（xlsx）+ company（门店名）
 */

use App\Auth;
use App\BillSheetParser;
use App\Database;
use App\RetailManualEntry;

Auth::init();
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => '文件上传失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

$company = trim($_POST['company'] ?? '');

// NDJSON 流式输出
header('Content-Type: application/x-ndjson; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache');
ini_set('output_buffering', 'off');
while (ob_get_level()) { ob_end_clean(); }
ob_implicit_flush(true);

/**
 * 与真实上传结果同形状的一行（前端只认一套字段）：被拒的单据也走这条渲染路径。
 */
$rejectLine = function (string $djbh, string $company, string $message): array {
    return [
        'djbh' => $djbh,
        'ent_name' => '',
        'company' => $company,
        'success' => false,
        'request_status' => '请求失败',
        'response_status' => null,
        'response' => json_encode(['error' => $message], JSON_UNESCAPED_UNICODE),
    ];
};

try {
    $parsed = BillSheetParser::parse($_FILES['file']['tmp_name']);
    $groups = $parsed['groups'];
    $errors = $parsed['errors'];

    $db = Database::getInstance();

    // 第一遍：逐条校验 + 解析对手方（平台查询只读）。失败的逐行报出并**跳过**，
    // 一条坏单不该让整批停摆——与批发导入同一条取舍
    $prepared = [];
    foreach ($groups as $djbh => $group) {
        $lineStr = BillSheetParser::lineLabel($group);
        try {
            $prepared[$djbh] = RetailManualEntry::prepare([
                'company' => $company,
                'rq' => $group['rq'],
                'djbh' => $djbh,
                'bill_type' => $group['bill_type'],
                'ent_name' => $group['ent_name'],
                'trace_codes' => implode(',', $group['codes']),
            ]);
        } catch (\RuntimeException $e) {
            // 消息本身已经带单号（校验在 RetailManualEntry 里，单条入口也靠它指认），
            // 这里只补上**行号**——xlsx 的错要能指回表格里的哪几行
            $errors[] = "{$lineStr}: " . $e->getMessage();
            echo json_encode($rejectLine($djbh, $company, $e->getMessage()), JSON_UNESCAPED_UNICODE) . "\n";
            flush();
        }
    }

    // 第二遍：逐条落库并上传
    $successCount = 0;
    foreach ($prepared as $djbh => $bill) {
        try {
            RetailManualEntry::create($bill, $db, function (array $progress) {
                echo json_encode($progress, JSON_UNESCAPED_UNICODE) . "\n";
                flush();
            });
            $successCount++;
        } catch (\Exception $e) {
            echo json_encode($rejectLine($djbh, $company, $e->getMessage()), JSON_UNESCAPED_UNICODE) . "\n";
            flush();
            $errors[] = "单号 {$djbh}: " . $e->getMessage();
        }
    }

    echo json_encode([
        '_final' => true,
        'success' => true,
        'total' => count($groups),
        'success_count' => $successCount,
        'error_count' => count($errors),
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Exception $e) {
    echo json_encode(['_final' => true, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n";
}
