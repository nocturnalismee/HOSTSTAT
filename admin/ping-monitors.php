<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/ping.php';
require_once __DIR__ . '/../includes/cache.php';

require_login();
$canManageMonitors = has_role('admin');

if (is_post()) {
    require_role('admin');
    if (!csrf_validate($_POST['_csrf_token'] ?? null)) {
        flash_set('danger', 'Invalid CSRF token.');
        redirect('/admin/ping-monitors.php');
    }

    $monitorId = (int) ($_POST['monitor_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    if ($monitorId <= 0) {
        flash_set('danger', 'Invalid ping monitor.');
        redirect('/admin/ping-monitors.php');
    }

    if ($action === 'toggle') {
        db_exec('UPDATE ping_monitors SET active = IF(active = 1, 0, 1) WHERE id = :id', [':id' => $monitorId]);
        invalidate_ping_cache($monitorId);
        audit_log('ping_monitor_toggle', 'Toggled ping monitor active status', 'ping_monitor', $monitorId);
        flash_set('success', 'Ping monitor status updated.');
    } elseif ($action === 'delete') {
        db_exec('DELETE FROM ping_monitors WHERE id = :id', [':id' => $monitorId]);
        invalidate_ping_cache($monitorId);
        audit_log('ping_monitor_delete', 'Deleted ping monitor and check history', 'ping_monitor', $monitorId);
        flash_set('success', 'Ping monitor deleted successfully.');
    }

    redirect('/admin/ping-monitors.php');
}

$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
$statusFilter = in_array($statusFilter, ['all', 'up', 'down', 'pending', 'paused'], true) ? $statusFilter : 'all';
$typeFilter = strtolower(trim((string) ($_GET['type'] ?? 'all')));
$typeFilter = in_array($typeFilter, ['all', 'ip', 'domain', 'url'], true) ? $typeFilter : 'all';
$methodFilter = strtolower(trim((string) ($_GET['method'] ?? 'all')));
$methodFilter = in_array($methodFilter, ['all', 'icmp', 'http'], true) ? $methodFilter : 'all';
$uptimePoints = 30;

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(pm.name LIKE :q OR pm.target LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($typeFilter !== 'all') {
    $where[] = 'pm.target_type = :target_type';
    $params[':target_type'] = $typeFilter;
}
if ($methodFilter !== 'all') {
    $where[] = 'pm.check_method = :check_method';
    $params[':check_method'] = $methodFilter;
}
if ($statusFilter === 'up') {
    $where[] = 'pm.active = 1 AND COALESCE(ps.last_status, "unknown") = "up"';
} elseif ($statusFilter === 'down') {
    $where[] = 'pm.active = 1 AND COALESCE(ps.last_status, "unknown") = "down"';
} elseif ($statusFilter === 'pending') {
    $where[] = 'pm.active = 1 AND COALESCE(ps.last_status, "unknown") = "unknown"';
} elseif ($statusFilter === 'paused') {
    $where[] = 'pm.active = 0';
}

$sql = 'SELECT pm.id, pm.name, pm.target, pm.target_type, pm.check_method, pm.check_interval_seconds, pm.timeout_seconds, pm.failure_threshold, pm.active,
               ps.last_status, ps.last_latency_ms, ps.last_error, ps.last_checked_at, ps.last_change_at, ps.consecutive_failures
        FROM ping_monitors pm
        LEFT JOIN ping_monitor_states ps ON ps.monitor_id = pm.id';
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY pm.created_at DESC, pm.id DESC';
$listCacheKey = 'ping:list:' . md5((string) json_encode([
    'q' => $q,
    'status' => $statusFilter,
    'type' => $typeFilter,
    'method' => $methodFilter,
    'uptime_points' => $uptimePoints,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$cachedListPayload = cache_get($listCacheKey);
if (is_array($cachedListPayload) && isset($cachedListPayload['rows']) && isset($cachedListPayload['summary'])) {
    $rows = is_array($cachedListPayload['rows']) ? $cachedListPayload['rows'] : [];
    $summary = is_array($cachedListPayload['summary']) ? $cachedListPayload['summary'] : [
        'total' => 0,
        'up' => 0,
        'down' => 0,
        'pending' => 0,
        'paused' => 0,
    ];
    $uptimeBarsByMonitor = is_array($cachedListPayload['uptime_bars_by_monitor'] ?? null) ? $cachedListPayload['uptime_bars_by_monitor'] : [];
} else {
    $rows = db_all($sql, $params);
    $summary = [
        'total' => count($rows),
        'up' => 0,
        'down' => 0,
        'pending' => 0,
        'paused' => 0,
    ];
    foreach ($rows as $row) {
        $status = ping_display_status((string) ($row['last_status'] ?? 'unknown'), (int) ($row['active'] ?? 0));
        if (isset($summary[$status])) {
            $summary[$status]++;
        }
    }

    $uptimeBarsByMonitor = [];
    $monitorIds = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows), static fn (int $id): bool => $id > 0));
    if (!empty($monitorIds)) {
        $placeholders = implode(',', array_fill(0, count($monitorIds), '?'));
        $stmt = db()->prepare(
            "SELECT ranked.monitor_id, ranked.status, ranked.checked_at
             FROM (
                SELECT pc.monitor_id, pc.status, pc.checked_at,
                       ROW_NUMBER() OVER (PARTITION BY pc.monitor_id ORDER BY pc.checked_at DESC, pc.id DESC) AS rn
                FROM ping_checks pc
                WHERE pc.monitor_id IN ({$placeholders})
             ) ranked
             WHERE ranked.rn <= {$uptimePoints}
             ORDER BY ranked.monitor_id ASC, ranked.checked_at ASC"
        );
        $stmt->execute($monitorIds);
        $barRows = $stmt->fetchAll();

        foreach ($monitorIds as $monitorId) {
            $uptimeBarsByMonitor[$monitorId] = array_fill(0, $uptimePoints, ['status' => 'pending', 'checked_at' => null]);
        }

        $grouped = [];
        foreach ($barRows as $barRow) {
            $mid = (int) ($barRow['monitor_id'] ?? 0);
            if ($mid <= 0) {
                continue;
            }
            $grouped[$mid][] = [
                'status' => (string) ($barRow['status'] ?? 'down'),
                'checked_at' => (string) ($barRow['checked_at'] ?? ''),
            ];
        }

        foreach ($grouped as $mid => $items) {
            $slice = array_slice($items, -$uptimePoints);
            $pad = $uptimePoints - count($slice);
            if ($pad > 0) {
                $slice = array_merge(array_fill(0, $pad, ['status' => 'pending', 'checked_at' => null]), $slice);
            }
            $uptimeBarsByMonitor[$mid] = $slice;
        }
    }

    cache_set(
        $listCacheKey,
        ['rows' => $rows, 'summary' => $summary, 'uptime_bars_by_monitor' => $uptimeBarsByMonitor],
        cache_ttl('cache_ttl_status_list', 15)
    );
}

function ping_status_badge_class(string $status): string
{
    return match ($status) {
        'up' => 'badge-online',
        'down' => 'badge-down',
        'paused' => 'text-bg-secondary',
        default => 'badge-pending',
    };
}

$title = APP_NAME . ' - Ping Monitor';
$activeNav = 'ping';
require_once __DIR__ . '/../includes/layout/head.php';
require_once __DIR__ . '/../includes/layout/nav.php';
require_once __DIR__ . '/../includes/layout/flash.php';
?>
<main class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Ping Monitor</h1>
            <p class="page-subtitle">Monitor availability and latency for IP and domain targets.</p>
        </div>
        <?php if ($canManageMonitors): ?>
            <div class="toolbar-actions">
                <a href="<?= e(app_url('admin/ping-add.php')) ?>" class="btn btn-info"><i class="ti ti-plus me-1"></i>Add Ping</a>
            </div>
        <?php endif; ?>
    </section>

    <section class="row g-3 summary-grid" data-ui-section>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-total p-3">
                <div class="summary-card-head"><span class="summary-card-label">Total</span><i class="ti ti-radar summary-card-icon"></i></div>
                <div class="summary-card-value"><?= e((string) $summary['total']) ?></div>
                <div class="summary-card-subtitle">Configured targets</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-online p-3">
                <div class="summary-card-head"><span class="summary-card-label">Up</span><i class="ti ti-arrow-up-circle summary-card-icon"></i></div>
                <div class="summary-card-value"><?= e((string) $summary['up']) ?></div>
                <div class="summary-card-subtitle">Reachable</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-down p-3">
                <div class="summary-card-head"><span class="summary-card-label">Down</span><i class="ti ti-alert-triangle summary-card-icon"></i></div>
                <div class="summary-card-value"><?= e((string) $summary['down']) ?></div>
                <div class="summary-card-subtitle">Unreachable</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-pending p-3">
                <div class="summary-card-head"><span class="summary-card-label">Pending/Paused</span><i class="ti ti-history summary-card-icon"></i></div>
                <div class="summary-card-value"><?= e((string) ($summary['pending'] + $summary['paused'])) ?></div>
                <div class="summary-card-subtitle">Pending <?= e((string) $summary['pending']) ?> | Paused <?= e((string) $summary['paused']) ?></div>
            </div>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-4 col-lg-3">
                    <label class="form-label">Search</label>
                    <input class="form-control" name="q" placeholder="name or target" value="<?= e($q) ?>">
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <?php foreach (['all', 'up', 'down', 'pending', 'paused'] as $opt): ?>
                            <option value="<?= e($opt) ?>" <?= $statusFilter === $opt ? 'selected' : '' ?>><?= e(strtoupper($opt)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label">Target Type</label>
                    <select class="form-select" name="type">
                        <?php foreach (['all', 'domain', 'ip', 'url'] as $opt): ?>
                            <option value="<?= e($opt) ?>" <?= $typeFilter === $opt ? 'selected' : '' ?>><?= e(strtoupper($opt)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label">Method</label>
                    <select class="form-select" name="method">
                        <?php foreach (['all', 'icmp', 'http'] as $opt): ?>
                            <option value="<?= e($opt) ?>" <?= $methodFilter === $opt ? 'selected' : '' ?>><?= e(strtoupper($opt)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-12 col-lg-1 d-flex align-items-end">
                    <button class="btn btn-outline-info px-4" type="submit">Filter</button>
                </div>
            </form>
        </div>

        <div class="table-responsive table-shell ping-table-shell ping-table-responsive" data-ui-table>
            <table class="table servmon-table mb-0">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Target</th>
                    <th>Method</th>
                    <th>Interval</th>
                    <th>Timeout</th>
                    <th>Fail Threshold</th>
                    <th>Status</th>
                    <th>Uptime</th>
                    <th>Latency</th>
                    <th>Last Check</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="11" class="table-empty">No ping monitors found.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php $status = ping_display_status((string) ($row['last_status'] ?? 'unknown'), (int) ($row['active'] ?? 0)); ?>
                    <?php
                    $monitorId = (int) ($row['id'] ?? 0);
                    $uptimeBars = $uptimeBarsByMonitor[$monitorId] ?? array_fill(0, $uptimePoints, ['status' => 'pending', 'checked_at' => null]);
                    ?>
                    <tr>
                        <td><?= e((string) $row['name']) ?></td>
                        <td>
                            <div class="fw-semibold"><?= e((string) $row['target']) ?></div>
                            <small class="text-secondary text-uppercase"><?= e((string) $row['target_type']) ?></small>
                        </td>
                        <td><span class="badge text-bg-secondary text-uppercase"><?= e((string) ($row['check_method'] ?? 'icmp')) ?></span></td>
                        <td><?= e((string) ((int) ($row['check_interval_seconds'] ?? 60))) ?>s</td>
                        <td><?= e((string) ((int) ($row['timeout_seconds'] ?? 2))) ?>s</td>
                        <td><?= e((string) ((int) ($row['failure_threshold'] ?? 2))) ?></td>
                        <td><span class="badge <?= e(ping_status_badge_class($status)) ?> text-uppercase"><?= e($status) ?></span></td>
                        <td>
                            <div class="ping-uptime-strip" aria-label="Last <?= e((string) $uptimePoints) ?> checks">
                                <?php foreach ($uptimeBars as $segment): ?>
                                    <?php
                                    $segmentStatus = (string) ($segment['status'] ?? 'pending');
                                    $segmentClass = $segmentStatus === 'up'
                                        ? 'is-up'
                                        : ($segmentStatus === 'down' ? 'is-down' : 'is-pending');
                                    $segmentCheckedAt = $segment['checked_at'] ?? null;
                                    $segmentTitle = $segmentCheckedAt !== null && $segmentCheckedAt !== ''
                                        ? ('Status: ' . strtoupper($segmentStatus) . ' | ' . (string) $segmentCheckedAt)
                                        : 'Status: PENDING';
                                    ?>
                                    <span class="ping-uptime-segment <?= e($segmentClass) ?>" title="<?= e($segmentTitle) ?>" aria-hidden="true"></span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td><?= isset($row['last_latency_ms']) ? e(number_format((float) $row['last_latency_ms'], 2)) . ' ms' : '-' ?></td>
                        <td><?= e((string) ($row['last_checked_at'] ?? '-')) ?></td>
                        <td class="text-end">
                            <div class="dropdown d-inline-block">
                                <button
                                    class="btn btn-sm btn-outline-light"
                                    type="button"
                                    data-bs-toggle="dropdown"
                                    data-bs-auto-close="outside"
                                    aria-expanded="false"
                                    aria-label="Actions"
                                >
                                    <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <a class="dropdown-item" href="<?= e(app_url('admin/ping-detail.php?id=' . (int) $row['id'])) ?>">
                                            <i class="ti ti-eye me-2" aria-hidden="true"></i>Details
                                        </a>
                                    </li>
                                    <?php if ($canManageMonitors): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?= e(app_url('admin/ping-edit.php?id=' . (int) $row['id'])) ?>">
                                                <i class="ti ti-pencil me-2" aria-hidden="true"></i>Edit
                                            </a>
                                        </li>
                                        <li>
                                            <form method="post" class="m-0">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="monitor_id" value="<?= e((string) $row['id']) ?>">
                                                <button class="dropdown-item text-warning" type="submit" data-submit-loading data-loading-text="Updating...">
                                                    <i class="ti ti-player-pause me-2" aria-hidden="true"></i><?= (int) ($row['active'] ?? 0) === 1 ? 'Pause' : 'Enable' ?>
                                                </button>
                                            </form>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form method="post" class="m-0">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="monitor_id" value="<?= e((string) $row['id']) ?>">
                                                <button class="dropdown-item text-danger" type="submit" data-confirm="Delete this ping monitor and all history?" data-submit-loading data-loading-text="Deleting...">
                                                    <i class="ti ti-trash me-2" aria-hidden="true"></i>Delete
                                                </button>
                                            </form>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
<script>window.SERVMON_PING_AUTO_REFRESH_MS = 15000;</script>
<script src="<?= e(asset_url('assets/js/ping-refresh.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
