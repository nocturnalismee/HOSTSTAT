<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/ping.php';
require_once __DIR__ . '/../includes/cache.php';

require_role('admin');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$monitor = db_one('SELECT * FROM ping_monitors WHERE id = :id LIMIT 1', [':id' => $id]);
if ($monitor === null) {
    flash_set('danger', 'Ping monitor not found.');
    redirect('/admin/ping-monitors.php');
}

if (is_post()) {
    if (!csrf_validate($_POST['_csrf_token'] ?? null)) {
        flash_set('danger', 'Invalid CSRF token.');
        redirect('/admin/ping-edit.php?id=' . $id);
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
        redirect('/admin/ping-edit.php?id=' . $id);
    }
    if (!ping_validate_target($target, $targetType, $checkMethod)) {
        flash_set('danger', 'Invalid ping target for selected type.');
        redirect('/admin/ping-edit.php?id=' . $id);
    }

    db_exec(
        'UPDATE ping_monitors
         SET name = :name,
             target = :target,
             target_type = :target_type,
             check_method = :check_method,
             check_interval_seconds = :check_interval_seconds,
             timeout_seconds = :timeout_seconds,
             failure_threshold = :failure_threshold,
             active = :active,
             updated_at = NOW()
         WHERE id = :id',
        [
            ':name' => $name,
            ':target' => $target,
            ':target_type' => $targetType,
            ':check_method' => $checkMethod,
            ':check_interval_seconds' => $intervalSeconds,
            ':timeout_seconds' => $timeoutSeconds,
            ':failure_threshold' => $failureThreshold,
            ':active' => $active,
            ':id' => $id,
        ]
    );

    invalidate_ping_cache($id);
    audit_log('ping_monitor_update', 'Updated ping monitor', 'ping_monitor', $id, [
        'name' => $name,
        'target' => $target,
        'target_type' => $targetType,
        'check_method' => $checkMethod,
        'check_interval_seconds' => $intervalSeconds,
    ]);
    flash_set('success', 'Ping monitor updated successfully.');
    redirect('/admin/ping-detail.php?id=' . $id);
}

$title = APP_NAME . ' - Edit Ping Monitor';
$activeNav = 'ping';
require_once __DIR__ . '/../includes/layout/head.php';
require_once __DIR__ . '/../includes/layout/nav.php';
require_once __DIR__ . '/../includes/layout/flash.php';
?>
<main class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Edit Ping Monitor</h1>
            <p class="page-subtitle">Update ping target, interval, timeout, and status behavior.</p>
        </div>
        <div class="toolbar-actions">
            <a class="btn btn-soft" href="<?= e(app_url('admin/ping-detail.php?id=' . $id)) ?>">Back to Detail</a>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft">
            <h2 class="h6 mb-0">Monitor Configuration</h2>
        </div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <?= csrf_input() ?>
                <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                <div class="col-md-6">
                    <label class="form-label">Name</label>
                    <input class="form-control" name="name" value="<?= e((string) $monitor['name']) ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Check Method</label>
                    <select class="form-select" name="check_method">
                        <option value="icmp" <?= (string) ($monitor['check_method'] ?? 'icmp') === 'icmp' ? 'selected' : '' ?>>ICMP Ping</option>
                        <option value="http" <?= (string) ($monitor['check_method'] ?? 'icmp') === 'http' ? 'selected' : '' ?>>HTTP Check</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Target Type</label>
                    <select class="form-select" name="target_type">
                        <option value="domain" <?= (string) $monitor['target_type'] === 'domain' ? 'selected' : '' ?>>Domain</option>
                        <option value="ip" <?= (string) $monitor['target_type'] === 'ip' ? 'selected' : '' ?>>IP</option>
                        <option value="url" <?= (string) $monitor['target_type'] === 'url' ? 'selected' : '' ?>>URL</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label d-block">Active</label>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="active" id="active" <?= (int) ($monitor['active'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="active">Enable checks</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Target</label>
                    <input class="form-control" name="target" value="<?= e((string) $monitor['target']) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Interval (seconds)</label>
                    <input class="form-control" type="number" min="30" max="3600" name="check_interval_seconds" value="<?= e((string) ((int) ($monitor['check_interval_seconds'] ?? 60))) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Timeout (seconds)</label>
                    <input class="form-control" type="number" min="1" max="10" name="timeout_seconds" value="<?= e((string) ((int) ($monitor['timeout_seconds'] ?? 2))) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Failure Threshold</label>
                    <input class="form-control" type="number" min="1" max="10" name="failure_threshold" value="<?= e((string) ((int) ($monitor['failure_threshold'] ?? 2))) ?>" required>
                </div>
                <div class="col-12 settings-actions d-flex gap-2 flex-wrap">
                    <button class="btn btn-info" type="submit" data-submit-loading data-loading-text="Saving...">Save Changes</button>
                    <a class="btn btn-outline-light" href="<?= e(app_url('admin/ping-detail.php?id=' . $id)) ?>">Cancel</a>
                </div>
            </form>
        </div>
    </section>
</main>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
