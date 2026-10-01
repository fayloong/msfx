<?php
/**
 * 数据页（上传任务 / 已上传 / 失败记录）的筛选条件**单一事实源**。
 *
 * 原先四个入口（api/tasks、api/uploaded、api/failed、api/export）各写一份 WHERE 构造，
 * 后果实测过一次：export 的失败记录分支漏了 `source = 'quantity_check' OR` 豁免，
 * 于是"失败记录页看得见的数量对账告警，导出的 xlsx 里没有"。改一处漏一处是拷贝的必然，
 * 故收敛为唯一入口——导出与页面**走同一段代码**，"导出的行数与页面一致"从此不靠人核对。
 *
 * 用法（列表 API 与导出完全一致）：
 *   $q = RecordQuery::build(RecordQuery::TYPE_FAILED, $_GET);
 *   $db->queryOne("SELECT COUNT(*) as cnt FROM {$q['count_from']} {$q['where']}", $q['params']);
 *   $db->query("{$q['select']} {$q['where']} {$q['order']} LIMIT ? OFFSET ?",
 *              array_merge($q['params'], [$perPage, $offset]));
 *
 * 本类**不读超全局**：筛选参数由调用方传入，故可在不碰 Web 的情况下直接构造任意组合核对。
 */
namespace App;

class RecordQuery
{
    public const TYPE_TASKS = 'tasks';
    public const TYPE_UPLOADED = 'uploaded';
    public const TYPE_FAILED = 'failed';

    /** 合法类型全集（导出的 type 参数白名单用它，免得那个白名单成为第二份类型枚举） */
    public const TYPES = [self::TYPE_TASKS, self::TYPE_UPLOADED, self::TYPE_FAILED];

    /**
     * 构造一条记录的查询（WHERE + SELECT + ORDER），各数据页共用。
     *
     * @param string              $type    TYPE_TASKS / TYPE_UPLOADED / TYPE_FAILED
     * @param array<string,mixed> $filters 筛选参数（生产传 $_GET；本类不读超全局）
     * @return array{select:string, where:string, count_from:string, params:array<int,string>, order:string}
     *         where 为 '' 或 'WHERE a AND b'（不含前导空格）；
     *         count_from 是 `SELECT COUNT(*) … FROM` 的表名——由本类给出，免得调用方
     *         各自再写一遍表名（写错就会出现"计数与数据来自不同表"、页面对不上导出）
     * @throws \InvalidArgumentException 未知类型——**不静默返回空条件**：
     *         失败页因此会把全表当失败记录吐出来（而这正是它最不该出错的地方）
     */
    public static function build(string $type, array $filters): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(
                "RecordQuery: 未知的记录类型「{$type}」（只认 tasks / uploaded / failed）"
            );
        }
        $isTaskTable = $type === self::TYPE_TASKS;
        // 日志页与 upload_tasks 左连接取单据类型，两张表都有 djbh / ent_name / company 等同名列，
        // 列名必须限定前缀；任务表是单表查询，保持裸列名
        $column = $isTaskTable ? '' : 'upload_logs.';

        $conditions = [];
        $params = [];

        // 条件与参数**成对追加**：`?` 与 $params 的下标由此恒等。合并四处拷贝后最该保住的性质，
        // 也是原先各处手写时最容易错的地方（尤其在同一段里插进新条件的时候）。
        $add = function (string $condition, string ...$values) use (&$conditions, &$params): void {
            $conditions[] = $condition;
            foreach ($values as $value) {
                $params[] = $value;
            }
        };

        // ---------- 各页的固定口径（不是筛选条件，但决定"哪些行属于这一页"） ----------
        if ($type === self::TYPE_UPLOADED) {
            $add("{$column}response_status IN ('上传成功', '单据重复')");
        } elseif ($type === self::TYPE_FAILED) {
            // 失败分两层：请求层失败，或响应层不是"上传成功/单据重复"
            $add("({$column}request_status = '请求失败' OR {$column}response_status NOT IN ('上传成功', '单据重复'))");
            // 例外：quantity_check 的数量对账告警不受同单号判重约束——其单号必然存在 batch_check
            // 的"上传成功"记录，若参与 NOT EXISTS 会被全部隐藏，告警出口（失败记录页）失效。
            // 该来源记录在下次数量对账重跑时按新判定自动清理。判重限定**同一企业**：
            // 去重键是 (company, djbh)，裸 djbh 会让零售的失败记录被同号批发成功单顶掉，
            // 而零售失败记录只可能来自人工补传——那是操作者唯一能看见"补传没成功"的出口（ADR 0007）。
            $add("({$column}source = 'quantity_check' OR NOT EXISTS (SELECT 1 FROM upload_logs ok"
                . " WHERE ok.djbh = {$column}djbh AND ok.company = {$column}company"
                . " AND ok.response_status IN ('上传成功', '单据重复')))");
        }
        // tasks 页无固定口径：任务状态由下拉的默认值"等待上传"给出

        // ---------- 关键词（命中范围与各页表格列一致） ----------
        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            if ($isTaskTable) {
                $add("(djbh LIKE ? OR ent_name LIKE ? OR trace_codes LIKE ? OR task_status LIKE ?"
                    . " OR request_status LIKE ? OR response_status LIKE ?)",
                    $search, $search, $search, $search, $search, $search);
            } else {
                $add("({$column}djbh LIKE ? OR {$column}ent_name LIKE ? OR {$column}trace_codes LIKE ?"
                    . " OR {$column}request_status LIKE ? OR {$column}response_status LIKE ? OR {$column}response LIKE ?)",
                    $search, $search, $search, $search, $search, $search);
            }
        }

        // ---------- 等值筛选 ----------
        if ($isTaskTable && !empty($filters['task_status'])) {
            $add("task_status = ?", (string)$filters['task_status']);
        }
        if (!empty($filters['response_status'])) {
            // 失败页的"请求失败"是**请求层**状态（与响应状态同列展示、取值不同），照搬页面下拉语义
            if ($type === self::TYPE_FAILED && $filters['response_status'] === '请求失败') {
                $add("{$column}request_status = '请求失败'");
            } else {
                $add("{$column}response_status = ?", (string)$filters['response_status']);
            }
        }
        if (!empty($filters['source'])) {
            $add("{$column}source = ?", (string)$filters['source']);
        }
        // 所属企业：下拉的值就是 company 列的值（未识别也在下拉里，照常筛得出来）；
        // 门店的手工建单行 company 就是所选门店，按门店筛照样筛得到
        if (!empty($filters['company'])) {
            $add("{$column}company = ?", (string)$filters['company']);
        }

        // ---------- 日期范围 ----------
        // 三页的"默认最近 7 天"维度**不一样**（上传任务页=单据日期，已上传/失败页=任务创建时间），
        // 前端据此决定传哪一组参数名；这里只做参数名→列的映射，不猜维度。
        if ($isTaskTable) {
            self::addRange($add, $filters, 'rq', 'date_from', 'date_to');
            self::addRange($add, $filters, 'date(created_at)', 'created_from', 'created_to');
        } else {
            self::addRange($add, $filters, "date({$column}created_at)", 'date_from', 'date_to');
            self::addRange($add, $filters, "{$column}rq", 'rq_from', 'rq_to');
        }

        // ---------- 单号 / 往来单位 ----------
        if (!empty($filters['djbh'])) {
            $add("{$column}djbh LIKE ?", '%' . $filters['djbh'] . '%');
        }
        if (!empty($filters['ent_name'])) {
            $add("{$column}ent_name LIKE ?", '%' . $filters['ent_name'] . '%');
        }

        return [
            'select' => $isTaskTable
                ? 'SELECT * FROM upload_tasks'
                : 'SELECT upload_logs.*, t.bill_type AS t_bill_type FROM upload_logs'
                    . ' LEFT JOIN upload_tasks t ON t.id = upload_logs.task_id',
            'where' => empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions),
            // 计数用哪张表也由本类说了算：调用方复述表名，就可能出现"计数查 A 表、数据查 B 表"
            'count_from' => $isTaskTable ? 'upload_tasks' : 'upload_logs',
            'params' => $params,
            'order' => $isTaskTable ? 'ORDER BY id DESC' : 'ORDER BY upload_logs.id DESC',
        ];
    }

    /**
     * 一对闭区间参数（from/to）→ 一对条件。
     *
     * 参数名与列表达式都由调用方给：三页用的参数名不同（上传任务页 date_from/date_to 指单据日期；
     * 日志页同样两个参数指任务创建时间、单据日期改用 rq_from/rq_to），映射错了就是"日期筛选没生效"。
     *
     * @param \Closure(string, string...):void $add
     */
    private static function addRange(\Closure $add, array $filters, string $expression, string $fromKey, string $toKey): void
    {
        if (!empty($filters[$fromKey])) {
            $add("{$expression} >= ?", (string)$filters[$fromKey]);
        }
        if (!empty($filters[$toKey])) {
            $add("{$expression} <= ?", (string)$filters[$toKey]);
        }
    }
}
