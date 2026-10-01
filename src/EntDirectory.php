<?php
/**
 * 往来单位名录：把"人填的名称"换成"平台认的 ent_id"。
 *
 * 缓存表是 SQLite 的 `ent_list`，唯一键 **`(company, ent_name)`**——同名往来单位在不同申报主体下
 * 各存一行，因为它是相对**申报主体**而言的：河药名下的"某某药店"与门店名下的同名单位是两个不同的
 * ent_id。查平台时的 `ref_ent_id` 同理，必须是**申报主体自己那套**（`$credential['ref_ent_id']`）——
 * 用错主体的编码去查，查到的是别人名下的往来单位，单据随后会被报到错误主体，且平台上不可逆。
 *
 * 两个调用方：
 *   - `UploadService::resolveEntId()`（批发：cron 采集单与手动上传）
 *   - `RetailManualEntry::prepare()`（门店手工建单：104/203 的对手方）
 *
 * 本类只做「查」：缓存未命中才调平台，查到才回写缓存。查不到返回 null 由调用方决定怎么拒
 * （批发记"往来单位缺失"并留日志，门店手工建单直接拒建单、不落任何库）。
 */
namespace App;

class EntDirectory
{
    /**
     * 按 (企业, 名称) 查往来单位，缓存优先。
     *
     * 客户端与查询用的 `ref_ent_id` 都从**同一份凭据**取（`ApiClient::forCredential` + 凭据的
     * `ref_ent_id`）：它们是"用谁的名录查"的两个面，由调用方分别给的话，给成两家的就是拿甲的名录
     * 去查乙的往来单位——查到的是别人名下的单位，单据随后报到错误主体。**收一个凭据参数即从构造上
     * 免掉这种配错**（与本项目"少一个可传错的参数"的既有做法同向）。
     *
     * @param string $company    申报主体名（company 列的值）
     * @param string $entName    人填的往来单位名称（精确匹配，不做模糊）
     * @param array  $credential 该企业那套凭据（四字段 + key）
     * @return array{ent_name: string, ent_id: string, ref_ent_id: string}|null null = 缓存与平台都没有
     */
    public static function resolve(string $company, string $entName, array $credential): ?array
    {
        $entName = trim($entName);
        if ($entName === '') {
            return null;
        }

        $db = Database::getInstance();
        $cached = $db->queryOne(
            "SELECT ent_id, ref_ent_id FROM ent_list WHERE ent_name = ? AND company = ?",
            [$entName, $company]
        );
        if ($cached && !empty($cached['ent_id'])) {
            return [
                'ent_name' => $entName,
                'ent_id' => (string)$cached['ent_id'],
                'ref_ent_id' => (string)($cached['ref_ent_id'] ?? ''),
            ];
        }

        $entInfo = ApiClient::forCredential($credential)->queryEntInfo($entName, (string)($credential['ref_ent_id'] ?? ''));
        if ($entInfo === null || empty($entInfo['ent_id'])) {
            return null;
        }

        // 缓存键是 (company, ent_name)，与唯一键一致——同名单位跨企业各存一行，不互相顶掉
        $db->execute(
            "INSERT OR REPLACE INTO ent_list (company, ent_name, ent_id, ref_ent_id) VALUES (?, ?, ?, ?)",
            [$company, $entInfo['ent_name'] ?? $entName, (string)$entInfo['ent_id'], (string)($entInfo['ref_ent_id'] ?? '')]
        );

        return [
            'ent_name' => (string)($entInfo['ent_name'] ?? $entName),
            'ent_id' => (string)$entInfo['ent_id'],
            'ref_ent_id' => (string)($entInfo['ref_ent_id'] ?? ''),
        ];
    }
}
