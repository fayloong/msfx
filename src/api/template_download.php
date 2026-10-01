<?php
/**
 * API: GET /api/template/download — 下载 xlsx 导入模板
 *
 * `?type=retail` 给门店分支（示例行用门店的两种单据类型，表头注明 321/116 不填往来单位）。
 * 两个模板的**列序完全一致**（日期 | 单号 | 单据类型 | 往来单位名称 | 追溯码），
 * 解析也共用 App\BillSheetParser——模板之间只有示例内容不同，不构成第二种格式。
 *
 * `type` 只影响示例行，且**只认 retail 一个值**：不识别的一律给批发模板（旧链接照旧可用）。
 */

use App\Auth;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

Auth::init();
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$isRetail = ($_GET['type'] ?? '') === 'retail';

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// 设置表头
$sheet->setCellValue('A1', '日期');
$sheet->setCellValue('B1', '单号');
$sheet->setCellValue('C1', '单据类型');
$sheet->setCellValue('D1', $isRetail ? '往来单位名称（321/116 留空）' : '往来单位名称');
$sheet->setCellValue('E1', '追溯码');

// 加粗表头
$sheet->getStyle('A1:E1')->getFont()->setBold(true);

// 设置列宽
$sheet->getColumnDimension('A')->setWidth(14);
$sheet->getColumnDimension('B')->setWidth(24);
$sheet->getColumnDimension('C')->setWidth(14);
$sheet->getColumnDimension('D')->setWidth(20);
$sheet->getColumnDimension('E')->setWidth(40);

// 示例数据：同单号三行（一行一个码，导入时合并成一条单）
// 门店模板用门店的两种单据类型各举一例——321 的往来单位留空（它不需要对手方）、
// 104 的填上（调拨单的 from/to 由它查出）
$samples = $isRetail
    ? [['3210001', '321', ''], ['1040001', '104', '示例单位']]
    : [['JHGWMS00060001', '102', '示例单位']];

$row = 2;
foreach ($samples as [$sampleDjbh, $sampleType, $sampleEnt]) {
    foreach (['追溯码1', '追溯码2', '追溯码3'] as $sampleCode) {
        $sheet->setCellValue('A' . $row, date('Y-m-d'));
        $sheet->setCellValue('B' . $row, $sampleDjbh);
        $sheet->setCellValue('C' . $row, $sampleType);
        $sheet->setCellValue('D' . $row, $sampleEnt);
        $sheet->setCellValue('E' . $row, $sampleCode);
        $row++;
    }
}

// 输出
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . ($isRetail ? 'upload_template_retail' : 'upload_template') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
