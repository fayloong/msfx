<?php
/**
 * 回写零售源库的「外部系统上传状态」表 dyt.bs_msfx.dbo.update_state
 *
 * 写什么：一条门店单据**已经申报到平台**（上传成功或单据重复），外部系统不必再传它。
 * 为什么：外部系统拿这张表判断"哪些单还没传"，本项目采集侧也用它过滤
 * （scripts/fetch_bills_retail.php 的 NOT EXISTS）——补传成功后写这一行，两边认知才一致。
 * 决策与探测证据见 docs/adr/0016。
 *
 * 三条硬约束（2026-10-02 探测实证）：
 *   1. **绝不包在本地事务里**：BEGIN TRAN 后写链接服务器会尝试启动分布式事务，而该链接服务器的
 *      OLE DB 接口（SQLNCLI10）报"无法启动分布式事务"（MSDTC 被禁）——包了必然失败。
 *   2. 该表**无主键、无唯一约束**（且已有同号多行的先例，外部系统自己也重复写），幂等只能靠 SQL
 *      自身：INSERT ... SELECT ... WHERE NOT EXISTS。不"先查后插"——两步之间有竞态、多一次往返。
 *   3. 写失败**不能影响上传结果**（上传已不可逆）：记一条 JSONL 警告、返回 false，不抛异常。
 *      警告只进 JSONL、不进 upload_logs——后者是上传结果日志，写进去会在失败记录页冒出既非上传
 *      也非失败的记录，污染唯一的告警出口（与采集脚本的 name_unmatched 同款处理，见 ADR 0007）。
 */
namespace App;

class UpdateStateWriter
{
    /** 远程表名（4 段式链接服务器名；连接连的是本实例的 hyyy_zyscm，表在 dyt 那侧） */
    private const TABLE = 'dyt.bs_msfx.dbo.update_state';

    /** bill_state 的取值：与表内存量一致（存量 67,842 行全为 '1'，列是 varchar） */
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
    public function mark(string $billCode)
    {
        $billCode = trim($billCode);
        if ($billCode === '') {
            return false;
        }

        try {
            // 一条语句里判存在 + 插入：远程执行、自动提交，不需要（也不能有）本地事务
            $affected = $this->db()->execute(
                'insert into ' . self::TABLE . ' (bill_code, bill_state)
                 select ?, ? where not exists (select 1 from ' . self::TABLE . ' where bill_code = ?)',
                [$billCode, self::STATE_UPLOADED, $billCode]
            );
        } catch (\Throwable $e) {
            // SqlSrvHelper 构造即连接，连不上会在这里抛出——与写失败同样处理：记警告、不抛
            $this->warn($billCode, $e->getMessage());
            return false;
        }

        if ($affected === false) {
            $this->warn($billCode, $this->db()->getErrorMessage());
            return false;
        }

        return $affected;
    }

    private function db(): \SqlSrvHelper
    {
        if ($this->db === null) {
            $this->db = new \SqlSrvHelper($this->config ?? [
                'server'   => Config::get('DB_SERVER', '192.168.2.133'),
                'port'     => Config::get('DB_PORT', '1433'),
                'database' => Config::get('DB_DATABASE', 'hyyy_zyscm'),
                'username' => Config::get('DB_USERNAME', 'sa'),
                'password' => Config::get('DB_PASSWORD', ''),
                // 登录超时比 SqlSrvHelper 的默认 30s 短得多：这条回写在 Web 请求里（人点补传），
                // 源库不可达时每条成功单都要卡一次连接超时，30s × 一屏单据 = 操作者以为页面死了。
                // 源库在同一内网，5s 连不上就是不可用——写失败本就是尽力而为（记警告继续）
                'timeout'  => 5,
            ]);
        }
        return $this->db;
    }

    /**
     * 写一条 JSONL 警告（与 scripts/fetch_bills_retail.php 的 writeRetailWarning 同款出口）。
     *
     * 刻意不走 LogWriter：那条路会写进 SQLite upload_logs，失败记录页随之冒出既非上传也非失败的
     * 记录（见类头第 3 条）。
     */
    private function warn(string $billCode, string $error): void
    {
        $line = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => 'update_state_write_failed',
            'djbh' => $billCode,
            'error' => $error,
        ];
        file_put_contents(
            __DIR__ . '/../logs/api_' . date('Y-m-d') . '.jsonl',
            json_encode($line, JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
