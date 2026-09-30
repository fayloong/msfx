<?php
/**
 * 从零售源库（dyt 链接服务器）采集门店单据写入上传任务表
 * 用法: php scripts/fetch_bills_retail.php [日期 Y-m-d]（⚠️ 当前口径**无日期过滤**，日期参数只打印不生效）
 *
 * 只采集入库、不上传——零售单据由外部系统上传，本项目只做"可见 + 人工补传"（见 docs/adr/0007）。
 * 落库 task_status='待补传'（不复用"等待上传"，那语义是"cron 会来取走并上传"）、source='retail'；
 * 自动上传链路对它们零动作（取数侧 company 白名单 + UploadService 的 fail-closed 守卫）。
 *
 * 落库的元数据必须够人工补传装配用（工单 06）：除追溯码外还要 from_user_id / to_user_id /
 * physic_type——补传时不会回头问源库，这三列缺一列这条单就永远补不出去（见 ADR 0010）。
 *
 * 源表（全程只读 SELECT，不调任何平台接口、不写源库）：
 *   dyt.msfx.dbo.zsm_ls           单据头（bill_time 是 varchar(10) 纯日期 'YYYY-MM-DD'）
 *   dyt.msfx.dbo.zsm_ls_code      追溯码，**一码一行**（列名误导），无排序列、bs 恒为 1
 *   dyt.bs_msfx.dbo.update_state  外部系统的上传状态（单号 + 状态两列）——**只读**，用作采集过滤
 *
 * ⚠️ 测试阶段临时口径（2026-09-30 用户指定，与 ADR 0007 的原始决定相反，测试结束需回收）：
 *   1. **单条 SQL**：zsm_ls LEFT JOIN zsm_ls_code，不再分"先头后码"两步。
 *   2. **带 NOT EXISTS(update_state)**：只采外部系统尚未上传的单，待补传清单里不再有已上传的单。
 *      代价见 ADR 0007：已上传的单在页面上不可见；update_state 无企业列，跨门店单号重复时它自身会串。
 *   3. **无 bill_time 日期过滤**：每轮全表扫（bill_time 无索引），日期参数不生效。
 *   4. LEFT JOIN 会放大行数：321 存在 14 列值全同的重复行，同一 bill_code 最多 120 行。
 *      故去重挪到 PHP 侧——追溯码用关联数组去重（保序，同 zsm_ls_code 的一码一行），
 *      单据头字段取首次出现的行（重复行各列本就相同）。行数可能到数十万，走 queryEach 逐行消费，
 *      不经 query() 攒数组（那会撞上 CLI 的 memory_limit=128M）。
 *
 * 采集口径见 .scratch/retail-chain/spec.md §5；认领走 App\Enterprise::claim()（见 ADR 0008）：
 * 按单据类型取 ID 列（321/116 → from_user_id，104/203 → to_user_id）命中门店登记过的任一平台 ID，
 * ID 缺失才回退 oper_ic_name 与门店名精确相等；都不命中 → company='未识别' 照常入库（丢单比错标更危险）。
 *
 * 建议 cron: 与 fetch_bills.php 同频（零售采集不调平台 API，不受 8-20 点限流窗口约束）
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

Config::load();

/** 采集的四种单据类型（写死）。`999` 语义未明，用户判定不采（见探测结论） */
const RETAIL_BILL_TYPES = [104, 203, 321, 116];

/** 单号 IN 列表分块大小（规避超长 SQL 与参数上限） */
const IN_CHUNK_SIZE = 500;

$dateArg = $argv[1] ?? null;

if ($dateArg !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateArg)) {
    echo "[fetch_bills_retail] 日期格式无效: {$dateArg}，需要 YYYY-MM-DD\n";
    exit(1);
}

echo "[fetch_bills_retail] 开始采集（测试阶段口径：全表扫描、无日期过滤、剔除外部系统已上传的单）\n";
if ($dateArg !== null) {
    echo "[fetch_bills_retail] 注意: 日期参数 {$dateArg} 本轮不生效——SQL 不带 bill_time 过滤\n";
}

try {
    // 与 TaskFetcher 同一条连接配置（4 段式链接服务器名可在同一连接上直接查，见探测结论）
    $source = new \SqlSrvHelper([
        'server'   => Config::get('DB_SERVER', '192.168.2.133'),
        'port'     => Config::get('DB_PORT', '1433'),
        'database' => Config::get('DB_DATABASE', 'hyyy_zyscm'),
        'username' => Config::get('DB_USERNAME', 'sa'),
        'password' => Config::get('DB_PASSWORD', ''),
    ]);

    // ── 单条 SQL：单据头 LEFT JOIN 追溯码（测试阶段口径，见文件头） ──
    // 必须 LEFT JOIN 而非内连接：没码的单也要采——它是待补传队列里值得看见的一条。
    // physic_type 不在"顺手拷来的老 SQL"里，但补传装配要它（ADR 0010），故显式补上；
    // ref_ent_id 取回来只为与源表列对齐，**本轮不使用**（那是全表单一值的总部主体，见 ADR 0010）。
    $sql = "select ls.bill_code,ls.bill_time,ls.bill_type,ls.physic_type,
                   ls.from_user_id,ls.to_user_id,ls.ref_ent_id,ls.oper_ic_name,co.trace_codes
            from dyt.msfx.dbo.zsm_ls ls
            left join dyt.msfx.dbo.zsm_ls_code co on co.bill_code=ls.bill_code
            where bill_type in (" . implode(', ', RETAIL_BILL_TYPES) . ")
            AND not exists(select * from dyt.bs_msfx.dbo.update_state a where a.bill_code=ls.bill_code)";

    echo "[fetch_bills_retail] 正在从源库拉取单据与追溯码...\n";

    // ── PHP 侧收口：LEFT JOIN 的重复行在此合并 ──
    // 结构：bill_code => [单据头字段..., 'codes' => [码 => true]]
    // 码用关联数组去重（保序）；单据头字段取首次出现的行——重复行各列本就完全相同
    $bills = [];
    $rawRows = 0;

    $ok = $source->queryEach($sql, [], function (array $row) use (&$bills, &$rawRows): void {
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

    // ── 认领 + 去重 + 落库 ──
    // 源库已全部读完（queryEach 已消费完语句并释放），之后的失败不会再产生"读一半写一半"的采集残缺
    $db = Database::getInstance();

    // 去重键 (company, djbh)：与批发同理，零售也跳过 upload_logs 里已上传成功/单据重复的单据
    // （人工补传成功的单据若被删了任务行，重采集不该再入队——那是重复申报的入口）
    $existingSet = [];
    foreach (array_chunk(array_keys($bills), IN_CHUNK_SIZE) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        foreach ($db->query(
            "SELECT company, djbh FROM upload_tasks WHERE djbh IN ({$placeholders})",
            $chunk
        ) as $row) {
            $existingSet[$row['company']][$row['djbh']] = true;
        }
        foreach ($db->query(
            "SELECT company, djbh FROM upload_logs WHERE djbh IN ({$placeholders}) AND response_status IN ('上传成功', '单据重复')",
            $chunk
        ) as $row) {
            $existingSet[$row['company']][$row['djbh']] = true;
        }
    }

    $now = date('Y-m-d H:i:s');
    $insertCount = 0;
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
            writeRetailWarning($warning);
            $reason = $claim['matched_by'] === 'id'
                ? "按 ID 认到 {$company}，但源库机构名「{$organName}」对不上任何门店"
                : "认领不到门店：源库机构名「{$organName}」未登记，ID 也未命中";
            echo "[fetch_bills_retail] 警告: 单号 {$djbh} {$reason}\n";
        }

        if ($claim['matched_by'] === 'none') {
            $unidentifiedCount++;
        }

        if (isset($existingSet[$company][$djbh])) {
            $skipCount++;
            continue;
        }

        // ent_name（往来单位）零售链路用不到，留空——对手方 ID 直接来自源表的 from_user_id/to_user_id，
        // 不查 ent_list（那是批发 kyt 接口把往来单位名换成 ent_id 才需要的缓存）。
        // from_user_id / to_user_id / physic_type 照搬源表同名列：补传装配要用（见 ADR 0010），
        // 除认领外不参与任何判定——三列都是单据头属性，与追溯码一样是"补传时不能现问源库"的输入。
        $db->execute(
            "INSERT INTO upload_tasks (rq, djbh, ent_name, trace_codes, bill_type, task_status, source, company, credential,
                                       from_user_id, to_user_id, physic_type, created_at, updated_at)
             VALUES (?, ?, '', ?, ?, '待补传', 'retail', ?, ?, ?, ?, ?, ?, ?)",
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
        $insertCount++;
    }

    echo "[fetch_bills_retail] 采集完成: 新增 {$insertCount} 条, 跳过 {$skipCount} 条, 其中未识别 {$unidentifiedCount} 条\n";

} catch (\Exception $e) {
    echo "[fetch_bills_retail] 错误: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * 写一条 JSONL 警告。
 *
 * 刻意**只进 JSONL、不进 upload_logs**：upload_logs 是"上传结果"日志，写进去会在失败记录页
 * 冒出一条既非上传也非失败的记录，污染那个唯一的告警出口（见 docs/adr/0007）。
 */
function writeRetailWarning(array $record): void
{
    $line = ['timestamp' => date('Y-m-d H:i:s')] + $record;
    $file = __DIR__ . '/../logs/api_' . date('Y-m-d') . '.jsonl';
    file_put_contents($file, json_encode($line, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
