<?php
/**
 * App\LogSource 单元测试（自包含断言脚本，无框架依赖）
 *
 * 运行: php tests/log_source_test.php
 *
 * 测试目标: 「来源」词表——`upload_tasks.source` / `upload_logs.source` 的取值怎么给人看。
 *   这个类存在的理由是"一处改、处处生效"：收口前三份 JS map 各写各的、键集互不相同，
 *   数量对账（quantity_check）三份都没有，于是告警在失败记录页直出机器值、按来源也筛不出来。
 *
 *   故这里钉的不是"标签文案长什么样"（那是随时可改的），而是几条**漏了就会出事**的性质：
 *
 *   - **已知取值清单与词表互为子集**（用例 1）：给词表加一个取值却没写下它从哪来 → 红；
 *     代码里新写一个取值而词表漏了它的标签（页面上直出机器值）→ 由用例 4 的常量对账兜住
 *   - **标签不是机器值本身**（用例 3）：占位式的 `'foo' => 'foo'` 看起来"有标签"实则没翻译 → 红
 *   - **标签互不重复**（用例 3）：两个取值同一个标签，筛选下拉里就是两个分不清的选项 → 红
 *   - **每个取值都有徽标色、标签非空**（用例 2）：缺一个，那一行就渲染成兜底灰／空白选项 → 红
 *   - **回落不吞数据**（用例 5）：未知取值回落原值、历史空串回落空串（与改动前逐字一致）
 *
 * 两个日志页与任务页的视图、以及导出**不进本测试**：它们只做渲染，口径全在这个类里
 * （票 01 的补充约束：测试接缝只有词表这一个）。
 */

require __DIR__ . '/../vendor/autoload.php';

use App\LogSource;
use App\RetailManualEntry;
use App\RetailRetransmit;

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

/**
 * 已知来源取值 => 从哪来。**这份清单要与 LogSource 的 MAP 互为子集**：
 * 多一项说明词表里有来路不明的取值，少一项说明某个取值没标签。
 */
$known = [
    'cron' => 'fetch_bills.php（批发采集落库）',
    'manual' => '手动上传（manual_create / manual_import）与门店手工建单（RetailManualEntry::LOG_SOURCE）',
    'batch_check' => 'check_bill_status.php / check_failed_logs.php（批量核查）',
    'batch_retry' => '批量重传（tasks_retry / tasks_batch_retry）',
    'retail' => 'fetch_bills_retail.php（零售采集）——只进任务表',
    'retail_retry' => 'RetailRetransmit::SOURCE（门店补传）',
    'retail_external' => '采集分流出的外部上传记录（见 .scratch/retail-collection-split/spec.md §4）',
    'quantity_check' => 'check_quantity.php（数量对账）——只进日志表',
];

$labels = LogSource::labels();
$badges = LogSource::badges();

// ---------- 用例 1: 已知取值与词表互为子集 ----------
$missing = array_diff(array_keys($known), array_keys($labels));
check('每个已知来源取值都有中文标签', $missing === [], '漏了: ' . implode(', ', $missing));

$stray = array_diff(array_keys($labels), array_keys($known));
check(
    '词表里没有来路不明的取值（新增取值时必须同时写进本测试的 $known）',
    $stray === [],
    '多出: ' . implode(', ', $stray)
);

// ---------- 用例 2: 每个取值都有徽标色、标签非空（下拉与徽标都不出空档） ----------
check('每个取值都有徽标色（顺序与标签表一一对应）', array_keys($badges) === array_keys($labels));
check(
    '每个取值的标签非空（下拉里不会出现空白选项）',
    count(array_filter($labels, fn($l) => trim($l) !== '')) === count($labels)
);

// ---------- 用例 3: 标签是翻译过的中文、且互不重复 ----------
$untranslated = array_filter($labels, fn($label, $value) => $label === $value, ARRAY_FILTER_USE_BOTH);
check('标签不是机器值本身（占位式标签等于没翻译）', $untranslated === [],
    '原样返回: ' . implode(', ', array_keys($untranslated)));

check(
    '标签互不重复（重复了筛选下拉里就是两个分不清的选项）',
    count(array_unique($labels)) === count($labels)
);

// ---------- 用例 4: 代码里的来源常量必须落在词表里 ----------
// 这条是"词表漏一个取值"的真闸门：常量改名/改值而词表没跟上 → 红。
// 直接写死 'retail_retry' 的话，改了常量本测试照样绿。
check('RetailRetransmit::SOURCE 在词表里', isset($labels[RetailRetransmit::SOURCE]), RetailRetransmit::SOURCE);
check('RetailManualEntry::LOG_SOURCE 在词表里', isset($labels[RetailManualEntry::LOG_SOURCE]), RetailManualEntry::LOG_SOURCE);

// ---------- 用例 5: 回落语义（不吞数据） ----------
check('未知取值回落机器值本身（页面/导出仍认得出是哪来的数据）',
    LogSource::label('who_knows') === 'who_knows', LogSource::label('who_knows'));
check('历史空串回落空串（source 列上线前的旧日志行，与收口前逐字一致）',
    LogSource::label('') === '', LogSource::label(''));

// ---------- 用例 6: 既有取值的标签逐项不变（本轮只加取值、不改文案） ----------
$unchanged = [
    'cron' => '定时采集',
    'manual' => '手动上传',
    'batch_check' => '批量核查',
    'batch_retry' => '批量重传',
    'retail' => '零售采集',
    'retail_retry' => '零售补传',
];
foreach ($unchanged as $value => $label) {
    check("既有取值标签不变: $value => $label", ($labels[$value] ?? null) === $label, $labels[$value] ?? '(缺失)');
}

// 两个新取值（本轮补的：数量对账是欠账，外部上传是下一票要写的数据）
check('数量对账（quantity_check）有中文标签', ($labels['quantity_check'] ?? null) === '数量对账');
check('外部上传（retail_external）有中文标签', ($labels['retail_external'] ?? null) === '外部上传');

echo "\n";
if ($failures === 0) {
    echo "全部通过 ✓\n";
    exit(0);
}
echo "失败 $failures 项 ✗\n";
exit(1);
