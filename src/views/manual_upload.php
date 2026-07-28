<?php
require_once __DIR__ . '/layout.php';
layout('手动上传', 'manual-upload');
?>

<h4 class="mb-4">手动上传</h4>

<div class="row g-4">
    <!-- 在线新增 -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent fw-semibold">在线新增</div>
            <div class="card-body">
                <form id="manual-form">
                    <div class="mb-3">
                        <label class="form-label">日期 <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="m-rq" required value="<?= date('Y-m-d') ?>">
                        <div class="invalid-feedback">请输入有效日期</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">单号 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="m-djbh" required placeholder="如: JHGWMS00060001">
                        <div class="invalid-feedback">单号不能为空</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">往来单位名称 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="m-ent-name" required placeholder="企业名称">
                        <div class="invalid-feedback">往来单位不能为空</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">追溯码 <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="m-trace-codes" rows="4" required
                            placeholder="追溯码1,追溯码2,追溯码3...（逗号分隔）"></textarea>
                        <div class="invalid-feedback">追溯码不能为空</div>
                        <div class="form-text">多个追溯码用英文逗号分隔，单次最多 3500 个</div>
                    </div>
                    <button type="submit" class="btn btn-primary" id="btn-submit">
                        <span class="spinner-border spinner-border-sm d-none" id="submit-spinner"></span>
                        提交并上传
                    </button>
                </form>
                <div id="form-result" class="mt-3 d-none"></div>
            </div>
        </div>
    </div>

    <!-- xlsx 导入 -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
                <span>xlsx 批量导入</span>
                <a href="index.php?page=api&action=template_download" class="btn btn-sm btn-outline-secondary">下载模板</a>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">选择 xlsx 文件</label>
                    <input type="file" class="form-control" id="import-file" accept=".xlsx">
                    <div class="form-text">文件格式: 日期 | 单号 | 往来单位名称 | 追溯码</div>
                </div>
                <button class="btn btn-success" id="btn-import">
                    <span class="spinner-border spinner-border-sm d-none" id="import-spinner"></span>
                    上传并导入
                </button>
                <div id="import-progress" class="mt-3 d-none">
                    <div class="progress" style="height:20px">
                        <div class="progress-bar" id="import-bar" style="width:0%">0%</div>
                    </div>
                </div>
                <div id="import-result" class="mt-3 d-none"></div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    // 在线新增
    document.getElementById('manual-form').addEventListener('submit', async function(e) {
        e.preventDefault();
        const form = this;

        const rq = document.getElementById('m-rq').value.trim();
        const djbh = document.getElementById('m-djbh').value.trim();
        const entName = document.getElementById('m-ent-name').value.trim();
        const traceCodes = document.getElementById('m-trace-codes').value.trim();

        if (!rq || !djbh || !entName || !traceCodes) {
            showResult('form-result', 'danger', '请填写所有必填字段');
            return;
        }

        const btn = document.getElementById('btn-submit');
        const spinner = document.getElementById('submit-spinner');
        btn.disabled = true;
        spinner.classList.remove('d-none');

        try {
            const resp = await fetch('index.php?page=api&action=manual_create', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({rq, djbh, ent_name: entName, trace_codes: traceCodes}),
            });
            const data = await resp.json();
            if (data.success) {
                showResult('form-result', 'success',
                    '上传完成! 任务ID: ' + data.task_id +
                    ', 成功: ' + data.result.success + ', 失败: ' + data.result.failed);
                form.reset();
                document.getElementById('m-rq').value = '<?= date('Y-m-d') ?>';
            } else {
                showResult('form-result', 'danger', data.error || '上传失败');
            }
        } catch (err) {
            showResult('form-result', 'danger', '请求失败: ' + err.message);
        } finally {
            btn.disabled = false;
            spinner.classList.add('d-none');
        }
    });

    // xlsx 导入
    document.getElementById('btn-import').addEventListener('click', async function() {
        const fileInput = document.getElementById('import-file');
        const file = fileInput.files[0];
        if (!file) {
            showResult('import-result', 'danger', '请先选择 xlsx 文件');
            return;
        }

        const btn = this;
        const spinner = document.getElementById('import-spinner');
        const progress = document.getElementById('import-progress');
        const bar = document.getElementById('import-bar');

        btn.disabled = true;
        spinner.classList.remove('d-none');
        showResult('import-result', '', '');
        progress.classList.remove('d-none');
        bar.style.width = '0%';
        bar.textContent = '上传中...';

        const formData = new FormData();
        formData.append('file', file);

        try {
            bar.style.width = '40%';
            bar.textContent = '处理中...';

            const resp = await fetch('index.php?page=api&action=manual_import', {
                method: 'POST',
                body: formData,
            });
            bar.style.width = '90%';

            const data = await resp.json();
            bar.style.width = '100%';
            bar.textContent = '完成';

            if (data.success) {
                let msg = '导入完成: 成功 ' + data.success_count + ' 条, 失败 ' + data.error_count + ' 条';
                if (data.errors && data.errors.length) {
                    msg += '\n错误详情:\n' + data.errors.join('\n');
                }
                showResult('import-result', data.error_count > 0 ? 'warning' : 'success', msg.replace(/\n/g, '<br>'));
            } else {
                showResult('import-result', 'danger', data.error || '导入失败');
            }
        } catch (err) {
            showResult('import-result', 'danger', '请求失败: ' + err.message);
        } finally {
            btn.disabled = false;
            spinner.classList.add('d-none');
            setTimeout(() => progress.classList.add('d-none'), 2000);
            fileInput.value = '';
        }
    });

    function showResult(id, type, msg) {
        const el = document.getElementById(id);
        if (!type) { el.classList.add('d-none'); return; }
        el.className = 'mt-3 alert alert-' + type;
        el.innerHTML = msg;
        el.classList.remove('d-none');
    }
})();
</script>

<?php layoutEnd(); ?>
