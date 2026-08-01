<?php
/**
 * 单据类型码工具：字母前缀与数字码归一化
 */
namespace App;

class BillType
{
    /** 单号前缀 → 数字类型码（兼容 fetch_bills 旧格式） */
    private const PREFIX_MAP = [
        'XSO' => '201',
        'XST' => '103',
        'JHG' => '102',
        'JHO' => '202',
    ];

    /**
     * 归一化为 3 位数字类型码
     * 字母前缀（如 XSO）转数字码；bill_type 缺失时按单号前缀推导；均无法识别返回空串
     */
    public static function normalize(?string $billType, ?string $djbh = ''): string
    {
        $raw = $billType !== null ? strtoupper(trim($billType)) : '';
        if (preg_match('/^\d{3}$/', $raw)) {
            return $raw;
        }
        if (isset(self::PREFIX_MAP[$raw])) {
            return self::PREFIX_MAP[$raw];
        }
        if ($raw === '') {
            $prefix = strtoupper(substr((string)$djbh, 0, 3));
            return self::PREFIX_MAP[$prefix] ?? '';
        }
        return '';
    }
}
