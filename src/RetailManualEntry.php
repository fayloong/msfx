<?php
/**
 * 门店手工新增单据 —— 在线新增与 xlsx 导入**共用的唯一实现**
 *
 * 与零售补传（`App\RetailRetransmit`）的分工：补传的元数据取自采集时落库的行（ADR 0011），
 * 而手工建单没有源表行可取，`from_user_id` / `to_user_id` / `physic_type` 必须现场确定——
 * 就是本类存在的理由，也是 ADR 0011 那条"手工录平台 ID 几乎必然出错"的例外所在：
 *
 *   - **不由人录平台 ID**：人录的是**往来单位名称**，本类用该门店自己的凭据去平台查出 `ent_id`
 *     （`App\EntDirectory`，与批发同一套），再按单据类型落位到 from/to。
 *     "把单据报到错误主体"这件事因此在人手上没有入口——与批发分支的做法一致。
 *   - `physic_type` 取常量（源表 `zsm_ls.physic_type` 实测全表恒为 3）。
 *
 * ⚠️ **与采集单的一处已知不一致**（ADR 0010 的待确认项所致）：采集单照搬源表同名列、不按发货/
 * 收货语义翻转（源表 104/203 的 `from_user_id` 都只有总部一个值）；手工单没有源表可照搬，只能按
 * SDK docblock 的语义合成，**203 的取值因此可能与采集单相反**。确认后两边要一起改，见
 * docs/adr/0015-retail-manual-entry.md。
 *
 * 用法（两个端点）：
 *   $bill = RetailManualEntry::prepare($input);          // 校验 + 取凭据 + 解析对手方（唯一一次平台往返）
 *   $result = RetailManualEntry::create($bill, $db, $onProgress);   // 落库 + 上传 + 翻状态
 *
 * 拆成两步是为了让端点把 `prepare()` 的失败当成**请求级错误**报出去（400 / 逐行 `_error`），
 * 而不是混进上传进度里——拒绝发生在落库之前，库里不留半条。
 */
namespace App;

class RetailManualEntry
{
    /**
     * 任务行的 `source`：与采集来的门店单**同列**（用户 2026-10-01 定）。
     *
     * 同一列而不是新值 'retail_manual'：上传任务页的"补传"按钮判定、门店徽标、以及
     * `cleanup_logs` 的 2 年超期清理都认这一个值，加第二个值就要在几处白名单里各补一笔，
     * 漏一处就是静默错误（超期单滞留最典型）。代价是来源列显示"零售采集"、分不出手工录入——
     * 要分辨看日志的 `source`（手工建单记 `manual`，补传记 `retail_retry`）。
     */
    public const TASK_SOURCE = 'retail';

    /** 上传日志的 source：手工触发的一次真实上传（与补传的 retail_retry 区分） */
    public const LOG_SOURCE = 'manual';

    /** 门店单据的四种类型（采集口径写死这四种，`Enterprise::route()` 也只认这四种） */
    public const BILL_TYPES = ['104', '203', '321', '116'];

    /**
     * 需要"往来单位名称"的类型 = 走 `lsyd.uploadinoutbill` 的调拨两类：
     * 该接口的 `fromUserId` / `toUserId` 是平台必填（ADR 0010）。
     * 另两类走 `lsyd.uploadretail`，接口里根本没有对手方入参——对手是消费者，
     * 不是平台注册的往来单位，页面对它们隐藏该输入框。
     */
    private const TYPES_WITH_COUNTERPARTY = ['104', '203'];

    /** 药品类型：源表 `zsm_ls.physic_type` 实测全表恒为 3（普药），批发链路同样硬编码 "3" */
    public const PHYSIC_TYPE = '3';

    /**
     * 这一种单据要不要填往来单位名称（页面显隐与后端校验读的是同一个判据）。
     */
    public static function needsCounterparty(string $billType): bool
    {
        return in_array(BillType::normalize($billType), self::TYPES_WITH_COUNTERPARTY, true);
    }

    /**
     * 对手方的 `ent_id` 与本店的 `ent_id` 在请求里各就各位。
     *
     * SDK docblock：`fromUserId` = **发货企业** entId、`toUserId` = **收货企业** entId。
     * 故 104 调拨入库（本店收货）→ from=对方、to=本店；203 调拨出库（本店发货）→ from=本店、to=对方。
     *
     * 只有调拨两类有对手方——其余类型调用即抛（而不是返回一对没意义的 ID）。
     *
     * @return array{from_user_id: string, to_user_id: string}
     * @throws \InvalidArgumentException 该类型没有对手方
     */
    public static function endpoints(string $billType, string $ownEntId, string $partnerEntId): array
    {
        $normalized = BillType::normalize($billType);
        if (!in_array($normalized, self::TYPES_WITH_COUNTERPARTY, true)) {
            throw new \InvalidArgumentException(
                "单据类型「{$normalized}」不走调拨接口，没有 from/to 对手方可言（只有 104/203 有）"
            );
        }

        return $normalized === '203'
            ? ['from_user_id' => $ownEntId, 'to_user_id' => $partnerEntId]
            : ['from_user_id' => $partnerEntId, 'to_user_id' => $ownEntId];
    }

    /**
     * 校验 + 取凭据 + 解析对手方：**平台往返只在这一步**，任一不过即拒（抛异常），
     * 此时库里不留任何东西、平台也没收到任何申报。
     *
     * @param array $bill ['company', 'rq', 'djbh', 'bill_type', 'trace_codes', 'ent_name'?]
     * @return array 补全后的单据：上面六项 + from_user_id/to_user_id/physic_type/credential_key/credential
     * @throws \RuntimeException 校验不过、门店无可用凭据、或对手方在平台上查不到
     */
    public static function prepare(array $bill): array
    {
        $company = trim((string)($bill['company'] ?? ''));
        $djbh = trim((string)($bill['djbh'] ?? ''));
        $rq = trim((string)($bill['rq'] ?? ''));
        $entName = trim((string)($bill['ent_name'] ?? ''));
        $traceCodes = trim((string)($bill['trace_codes'] ?? ''));
        $billType = BillType::normalize((string)($bill['bill_type'] ?? ''), $djbh);

        // ── 单据本身的校验（错误消息带单号，逐行导入时能指认是哪一行） ──
        if (!Enterprise::isRetail($company)) {
            throw new \RuntimeException("单号 {$djbh}: 「{$company}」不是门店（零售企业），本入口只建门店单据");
        }
        if ($djbh === '') {
            throw new \RuntimeException('单号不能为空');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rq)) {
            throw new \RuntimeException("单号 {$djbh}: 单据日期应为 YYYY-MM-DD（当前：{$rq}）");
        }
        // 平台不接受 2 年前的单据（App\RetailRetention 是这条规则的唯一来源，采集与清理共用它）
        if ($rq < RetailRetention::cutoffDate()) {
            throw new \RuntimeException(
                "单号 {$djbh}: 单据日期 {$rq} 早于保留期截止日 " . RetailRetention::cutoffDate()
                . '——平台不接受 2 年前的单据，即使是手工建单也补传不出去'
            );
        }
        if (!in_array($billType, self::BILL_TYPES, true)) {
            throw new \RuntimeException(
                "单号 {$djbh}: 单据类型「{$billType}」不是门店单据类型（只认 " . implode('/', self::BILL_TYPES) . '）'
            );
        }
        if ($traceCodes === '') {
            throw new \RuntimeException("单号 {$djbh}: 追溯码不能为空");
        }
        if (self::needsCounterparty($billType) && $entName === '') {
            throw new \RuntimeException("单号 {$djbh}: 单据类型 {$billType} 需要往来单位名称（调拨单的 from/to 由它查出）");
        }

        // ── 凭据：该门店那套，不由调用方给（门店与凭据 1:1，ADR 0012） ──
        $credential = Enterprise::credentialFor($company);
        if ($credential === null) {
            throw new \RuntimeException(
                "单号 {$djbh}: 门店「{$company}」在配置里没有声明凭据位（config/enterprises.php 缺这一项），拒绝建单"
            );
        }
        if (!Enterprise::credentialConfigured($credential)) {
            throw new \RuntimeException(
                "单号 {$djbh}: 门店「{$company}」的凭据尚未配齐（AppKey/SECRETKEY 未到手），拒绝建单"
            );
        }

        // ── 对手方：人填名称 → 平台查 ent_id → 按发货/收货语义落位 ──
        $fromUserId = '';
        $toUserId = '';
        $physicType = '';
        if (self::needsCounterparty($billType)) {
            $client = new ApiClient((string)$credential['appkey'], (string)$credential['secretkey']);
            $partner = EntDirectory::resolve(
                $company,
                $entName,
                $client,
                (string)$credential['ref_ent_id']
            );
            if ($partner === null) {
                throw new \RuntimeException(
                    "单号 {$djbh}: 往来单位「{$entName}」在门店「{$company}」名下查不到"
                    . '（对照平台上的往来单位名录或先建往来单位），拒绝建单'
                );
            }
            $endpoints = self::endpoints($billType, (string)$credential['ent_id'], $partner['ent_id']);
            $fromUserId = $endpoints['from_user_id'];
            $toUserId = $endpoints['to_user_id'];
            $physicType = self::PHYSIC_TYPE;
        }

        return [
            'company' => $company,
            'rq' => $rq,
            'djbh' => $djbh,
            'bill_type' => $billType,
            'trace_codes' => $traceCodes,
            // 名称只对调拨两类有意义（另两类没有对手方）——存进 ent_name 供页面显示与事后追查；
            // 采集来的门店行该列为空，两者在页面上的区别就是"有名字的是手工建的"
            'ent_name' => self::needsCounterparty($billType) ? $entName : '',
            'from_user_id' => $fromUserId,
            'to_user_id' => $toUserId,
            'physic_type' => $physicType,
            'credential_key' => (string)$credential['key'],
            'credential' => $credential,
        ];
    }

    /**
     * 落库 + 上传 + 翻任务状态（`prepare()` 的产物 → 真申报）。
     *
     * 落库在前是为了让这条单**在页面上看得见**：上传不论成败都会留下一条可追查的行，
     * 与采集单同处一张表、同一个来源值，上传任务页的"补传"按钮因此天然认得它。
     *
     * 上传交给 `App\RetailRetransmit`（补传链路的同一份实现）：三关 fail-closed、拆单、
     * 限速重试、写日志、失败也翻"已处理"——手工建单没有理由另写一份。
     *
     * @param array $prepared prepare() 的返回值
     * @return array{task_id: int, total: int, success: int, failed: int}
     */
    public static function create(array $prepared, Database $db, ?callable $onProgress = null): array
    {
        $db->execute(
            "INSERT INTO upload_tasks
                (rq, djbh, ent_name, trace_codes, bill_type, task_status, source, company, credential,
                 from_user_id, to_user_id, physic_type, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, '等待上传', ?, ?, ?, ?, ?, ?, datetime('now','localtime'), datetime('now','localtime'))",
            [
                $prepared['rq'],
                $prepared['djbh'],
                $prepared['ent_name'],
                $prepared['trace_codes'],
                $prepared['bill_type'],
                self::TASK_SOURCE,
                $prepared['company'],
                $prepared['credential_key'],
                $prepared['from_user_id'],
                $prepared['to_user_id'],
                $prepared['physic_type'],
            ]
        );

        $taskId = $db->lastInsertId();
        // 上传读的是**落库行**而不是 $prepared：链路要用的字段以库里的为准（与补传同一条契约），
        // 也让"手工建的单能被补传"成立——补传拿到的就是同一行
        $task = $db->queryOne('SELECT * FROM upload_tasks WHERE id = ?', [$taskId]);

        $result = (new RetailRetransmit())->retransmit($task, $db, $onProgress, self::LOG_SOURCE);

        return ['task_id' => $taskId] + $result;
    }
}
