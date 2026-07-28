<?php
/**
 * 药品追溯码上传系统 — Web 入口
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Auth;

Config::load();
Auth::init();

$page = $_GET['page'] ?? 'dashboard';

// 公开页面：登录
if ($page === 'login') {
    require __DIR__ . '/../src/views/login.php';
    exit;
}

// API 端点不需要登录验证（内部处理）
if ($page === 'api') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'] ?? '';
    $apiFile = __DIR__ . '/../src/api/' . $action . '.php';
    if (file_exists($apiFile)) {
        require $apiFile;
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'API not found'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 静态资源
if ($page === 'asset') {
    return false; // 让 Nginx 处理
}

// 需要登录的页面
Auth::require();

// 路由分发
$viewMap = [
    'dashboard' => 'dashboard.php',
    'upload-tasks' => 'upload_tasks.php',
    'uploaded' => 'uploaded.php',
    'failed' => 'failed.php',
    'manual-upload' => 'manual_upload.php',
];

$viewFile = $viewMap[$page] ?? 'dashboard.php';
$viewPath = __DIR__ . '/../src/views/' . $viewFile;

if (file_exists($viewPath)) {
    require $viewPath;
} else {
    http_response_code(404);
    echo '<h1>404 — 页面不存在</h1>';
}
