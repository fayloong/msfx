<?php
/**
 * 登录页面
 */

use App\Auth;

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if (Auth::login($password)) {
        $redirect = $_GET['redirect'] ?? 'dashboard';
        header('Location: index.php?page=' . urlencode($redirect));
        exit;
    }
    $error = '密码错误，请重试';
}

$redirect = $_GET['redirect'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>码上放心 — 登录</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .login-card { max-width: 420px; width: 100%; border-radius: 12px; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center">
    <div class="login-card card shadow-lg p-4">
        <div class="text-center mb-4">
            <h3 class="fw-bold text-primary">码上放心</h3>
            <p class="text-muted">药品追溯码上传系统</p>
        </div>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post" action="index.php?page=login&redirect=<?= htmlspecialchars($redirect) ?>">
            <div class="mb-3">
                <label for="password" class="form-label">管理员密码</label>
                <input type="password" class="form-control form-control-lg" id="password" name="password"
                       placeholder="请输入管理员密码" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-100">登录</button>
        </form>
    </div>
</body>
</html>
