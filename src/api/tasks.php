<?php
/**
 * API: /api/tasks — 上传任务 CRUD
 * GET  — 列表（分页、搜索、筛选）
 * PUT  — 编辑单条
 * DELETE — 删除单条
 */

use App\Auth;
use App\BillType;
use App\Database;
use App\Enterprise;
use App\RecordQuery;

Auth::init();
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

// 支持 _method 覆盖（某些环境限制）
if ($method === 'POST' && !empty($_POST['_method'])) {
    $method = strtoupper($_POST['_method']);
}

if ($method === 'GET') {
    // 按 ID 获取单条记录
    if (!empty($_GET['id'])) {
        $task = $db->queryOne("SELECT * FROM upload_tasks WHERE id = ?", [$_GET['id']]);
        if (!$task) {
            http_response_code(404);
            echo json_encode(['error' => '任务不存在'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $task['bill_type'] = BillType::normalize($task['bill_type'] ?? '', $task['djbh'] ?? '');
        echo json_encode($task, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 列表查询
    $page = max(1, intval($_GET['page_num'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    // 筛选条件构造在 App\RecordQuery——本页、另两页与导出共用同一份实现，
    // 免得"页面筛得出来、导出筛不出来"（该漂移实测发生过一次，见该类注释）
    $query = RecordQuery::build(RecordQuery::TYPE_TASKS, $_GET);

    // 总数
    $countRow = $db->queryOne("SELECT COUNT(*) as cnt FROM upload_tasks {$query['where']}", $query['params']);
    $total = $countRow['cnt'] ?? 0;

    // 数据
    $rows = $db->query(
        "{$query['select']} {$query['where']} {$query['order']} LIMIT ? OFFSET ?",
        array_merge($query['params'], [$perPage, $offset])
    );

    foreach ($rows as &$row) {
        $row['bill_type'] = BillType::normalize($row['bill_type'] ?? '', $row['djbh'] ?? '');
    }
    unset($row);

    echo json_encode([
        'data' => $rows,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => ceil($total / $perPage),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'PUT') {
    // 解析 PUT 请求体
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => '缺少参数'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sets = ['rq = ?', 'djbh = ?', 'ent_name = ?', 'trace_codes = ?', 'bill_type = ?'];
    $values = [
        $input['rq'] ?? '',
        $input['djbh'] ?? '',
        $input['ent_name'] ?? '',
        $input['trace_codes'] ?? '',
        $input['bill_type'] ?? '',
    ];

    // 改"所属企业"必须连带重设凭据：重传用哪套授权由 (company, credential) 两列共同决定
    // （UploadService 的守卫按这两列取凭据，取不到即拒传）。未识别行的 credential 是 NULL，
    // 只改 company 不改 credential 的话，本功能最主要的使用场景（把未识别行指派给正确门店）
    // 等于没做——守卫会以"取不到可用凭据"拒传。
    // 企业不在配置中时 defaultCredentialKey 返回 null，届时守卫照样明确拒传，不会静默传错主体。
    // 未带 company 键则两列都不动（旧调用方与未刷新的页面不会被误清空）。
    if (array_key_exists('company', $input)) {
        $company = trim((string)$input['company']);
        if ($company === '') {
            http_response_code(400);
            echo json_encode(['error' => '所属企业不能为空'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // 该调用要载入企业配置，配置坏了会抛——这里是用户点"保存"的同步路径，抛成 PHP 致命错误
        // 只会给一个 500 空响应，不如回一句能看懂的话（此时整站的企业相关内容本就已不可用）。
        // 注意取值在 $db->execute() **之前**，故这一路失败不会留下半截写入。
        try {
            $credentialKey = Enterprise::defaultCredentialKey($company);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => '企业配置载入失败，无法确定该企业的凭据：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $sets[] = 'company = ?';
        $values[] = $company;
        $sets[] = 'credential = ?';
        $values[] = $credentialKey;
    }

    $sets[] = "updated_at = datetime('now','localtime')";
    $values[] = $input['id'];
    $db->execute('UPDATE upload_tasks SET ' . implode(', ', $sets) . ' WHERE id = ?', $values);

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => '缺少 id 参数'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db->execute("DELETE FROM upload_tasks WHERE id = ?", [$id]);
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
