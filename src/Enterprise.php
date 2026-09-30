<?php
/**
 * 企业（申报主体）与凭据：配置解析、门店认领、接口路由
 *
 * 为什么"凭据是路由主体、门店与凭据是 1:N"：见 docs/adr/0006-credential-as-routing-subject.md
 * 完整设计：见 .scratch/retail-chain/spec.md
 *
 * 配置分两文件（凭据绝不入 git）：
 *   config/enterprises.php        结构：企业名 / 类型 / 凭据位（label、primary）
 *   config/enterprises.local.php  取值：平台 ID 列表 + 凭据四字段明文
 */
namespace App;

class Enterprise
{
    /** 认领失败时的 company 值：照常入库，页面显著提示、禁用补传（丢单比错标更危险） */
    public const UNIDENTIFIED = '未识别';

    /** 企业类型 */
    public const TYPE_WHOLESALE = 'wholesale';
    public const TYPE_RETAIL = 'retail';

    /** 凭据必须同时填齐的四个字段；四项全空 = 待配凭据（合法），填一半 = 配置错误 */
    private const CREDENTIAL_FIELDS = ['appkey', 'secretkey', 'ref_ent_id', 'ent_id'];

    /**
     * 门店平台 ID 在哪一列。
     *
     * 实测依据（2026-09-29 只读探测 dyt.msfx.dbo.zsm_ls 119,522 行）：
     *   - 321 使用出库 / 116 消费者退货入库 → 门店在 from_user_id（门店是发货方）
     *   - 104 调拨入库 / 203 调拨出库     → 门店在 to_user_id（门店是收货方）
     *   - oper_ic_name 对 321/116 共 101,288 行（84.7%）**全空**，故名字只能作回退键；
     *     而 321/116 的 20 个非空 from_user_id 实测 20/20 都能由 104 的"名字↔ID"对照认到门店。
     */
    private const STORE_ID_COLUMN = [
        '321' => 'from_user_id',
        '116' => 'from_user_id',
        '104' => 'to_user_id',
        '203' => 'to_user_id',
    ];

    /**
     * 接口路由与追溯码上限 [(企业类型)][(单据类型|'*')] => [class, limit]
     *
     * 同一单据类型码在批发/零售下走**不同接口**（用户确认：所有门店通用一套映射）。
     * 码上限来自各接口的平台限制：kyt 3500、lsyd.uploadinoutbill 10000、lsyd.uploadretail 3500。
     * 请求类名用 ::class 常量——PHP 编译期解析，不触发加载，故零售 SDK 类并入前（工单 04）本表即可用。
     */
    private const ROUTES = [
        self::TYPE_WHOLESALE => [
            '*' => ['class' => \AlibabaAlihealthDrugKytUploadinoutbillRequest::class, 'limit' => 3500],
        ],
        self::TYPE_RETAIL => [
            '104' => ['class' => \AlibabaAlihealthDrugtraceTopLsydUploadinoutbillRequest::class, 'limit' => 10000],
            '203' => ['class' => \AlibabaAlihealthDrugtraceTopLsydUploadinoutbillRequest::class, 'limit' => 10000],
            '321' => ['class' => \AlibabaAlihealthDrugtraceTopLsydUploadretailRequest::class, 'limit' => 3500],
            '116' => ['class' => \AlibabaAlihealthDrugtraceTopLsydUploadretailRequest::class, 'limit' => 3500],
        ],
    ];

    /** @var array<string,array> 合并后的企业表（企业 key => 企业） */
    private static array $companies = [];

    /** @var array<string,string> 平台 ID => 企业 key */
    private static array $idIndex = [];

    /** @var array<string,string> 企业名（中文全名）=> 企业 key */
    private static array $nameIndex = [];

    /** @var array<int,string> 合并阶段（索引构建前）发现的问题 */
    private static array $mergeErrors = [];

    private static bool $loaded = false;

    /**
     * 从配置文件载入并合并结构 + 取值。
     *
     * @throws \RuntimeException 配置违反不变量时抛出（启动即暴露，避免采集时静默错标）
     */
    public static function loadFromFiles(?string $structurePath = null, ?string $localPath = null): void
    {
        $structurePath ??= __DIR__ . '/../config/enterprises.php';
        $localPath ??= __DIR__ . '/../config/enterprises.local.php';

        // local 文件可能引用 .env（迁移期河药批发凭据仍从 .env 取），先确保 Config 已载入
        Config::load();

        $structure = is_file($structurePath) ? require $structurePath : [];
        $local = is_file($localPath) ? require $localPath : [];

        self::load(is_array($structure) ? $structure : [], is_array($local) ? $local : []);
    }

    /**
     * 载入（合并）配置。测试用固化数组调用，生产用 loadFromFiles()。
     *
     * @param array $structure 结构：['companies' => [['key','name','type','credentials'=>[key=>['label','primary']]]]]
     * @param array $local     取值：['ids' => [企业key => [平台ID...]], 'credentials' => [企业key => [凭据key => [四字段]]]]
     * @throws \RuntimeException 配置违反不变量时抛出
     */
    public static function load(array $structure, array $local = []): void
    {
        self::$companies = [];
        self::$mergeErrors = [];

        $localIds = $local['ids'] ?? [];
        $localCredentials = $local['credentials'] ?? [];

        foreach ($structure['companies'] ?? [] as $company) {
            $key = trim((string)($company['key'] ?? ''));
            if ($key === '') {
                self::$mergeErrors[] = '有企业缺少 key（企业稳定标识）';
                continue;
            }
            if (isset(self::$companies[$key])) {
                self::$mergeErrors[] = "企业 key 重复: {$key}";
                continue;
            }

            $credentials = [];
            foreach ($company['credentials'] ?? [] as $credentialKey => $declared) {
                $secrets = $localCredentials[$key][$credentialKey] ?? [];
                $credentials[(string)$credentialKey] = array_merge(
                    ['label' => '', 'primary' => false],
                    is_array($declared) ? $declared : [],
                    ['key' => (string)$credentialKey],
                    is_array($secrets) ? $secrets : []
                );
            }

            $ids = array_values(array_unique(array_filter(array_map(
                'trim',
                (array)($localIds[$key] ?? [])
            ), fn($v) => $v !== '')));

            self::$companies[$key] = [
                'key' => $key,
                'name' => trim((string)($company['name'] ?? '')),
                'type' => trim((string)($company['type'] ?? '')),
                'ids' => $ids,
                'credentials' => $credentials,
            ];
        }

        if (empty(self::$companies)) {
            self::$mergeErrors[] = '配置里没有任何企业（config/enterprises.php 缺失或为空）';
        }

        self::buildIndexes();
        self::$loaded = true;

        $errors = self::validate();
        if (!empty($errors)) {
            throw new \RuntimeException("企业配置有误:\n  - " . implode("\n  - ", $errors));
        }
    }

    /** 清空状态（测试用） */
    public static function reset(): void
    {
        self::$companies = [];
        self::$idIndex = [];
        self::$nameIndex = [];
        self::$mergeErrors = [];
        self::$loaded = false;
    }

    /** @return array<string,array> 全部企业（企业 key => 企业） */
    public static function all(): array
    {
        self::ensureLoaded();
        return self::$companies;
    }

    /** @return array<int,string> 全部企业名（页面"所属企业"筛选下拉用） */
    public static function names(): array
    {
        self::ensureLoaded();
        return array_values(array_map(fn($c) => $c['name'], self::$companies));
    }

    /** 按企业名（company 列的值）查找 */
    public static function find(string $company): ?array
    {
        self::ensureLoaded();
        $key = self::$nameIndex[trim($company)] ?? null;
        return $key === null ? null : self::$companies[$key];
    }

    /** 按企业稳定标识（配置 key）查找 */
    public static function findByKey(string $key): ?array
    {
        self::ensureLoaded();
        return self::$companies[$key] ?? null;
    }

    /** 是否零售企业（决定走 lsyd 接口；未知企业返回 false） */
    public static function isRetail(string $company): bool
    {
        $found = self::find($company);
        return ($found['type'] ?? '') === self::TYPE_RETAIL;
    }

    /**
     * 批发申报主体：['key' => 配置 key, 'name' => 企业全名, 'credential_key' => primary 凭据位键]。
     *
     * 批发链路（采集落库 / cron 取数 / 手动上传 / 检查脚本）取"本项目的自动上传主体"的唯一入口，
     * 免得各脚本各自硬编码企业名与凭据键。当前只有河药一家批发企业，故按类型取唯一那家；
     * **出现第二家批发企业时抛异常而不是静默取第一个**——那正是"把单据申报到错误主体"的经典路径，
     * 必须由人显式选择主体后再改这里（见 docs/adr/0006）。
     *
     * 注意 credential_key 是**键**不是凭据数组（凭据数组由 Enterprise::credential() 按该键取）；
     * 键是落库到 upload_tasks.credential 列的值，也是 upload_logs.credential 的审计值。
     * 该企业连凭据位都没声明时 credential_key 为 null（照常返回，由调用方决定拒到什么程度）。
     *
     * @throws \RuntimeException 批发企业不是恰好一家
     */
    public static function wholesaleSubject(): array
    {
        self::ensureLoaded();

        $matched = [];
        foreach (self::$companies as $key => $company) {
            if ($company['type'] === self::TYPE_WHOLESALE) {
                $matched[$key] = $company;
            }
        }

        if (count($matched) !== 1) {
            throw new \RuntimeException(
                '批发主体必须恰好一家，当前配置里有 ' . count($matched) . ' 家'
                . ($matched ? '（' . implode('、', array_keys($matched)) . '）' : '')
                . '——批发链路无法确定用哪套凭据，拒绝继续'
            );
        }

        $key = (string)array_key_first($matched);

        return [
            'key' => $key,
            'name' => $matched[$key]['name'],
            'credential_key' => self::primaryCredentialKey($key),
        ];
    }

    /**
     * 门店认领：平台 ID 优先 → 名字回退 → 未识别。
     *
     * 返回的 credential 是**该企业的 primary 凭据键**（表达"默认会用哪套"）；采集时据此落库，
     * 补传时若人工切到备用凭据，由补传流程覆盖该行的 credential 值。
     * 无凭据的企业（待配凭据）credential 为 null，但 company 仍能认领——页面标"待配凭据"并禁用补传。
     *
     * @param string      $billType   单据类型码（104/203/321/116…）
     * @param string|null $fromUserId 源表 from_user_id
     * @param string|null $toUserId   源表 to_user_id
     * @param string|null $organName  源表 oper_ic_name（ID 缺失时的回退键）
     * @return array{company:string, company_key:?string, credential:?string, matched_by:string, name_unmatched:bool}
     *         matched_by: id | name | none
     */
    public static function claim(string $billType, ?string $fromUserId, ?string $toUserId, ?string $organName): array
    {
        self::ensureLoaded();

        $column = self::STORE_ID_COLUMN[BillType::normalize($billType)] ?? null;
        $platformId = $column === 'from_user_id' ? $fromUserId : ($column === 'to_user_id' ? $toUserId : null);
        $platformId = trim((string)$platformId);
        $name = trim((string)$organName);

        if ($platformId !== '' && isset(self::$idIndex[$platformId])) {
            $key = self::$idIndex[$platformId];
            return [
                'company' => self::$companies[$key]['name'],
                'company_key' => $key,
                'credential' => self::primaryCredentialKey($key),
                'matched_by' => 'id',
                // ID 认到了、名字却对不上任何企业 → 源库名字可疑（错值/改名/已关店），调用方记警告日志
                'name_unmatched' => $name !== '' && !isset(self::$nameIndex[$name]),
            ];
        }

        if ($name !== '' && isset(self::$nameIndex[$name])) {
            $key = self::$nameIndex[$name];
            return [
                'company' => self::$companies[$key]['name'],
                'company_key' => $key,
                'credential' => self::primaryCredentialKey($key),
                'matched_by' => 'name',
                'name_unmatched' => false,
            ];
        }

        return [
            'company' => self::UNIDENTIFIED,
            'company_key' => null,
            'credential' => null,
            'matched_by' => 'none',
            'name_unmatched' => $name !== '',
        ];
    }

    /**
     * 接口路由：(企业, 单据类型) => ['class' => 请求类名, 'limit' => 追溯码上限]
     * 未知企业或未知单据类型返回 null——**不猜**（猜错就是把单据申报到错误主体）。
     */
    public static function route(string $company, string $billType): ?array
    {
        self::ensureLoaded();
        $found = self::find($company);
        if ($found === null) {
            return null;
        }
        $routes = self::ROUTES[$found['type']] ?? [];
        return $routes[BillType::normalize($billType)] ?? $routes['*'] ?? null;
    }

    /** 取某企业的一套凭据（补传时按页面所选凭据键取用；不存在返回 null） */
    public static function credential(string $company, string $credentialKey): ?array
    {
        $found = self::find($company);
        return $found['credentials'][$credentialKey] ?? null;
    }

    /**
     * 某企业名下的 primary 凭据键——"这家默认会用哪套"。
     *
     * 编辑上传任务改"所属企业"时据此重设 credential 列：重传用哪套授权由 (company, credential)
     * 两列共同决定，只改企业不改凭据会让 UploadService 的守卫以"取不到可用凭据"拒传
     * （未识别行的 credential 本就是 NULL）。未知企业（含 `未识别`）返回 null，调用方照常落库，
     * 守卫届时明确拒传——**不猜**（猜错就是把单据申报到错误主体）。
     */
    public static function defaultCredentialKey(string $company): ?string
    {
        $found = self::find($company);
        return $found === null ? null : self::primaryCredentialKey($found['key']);
    }

    /** 凭据四字段是否填齐（未填齐 = 待配凭据，页面禁用补传） */
    public static function credentialConfigured(array $credential): bool
    {
        foreach (self::CREDENTIAL_FIELDS as $field) {
            if (trim((string)($credential[$field] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * 配置自检：返回问题列表（空数组 = 通过）。
     *
     * 这些不变量都是"违反了就会静默传错主体"的一类错误，故 load() 直接抛异常而不是只记日志。
     *
     * @return array<int,string>
     */
    public static function validate(): array
    {
        $errors = self::$mergeErrors;
        $seenIds = [];
        $seenNames = [];

        foreach (self::$companies as $key => $company) {
            if (!in_array($company['type'], [self::TYPE_WHOLESALE, self::TYPE_RETAIL], true)) {
                $errors[] = "{$key}: 企业类型非法（{$company['type']}），应为 wholesale / retail";
            }

            if ($company['name'] === '') {
                $errors[] = "{$key}: 缺少企业名（company 列的值）";
            } elseif (isset($seenNames[$company['name']])) {
                $errors[] = "企业名重复: {$company['name']}（{$seenNames[$company['name']]} 与 {$key}）";
            } else {
                $seenNames[$company['name']] = $key;
            }

            if ($company['type'] === self::TYPE_RETAIL && empty($company['ids'])) {
                $errors[] = "{$key}: 零售企业没有任何平台 ID（local 文件 ids），采集将无法认领其单据";
            }

            foreach ($company['ids'] as $id) {
                if (isset($seenIds[$id])) {
                    $errors[] = "平台 ID 重复: {$id} 同时属于 {$seenIds[$id]} 与 {$key}（会把单据认到错误门店）";
                } else {
                    $seenIds[$id] = $key;
                }
            }

            $primaryCount = 0;
            foreach ($company['credentials'] as $credentialKey => $credential) {
                $filled = 0;
                foreach (self::CREDENTIAL_FIELDS as $field) {
                    if (trim((string)($credential[$field] ?? '')) !== '') {
                        $filled++;
                    }
                }
                if ($filled > 0 && $filled < count(self::CREDENTIAL_FIELDS)) {
                    $errors[] = "{$key}/{$credentialKey}: 凭据字段残缺（四字段须同时填齐，全空表示待配凭据）";
                }
                if ($filled === count(self::CREDENTIAL_FIELDS)) {
                    foreach (['ref_ent_id', 'ent_id'] as $field) {
                        if (!in_array(trim((string)$credential[$field]), $company['ids'], true)) {
                            $errors[] = "{$key}/{$credentialKey}: {$field} 不在该企业的平台 ID 列表内（上传主体与认领主体不一致）";
                        }
                    }
                }
                if (!empty($credential['primary'])) {
                    $primaryCount++;
                }
            }
            if (count($company['credentials']) > 1 && $primaryCount !== 1) {
                $errors[] = "{$key}: 有 " . count($company['credentials'])
                    . " 套凭据，须恰好一套标 primary（当前 {$primaryCount} 套）";
            }
        }

        return $errors;
    }

    private static function ensureLoaded(): void
    {
        if (!self::$loaded) {
            self::loadFromFiles();
        }
    }

    private static function buildIndexes(): void
    {
        self::$idIndex = [];
        self::$nameIndex = [];

        foreach (self::$companies as $key => $company) {
            if ($company['name'] !== '') {
                self::$nameIndex[$company['name']] = $key;
            }
            foreach ($company['ids'] as $id) {
                self::$idIndex[$id] = $key;
            }
        }
    }

    /** primary 凭据键；单套凭据无需显式标记，取唯一那套 */
    private static function primaryCredentialKey(string $companyKey): ?string
    {
        $credentials = self::$companies[$companyKey]['credentials'] ?? [];
        if (empty($credentials)) {
            return null;
        }
        foreach ($credentials as $credentialKey => $credential) {
            if (!empty($credential['primary'])) {
                return (string)$credentialKey;
            }
        }
        return (string)array_key_first($credentials);
    }
}
