<?php
/**
 * 门店单据在平台上再核查一次（零售版 check_bill_status）
 * 用法: php scripts/check_bill_status_retail.php [--dry-run] [--limit=N] [--company=名,名…]
 *   （无参数）   拿各门店自己的凭据调 `lsyd.query.upbilldetail`，逐条问平台"这单在不在"
 *   --dry-run   只列清单（企业/单号），**一次平台调用都不发、不写任何库**。数字是**计划数**
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
 * ⚠️ **本脚本不进 crontab**（同 upload_pending_retail.php 的态度，见 spec）：一趟几千条
 *    @500ms 要十几分钟，且"翻正本地状态"这件事人应该看得见。默认（不带 --dry-run）就是真查、
 *    真翻正——先 `--dry-run` 看清单，再用 `--limit=N` 小步走。
 *    它**只发查询、不发申报**（不可逆的是申报，这个接口只读），但仍会往平台发真实调用。
 *
 * 与状态闭环的分工：闭环查**源库状态表**（免费、每 30 分钟一轮、按裸单号比对），本脚本查
 * **平台本身**（带 ref_ent_id、按企业隔离、要花调用）。两者谁先翻正都行——翻正过的行不再是
 * 「等待上传」，另一条路径自然不再管它。
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
    // ── 待办清单：与状态闭环同一份取数口径 ──
    $pending = RetailExternalUploads::pendingItems();

    // --company：取回之后过滤（取数口径本身不变）；未知名在参数解析后已拦掉
    if ($companies !== null) {
        $pending = array_intersect_key($pending, array_flip($companies));
    }

    $total = 0;
    foreach ($pending as $byDjbh) {
        $total += count($byDjbh);
    }
    echo "[check_bill_status_retail] 本地待办 {$total} 条（涉及 " . count($pending) . " 家企业）\n";

    if ($total === 0) {
        echo "[check_bill_status_retail] 没有需要核查的待办\n";
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
        echo "[check_bill_status_retail] 翻正: 任务行 {$turned} 行、追加外部上传记录 {$recorded} 条\n";
    }
} catch (\Exception $e) {
    echo '[check_bill_status_retail] 错误: ' . $e->getMessage() . "\n";
    exit(1);
}
