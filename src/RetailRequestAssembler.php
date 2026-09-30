<?php
/**
 * 零售补传的请求装配（纯函数：不发起任何平台调用、不读数据库、不写日志）
 *
 * 输入：一条零售单据的数据（已落库任务的形状）+ 一套门店凭据；
 * 输出：参数装配好的 lsyd 请求对象 + 该接口的追溯码上限（两者都取自 App\Enterprise::route()）。
 *
 * 为什么需要这个接缝：装配里混淆任意一项（refUserId 取了 ent_id、from/toUserId 取反、漏 clientType…）
 * 都会把单据申报到**错误主体**，且在平台上不可逆；而这些取值分散在 SDK docblock、源表列与凭据里，
 * 靠人眼复核不住。映射规则与那条待确认项见 docs/adr/0010-retail-request-parameter-mapping.md。
 *
 * 装配规格（只填 check() 强制的必填项 + 源库能直接确定的值，其余可选字段一律不填——少填比填错安全）：
 *   lsyd.uploadinoutbill（104/203）：billCode、billTime、billType、clientType(="2")、fromUserId、
 *       physicType、refUserId、toUserId、traceCodes；码上限 10000
 *   lsyd.uploadretail（321/116）：billCode、billTime、billType、refUserId、traceCodes；码上限 3500
 *     （这个接口里没有 clientType 字段，fromUserId 可空、physicType 可随便填——本轮都不填）
 */
namespace App;

// 请求类不在 composer 的 autoload 里（它们由 SDK 自己的 Autoloader 加载，与 ApiClient 同构）；
// 本类不依赖 ApiClient，故自己引入 SDK 入口
require_once __DIR__ . '/../top_sdk/TopSdk.php';

class RetailRequestAssembler
{
    /** 客户端类型：上传接口必须填 "2"（仅 lsyd.uploadinoutbill 有此字段） */
    private const CLIENT_TYPE = '2';

    /**
     * 装配一个 lsyd 上传请求。
     *
     * @param array $bill 单据数据，键名沿用落库任务列 + 源表同名列：
     *                    djbh（单号）、rq（单据日期）、bill_type、trace_codes、
     *                    from_user_id / to_user_id（仅 uploadinoutbill 用）、physic_type（同前，源表实测恒为 3）
     * @param string $company    企业名（即 company 列的值）
     * @param array  $credential 该企业的凭据（四字段），refUserId 取其中的 ref_ent_id
     * @return array{request: object, limit: int} limit = 该接口的追溯码上限，调用方据此拆单
     * @throws \RuntimeException 非零售企业 / 凭据不属于该企业 / 单据类型无路由 / 必填项缺失（经请求类 check() 判定）
     */
    public static function assemble(array $bill, string $company, array $credential): array
    {
        $djbh = trim((string)($bill['djbh'] ?? ''));

        // fail-closed：本装配只做零售 lsyd。批发链路有自己的装配（UploadService::uploadSingle），
        // 拿零售模板去装配批发单据、或拿批发主体装配门店单据，都会把单据报到错误主体。
        if (!Enterprise::isRetail($company)) {
            throw new \RuntimeException("单号 {$djbh}: 「{$company}」不是零售企业，本装配只做零售 lsyd 接口");
        }

        // 传入的凭据必须**确实属于**这家企业：(企业, 凭据) 是调用方给的两个独立参数，配错即错主体
        // （UploadService::resolveContext 用"按企业 + 凭据键现取"从构造上避免了这件事，本接缝收的是
        // 凭据数组本身，故在此比对）。只比对 ref_ent_id：它是装配唯一从凭据取值的字段。
        // 凭据的 key 由 Enterprise::load() 盖上，故取自 Enterprise::credential() 的凭据天然通过
        $credentialKey = trim((string)($credential['key'] ?? ''));
        $configured = $credentialKey === '' ? null : Enterprise::credential($company, $credentialKey);
        if ($configured === null || (string)($configured['ref_ent_id'] ?? '') !== (string)($credential['ref_ent_id'] ?? '')) {
            throw new \RuntimeException(
                "单号 {$djbh}: 传入的凭据不属于企业「{$company}」"
                . ($credentialKey === '' ? '（凭据未标 key，无法确认归属）' : "（凭据位 {$credentialKey}）")
                . '，拒绝装配'
            );
        }

        $billType = BillType::normalize((string)($bill['bill_type'] ?? ''), $djbh);

        // 请求类与码上限一律取自路由，不硬编码类名、不硬编码 3500/10000
        $route = Enterprise::route($company, $billType);
        if ($route === null) {
            throw new \RuntimeException("单号 {$djbh}: 企业「{$company}」的单据类型「{$billType}」没有接口路由，拒绝装配");
        }

        $class = $route['class'];
        $req = new $class;

        $req->setBillCode($djbh);
        $req->setBillTime((string)($bill['rq'] ?? ''));
        $req->setBillType($billType);
        // refUserId 是**凭据里的 ref_ent_id**，不是 ent_id、也不是源表 zsm_ls.ref_ent_id
        // （源表那列全表单一值、属总部主体）。SDK docblock 写死："该入参是 ref_ent_id，不是 ent_id"
        $req->setRefUserId((string)($credential['ref_ent_id'] ?? ''));
        $req->setTraceCodes((string)($bill['trace_codes'] ?? ''));

        // uploadinoutbill 的专属必填项。clientType 是它独有的字段——uploadretail 类里根本没有，
        // 故用它可以判定"这批参数只有 uploadinoutbill 需要"（能力探测，不硬编码类名）
        if (method_exists($req, 'setClientType')) {
            $req->setClientType(self::CLIENT_TYPE);
            $req->setPhysicType((string)($bill['physic_type'] ?? ''));
            // 照搬源表同名列（用户 2026-09-29 定案）。⚠️ 待确认项：SDK docblock 写作发货/收货语义，
            // 而源表 104/203 的 from_user_id 都只有总部一个值、不随调拨方向翻转——若外部系统工程师
            // 确认 203 应反向使用，只改这两行取值（见 ADR 0010）
            $req->setFromUserId((string)($bill['from_user_id'] ?? ''));
            $req->setToUserId((string)($bill['to_user_id'] ?? ''));
        }

        // 必填项缺一即拒（平台契约由 SDK 的 check() 表达，本类不另抄一份清单）；
        // 挡住的是"装配出一个必被平台退回、却已经把单号占掉的请求"
        try {
            $req->check();
        } catch (\Exception $e) {
            throw new \RuntimeException("单号 {$djbh}: 装配出的请求未通过 SDK 校验——" . $e->getMessage(), 0, $e);
        }

        return ['request' => $req, 'limit' => (int)$route['limit']];
    }
}
