<?php

namespace App;

/**
 * 追溯码拆行器：导出 xlsx 时把超长追溯码按字符数拆成多行。
 *
 * 为什么按字符数而不是按码数：码上放心追溯码为 20 位数字，上传拆分的
 * 3500 码/片 ≈ 73500 字符，远超 Excel 单格硬上限 32767 字符——严格对齐
 * UploadService::splitBillCodes 的 3500 码阈值在 xlsx 里物理放不下，
 * 故导出拆行以字符数（默认 32000，留余量）为限。
 *
 * 命名语义对齐 splitBillCodes：超限时所有分片单号带 _N 后缀（含第一个
 * 分片，无裸单号）；已带后缀的单号再拆时追加后缀（xxx_1 → xxx_1_1…）。
 */
class TraceSplitter
{
    /** 默认单格字符上限（Excel 32767，留余量） */
    public const DEFAULT_CHAR_LIMIT = 32000;

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
