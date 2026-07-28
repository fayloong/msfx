<?php
/**
 * API: POST /api/manual/import — xlsx 导入批量上传
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

try {
    $spreadsheet = IOFactory::load($tmpFile);
    $worksheet = $spreadsheet->getActiveSheet();
    $rows = $worksheet->toArray();

    if (count($rows) < 2) {
        throw new \Exception('文件中没有数据行');
    }

    // 跳过表头
    $headers = array_map('trim', $rows[0]);
    $dataRows = array_slice($rows, 1);

    $db = Database::getInstance();
    $uploadService = new UploadService();

    $errors = [];
    $successCount = 0;
    $totalRows = count($dataRows);

    foreach ($dataRows as $index => $row) {
        $lineNum = $index + 2; // Excel 行号（从2开始，1是表头）
        $rq = trim($row[0] ?? '');
        $djbh = trim($row[1] ?? '');
        $entName = trim($row[2] ?? '');
        $traceCodes = trim($row[3] ?? '');

        // 跳过全空行
        if (empty($rq) && empty($djbh) && empty($entName) && empty($traceCodes)) {
            continue;
        }

        // 验证
        $rowErrors = [];
        if (empty($rq)) $rowErrors[] = '日期为空';
        if (empty($djbh)) $rowErrors[] = '单号为空';
        if (empty($entName)) $rowErrors[] = '往来单位为为空';
        if (empty($traceCodes)) $rowErrors[] = '追溯码为空';

        if (!empty($rowErrors)) {
            $errors[] = "第 {$lineNum} 行: " . implode(', ', $rowErrors);
            continue;
        }

        // 写入 upload_tasks
        $db->execute(
            "INSERT INTO upload_tasks (rq, djbh, ent_name, trace_codes, status, source, created_at, updated_at) VALUES (?, ?, ?, ?, '等待上传', 'manual', datetime('now','localtime'), datetime('now','localtime'))",
            [$rq, $djbh, $entName, $traceCodes]
        );
        $taskId = $db->lastInsertId();

        try {
            $result = $uploadService->upload([[
                'type' => substr($djbh, 0, 3),
                'rq' => $rq,
                'djbh' => $djbh,
                'ent_name' => $entName,
                'sn' => $traceCodes,
                'task_id' => $taskId,
            ]]);
            $successCount++;
        } catch (\Exception $e) {
            $errors[] = "第 {$lineNum} 行 (${djbh}): " . $e->getMessage();
        }
    }

    echo json_encode([
        'success' => true,
        'total' => $totalRows,
        'success_count' => $successCount,
        'error_count' => count($errors),
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
