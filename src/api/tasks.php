<?php
/**
 * API: /api/tasks — 上传任务 CRUD
 * GET  — 列表（分页、搜索、筛选）
 * PUT  — 编辑单条
 * DELETE — 删除单条
 */

use App\Auth;
use App\Database;

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
        echo json_encode($task, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 列表查询
    $page = max(1, intval($_GET['page_num'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $where = [];
    $params = [];

    // 搜索
    if (!empty($_GET['search'])) {
        $search = '%' . $_GET['search'] . '%';
        $where[] = "(djbh LIKE ? OR ent_name LIKE ? OR trace_codes LIKE ? OR task_status LIKE ? OR request_status LIKE ? OR response_status LIKE ?)";
        $params = array_merge($params, [$search, $search, $search, $search, $search, $search]);
    }

    // 任务状态筛选
    if (!empty($_GET['task_status'])) {
        $where[] = "task_status = ?";
        $params[] = $_GET['task_status'];
    }
    // 响应状态筛选
    if (!empty($_GET['response_status'])) {
        $where[] = "response_status = ?";
        $params[] = $_GET['response_status'];
    }

    // 日期范围
    if (!empty($_GET['date_from'])) {
        $where[] = "rq >= ?";
        $params[] = $_GET['date_from'];
    }
    if (!empty($_GET['date_to'])) {
        $where[] = "rq <= ?";
        $params[] = $_GET['date_to'];
    }

    // 单号筛选
    if (!empty($_GET['djbh'])) {
        $where[] = "djbh LIKE ?";
        $params[] = '%' . $_GET['djbh'] . '%';
    }
    // 往来单位筛选
    if (!empty($_GET['ent_name'])) {
        $where[] = "ent_name LIKE ?";
        $params[] = '%' . $_GET['ent_name'] . '%';
    }

    $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

    // 总数
    $countRow = $db->queryOne("SELECT COUNT(*) as cnt FROM upload_tasks {$whereClause}", $params);
    $total = $countRow['cnt'] ?? 0;

    // 数据
    $rows = $db->query(
        "SELECT * FROM upload_tasks {$whereClause} ORDER BY id DESC LIMIT ? OFFSET ?",
        array_merge($params, [$perPage, $offset])
    );

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

    $db->execute(
        "UPDATE upload_tasks SET rq = ?, djbh = ?, ent_name = ?, trace_codes = ?, updated_at = datetime('now','localtime') WHERE id = ?",
        [
            $input['rq'] ?? '',
            $input['djbh'] ?? '',
            $input['ent_name'] ?? '',
            $input['trace_codes'] ?? '',
            $input['id'],
        ]
    );

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
