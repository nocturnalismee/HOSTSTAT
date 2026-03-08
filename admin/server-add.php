<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/cache.php';
require_once __DIR__ . '/../includes/audit.php';
require_role('admin');

if (is_post()) {
    if (!csrf_validate($_POST['_csrf_token'] ?? null)) {
        flash_set('danger', 'Invalid CSRF token.');
        redirect('/admin/server-add.php');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $location = trim((string) ($_POST['location'] ?? ''));
    $host = trim((string) ($_POST['host'] ?? ''));
    $type = trim((string) ($_POST['type'] ?? ''));
    $provider = trim((string) ($_POST['provider'] ?? ''));
    $label = trim((string) ($_POST['label'] ?? ''));

    if ($name === '') {
        flash_set('danger', 'Server name is required.');
        redirect('/admin/server-add.php');
    }
    $token = bin2hex(random_bytes(32));
    db_exec(
        'INSERT INTO servers (name, url, location, host, type, provider, label, agent_mode, token, active, created_at)
         VALUES (:name, :url, :location, :host, :type, :provider, :label, :agent_mode, :token, 1, NOW())',
        [
            ':name' => $name,
            ':url' => null,
            ':location' => $location !== '' ? $location : null,
            ':host' => $host !== '' ? $host : null,
            ':type' => $type !== '' ? $type : null,
            ':provider' => $provider !== '' ? $provider : null,
            ':label' => $label !== '' ? $label : null,
            ':agent_mode' => 'push',
            ':token' => $token,
        ]
    );

    $id = (int) db()->lastInsertId();
    invalidate_status_cache();
    audit_log('server_add', 'Created new server', 'server', $id, [
        'name' => $name,
        'agent_mode' => 'push',
        'location' => $location,
        'type' => $type,
        'label' => $label,
        'provider' => $provider,
    ]);
    flash_set('success', 'Server added successfully.');
    redirect('/admin/server-setup.php?id=' . $id);
}

$title = APP_NAME . ' - Add Server';
$activeNav = 'servers';
require_once __DIR__ . '/../includes/layout/head.php';
require_once __DIR__ . '/../includes/layout/nav.php';
require_once __DIR__ . '/../includes/layout/flash.php';
?>
<main class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Add New Server</h1>
            <p class="page-subtitle">Register a server node with push-mode agent configuration.</p>
        </div>
        <div class="toolbar-actions">
            <a class="btn btn-soft" href="<?= e(app_url('admin/servers.php')) ?>">Back to Servers</a>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft">
            <h2 class="h6 mb-0">Server Identity</h2>
        </div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <?= csrf_input() ?>
                <div class="col-md-6">
                    <label class="form-label">Name</label>
                    <input class="form-control" name="name" value="<?= e(old('name')) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Location</label>
                    <input class="form-control" name="location" value="<?= e(old('location')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Host/IP</label>
                    <input class="form-control" name="host" value="<?= e(old('host')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Type</label>
                    <input class="form-control" name="type" value="<?= e(old('type')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Provider (optional)</label>
                    <input class="form-control" name="provider" value="<?= e(old('provider')) ?>" placeholder="DigitalOcean, Hetzner, AWS, etc.">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Label (optional)</label>
                    <input class="form-control" name="label" value="<?= e(old('label')) ?>" placeholder="Production, Staging, Core API, etc.">
                </div>
                <div class="col-12 settings-actions d-flex flex-wrap gap-2">
                    <button class="btn btn-info" type="submit" data-submit-loading data-loading-text="Saving...">Save</button>
                    <a class="btn btn-outline-light" href="<?= e(app_url('admin/servers.php')) ?>">Cancel</a>
                </div>
            </form>
        </div>
    </section>
</main>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
