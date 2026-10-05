<?php
/**
 * 门店单据在平台上再核查一次（零售版 check_bill_status）
 * 用法: php scripts/check_bill_status_retail.php [--dry-run] [--limit=N] [--company=名,名…]
 *   （无参数）   拿各门店自己的凭据调 `lsyd.query.upbilldetail`，逐条问平台"这单在不在"；
 *               30 分钟内查过的单本轮跳过（门卫），开头会打印被挡下多少条
 *   --dry-run   只列清单（企业/单号），**一次平台调用都不发、不写任何库**。数字是**计划数**
 *               （列的是**过完门卫**的那份，与真跑一致）
 *   --limit=N   最多查 N 条（N ≥ 1），给试跑用；跳过的门店（取不到凭据）不占额度
 *   --company=  只查这些**企业名**（逗号分隔，写全名）的待办——取数口径仍是全部门店
 *               （`RetailExternalUploads::pendingItems()`），过滤在取回之后做
 *
 * 两个来源合一（等待上传的门店任务 ＋ 零售企业的补传失败记录）：两者都要按门店分组取凭据、
 * 都要限速，拆成两个脚本只会把同一套分组逻辑写两遍。判定与落库**复用状态闭环那一套**
 * （`RetailExternalUploads::closureActions()` / `applyActions()`），本脚本只做参数解析、
 * 取数、取锁与打印：
 *   - 平台上有（`msg_code != FAIL_BIZ_NO_PAT_INFO`）→ 任务行翻「已处理」+「上传成功」
 *     （`request_status` 不动：本项目没发起过上传请求）；失败记录**追加**一条「外部上传」记录
 *   - 信息不存在 → 未上传，一个字段都不改
 *   - 查询异常（网络/平台错误）→ 跳过不修改（"不知道"不等于"没上传"）
 *
 * **已挂 crontab**（票 02，2026-10-05）：`20,50 8-21 * * *`——**只跑白天**（门店单据的流转发生在
 * 营业时间，夜里那几轮查到的多半是白天已查过的同一批；隔夜的新单由次日 8:20 那轮补上，门卫
 * 30 分钟早过期）。零售用的是各家门店自己的 AppKey，不受批发那个 8-20 点窗口约束。
 *   - **逐单新鲜度门卫**（`CHECK_INTERVAL_MINUTES = 30`，与批发两个检查脚本同值——运维不该记两套
 *     门卫语义）：`last_checked_at` 还在窗口内的单本轮直接跳过。定时跑会反复查同一批未上传单，
 *     门卫就是为这个场景存在的。**被挡下多少条打印在开头**（与采集侧计数门卫同一个立场：日志要
 *     分得清"真没待办"与"待办都在门卫窗口内"）
 *   - **touch 由本脚本批量写**（`RetailExternalUploads::touchChecked()`）：平台给了答复的那些键
 *     （在／不在都算）**两张表一起**刷 `last_checked_at`，整批**一次事务**；查异常的不刷
 *     （下次 cron 自动重查）。门卫与记账都不进 `RetailPlatformCheck`——那个类保持"只查不写"，
 *     离线测试才钉得住"`--dry-run` 一次平台调用都不发"这条
 * 其余口径不变：默认（不带 --dry-run）就是真查、真翻正，先 `--dry-run` 看清单、再用 `--limit=N`
 * 小步走；它**只发查询、不发申报**（不可逆的是申报，这个接口只读），但仍是真实调用。
 *
 * ⚠️ **2026-10-05 起它是门店单「是否已上传」的唯一判据**（docs/adr/0018）：源库状态表那条路
 * （采集分流 + 状态闭环）因外部系统不稳定整体停用——那张表写的是外部系统的行为，信它会让一条
 * 实际没传成的单从补传队列里消失。**它的覆盖面就是系统的覆盖面**：取不到凭据的门店（待配凭据 /
 * `未识别`）整组跳过，那批单会一直挂在「等待上传」（开头那句"跳过 N 家门店 M 条"是运维要看的数）。
 * （历史：闭环 2026-10-05 前每轮跑一遍，免费、按裸单号、不受门卫约束、抓跨日翻转；停接后它读的
 * 那张表只剩写侧——补传成功后回写，告诉外部系统"别再传"。）
 *
 * 属主注意：与别的写库脚本一样，以 nginx 身份跑（见 CLAUDE.md「文件权限」那条）。
 */

require_once __DIR__ . '/../vendor/autoload.php';

// CLI 环境下 db.php 不在 include_path，提供桩函数
if (!function_exists('info_log')) {
    function info_log(string $title, string $msg = '', string $level = 'INFO', array $data = []): void {
        $ts = date('Y-m-d H:i:s');
        $ctx = $data ? ' ' . json_encode($data, JSON_UNESCAPED_UNICODE) : '';
        fwrite(STDERR, "[{$ts}] [{$level}] {$title}{$msg}{$ctx}\n");
    }
}

use App\ApiClient;
use App\Config;
use App\Enterprise;
use App\RetailExternalUploads;
use App\RetailPlatformCheck;

Config::load();

// 新鲜度门卫：距上次成功查询超过该分钟数的单据才重新调 API。与批发两个检查脚本同值——
// 运维不该记两套门卫语义。
const CHECK_INTERVAL_MINUTES = 30;

// ── 参数：--dry-run / --limit=N / --company=名,名（顺序随意，可组合）──
$dryRun = false;
$limit = null;
$companies = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int)$m[1];
        if ($limit < 1) {
            // --limit=0 几乎必然是笔误（想看一眼清单该用 --dry-run），拒绝而不是静默空转
            echo "[check_bill_status_retail] 参数无效: {$arg}（--limit 至少为 1；只想看清单用 --dry-run）\n";
            exit(1);
        }
    } elseif (preg_match('/^--company=(.+)$/', $arg, $m)) {
        $companies = array_values(array_filter(
            array_map('trim', explode(',', $m[1])),
            static fn(string $v): bool => $v !== ''
        ));
        if ($companies === []) {
            echo "[check_bill_status_retail] 参数无效: {$arg}（--company 没有给出任何企业名）\n";
            exit(1);
        }
    } else {
        echo "[check_bill_status_retail] 未知参数: {$arg}\n";
        echo "用法: php scripts/check_bill_status_retail.php [--dry-run] [--limit=N] [--company=名,名…]\n";
        exit(1);
    }
}

// --company 的未知企业名**直接退出 1**（在取锁与任何查询之前）：与 upload_pending_retail 的
// 同名参数同一个立场（那边记的是"打错一个字母与队列本来就是空的在日志上长得一样"）——这里
// 更糟：写错店名会让"核查了一整轮"看起来像"本来就没有待办"。注意两处的取值不同：那边是企业
// **key**（`dyt-baoyuan`），这边是企业**全名**（页面下拉里那个）
if ($companies !== null) {
    foreach ($companies as $name) {
        if (Enterprise::find($name) === null) {
            echo "[check_bill_status_retail] 未知企业名: {$name}（config/enterprises.php 里没有这家；--company 收的是企业全名）\n";
            exit(1);
        }
    }
}

// flock 防并发：真跑才取锁（--dry-run 一个字节都不写，没有要互斥的东西）
$lockFp = null;
if (!$dryRun) {
    $lockFile = __DIR__ . '/../logs/check_bill_status_retail.lock';
    $lockFp = fopen($lockFile, 'w+');
    if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
        if ($lockFp) {
            fclose($lockFp);
        }
        echo "[check_bill_status_retail] 已有实例在运行（锁文件 {$lockFile} 被占用），本次退出\n";
        exit(0);
    }
}

echo '[check_bill_status_retail] ' . ($dryRun ? '预演（不发任何平台调用）' : '开始核查') . "\n";

try {
    // ── 待办清单：与状态闭环同一份取数口径；门卫只在**本脚本**生效（闭环不传，它每轮跑全量）──
    // 全量先数一遍：与"过了门卫的那份"的差就是本轮被挡下的条数。日志里要分得清"真没待办"与
    // "待办都还在门卫窗口内"——与采集侧计数门卫同一个立场（脚本没跑 ≠ 今天没事）
    $allPending = RetailExternalUploads::pendingItems();
    $pending = RetailExternalUploads::pendingItems(CHECK_INTERVAL_MINUTES);

    // --company：取回之后过滤（取数口径本身不变）；未知名在参数解析后已拦掉。
    // **两份都要过滤**——否则"挡下 N 条"里会混进别的门店的数
    if ($companies !== null) {
        $keep = array_flip($companies);
        $allPending = array_intersect_key($allPending, $keep);
        $pending = array_intersect_key($pending, $keep);
    }

    $allTotal = RetailExternalUploads::pendingCount($allPending);
    $total = RetailExternalUploads::pendingCount($pending);
    // 两次查询之间有别的写入（Web 端补传、采集）时差值可能偏小甚至为负——负数一律按 0 报
    $gatedOut = max(0, $allTotal - $total);

    echo "[check_bill_status_retail] 本地待办 {$allTotal} 条（涉及 " . count($allPending) . " 家企业）";
    echo $gatedOut > 0
        ? "；其中 {$gatedOut} 条在 " . CHECK_INTERVAL_MINUTES . " 分钟门卫窗口内已查过，本轮跳过\n"
        : "\n";

    if ($total === 0) {
        echo "[check_bill_status_retail] 没有需要核查的待办"
            . ($gatedOut > 0 ? '（都在门卫窗口内，等下轮）' : '') . "\n";
        exit(0);
    }

    // ── 分组：取不到凭据的门店整组跳过（它们拿不出 ref_ent_id，这条判据对它们不成立）──
    $grouped = RetailPlatformCheck::groupByCompany($pending);
    foreach ($grouped['skipped'] as $company => $n) {
        echo "[check_bill_status_retail] 跳过 {$company}：取不到可用凭据，{$n} 条\n";
    }

    $queueCount = 0;
    foreach ($grouped['queryable'] as $entry) {
        $queueCount += count($entry['items']);
    }
    $expected = $limit !== null ? min($queueCount, $limit) : $queueCount;
    echo "[check_bill_status_retail] 将核查 {$expected} 条（" . count($grouped['queryable']) . " 家门店，每条间隔 "
        . (RetailPlatformCheck::QUERY_INTERVAL_US / 1000) . "ms）\n";

    // ── 查询回调：按 AppKey 缓存客户端（同一门店的 N 条共用一个 TopClient）──
    $clients = [];
    $query = static function (string $djbh, array $credential) use (&$clients): array {
        $key = (string)($credential['appkey'] ?? '');
        $clients[$key] ??= ApiClient::forCredential($credential);
        return $clients[$key]->queryUpbillDetail($djbh, (string)($credential['ref_ent_id'] ?? ''));
    };

    // ── 逐条进度 ──
    $labels = [
        RetailPlatformCheck::OUTCOME_PLAN     => '计划',
        RetailPlatformCheck::OUTCOME_UPLOADED => '已上传',
        RetailPlatformCheck::OUTCOME_ABSENT   => '未上传',
        RetailPlatformCheck::OUTCOME_ERROR    => '查询异常',
    ];
    $n = 0;
    $report = static function (string $company, string $djbh, array $event) use (&$n, $expected, $labels): void {
        $n++;
        $label = $labels[$event['outcome']] ?? $event['outcome'];
        $suffix = $event['error'] !== null ? '：' . $event['error'] : '';
        echo "[{$n}/{$expected}] {$company} {$djbh} → {$label}{$suffix}\n";
    };

    $stats = RetailPlatformCheck::run($pending, $query, $dryRun, $limit, $report);

    // ── 翻正：判定与落库复用闭环那一套（企业隔离的落点在 actionsByCompany）──
    $turned = 0;
    $recorded = 0;
    if (!$dryRun && $stats['uploaded'] > 0) {
        $success = RetailExternalUploads::successKeys(RetailExternalUploads::pendingDjbhList($pending));
        $actions = RetailPlatformCheck::actionsByCompany($pending, $stats['uploaded_by_company'], $success);
        $applied = RetailExternalUploads::applyActions($actions, $pending, [
            'reason' => '平台查询确认该单据已上传（lsyd.query.upbilldetail）',
            'checker' => '平台核查',
            'log_type' => 'retail_platform_check',
            'judged_by' => 'alibaba.alihealth.drugtrace.top.lsyd.query.upbilldetail',
        ]);
        $turned = $applied['turned'];
        $recorded = $applied['recorded'];
    }

    // ── 汇总 ──
    echo "\n[check_bill_status_retail] 核查完成: 已上传 {$stats['uploaded']} / 未上传 {$stats['absent']}"
        . " / 异常 {$stats['error']}"
        . " / 跳过 {$stats['skipped_companies']} 家门店 {$stats['skipped_items']} 条"
        . "（共 {$stats['queued']} 条";
    if ($stats['remaining'] > 0) {
        echo "，另有 {$stats['remaining']} 条未查（--limit）";
    }
    echo "）\n";

    if ($dryRun) {
        echo "[check_bill_status_retail] 预演结束：未发任何平台调用、未写任何库\n";
    } else {
        // ── 门卫记账：平台给了答复的那些键（在／不在都算）刷 last_checked_at ──
        // 放在翻正**之后**：翻正是有价值的动作，记账只是后续动作——顺序反过来的话，applyActions
        // 万一抛异常，这批单会被门卫白挡 30 分钟（下轮重查即可，但何必）
        $touched = RetailExternalUploads::touchChecked($stats['checked_by_company']);

        echo "[check_bill_status_retail] 翻正: 任务行 {$turned} 行、追加外部上传记录 {$recorded} 条\n";
        echo "[check_bill_status_retail] 门卫记账: 刷新 last_checked_at {$touched} 行（这些单 "
            . CHECK_INTERVAL_MINUTES . " 分钟内不再查）\n";
    }
} catch (\Exception $e) {
    echo '[check_bill_status_retail] 错误: ' . $e->getMessage() . "\n";
    exit(1);
}
