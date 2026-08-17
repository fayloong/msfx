<?php
/**
 * App\TraceSplitter 单元测试（自包含断言脚本，无框架依赖）
 *
 * 运行: php tests/trace_splitter_test.php
 *
 * 测试目标: 导出 xlsx 时追溯码按字符数拆行的行为。
 * 拆分语义对齐 UploadService::splitBillCodes:
 *   - 按逗号 split 并过滤空值
 *   - 超限时所有分片单号带 _N 后缀（含第一个分片）
 *   - 已带后缀的单号再拆时追加后缀（xxx_1 → xxx_1_1, xxx_1_2…）
 */

require __DIR__ . '/../vendor/autoload.php';

use App\TraceSplitter;

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

/** 生成 n 个唯一 20 位数字追溯码 */
function makeCodes(int $n): array
{
    $codes = [];
    for ($i = 0; $i < $n; $i++) {
        $codes[] = str_pad((string)$i, 20, '8', STR_PAD_LEFT);
    }
    return $codes;
}

// ---------- 用例 1: 不超限短路，原样返回 ----------
$codes = makeCodes(100);
$codesStr = implode(',', $codes);
$result = TraceSplitter::splitByCharLimit('JHGWMS001', $codesStr);
check('不超限返回 1 片', count($result) === 1, '实际 ' . count($result) . ' 片');
check('不超限键为原单号', array_key_first($result) === 'JHGWMS001', '键: ' . array_key_first($result));
check('不超限码原样返回', reset($result) === $codesStr, '值被改写');

// ---------- 用例 2: 空字符串 ----------
$result = TraceSplitter::splitByCharLimit('JHGWMS001', '');
check('空字符串返回 1 片', count($result) === 1);
check('空字符串键为原单号', array_key_first($result) === 'JHGWMS001');
check('空字符串值为空', reset($result) === '');

// ---------- 用例 3: 超限拆多片，码完整覆盖 ----------
$codes = makeCodes(2000); // 2000 码 ≈ 42000 字符 > 32000
$codesStr = implode(',', $codes);
$result = TraceSplitter::splitByCharLimit('JHGWMS001', $codesStr);
check('2000 码拆为 2 片', count($result) === 2, '实际 ' . count($result) . ' 片');
check('第一片键为 单号_1', array_keys($result) === ['JHGWMS001_1', 'JHGWMS001_2'], implode(',', array_keys($result)));
foreach ($result as $pieceBillCode => $pieceCodes) {
    check("$pieceBillCode 不超限", mb_strlen($pieceCodes) <= 32000, mb_strlen($pieceCodes) . ' 字符');
    check("$pieceBillCode 无空码", strpos($pieceCodes, ',,' ) === false && !str_starts_with($pieceCodes, ',') && !str_ends_with($pieceCodes, ','));
}
$all = implode(',', array_values($result));
$original = array_values(makeCodes(2000));
$missing = [];
foreach ($original as $i => $c) {
    if (!str_contains($all, $c)) {
        $missing[] = $i;
    }
}
check('码无遗漏', empty($missing), '遗漏下标: ' . implode(',', $missing));
check('码总数无遗漏无重复', substr_count($all, ',') + 1 === 2000, '拆片合计 ' . (substr_count($all, ',') + 1) . ' 个码');

// ---------- 用例 4: 边界 32000/32001 ----------
$codes = makeCodes(1523); // 1523 码 = 1523*21-1 = 31982 字符 ≤ 32000
$codesStr = implode(',', $codes);
$result = TraceSplitter::splitByCharLimit('JHGWMS001', $codesStr);
check('31982 字符不拆', count($result) === 1, '实际 ' . count($result) . ' 片');

$codes = makeCodes(1524); // 1524 码 = 1524*21-1 = 32003 字符 > 32000
$codesStr = implode(',', $codes);
$result = TraceSplitter::splitByCharLimit('JHGWMS001', $codesStr);
check('32003 字符拆 2 片', count($result) === 2, '实际 ' . count($result) . ' 片');

// ---------- 用例 5: 已带后缀单号再拆，追加后缀 ----------
$codes = makeCodes(3500); // 上传分片: 3500 码 ≈ 73500 字符
$codesStr = implode(',', $codes);
$result = TraceSplitter::splitByCharLimit('JHGWMS001_1', $codesStr);
check('分片单号再拆为 3 片', count($result) === 3, '实际 ' . count($result) . ' 片');
check('追加后缀命名', array_keys($result) === ['JHGWMS001_1_1', 'JHGWMS001_1_2', 'JHGWMS001_1_3'], implode(',', array_keys($result)));
foreach ($result as $pieceCodes) {
    check('再拆片不超限', mb_strlen($pieceCodes) <= 32000, mb_strlen($pieceCodes) . ' 字符');
}

// ---------- 用例 6: 空码过滤（拆分时） ----------
// 1524 码中间插空码，拆分后空码消失且计数不变
$codes = makeCodes(1524);
$codesStr = implode(',', $codes);
$codesStrWithEmpty = str_replace($codes[500], $codes[500] . ',,', $codesStr); // "code500,,code501"
$result = TraceSplitter::splitByCharLimit('JHGWMS001', $codesStrWithEmpty);
$total = array_sum(array_map(fn($c) => substr_count($c, ',') + 1, array_values($result)));
check('空码被过滤', $total === 1524, '合计 ' . $total . ' 个码');

// ---------- 用例 7: 尾片不截断（不足 32000 也完整保留） ----------
$result = TraceSplitter::splitByCharLimit('JHGWMS001', implode(',', makeCodes(2000)));
$last = end($result);
check('尾片完整保留', substr_count($last, ',') + 1 === 2000 - 1523, '尾片 ' . (substr_count($last, ',') + 1) . ' 个码');

// ---------- 用例 8: 与其他列无关，只拆追溯码 ----------
$codes = makeCodes(2000);
$result = TraceSplitter::splitByCharLimit('JHGWMS001', implode(',', $codes));
foreach ($result as $pieceBillCode => $pieceCodes) {
    check("$pieceBillCode 单号不含逗号", !str_contains($pieceBillCode, ','));
}

// ---------- 用例 9: 超限但过滤后无码（纯逗号串），兜底输出一行不丢单 ----------
$emptyStr = str_repeat(',', 32001); // >32000 字符，过滤后 0 个码
$result = TraceSplitter::splitByCharLimit('JHGWMS001', $emptyStr);
check('纯逗号超长串兜底 1 片', count($result) === 1, '实际 ' . count($result) . ' 片');
check('纯逗号超长串键为 单号_1', array_key_first($result) === 'JHGWMS001_1', '键: ' . array_key_first($result));
check('纯逗号超长串值为空', reset($result) === '');

// ---------- 用例 10: 自定义 limit 参数生效 ----------
$codes = makeCodes(100); // 100 码 = 2099 字符
$codesStr = implode(',', $codes);
$result = TraceSplitter::splitByCharLimit('JHGWMS001', $codesStr, 1000);
check('自定义 limit=1000 拆为 3 片', count($result) === 3, '实际 ' . count($result) . ' 片');
foreach ($result as $pieceCodes) {
    check('自定义 limit 每片不超限', mb_strlen($pieceCodes) <= 1000, mb_strlen($pieceCodes) . ' 字符');
}

echo "\n";
if ($failures === 0) {
    echo "全部通过 ✓\n";
    exit(0);
}
echo "失败 $failures 项 ✗\n";
exit(1);
