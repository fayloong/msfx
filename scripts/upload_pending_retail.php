<?php
/**
 * 零售门店单据的批量上传（票 06）——**能力已备、暂不启用**
 * 用法: php scripts/upload_pending_retail.php [--dry-run] [--limit=N] [--company=key,key…]
 *   （无参数）  把「等待上传」的门店单据逐条交给现有补传链路
 *   --dry-run  只列出会轮到哪些单（单号/门店/码数），**一次平台调用都不发、不写任何库**。
 *              它是**上界**：预演不代跑三关，凭据未配齐的门店（会逐条标出来）真跑时会被拒
 *   --limit=N  最多处理 N 条（N ≥ 1），给首跑小步走用。**未识别的行不占额度**；被三关拒的**占**
 *              ——它们在 N 条之内被逐条拒掉，后面的单不补位，那正是"小步走"要的效果
 *   --company= 只处理这些**企业 key**（config/enterprises.php 里的 key，如 `dyt-baoyuan`；
 *              逗号分隔）的待办——取数口径仍是 `RetailBatchUpload::PENDING_SQL`（全部门店），
 *              过滤在**取回之后**做，故"跳过非门店企业"那句统计的数会随之变小。
 *              未知 key 直接拒绝退出 1，不静默当成空集（打错一个字母就"跑了一遍什么都没传"，
 *              或者更糟——以为限定了范围、其实没限）。**这是"只传某几家"的唯一开关**：
 *              不加它就是把队列里**所有**门店的待办都真传出去
 *
 * ⚠️ **这不是 cron 脚本，也不该变成 cron 脚本**（票面第 5 条）：每一条都是**向平台的真实申报、
 * 不可逆**——传错了要人去平台上收拾。本脚本把"补传"从"人在页面上逐条点"扩成"一次可以走一批"，
 * 那条"由人看着清单再点"的约束因此**依赖人来手动运行它**：挂上定时任务，申报就在人看不见的
 * 时候发出去了（ADR 0011 的补注记了这件事）。能力备在这儿，启用与否由人决定。
 *
 * 本脚本**不重写任何上传逻辑**：逐条调 `App\RetailRetransmit::retransmit()`——三关 fail-closed、
 * 拆单、装配、日志（source 仍是补传那个 `retail_retry`）、任务状态翻转、源库回写全在它里面。
 * 取数口径与"哪些行不该碰"在 `App\RetailBatchUpload`（那儿也是 `--dry-run` 那条自包含测试的
 * 接缝：主循环收一个 upload 回调，dry-run 时一次都不调它）。本文件只做参数解析、取数、取锁与打印。
 *
 * 三处刻意的顺序（改之前先看为什么）：
 *   ① **空队列在取锁之前退出**：没活干就别去抢锁——抢了也只是让并发的另一个实例白等一次
 *   ② **`--dry-run` 不取锁**：它一个字节都不写，没有要互斥的东西；真跑才取（`logs/upload_pending_retail.lock`，
 *      与 `check_bill_status.php` 同款 `flock(LOCK_EX|LOCK_NB)`，被占用即退出）
 *   ③ **未识别在 `--limit` 之前跳过**：夹在队列里的未识别行不占额度（口径在 `RetailBatchUpload::run()`，
 *      不是这里）
 *
 * 跑之前的生产库注意事项与别的写库脚本一样（属主 nginx:nginx，见 init_db 那条）。
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

use App\Config;
use App\Database;
use App\Enterprise;
use App\RetailBatchUpload;
use App\RetailRetransmit;

Config::load();

// ── 参数：--dry-run / --limit=N / --company=k1,k2（顺序随意，可组合；正则只认 `=` 那一种形态）──
$dryRun = false;
$limit = null;
$companyKeys = null;
$badArg = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int)$m[1];
        if ($limit < 1) {
            // --limit=0 几乎必然是笔误（想看一眼队列该用 --dry-run），且它会让"跑了一遍却什么都没传"
            // 看起来像失败——宁可拒绝，也别让它成为一个静默的空操作
            echo "[upload_pending_retail] 参数无效: {$arg}（--limit 至少为 1；只想看清单用 --dry-run）\n";
            exit(1);
        }
    } elseif (preg_match('/^--company=(.+)$/', $arg, $m)) {
        $companyKeys = array_values(array_filter(
            array_map('trim', explode(',', $m[1])),
            static fn(string $v): bool => $v !== ''
        ));
        if ($companyKeys === []) {
            echo "[upload_pending_retail] 参数无效: {$arg}（--company 没有给出任何企业 key）\n";
            exit(1);
        }
    } else {
        $badArg = $arg;
        break;
    }
}

if ($badArg !== null) {
    echo "[upload_pending_retail] 参数无效: {$badArg}，需要 --dry-run、--limit=N、--company=k1,k2（可组合）\n";
    exit(1);
}

try {
    $db = Database::getInstance();

    // ── 取数：口径在 App\RetailBatchUpload::PENDING_SQL（门店来源 + 等待上传；未识别一并取回，
    //    由 run() 跳过并计数——票面要的那个数是它数出来的，不是 SQL 里筛掉后再猜的）──
    $tasks = $db->query(RetailBatchUpload::PENDING_SQL);

    // ── --company：只留这些企业的待办 ──
    // 过滤放在**取回之后**（而不是改 PENDING_SQL 拼 WHERE）：SQL 常量是取数口径的单一事实源，
    // 让它按调用方的参数变形，等于把"队列里有什么"这件事拆成两份会各自漂移的规则。
    // 未知 key 直接退出 1：打错一个字母时，"跑了一遍什么都没传"看起来跟"队列本来就是空的"
    // 一模一样——那正是需要 fail-closed 的地方。
    if ($companyKeys !== null) {
        $names = [];
        foreach ($companyKeys as $key) {
            $company = Enterprise::findByKey($key);
            if ($company === null) {
                echo "[upload_pending_retail] 参数无效: 未知企业 key「{$key}」"
                    . "（--company 用 config/enterprises.php 里的 key，如 dyt-baoyuan）\n";
                exit(1);
            }
            $names[$company['name']] = true;
        }
        $tasks = array_values(array_filter(
            $tasks,
            static fn(array $t): bool => isset($names[trim((string)($t['company'] ?? ''))])
        ));
        echo "[upload_pending_retail] --company 限定 " . implode('、', $companyKeys)
            . "：队列取回后留下 " . count($tasks) . " 条\n";
    }

    // 空队列**秒退，不取锁**（见头部 ①）
    if (empty($tasks)) {
        echo "[upload_pending_retail] 没有等待上传的门店单据\n";
        exit(0);
    }

    echo "[upload_pending_retail] 取到 " . count($tasks) . " 条门店待办"
        . ($dryRun ? '（--dry-run 预演）' : '') . "\n";
    if ($dryRun) {
        echo "[upload_pending_retail] --dry-run: 只列出将要上传的单据，不取锁、不调平台接口、不写任何库\n";
    }

    // ── 上传回调：逐条交给现有补传实现（本脚本不碰装配与三关）──
    $retransmit = new RetailRetransmit();
    $upload = static function (array $task) use ($retransmit, $db): array {
        return $retransmit->retransmit($task, $db);
    };

    // 逐条一行结果。**判据全在事件里**（`outcome` 与 `codes` 由 App\RetailBatchUpload 判定并给出）
    // ——脚本不再自己数码、也不再自己判"这一条算不算成功"：两处各写一份就会各自漂移
    //
    // 预演另标一处：按 id 顺序排在前面的单里会有**凭据未配齐**的门店（页面禁用补传的那批），
    // 真跑时它们被三关拒、一次调用都不发。所以 `--dry-run` 的数字是**上界**——逐条标出来，
    // "先 --dry-run 看量、再 --limit 小步走"才不会被偏乐观的数骗到。就绪态取页面同一份
    // （Enterprise::retailCredentialReady()），不另写一份"这家能不能补传"的判据
    $storeStates = $dryRun ? Enterprise::retailCredentialReady() : [];
    $notReady = 0;
    $report = static function (array $task, array $event) use ($storeStates, &$notReady): void {
        $company = trim((string)($task['company'] ?? ''));
        $head = '单号 ' . (string)($task['djbh'] ?? '') . ' | 门店 ' . $company . ' | ' . $event['codes'] . ' 码';

        if ($event['outcome'] === RetailBatchUpload::OUTCOME_PLAN) {
            // 预演打的就是"计划"——把 [成功] 打在这儿会让人以为真传了
            $ready = ($storeStates[$company] ?? '') === 'ready';
            if (!$ready) {
                $notReady++;
            }
            echo '[计划] ' . $head . ($ready ? '' : '（凭据未配齐，真跑会被三关拒绝、不发调用）') . "\n";
            return;
        }
        if ($event['outcome'] === RetailBatchUpload::OUTCOME_SUCCESS) {
            echo '[成功] ' . $head . "\n";
            return;
        }
        if ($event['error'] !== null) {
            // 三关 fail-closed 拒掉（非门店 / 凭据未配齐 / 无路由 / 装配缺项）：一个平台调用都没发
            echo '[拒绝] ' . $head . " —— {$event['error']}\n";
            return;
        }
        echo '[失败] ' . $head . '（子单 ' . (int)($event['result']['total'] ?? 0)
            . ' 个中 ' . (int)($event['result']['failed'] ?? 0) . ' 个没成，详见失败记录页）' . "\n";
    };

    // ── --dry-run：不取锁，跑完就退（头部 ②）──
    if ($dryRun) {
        $stats = RetailBatchUpload::run($tasks, $upload, dryRun: true, limit: $limit, report: $report);
        printSummary($stats, $limit, true, $notReady);
        exit(0);
    }

    // ── 真跑：先取锁（与 check_bill_status.php 同款）──
    // 本脚本由人手动跑，撞上并发的情形其实只有"同一张单被点两次"；锁仍要有——第二次进来会在
    // 平台调用之前被挡住，而不是两张单各传一半
    $lockFile = __DIR__ . '/../logs/upload_pending_retail.lock';
    $lockFp = fopen($lockFile, 'w+');
    if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
        if ($lockFp) {
            fclose($lockFp);
        }
        echo "[upload_pending_retail] 已有实例在运行（锁文件 {$lockFile} 被占用），本次退出\n";
        exit(0);
    }

    try {
        $stats = RetailBatchUpload::run($tasks, $upload, dryRun: false, limit: $limit, report: $report);
    } finally {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }

    printSummary($stats, $limit, false);

} catch (\Exception $e) {
    echo "[upload_pending_retail] 错误: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * 末尾统计（票面第 1 条点名要两个数：跳过未识别的条数 + 成功/失败）。
 *
 * 失败**不改变退出码**：上传失败是业务结果，已经落在失败记录页等人处置；脚本本身没出错。
 * 非零退出只留给"参数错、取数失败"这类脚本跑不成的情形（与既有脚本同口径）。
 */
function printSummary(array $stats, ?int $limit, bool $dryRun, int $notReady = 0): void
{
    if ($dryRun) {
        echo "[upload_pending_retail] --dry-run 小结: 计划处理 " . $stats['queued'] . " 单 / "
            . $stats['codes'] . " 码；跳过非门店企业（含未识别）{$stats['skipped']} 条\n";
        if ($notReady > 0) {
            // 预演是**上界**：这一行把"其中多少单真跑必被拒"说清楚（逐条也各标了一处）
            echo "[upload_pending_retail] 注意: 其中 {$notReady} 单所属门店凭据未配齐——真跑会被三关拒绝、一次平台调用都不发\n";
        }
        echo "[upload_pending_retail] 预演未取锁、未调平台接口、未写任何库\n";
    } else {
        echo "[upload_pending_retail] 完成: 成功 {$stats['success']} 单 / 失败 {$stats['failed']} 单"
            . "（共处理 {$stats['queued']} 单）\n";
        echo "[upload_pending_retail] 跳过非门店企业（含未识别）{$stats['skipped']} 条\n";
    }

    if ($stats['remaining'] > 0) {
        // --limit 挡住的那批：说清楚"还剩多少没碰"，别让人以为队列已经清空了
        echo "[upload_pending_retail] --limit={$limit} 限定，队列里还有 {$stats['remaining']} 条没碰\n";
    }
}
