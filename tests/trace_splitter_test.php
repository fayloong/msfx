<?php
/**
 * App\TraceSplitter 单元测试（自包含断言脚本，无框架依赖）
 *
 * 运行: php tests/trace_splitter_test.php
 *
 * 测试目标: 追溯码的两种拆法——
 *   splitByCharLimit(): 导出 xlsx 按字符数拆行（用例 1-10）
 *   splitByCount():     上传按码数拆单（用例 11-15），上限由调用方给
 *                       （批发 kyt 3500、零售 lsyd 10000/3500，见 App\Enterprise::route()）
 * 两者共用的语义:
 *   - 按逗号 split 并过滤空值
 *   - 超限时所有分片单号带 _N 后缀（含第一个分片）
 *   - 已带后缀的单号再拆时追加后缀（xxx_1 → xxx_1_1, xxx_1_2…）
 *   - 不超限时原样返回（不改写、不过滤、不加后缀）
 * 注: splitByCount 原为 UploadService::splitBillCodes（无测试），随工单 06 收进本类以消除
 * 拆单逻辑的第二份实现，故用例 11-15 同时是批发拆单的回归网。
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

// ---------- 用例 11: splitByCount 不超限短路，原样返回 ----------
$codesStr = implode(',', makeCodes(100));
$result = TraceSplitter::splitByCount('JHGWMS001', $codesStr, 3500);
check('按码数：不超限返回 1 片', count($result) === 1, '实际 ' . count($result) . ' 片');
check('按码数：不超限键为原单号', array_key_first($result) === 'JHGWMS001', '键: ' . array_key_first($result));
check('按码数：不超限码原样返回', reset($result) === $codesStr, '值被改写');

// ---------- 用例 12: splitByCount 边界（恰好等于上限不拆，多一个就拆） ----------
$exact = implode(',', makeCodes(250));
check('按码数：恰好 250 码不拆', count(TraceSplitter::splitByCount('JHGWMS001', $exact, 250)) === 1);

$result = TraceSplitter::splitByCount('JHGWMS001', implode(',', makeCodes(251)), 250);
check('按码数：251 码拆 2 片', count($result) === 2, '实际 ' . count($result) . ' 片');
check('按码数：分片命名 单号_1/单号_2', array_keys($result) === ['JHGWMS001_1', 'JHGWMS001_2'], implode(',', array_keys($result)));
check('按码数：首片满 250、尾片 1', substr_count($result['JHGWMS001_1'], ',') + 1 === 250
    && substr_count($result['JHGWMS001_2'], ',') + 1 === 1);

// ---------- 用例 13: splitByCount 多片时码无遗漏无重复 ----------
$result = TraceSplitter::splitByCount('JHGWMS001', implode(',', makeCodes(1000)), 300);
check('按码数：1000 码 / 上限 300 拆 4 片', count($result) === 4, '实际 ' . count($result) . ' 片');
$all = implode(',', array_values($result));
check('按码数：码总数无遗漏无重复', substr_count($all, ',') + 1 === 1000, '合计 ' . (substr_count($all, ',') + 1) . ' 个码');
check('按码数：键依次 _1.._4', array_keys($result) === ['JHGWMS001_1', 'JHGWMS001_2', 'JHGWMS001_3', 'JHGWMS001_4']);

// ---------- 用例 14: splitByCount 过滤空码（10 个真码 + 1 个空码，上限 10 → 不拆） ----------
// 有辨别力：不够滤的话是 11 段 > 10，会拆成 2 片
$codes = makeCodes(10);
$withEmpty = str_replace($codes[5], $codes[5] . ',,', implode(',', $codes)); // code5,,code6
check('按码数：空码被过滤后不拆', count(TraceSplitter::splitByCount('JHGWMS001', $withEmpty, 10)) === 1,
    '实际 ' . count(TraceSplitter::splitByCount('JHGWMS001', $withEmpty, 10)) . ' 片');

// ---------- 用例 15: splitByCount 非法上限（≤0）不拆、不抛 ----------
check('按码数：上限 0 不拆', count(TraceSplitter::splitByCount('JHGWMS001', $exact, 0)) === 1);

echo "\n";
if ($failures === 0) {
    echo "全部通过 ✓\n";
    exit(0);
}
echo "失败 $failures 项 ✗\n";
exit(1);
