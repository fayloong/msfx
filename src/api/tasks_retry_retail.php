<?php
/**
 * API: POST /api/tasks_retry_retail — 零售门店单据的补传（人工逐条触发）
 *
 * 与批发重传（tasks_retry / tasks_batch_retry）刻意分成两个入口：那条走 kyt 接口 + ent_list
 * 往来单位缓存，这条走 lsyd 接口 + 源表平台 ID，装配与凭据来源完全不同（见 docs/adr/0010）。
 * 合成一条"按企业类型分发"的路由，只会让两边都难读；分开后各自的 fail-closed 关口也一目了然。
 *
 * **补传是向平台的真实申报**，装配错一项就是把单据报到错误主体、在平台上不可逆，
 * 故所有校验都发生在第一次平台调用之前（见 docs/adr/0006 / docs/adr/0007）。
 *
 * 入参：{id: 任务 ID, credential: 凭据位键}
 * - 单据元数据一律取自**采集时落库的记录**，不接受调用方传任何单据字段（票面：不提供从零手工录入。
 *   手工录 4 个平台 ID 几乎必然出错，且本轮不查平台，录错了察觉不了）
 * - 凭据由操作者在页面上**显式选择**（本轮不做多套凭据的自动分发规则，由人指定比猜一套规则可靠）
 *
 * **不做 flock**（批发链路有 `logs/upload.lock`，那是给 cron 与批量共用的入口互斥用的）：本入口
 * 由人点击触发、每次一张单，同单并发最多撞上"同一个人连点两下"——第二发会得到平台的"该单据号已存在"
 * （→ 单据重复），平台自身的单号唯一性就是兜底，加锁只是多一个状态文件要维护。
 *
 * 限流侧同理：平台的限流池按 AppKey 计，本入口用的是各门店自己的 AppKey，与河药的
 * `check_bill_status` 8-20 点窗口不共享池子。**但配置并不禁止两套凭据共用同一个 AppKey**
 * （同一开发者账号下的多个企业本就可以合法共用，故 `Enterprise::validate()` 不拦），所以
 * 真有门店与河药共用 AppKey 时，"补传不受 8-20 点窗口约束"这条就不再成立——那时才需要拿锁
 * 与错峰，别默认它永远成立。
 */

use App\ApiClient;
use App\Auth;
use App\Database;
use App\Enterprise;
use App\LogWriter;
use App\RetailRequestAssembler;
use App\TraceSplitter;

/** 上传日志的来源值：与 task 行的 source='retail'（采集）区分开，标识"这次是人工补传" */
const RETAIL_RETRY_SOURCE = 'retail_retry';

/** 重试与限速：沿用批发链路的既有约定（见 UploadService 顶部常量） */
const RETAIL_MAX_RETRIES = 3;
const RETAIL_RETRY_INTERVAL_SEC = 30;
const RETAIL_API_INTERVAL_US = 330000;

Auth::init();
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$id = $input['id'] ?? null;
$credentialKey = trim((string)($input['credential'] ?? ''));

if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => '缺少 id 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($credentialKey === '') {
    http_response_code(400);
    echo json_encode(['error' => '未选择凭据'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = Database::getInstance();
$task = $db->queryOne("SELECT * FROM upload_tasks WHERE id = ?", [$id]);

if (!$task) {
    http_response_code(404);
    echo json_encode(['error' => '任务不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

// NDJSON 流式输出（与批发重传同格式，前端复用同一个进度弹窗）
header('Content-Type: application/x-ndjson; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache');
ini_set('output_buffering', 'off');
while (ob_get_level()) { ob_end_clean(); }
ob_implicit_flush(true);

try {
    $result = retailRetransmit($task, $credentialKey, $db, function (array $progress) {
        echo json_encode($progress, JSON_UNESCAPED_UNICODE) . "\n";
        flush();
    });

    echo json_encode(['_final' => true, 'success' => true, 'result' => $result], JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Throwable $e) {
    // **不复位任务状态**（批发链路那两个入口会复位，本入口刻意不照搬）：这条链路上任务行只在
    // 平台调用**之后**被写（updateRetailTaskStatus 是唯一的写入点），所以异常要么发生在第一次
    // 调用之前（任务行根本没被动过，复位是空操作），要么发生在某个子单已完成之后（此时那一写
    // 就是本次尝试的真实结果，复位反而把已记录的结果抹成 NULL）。凭据列同理不回滚——它记的是
    // "这次实际用了哪套"，而抛异常意味着根本没机会用上凭据。
    echo json_encode(['_final' => true, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n";
}

/**
 * 补传一条零售单据（必要时拆单，逐个子单调用平台）。
 *
 * @return array{total:int, success:int, failed:int}
 * @throws \RuntimeException 校验不过（非零售企业 / 凭据不属于该门店或未配齐 / 无路由 / 装配必填项缺失）
 */
function retailRetransmit(array $task, string $credentialKey, Database $db, callable $onProgress): array
{
    $djbh = (string)$task['djbh'];
    $company = trim((string)($task['company'] ?? ''));

    // ── 三关 fail-closed，全在第一次平台调用之前 ──

    // 1) 只做零售。批发单据走 kyt 路径（tasks_retry / tasks_batch_retry），
    //    拿零售模板装配批发单、或拿批发主体装配门店单，都会把单据报到错误主体
    if (!Enterprise::isRetail($company)) {
        throw new \RuntimeException("单号 {$djbh}: 「{$company}」不是零售企业，本入口只补传门店单据");
    }

    // 2) 凭据必须是这家门店的、且四字段填齐（待配凭据的门店页面已禁用，这里再拦一次——
    //    页面只是显示层，不是可信边界）
    $credential = Enterprise::credential($company, $credentialKey);
    if ($credential === null) {
        throw new \RuntimeException("单号 {$djbh}: 门店「{$company}」没有凭据位「{$credentialKey}」，拒绝补传");
    }
    if (!Enterprise::credentialConfigured($credential)) {
        throw new \RuntimeException(
            "单号 {$djbh}: 门店「{$company}」的凭据「{$credentialKey}」尚未配齐"
            . "（AppKey/SECRETKEY 未到手），拒绝补传"
        );
    }

    // 3) 元数据全部取自落库行。工单 06 之前采的行缺 from_user_id/to_user_id/physic_type，
    //    装配末尾的 check() 会把它们拦下（fail-closed），不会拼出一个"看着合法、却报错主体"的请求
    $billType = (string)($task['bill_type'] ?? '');
    $route = Enterprise::route($company, $billType);
    if ($route === null) {
        throw new \RuntimeException("单号 {$djbh}: 门店「{$company}」的单据类型「{$billType}」没有接口路由，拒绝补传");
    }

    $bill = [
        'rq' => (string)($task['rq'] ?? ''),
        'bill_type' => $billType,
        'trace_codes' => (string)($task['trace_codes'] ?? ''),
        'from_user_id' => (string)($task['from_user_id'] ?? ''),
        'to_user_id' => (string)($task['to_user_id'] ?? ''),
        'physic_type' => (string)($task['physic_type'] ?? ''),
    ];

    // 码上限取自路由（104/203 → 10000、321/116 → 3500），拆单命名沿用批发约定 单号_1、单号_2…
    $chunks = TraceSplitter::splitByCount($djbh, $bill['trace_codes'], (int)$route['limit']);

    $client = new ApiClient((string)$credential['appkey'], (string)$credential['secretkey']);
    $logWriter = new LogWriter();
    $taskId = (int)$task['id'];

    $success = 0;
    $failed = 0;

    foreach ($chunks as $billCode => $codes) {
        $bill['djbh'] = $billCode;
        $bill['trace_codes'] = $codes;

        $assembled = RetailRequestAssembler::assemble($bill, $company, $credential);
        $attempt = uploadRetailSingle($client, $assembled['request'], $bill, $company, $credentialKey, $taskId, $logWriter);

        $attempt['success'] ? $success++ : $failed++;

        // 按平台返回翻转任务状态（与批发链路一致：失败也记"已处理"——任务表是待处理队列，
        // "补传没成功"的出口是失败记录页）
        updateRetailTaskStatus($db, $taskId, $credentialKey, $attempt);

        if ($onProgress) {
            $onProgress([
                'djbh' => $billCode,
                'ent_name' => '',
                'company' => $company,
                'success' => $attempt['success'],
                'request_status' => $attempt['request_status'],
                'response_status' => $attempt['response_status'],
                'response' => $attempt['response'],
            ]);
        }

        usleep(RETAIL_API_INTERVAL_US);
    }

    return ['total' => count($chunks), 'success' => $success, 'failed' => $failed];
}

/**
 * 上传单个子单（含重试）。每尝试一次写一条日志，与批发链路同构。
 *
 * @param object $req RetailRequestAssembler 装配好的请求对象
 * @return array{success:bool, request_status:string, response_status:?string, response:string}
 */
function uploadRetailSingle(
    ApiClient $client,
    $req,
    array $bill,
    string $company,
    string $credentialKey,
    int $taskId,
    LogWriter $logWriter
): array {
    for ($attempt = 1; $attempt <= RETAIL_MAX_RETRIES; $attempt++) {
        $result = $client->execute($req);
        $response = json_encode($result, JSON_UNESCAPED_UNICODE);
        $requestStatus = $result['is_network_error'] ? '请求失败' : '请求成功';
        $responseStatus = ApiClient::resolveUploadResponseStatus($result);

        $logWriter->write([
            'djbh' => $bill['djbh'],
            'request_status' => $requestStatus,
            'response_status' => $responseStatus,
            'response' => $response,
            'task_id' => $taskId,
            'trace_codes' => $bill['trace_codes'],
            'rq' => $bill['rq'],
            'source' => RETAIL_RETRY_SOURCE,
            'company' => $company,
            'credential' => $credentialKey,
        ]);

        // 成功、或业务错误（业务错误不重试）→ 结束
        if ($result['success'] || !$result['is_network_error']) {
            // "成功"按**业务结果**算，不照搬 ApiClient::execute 的 success（那是网关级：无 code
            // 错误即 true）。实测平台对"存在已出售的码"这类业务拒绝也返回 success=true + msg_code=FAIL，
            // 照搬会把真实失败显示成绿色 [成功]、汇总写成"成功 1"——操作者据此判断补传结果，不能骗人。
            // 口径与已上传页/失败页一致：上传成功与单据重复都算成功（单据已在平台上），其余算失败。
            return [
                'success' => in_array($responseStatus, ['上传成功', '单据重复'], true),
                'request_status' => $requestStatus,
                'response_status' => $responseStatus,
                'response' => $response,
            ];
        }

        // 网络错误，等待后重试（最后一次不再等）
        if ($attempt < RETAIL_MAX_RETRIES) {
            sleep(RETAIL_RETRY_INTERVAL_SEC);
        }
    }

    // 三次都是网络错误：每次尝试都已写过日志，这里返回一个汇总，不再重复记
    return [
        'success' => false,
        'request_status' => '请求失败',
        'response_status' => null,
        'response' => json_encode(['error' => '网络错误重试 ' . RETAIL_MAX_RETRIES . ' 次仍失败'], JSON_UNESCAPED_UNICODE),
    ];
}

/**
 * 翻转任务状态并记下这次实际用了哪套凭据。
 *
 * credential 列是审计值：采集时预填该门店的 primary，人工切到备用凭据时在这里被覆盖
 * （去重键是 (company, djbh)，credential 不参与任何键，见 docs/adr/0006）。
 */
function updateRetailTaskStatus(Database $db, int $taskId, string $credentialKey, array $attempt): void
{
    $db->execute(
        "UPDATE upload_tasks
         SET task_status = '已处理', request_status = ?, response_status = ?, resp = ?, credential = ?,
             updated_at = datetime('now','localtime')
         WHERE id = ?",
        [$attempt['request_status'], $attempt['response_status'], $attempt['response'], $credentialKey, $taskId]
    );
}

