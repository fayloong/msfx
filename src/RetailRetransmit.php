<?php
/**
 * 零售门店单据的**上传**——补传（上传任务页）与手工建单（手动上传页）共用的唯一实现
 *
 * 传到平台 = **真实申报，不可逆**。装配错一项就是把单据报到错误主体，所以三关
 * fail-closed 全在第一次平台调用之前：不过则不发一次调用、不写一条日志、任务行一个字段都不动
 * （见 docs/adr/0006-credential-as-routing-subject.md、docs/adr/0011-retail-retransmit-metadata-and-manual-trigger.md）。
 *
 * 为什么这段流程在类里而不在端点里（工单 07）：两个入口共用同一份装配与落库，
 * 而端点文件不能被另一个端点 `include`（会执行它的认证、参数解析与 exit）。故各端点只留
 * 「解析请求 + 流式输出」，流程在这里；`App\RetailManualEntry` 是手工建单那侧的前置（校验 +
 * 对手方解析 + 落库），落库后同样调本类上传。装配仍走 `RetailRequestAssembler`
 * （工单 05 的纯函数接缝），本类不重抄任何映射规则。
 *
 * 调用方：
 *   - src/api/tasks_retry_retail.php 单条补传（上传任务页零售行的"补传"）
 *   - App\RetailManualEntry::create() 门店手工建单（手动上传页的在线新增 / xlsx 导入）——
 *     同样是"上传一条门店单据"，差别只在日志的 source（补传记 retail_retry、手工建单记 manual）
 *
 * 不做 flock（批发链路有 `logs/upload.lock`，那是给 cron 与批量共用的入口互斥用的）：本链路由人点击触发，
 * 同单并发最多撞上"同一个人连点两下"——第二发会得到平台的"该单据号已存在"（→ 单据重复），
 * 平台自身的单号唯一性就是兜底。
 *
 * 限流侧同理：平台限流池按 AppKey 计，零售用的是各门店自己的 AppKey，与河药的 `check_bill_status`
 * 8-20 点窗口不共享池子。**但配置并不禁止不同企业的凭据共用同一个 AppKey**（同一开发者账号下的多个企业
 * 本就可以合法共用，故 `Enterprise::validate()` 不拦），所以真有门店与河药共用 AppKey 时，"补传不受
 * 8-20 点窗口约束"这条就不再成立——那时才需要拿锁与错峰，别默认它永远成立。
 */
namespace App;

class RetailRetransmit
{
    /**
     * upload_logs.source 的**默认**值：与任务行的 source='retail'（采集）区分开，标识"这次是人工补传"。
     * 手工建单走同一条链路但传 `manual`（`RetailManualEntry::LOG_SOURCE`）——补传与新建是两件事，
     * 来源列上要分得清。
     */
    public const SOURCE = 'retail_retry';

    /** 重试与限速：沿用批发链路的既有约定（见 UploadService 顶部常量） */
    private const MAX_RETRIES = 3;
    private const RETRY_INTERVAL_SEC = 30;
    private const API_INTERVAL_US = 330000;

    /**
     * 上传一条零售单据（必要时拆单，逐个子单调用平台）。
     *
     * @param array    $task 落库的任务行（元数据一律取自这里，不接受调用方传单据字段）
     * @param Database $db   任务状态写回用
     * @param callable|null $onProgress 每个子单的结果回调（收到一条真实结果即调一次）
     * @param string   $logSource 写进 upload_logs.source 的来源值：补传默认 retail_retry，
     *                            手工建单传 manual——同一段链路、不同的入口，来源列要分得清
     * @return array{total:int, success:int, failed:int} total/success/failed 均按**子单**计
     * @throws \RuntimeException 校验不过（非零售企业 / 门店无凭据位或未配齐 / 无路由 / 装配必填项缺失）
     */
    public function retransmit(array $task, Database $db, ?callable $onProgress = null, string $logSource = self::SOURCE): array
    {
        $djbh = (string)$task['djbh'];
        $company = trim((string)($task['company'] ?? ''));

        // ── 三关 fail-closed，全在第一次平台调用之前 ──

        // 1) 只做零售。批发单据走 kyt 路径（tasks_retry / tasks_batch_retry），
        //    拿零售模板装配批发单、或拿批发主体装配门店单，都会把单据报到错误主体
        if (!Enterprise::isRetail($company)) {
            throw new \RuntimeException("单号 {$djbh}: 「{$company}」不是零售企业，本入口只上传门店单据");
        }

        // 2) 凭据 = 该门店**那套**（每家企业恰一套，见 docs/adr/0012），且四字段填齐。
        //    凭据不由调用方指定：页面上已没有可选的东西，端点也就不该有"传哪套凭据"这个入参——
        //    少一个可传错的参数，就少一条"把单据报到错误主体"的路径。待配凭据的门店页面已禁用，
        //    这里再拦一次（页面只是显示层，不是可信边界）
        $credential = Enterprise::credentialFor($company);
        if ($credential === null) {
            throw new \RuntimeException("单号 {$djbh}: 门店「{$company}」没有凭据位，拒绝补传");
        }
        if (!Enterprise::credentialConfigured($credential)) {
            throw new \RuntimeException(
                "单号 {$djbh}: 门店「{$company}」的凭据尚未配齐（AppKey/SECRETKEY 未到手），拒绝补传"
            );
        }
        $credentialKey = (string)$credential['key'];

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
            $attempt = $this->uploadSingle($client, $assembled['request'], $bill, $company, $credentialKey, $taskId, $logWriter, $logSource);

            $attempt['success'] ? $success++ : $failed++;

            // 按平台返回翻转任务状态（与批发链路一致：失败也记"已处理"——任务表是待处理队列，
            // "补传没成功"的出口是失败记录页）
            $this->updateTaskStatus($db, $taskId, $credentialKey, $attempt);

            if ($onProgress) {
                $onProgress(self::progressLine($billCode, $company, $attempt));
            }

            usleep(self::API_INTERVAL_US);
        }

        return ['total' => count($chunks), 'success' => $success, 'failed' => $failed];
    }

    /**
     * 一条进度行的形状（前端逐行渲染的输入）。**真实结果与"没能进平台"共用这一个形状**——
     * 前端只要认一套字段，不必为拒绝路径单写一个渲染分支。
     */
    private static function progressLine(string $djbh, string $company, array $attempt): array
    {
        return [
            'djbh' => $djbh,
            'ent_name' => '', // 零售的对手方是平台 ID（采集的来自源表、手工建的由名称查出），前端回落到 company 显示门店名
            'company' => $company,
            'success' => $attempt['success'],
            'request_status' => $attempt['request_status'],
            'response_status' => $attempt['response_status'],
            'response' => $attempt['response'],
        ];
    }

    /**
     * 上传单个子单（含重试）。每尝试一次写一条日志，与批发链路同构。
     *
     * @param object $req RetailRequestAssembler 装配好的请求对象
     * @return array{success:bool, request_status:string, response_status:?string, response:string}
     */
    private function uploadSingle(
        ApiClient $client,
        $req,
        array $bill,
        string $company,
        string $credentialKey,
        int $taskId,
        LogWriter $logWriter,
        string $logSource
    ): array {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
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
                'source' => $logSource,
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
            if ($attempt < self::MAX_RETRIES) {
                sleep(self::RETRY_INTERVAL_SEC);
            }
        }

        // 三次都是网络错误：每次尝试都已写过日志，这里返回一个汇总，不再重复记
        return [
            'success' => false,
            'request_status' => '请求失败',
            'response_status' => null,
            'response' => json_encode(['error' => '网络错误重试 ' . self::MAX_RETRIES . ' 次仍失败'], JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * 翻转任务状态并记下这次实际用了哪套凭据。
     *
     * credential 列是审计值：采集时预填该门店那套凭据的键，这里写回的是同一套——每家企业恰一套
     * （见 docs/adr/0012），故这一写不改变取值，只是把"这次用了哪套"记成事实。
     * 去重键是 (company, djbh)，credential 不参与任何键（见 docs/adr/0006）。
     */
    private function updateTaskStatus(Database $db, int $taskId, string $credentialKey, array $attempt): void
    {
        $db->execute(
            "UPDATE upload_tasks
             SET task_status = '已处理', request_status = ?, response_status = ?, resp = ?, credential = ?,
                 updated_at = datetime('now','localtime')
             WHERE id = ?",
            [$attempt['request_status'], $attempt['response_status'], $attempt['response'], $credentialKey, $taskId]
        );
    }
}
