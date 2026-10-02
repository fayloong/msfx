<?php
/**
 * App\RetailBatchUpload 单元测试（自包含断言脚本，无框架依赖）
 *
 * 运行: php tests/retail_batch_upload_test.php
 *
 * 测试目标（票 06 要的两条自包含测试都在这儿）：
 *   ① **取数口径**：`PENDING_SQL` 只取门店来源 + 还挂着「等待上传」的行，且**不把 `未识别`
 *      筛在 SQL 里**——那个数是票面点名要打印的（"跳过未识别 N 条"），筛进 SQL 就数不出来
 *   ② **`--dry-run` 一次平台调用都不发**：主循环收 `$upload` 回调，测试注入一个"被调用就记一笔"
 *      的假回调，断言 dry-run 下它一次都没被调。这条正是把主循环抽成纯函数的原因——没有这个
 *      接缝，"不产生平台调用"就只能靠人工跑一遍、看它没写日志来"验证"
 *
 * **辨别力**（2026-10-02 逐条实测，改一处跑一遍再还原）：
 *   - 去掉 `run()` 的 `$dryRun` 分支（dry-run 照传）→ 用例 2 的前两条断言红。"每条都报告了"
 *     那条**不会**红（真传路径同样逐条回调）——所以"不发调用"这个事实由回调次数那条钉住，
 *     报告条数只是旁证，两条都不能少的正是这个原因
 *   - 把"能不能传"的判据从 `Enterprise::isRetail` 换成恒真 → 用例 3、4、5 共 8 条断言红
 *     （未识别/批发/空名全被传给 upload，且白占 `--limit` 额度）
 *   - 把 `--limit` 的截断挪到分流之前（截原始列表）→ 用例 5 的两条红（`--limit=2` 只传出去 1 条）
 *   - 去掉 upload 那圈的 try/catch → PHP fatal（未捕获的 `RuntimeException`），用例 6 的断言
 *     根本没跑到，进程退出码 255
 *   - 把 `PENDING_SQL` 里的 `task_status = '等待上传'` 删掉 → 用例 1 那条红（已处理的单会被反复重传）
 *   - 把子单 `failed > 0` 判成成功 → 用例 7 红（平台业务失败会被记成"成功 N 单"）
 *
 * 上传链路本身（三关 fail-closed、装配、日志、状态翻转、源库回写）**不进本测试**：那是
 * `App\RetailRetransmit` 的事，本票一个字都不动它，`run()` 只负责把任务逐条交给它。
 * 真传那条路是否真的走的是它，靠副本 + 离线桩实跑验（见票 06 交付记录），不靠 mock 断言。
 */

require __DIR__ . '/../vendor/autoload.php';

use App\RetailBatchUpload;

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

/** 一条门店待办（形状取自 PENDING_SQL 的列；run() 只读 djbh/company/trace_codes 三列） */
function task(string $djbh, string $company, string $codes = 'c1,c2'): array
{
    static $id = 0;
    $id++;
    return [
        'id' => $id,
        'rq' => '2026-10-02',
        'djbh' => $djbh,
        'trace_codes' => $codes,
        'bill_type' => '321',
        'company' => $company,
        'credential' => 'main',
    ];
}

/** 一条成功的上传返回（RetailRetransmit::retransmit() 的形状，按子单计） */
function uploaded(): array
{
    return ['total' => 1, 'success' => 1, 'failed' => 0];
}

// 配置里真实存在的一家门店（tests 依赖 config/enterprises.php 的既有做法，见 enterprise_config_test.php）
$store = '大源堂智慧药房（河源）有限公司新江分店';
$wholesale = '河药医药（河源）有限公司';

// ---------- 用例 1: 取数口径 ----------
// 断言的是**字面量**而不是"常量等于自己"：这三条条件就是这条链路"会碰哪些行"的全部定义，
// 每一条被去掉都会让脚本碰到它不该碰的行（批发任务、已处理的单），而脚本照样跑得完、不报错
check(
    '取数只取门店来源（source = retail）',
    str_contains(RetailBatchUpload::PENDING_SQL, "source = 'retail'"),
    RetailBatchUpload::PENDING_SQL
);
check(
    '取数只取等待上传（已处理的不再重复申报——申报不可逆）',
    str_contains(RetailBatchUpload::PENDING_SQL, "task_status = '等待上传'"),
    RetailBatchUpload::PENDING_SQL
);
check(
    '未识别不筛在 SQL 里（票面那句"跳过未识别 N 条"要靠它数出来）',
    !str_contains(RetailBatchUpload::PENDING_SQL, '未识别'),
    RetailBatchUpload::PENDING_SQL
);
check(
    '取数按 id 升序（--limit 首跑小步走时，每次跑的"前 N 条"得是同一批）',
    str_contains(RetailBatchUpload::PENDING_SQL, 'ORDER BY id'),
    RetailBatchUpload::PENDING_SQL
);

// ---------- 用例 2: --dry-run 一次平台调用都不发（票面第 4 条） ----------
$calls = 0;
$reported = [];
$stats = RetailBatchUpload::run(
    [task('D1', $store, 'c1,c2'), task('D2', $store, 'c3')],
    function () use (&$calls) {
        $calls++;
        return uploaded();
    },
    dryRun: true,
    report: function (array $t) use (&$reported): void {
        $reported[] = $t['djbh'];
    }
);
check('dry-run: upload 回调一次都没被调', $calls === 0, "被调了 {$calls} 次");
check('dry-run: 每条都报告了（列出计划）', $reported === ['D1', 'D2'], implode(',', $reported));
check(
    'dry-run: 统计只算计划数（成功/失败为 0，不发调用就没有结果）',
    $stats['queued'] === 2 && $stats['codes'] === 3 && $stats['success'] === 0 && $stats['failed'] === 0,
    json_encode($stats, JSON_UNESCAPED_UNICODE)
);

// ---------- 用例 3: 未识别被跳过并计数，不交给上传 ----------
$uploadedDjbhs = [];
$stats = RetailBatchUpload::run(
    [task('U1', '未识别', 'c1'), task('D1', $store, 'c1,c2')],
    function (array $t) use (&$uploadedDjbhs) {
        $uploadedDjbhs[] = $t['djbh'];
        return uploaded();
    }
);
check('未识别的行不交给上传', $uploadedDjbhs === ['D1'], implode(',', $uploadedDjbhs));
check('未识别计数为 1（票面要打印的那个数）', $stats['skipped'] === 1, json_encode($stats, JSON_UNESCAPED_UNICODE));
check(
    '总数与可上传数分开报（取回 2 条、跳过 1 条、处理 1 条）',
    $stats['total'] === 2 && $stats['queued'] === 1,
    json_encode($stats, JSON_UNESCAPED_UNICODE)
);

// ---------- 用例 4: 批发主体与空企业名同样不传（防御脏数据，判据是"是不是门店"） ----------
$uploadedDjbhs = [];
$stats = RetailBatchUpload::run(
    [task('W1', $wholesale), task('E1', ''), task('D1', $store)],
    function (array $t) use (&$uploadedDjbhs) {
        $uploadedDjbhs[] = $t['djbh'];
        return uploaded();
    }
);
check('批发主体与空企业名都不交给上传', $uploadedDjbhs === ['D1'], implode(',', $uploadedDjbhs));
check('两者都计进 skipped', $stats['skipped'] === 2, json_encode($stats, JSON_UNESCAPED_UNICODE));

// ---------- 用例 5: --limit 限的是上传条数，跳过的不占额度 ----------
$uploadedDjbhs = [];
$stats = RetailBatchUpload::run(
    [task('U1', '未识别'), task('D1', $store), task('U2', '未识别'), task('D2', $store), task('D3', $store)],
    function (array $t) use (&$uploadedDjbhs) {
        $uploadedDjbhs[] = $t['djbh'];
        return uploaded();
    },
    limit: 2
);
check('--limit=2 只传 2 条（且是最前面的两条门店单）', $uploadedDjbhs === ['D1', 'D2'], implode(',', $uploadedDjbhs));
check('未识别不占额度（两条都被跳过）', $stats['skipped'] === 2, json_encode($stats, JSON_UNESCAPED_UNICODE));
check(
    'remaining 报出这轮没碰的那条（限了量不等于当它不存在）',
    $stats['remaining'] === 1 && $stats['queued'] === 2,
    json_encode($stats, JSON_UNESCAPED_UNICODE)
);

// ---------- 用例 6: 被三关拒（抛异常）算失败，不影响后面的单 ----------
$errors = [];
$stats = RetailBatchUpload::run(
    [task('D1', $store), task('D2', $store), task('D3', $store)],
    function (array $t) {
        if ($t['djbh'] === 'D2') {
            throw new \RuntimeException('门店「' . $t['company'] . '」的凭据尚未配齐，拒绝补传');
        }
        return uploaded();
    },
    report: function (array $t, ?array $result, ?string $error) use (&$errors): void {
        if ($error !== null) {
            $errors[$t['djbh']] = $error;
        }
    }
);
check('被拒的一条算失败', $stats['failed'] === 1, json_encode($stats, JSON_UNESCAPED_UNICODE));
check('被拒不影响后面的单（先 1 后 1）', $stats['success'] === 2, json_encode($stats, JSON_UNESCAPED_UNICODE));
check('被拒的原因原样报到 report（脚本要打给人看）', str_contains($errors['D2'] ?? '', '凭据尚未配齐'), json_encode($errors, JSON_UNESCAPED_UNICODE));

// ---------- 用例 7: 平台业务失败（子单 failed > 0）也算失败 ----------
$stats = RetailBatchUpload::run(
    [task('D1', $store)],
    fn() => ['total' => 1, 'success' => 0, 'failed' => 1]
);
check(
    '子单 failed > 0 的一单计失败（口径与失败记录页一致：只有上传成功/单据重复才算成功）',
    $stats['failed'] === 1 && $stats['success'] === 0,
    json_encode($stats, JSON_UNESCAPED_UNICODE)
);

// ---------- 用例 8: 空队列 ----------
$stats = RetailBatchUpload::run([], function () {
    throw new \Exception('空队列不该调上传');
});
check(
    '空队列：什么都不调，统计全 0',
    $stats['total'] === 0 && $stats['queued'] === 0 && $stats['success'] === 0
        && $stats['failed'] === 0 && $stats['skipped'] === 0 && $stats['remaining'] === 0 && $stats['codes'] === 0,
    json_encode($stats, JSON_UNESCAPED_UNICODE)
);

// ---------- 汇总 ----------
echo $failures === 0
    ? "\n全部通过\n"
    : "\n{$failures} 项失败\n";
exit($failures === 0 ? 0 : 1);
