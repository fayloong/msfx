<?php
/**
 * App\RetailCollectionGate 单元测试（自包含断言脚本，无框架依赖）
 *
 * 运行: php tests/retail_collection_gate_test.php
 *
 * 测试目标: 零售采集的计数门卫——**当日总数**与基线比对、基线文件读写、
 *   以及**基线只在整轮采集成功之后才写**这条顺序铁律。
 *   （2026-10-05 起门卫只数一个数：原先的「已上传 / 未上传」读的是源库状态表，
 *     判据统一到平台核查之后采集不再读那张表，见 ADR 0018。）
 *
 *   本文件真正钉的是三条**漏了就会出事**的性质：
 *   - **失败不写基线**（用例 8、9）：落库跑到一半失败却把计数记成"见过"，下一轮门卫
 *     就判"无变化"从此永久跳过——那天剩下的单据再也采不进来，且日志上完全看不出异常
 *   - **未变就不采集**（用例 5）：数相同却照跑，门卫等于没装，日志里照样分不清
 *     "真没新单"与"脚本没跑"（这正是门卫要交付给运维的那件事）
 *   - **计数失败照常采集**（用例 8）：门卫跳过的是一整轮采集，方向必须朝"宁可多跑一轮"。
 *     照批发那边的"计数失败就跳过"写，源库抖一下当天就不再采了——零售这边没有第二道兜底
 *   （批发的采集失败只是少跑一轮视图，零售漏采就是整天单据在页面上不存在）
 *   其余用例钉形状与边界：数的比对、日期不符、损坏/缺失基线、**旧版形状的基线**、
 *   `--all` 绕过、基线文件与批发的不是同一个。
 *
 * **辨别力**（去掉关键行为必须变红）：
 *   - 把 `guard()` 里的 `write()` 挪到 `collect()` **之前** → 用例 9 红
 *   - 去掉计数失败时的"照常采集"（改成跳过或直接抛） → 用例 8 红
 *   - 把未变判定改成恒 false → 用例 4、5 红
 *   - 去掉日期比对（只看数） → 用例 6 红
 *   - 把坏基线当异常抛出而不是"视为无基线" → 用例 7 红
 *   - **把旧版形状的基线当合法**（只判 date/total 在不在）→ 用例 12 红
 *   - 把基线文件指到批发的 `fetch_bill_counter.json` → 用例 11 红
 *
 * **计数 SQL 本身不进本测试**：它要连源库才有答案，且"不断言 SQL 字符串"是 spec 定的
 * 测试口径（见 .scratch/retail-collection-split/spec.md 的 Testing Decisions）。
 * 那个数的口径对不对，靠实测核对（票 04 验收项：连跑两次 + 与采集行数对账）。
 */

require __DIR__ . '/../vendor/autoload.php';

use App\RetailCollectionGate;

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

// 临时目录：基线文件的读写是真落盘（它本身就是被测行为），故用一个临时文件而不是 mock 文件系统
$tmpDir = sys_get_temp_dir() . '/retail_gate_test_' . getmypid();
@mkdir($tmpDir, 0777, true);
$stateFile = $tmpDir . '/counter.json';

/** 每个用例用干净的文件开头，免得上一个用例的残留把判定喂成别的分支 */
function freshStateFile(string $file, ?string $content = null): void
{
    @unlink($file);
    if ($content !== null) {
        file_put_contents($file, $content);
    }
}

/** 读回落盘的基线（不存在时返回 null） */
function readState(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $decoded = json_decode((string)file_get_contents($file), true);
    return is_array($decoded) ? $decoded : null;
}

/** 门卫数出来的那个数（形状与 `counts()` 的返回值一致） */
$counts = static fn(int $total): array => ['total' => $total];

// ---------- 用例 1: unchanged() —— 纯判定 ----------
$base = ['date' => '2026-10-02', 'total' => 45];
check('日期相同 + 总数没变 → 判为「未变」',
    RetailCollectionGate::unchanged($base, '2026-10-02', $counts(45)));
check('总数变了 → 判为「有变化」',
    !RetailCollectionGate::unchanged($base, '2026-10-02', $counts(46)));
check('日期不符（数相同）→ 判为「有变化」（换了一天就得重采）',
    !RetailCollectionGate::unchanged($base, '2026-10-03', $counts(45)));
check('无基线 → 判为「有变化」（照常采集）',
    !RetailCollectionGate::unchanged(null, '2026-10-02', $counts(45)));
check('基线里的数被人工写成字符串 → 仍能判「未变」（不比类型，只比数）',
    RetailCollectionGate::unchanged(['date' => '2026-10-02', 'total' => '45'], '2026-10-02', $counts(45)));

// ---------- 用例 2: write() / read() —— 基线文件的形状 ----------
freshStateFile($stateFile);
check('写基线成功', RetailCollectionGate::write($stateFile, '2026-10-02', $counts(45)) === true);
$written = readState($stateFile);
// 形状是定死的：日期 + 那个数。多写一个键就等于悄悄改了契约（人工对账靠它）
check('落盘形状恰为 {date, total}',
    is_array($written) && array_keys($written) === ['date', 'total'],
    json_encode($written, JSON_UNESCAPED_UNICODE));
check('落盘内容与传入一致',
    $written === ['date' => '2026-10-02', 'total' => 45],
    json_encode($written, JSON_UNESCAPED_UNICODE));

$read = RetailCollectionGate::read($stateFile);
check('read() 读回基线且不报故障', $read['baseline'] === $written && $read['note'] === '', var_export($read['note'], true));

// ---------- 用例 3: read() —— 缺失 / 损坏 / 形状非法，一律"视为无基线" ----------
freshStateFile($stateFile);
$read = RetailCollectionGate::read($stateFile);
check('文件不存在 → 无基线', $read['baseline'] === null);
check('文件不存在 → 有说明（日志里能说清为什么没基线）', $read['note'] !== '');

file_put_contents($stateFile, '{"date":"2026-10-02",');   // 半截 JSON（写到一半断电）
$read = RetailCollectionGate::read($stateFile);
check('文件损坏（半截 JSON）→ 无基线', $read['baseline'] === null);
check('文件损坏 → 有说明', $read['note'] !== '');

file_put_contents($stateFile, '{"date":"2026-10-02"}');   // 缺那个数
check('缺键 → 无基线', RetailCollectionGate::read($stateFile)['baseline'] === null);

file_put_contents($stateFile, '{"date":"10/02/2026","total":45}');
check('日期不是 YYYY-MM-DD → 无基线', RetailCollectionGate::read($stateFile)['baseline'] === null);

file_put_contents($stateFile, '"2026-10-02"');   // 合法 JSON 但不是对象
check('JSON 不是对象 → 无基线', RetailCollectionGate::read($stateFile)['baseline'] === null);

// ---------- 用例 4: guard() —— 无基线 → 照常采集并落基线 ----------
freshStateFile($stateFile);
$collectRuns = 0;
$result = RetailCollectionGate::guard(
    stateFile: $stateFile,
    date: '2026-10-02',
    count: static function (string $date) use ($counts): array {
        check('计数被调用且收到的是采集日期', $date === '2026-10-02', $date);
        return $counts(45);
    },
    collect: static function () use (&$collectRuns): void {
        $collectRuns++;
    }
);
check('无基线 → 不跳过', $result['skipped'] === false);
check('无基线 → 采集跑了一轮', $collectRuns === 1);
check('无基线 → 采集成功后落基线',
    readState($stateFile) === ['date' => '2026-10-02', 'total' => 45],
    json_encode(readState($stateFile), JSON_UNESCAPED_UNICODE));
check('无基线 → 返回值里带回那个数（供打印）', $result['counts'] === $counts(45));
check('无基线 → 无警告', $result['warning'] === null, (string)$result['warning']);

// ---------- 用例 5: guard() —— 数没变 → 跳过整轮 ----------
$collectRuns = 0;
$before = file_get_contents($stateFile);
$result = RetailCollectionGate::guard(
    stateFile: $stateFile,
    date: '2026-10-02',
    count: static fn(string $date): array => $counts(45),
    collect: static function () use (&$collectRuns): void {
        $collectRuns++;
    }
);
check('数未变 → 跳过', $result['skipped'] === true);
check('数未变 → 采集一次都没跑（门卫的全部价值在这里）', $collectRuns === 0, "跑了 {$collectRuns} 轮");
check('数未变 → 基线一字未动', file_get_contents($stateFile) === $before);
check('数未变 → 原因里写明那个数（运维据此区分"没新单"与"脚本没跑"）',
    str_contains($result['reason'], '45') && str_contains($result['reason'], '跳过'),
    $result['reason']);

// ---------- 用例 6: guard() —— 日期不符 → 不跳过（哪怕数碰巧相同） ----------
$collectRuns = 0;
$result = RetailCollectionGate::guard(
    stateFile: $stateFile,
    date: '2026-10-03',            // 新的一天，计数与昨天一样（昨天那 45 张是别的日期的单）
    count: static fn(string $date): array => $counts(45),
    collect: static function () use (&$collectRuns): void {
        $collectRuns++;
    }
);
check('日期不符 → 不跳过', $result['skipped'] === false);
check('日期不符 → 采集跑了一轮', $collectRuns === 1);
check('日期不符 → 基线换成新日期',
    (readState($stateFile)['date'] ?? null) === '2026-10-03',
    json_encode(readState($stateFile), JSON_UNESCAPED_UNICODE));

// ---------- 用例 7: guard() —— 基线损坏 → 视为无基线，照常采集并覆盖回合法值 ----------
file_put_contents($stateFile, 'not json at all');
$collectRuns = 0;
$result = RetailCollectionGate::guard(
    stateFile: $stateFile,
    date: '2026-10-02',
    count: static fn(string $date): array => $counts(45),
    collect: static function () use (&$collectRuns): void {
        $collectRuns++;
    }
);
check('基线损坏 → 不跳过（不能因为文件坏了就永远不采）', $result['skipped'] === false);
check('基线损坏 → 采集跑了一轮', $collectRuns === 1);
check('基线损坏 → 被合法内容覆盖（下一轮恢复正常判定）',
    readState($stateFile) === ['date' => '2026-10-02', 'total' => 45],
    json_encode(readState($stateFile), JSON_UNESCAPED_UNICODE));

// ---------- 用例 8: guard() —— 计数查询失败 = 无基线、照常采集、**不写基线** ----------
// 方向性的一条：门卫跳过的是一整轮采集。计数失败时若照批发那样"跳过本次"，源库抖一下
// 当天就不再采了——零售没有第二道兜底（批发漏一轮只是少跑一次视图，零售漏采是整天单据在
// 页面上不存在）。所以这里必须"宁可多跑一轮"。
file_put_contents($stateFile, json_encode(['date' => '2026-10-02', 'total' => 45]));
$before = file_get_contents($stateFile);
$collectRuns = 0;
$result = RetailCollectionGate::guard(
    stateFile: $stateFile,
    date: '2026-10-02',
    count: static function (string $date): array {
        throw new \RuntimeException('源库连接超时');
    },
    collect: static function () use (&$collectRuns): void {
        $collectRuns++;
    }
);
check('计数失败 → 不跳过（照常采集）', $result['skipped'] === false);
check('计数失败 → 采集跑了一轮', $collectRuns === 1);
check('计数失败 → 手里没有那个数', $result['counts'] === null);
check('计数失败 → 原因里带上源库的错（日志可查）',
    str_contains($result['reason'], '源库连接超时'), $result['reason']);
// 没有数就没资格写基线：拿旧数写回去会让"计数一直失败"这几天看起来"一直没变化"
check('计数失败 → 基线保持原样（不被旧数覆盖，也不被清空）', file_get_contents($stateFile) === $before);

// ---------- 用例 9: guard() —— 采集失败 → 异常上抛、**基线不写**（本票的辨别力核心） ----------
// 把 write() 挪到 collect() 之前这条立刻变红。真出事的样子：落库跑到一半失败，
// 计数却已被记成"见过"，下一轮判"无变化"永久跳过——剩下的单据再也采不进来。
$noFile = $tmpDir . '/never-written.json';
freshStateFile($noFile);
$caught = null;
try {
    RetailCollectionGate::guard(
        stateFile: $noFile,
        date: '2026-10-02',
        count: static fn(string $date): array => $counts(45),
        collect: static function (): void {
            throw new \RuntimeException('落库中途失败');
        }
    );
} catch (\RuntimeException $e) {
    $caught = $e;
}
check('采集失败 → 异常原样上抛（脚本据此非零退出）', $caught instanceof \RuntimeException && $caught->getMessage() === '落库中途失败');
check('采集失败 → 基线文件压根没建', !is_file($noFile));

// 已有旧基线时同样不写：旧数原样留着（下一轮计数仍不等，自动重采）
file_put_contents($stateFile, json_encode(['date' => '2026-09-30', 'total' => 10]));
$before = file_get_contents($stateFile);
try {
    RetailCollectionGate::guard(
        stateFile: $stateFile,
        date: '2026-10-02',
        count: static fn(string $date): array => $counts(45),
        collect: static function (): void {
            throw new \RuntimeException('落库中途失败');
        }
    );
} catch (\RuntimeException $e) {
    // 预期内
}
check('采集失败 → 旧基线一字未动（不是"没变化"，是"没成功"）', file_get_contents($stateFile) === $before);

// ---------- 用例 10: guard() —— --all（date = null）绕过门卫 ----------
// 两年窗口侦察的计数口径与门卫的"当日"不是一回事：既不该拿它去比对当日基线，
// 也不该拿它写基线（写了次日 cron 会拿当日的数去比一个窗口的数，永远不相等）。
file_put_contents($stateFile, json_encode(['date' => '2026-10-02', 'total' => 45]));
$before = file_get_contents($stateFile);
$collectRuns = 0;
$countCalls = 0;
$result = RetailCollectionGate::guard(
    stateFile: $stateFile,
    date: null,
    count: static function (string $date) use (&$countCalls): array {
        $countCalls++;
        return [];
    },
    collect: static function () use (&$collectRuns): void {
        $collectRuns++;
    }
);
check('--all → 不跳过', $result['skipped'] === false);
check('--all → 侦察跑了一轮', $collectRuns === 1);
check('--all → 连计数都不调（省一次源库查询）', $countCalls === 0);
check('--all → 基线一字未动', file_get_contents($stateFile) === $before);

// ---------- 用例 11: 基线文件与批发采集的那个不是同一个 ----------
// 两个脚本共写一个文件会互相踩：批发写 {date, count}、零售写 {date, total}，
// 彼此的判定都会被对方的写入喂成"格式非法→视为无基线"（表现是门卫时灵时不灵，最难查的那类）。
$batch = dirname(__DIR__) . '/data/fetch_bill_counter.json';
$retail = RetailCollectionGate::stateFile();
check('零售基线文件与批发的不是同一个', $retail !== $batch, $retail);
check('零售基线文件落在 data/ 下', dirname($retail) === dirname($batch), $retail);
check('零售基线文件名带 retail（人工 ls 时一眼分得清）', str_contains(basename($retail), 'retail'), $retail);
check('批发的基线文件仍然指向 fetch_bill_counter.json（本票不碰批发）',
    basename($batch) === 'fetch_bill_counter.json', $batch);

// ---------- 用例 12: 旧版形状的基线 → 视为无基线（2026-10-05 改口径的过渡护栏） ----------
// 那次之前门卫数三个数，基线里带着 uploaded/unuploaded。它的 total 其实还能用，但"形状对不上就
// 整份作废"是本类一贯的立场——宁可多采一轮，也不拿一份按旧口径写的文件去判"没变化"。
$oldShape = json_encode(['date' => '2026-10-02', 'total' => 45, 'uploaded' => 4, 'unuploaded' => 41]);
freshStateFile($stateFile, $oldShape);
$read = RetailCollectionGate::read($stateFile);
check('旧版形状 → 无基线', $read['baseline'] === null);
check('旧版形状 → 说明里点出是旧版（不是含混的"格式非法"）',
    str_contains($read['note'], '旧版'), $read['note']);

$collectRuns = 0;
$result = RetailCollectionGate::guard(
    stateFile: $stateFile,
    date: '2026-10-02',
    count: static fn(string $date): array => $counts(45),   // 数恰好与旧基线里的 total 相同
    collect: static function () use (&$collectRuns): void {
        $collectRuns++;
    }
);
check('旧版形状 → 不跳过（数碰巧相同也照采）', $result['skipped'] === false);
check('旧版形状 → 采集跑了一轮', $collectRuns === 1);
check('旧版形状 → 被新形状覆盖（此后恢复正常判定）',
    readState($stateFile) === ['date' => '2026-10-02', 'total' => 45],
    json_encode(readState($stateFile), JSON_UNESCAPED_UNICODE));

// ---------- 收尾：清理临时文件 ----------
@unlink($stateFile);
@unlink($noFile);
@rmdir($tmpDir);

echo "\n";
if ($failures === 0) {
    echo "全部通过 ✓\n";
    exit(0);
}
echo "失败 $failures 项 ✗\n";
exit(1);
