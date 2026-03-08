<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/ping.php';
require_once __DIR__ . '/../includes/cache.php';

require_role('admin');

if (is_post()) {
    if (!csrf_validate($_POST['_csrf_token'] ?? null)) {
        flash_set('danger', 'Invalid CSRF token.');
        redirect('/admin/ping-add.php');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $target = ping_normalize_target((string) ($_POST['target'] ?? ''));
    $targetType = ping_normalize_target_type((string) ($_POST['target_type'] ?? 'domain'));
    $checkMethod = ping_normalize_check_method((string) ($_POST['check_method'] ?? 'icmp'));
    if ($checkMethod === 'http') {
        $targetType = 'url';
    } elseif ($targetType === 'url') {
        $targetType = 'domain';
    }
    $intervalSeconds = max(30, min(3600, (int) ($_POST['check_interval_seconds'] ?? 60)));
    $timeoutSeconds = max(1, min(10, (int) ($_POST['timeout_seconds'] ?? 2)));
    $failureThreshold = max(1, min(10, (int) ($_POST['failure_threshold'] ?? 2)));
    $active = isset($_POST['active']) ? 1 : 0;

    if ($name === '') {
        flash_set('danger', 'Monitor name is required.');
        redirect('/admin/ping-add.php');
    }
    if (!ping_validate_target($target, $targetType, $checkMethod)) {
        flash_set('danger', 'Invalid ping target for selected type.');
        redirect('/admin/ping-add.php');
    }

    db_exec(
        'INSERT INTO ping_monitors (
            name, target, target_type, check_method, check_interval_seconds, timeout_seconds, failure_threshold, active, created_at, updated_at
        ) VALUES (
            :name, :target, :target_type, :check_method, :check_interval_seconds, :timeout_seconds, :failure_threshold, :active, NOW(), NOW()
        )',
        [
            ':name' => $name,
            ':target' => $target,
            ':target_type' => $targetType,
            ':check_method' => $checkMethod,
            ':check_interval_seconds' => $intervalSeconds,
            ':timeout_seconds' => $timeoutSeconds,
            ':failure_threshold' => $failureThreshold,
            ':active' => $active,
        ]
    );

    $id = (int) db()->lastInsertId();
    invalidate_ping_cache();
    audit_log('ping_monitor_add', 'Created ping monitor', 'ping_monitor', $id, [
        'name' => $name,
        'target' => $target,
        'target_type' => $targetType,
        'check_method' => $checkMethod,
        'check_interval_seconds' => $intervalSeconds,
    ]);
    flash_set('success', 'Ping monitor created successfully.');
    redirect('/admin/ping-detail.php?id=' . $id);
}

$title = APP_NAME . ' - Add Ping Monitor';
$activeNav = 'ping';
require_once __DIR__ . '/../includes/layout/head.php';
require_once __DIR__ . '/../includes/layout/nav.php';
require_once __DIR__ . '/../includes/layout/flash.php';
?>
<main class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Add Ping Monitor</h1>
            <p class="page-subtitle">Create target checks for domain or IP with custom interval.</p>
        </div>
        <div class="toolbar-actions">
            <a class="btn btn-soft" href="<?= e(app_url('admin/ping-monitors.php')) ?>">Back to Ping Monitor</a>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft">
            <h2 class="h6 mb-0">Monitor Configuration</h2>
        </div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <?= csrf_input() ?>
                <div class="col-md-6">
                    <label class="form-label">Name</label>
                    <input class="form-control" name="name" value="<?= e(old('name')) ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Target Type</label>
                    <select class="form-select" name="target_type">
                        <option value="domain" <?= old('target_type', 'domain') === 'domain' ? 'selected' : '' ?>>Domain</option>
                        <option value="ip" <?= old('target_type') === 'ip' ? 'selected' : '' ?>>IP</option>
                        <option value="url" <?= old('target_type') === 'url' ? 'selected' : '' ?>>URL</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Check Method</label>
                    <select class="form-select" name="check_method">
                        <option value="icmp" <?= old('check_method', 'icmp') === 'icmp' ? 'selected' : '' ?>>ICMP Ping</option>
                        <option value="http" <?= old('check_method') === 'http' ? 'selected' : '' ?>>HTTP Check</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label d-block">Active</label>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="active" id="active" <?= old('active', '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="active">Enable checks immediately</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Target</label>
                    <input class="form-control" name="target" placeholder="ICMP: example.com / 203.0.113.10 | HTTP: https://example.com/health" value="<?= e(old('target')) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Interval (seconds)</label>
                    <input class="form-control" type="number" min="30" max="3600" name="check_interval_seconds" value="<?= e(old('check_interval_seconds', '60')) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Timeout (seconds)</label>
                    <input class="form-control" type="number" min="1" max="10" name="timeout_seconds" value="<?= e(old('timeout_seconds', '2')) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Failure Threshold</label>
                    <input class="form-control" type="number" min="1" max="10" name="failure_threshold" value="<?= e(old('failure_threshold', '2')) ?>" required>
                </div>
                <div class="col-12 settings-actions d-flex gap-2 flex-wrap">
                    <button class="btn btn-info" type="submit" data-submit-loading data-loading-text="Saving...">Save</button>
                    <a class="btn btn-outline-light" href="<?= e(app_url('admin/ping-monitors.php')) ?>">Cancel</a>
                </div>
            </form>
        </div>
    </section>
</main>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
