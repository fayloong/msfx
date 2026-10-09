<?php
/**
 * 核查「本地记着已上传成功」的门店单**在平台上到底有没有**——没有的，清掉那条假记录。
 * 用法: php scripts/check_uploaded_retail.php --company=名,名 --from=YYYY-MM-DD [--dry-run] [--limit=N]
 *
 * 为什么需要它（本脚本要解决的问题）：
 *   `upload_logs` 里有一批 `source='retail_external'` 的「上传成功」记录，判据是**源库状态表**
 *   （`response.judged_by = dyt.bs_msfx.dbo.update_state`）。那张表记的是**外部系统的行为**，不是
 *   平台的承诺——ADR 0018 因此把它的读侧整体停用（采集不再按它分流、状态闭环不再按它翻正）。
 *   但 2026-10-05 之前写下的那批记录**还留在库里**，其中判据来自那张表的部分**从未被平台核实过**：
 *   外部系统写了一条痕迹而单其实没传成时，这些记录会让**已上传页谎报**，还会把单据挡在
 *   `decide()` 的"本地已有成功记录 → 跳过"那一格里——采集因此永远不给它建任务。
 *
 * 本脚本补的就是这一格：拿**各门店自己的凭据**调 `lsyd.query.upbilldetail` 逐条问平台，判据复用
 *   `ApiClient::isBillFound()`（与平台核查、数量对账同一份）。
 *
 * ⚠️ **单号形态：源单号查不到时要再试 `RK` + 单号**（2026-10-09 实测，见 docs/adr/0019）。
 *   外部系统上传 **104（调拨入库）** 时，平台侧的单号是 `RK` + 源库单号，**源单号在平台上精确
 *   匹配不到**。本脚本首次运行（2026-10-09）没有这一步，把两家店 34 条**真实**的「上传成功」
 *   记录判成了假记录并删除——同日已从 JSONL 存档全部回滚（与备份库逐行比对一致）。
 *   现在的判据是**两步都查不到才算"没有"**：多花一次调用，好过把真记录判伪。
 *   （同样的盲区也在 `check_bill_status_retail.php` 里：它至今看不见任何 RK 形态的单，
 *   104 的待办因此永远翻不正——修复是另一票，见 docs/adr/0019 的 Consequences。）
 *
 * 三种结果的处置（**只有第一类会写库**）：
 *   - 平台"信息不存在" 且 记录来源是 `retail_external` → **删掉那条记录**。它是"外部系统说传了"的
 *     转述，平台既然说没有，留着它只会让已上传页继续谎报，并挡住采集建任务。删除前把**整行**连同
 *     平台答复写进 JSONL（`type=retail_uploaded_disproved`，logs/api_<日期>.jsonl **永久保存**）
 *     ——证据不丢，而 `upload_logs` 本身 3 个月后会被 cleanup_logs 清掉，本就不是长久的存档。
 *     删完**必须按日期重采**（`fetch_bills_retail.php <日期>`）才会建出「等待上传」任务：
 *     补传装配要 `bill_type`/`from_user_id`/`to_user_id`/`physic_type` 四列，而 `upload_logs`
 *     一列都没有——只有源库有，故重建必须走采集那条路（脚本末尾会把这几个日期打出来）。
 *   - 平台"信息不存在" 而记录来源**不是** `retail_external`（如 `retail_retry`，本项目自己传的、
 *     平台当次答复过 `上传成功`）→ **只报警、不删**：那是"平台丢单"级别的异常，值得人去看，
 *     不是本脚本该顺手抹掉的
 *   - 查询异常（网络/平台错误）→ 跳过不修改："不知道"不等于"没上传"
 *
 * 与 `check_bill_status_retail.php` 的分工：那个问平台的是**待办**（等待上传的任务 ＋ 补传失败的
 * 记录），方向是"其实传上去了 → 翻正"；本脚本问的是**已判成功的记录**，方向相反。两者都只发查询
 * 接口，都不发申报。取数口径各自独立（那个走 `pendingItems()`，本脚本按 `company + rq` 直查），
 * 因为"待办"这个概念对本脚本不适用——已成功的记录压根不在待办清单里。
 *
 * **不进 crontab**：它是一次性核查工具（一家店配齐凭据后核一次即可），且删除是不可逆动作；
 * 日常的"平台上有／没有"由 `check_bill_status_retail.php` 每 30 分钟一轮盯着。
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
use App\Database;
use App\Enterprise;
use App\LogWriter;
use App\RetailExternalUploads;

Config::load();

/** 每条查询之间的间隔（微秒）——与平台核查同速（1 秒 2 次，同一个限流池） */
const QUERY_INTERVAL_US = 500000;

/** 「已上传成功」的取值（与全站同一个成功口径：单据已在平台上） */
const SUCCESS_STATUSES = ['上传成功', '单据重复'];

// ── 参数：--company=名,名（必填）/ --from=Y-m-d（必填）/ --dry-run / --limit=N ──
$companies = null;
$from = null;
$dryRun = false;
$limit = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int)$m[1];
        if ($limit < 1) {
            echo "[check_uploaded_retail] 参数无效: {$arg}（--limit 至少为 1；只想看清单用 --dry-run）\n";
            exit(1);
        }
    } elseif (preg_match('/^--company=(.+)$/', $arg, $m)) {
        $companies = array_values(array_filter(
            array_map('trim', explode(',', $m[1])),
            static fn(string $v): bool => $v !== ''
        ));
    } elseif (preg_match('/^--from=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $from = $m[1];
    } else {
        echo "[check_uploaded_retail] 未知参数: {$arg}\n";
        echo "用法: php scripts/check_uploaded_retail.php --company=名,名 --from=YYYY-MM-DD [--dry-run] [--limit=N]\n";
        exit(1);
    }
}

// 两个参数都必填：不给 --company 就会把全站十几家店的记录一起核（几万条调用），
// 不给 --from 就没有范围——两种"没写清楚"都得在发调用**之前**停下来
if ($companies === null || $companies === []) {
    echo "[check_uploaded_retail] 必须给 --company=名,名（企业全名，逗号分隔）\n";
    exit(1);
}
if ($from === null) {
    echo "[check_uploaded_retail] 必须给 --from=YYYY-MM-DD（核查该单据日期起的记录）\n";
    exit(1);
}

// 未知企业名**直接退出 1**（在取锁与任何查询之前）：与 check_bill_status_retail 同一个立场——
// 写错店名会让"核查了一整轮、一条没查"看起来像"这家店本来就没记录"
foreach ($companies as $name) {
    if (Enterprise::find($name) === null) {
        echo "[check_uploaded_retail] 未知企业名: {$name}（config/enterprises.php 里没有这家）\n";
        exit(1);
    }
}

// flock 防并发：真跑才取锁（--dry-run 一个字节都不写）
$lockFp = null;
if (!$dryRun) {
    $lockFile = __DIR__ . '/../logs/check_uploaded_retail.lock';
    $lockFp = fopen($lockFile, 'w+');
    if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
        if ($lockFp) {
            fclose($lockFp);
        }
        echo "[check_uploaded_retail] 已有实例在运行（锁文件 {$lockFile} 被占用），本次退出\n";
        exit(0);
    }
}

// 预演**照发平台查询**（那正是要给人看的信息），只是不删任何记录——与平台核查那边
// "--dry-run 一次调用都不发"的取舍不同：那个脚本的预演用途是"看清单有多大"，
// 而本脚本的用途是"平台到底怎么说"，不发调用就等于什么都没查
echo '[check_uploaded_retail] ' . ($dryRun ? '预演（平台查询照发，但不删任何记录）' : '开始核查') . "\n";

try {
    $db = Database::getInstance();

    // ── 取数：本地记着「上传成功」的记录（按 (company, djbh) 定位，含全部列以便原样存档）──
    $placeholders = implode(',', array_fill(0, count($companies), '?'));
    $statusPlaceholders = implode(',', array_fill(0, count(SUCCESS_STATUSES), '?'));
    $rows = $db->query(
        "SELECT * FROM upload_logs
          WHERE company IN ({$placeholders})
            AND response_status IN ({$statusPlaceholders})
            AND rq >= ?
          ORDER BY company, rq, djbh",
        array_merge($companies, SUCCESS_STATUSES, [$from])
    );

    if ($rows === []) {
        echo "[check_uploaded_retail] 没有符合条件的记录（{$from} 起、指定企业）\n";
        exit(0);
    }

    // ── 分组取凭据：取不到的门店整组跳过（没有 ref_ent_id 这条判据不成立，与平台核查同一开关）──
    $byCompany = [];
    $skipped = [];
    foreach ($rows as $row) {
        $company = (string)$row['company'];
        $credential = Enterprise::credentialFor($company);
        if ($credential === null
            || !Enterprise::isRetail($company)
            || !Enterprise::credentialConfigured($credential)) {
            $skipped[$company] = ($skipped[$company] ?? 0) + 1;
            continue;
        }
        $byCompany[$company][] = $row;
    }
    foreach ($skipped as $company => $n) {
        echo "[check_uploaded_retail] 跳过 {$company}：取不到可用凭据，{$n} 条\n";
    }

    $queue = [];
    foreach ($byCompany as $company => $companyRows) {
        foreach ($companyRows as $row) {
            $queue[] = [$company, $row];
        }
    }
    $remaining = 0;
    if ($limit !== null && count($queue) > $limit) {
        $remaining = count($queue) - $limit;
        $queue = array_slice($queue, 0, $limit);
    }

    echo "[check_uploaded_retail] 将核查 " . count($queue) . " 条（" . count($byCompany) . " 家门店，每条间隔 "
        . (QUERY_INTERVAL_US / 1000) . "ms）" . ($remaining > 0 ? "，另有 {$remaining} 条未查（--limit）" : '') . "\n";

    // 按 AppKey 缓存客户端（同一门店的 N 条共用一个 TopClient）
    $clients = [];
    $absent = [];       // 平台说没有的（企业 => 单号 => ['row' => …, 'platform' => …]）
    $absentForeign = []; // 平台说没有、但记录不是 retail_external 的（只报警不删）
    $found = 0;
    $errors = 0;

    foreach ($queue as [$company, $row]) {
        $djbh = (string)$row['djbh'];
        $credential = Enterprise::credentialFor($company);
        $appkey = (string)$credential['appkey'];
        $clients[$appkey] ??= ApiClient::forCredential($credential);
        $refEntId = (string)$credential['ref_ent_id'];

        $matchedForm = $djbh;
        try {
            $result = $clients[$appkey]->queryUpbillDetail($djbh, $refEntId);

            // ⚠️ **源单号查不到时，必须再试一次 `RK` + 单号**——这是 2026-10-09 首次运行的教训：
            // 外部系统上传 **104（调拨入库）** 时，平台侧的单号是 `RK` + 源库单号，**源单号在平台上
            // 查不到**（`lsyd.query.upbilldetail` 是精确匹配，不是子串）。少了这一步，一张真实
            // 已上传的单会被判成"平台上没有"，本脚本据此删掉了 34 条**真**记录（已从 JSONL 存档
            // 全部回滚，逐行比对与核查前一致）。同样的盲区也存在于 `check_bill_status_retail.php`，
            // 见 docs/adr/0019。
            // 判据保守：两步都查不到才算"没有"——多花一次调用，好过把真记录判伪
            if ($result['error'] === '' && empty($result['found'])) {
                usleep(QUERY_INTERVAL_US);
                $alt = $clients[$appkey]->queryUpbillDetail('RK' . $djbh, $refEntId);
                if ($alt['error'] === '' && !empty($alt['found'])) {
                    $result = $alt;
                    $matchedForm = 'RK' . $djbh;
                }
            }
        } catch (\Throwable $e) {
            $result = ['found' => false, 'response' => null, 'error' => $e->getMessage()];
        }

        $error = (string)($result['error'] ?? '');
        if ($error !== '') {
            $errors++;
            echo "  查询异常 {$company} {$djbh}：{$error}\n";
        } elseif (!empty($result['found'])) {
            $found++;
            if ($matchedForm !== $djbh) {
                // 单号形态与源库不同（RK 前缀）——打出来，别让它悄悄过去：这个事实本身有价值
                echo "  平台上在（单号形态 {$matchedForm}）{$company} {$djbh}\n";
            }
        } else {
            $entry = [
                'row' => $row,
                'platform' => [
                    'ref_ent_id' => $refEntId,
                    'matched_form' => $matchedForm,
                    'tried' => [$djbh, 'RK' . $djbh],
                    'msg_code' => $result['response']['result']['msg_code'] ?? null,
                    'msg_info' => $result['response']['result']['msg_info'] ?? null,
                ],
            ];
            if ((string)$row['source'] === RetailExternalUploads::SOURCE) {
                $absent[$company][$djbh] = $entry;
                echo "  平台上没有 {$company} {$djbh}（{$row['rq']}，来源 {$row['source']}）→ 将删除该记录\n";
            } else {
                $absentForeign[$company][$djbh] = $entry;
                echo "  !! 平台上没有 {$company} {$djbh}（{$row['rq']}）但来源是 {$row['source']}"
                    . "——本项目自己传的、平台当次答复过成功，**不删**，请人工查\n";
            }
        }

        usleep(QUERY_INTERVAL_US);
    }

    // ── 落库：删掉被判伪的记录，每条先写 JSONL 存档（整行 + 平台答复）──
    $removed = 0;
    $dates = [];
    if (!$dryRun && $absent !== []) {
        $logWriter = new LogWriter();
        foreach ($absent as $company => $byDjbh) {
            foreach ($byDjbh as $djbh => $entry) {
                $row = $entry['row'];
                $logWriter->writeJsonlOnly([
                    'type' => 'retail_uploaded_disproved',
                    'reason' => '平台查询（lsyd.query.upbilldetail）该单号"信息不存在"，'
                        . '本地这条「上传成功」记录的判据是源库状态表、从未经平台核实（ADR 0018），故判伪删除',
                    'company' => $company,
                    'djbh' => $djbh,
                    'rq' => $row['rq'],
                    'removed_log_id' => $row['id'],
                    'removed_record' => $row,
                    'platform' => $entry['platform'],
                    'next' => '按日期重采（fetch_bills_retail.php）建「等待上传」任务后方可补传',
                ]);
                $removed += $db->execute('DELETE FROM upload_logs WHERE id = ?', [$row['id']]);
                $dates[(string)$row['rq']] = true;
            }
        }
    } elseif ($absent !== []) {
        // 预演：把要删的记录也列出来（dates 只为打印重采日期）
        foreach ($absent as $byDjbh) {
            foreach ($byDjbh as $entry) {
                $dates[(string)$entry['row']['rq']] = true;
            }
        }
    }

    // ── 汇总 ──
    $absentCount = 0;
    foreach ($absent as $byDjbh) {
        $absentCount += count($byDjbh);
    }
    $foreignCount = 0;
    foreach ($absentForeign as $byDjbh) {
        $foreignCount += count($byDjbh);
    }

    echo "\n[check_uploaded_retail] 核查完成: 平台上在 {$found} / 不在 {$absentCount}"
        . ($foreignCount > 0 ? " / **不在但来源存疑 {$foreignCount}**" : '')
        . " / 异常 {$errors} / 跳过 " . array_sum($skipped) . " 条\n";

    if ($dryRun) {
        echo "[check_uploaded_retail] 预演结束：查了平台 {$found}＋{$absentCount}＋{$errors} 条，一条记录都没删\n";
    } else {
        echo "[check_uploaded_retail] 已删除判伪记录 {$removed} 条（整行已存 JSONL: logs/api_" . date('Y-m-d') . ".jsonl）\n";
    }

    if ($dates !== []) {
        $list = array_keys($dates);
        sort($list);
        echo "[check_uploaded_retail] 这些日期需要**按日期重采**才会建出任务（补传要的四列只有源库有）：\n";
        echo '  ' . implode(' ', $list) . "\n";
        echo "  例: php scripts/fetch_bills_retail.php {$list[0]}\n";
    }
} catch (\Exception $e) {
    echo '[check_uploaded_retail] 错误: ' . $e->getMessage() . "\n";
    exit(1);
}
