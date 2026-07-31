<?php
/**
 * API: POST /api/manual/import — xlsx 导入批量上传
 * 支持两种格式：
 *   1. 一行一个单据（传统格式）：追溯码在同一行用逗号分隔
 *   2. 一行一个追溯码（新格式）：同单号的行自动合并
 *
 * xlsx 列: 日期 | 单号 | 单据类型 | 往来单位名称 | 追溯码
 */

use App\Auth;
use App\Database;
use App\UploadService;
use PhpOffice\PhpSpreadsheet\IOFactory;

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

$tmpFile = $_FILES['file']['tmp_name'];

$allowedBillTypes = [
    '102', '103', '104', '107', '108', '110', '111', '112', '113',
    '201', '202', '203', '204', '205', '206', '207', '209', '211', '212', '214', '215', '216', '217', '237',
];

// NDJSON 流式输出
header('Content-Type: application/x-ndjson; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache');
ini_set('output_buffering', 'off');
while (ob_get_level()) { ob_end_clean(); }
ob_implicit_flush(true);

try {
    $spreadsheet = IOFactory::load($tmpFile);
    $worksheet = $spreadsheet->getActiveSheet();
    $rows = $worksheet->toArray();

    if (count($rows) < 2) {
        throw new \Exception('文件中没有数据行');
    }

    // 跳过表头
    $dataRows = array_slice($rows, 1);

    // 第一遍：按单号分组合并
    $groups = []; // djbh => ['rq' => ..., 'bill_type' => ..., 'ent_name' => ..., 'codes' => [], 'lines' => []]
    $lineErrors = [];

    foreach ($dataRows as $index => $row) {
        $lineNum = $index + 2;
        $rq = trim($row[0] ?? '');
        $djbh = trim($row[1] ?? '');
        $billType = trim($row[2] ?? '');
        $entName = trim($row[3] ?? '');
        $traceCodes = trim($row[4] ?? '');
        // 支持一行一个追溯码，自动转换为逗号分隔
        $traceCodes = preg_replace('/\r\n|\r/', "\n", $traceCodes);
        $traceCodes = preg_replace('/\n+/', ',', $traceCodes);
        $traceCodes = trim($traceCodes, ',');

        // 跳过全空行
        if (empty($rq) && empty($djbh) && empty($billType) && empty($entName) && empty($traceCodes)) {
            continue;
        }

        if (empty($djbh)) {
            $lineErrors[] = "第 {$lineNum} 行: 单号为空，无法分组";
            continue;
        }

        if (!isset($groups[$djbh])) {
            $groups[$djbh] = [
                'rq' => '',
                'bill_type' => '',
                'ent_name' => '',
                'codes' => [],
                'lines' => [],
            ];
        }

        // 取第一个非空的日期、单据类型和往来单位
        if (empty($groups[$djbh]['rq']) && !empty($rq)) {
            $groups[$djbh]['rq'] = $rq;
        }
        if (empty($groups[$djbh]['bill_type']) && !empty($billType)) {
            $groups[$djbh]['bill_type'] = $billType;
        }
        if (empty($groups[$djbh]['ent_name']) && !empty($entName)) {
            $groups[$djbh]['ent_name'] = $entName;
        }

        if (!empty($traceCodes)) {
            $groups[$djbh]['codes'][] = $traceCodes;
        }
        $groups[$djbh]['lines'][] = $lineNum;
    }

    // 验证分组
    $groupErrors = [];
    foreach ($groups as $djbh => $group) {
        $lineStr = '第 ' . implode('、', $group['lines']) . ' 行';
        if (empty($group['rq'])) {
            $groupErrors[] = "单号 {$djbh}（{$lineStr}）: 日期为空";
        }
        if (empty($group['bill_type'])) {
            $groupErrors[] = "单号 {$djbh}（{$lineStr}）: 单据类型为空";
        } elseif (!in_array($group['bill_type'], $allowedBillTypes, true)) {
            $groupErrors[] = "单号 {$djbh}（{$lineStr}）: 无效的单据类型 \"{$group['bill_type']}\"";
        }
        if (empty($group['ent_name'])) {
            $groupErrors[] = "单号 {$djbh}（{$lineStr}）: 往来单位为空";
        }
        if (empty($group['codes'])) {
            $groupErrors[] = "单号 {$djbh}（{$lineStr}）: 追溯码为空";
        }
    }

    $errors = array_merge($lineErrors, $groupErrors);

    // 输出验证错误
    if (!empty($errors)) {
        foreach ($errors as $err) {
            echo json_encode(['_error' => $err], JSON_UNESCAPED_UNICODE) . "\n";
            flush();
        }
    }

    // 第二遍：逐个单据上传
    $db = Database::getInstance();
    $uploadService = new UploadService();

    $successCount = 0;
    $totalGroups = count($groups);

    foreach ($groups as $djbh => $group) {
        // 跳过验证失败的
        if (empty($group['rq']) || empty($group['bill_type']) || empty($group['ent_name']) || empty($group['codes'])) {
            continue;
        }
        if (!in_array($group['bill_type'], $allowedBillTypes, true)) {
            continue;
        }

        $allCodes = implode(',', $group['codes']);

        // 写入 upload_tasks
        $db->execute(
            "INSERT INTO upload_tasks (rq, djbh, ent_name, trace_codes, bill_type, task_status, source, created_at, updated_at) VALUES (?, ?, ?, ?, ?, '等待上传', 'manual', datetime('now','localtime'), datetime('now','localtime'))",
            [$group['rq'], $djbh, $group['ent_name'], $allCodes, $group['bill_type']]
        );
        $taskId = $db->lastInsertId();

        try {
            $result = $uploadService->upload([[
                'type' => $group['bill_type'],
                'rq' => $group['rq'],
                'djbh' => $djbh,
                'ent_name' => $group['ent_name'],
                'sn' => $allCodes,
                'task_id' => $taskId,
                'source' => 'manual',
            ]], function (array $progress) {
                echo json_encode($progress, JSON_UNESCAPED_UNICODE) . "\n";
                flush();
            });
            $successCount++;
        } catch (\Exception $e) {
            echo json_encode(['djbh' => $djbh, 'ent_name' => $group['ent_name'], 'success' => false, 'response_status' => '请求失败', 'response' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n";
            flush();
            $errors[] = "单号 {$djbh}: " . $e->getMessage();
        }
    }

    echo json_encode([
        '_final' => true,
        'success' => true,
        'total' => $totalGroups,
        'success_count' => $successCount,
        'error_count' => count($errors),
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE) . "\n";

} catch (\Exception $e) {
    echo json_encode(['_final' => true, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n";
}
