<?php
/**
 * 从零售源库（dyt 链接服务器）采集门店单据，按源库状态表**分流**落库
 * 用法: php scripts/fetch_bills_retail.php [日期 Y-m-d | --all]
 *   缺省/日期  → 只采该日的单据（默认当天），**cron 走这条**
 *   --all      → 全量快照入口（不带等值日期条件；下限 2 年）——**当前别跑**，见下方 ⚠️
 *
 * 分流（2026-10-02 票 02 起，见 .scratch/retail-collection-split/spec.md §1）：
 *   已上传的（源库状态表里有该单号）→ 写一条「外部上传」记录进已上传记录页，**不建**补传任务；
 *   未上传的                        → 照旧建「等待上传」任务，由人在上传任务页补传。
 * 判据与记录形状都在 App\RetailExternalUploads——EXISTS 子查询，**不能**用 JOIN（那张表无主键、
 * 无唯一约束，实测 82 个单号是多行，JOIN 会把结果集放大）。测试阶段那条 NOT EXISTS 整批过滤
 * 就此结束：已上传的单从此在页面上可见，不必回源库查"这张单到底传没传"。
 *
 * 状态闭环（2026-10-02 票 03）：**每轮采集前**先拿本地待办清单（还挂着的门店任务 ＋ 零售企业的
 * 补传失败记录）去状态表核对，外部系统**后来**才把某张单传成的痕迹就地翻正——任务是"先入队、
 * 后上传"的那批，分流（只看写入那一刻的判据、不回头改已有行）管不到它们。清单按单号查、不按
 * 日期扫源库，故**跨日有效**；同样只读源库、不调平台接口。判定与动作全在
 * App\RetailExternalUploads::closeLoop() / closureActions()。
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
 *                                 外部系统的上传状态（单号 + 状态两列）——**只读**，用来分流；
 *                                 本项目对该表唯一的写入在补传链路（回写，见 ADR 0016）
 *
 * 四条沿途保留的口径（改动前什么样、现在还是什么样）：
 *   1. **单条 SQL**：zsm_ls LEFT JOIN zsm_ls_code，不分"先头后码"两步。
 *   2. **默认按 bill_time 限当日**（cron 的口径）；`bill_time >= 下限` **始终在**（2 年，见
 *      App\RetailRetention）；显式指定超期日期在上面已直接拒绝并退出 1。
 *   3. LEFT JOIN 会放大行数：321 存在 14 列值全同的重复行，同一 bill_code 最多 120 行。
 *      故去重挪在 PHP 侧——追溯码用关联数组去重（保序，同 zsm_ls_code 的一码一行），
 *      单据头字段（含已上传标志）取首次出现的行（重复行各列本就相同）。`--all` 时行数可能到
 *      数十万，走 queryEach 逐行消费，不经 query() 攒数组（那会撞上 CLI 的 memory_limit=128M）。
 *   4. 认领走 App\Enterprise::claim()（见 ADR 0008）：按单据类型取 ID 列（321/116 → from_user_id，
 *      104/203 → to_user_id）命中门店登记过的任一平台 ID，ID 缺失才回退 oper_ic_name 与门店名
 *      精确相等；都不命中 → company='未识别' 照常入库（丢单比错标更危险）。
 *
 * ⚠️ `--all` **当前别跑**：去掉 NOT EXISTS 后它是"按分流规则全量落库"，会把最近两年窗口内约
 *   三万七千张未上传的历史单**全建成「等待上传」任务**（规模见 spec §1 实测）——人工处理不现实，
 *   还会把待补传这份工作清单的信号淹没。"只写已上传记录、不写历史未上传任务"的新语义由**票 05**
 *   落地；在那之前只跑 cron 那条（当日或指定日期）。
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
use App\RetailExternalUploads;
use App\RetailRetention;

Config::load();

/** 采集的四种单据类型（写死）。`999` 语义未明，用户判定不采（见探测结论） */
const RETAIL_BILL_TYPES = [104, 203, 321, 116];

/** 单号 IN 列表分块大小（规避超长 SQL 与参数上限） */
const IN_CHUNK_SIZE = 500;

$arg = $argv[1] ?? null;
$snapshotAll = ($arg === '--all');

if ($arg !== null && !$snapshotAll && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg)) {
    echo "[fetch_bills_retail] 参数无效: {$arg}，需要 YYYY-MM-DD 或 --all\n";
    exit(1);
}

// 采集日期：--all 为 null（不加等值日期条件，全量快照）；否则缺省当天
$date = $snapshotAll ? null : ($arg ?? date('Y-m-d'));

// 平台硬性规定不接受 2 年前的单据（见 App\RetailRetention）。显式指定一个超期日期时直接拒绝：
// 静默采回 0 条会让人以为"那天真没单据"，而真相是那天即使有单也补传不出去。
$retentionCutoff = RetailRetention::cutoffDate();
if ($date !== null && $date < $retentionCutoff) {
    echo "[fetch_bills_retail] 拒绝采集: {$date} 早于保留下限 {$retentionCutoff}——平台不接受 2 年前的单据（App\\RetailRetention）\n";
    exit(1);
}

if ($snapshotAll) {
    echo "[fetch_bills_retail] 开始采集（--all 全量快照：按分流规则落库，下限 {$retentionCutoff}）\n";
    echo "[fetch_bills_retail] ⚠️ 警告: 当前 --all 会把窗口内**全部未上传**的历史单建成「等待上传」任务\n";
    echo "[fetch_bills_retail]          （实测约三万七千条，人工处理不现实）——「只写已上传记录」的新语义尚未落地，**请勿运行**\n";
    echo "[fetch_bills_retail] 注意: 这是一次性入口，跑一次即可——**别挂进 cron**\n";
} else {
    echo "[fetch_bills_retail] 开始采集，日期: {$date}（按源库状态表分流：已上传→外部上传记录，未上传→等待上传任务）\n";
}

try {
    // 与 TaskFetcher 同一条连接配置（`Config::sqlServer()`，五字段单一来源；
    // 4 段式链接服务器名可在同一连接上直接查，见探测结论）
    $source = new \SqlSrvHelper(Config::sqlServer());

    // ── 状态闭环（票 03）：先拿**本地待办清单**去源库状态表核对一遍 ──
    // 放在采集**之前**：它不依赖本批采到什么（待办是跨日的，昨天的单今天才被传成一样能翻），
    // 而采集失败或空批次都不该让闭环漏跑一轮。反过来，闭环追加的成功记录会让同轮采集的
    // decide() 判 SKIP——两条路径对"已上传"给同一个结论，不会一边写记录一边又建任务。
    // 全程只读源库、不调平台接口，故不受 8-20 点限流窗口约束；也不受计数门卫约束（票 04 那套），
    // 它不扫源库大表。翻正哪些痕迹、为什么这么判，见 App\RetailExternalUploads::closeLoop()
    $closure = RetailExternalUploads::closeLoop($source);
    if ($closure['error'] !== null) {
        echo "[fetch_bills_retail] 状态闭环: 源库查询失败，本轮未翻正任何痕迹（{$closure['error']}）\n";
    } else {
        echo "[fetch_bills_retail] 状态闭环: 核对 {$closure['pending']} 条待办 → 翻正任务 {$closure['turned']} 行"
            . ", 追加外部上传记录 {$closure['recorded']} 条\n";
    }

    // ── 单条 SQL：单据头 LEFT JOIN 追溯码，外加拿一个"已上传"标志列 ──
    // 必须 LEFT JOIN 而非内连接：没码的单也要采——它要么是待补传的一条、要么是一份外部上传记录。
    // physic_type 不在"顺手拷来的老 SQL"里，但补传装配要它（ADR 0010），故显式补上；
    // ref_ent_id 取回来只为与源表列对齐，**本轮不使用**（那是全表单一值的总部主体，见 ADR 0010）。
    //
    // 已上传标志用 **EXISTS 子查询**：状态表无主键、无唯一约束（实测 82 个单号多行），JOIN 会把
    // 结果集放大。SQL Server 不允许在**聚合**里套子查询（票 04 的门卫 SQL 因此要换写法），
    // 但这里是非聚合的 case when——允许。
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
    $sql .= "\n            AND ls.bill_time >= ?";
    if ($date !== null) {
        $sql .= "\n            AND ls.bill_time = ?";
        $params[] = $date;
    }

    echo "[fetch_bills_retail] 正在从源库拉取单据与追溯码...\n";

    // ── PHP 侧收口：LEFT JOIN 的重复行在此合并 ──
    // 结构：bill_code => [单据头字段..., 'uploaded' => bool, 'codes' => [码 => true]]
    // 码用关联数组去重（保序）；单据头字段取首次出现的行——重复行各列本就完全相同
    $bills = [];
    $rawRows = 0;

    $ok = $source->queryEach($sql, $params, function (array $row) use (&$bills, &$rawRows): void {
        $rawRows++;
        $billCode = trim((string)($row['bill_code'] ?? ''));
        if ($billCode === '') {
            return;
        }
        if (!isset($bills[$billCode])) {
            $bills[$billCode] = [
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
        }
        $code = trim((string)($row['trace_codes'] ?? ''));
        if ($code !== '') {
            $bills[$billCode]['codes'][$code] = true; // 关联数组去重（保序）
        }
    });

    // 查询失败与"真没单据"必须分开：前者非零退出且不写库
    // （queryEach 返回 false 即 SQL 出错，错误另存在 lastError 里）
    if ($ok === false) {
        throw new \RuntimeException('单据查询失败: ' . $source->getErrorMessage());
    }

    if (empty($bills)) {
        echo "[fetch_bills_retail] 没有需要采集的单据\n";
        exit(0);
    }

    echo "[fetch_bills_retail] 拉取到 " . count($bills) . " 张单据（原始 {$rawRows} 行，已按单号去重收口）\n";

    // ── 认领 + 分流 + 落库 ──
    // 源库已全部读完（queryEach 已消费完语句并释放），之后的失败不会再产生"读一半写一半"的采集残缺
    $db = Database::getInstance();

    // 本地已有痕迹**分两张查**：两条分支的幂等判据不同（见 RetailExternalUploads::decide）——
    // 已上传的单只看"有没有成功记录"（已有任务行不拦它：那条任务行是本地待办痕迹，由状态闭环翻正），
    // 未上传的单则是"任务行或成功记录任一存在"就跳过。合成一个集合会让已上传分支误跳过、记录写不出来。
    // 判据键均为 (company, djbh)：别家企业的同名单号不算"已有"（生产库里裸单号并不唯一）。
    $taskSet = [];
    $successSet = [];
    foreach (array_chunk(array_keys($bills), IN_CHUNK_SIZE) as $chunk) {
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

    $now = date('Y-m-d H:i:s');
    $taskCount = 0;
    $recordCount = 0;
    $skipCount = 0;
    $unidentifiedCount = 0;

    foreach ($bills as $djbh => $bill) {
        $billType = BillType::normalize($bill['bill_type'], $djbh);
        $organName = trim($bill['oper_ic_name']);
        $claim = Enterprise::claim($billType, $bill['from_user_id'], $bill['to_user_id'], $organName);
        $company = $claim['company'];

        // 源库写了机构名、却对不上任何门店（ID 认到但名字不符，或名字与 ID 都不命中）→
        // 疑似错名/改名/已关店：只记 JSONL 警告，**不改判定**（照常按认领结果落库）
        if ($claim['name_unmatched']) {
            $warning = [
                'type' => 'retail_claim_name_unmatched',
                'djbh' => $djbh,
                'bill_type' => $billType,
                'company' => $company,
                'company_key' => $claim['company_key'],
                'matched_by' => $claim['matched_by'],
                'organ_name' => $organName,
            ];
            // 只进 JSONL、不进 upload_logs（后者是"上传结果"日志，写进去会在失败记录页冒出
            // 既非上传也非失败的记录，污染唯一的告警出口，见 docs/adr/0007）
            (new LogWriter())->writeJsonlOnly($warning);
            $reason = $claim['matched_by'] === 'id'
                ? "按 ID 认到 {$company}，但源库机构名「{$organName}」对不上任何门店"
                : "认领不到门店：源库机构名「{$organName}」未登记，ID 也未命中";
            echo "[fetch_bills_retail] 警告: 单号 {$djbh} {$reason}\n";
        }

        if ($claim['matched_by'] === 'none') {
            $unidentifiedCount++;
        }

        // 分流决定（判据与幂等规则见 App\RetailExternalUploads::decide）
        // 按名传参：后两个都是同型的 bool，位置传参写反了没有任何东西会拦
        $action = RetailExternalUploads::decide(
            uploaded: $bill['uploaded'],
            hasTask: isset($taskSet[$company][$djbh]),
            hasSuccess: isset($successSet[$company][$djbh])
        );

        if ($action === RetailExternalUploads::ACTION_SKIP) {
            $skipCount++;
            continue;
        }

        if ($action === RetailExternalUploads::ACTION_RECORD) {
            // 外部系统已上传：只留一条记录进已上传记录页，**不建任务**——这张单没有要人做的事。
            // 记录里 request_status 留空、task_id=0、response 写明出处（见 buildRecord 的注释）
            RetailExternalUploads::record([
                'djbh'        => $djbh,
                'rq'          => $bill['bill_time'],
                'trace_codes' => implode(',', array_keys($bill['codes'])),
                'company'     => $company,
                'credential'  => $claim['credential'],
            ]);
            $recordCount++;
            continue;
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
                $billType,
                $company,
                $claim['credential'],
                trim($bill['from_user_id']),
                trim($bill['to_user_id']),
                trim($bill['physic_type']),
                $now,
                $now,
            ]
        );
        $taskCount++;
    }

    // "本批未识别"的范围是**拉取到的整批**（含被跳过与写成记录的），不只是新增任务那一部分
    echo "[fetch_bills_retail] 采集完成: 新增任务 {$taskCount} 条, 外部上传记录 {$recordCount} 条"
        . ", 跳过 {$skipCount} 条（本批认领不到门店的共 {$unidentifiedCount} 条）\n";

} catch (\Exception $e) {
    echo "[fetch_bills_retail] 错误: " . $e->getMessage() . "\n";
    exit(1);
}
