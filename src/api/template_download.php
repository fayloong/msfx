<?php
/**
 * API: GET /api/template/download — 下载 xlsx 导入模板
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

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// 设置表头
$sheet->setCellValue('A1', '日期');
$sheet->setCellValue('B1', '单号');
$sheet->setCellValue('C1', '单据类型');
$sheet->setCellValue('D1', '往来单位名称');
$sheet->setCellValue('E1', '追溯码');

// 加粗表头
$sheet->getStyle('A1:E1')->getFont()->setBold(true);

// 设置列宽
$sheet->getColumnDimension('A')->setWidth(14);
$sheet->getColumnDimension('B')->setWidth(24);
$sheet->getColumnDimension('C')->setWidth(14);
$sheet->getColumnDimension('D')->setWidth(20);
$sheet->getColumnDimension('E')->setWidth(40);

// 示例数据
$sheet->setCellValue('A2', date('Y-m-d'));
$sheet->setCellValue('B2', 'JHGWMS00060001');
$sheet->setCellValue('C2', '102');
$sheet->setCellValue('D2', '示例单位');
$sheet->setCellValue('E2', '追溯码1');

$sheet->setCellValue('A3', date('Y-m-d'));
$sheet->setCellValue('B3', 'JHGWMS00060001');
$sheet->setCellValue('C3', '102');
$sheet->setCellValue('D3', '示例单位');
$sheet->setCellValue('E3', '追溯码2');

$sheet->setCellValue('A4', date('Y-m-d'));
$sheet->setCellValue('B4', 'JHGWMS00060001');
$sheet->setCellValue('C4', '102');
$sheet->setCellValue('D4', '示例单位');
$sheet->setCellValue('E4', '追溯码3');

// 输出
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="upload_template.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
