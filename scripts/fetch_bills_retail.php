<?php
/**
 * 从零售源库（dyt 链接服务器）采集门店单据写入上传任务表
 * 用法: php scripts/fetch_bills_retail.php [日期 Y-m-d]（默认当天）
 *
 * 只采集入库、不上传——零售单据由外部系统上传，本项目只做"可见 + 人工补传"（见 docs/adr/0007）。
 * 落库 task_status='待补传'（不复用"等待上传"，那语义是"cron 会来取走并上传"）、source='retail'；
 * 自动上传链路对它们零动作（取数侧 company 白名单 + UploadService 的 fail-closed 守卫）。
 *
 * 源表（全程只读 SELECT，不调任何平台接口、不写源库）：
 *   dyt.msfx.dbo.zsm_ls       单据头（bill_time 是 varchar(10) 纯日期 'YYYY-MM-DD'，字符串比较即日期比较）
 *   dyt.msfx.dbo.zsm_ls_code  追溯码，**一码一行**（列名误导），无排序列、bs 恒为 1
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

$date = $argv[1] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo "[fetch_bills_retail] 日期格式无效: {$date}，需要 YYYY-MM-DD\n";
    exit(1);
}

echo "[fetch_bills_retail] 开始采集，日期: {$date}\n";

try {
    // 与 TaskFetcher 同一条连接配置（4 段式链接服务器名可在同一连接上直接查，见探测结论）
    $source = new \SqlSrvHelper([
        'server'   => Config::get('DB_SERVER', '192.168.2.133'),
        'port'     => Config::get('DB_PORT', '1433'),
        'database' => Config::get('DB_DATABASE', 'hyyy_zyscm'),
        'username' => Config::get('DB_USERNAME', 'sa'),
        'password' => Config::get('DB_PASSWORD', ''),
    ]);

    // ── 第一步：单据头。必须先按 bill_code 去重 ──
    // 321 存在完全重复行（同一 bill_code 最多 120 行，14 列值全同、无任何区分列），
    // 不去重会让第二步按单号取码时追溯码被放大最多 120 倍。
    // MIN() 只作去重的确定性代表值：重复行各列本就完全相同。bill_time 无索引，本查询为全表扫（~0.5s）
    echo "[fetch_bills_retail] 正在从源库拉取单据头...\n";
    $headers = $source->query(
        "SELECT bill_code,
                MIN(bill_time)    AS bill_time,
                MIN(bill_type)    AS bill_type,
                MIN(from_user_id) AS from_user_id,
                MIN(to_user_id)   AS to_user_id,
                MIN(oper_ic_name) AS oper_ic_name
         FROM dyt.msfx.dbo.zsm_ls
         WHERE bill_type IN (" . implode(', ', RETAIL_BILL_TYPES) . ")
           AND bill_time = ?
         GROUP BY bill_code",
        [$date]
    );

    // SqlSrvHelper::query 查询失败时返回空数组（错误另存在 lastError 里），
    // 故"空结果"要分两种：真没单据 vs 查询失败——后者必须非零退出且不写库
    if (empty($headers) && $source->getLastError() !== null) {
        throw new \RuntimeException('单据头查询失败: ' . $source->getErrorMessage());
    }

    if (empty($headers)) {
        echo "[fetch_bills_retail] 没有需要采集的单据\n";
        exit(0);
    }

    echo "[fetch_bills_retail] 拉取到 " . count($headers) . " 张单据（已按单号去重），正在取追溯码...\n";

    // ── 第二步：按单号批量取码 ──
    // group by 去重 + order by 保证一单的码拼接结果确定（源表没有排序列）
    $codesByDjbh = [];
    foreach (array_chunk(array_column($headers, 'bill_code'), IN_CHUNK_SIZE) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $rows = $source->query(
            "SELECT bill_code, trace_codes
             FROM dyt.msfx.dbo.zsm_ls_code
             WHERE bill_code IN ({$placeholders})
             GROUP BY bill_code, trace_codes
             ORDER BY bill_code, trace_codes",
            $chunk
        );
        if (empty($rows) && $source->getLastError() !== null) {
            throw new \RuntimeException('追溯码查询失败: ' . $source->getErrorMessage());
        }
        foreach ($rows as $row) {
            $code = trim((string)($row['trace_codes'] ?? ''));
            if ($code === '') {
                continue;
            }
            $codesByDjbh[$row['bill_code']][$code] = true; // 关联数组去重（保序）
        }
    }

    // ── 第三步：认领 + 去重 + 落库 ──
    // 到这里源库已全部读完，之后的失败都不会再产生"读一半写一半"的采集残缺
    $db = Database::getInstance();

    // 去重键 (company, djbh)：与批发同理，零售也跳过 upload_logs 里已上传成功/单据重复的单据
    // （人工补传成功的单据若被删了任务行，重采集不该再入队——那是重复申报的入口）
    $existingSet = [];
    foreach (array_chunk(array_column($headers, 'bill_code'), IN_CHUNK_SIZE) as $chunk) {
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

    foreach ($headers as $bill) {
        $djbh = trim((string)$bill['bill_code']);
        if ($djbh === '') {
            continue;
        }

        $billType = BillType::normalize((string)$bill['bill_type'], $djbh);
        $organName = trim((string)($bill['oper_ic_name'] ?? ''));
        $claim = Enterprise::claim($billType, $bill['from_user_id'] ?? null, $bill['to_user_id'] ?? null, $organName);
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
        // 不查 ent_list（那是批发 kyt 接口把往来单位名换成 ent_id 才需要的缓存）
        $db->execute(
            "INSERT INTO upload_tasks (rq, djbh, ent_name, trace_codes, bill_type, task_status, source, company, credential, created_at, updated_at)
             VALUES (?, ?, '', ?, ?, '待补传', 'retail', ?, ?, ?, ?)",
            [
                (string)$bill['bill_time'],
                $djbh,
                implode(',', array_keys($codesByDjbh[$djbh] ?? [])),
                $billType,
                $company,
                $claim['credential'],
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
