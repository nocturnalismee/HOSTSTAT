<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/cache.php';
require_once __DIR__ . '/../includes/audit.php';
require_role('admin');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$server = db_one('SELECT * FROM servers WHERE id = :id LIMIT 1', [':id' => $id]);
if ($server === null) {
    flash_set('danger', 'Server not found.');
    redirect('/admin/servers.php');
}

if (is_post()) {
    if (!csrf_validate($_POST['_csrf_token'] ?? null)) {
        flash_set('danger', 'Invalid CSRF token.');
        redirect('/admin/server-edit.php?id=' . $id);
    }

    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'regen_token') {
        db_exec('UPDATE servers SET token = :token WHERE id = :id', [':token' => bin2hex(random_bytes(32)), ':id' => $id]);
        invalidate_status_cache($id);
        audit_log('server_regen_token', 'Regenerated server token', 'server', $id);
        flash_set('success', 'Token regenerated successfully.');
        redirect('/admin/server-edit.php?id=' . $id);
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $location = trim((string) ($_POST['location'] ?? ''));
    $host = trim((string) ($_POST['host'] ?? ''));
    $type = trim((string) ($_POST['type'] ?? ''));
    $agentMode = (string) ($_POST['agent_mode'] ?? 'push');
    $url = trim((string) ($_POST['url'] ?? ''));
    $notifyEmail = trim((string) ($_POST['notify_email'] ?? ''));
    $pushAllowedIps = trim((string) ($_POST['push_allowed_ips'] ?? ''));
    $maintenanceMode = isset($_POST['maintenance_mode']) ? 1 : 0;
    $maintenanceUntilInput = trim((string) ($_POST['maintenance_until'] ?? ''));
    $maintenanceUntil = null;
    if ($maintenanceMode === 1 && $maintenanceUntilInput !== '') {
        $ts = strtotime($maintenanceUntilInput);
        if ($ts !== false) {
            $maintenanceUntil = date('Y-m-d H:i:s', $ts);
        }
    }

    if ($name === '') {
        flash_set('danger', 'Server name is required.');
        redirect('/admin/server-edit.php?id=' . $id);
    }

    db_exec(
        'UPDATE servers
         SET name = :name, location = :location, host = :host, type = :type, agent_mode = :agent_mode, url = :url, notify_email = :notify_email,
             maintenance_mode = :maintenance_mode, maintenance_until = :maintenance_until, push_allowed_ips = :push_allowed_ips
         WHERE id = :id',
        [
            ':name' => $name,
            ':location' => $location !== '' ? $location : null,
            ':host' => $host !== '' ? $host : null,
            ':type' => $type !== '' ? $type : null,
            ':agent_mode' => $agentMode,
            ':url' => $url !== '' ? $url : null,
            ':notify_email' => $notifyEmail !== '' ? $notifyEmail : null,
            ':maintenance_mode' => $maintenanceMode,
            ':maintenance_until' => $maintenanceMode === 1 ? $maintenanceUntil : null,
            ':push_allowed_ips' => $pushAllowedIps !== '' ? $pushAllowedIps : null,
            ':id' => $id,
        ]
    );
    invalidate_status_cache($id);
    audit_log(
        'server_update',
        'Updated server settings',
        'server',
        $id,
        [
            'agent_mode' => $agentMode,
            'maintenance_mode' => $maintenanceMode,
            'maintenance_until' => $maintenanceUntil,
            'push_allowed_ips' => $pushAllowedIps,
        ]
    );
    flash_set('success', 'Server changes saved successfully.');
    redirect('/admin/servers.php');
}

$title = APP_NAME . ' - Edit Server';
$activeNav = 'servers';
require_once __DIR__ . '/../includes/layout/head.php';
require_once __DIR__ . '/../includes/layout/nav.php';
require_once __DIR__ . '/../includes/layout/flash.php';
?>
<main class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Edit Server</h1>
            <p class="page-subtitle">Update connectivity, notification, and maintenance configuration.</p>
        </div>
        <div class="toolbar-actions">
            <a class="btn btn-soft" href="<?= e(app_url('admin/servers.php')) ?>">Back to Servers</a>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft">
            <h2 class="h6 mb-0">Server Configuration</h2>
        </div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <?= csrf_input() ?>
                <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                <div class="col-md-6">
                    <label class="form-label">Name</label>
                    <input class="form-control" name="name" value="<?= e((string) $server['name']) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Location</label>
                    <input class="form-control" name="location" value="<?= e((string) ($server['location'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Host/IP</label>
                    <input class="form-control" name="host" value="<?= e((string) ($server['host'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Type</label>
                    <input class="form-control" name="type" value="<?= e((string) ($server['type'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Agent Mode</label>
                    <select class="form-select" name="agent_mode">
                        <option value="push" <?= (string) $server['agent_mode'] === 'push' ? 'selected' : '' ?>>Push</option>
                        <option value="pull" <?= (string) $server['agent_mode'] === 'pull' ? 'selected' : '' ?>>Pull</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">URL Agent</label>
                    <input class="form-control" name="url" value="<?= e((string) ($server['url'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Notify Email</label>
                    <input class="form-control" name="notify_email" value="<?= e((string) ($server['notify_email'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Push Allowed IPs (optional)</label>
                    <textarea class="form-control" rows="2" name="push_allowed_ips" placeholder="contoh: 10.0.0.5, 203.0.113.0/24"><?= e((string) ($server['push_allowed_ips'] ?? '')) ?></textarea>
                    <div class="form-text">Kosong = semua IP diizinkan. Support IP exact dan CIDR IPv4.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label d-block">Maintenance Mode</label>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="maintenance_mode" id="maintenance_mode" <?= (int) ($server['maintenance_mode'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="maintenance_mode">Enable maintenance (suppress alerts)</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Maintenance Until (optional)</label>
                    <?php
                    $maintenanceUntilValue = '';
                    if (!empty($server['maintenance_until'])) {
                        $mt = strtotime((string) $server['maintenance_until']);
                        if ($mt !== false) {
                            $maintenanceUntilValue = date('Y-m-d\TH:i', $mt);
                        }
                    }
                    ?>
                    <input class="form-control" type="datetime-local" name="maintenance_until" value="<?= e($maintenanceUntilValue) ?>">
                </div>
                <div class="col-12 settings-actions d-flex flex-wrap gap-2">
                    <button class="btn btn-info" type="submit" name="action" value="save" data-submit-loading data-loading-text="Saving...">Save Changes</button>
                    <button class="btn btn-outline-warning" type="submit" name="action" value="regen_token" data-confirm="Regenerate this server token?" data-submit-loading data-loading-text="Generating...">Regenerate Token</button>
                    <a class="btn btn-outline-light" href="<?= e(app_url('admin/servers.php')) ?>">Back</a>
                </div>
            </form>
        </div>
    </section>
    <section class="card card-neon" data-ui-section>
        <div class="card-body">
            <h2 class="h6 text-secondary">Current Token</h2>
            <code class="d-block text-break"><?= e((string) $server['token']) ?></code>
        </div>
    </section>
</main>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
