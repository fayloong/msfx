<?php
/**
 * 企业（申报主体）与凭据：配置解析、门店认领、接口路由
 *
 * 为什么"凭据是路由主体"：见 docs/adr/0006-credential-as-routing-subject.md；
 * 为什么门店与凭据是 **1:1**（每家企业恰一套，补传不由人选凭据）：见 docs/adr/0012。
 * 完整设计：见 .scratch/retail-chain/spec.md
 *
 * 配置分两文件（凭据绝不入 git）：
 *   config/enterprises.php        结构：企业名 / 类型 / 凭据位（label）
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

    /**
     * 凭据就绪态——`credentialReady()` 的取值，页面据此决定"所属企业"下拉里怎么显示。
     * 含义见 `credentialReady()` 的说明。
     *
     * 这几个字面量**同时是前端与 PHP 两侧的口径**（页面把 `retailCredentialReady()` 的结果
     * json_encode 进内联 JS，那儿按 `'pending'` / `'no_slot'` 直接比较），故这里定常量只为
     * 消掉 PHP 侧的拼写漂移，别当成"词汇表已经单点收口"——改取值要连内联 JS 一起改。
     */
    public const STATE_READY = 'ready';
    public const STATE_PENDING = 'pending';
    public const STATE_NO_SLOT = 'no_slot';
    /** `未识别` 的态：它**不是企业**、没有凭据概念，故不在上面三态之列（页面不据此上样式） */
    public const STATE_UNIDENTIFIED = 'unidentified';

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
     * @param array $structure 结构：['companies' => [['key','name','type','credentials'=>[key=>['label']]]]]
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
                    ['label' => ''],
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
     * 批发申报主体：['key' => 配置 key, 'name' => 企业全名, 'credential_key' => 凭据位键]。
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
            'credential_key' => self::soleCredentialKey($key),
        ];
    }

    /**
     * 门店认领：平台 ID 优先 → 名字回退 → 未识别。
     *
     * 返回的 credential 是**该企业唯一那套凭据的键**（每家企业恰一套，见 docs/adr/0012）；
     * 采集时据此落库，补传时写回的也是同一套（`credential` 列只作审计，不参与任何键）。
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
                'credential' => self::soleCredentialKey($key),
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
                'credential' => self::soleCredentialKey($key),
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

    /** 取某企业的一套凭据（按凭据键取；不存在返回 null） */
    public static function credential(string $company, string $credentialKey): ?array
    {
        $found = self::find($company);
        return $found['credentials'][$credentialKey] ?? null;
    }

    /**
     * 某企业**那套**凭据（含 `key` 字段）——门店与凭据已定为 1:1（见 docs/adr/0012），
     * 故"这家用哪套"在配置里只有一个答案，不需要（也不该）由页面或调用方指定。
     *
     * 返回 null 的两种情形：企业不在配置里、该企业没声明任何凭据位。
     * **待配凭据**（凭据位在、四字段未填齐）返回的是那套空凭据而非 null——调用方用
     * `credentialConfigured()` 判定后拒传，与 `claim()` 的语义一致（认领得到、但还不能传）。
     */
    public static function credentialFor(string $company): ?array
    {
        $found = self::find($company);
        if ($found === null) {
            return null;
        }
        $key = self::soleCredentialKey($found['key']);
        return $key === null ? null : $found['credentials'][$key];
    }

    /**
     * **全部企业**的凭据就绪态（页面用）：企业名 => 三态之一。
     *
     *   'ready'    凭据四字段填齐，可以上传
     *   'pending'  凭据位在、四字段没填齐——**待配凭据**，等 AppKey/SECRETKEY 到手即可，预期内的正常状态
     *   'no_slot'  连凭据位都没在配置里声明——**配置缺口**，不是等就能好的那种
     *
     * 企业名不在返回的数组里 = 该企业不在配置中。
     *
     * 与 `retailCredentialReady()` 的差别**只有"覆盖哪些企业"**：这份含批发主体（那个下拉里批发企业
     * 也在），且它的凭据迁到配置前仍从 `.env` 读——`.env` 缺字段时它同样会是 `pending`，此时标出来
     * 才是真话。三态的判据只在这一处，"下拉里标着能用、按钮却是禁用"那种自相矛盾因此不可能出现。
     *
     * @return array<string,string>
     */
    public static function credentialReady(): array
    {
        self::ensureLoaded();

        $ready = [];
        foreach (self::$companies as $company) {
            $credential = self::credentialFor($company['name']);
            $ready[$company['name']] = $credential === null
                ? self::STATE_NO_SLOT
                : (self::credentialConfigured($credential) ? self::STATE_READY : self::STATE_PENDING);
        }
        return $ready;
    }

    /**
     * 零售门店的补传可用性（页面用）：门店名 => 同款三态，**只含零售**。
     *
     * 三种"不能补传"分开给，是因为它们要不同的人做不同的事（等授权 / 补配置 / 查配置或源库），
     * 混成一句"不可用"会误导操作者——尤其别把 `no_slot` 说成"预期内的正常状态"（它是配置漏了，
     * 链路那头 fail-closed 照样拦得住，但页面上的说明得是真的）。门店名缺席 = 该门店不在配置中。
     *
     * 判据全部来自 `credentialReady()`，这里只按企业类型过滤：两份各遍历一遍，迟早会出现
     * "下拉里标着能用、补传按钮却是禁用"这种没人能一眼看出矛盾的状态。
     *
     * 页面曾经还要渲染凭据下拉、故得拿到凭据位键与 label；取消人工选凭据后这些都不必再出后端，
     * 少一份"哪些字段能出页面"的口径要维护。
     *
     * @return array<string,string>
     */
    public static function retailCredentialReady(): array
    {
        $ready = [];
        foreach (self::credentialReady() as $name => $state) {
            if (self::isRetail($name)) {
                $ready[$name] = $state;
            }
        }
        return $ready;
    }

    /**
     * 页面"所属企业"下拉与编辑弹窗的选项：**企业名 => 凭据就绪态**（见 `credentialReady()`），
     * 另含 `未识别` => `'unidentified'`。
     *
     * 键与值一起给，是因为它们要被同一个 `<option>` 一起用——就绪态决定这个选项长什么样
     * （`companyOptionAttrs()`）。分两处取，就会有人只取名字、忘了取态，那个下拉里的待配凭据企业
     * 于是悄悄变回正常字体，**而没有任何地方会报错**。改形时连名字一起换、不留 `selectableNames`
     * 别名也是为此：老调用点当场炸，而不是静默丢掉样式。
     *
     * `未识别` **不是一个企业**（故态取 `unidentified`，不在三态之列），但它确实是 `company` 列的
     * 一个取值（门店认领失败的行）——那正是需要人去查配置或源库的那批，必须能单独筛出来。
     * 全站 6 处下拉（三个筛选栏 + 两个编辑弹窗 + 手动上传页顶部）共用这一份，
     * 免得"哪些选项该出现"在各视图里各写一遍（本项目的常见漂移来源）。
     *
     * @return array<string,string>
     */
    public static function selectableOptions(): array
    {
        $options = self::credentialReady();
        // 排在最后。企业名真叫 `未识别` 属配置错误（自检不拦这一条），此处按哨兵值处理——
        // 展示上不会多出一项
        $options[self::UNIDENTIFIED] = self::STATE_UNIDENTIFIED;
        return $options;
    }

    /**
     * 某企业名下的凭据键——每家企业恰一套（docs/adr/0012），故这也是"这家用哪套"的唯一答案。
     *
     * 编辑上传任务改"所属企业"时据此重设 credential 列：重传用哪套授权由 (company, credential)
     * 两列共同决定，只改企业不改凭据会让 UploadService 的守卫以"取不到可用凭据"拒传
     * （未识别行的 credential 本就是 NULL）。未知企业（含 `未识别`）返回 null，调用方照常落库，
     * 守卫届时明确拒传——**不猜**（猜错就是把单据申报到错误主体）。
     */
    public static function defaultCredentialKey(string $company): ?string
    {
        $found = self::find($company);
        return $found === null ? null : self::soleCredentialKey($found['key']);
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

            // 门店与凭据已定为 1:1（docs/adr/0012）：多套凭据会让"补传用哪套"重新变成一个要人做的
            // 选择，而页面上已经没有这个选择项了——故在配置层直接拒绝，而不是悄悄取其中一套。
            if (count($company['credentials']) > 1) {
                $errors[] = "{$key}: 声明了 " . count($company['credentials']) . " 套凭据（"
                    . implode('、', array_keys($company['credentials']))
                    . "）——每家企业只允许一套凭据（见 docs/adr/0012）";
            }

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

    /**
     * 该企业唯一那套凭据的键（没有凭据位时 null）。
     *
     * `validate()` 已保证每家至多一套，"取哪套"因此没有第二个答案——**`primary` 标记不再被读取**：
     * 它在多套时代用来在结构文件声明的几套之间指定默认，如今那个问题不存在（见 docs/adr/0012）。
     * 留着这个分支会让"放开多套"变成一次静默的行为变化，不如让它现在就不可达。
     */
    private static function soleCredentialKey(string $companyKey): ?string
    {
        $credentials = self::$companies[$companyKey]['credentials'] ?? [];
        return empty($credentials) ? null : (string)array_key_first($credentials);
    }
}
