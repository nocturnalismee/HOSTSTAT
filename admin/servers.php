<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/cache.php';
require_once __DIR__ . '/../includes/maintenance.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/settings.php';
require_login();
$canManageServers = has_role('admin');
$statusOnlineMinutes = max(1, (int) setting_get('alert_down_minutes'));

if (is_post()) {
    require_role('admin');
    if (!csrf_validate($_POST['_csrf_token'] ?? null)) {
        flash_set('danger', 'Invalid CSRF token.');
        redirect('/admin/servers.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    $serverId = (int) ($_POST['server_id'] ?? 0);
    if ($serverId <= 0) {
        flash_set('danger', 'Invalid server.');
        redirect('/admin/servers.php');
    }

    if ($action === 'toggle') {
        $before = db_one('SELECT id, name, active FROM servers WHERE id = :id LIMIT 1', [':id' => $serverId]);
        db_exec('UPDATE servers SET active = IF(active = 1, 0, 1) WHERE id = :id', [':id' => $serverId]);
        $after = db_one('SELECT id, name, active FROM servers WHERE id = :id LIMIT 1', [':id' => $serverId]);
        invalidate_status_cache($serverId);
        audit_log('server_toggle_active', 'Toggled server active status', 'server', $serverId);
        if ($after !== null) {
            $actor = current_user();
            $actorName = (string) ($actor['username'] ?? 'system');
            $serverName = (string) ($after['name'] ?? ('Server #' . $serverId));
            $isActive = (int) ($after['active'] ?? 0) === 1;
            $alertType = $isActive ? 'server_enabled' : 'server_disabled';
            $severity = $isActive ? 'success' : 'warning';
            $title = '[' . $serverName . '] Server ' . ($isActive ? 'Enabled' : 'Disabled');
            $message = 'Server monitoring was ' . ($isActive ? 'enabled' : 'disabled') . ' from Server Management by ' . $actorName . '.';
            db_exec(
                'INSERT INTO alert_logs
                (server_id, alert_type, severity, title, message, context_json, sent_email, sent_telegram, created_at)
                VALUES
                (:server_id, :alert_type, :severity, :title, :message, :context_json, 0, 0, NOW())',
                [
                    ':server_id' => $serverId,
                    ':alert_type' => $alertType,
                    ':severity' => $severity,
                    ':title' => $title,
                    ':message' => $message,
                    ':context_json' => json_encode(
                        [
                            'source' => 'admin_server_toggle',
                            'previous_active' => (int) ($before['active'] ?? 0),
                            'current_active' => (int) ($after['active'] ?? 0),
                            'actor' => $actorName,
                        ],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                ]
            );
            invalidate_alert_cache();
        }
        flash_set('success', 'Server active status updated.');
    } elseif ($action === 'delete') {
        db_exec('DELETE FROM servers WHERE id = :id', [':id' => $serverId]);
        invalidate_status_cache($serverId);
        audit_log('server_delete', 'Deleted server and related metrics', 'server', $serverId);
        flash_set('success', 'Server and related metrics deleted successfully.');
    }

    redirect('/admin/servers.php');
}

$rows = db_all(
    'SELECT s.id, s.name, s.location, s.provider, s.label, s.host, s.type, s.agent_mode, s.active, s.maintenance_mode, s.maintenance_until,
            m.recorded_at AS last_seen, m.cpu_load, m.panel_profile,
            COALESCE(ss.up_count, 0) AS service_up_count,
            COALESCE(ss.down_count, 0) AS service_down_count,
            COALESCE(ss.unknown_count, 0) AS service_unknown_count
     FROM servers s' . latest_metric_join_sql('s', 'm') . '
     LEFT JOIN (
         SELECT server_id, SUM(last_status = "up") AS up_count, SUM(last_status = "down") AS down_count, SUM(last_status = "unknown") AS unknown_count
         FROM server_service_states
         GROUP BY server_id
     ) ss ON ss.server_id = s.id
     ORDER BY s.created_at DESC'
);

$title = APP_NAME . ' - Server Management';
$activeNav = 'servers';
require_once __DIR__ . '/../includes/layout/head.php';
require_once __DIR__ . '/../includes/layout/nav.php';
require_once __DIR__ . '/../includes/layout/flash.php';
?>
<main class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Server Management</h1>
            <p class="page-subtitle">Manage inventory, status visibility, and operational actions.</p>
        </div>
        <?php if ($canManageServers): ?>
            <div class="toolbar-actions">
                <a href="<?= e(app_url('admin/server-add.php')) ?>" class="btn btn-info"><i class="ti ti-plus me-1"></i>Add Server</a>
            </div>
        <?php endif; ?>
    </section>
    <?php if (!$canManageServers): ?>
        <div class="alert alert-info">Viewer mode: server changes are restricted to admin users.</div>
    <?php endif; ?>

    <section class="card card-neon" data-ui-section>
        <div class="table-responsive table-shell" data-ui-table>
            <table class="table servmon-table mb-0">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Host</th>
                    <th>Location</th>
                    <th>Provider</th>
                    <th>Label</th>
                    <th>Type</th>
                    <th>Panel</th>
                    <th>Service Summary</th>
                    <th>Maintenance</th>
                    <th>Status</th>
                    <th>Last Seen</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="12" class="table-empty">No servers available yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php $status = serverStatusFromLastSeen($row['last_seen'] ?? null, (int) ($row['active'] ?? 0) === 1, $statusOnlineMinutes); ?>
                    <tr>
                        <td>
                            <span class="table-cell-truncate" title="<?= e((string) $row['name']) ?>">
                                <?= e((string) $row['name']) ?>
                            </span>
                        </td>
                        <td><?= e($row['host'] ?? '-') ?></td>
                        <td><?= e($row['location'] ?? '-') ?></td>
                        <td><?= e($row['provider'] ?? '-') ?></td>
                        <td><?= e($row['label'] ?? '-') ?></td>
                        <td><?= e($row['type'] ?? '-') ?></td>
                        <td><code><?= e((string) ($row['panel_profile'] ?? 'generic')) ?></code></td>
                        <?php
                        $upCount = max(0, (int) ($row['service_up_count'] ?? 0));
                        $downCount = max(0, (int) ($row['service_down_count'] ?? 0));
                        $unknownCount = max(0, (int) ($row['service_unknown_count'] ?? 0));
                        $totalServices = $upCount + $downCount + $unknownCount;
                        if ($status === 'down' && $totalServices > 0) {
                            $upCount = 0;
                            $downCount = $totalServices;
                            $unknownCount = 0;
                        }
                        ?>
                        <td>
                            <?php if ($downCount > 0 || $unknownCount > 0): ?>
                                <?php if ($downCount > 0): ?>
                                    <span class="text-danger fw-semibold me-2"><i class="ti ti-arrow-down-circle me-1" aria-label="down"></i><?= e((string) $downCount) ?></span>
                                <?php endif; ?>
                                <?php if ($unknownCount > 0): ?>
                                    <span class="text-warning fw-semibold"><i class="ti ti-help-circle me-1" aria-label="unknown"></i><?= e((string) $unknownCount) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-success fw-semibold"><i class="ti ti-arrow-up-circle me-1" aria-label="up"></i><?= e((string) $upCount) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(maintenance_display_text($row)) ?></td>
                        <td><span class="badge <?= e('badge-' . $status) ?> text-uppercase"><?= e($status) ?></span></td>
                        <td><?= e($row['last_seen'] ?? '-') ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-soft" href="<?= e(app_url('admin/server-detail.php?id=' . (int) $row['id'])) ?>">Details</a>
                            <?php if ($canManageServers): ?>
                                <a class="btn btn-sm btn-outline-info" href="<?= e(app_url('admin/server-edit.php?id=' . (int) $row['id'])) ?>">Edit</a>
                                <form class="d-inline" method="post">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="server_id" value="<?= e((string) $row['id']) ?>">
                                    <button class="btn btn-sm btn-outline-warning" type="submit" data-submit-loading data-loading-text="Updating..."><?= (int) $row['active'] === 1 ? 'Disable' : 'Enable' ?></button>
                                </form>
                                <form class="d-inline" method="post">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="server_id" value="<?= e((string) $row['id']) ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit" data-confirm="Delete this server and all related metrics?" data-submit-loading data-loading-text="Deleting...">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
