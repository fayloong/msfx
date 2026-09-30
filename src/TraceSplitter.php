<?php

namespace App;

/**
 * 追溯码拆分器：两种拆法，按不同上限、给不同去处。
 *
 *   splitByCount()      按**码数**拆 —— 上传用（平台接口对单次追溯码个数有上限）
 *   splitByCharLimit()  按**字符数**拆 —— 导出 xlsx 用（Excel 单格硬上限 32767 字符）
 *
 * 为什么导出不能复用按码数的那套：码上放心追溯码为 20 位数字，3500 码/片 ≈ 73500 字符，
 * 远超 Excel 单格上限——严格对齐上传的 3500 码阈值在 xlsx 里物理放不下，故导出以字符数
 * （默认 32000，留余量）为限。
 *
 * 两者的**命名语义一致**：超限时所有分片单号带 _N 后缀（含第一个分片，无裸单号）；
 * 已带后缀的单号再拆时追加后缀（xxx_1 → xxx_1_1…）。
 *
 * 上传的码数上限**按接口区分**（批发 kyt 3500、零售 lsyd.uploadinoutbill 10000 /
 * uploadretail 3500），故 splitByCount 的 limit 由调用方传入——值取自
 * App\Enterprise::route()，本类不硬编码（见 docs/adr/0009、docs/adr/0011）。
 */
class TraceSplitter
{
    /** 默认单格字符上限（Excel 32767，留余量） */
    public const DEFAULT_CHAR_LIMIT = 32000;

    /**
     * 数一串追溯码里有几个码：逗号数 + 1，空白串算 0。
     *
     * 口径曾经散在四个地方（门店清单接口的 SQL、导出的 PHP 助手、页面"码数"列、追溯码弹窗的 JS），
     * 页面上同一行的两处数字因此有各算各的风险——"码数"列说 3、弹窗说 4 会让人以为哪边漏了码。
     * 收在这里，PHP 侧的调用方共用；JS 侧一律用服务端回的 `code_count`，不再自行切串。
     *
     * 空串单独判：`substr_count('', ',') + 1` 会算成 1，而"没有码"与"一个码"不是一回事。
     */
    public static function countCodes(string $traceCodes): int
    {
        $traceCodes = trim($traceCodes);
        return $traceCodes === '' ? 0 : substr_count($traceCodes, ',') + 1;
    }

    /**
     * 按码数拆分追溯码（上传用），返回 [单号 => 追溯码] map。
     *
     * 不超限时**原样返回** [原单号 => 原始字符串]：不做过滤、不改写、不加后缀——
     * 拆单是异常路径，正常单子的单号不该被动（清空与改写码串会直接改变申报内容）。
     * 超限时按逗号 split、过滤空值，每片 $limit 个码，键为 当前单号_N。
     *
     * @param int $limit 该接口的码上限；≤0 视为非法上限（调用方给的一定是正数），不拆
     * @return array<string, string>
     */
    public static function splitByCount(string $billCode, string $traceCodes, int $limit): array
    {
        $codes = array_filter(explode(',', $traceCodes));
        if ($limit <= 0 || count($codes) <= $limit) {
            return [$billCode => $traceCodes];
        }

        $result = [];
        foreach (array_chunk($codes, $limit) as $i => $chunk) {
            $result[$billCode . '_' . ($i + 1)] = implode(',', $chunk);
        }
        return $result;
    }

    /**
     * 按字符数拆分追溯码，返回 [单号 => 追溯码] map。
     *
     * 不超限时原样返回 [原单号 => 原始字符串]（不做过滤/重排，与
     * splitBillCodes 短路行为一致）；超限时按逗号 split、过滤空值、
     * 逐码贪心装填，每片 ≤ $limit（单条码自身超限的极端情况除外，
     * 由调用方 truncateTraceCodes 兜底截断），键为 当前单号_N。
     * 超限但过滤后无码（如纯逗号串）时返回 [当前单号_1 => '']，
     * 保证调用方始终至少产出一行、该单据不从导出中丢失。
     *
     * @return array<string, string>
     */
    public static function splitByCharLimit(string $billCode, string $traceCodes, int $limit = self::DEFAULT_CHAR_LIMIT): array
    {
        if (mb_strlen($traceCodes) <= $limit) {
            return [$billCode => $traceCodes];
        }

        $codes = array_filter(explode(',', $traceCodes));
        if (empty($codes)) {
            return [$billCode . '_1' => ''];
        }
        $result = [];
        $chunk = [];
        $chunkLen = 0;
        $i = 1;

        foreach ($codes as $code) {
            $codeLen = mb_strlen($code);
            // 当前片已非空且再加这个码（含逗号）会超限 → 开新片
            if (!empty($chunk) && $chunkLen + $codeLen + 1 > $limit) {
                $result[$billCode . '_' . $i++] = implode(',', $chunk);
                $chunk = [];
                $chunkLen = 0;
            }
            $chunk[] = $code;
            $chunkLen += $codeLen + (count($chunk) > 1 ? 1 : 0);
        }

        if (!empty($chunk)) {
            $result[$billCode . '_' . $i] = implode(',', $chunk);
        }

        return $result;
    }
}
