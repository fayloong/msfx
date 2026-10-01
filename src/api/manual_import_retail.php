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
use App\RetailRetransmit;

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
            // 拒绝行与真实上传结果**同一个形状**（实现见 RetailRetransmit::rejectedProgress）：
            // 前端只要认一套字段，不必为拒绝路径单写一个渲染分支
            echo json_encode(RetailRetransmit::rejectedProgress($djbh, $company, $e->getMessage()), JSON_UNESCAPED_UNICODE) . "\n";
            flush();
        }
    }

    // 第二遍：逐条落库并上传。汇总按**单据**计，且"成功"取**上传结果**（与在线新增那侧同一个口径）：
    // 落库成功但平台业务拒传（存在已出售的码…）时，页面汇总报"成功"会骗人——那一单并没有传上去
    $successCount = 0;
    foreach ($prepared as $djbh => $bill) {
        try {
            $result = RetailManualEntry::create($bill, $db, function (array $progress) {
                echo json_encode($progress, JSON_UNESCAPED_UNICODE) . "\n";
                flush();
            });
            if ($result['failed'] === 0) {
                $successCount++;
            } else {
                // 拆过单的会在这里报出子单数；未拆单的就是 0/1
                $errors[] = "单号 {$djbh}: 上传未成功（子单成功 {$result['success']} / 失败 {$result['failed']}）";
            }
        } catch (\Exception $e) {
            echo json_encode(RetailRetransmit::rejectedProgress($djbh, $company, $e->getMessage()), JSON_UNESCAPED_UNICODE) . "\n";
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
