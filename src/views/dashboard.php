<?php
/**
 * 首页仪表盘 — 四个统计卡片
 */

require_once __DIR__ . '/layout.php';

use App\Database;

$db = Database::getInstance();
$today = date('Y-m-d');

// 今日上传总数
$totalToday = $db->queryOne("SELECT COUNT(*) as cnt FROM upload_logs WHERE date(created_at) = ?", [$today])['cnt'] ?? 0;

// 今日成功数
$successToday = $db->queryOne("SELECT COUNT(*) as cnt FROM upload_logs WHERE date(created_at) = ? AND response_status IN ('上传成功', '单据重复')", [$today])['cnt'] ?? 0;

// 今日失败数（排除该单号已有上传成功/单据重复记录的，与失败记录页口径一致）
$failedToday = $db->queryOne("SELECT COUNT(*) as cnt FROM upload_logs WHERE date(created_at) = ? AND (request_status = '请求失败' OR response_status NOT IN ('上传成功', '单据重复')) AND NOT EXISTS (SELECT 1 FROM upload_logs ok WHERE ok.djbh = upload_logs.djbh AND ok.response_status IN ('上传成功', '单据重复'))", [$today])['cnt'] ?? 0;

// 等待上传数 — 从 upload_tasks 表中查
$pendingCount = $db->queryOne("SELECT COUNT(*) as cnt FROM upload_tasks WHERE task_status = '等待上传'")['cnt'] ?? 0;

layout('首页仪表盘', 'dashboard');
?>

<h4 class="mb-4">首页仪表盘</h4>

<div class="row g-3">
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm bg-primary bg-opacity-10">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-primary mb-1 small fw-semibold">今日上传总数</p>
                        <h2 class="mb-0" id="stat-total"><?= $totalToday ?></h2>
                    </div>
                    <div class="rounded-circle bg-primary bg-opacity-25 p-3">
                        <svg width="24" height="24" fill="#0d6efd" viewBox="0 0 16 16"><path d="M8 4a.5.5 0 0 1 .5.5v.634l.549-.317a.5.5 0 1 1 .5.866L9 6l.549.317a.5.5 0 1 1-.5.866L8.5 6.866V7.5a.5.5 0 0 1-1 0v-.634l-.549.317a.5.5 0 1 1-.5-.866L7 6l-.549-.317a.5.5 0 1 1 .5-.866l.549.317V4.5A.5.5 0 0 1 8 4zM5 9.5a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5zm0 2a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5z"/></svg>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm bg-success bg-opacity-10">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-success mb-1 small fw-semibold">今日成功</p>
                        <h2 class="mb-0" id="stat-success"><?= $successToday ?></h2>
                    </div>
                    <div class="rounded-circle bg-success bg-opacity-25 p-3">
                        <svg width="24" height="24" fill="#198754" viewBox="0 0 16 16"><path d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"/></svg>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm bg-danger bg-opacity-10">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-danger mb-1 small fw-semibold">今日失败</p>
                        <h2 class="mb-0" id="stat-failed"><?= $failedToday ?></h2>
                    </div>
                    <div class="rounded-circle bg-danger bg-opacity-25 p-3">
                        <svg width="24" height="24" fill="#dc3545" viewBox="0 0 16 16"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/><path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/></svg>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm bg-warning bg-opacity-10">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-warning mb-1 small fw-semibold">等待上传</p>
                        <h2 class="mb-0" id="stat-pending"><?= $pendingCount ?></h2>
                    </div>
                    <div class="rounded-circle bg-warning bg-opacity-25 p-3">
                        <svg width="24" height="24" fill="#fd7e14" viewBox="0 0 16 16"><path d="M8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71V3.5z"/><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm7-8A7 7 0 1 1 1 8a7 7 0 0 1 14 0z"/></svg>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-2">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent fw-semibold">今日上传概览</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0" id="recent-table">
                        <thead>
                            <tr><th>时间</th><th>单号</th><th>状态</th><th>返回信息</th></tr>
                        </thead>
                        <tbody>
                            <?php
                            $logs = $db->query("SELECT * FROM upload_logs WHERE date(created_at) = ? ORDER BY id DESC LIMIT 10", [$today]);
                            foreach ($logs as $log):
                                $isSuccess = in_array(($log['response_status'] ?? ''), ['上传成功', '单据重复']);
                                $badge = $isSuccess ? 'bg-success' : 'bg-danger';
                                $label = $isSuccess ? '成功' : '失败';
                            ?>
                            <tr>
                                <td class="text-nowrap"><?= htmlspecialchars($log['created_at']) ?></td>
                                <td><code><?= htmlspecialchars($log['djbh']) ?></code></td>
                                <td><span class="badge <?= $badge ?>"><?= $label ?></span></td>
                                <td class="text-truncate" style="max-width:300px"><?= htmlspecialchars(mb_substr($log['response'] ?? '', 0, 100)) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($logs)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">暂无今日上传记录</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php layoutEnd(); ?>
