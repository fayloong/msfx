<?php
/**
 * 全局布局组件 — 左侧可折叠菜单 + 右侧内容区
 *
 * 用法: layout($pageTitle, $activeMenu) { ... 页面内容 ... }
 */
function layout(string $title, string $activeMenu = 'dashboard'): void {
    $menuItems = [
        'dashboard' => ['icon' => 'house', 'label' => '首页', 'href' => 'index.php?page=dashboard'],
        'upload' => [
            'icon' => 'cloud-upload', 'label' => '单据上传',
            'children' => [
                'upload-tasks' => ['label' => '上传任务', 'href' => 'index.php?page=upload-tasks'],
                'uploaded' => ['label' => '已上传', 'href' => 'index.php?page=uploaded'],
                'failed' => ['label' => '失败记录', 'href' => 'index.php?page=failed'],
            ]
        ],
        'manual-upload' => ['icon' => 'plus-circle', 'label' => '手动上传', 'href' => 'index.php?page=manual-upload'],
    ];

    $svgIcons = [
        'house' => '<svg width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M8.707 1.5a1 1 0 0 0-1.414 0L.646 8.146a.5.5 0 0 0 .708.708L2 8.207V13.5A1.5 1.5 0 0 0 3.5 15h9a1.5 1.5 0 0 0 1.5-1.5V8.207l.646.647a.5.5 0 0 0 .708-.708L13 5.793V2.5a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0-.5.5v1.293L8.707 1.5Z"/></svg>',
        'cloud-upload' => '<svg width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M4.406 1.342A5.53 5.53 0 0 1 8 0c2.69 0 4.923 2 5.166 4.579C14.758 4.804 16 6.137 16 7.773 16 9.569 14.502 11 12.687 11H10a.5.5 0 0 1 0-1h2.688C13.979 10 15 8.988 15 7.773c0-1.216-1.02-2.228-2.313-2.228h-.5v-.5C12.188 2.825 10.328 1 8 1a4.53 4.53 0 0 0-2.941 1.1c-.757.652-1.153 1.438-1.153 2.055v.448l-.445.049C2.064 4.805 1 5.952 1 7.318 1 8.785 2.23 10 3.781 10H6a.5.5 0 0 1 0 1H3.781C1.708 11 0 9.366 0 7.318c0-1.763 1.266-3.223 2.942-3.593.143-.863.698-1.723 1.464-2.383Z"/></svg>',
        'plus-circle' => '<svg width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/><path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z"/></svg>',
    ];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> — 码上放心</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.9/dist/flatpickr.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.9/dist/flatpickr.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.9/dist/l10n/zh.js"></script>
    <style>
        :root {
            --sidebar-width: 240px;
            --sidebar-collapsed-width: 56px;
            --transition-speed: 0.25s;
        }
        body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .sidebar {
            position: fixed; top: 0; left: 0; bottom: 0; z-index: 1000;
            width: var(--sidebar-width); background: #1e293b; color: #cbd5e1;
            transition: width var(--transition-speed) ease;
            overflow-x: hidden; overflow-y: auto; white-space: nowrap;
        }
        .sidebar.collapsed { width: var(--sidebar-collapsed-width); }
        .sidebar .brand {
            display: flex; align-items: center; height: 56px; padding: 0 16px;
            font-weight: 700; font-size: 1.1rem; color: #fff; border-bottom: 1px solid #334155;
        }
        .sidebar .brand-text { transition: opacity var(--transition-speed); }
        .sidebar.collapsed .brand-text { opacity: 0; width: 0; overflow: hidden; }
        .sidebar .nav-link {
            display: flex; align-items: center; padding: 10px 16px; color: #94a3b8;
            text-decoration: none; border-radius: 0; transition: background 0.15s;
            gap: 12px; cursor: pointer;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: #334155; }
        .sidebar .nav-link svg { flex-shrink: 0; }
        .sidebar .nav-label { transition: opacity var(--transition-speed); }
        .sidebar.collapsed .nav-label { opacity: 0; width: 0; overflow: hidden; }
        .sidebar .submenu { padding-left: 16px; }
        .sidebar .submenu .nav-link { padding-left: 32px; font-size: 0.9rem; }
        .sidebar.collapsed .submenu { display: none; }
        .main-content {
            margin-left: var(--sidebar-width); padding: 24px;
            transition: margin-left var(--transition-speed) ease;
        }
        .sidebar.collapsed ~ .main-content { margin-left: var(--sidebar-collapsed-width); }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        @media (max-width: 768px) {
            .sidebar { width: var(--sidebar-collapsed-width); }
            .sidebar .brand-text, .sidebar .nav-label { opacity: 0; width: 0; overflow: hidden; }
            .sidebar .submenu { display: none; }
            .main-content { margin-left: var(--sidebar-collapsed-width); }
        }
    </style>
</head>
<body>
<aside class="sidebar" id="sidebar">
    <div class="brand">
        <svg width="24" height="24" fill="#60a5fa" viewBox="0 0 16 16" style="flex-shrink:0"><path d="M2.5 3.5a.5.5 0 0 1 0-1h11a.5.5 0 0 1 0 1h-11zm0 3a.5.5 0 0 1 0-1h7a.5.5 0 0 1 0 1h-7zm0 3a.5.5 0 0 1 0-1h9a.5.5 0 0 1 0 1h-9zm0 3a.5.5 0 0 1 0-1h5a.5.5 0 0 1 0 1h-5z"/></svg>
        <span class="brand-text ms-2">码上放心</span>
    </div>
    <nav class="mt-2">
        <?php foreach ($menuItems as $key => $item): ?>
            <?php if (isset($item['children'])): ?>
                <!-- 子菜单 -->
                <a class="nav-link <?= in_array($activeMenu, array_keys($item['children'])) ? 'active' : '' ?>"
                   data-bs-toggle="collapse" href="#submenu-<?= $key ?>" role="button">
                    <?= $svgIcons[$item['icon']] ?? '' ?>
                    <span class="nav-label"><?= htmlspecialchars($item['label']) ?></span>
                </a>
                <div class="collapse submenu <?= in_array($activeMenu, array_keys($item['children'])) ? 'show' : '' ?>"
                     id="submenu-<?= $key ?>">
                    <?php foreach ($item['children'] as $childKey => $child): ?>
                        <a class="nav-link <?= $activeMenu === $childKey ? 'active' : '' ?>"
                           href="<?= $child['href'] ?>">
                            <span class="nav-label"><?= htmlspecialchars($child['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <a class="nav-link <?= $activeMenu === $key ? 'active' : '' ?>" href="<?= $item['href'] ?>">
                    <?= $svgIcons[$item['icon']] ?? '' ?>
                    <span class="nav-label"><?= htmlspecialchars($item['label']) ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
</aside>

<div class="main-content">
    <div class="top-bar">
        <button class="btn btn-sm btn-outline-secondary" id="toggle-sidebar" title="收起/展开菜单">
            <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M2.5 12a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5zm0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5zm0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5z"/></svg>
        </button>
        <div class="d-flex align-items-center gap-3">
            <span class="text-muted small"><?= date('Y-m-d H:i') ?></span>
            <a href="index.php?page=login&action=logout" class="btn btn-sm btn-outline-danger">退出</a>
        </div>
    </div>
<?php
}

function layoutEnd(): void {
?>
</div><!-- .main-content -->
<div class="modal fade" id="globalModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content" id="globalModalContent"></div></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('toggle-sidebar').addEventListener('click', () => {
    document.getElementById('sidebar').classList.toggle('collapsed');
    localStorage.setItem('sidebar-collapsed', document.getElementById('sidebar').classList.contains('collapsed'));
});
if (localStorage.getItem('sidebar-collapsed') === 'true') {
    document.getElementById('sidebar').classList.add('collapsed');
}
</script>
</body>
</html>
<?php
}

// 处理退出
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    \App\Auth::logout();
    header('Location: index.php?page=login');
    exit;
}
