<?php
/**
 * 从零售源库（dyt 链接服务器）采集门店单据，按源库状态表**分流**落库
 * 用法: php scripts/fetch_bills_retail.php [日期 Y-m-d | --all] [--dry-run]
 *   缺省/日期  → 只采该日的单据（默认当天），**cron 走这条**
 *   --all      → 全量快照入口（不带等值日期条件；下限 2 年）：**一次性、别挂 cron**。按分流规则
 *                落库，但**未上传的历史单一律不建任务**（见下"快照口径"）
 *   --dry-run  → 预演：只读源库、只统计并打印「将写入 N 单 / M 码」，**不落任何库、不写基线、
 *                不调平台接口**；可与日期或 --all 组合
 *
 * 分流（2026-10-02 票 02 起，见 .scratch/retail-collection-split/spec.md §1）：
 *   已上传的（源库状态表里有该单号）→ 写一条「外部上传」记录进已上传记录页，**不建**补传任务；
 *   未上传的                        → 照旧建「等待上传」任务，由人在上传任务页补传。
 * 判据与记录形状都在 App\RetailExternalUploads——EXISTS 子查询，**不能**用 JOIN（那张表无主键、
 * 无唯一约束，实测 82 个单号是多行，JOIN 会把结果集放大）。测试阶段那条 NOT EXISTS 整批过滤
 * 就此结束：已上传的单从此在页面上可见，不必回源库查"这张单到底传没传"。
 *
 * **快照 --all 的口径（票 05）**：只写已上传记录，**未上传的历史单不建任务**——一次跑出约三万
 * 七千条「等待上传」人工处理不现实，还会把待补传这份工作清单的信号淹没；工作队列由日常采集
 * 按日累积（今天采今天的）。代价是窗口内未上传的历史单在本系统里**不可见**，所以跑完必须把
 * 那个数打印出来（"窗口内未上传 N 张，未建任务"），让欠账是**可查的数**而不是看不见的洞。
 * 判定落在 RetailExternalUploads::ACTION_COUNT_ONLY（`decide(..., buildTasks: false)`），
 * **日常口径一字不改**（默认参数）——`--all` 与日常共用下面这套取数/认领/落库，只差这一格。
 *
 * 状态闭环（2026-10-02 票 03）：**每轮采集前**先拿本地待办清单（还挂着的门店任务 ＋ 零售企业的
 * 补传失败记录）去状态表核对，外部系统**后来**才把某张单传成的痕迹就地翻正——任务是"先入队、
 * 后上传"的那批，分流（只看写入那一刻的判据、不回头改已有行）管不到它们。清单按单号查、不按
 * 日期扫源库，故**跨日有效**；同样只读源库、不调平台接口。判定与动作全在
 * App\RetailExternalUploads::closeLoop() / closureActions()。
 *
 * 计数门卫（2026-10-02 票 04）：**闭环之后、采集之前**先数三个数（当日总数 / 已上传 / 未上传），
 * 与上次基线一致就跳过整轮采集并打印原因——日志从此能区分"今天真没新单"与"脚本压根没跑"，
 * 顺带省掉本地那段空转（去重、认领、写库；源库那一趟计数查询省不掉，它只有百毫秒级）。
 * 判定、基线读写与"只在采集成功后才写基线"的顺序铁律都在 App\RetailCollectionGate，
 * 基线文件 `data/fetch_bill_counter_retail.json`（**与批发的 fetch_bill_counter.json 各一个**）。
 * 三处刻意：① 计数查询失败 = 无基线、照常采集（门卫跳过的是一整轮采集，方向必须朝"宁可多跑一轮"，
 * 与批发那边相反——零售没有第二道兜底）；② `--all` 绕过（快照的计数口径是两年窗口，不是当日）；
 * ③ 插在闭环**之后**（见下方接力注释）。
 *
 * **`--dry-run` 不经过门卫**（票 04 留的接线欠账）：门卫的产物是基线，而预演"什么都不写"——
 * 若写成"照常过门卫、只是采集空转"，预演会把基线写掉，下一轮 cron 据此判"三个数没变"而少采
 * 一轮。同理预演也不跑状态闭环（闭环会翻任务行、追加记录）。两条都在下面分流调用点写明了。
 *
 * 只采集入库、不上传——零售单据由外部系统上传，本项目只做"可见 + 人工补传"（见 docs/adr/0007）。
 * 未上传的落库 task_status='等待上传'、source='retail'——**与批发共用一个状态值**（2026-10-01 统一，
 * 见 docs/adr/0014）。门店单的"等待上传"**不代表 cron 会来取走它**：自动上传链路对它们零动作
 * （取数侧 company 白名单 + UploadService 的 fail-closed 守卫），补传始终由人点。
 *
 * 落库的元数据必须够人工补传装配用（工单 06）：除追溯码外还要 from_user_id / to_user_id /
 * physic_type——补传时不会回头问源库，这三列缺一列这条单就永远补不出去（见 ADR 0010）。
 *
 * 源表（全程只读 SELECT，不调任何平台接口、不写源库）：
 *   dyt.msfx.dbo.zsm_ls           单据头（bill_time 是 varchar(10) 纯日期 'YYYY-MM-DD'）
 *   dyt.msfx.dbo.zsm_ls_code      追溯码，**一码一行**（列名误导），无排序列、bs 恒为 1
 *   状态表（表名见 App\RetailExternalUploads::TABLE）
 *                                 外部系统的上传状态（单号 + 状态两列）——**只读**，用来分流、
 *                                 闭环核对与计数；本项目对该表唯一的写入在补传链路（回写，见 ADR 0016）
 *
 * 五条沿途保留的口径（改动前什么样、现在还是什么样）：
 *   1. **单条 SQL**：zsm_ls LEFT JOIN zsm_ls_code，不分"先头后码"两步；`order by ls.bill_code`
 *      是票 05 加的，为的是让同一单号的行连续（见下"逐行消费"），不是两条查询。
 *   2. **默认按 bill_time 限当日**（cron 的口径）；`bill_time >= 下限` **始终在**（2 年，见
 *      App\RetailRetention）；显式指定超期日期在上面已直接拒绝并退出 1。
 *   3. LEFT JOIN 会放大行数：321 存在 14 列值全同的重复行，同一 bill_code 最多 120 行。
 *      故去重挪在 PHP 侧——追溯码用关联数组去重（保序，同 zsm_ls_code 的一码一行），
 *      单据头字段（含已上传标志）取该单首次出现的行（重复行各列本就相同）。
 *   4. 认领走 App\Enterprise::claim()（见 ADR 0008）：按单据类型取 ID 列（321/116 → from_user_id，
 *      104/203 → to_user_id）命中门店登记过的任一平台 ID，ID 缺失才回退 oper_ic_name 与门店名
 *      精确相等；都不命中 → company='未识别' 照常入库（丢单比错标更危险）。
 *   5. 幂等按 (company, djbh)（裸单号在生产库里并不唯一），重跑既不重复建任务也不产生第二条
 *      成功记录。这一条在"逐行消费"之后**更要紧**：见下 ②。
 *
 * **逐行消费（票 05）**：两年窗口实测去重后 **54,085 单 / 558,981 行**（票面原先按"104,995 单"
 * 估的其实是**行数**——与门卫票 04 撞见的是同一个坑）。故 SQL 加 `order by ls.bill_code`、
 * PHP 侧**按键切换**：同一单号的行读完之后收进批，批满 RETAIL_WRITE_BATCH 就落一批。
 * 一句话交代这到底解决了什么（免得后人以为它救过一次溢出）：旧写法（全攒进 `$bills` 再统一落库）
 * 在**当前**规模下实测内存峰值 **84.5 MB**——128M 的上限下跑得过，只是余量只剩三成；而窗口再长
 * 一倍就会当场溢出。流式把内存压到 **6–12 MB** 并让它**与窗口规模无关**（票 05 之前那个
 * "十万单就过线"的估算因此不准确），顺带把落库变成分批事务——那一条才是硬需求，见 ③。
 * 三处后果，都是刻意的：
 *   ① 内存与批大小同阶，与窗口里有多少单据无关（快照与日常走同一条流式代码，只有一套实现）；
 *   ② **落库与读源库交错进行**（旧写法是"源库全部读完才开始写"）：中途失败会留下已经落库的
 *      那几批。可以接受，因为落库**幂等**（上一条）——重跑按 (company, djbh) 跳过已有的，
 *      剩下的补齐即可；门卫那条"基线只在整轮成功后写"也随之兜住（抛异常即不写基线 → 下轮重采）；
 *   ③ 每批的落库包在**一次事务**里（App\Database::transaction()）：单条 INSERT 一个隐式事务 =
 *      一次 fsync，本机实测 21–28 ms/条，快照那六万多条记录按"一条一提交"要等 25–30 分钟磁盘，
 *      攒批后（实测 68,000 条 / 每批 500）3.7 秒。
 *
 * 采集口径见 .scratch/retail-collection-split/spec.md；建议 cron: 与 fetch_bills.php 同频
 * （零售采集不调平台 API，不受 8-20 点限流窗口约束）
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

use App\BillType;
use App\Config;
use App\Database;
use App\Enterprise;
use App\LogWriter;
use App\RetailCollectionGate;
use App\RetailExternalUploads;
use App\RetailRetention;

Config::load();

/** 采集的四种单据类型（写死）。`999` 语义未明，用户判定不采（见探测结论）。
 *  门卫数的也是这四种（`RetailCollectionGate::counts()` 由这里传进去）——**两边必须是同一份
 *  清单**：门卫数窄了会跳过"有单要采"的那一轮，多出来的单要等到别的单挪动计数才被采到。 */
const RETAIL_BILL_TYPES = [104, 203, 321, 116];

/**
 * 一批攒多少张单据再落库（见头部"逐行消费"③）。
 *
 * 与 `RetailExternalUploads::IN_CHUNK_SIZE` 同值是有意的：批内查本地痕迹（`djbh IN (…)`）
 * 正好一块，不会因为批比块大而多切几刀。**别调大太多**：落库那一批持有 SQLite 写锁，
 * cron 的采集正靠 busyTimeout(30s) 等锁——一批 500 条约 50 ms。
 */
const RETAIL_WRITE_BATCH = 500;

// ── 参数：日期 / --all / --dry-run（顺序随意，可组合；`--all` 与具体日期互斥）──
$snapshotAll = false;
$dryRun = false;
$dateArg = null;
$badArg = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--all') {
        $snapshotAll = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg)) {
        $dateArg = $arg;
    } else {
        $badArg = $arg;
        break;
    }
}

if ($badArg !== null) {
    echo "[fetch_bills_retail] 参数无效: {$badArg}，需要 YYYY-MM-DD、--all、--dry-run（后两者可组合）\n";
    exit(1);
}
if ($snapshotAll && $dateArg !== null) {
    // 快照的语义是"整个两年窗口"，再给一个具体日期没有意义——宁可拒绝，也别让人以为它俩叠加了
    echo "[fetch_bills_retail] 参数冲突: --all 与具体日期（{$dateArg}）不能同时给\n";
    exit(1);
}

// 采集日期：--all 为 null（不加等值日期条件，全量快照）；否则缺省当天。
// 这个 null 同时也是门卫的"不适用"信号（快照没有"当日"这个口径，见 App\RetailCollectionGate）
$date = $snapshotAll ? null : ($dateArg ?? date('Y-m-d'));

// 平台硬性规定不接受 2 年前的单据（见 App\RetailRetention）。显式指定一个超期日期时直接拒绝：
// 静默采回 0 条会让人以为"那天真没单据"，而真相是那天即使有单也补传不出去。
$retentionCutoff = RetailRetention::cutoffDate();
if ($date !== null && $date < $retentionCutoff) {
    echo "[fetch_bills_retail] 拒绝采集: {$date} 早于保留下限 {$retentionCutoff}——平台不接受 2 年前的单据（App\\RetailRetention）\n";
    exit(1);
}

if ($snapshotAll) {
    echo "[fetch_bills_retail] 开始采集（--all 全量快照：只写已上传记录，未上传的历史单不建任务；下限 {$retentionCutoff}）\n";
    echo "[fetch_bills_retail] 注意: 这是一次性入口，跑一次即可——**别挂进 cron**\n";
} else {
    echo "[fetch_bills_retail] 开始采集，日期: {$date}（按源库状态表分流：已上传→外部上传记录，未上传→等待上传任务）\n";
}
if ($dryRun) {
    echo "[fetch_bills_retail] --dry-run 预演: 只读源库、只统计，不落任何库、不写基线、不调平台接口\n";
}

/**
 * 落一批单据：查本地已有痕迹 → 认领 → 分流判定 → 写记录 / 建任务 / 跳过 / 只计数。
 *
 * 本函数只做编排与输出：判定在 `App\RetailExternalUploads::decide()`、统计口径在 `tally()`、
 * 记录形状在 `buildRecord()`——三处都不在这里重复一遍（重复一遍就会有两份能各自跑偏的规则）。
 *
 * 批内**先全部判定完再落库**：这样"写"那一段能整批包进一次事务（见头部"逐行消费"③），
 * 而 `$dryRun` 时连事务都不开——预演一个字节都不写（含逐条认领告警的 JSONL）。
 *
 * @param array<string,array<string,mixed>> $bills 一批单据（单号 => 单据头字段 + uploaded + codes）
 * @param bool          $snapshotAll `--all` 快照口径：未上传的历史单不建任务（传给 decide 的 buildTasks）
 * @param bool          $dryRun      预演：只统计不落库
 * @param array<string,int> $tally   本轮统计（累加）。动作那五个键由 `RetailExternalUploads::tally()`
 *                                   维护；`uploaded` / `unuploaded` / `unidentified` / `claim_warned`
 *                                   是**单据事实**的计数，由本函数维护（快照末尾那几句要它们）
 */
function flushRetailBatch(array $bills, bool $snapshotAll, bool $dryRun, array &$tally): void
{
    if ($bills === []) {
        return;
    }

    $db = Database::getInstance();
    $logWriter = new LogWriter();
    $now = date('Y-m-d H:i:s');

    // 本地已有痕迹**分两张查**：两条分支的幂等判据不同（见 decide()）——已上传的单只看"有没有
    // 成功记录"（已有任务行不拦它：那条任务行是本地待办痕迹，由状态闭环翻正），未上传的单则是
    // "任务行或成功记录任一存在"就跳过。合成一个集合会让已上传分支误跳过、记录写不出来。
    // 判据键均为 (company, djbh)：别家企业的同名单号不算"已有"（生产库里裸单号并不唯一）。
    $taskSet = [];
    $successSet = [];
    foreach (array_chunk(array_keys($bills), RetailExternalUploads::IN_CHUNK_SIZE) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        foreach ($db->query(
            "SELECT company, djbh FROM upload_tasks WHERE djbh IN ({$placeholders})",
            $chunk
        ) as $row) {
            $taskSet[$row['company']][$row['djbh']] = true;
        }
        foreach ($db->query(
            "SELECT company, djbh FROM upload_logs WHERE djbh IN ({$placeholders}) AND response_status IN ('上传成功', '单据重复')",
            $chunk
        ) as $row) {
            $successSet[$row['company']][$row['djbh']] = true;
        }
    }

    // ── 认领 + 判定（先算完，落库在下一段）──
    $decided = [];
    foreach ($bills as $djbh => $bill) {
        $billType = BillType::normalize($bill['bill_type'], $djbh);
        $organName = trim($bill['oper_ic_name']);
        $claim = Enterprise::claim($billType, $bill['from_user_id'], $bill['to_user_id'], $organName);
        $company = $claim['company'];

        $tally['uploaded'] = ($tally['uploaded'] ?? 0) + ($bill['uploaded'] ? 1 : 0);
        $tally['unuploaded'] = ($tally['unuploaded'] ?? 0) + ($bill['uploaded'] ? 0 : 1);

        // 源库写了机构名、却对不上任何门店（ID 认到但名字不符，或名字与 ID 都不命中）→
        // 疑似错名/改名/已关店：只记 JSONL 警告，**不改判定**（照常按认领结果落库）
        if ($claim['name_unmatched']) {
            $tally['claim_warned'] = ($tally['claim_warned'] ?? 0) + 1;
            if (!$dryRun) {
                // 只进 JSONL、不进 upload_logs（后者是"上传结果"日志，写进去会在失败记录页冒出
                // 既非上传也非失败的记录，污染唯一的告警出口，见 docs/adr/0007）
                $logWriter->writeJsonlOnly([
                    'type' => 'retail_claim_name_unmatched',
                    'djbh' => $djbh,
                    'bill_type' => $billType,
                    'company' => $company,
                    'company_key' => $claim['company_key'],
                    'matched_by' => $claim['matched_by'],
                    'organ_name' => $organName,
                ]);
                $reason = $claim['matched_by'] === 'id'
                    ? "按 ID 认到 {$company}，但源库机构名「{$organName}」对不上任何门店"
                    : "认领不到门店：源库机构名「{$organName}」未登记，ID 也未命中";
                echo "[fetch_bills_retail] 警告: 单号 {$djbh} {$reason}\n";
            }
        }

        if ($claim['matched_by'] === 'none') {
            $tally['unidentified'] = ($tally['unidentified'] ?? 0) + 1;
        }

        // 分流决定（判据与幂等规则见 App\RetailExternalUploads::decide）
        // 按名传参：后三个都是同型的 bool，位置传参写反了没有任何东西会拦
        $action = RetailExternalUploads::decide(
            uploaded: $bill['uploaded'],
            hasTask: isset($taskSet[$company][$djbh]),
            hasSuccess: isset($successSet[$company][$djbh]),
            buildTasks: !$snapshotAll
        );
        $tally = RetailExternalUploads::tally($tally, $action, count($bill['codes']));

        $decided[] = ['action' => $action, 'djbh' => $djbh, 'bill' => $bill, 'claim' => $claim, 'bill_type' => $billType];
    }

    // 预演到此为止：判定与统计都做完了（进度也报了），一个字节都不写
    if ($dryRun) {
        printRetailProgress($tally, $snapshotAll, true);
        return;
    }

    // ── 落库：整批一次事务（见头部"逐行消费"③）──
    $db->transaction(static function () use ($db, $decided, $now): void {
        foreach ($decided as $item) {
            $bill = $item['bill'];
            $djbh = $item['djbh'];

            if ($item['action'] === RetailExternalUploads::ACTION_RECORD) {
                // 外部系统已上传：只留一条记录进已上传记录页，**不建任务**——这张单没有要人做的事。
                // 记录里 request_status 留空、task_id=0、response 写明出处（见 buildRecord 的注释）
                RetailExternalUploads::record([
                    'djbh'        => $djbh,
                    'rq'          => $bill['bill_time'],
                    'trace_codes' => implode(',', array_keys($bill['codes'])),
                    'company'     => $item['claim']['company'],
                    'credential'  => $item['claim']['credential'],
                ]);
                continue;
            }

            if ($item['action'] !== RetailExternalUploads::ACTION_TASK) {
                continue; // SKIP（本地已有痕迹）/ COUNT_ONLY（快照未建任务）：什么都不写
            }

            // ACTION_TASK：建「等待上传」任务，由人补传。
            // ent_name（往来单位）零售链路用不到，留空——对手方 ID 直接来自源表的 from_user_id/to_user_id，
            // 不查 ent_list（那是批发 kyt 接口把往来单位名换成 ent_id 才需要的缓存）。
            // from_user_id / to_user_id / physic_type 照搬源表同名列：补传装配要用（见 ADR 0010），
            // 除认领外不参与任何判定——三列都是单据头属性，与追溯码一样是"补传时不能现问源库"的输入。
            $db->execute(
                "INSERT INTO upload_tasks (rq, djbh, ent_name, trace_codes, bill_type, task_status, source, company, credential,
                                           from_user_id, to_user_id, physic_type, created_at, updated_at)
                 VALUES (?, ?, '', ?, ?, '等待上传', 'retail', ?, ?, ?, ?, ?, ?, ?)",
                [
                    $bill['bill_time'],
                    $djbh,
                    implode(',', array_keys($bill['codes'])),
                    $item['bill_type'],
                    $item['claim']['company'],
                    $item['claim']['credential'],
                    trim($bill['from_user_id']),
                    trim($bill['to_user_id']),
                    trim($bill['physic_type']),
                    $now,
                    $now,
                ]
            );
        }
    });

    printRetailProgress($tally, $snapshotAll, $dryRun);
}

/**
 * 快照是几分钟的长跑，逐批给一行进度（日志里看得出它在动，而不是卡死了）。
 *
 * 只给快照打：日常一批就完事（几十张单），多一行只是噪音。预演也打，且标出 `--dry-run`
 * ——否则那行"写记录 480"会被读成真写了。真跑时它在**这一批落库之后**打印，数字是既成事实。
 *
 * @param array<string,int> $tally 本轮累计（见 flushRetailBatch）
 */
function printRetailProgress(array $tally, bool $snapshotAll, bool $dryRun): void
{
    if (!$snapshotAll) {
        return;
    }
    printf(
        "[fetch_bills_retail] %s%s 已处理 %d 单: 写记录 %d / 跳过 %d / 未建任务 %d\n",
        $dryRun ? '--dry-run ' : '',
        date('H:i:s'),
        ($tally['records'] ?? 0) + ($tally['count_only'] ?? 0) + ($tally['skipped'] ?? 0),
        $tally['records'] ?? 0,
        $tally['skipped'] ?? 0,
        $tally['count_only'] ?? 0
    );
}

try {
    // 与 TaskFetcher 同一条连接配置（`Config::sqlServer()`，五字段单一来源；
    // 4 段式链接服务器名可在同一连接上直接查，见探测结论）
    $source = new \SqlSrvHelper(Config::sqlServer());

    // 本轮统计（动作计数 + 单据事实计数）：闭包按引用往里累加，采集完由下面的输出段读取
    $tally = [];

    // ── 采集整轮：流式读源库 → 按键切换攒批 → 交 flushRetailBatch 落库（或只统计）──
    // 包成一个闭包是给门卫用的：闭包里抛出的任何异常都会穿过 guard() 且不写基线（源库读取失败、
    // 落库中途失败都算），而"写基线"那句只出现在闭包返回之后——"基线只在整轮采集成功之后才写"
    // 因此是**构造上的性质**，不是一句得靠人记住的话。别把它拆开写成"先采集、后手动写基线"。
    $collect = static function () use ($source, $date, $retentionCutoff, $snapshotAll, $dryRun, &$tally): void {
        // ── 单条 SQL：单据头 LEFT JOIN 追溯码，外加拿一个"已上传"标志列 ──
        // 必须 LEFT JOIN 而非内连接：没码的单也要采——它要么是待补传的一条、要么是一份外部上传记录。
        // physic_type 不在"顺手拷来的老 SQL"里，但补传装配要它（ADR 0010），故显式补上；
        // ref_ent_id 取回来只为与源表列对齐，**本轮不使用**（那是全表单一值的总部主体，见 ADR 0010）。
        //
        // 已上传标志用 **EXISTS 子查询**：状态表无主键、无唯一约束（实测 82 个单号多行），JOIN 会把
        // 结果集放大。SQL Server 不允许在**聚合**里套子查询（门卫的计数 SQL 因此换了写法，见
        // App\RetailCollectionGate），但这里是非聚合的 case when——允许。
        //
        // **order by ls.bill_code 是票 05 加的**（逐行消费的前提，见头部）：同一单号的行必须连续
        // 出现，PHP 侧才能"读完一张收一张、攒够一批落一批"。它排序的代价在源库那边，换来的是一条
        // 与窗口规模无关的内存曲线
        $sql = "select ls.bill_code,ls.bill_time,ls.bill_type,ls.physic_type,
                       ls.from_user_id,ls.to_user_id,ls.ref_ent_id,ls.oper_ic_name,co.trace_codes,
                       case when exists(select 1 from " . RetailExternalUploads::TABLE . " us
                                        where us.bill_code=ls.bill_code) then 1 else 0 end as uploaded
                from dyt.msfx.dbo.zsm_ls ls
                left join dyt.msfx.dbo.zsm_ls_code co on co.bill_code=ls.bill_code
                where bill_type in (" . implode(', ', RETAIL_BILL_TYPES) . ")";

        // 日期条件走参数绑定（bill_time 是 varchar(10) 纯日期，等值比较即日期比较）。
        // **保留下限始终参与查询**：`--all` 靠它把超期单据挡在队列外；显式日期在上面已拒绝过更早的，
        // 这里是纵深；cron 的当日采集天然满足。参数顺序与占位符出现顺序一致（下限在前、等值在后）。
        $params = [$retentionCutoff];
        $sql .= "\n                    AND ls.bill_time >= ?";
        if ($date !== null) {
            $sql .= "\n                    AND ls.bill_time = ?";
            $params[] = $date;
        }
        $sql .= "\n                    order by ls.bill_code";

        echo "[fetch_bills_retail] 正在从源库拉取单据与追溯码...\n";

        $tally = [];
        $batch = [];       // 已经收尾、等着落库的一批（单号 => 单据）
        $openDjbh = null;  // 正在读的单号
        $openBill = null;  // 正在读的那张单（单据头取首次出现的行、码用关联数组去重，同"口径 3"）
        $rawRows = 0;
        $billCount = 0;
        $startedAt = microtime(true);

        $ok = $source->queryEach($sql, $params, static function (array $row) use (
            &$batch, &$openDjbh, &$openBill, &$rawRows, &$billCount, &$tally, $snapshotAll, $dryRun
        ): void {
            $rawRows++;
            $billCode = trim((string)($row['bill_code'] ?? ''));
            if ($billCode === '') {
                return;
            }

            // 换单号 = 上一张的所有行已经读完（order by ls.bill_code 保证同一单号的行连续）
            if ($billCode !== $openDjbh) {
                if ($openDjbh !== null) {
                    $batch[$openDjbh] = $openBill;
                    if (count($batch) >= RETAIL_WRITE_BATCH) {
                        flushRetailBatch($batch, $snapshotAll, $dryRun, $tally);
                        $batch = [];
                    }
                }
                $openDjbh = $billCode;
                $openBill = [
                    'bill_time'    => (string)($row['bill_time'] ?? ''),
                    'bill_type'    => (string)($row['bill_type'] ?? ''),
                    'physic_type'  => (string)($row['physic_type'] ?? ''),
                    'from_user_id' => (string)($row['from_user_id'] ?? ''),
                    'to_user_id'   => (string)($row['to_user_id'] ?? ''),
                    'oper_ic_name' => (string)($row['oper_ic_name'] ?? ''),
                    // 单据头属性：EXISTS 只看单号，同单号各行取值相同
                    'uploaded'     => (int)($row['uploaded'] ?? 0) === 1,
                    'codes'        => [],
                ];
                $billCount++;
            }

            $code = trim((string)($row['trace_codes'] ?? ''));
            if ($code !== '') {
                $openBill['codes'][$code] = true; // 关联数组去重（保序）
            }
        });

        // 查询失败与"真没单据"必须分开：前者非零退出且不再往下走
        // （queryEach 返回 false 即 SQL 出错，错误另存在 lastError 里）
        // 这一抛会穿过门卫 → 基线不写 → 下一轮计数仍不等，自动重采
        if ($ok === false) {
            throw new \RuntimeException('单据查询失败: ' . $source->getErrorMessage());
        }

        // 收尾：最后一张单（它在回调里还没被收进批）+ 不足一批的余量
        if ($openDjbh !== null) {
            $batch[$openDjbh] = $openBill;
        }
        if ($batch !== []) {
            flushRetailBatch($batch, $snapshotAll, $dryRun, $tally);
        }

        if ($billCount === 0) {
            // 源库读通了、只是这天没单——算**采集成功**（与批发采集同口径）：返回而不是 exit，
            // 好让门卫把基线写上，下一轮直接"跳过"而不是每轮都去问一次源库。
            // 这里若写成 exit(0)，空日会永远重跑，且日志上永远看不出脚本跑没跑
            echo "[fetch_bills_retail] 没有需要采集的单据\n";
            return;
        }

        // 这行的数字与门卫数出来的 `total` 是同一个口径（当日采集时逐字相等），运维拿它对账
        echo "[fetch_bills_retail] 拉取到 {$billCount} 张单据（原始 {$rawRows} 行，已按单号去重收口）\n";

        $records = $tally['records'] ?? 0;
        $tasks = $tally['tasks'] ?? 0;
        $skipped = $tally['skipped'] ?? 0;
        $countOnly = $tally['count_only'] ?? 0;
        $codes = $tally['codes'] ?? 0;
        $unidentified = $tally['unidentified'] ?? 0;
        $unuploaded = $tally['unuploaded'] ?? 0;
        $elapsed = round(microtime(true) - $startedAt, 1);
        $memMb = round(memory_get_peak_usage(true) / 1048576, 1);

        if ($dryRun) {
            echo "[fetch_bills_retail] --dry-run 预演（" . ($snapshotAll ? '--all 快照口径' : "日期 {$date}") . "）: "
                . "将写入 " . ($records + $tasks) . " 单 / {$codes} 码（"
                . ($snapshotAll
                    ? "外部上传记录 {$records} 条"
                    : "新增任务 {$tasks} 条、外部上传记录 {$records} 条")
                . "）；跳过 {$skipped} 单\n";
            if ($snapshotAll) {
                echo "[fetch_bills_retail] 窗口内未上传 {$unuploaded} 单（其中 " . ($unuploaded - $countOnly)
                    . " 单本地已有痕迹，不重复写），其余 {$countOnly} 单将不建任务\n";
            }
            echo "[fetch_bills_retail] 预演统计: 认领不到门店 {$unidentified} 单、源库机构名对不上 "
                . ($tally['claim_warned'] ?? 0) . " 单（逐条告警预演不打印；真跑会逐条打并写 JSONL）\n";
            echo "[fetch_bills_retail] 预演未落任何库、未写基线、未调平台接口、未跑状态闭环；"
                . "耗时 {$elapsed} 秒，内存峰值 {$memMb} MB\n";
            return;
        }

        if ($snapshotAll) {
            echo "[fetch_bills_retail] 快照完成: 外部上传记录 {$records} 条（追溯码 {$codes} 个）, 跳过 {$skipped} 条"
                . "（本批认领不到门店的共 {$unidentified} 条）\n";
            // 票面的那句话：欠账必须是**可查的数**。"未上传且本地无痕"才是真正在本系统里看不见的那批
            // ——本地已有任务行/成功记录的未上传单不重复写，也不算欠账（它们在补传队列/已上传页里看得见）
            echo "[fetch_bills_retail] 窗口内未上传 {$unuploaded} 单（其中 " . ($unuploaded - $countOnly)
                . " 单本地已有痕迹，不重复写），其余 {$countOnly} 单未建任务——这些单在本系统里不可见\n";
            echo "[fetch_bills_retail] 快照耗时 {$elapsed} 秒，内存峰值 {$memMb} MB\n";
            return;
        }

        // "本批未识别"的范围是**拉取到的整批**（含被跳过与写成记录的），不只是新增任务那一部分
        echo "[fetch_bills_retail] 采集完成: 新增任务 {$tasks} 条, 外部上传记录 {$records} 条"
            . ", 跳过 {$skipped} 条（本批认领不到门店的共 {$unidentified} 条）\n";
    };

    // ── 预演（--dry-run）：只跑采集那一轮，然后到此为止 ──
    // **它不经过计数门卫**（票 04 留的接线欠账）：门卫的产物是基线，而预演"什么都不写"——
    // 写成"照常过门卫、只是采集空转"的话，预演会把基线写掉，下一轮 cron 据此判"三个数没变"
    // 而少采一轮。状态闭环（下面那段）同理不跑：它会翻任务行、追加记录，都是写库。
    if ($dryRun) {
        $collect();
        exit(0);
    }

    // ── 状态闭环（票 03）：先拿**本地待办清单**去源库状态表核对一遍 ──
    // 放在采集**之前**：它不依赖本批采到什么（待办是跨日的，昨天的单今天才被传成一样能翻），
    // 而采集失败或空批次都不该让闭环漏跑一轮。反过来，闭环追加的成功记录会让同轮采集的
    // decide() 判 SKIP——两条路径对"已上传"给同一个结论，不会一边写记录一边又建任务。
    // 全程只读源库、不调平台接口，故不受 8-20 点限流窗口约束；也不受计数门卫约束（票 04 那套），
    // 它不扫源库大表。翻正哪些痕迹、为什么这么判，见 App\RetailExternalUploads::closeLoop()
    //
    // ⚠️ 门卫必须插在**本段之后**（票 04 就是这么接的，见下面那段）：门卫的"三个数都没变就
    //    直接跳过"跳的是**整轮采集**，而闭环不在它的覆盖范围里（票 03 验收项：每轮都跑、
    //    不受门卫约束）。插到前面，外部系统后来传成的单就不会被翻正了。
    $closure = RetailExternalUploads::closeLoop($source);
    if ($closure['error'] !== null) {
        echo "[fetch_bills_retail] 状态闭环: 源库查询失败，本轮未翻正任何痕迹（{$closure['error']}）\n";
    } else {
        echo "[fetch_bills_retail] 状态闭环: 核对 {$closure['pending']} 条待办 → 翻正任务 {$closure['turned']} 行"
            . ", 追加外部上传记录 {$closure['recorded']} 条\n";
    }

    // ── 计数门卫（票 04）──
    // `--all` 的 date 是 null = 门卫不适用（快照的计数口径是两年窗口，不是当日）：见 guard()。
    // 这里不另写一个 if —— 绕过的开关只有 guard() 那一处，多写一处就是两个能各自跑偏的判据
    $gate = RetailCollectionGate::guard(
        stateFile: RetailCollectionGate::stateFile(),
        date: $date,
        // 类型清单与 2 年下限都从这一处传进去：门卫数的范围必须与下面采集 SQL 的范围逐字一致
        count: static fn(string $d): array => RetailCollectionGate::counts($source, $d, RETAIL_BILL_TYPES, $retentionCutoff),
        collect: $collect,
    );

    // 门卫的结论（无论跳过还是采集，都原样打印它的一句话说明——运维靠这行区分"没新单"与"没跑"）
    echo "[fetch_bills_retail] 计数门卫: {$gate['reason']}\n";
    if ($gate['warning'] !== null) {
        echo "[fetch_bills_retail] 警告: {$gate['warning']}\n";
    }
    if ($gate['skipped']) {
        exit(0);
    }

} catch (\Exception $e) {
    echo "[fetch_bills_retail] 错误: " . $e->getMessage() . "\n";
    exit(1);
}
