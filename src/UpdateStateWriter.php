<?php
/**
 * 回写零售源库的「外部系统上传状态」表 dyt.bs_msfx.dbo.update_state
 *
 * 写什么：一条门店单据**已经申报到平台**（上传成功或单据重复），外部系统不必再传它。
 * 为什么：外部系统拿这张表判断"哪些单还没传"，本项目采集侧也读它分流
 * （scripts/fetch_bills_retail.php 的 EXISTS 标志列 → 已上传的单落成「外部上传」记录，
 * 见 App\RetailExternalUploads）——补传成功后写这一行，两边认知才一致。
 * 表名常量收在 `RetailExternalUploads::TABLE`（全仓唯一一处）；本类是它的**写侧**。
 * 决策与探测证据见 docs/adr/0016。
 *
 * 三条硬约束（2026-10-02 探测实证）：
 *   1. **绝不包在本地事务里**：BEGIN TRAN 后写链接服务器会尝试启动分布式事务，而该链接服务器的
 *      OLE DB 接口（SQLNCLI10）报"无法启动分布式事务"（MSDTC 被禁）——包了必然失败。
 *   2. 该表**无主键、无唯一约束**（且已有同号多行的先例，外部系统自己也重复写），幂等只能靠 SQL
 *      自身：INSERT ... SELECT ... WHERE NOT EXISTS。不"先查后插"——两步之间有竞态、多一次往返。
 *   3. 写失败**不能影响上传结果**（上传已不可逆）：记一条 JSONL 警告、返回 false，不抛异常——
 *      **包括记警告这一步本身**（logs 目录不可写也只吞掉，见 warn()）。警告只进 JSONL、
 *      不进 upload_logs——后者是上传结果日志，写进去会在失败记录页冒出既非上传也非失败的记录，
 *      污染唯一的告警出口（与采集脚本的 name_unmatched 同款处理，见 ADR 0007）。
 */
namespace App;

class UpdateStateWriter
{
    /** bill_state 的取值：与表内存量一致（2026-10-02 实测 67,856 行全为 '1'，列是 varchar） */
    private const STATE_UPLOADED = '1';

    private ?array $config;

    /** 惰性建立：三关被拒、一个平台调用都没发的单，不该先白连一次源库 */
    private ?\SqlSrvHelper $db = null;

    /**
     * @param array|null $config 连接配置；null 时从 Config 取（与 TaskFetcher 同口径）
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config;
    }

    /**
     * 幂等写入一条"已上传"状态。
     *
     * @param string $billCode 单据号（原始单号；拆分的子单号对源库那张表没有意义）
     * @return int|false 受影响行数（1=新写入，0=表里已有该单号、跳过），false=写失败（已记警告）
     */
    public function markUploaded(string $billCode)
    {
        $billCode = trim($billCode);
        if ($billCode === '') {
            // 空单号与写失败走同一个契约（false + 已记警告）：若这里静默返回 false，
            // 调用方会把"参数是空的"这个调用方的错误，当成一次"写失败"计进统计
            $this->warn('', '单号为空，未写入');
            return false;
        }

        try {
            // 一条语句里判存在 + 插入：远程执行、自动提交，不需要（也不能有）本地事务。
            // 表名取自 RetailExternalUploads::TABLE（读侧与写侧唯一的表名来源）
            $affected = $this->db()->execute(
                'insert into ' . RetailExternalUploads::TABLE . ' (bill_code, bill_state)
                 select ?, ? where not exists (select 1 from ' . RetailExternalUploads::TABLE . ' where bill_code = ?)',
                [$billCode, self::STATE_UPLOADED, $billCode]
            );

            if ($affected === false) {
                $this->warn($billCode, $this->db()->getErrorMessage());
                return false;
            }

            return $affected;
        } catch (\Throwable $e) {
            // SqlSrvHelper 构造即连接，连不上会在这里抛出——与写失败同样处理：记警告、不抛。
            // catch 包住**整个** try（而不只是 execute 那一句）：本方法对外的承诺是"绝不抛"，
            // 就不能有任何一句留在保护圈外
            $this->warn($billCode, $e->getMessage());
            return false;
        }
    }

    /**
     * 缓存的连接。**缓存只在连接建成后生效**：源库不可达时构造抛异常、`$this->db` 仍是 null，
     * 下一条 markUploaded() 会再试一次——这是刻意的重试语义（源库中途恢复，后面的单仍能写上，
     * 不整批放弃），代价是失败路径下每条单据各等一次登录超时（故超时取 5s 而非默认 30s）。
     * 手工导入逐条 new RetailRetransmit()，缓存粒度就是"一个实例"。
     */
    private function db(): \SqlSrvHelper
    {
        if ($this->db === null) {
            // 五字段取自 Config::sqlServer()（唯一来源）；timeout 在调用点叠加——
            // 注入 $config 时不叠加，原样下传（保留注入点的原语义）。
            // 数组并集是**左侧优先**，故 sqlServer() 永远不能含 timeout（tests/config_test.php
            // 的"键集合恰为五字段"钉着这一点），否则这里的 5s 会被静默吞掉
            $this->db = new \SqlSrvHelper($this->config ?? (Config::sqlServer() + [
                // 登录超时比 SqlSrvHelper 的默认 30s 短得多：这条回写在 Web 请求里（人点补传），
                // 源库不可达时每条成功单都要卡一次连接超时，30s × 一屏单据 = 操作者以为页面死了。
                // 源库在同一内网，5s 连不上就是不可用——写失败本就是尽力而为（记警告继续）
                'timeout' => 5,
            ]));
        }
        return $this->db;
    }

    /**
     * 写一条 JSONL 警告。走 `LogWriter::writeJsonlOnly()`——**不写 SQLite** 的那条车道
     * （`write()` 会写进 `upload_logs`，失败记录页随之冒出既非上传也非失败的记录，见类头第 3 条）。
     *
     * **整段吞异常**：本类对外的承诺是"回写绝不反过来影响上传结果"，而日志目录不可写
     * （或环境把 warning 转成异常）时，记警告这一步本身就会抛——丢一条告警，比抛穿整条补传链路轻。
     */
    private function warn(string $billCode, string $error): void
    {
        try {
            (new LogWriter())->writeJsonlOnly([
                'type' => 'update_state_write_failed',
                'djbh' => $billCode,
                'error' => $error,
            ]);
        } catch (\Throwable $e) {
            // 无处可记，只能吞
        }
    }
}
