<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/ping.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../includes/cache.php';

require_login();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$singleCacheKey = 'ping:single:' . $id;
$cachedMonitor = cache_get($singleCacheKey);
if (is_array($cachedMonitor) && (int) ($cachedMonitor['id'] ?? 0) === $id) {
    $monitor = $cachedMonitor;
} else {
    $monitor = db_one(
        'SELECT pm.id, pm.name, pm.target, pm.target_type, pm.check_method, pm.check_interval_seconds, pm.timeout_seconds, pm.failure_threshold, pm.active, pm.created_at, pm.updated_at,
                ps.last_status, ps.consecutive_failures, ps.last_latency_ms, ps.last_error, ps.last_checked_at, ps.last_change_at
         FROM ping_monitors pm
         LEFT JOIN ping_monitor_states ps ON ps.monitor_id = pm.id
         WHERE pm.id = :id
         LIMIT 1',
        [':id' => $id]
    );
    if (is_array($monitor)) {
        cache_set($singleCacheKey, $monitor, cache_ttl('cache_ttl_status_single', 15));
    }
}
if ($monitor === null) {
    flash_set('danger', 'Ping monitor not found.');
    redirect('/admin/ping-monitors.php');
}

$canManageMonitors = has_role('admin');

if (is_post()) {
    require_role('admin');
    if (!csrf_validate($_POST['_csrf_token'] ?? null)) {
        flash_set('danger', 'Invalid CSRF token.');
        redirect('/admin/ping-detail.php?id=' . $id);
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'delete') {
        db_exec('DELETE FROM ping_monitors WHERE id = :id', [':id' => $id]);
        invalidate_ping_cache($id);
        audit_log('ping_monitor_delete', 'Deleted ping monitor and check history', 'ping_monitor', $id);
        flash_set('success', 'Ping monitor deleted successfully.');
        redirect('/admin/ping-monitors.php');
    }

    if ($action === 'toggle') {
        db_exec('UPDATE ping_monitors SET active = IF(active = 1, 0, 1), updated_at = NOW() WHERE id = :id', [':id' => $id]);
        invalidate_ping_cache($id);
        audit_log('ping_monitor_toggle', 'Toggled ping monitor active status', 'ping_monitor', $id);
        flash_set('success', 'Ping monitor status updated.');
        redirect('/admin/ping-detail.php?id=' . $id);
    }

    if ($action === 'run_now') {
        $probe = ping_probe_target(
            (string) ($monitor['target'] ?? ''),
            max(1, (int) ($monitor['timeout_seconds'] ?? 2)),
            (string) ($monitor['check_method'] ?? 'icmp')
        );
        $transition = ping_record_check(
            $id,
            $probe,
            max(1, (int) ($monitor['failure_threshold'] ?? 2))
        );
        if (($transition['changed'] ?? false) === true) {
            evaluate_ping_monitor_transition_alert($monitor, $transition, $probe);
        }
        invalidate_ping_cache($id);
        audit_log('ping_monitor_run_now', 'Executed manual ping check', 'ping_monitor', $id, [
            'probe_status' => $probe['status'] ?? 'down',
            'latency_ms' => $probe['latency_ms'] ?? null,
        ]);
        flash_set('success', 'Manual ping check executed.');
        redirect('/admin/ping-detail.php?id=' . $id);
    }
}

$range = strtolower(trim((string) ($_GET['range'] ?? '24h')));
$range = in_array($range, ['5m', '30m', '24h', '7d', '30d'], true) ? $range : '24h';
$rangeSql = match ($range) {
    '5m' => '5 MINUTE',
    '30m' => '30 MINUTE',
    '7d' => '7 DAY',
    '30d' => '30 DAY',
    default => '24 HOUR',
};
$historyLimit = match ($range) {
    '5m' => 400,
    '30m' => 1200,
    '7d' => 2500,
    '30d' => 5000,
    default => 1500,
};
$historyTtl = match ($range) {
    '5m' => cache_ttl('cache_ttl_history_5m', 5),
    '30m' => cache_ttl('cache_ttl_history_30m', 10),
    '7d' => cache_ttl('cache_ttl_history_7d', 120),
    '30d' => cache_ttl('cache_ttl_history_30d', 180),
    default => cache_ttl('cache_ttl_history_24h', 30),
};

$historyCacheKey = 'ping:history:' . $id . ':' . $range;
$cachedHistory = cache_get($historyCacheKey);
if (is_array($cachedHistory)) {
    $history = $cachedHistory;
} else {
    $history = db_all(
        "SELECT id, status, latency_ms, error_message, checked_at
         FROM ping_checks
         WHERE monitor_id = :id
         AND checked_at >= DATE_SUB(NOW(), INTERVAL {$rangeSql})
         ORDER BY checked_at DESC
         LIMIT {$historyLimit}",
        [':id' => $id]
    );
    cache_set($historyCacheKey, $history, $historyTtl);
}

$perPage = 15;
$requestedPage = (int) ($_GET['page'] ?? 1);
$page = max(1, $requestedPage);
$totalHistoryRows = count($history);
$totalPages = max(1, (int) ceil($totalHistoryRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$historyPageRows = array_slice($history, $offset, $perPage);

$chartRows = array_reverse($history);
$chartPayload = array_map(static function (array $row): array {
    return [
        'checked_at' => (string) ($row['checked_at'] ?? ''),
        'status' => (string) ($row['status'] ?? 'down'),
        'latency_ms' => isset($row['latency_ms']) ? (float) $row['latency_ms'] : null,
    ];
}, $chartRows);

$displayStatus = ping_display_status((string) ($monitor['last_status'] ?? 'unknown'), (int) ($monitor['active'] ?? 0));
$statusBadgeClass = match ($displayStatus) {
    'up' => 'badge-online',
    'down' => 'badge-down',
    'paused' => 'text-bg-secondary',
    default => 'badge-pending',
};

$title = APP_NAME . ' - Ping Monitor Detail';
$activeNav = 'ping';
require_once __DIR__ . '/../includes/layout/head.php';
require_once __DIR__ . '/../includes/layout/nav.php';
require_once __DIR__ . '/../includes/layout/flash.php';
?>
<main class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Ping Monitor Detail</h1>
            <p class="page-subtitle">Detailed status, latency history, and operational actions.</p>
        </div>
        <div class="toolbar-actions">
            <a class="btn btn-soft" href="<?= e(app_url('admin/ping-monitors.php')) ?>">Back to List</a>
            <?php if ($canManageMonitors): ?>
                <a class="btn btn-outline-info" href="<?= e(app_url('admin/ping-edit.php?id=' . $id)) ?>">Edit</a>
                <form class="d-inline" method="post">
                    <?= csrf_input() ?>
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <input type="hidden" name="action" value="run_now">
                    <button class="btn btn-outline-success" type="submit" data-submit-loading data-loading-text="Running...">Run Now</button>
                </form>
                <form class="d-inline" method="post">
                    <?= csrf_input() ?>
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <button class="btn btn-outline-warning" type="submit" data-submit-loading data-loading-text="Updating..."><?= (int) ($monitor['active'] ?? 0) === 1 ? 'Pause' : 'Enable' ?></button>
                </form>
                <form class="d-inline" method="post">
                    <?= csrf_input() ?>
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <input type="hidden" name="action" value="delete">
                    <button class="btn btn-outline-danger" type="submit" data-confirm="Delete this ping monitor and all history?" data-submit-loading data-loading-text="Deleting...">Delete</button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <section class="row g-3 summary-grid" data-ui-section>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-total p-3 h-100">
                <div class="summary-card-head"><span class="summary-card-label">Name</span><i class="ti ti-bookmark summary-card-icon"></i></div>
                <div class="summary-card-value" style="font-size:1rem;line-height:1.35;"><?= e((string) $monitor['name']) ?></div>
                <div class="summary-card-subtitle">Target: <?= e((string) ($monitor['target'] ?? '-')) ?></div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-online p-3 h-100">
                <div class="summary-card-head"><span class="summary-card-label">Current Status</span><i class="ti ti-activity summary-card-icon"></i></div>
                <div class="summary-card-value"><span class="badge <?= e($statusBadgeClass) ?> text-uppercase"><?= e($displayStatus) ?></span></div>
                <div class="summary-card-subtitle">Last change: <?= e((string) ($monitor['last_change_at'] ?? '-')) ?></div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-pending p-3 h-100">
                <div class="summary-card-head"><span class="summary-card-label">Latency</span><i class="ti ti-dashboard summary-card-icon"></i></div>
                <div class="summary-card-value"><?= isset($monitor['last_latency_ms']) ? e(number_format((float) $monitor['last_latency_ms'], 2)) . ' ms' : '-' ?></div>
                <div class="summary-card-subtitle">Last check: <?= e((string) ($monitor['last_checked_at'] ?? '-')) ?></div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-down p-3 h-100">
                <div class="summary-card-head"><span class="summary-card-label">Failure Count</span><i class="ti ti-alert-triangle summary-card-icon"></i></div>
                <div class="summary-card-value"><?= e((string) max(0, (int) ($monitor['consecutive_failures'] ?? 0))) ?></div>
                <div class="summary-card-subtitle">Threshold: <?= e((string) ((int) ($monitor['failure_threshold'] ?? 2))) ?></div>
            </div>
        </div>
    </section>

    <?php if (!empty($monitor['last_error'])): ?>
        <div class="alert alert-warning mb-0">Last error: <?= e((string) $monitor['last_error']) ?></div>
    <?php endif; ?>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Latency History</h2>
            <form method="get" class="d-flex gap-2">
                <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                <select class="form-select form-select-sm" name="range" onchange="this.form.submit()">
                    <option value="5m" <?= $range === '5m' ? 'selected' : '' ?>>5 Minutes</option>
                    <option value="30m" <?= $range === '30m' ? 'selected' : '' ?>>30 Minutes</option>
                    <option value="24h" <?= $range === '24h' ? 'selected' : '' ?>>24 Hours</option>
                    <option value="7d" <?= $range === '7d' ? 'selected' : '' ?>>7 Days</option>
                    <option value="30d" <?= $range === '30d' ? 'selected' : '' ?>>30 Days</option>
                </select>
            </form>
        </div>
        <div class="card-body">
            <div id="pingHistoryChart" style="height: 280px;"></div>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft">
            <h2 class="h6 mb-0">Recent Checks</h2>
        </div>
        <div class="table-responsive table-shell">
            <table class="table servmon-table mb-0">
                <thead>
                <tr>
                    <th>Checked At</th>
                    <th>Status</th>
                    <th>Latency</th>
                    <th>Error</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($historyPageRows)): ?>
                    <tr><td colspan="4" class="table-empty">No checks in selected range.</td></tr>
                <?php endif; ?>
                <?php foreach ($historyPageRows as $row): ?>
                    <?php $rowStatus = (string) ($row['status'] ?? 'down'); ?>
                    <tr>
                        <td><?= e((string) ($row['checked_at'] ?? '-')) ?></td>
                        <td><span class="badge <?= e($rowStatus === 'up' ? 'badge-online' : 'badge-down') ?> text-uppercase"><?= e($rowStatus) ?></span></td>
                        <td><?= isset($row['latency_ms']) ? e(number_format((float) $row['latency_ms'], 2)) . ' ms' : '-' ?></td>
                        <td><?= e((string) ($row['error_message'] ?? '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-surface-2 border-soft d-flex justify-content-between align-items-center">
                <small class="text-secondary">
                    Showing <?= e((string) ($offset + 1)) ?>-<?= e((string) min($offset + $perPage, $totalHistoryRows)) ?> of <?= e((string) $totalHistoryRows) ?> checks
                </small>
                <nav aria-label="Recent checks pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e(app_url('admin/ping-detail.php?id=' . $id . '&range=' . rawurlencode($range) . '&page=' . max(1, $page - 1))) ?>">Previous</a>
                        </li>
                        <li class="page-item active" aria-current="page">
                            <span class="page-link"><?= e((string) $page) ?> / <?= e((string) $totalPages) ?></span>
                        </li>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e(app_url('admin/ping-detail.php?id=' . $id . '&range=' . rawurlencode($range) . '&page=' . min($totalPages, $page + 1))) ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </section>
</main>
<script>window.SERVMON_PING_HISTORY = <?= json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= e(asset_url('assets/js/ping-detail.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
<script>window.SERVMON_PING_AUTO_REFRESH_MS = 15000;</script>
<script src="<?= e(asset_url('assets/js/ping-refresh.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
