<?php
/**
 * API: GET /api/export — 按当前筛选条件导出 xlsx（全量导出，流式生成，内存占用恒定）
 *
 * 参数: type=tasks|uploaded|failed|retail_tasks，其余筛选参数与对应列表 API 完全一致
 *       （retail_tasks＝手动上传页门店分支的补传清单，另需 company，见下）。
 * 实现说明: 不用 PhpSpreadsheet（其 Xlsx Writer 全量驻留内存），改为手工构造 xlsx——
 *          sheet XML 逐行写入临时文件（内存 O(1)），再经 ZipArchive 打包输出。
 */

use App\Auth;
use App\BillType;
use App\Database;
use App\RecordQuery;
use App\TraceSplitter;

Auth::init();
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$type = $_GET['type'] ?? '';
if (!in_array($type, RecordQuery::TYPES, true)) {
    http_response_code(400);
    echo json_encode(['error' => '无效的 type 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 门店补传清单（type=retail_tasks）缺 company 时 build() 会抛——那是**必须**的：没有门店的
// 门店导出是一份"各家混在一起、看不出毛病"的文件。这里把它收成 400 而不是 500 空响应
if ($type === RecordQuery::TYPE_RETAIL_TASKS && trim((string)($_GET['company'] ?? '')) === '') {
    http_response_code(400);
    echo json_encode(['error' => '门店补传清单导出必须指定 company（门店）'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 大导出可能耗时较长，放宽执行时间
set_time_limit(0);

$db = Database::getInstance();
$sqlite = $db->getDb();

// ---------- 筛选条件构建：与三个列表 API 同一份实现（App\RecordQuery） ----------
// 这里曾经是第 4 份拷贝，并且已经漂了一次：失败记录分支漏掉 `source = 'quantity_check' OR`
// 豁免，于是"失败记录页看得见的数量对账告警，导出的 xlsx 里没有"。现在导出与页面走同一段
// 代码，"导出的行数与页面一致"是构造上的性质，不再靠人工核对（见该类注释）。
$query = RecordQuery::build($type, $_GET);
$sql = $query['select'] . ' ' . $query['where'] . ' ' . $query['order'];
$params = $query['params'];

// ---------- 导出列定义（与页面表格列对齐，来源列导出机器值 cron/manual/...） ----------
if ($type === RecordQuery::TYPE_RETAIL_TASKS) {
    // 手动上传页门店分支的补传清单：列与那张表**逐列对齐**（单号/单据日期/单据类型/追溯码/码数/
    // 补传任务创建时间/状态）。页面的"状态"格是"任务状态徽标 + 响应状态徽标"，落进 xlsx 拆成两列
    $columns = [
        '单号' => fn($r) => $r['_piece_bill_code'] ?? ($r['djbh'] ?? ''),
        '单据日期' => fn($r) => $r['rq'] ?? '',
        '单据类型' => fn($r) => BillType::normalize($r['bill_type'] ?? '', $r['djbh'] ?? ''),
        '追溯码' => fn($r) => truncateTraceCodes((string)($r['_piece_trace_codes'] ?? ($r['trace_codes'] ?? ''))),
        '码数' => fn($r) => traceCodeCount((string)($r['_piece_trace_codes'] ?? ($r['trace_codes'] ?? ''))),
        '补传任务创建时间' => fn($r) => $r['created_at'] ?? '',
        '任务状态' => fn($r) => $r['task_status'] ?? '',
        '响应状态' => fn($r) => $r['response_status'] ?? '',
    ];
} elseif ($type === 'tasks') {
    $columns = [
        '单据日期' => fn($r) => $r['rq'] ?? '',
        '单号' => fn($r) => $r['_piece_bill_code'] ?? ($r['djbh'] ?? ''),
        '单据类型' => fn($r) => BillType::normalize($r['bill_type'] ?? '', $r['djbh'] ?? ''),
        '所属企业' => fn($r) => $r['company'] ?? '',
        '往来单位' => fn($r) => $r['ent_name'] ?? '',
        '追溯码' => fn($r) => truncateTraceCodes((string)($r['_piece_trace_codes'] ?? ($r['trace_codes'] ?? ''))),
        '来源' => fn($r) => $r['source'] ?? '',
        '任务状态' => fn($r) => $r['task_status'] ?? '',
        '响应状态' => fn($r) => $r['response_status'] ?? '',
        '任务创建时间' => fn($r) => $r['created_at'] ?? '',
        '最后更新时间' => fn($r) => $r['updated_at'] ?? '',
    ];
} else {
    // 状态列与页面渲染一致：请求失败显示"请求失败"，否则显示响应状态
    $statusFn = $type === 'uploaded'
        ? fn($r) => $r['response_status'] ?: '成功'
        : fn($r) => ($r['request_status'] ?? '') === '请求失败' ? '请求失败' : ($r['response_status'] ?: '失败');
    $columns = [
        '单据日期' => fn($r) => $r['rq'] ?? '',
        '单号' => fn($r) => $r['_piece_bill_code'] ?? ($r['djbh'] ?? ''),
        '单据类型' => fn($r) => BillType::normalize($r['t_bill_type'] ?? '', $r['djbh'] ?? ''),
        '所属企业' => fn($r) => $r['company'] ?? '',
        '往来单位' => fn($r) => $r['ent_name'] ?? '',
        '追溯码' => fn($r) => truncateTraceCodes((string)($r['_piece_trace_codes'] ?? ($r['trace_codes'] ?? ''))),
        '关联任务ID' => fn($r) => ($r['task_id'] ?? 0) ?: '',
        '来源' => fn($r) => $r['source'] ?? '',
        '任务创建时间' => fn($r) => $r['created_at'] ?? '',
        '最后更新时间' => fn($r) => $r['updated_at'] ?? '',
        '状态' => $statusFn,
        'API 返回详情' => fn($r) => truncateCell((string)($r['response'] ?? '')),
    ];
}

// ---------- 辅助函数 ----------
/** xlsx 单格文本上限 32767 字符，超限截断并追加省略标记；值取自 TraceSplitter 保持单一来源 */
const CELL_TEXT_LIMIT = TraceSplitter::DEFAULT_CHAR_LIMIT;

/**
 * 追溯码截断兜底：正常路径已由 TraceSplitter 按 32000 字符拆行，单格不会超限；
 * 仅在极端情况（单条追溯码自身超 32000 字符，理论不触发）下截断并提示码总数。
 */
function truncateTraceCodes(string $value): string
{
    if (mb_strlen($value) <= CELL_TEXT_LIMIT) {
        return $value;
    }
    $count = substr_count($value, ',') + 1;
    return mb_substr($value, 0, CELL_TEXT_LIMIT) . '…(共' . $count . '个码)';
}

/**
 * 码数（门店补传清单的"码数"列）：逗号数 +1，空串算 0——与页面那列同口径。
 * 取值用拆行后的 `_piece_trace_codes`：追溯码超 32000 字符被拆成多行时，
 * 每行写的是**本行**的码数，与它自己那格追溯码对得上（而不是两行都写总数）。
 */
function traceCodeCount(string $value): int
{
    $value = trim($value);
    return $value === '' ? 0 : substr_count($value, ',') + 1;
}

function truncateCell(string $value): string
{
    if (mb_strlen($value) <= CELL_TEXT_LIMIT) {
        return $value;
    }
    return mb_substr($value, 0, CELL_TEXT_LIMIT) . '…(已截断)';
}

/** 转义 XML 特殊字符，并剔除 XML 1.0 不允许的控制字符（否则 Excel 报文件损坏） */
function xmlEscape(string $value): string
{
    $value = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** 列序号转 Excel 列字母: 1=>A, 26=>Z, 27=>AA */
function colLetter(int $n): string
{
    $s = '';
    while ($n > 0) {
        $n--;
        $s = chr(65 + ($n % 26)) . $s;
        $n = intdiv($n, 26);
    }
    return $s;
}

/** 生成一行 XML（inlineStr 类型，避免维护 sharedStrings） */
function rowXml(int $rowNum, array $cells): string
{
    $out = '<row r="' . $rowNum . '">';
    foreach ($cells as $i => $value) {
        $out .= '<c r="' . colLetter($i + 1) . $rowNum . '" t="inlineStr"><is><t xml:space="preserve">'
            . xmlEscape((string)$value) . '</t></is></c>';
    }
    return $out . '</row>';
}

// ---------- 流式写 sheet XML（逐行 fwrite，内存 O(1)） ----------
$tmpSheet = tempnam(sys_get_temp_dir(), 'xlsx_sheet_');
$fh = fopen($tmpSheet, 'w');
fwrite($fh, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n");
fwrite($fh, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');

fwrite($fh, rowXml(1, array_keys($columns)));

$stmt = $sqlite->prepare($sql);
foreach ($params as $i => $value) {
    $stmt->bindValue($i + 1, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
}
$result = $stmt->execute();
$rowNum = 2;
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    // 追溯码按字符数拆行（超 32000 字符时一单多行，单号加 _N 后缀，对齐上传拆分命名）
    $pieces = TraceSplitter::splitByCharLimit(
        (string)($row['djbh'] ?? ''),
        (string)($row['trace_codes'] ?? '')
    );
    foreach ($pieces as $pieceBillCode => $pieceCodes) {
        $row['_piece_bill_code'] = $pieceBillCode;
        $row['_piece_trace_codes'] = $pieceCodes;
        $cells = [];
        foreach ($columns as $fn) {
            $cells[] = $fn($row);
        }
        fwrite($fh, rowXml($rowNum++, $cells));
    }
}
fwrite($fh, '</sheetData></worksheet>');
fclose($fh);
$stmt->close();

// ---------- 打包 xlsx（最小文件结构） ----------
$tmpZip = tempnam(sys_get_temp_dir(), 'xlsx_');
$zip = new ZipArchive();
$zip->open($tmpZip, ZipArchive::OVERWRITE);

$zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML);

$zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML);

$zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>
</workbook>
XML);

$zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML);

$zip->addFile($tmpSheet, 'xl/worksheets/sheet1.xml');
$zip->close();

// ---------- 输出 ----------
$fileNames = [
    'tasks' => ['上传任务', 'upload_tasks'],
    'uploaded' => ['已上传', 'uploaded'],
    'failed' => ['失败记录', 'failed'],
    RecordQuery::TYPE_RETAIL_TASKS => ['门店补传', 'retail_tasks'],
];
[$cnName, $enName] = $fileNames[$type];
$cnFile = $cnName . '_' . date('Y-m-d') . '.xlsx';
$enFile = $enName . '_' . date('Y-m-d') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $enFile . '"; filename*=UTF-8\'\'' . rawurlencode($cnFile));
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: max-age=0');
readfile($tmpZip);

unlink($tmpZip);
unlink($tmpSheet);
exit;
