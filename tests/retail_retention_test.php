<?php
/**
 * App\RetailRetention 单元测试（自包含断言脚本，无框架依赖）
 *
 * 运行: php tests/retail_retention_test.php
 *
 * 测试目标: 门店数据保留期（平台硬性规定 2 年）的截止日计算——
 *   截止日 = 今天往前推 2 年，**早于**它的单据超期、截止日当天保留。
 *   采集下限（fetch_bills_retail）与清理下限（cleanup_logs）共用这一个函数，
 *   故这里的边界算错会同时错在入口与出口——两处一起把超期数据放进来或删过头。
 */

require __DIR__ . '/../vendor/autoload.php';

use App\RetailRetention;

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

// ---------- 用例 1: 保留年数是平台规定的 2 年 ----------
check('YEARS 恒为 2（平台硬性规定）', RetailRetention::YEARS === 2, '实际: ' . RetailRetention::YEARS);

// ---------- 用例 2: 常规日期——两年前的同一个日子 ----------
$cut = RetailRetention::cutoffDate('2026-09-30');
check('2026-09-30 → 2024-09-30', $cut === '2024-09-30', '实际: ' . $cut);

// ---------- 用例 3: 跨年 ----------
$cut = RetailRetention::cutoffDate('2026-01-15');
check('2026-01-15 → 2024-01-15', $cut === '2024-01-15', '实际: ' . $cut);

// ---------- 用例 4: 月末（31 日在目标年份存在，不溢出） ----------
$cut = RetailRetention::cutoffDate('2026-03-31');
check('2026-03-31 → 2024-03-31', $cut === '2024-03-31', '实际: ' . $cut);

// ---------- 用例 5: 闰日——往前两年没有 2 月 29 日，PHP 溢出到 3 月 1 日 ----------
// 这不是巧合而是性质：溢出方向朝"多删一天"，不会留下正好卡在 2 年零 1 天、平台必拒的单据。
// 若哪天 PHP 改成回落到 2 月 28 日，这条会红——那正是需要重新判断方向的时刻。
$cut = RetailRetention::cutoffDate('2024-02-29');
check('2024-02-29 → 2022-03-01（溢出，方向安全）', $cut === '2022-03-01', '实际: ' . $cut);

// ---------- 用例 6: 截止日当天保留、前一天删除（边界语义） ----------
$cut = RetailRetention::cutoffDate('2026-09-30');
check('rq 早于截止日 → 超期', '2024-09-29' < $cut);
check('rq 等于截止日 → 保留', !('2024-09-30' < $cut));

// ---------- 用例 7: 不传参时相对"今天"滚动 ----------
$cut = RetailRetention::cutoffDate();
check(
    '不传参 = 相对今天（生产调用的形态）',
    $cut === date('Y-m-d', strtotime('-2 years')),
    '实际: ' . $cut
);
check('输出为 YYYY-MM-DD', (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $cut), '实际: ' . $cut);

echo "\n";
if ($failures === 0) {
    echo "全部通过 ✓\n";
    exit(0);
}
echo "失败 $failures 项 ✗\n";
exit(1);
