<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/worker.php';
require_once __DIR__ . '/../includes/settings.php';
require_login();

function dashboard_uptime_compact(int $totalSeconds): string
{
    $seconds = max(0, $totalSeconds);
    if ($seconds <= 0) {
        return '0H';
    }
    $days = intdiv($seconds, 86400);
    if ($days > 0) {
        return $days . 'D';
    }
    $hours = intdiv($seconds, 3600);
    if ($hours > 0) {
        return $hours . 'H';
    }
    return '<1H';
}

$summary = db_one(
    'SELECT
        (SELECT COUNT(*) FROM servers) AS total_servers,
        (SELECT COUNT(*) FROM servers WHERE active = 1) AS active_servers'
);

$latestMetricJoin = latest_metric_join_sql('s', 'm');
$rows = db_all(
    'SELECT s.id, s.name, s.location, s.type, s.active,
            m.recorded_at AS last_seen, m.uptime, m.cpu_load, m.ram_total, m.ram_used, m.hdd_total, m.hdd_used, m.network_in_bps, m.network_out_bps, m.mail_mta, m.mail_queue_total, m.panel_profile
     FROM servers s' . $latestMetricJoin . '
     ORDER BY s.name ASC'
);
$statusOnlineMinutes = max(1, (int) setting_get('alert_down_minutes'));
$serviceSummaryByServer = [];
$serverIds = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows), static fn (int $id): bool => $id > 0));
if (!empty($serverIds)) {
    $placeholders = implode(',', array_fill(0, count($serverIds), '?'));
    $stmt = db()->prepare(
        "SELECT server_id,
                SUM(last_status = 'up') AS up_count,
                SUM(last_status = 'down') AS down_count,
                SUM(last_status = 'unknown') AS unknown_count
         FROM server_service_states
         WHERE server_id IN ({$placeholders})
         GROUP BY server_id"
    );
    $stmt->execute($serverIds);
    foreach ($stmt->fetchAll() as $row) {
        $sid = (int) ($row['server_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $serviceSummaryByServer[$sid] = [
            'up' => (int) ($row['up_count'] ?? 0),
            'down' => (int) ($row['down_count'] ?? 0),
            'unknown' => (int) ($row['unknown_count'] ?? 0),
        ];
    }
}
$recentAlerts = db_all(
    'SELECT id, severity, title, created_at
     FROM alert_logs
     ORDER BY id DESC
     LIMIT 8'
);
$alertWorkerHealth = worker_health_status('alert_check', 180);
$pingWorkerHealth = worker_health_status('ping_check', 300);
$diskRollupWorkerHealth = worker_health_status('disk_history_rollup', 129600);
$retentionWorkerHealth = worker_health_status('retention_cleanup', 129600);
$projectRoot = realpath(__DIR__ . '/..');
if (!is_string($projectRoot) || $projectRoot === '') {
    $projectRoot = dirname(__DIR__);
}
$workersRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
$alertCronCmd = '* * * * * /usr/bin/php ' . $workersRoot . '/workers/alert-check.php >/dev/null 2>&1';
$pingCronCmd = '* * * * * /usr/bin/php ' . $workersRoot . '/workers/ping-check.php >/dev/null 2>&1';
$diskRollupCronCmd = '0 2 * * * /usr/bin/php ' . $workersRoot . '/workers/disk-rollup.php >/dev/null 2>&1';
$retentionCronCmd = '0 3 * * * /usr/bin/php ' . $workersRoot . '/workers/cleanup.php >/dev/null 2>&1';

$online = 0;
$down = 0;
$pending = 0;
foreach ($rows as $row) {
    $st = serverStatusFromLastSeen($row['last_seen'] ?? null, (int) ($row['active'] ?? 0) === 1, $statusOnlineMinutes);
    if ($st === 'online') {
        $online++;
    } elseif ($st === 'down') {
        $down++;
    } else {
        $pending++;
    }
}

$title = APP_NAME . ' - Admin Dashboard';
$activeNav = 'dashboard';
require_once __DIR__ . '/../includes/layout/head.php';
require_once __DIR__ . '/../includes/layout/nav.php';
require_once __DIR__ . '/../includes/layout/flash.php';
?>
<main class="container py-4 admin-page admin-shell">
    <?php if (($alertWorkerHealth['health'] ?? 'unknown') !== 'ok'): ?>
        <div class="alert alert-warning">
            Worker <code>alert_check</code> is <?= e((string) ($alertWorkerHealth['health'] ?? 'unknown')) ?>.
            Last success: <?= e((string) ($alertWorkerHealth['last_success_at'] ?? 'never')) ?>.
            Ensure cron <code><?= e($alertCronCmd) ?></code> is running.
        </div>
    <?php endif; ?>
    <?php if (($retentionWorkerHealth['health'] ?? 'unknown') === 'error'): ?>
        <div class="alert alert-warning">
            Worker <code>retention_cleanup</code> reported an error.
            Last success: <?= e((string) ($retentionWorkerHealth['last_success_at'] ?? 'never')) ?>.
            Ensure cron <code><?= e($retentionCronCmd) ?></code> is running.
        </div>
    <?php endif; ?>
    <?php if (($diskRollupWorkerHealth['health'] ?? 'unknown') === 'error'): ?>
        <div class="alert alert-warning">
            Worker <code>disk_history_rollup</code> reported an error.
            Last success: <?= e((string) ($diskRollupWorkerHealth['last_success_at'] ?? 'never')) ?>.
            Ensure cron <code><?= e($diskRollupCronCmd) ?></code> is running.
        </div>
    <?php endif; ?>
    <?php if (($pingWorkerHealth['health'] ?? 'unknown') !== 'ok'): ?>
        <div class="alert alert-warning">
            Worker <code>ping_check</code> is <?= e((string) ($pingWorkerHealth['health'] ?? 'unknown')) ?>.
            Last success: <?= e((string) ($pingWorkerHealth['last_success_at'] ?? 'never')) ?>.
            Ensure cron <code><?= e($pingCronCmd) ?></code> is running.
        </div>
    <?php endif; ?>
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Dashboard Monitoring</h1>
            <p class="page-subtitle">Summary of server health and recent alerts.</p>
        </div>
        <div class="toolbar-actions">
            <a href="<?= e(app_url('admin/settings.php?section=ops')) ?>" class="btn btn-outline-light btn-sm btn-toolbar-compact"><i class="ti ti-terminal-2 me-1"></i>Ops</a>
            <a href="<?= e(app_url('admin/alert-logs.php')) ?>" class="btn btn-outline-warning btn-sm btn-toolbar-compact"><i class="ti ti-bell me-1"></i>Alert Logs</a>
            <?php if (has_role('admin')): ?>
                <a href="<?= e(app_url('admin/audit-logs.php')) ?>" class="btn btn-outline-info btn-sm btn-toolbar-compact"><i class="ti ti-shield-check me-1"></i>Audit Logs</a>
            <?php endif; ?>
        </div>
    </section>

    <section class="row g-3 summary-grid" data-ui-section>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-total p-3">
                <div class="summary-card-head">
                    <span class="summary-card-label">Total Servers</span>
                    <i class="ti ti-server-2 summary-card-icon" aria-hidden="true"></i>
                </div>
                <div class="summary-card-value" data-admin-total><?= e((string) ($summary['total_servers'] ?? 0)) ?></div>
                <div class="summary-card-subtitle">Monitored inventory</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-online p-3">
                <div class="summary-card-head">
                    <span class="summary-card-label">Online</span>
                    <i class="ti ti-arrow-up-circle summary-card-icon" aria-hidden="true"></i>
                </div>
                <div class="summary-card-value" data-admin-online><?= e((string) $online) ?></div>
                <div class="summary-card-subtitle">Responding now</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-down p-3">
                <div class="summary-card-head">
                    <span class="summary-card-label">Down</span>
                    <i class="ti ti-alert-triangle summary-card-icon" aria-hidden="true"></i>
                </div>
                <div class="summary-card-value" data-admin-down><?= e((string) $down) ?></div>
                <div class="summary-card-subtitle">Needs action</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-pending p-3">
                <div class="summary-card-head">
                    <span class="summary-card-label">Pending</span>
                    <i class="ti ti-history summary-card-icon" aria-hidden="true"></i>
                </div>
                <div class="summary-card-value" data-admin-pending><?= e((string) $pending) ?></div>
                <div class="summary-card-subtitle">No recent update</div>
            </div>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Monitoring Summary</h2>
            <div class="d-flex align-items-center gap-2">
                <small class="text-secondary" data-dashboard-stale>Live | -</small>
                <a href="<?= e(app_url('admin/servers.php')) ?>" class="btn btn-sm btn-outline-info">Manage Servers</a>
            </div>
        </div>
        <div class="table-responsive table-shell" data-ui-table>
            <table class="table servmon-table dashboard-summary-table mb-0">
                <colgroup>
                    <col style="width: 9rem;">
                    <col style="width: 8.5rem;">
                    <col style="width: 6rem;">
                    <col style="width: 6rem;">
                    <col style="width: 19rem;">
                    <col style="width: 19rem;">
                    <col class="dashboard-col-panel" style="width: 6rem;">
                    <col style="width: 7.5rem;">
                    <col style="width: 10.5rem;">
                    <col style="width: 5.5rem;">
                    <col style="width: 7rem;">
                </colgroup>
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Location</th>
                    <th>Uptime</th>
                    <th>CPU</th>
                    <th>RAM</th>
                    <th>Disk</th>
                    <th class="d-none d-xl-table-cell">Panel</th>
                    <th>Services</th>
                    <th>NET</th>
                    <th>Queue</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody data-server-table>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="11" class="table-empty">No server metrics available yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $sid = (int) ($row['id'] ?? 0);
                    $status = serverStatusFromLastSeen($row['last_seen'] ?? null, (int) ($row['active'] ?? 0) === 1, $statusOnlineMinutes);
                    $ramPct = calculateUsagePercent((int) ($row['ram_used'] ?? 0), (int) ($row['ram_total'] ?? 0));
                    $hddPct = calculateUsagePercent((int) ($row['hdd_used'] ?? 0), (int) ($row['hdd_total'] ?? 0));
                    $serviceSummary = $serviceSummaryByServer[$sid] ?? ['up' => 0, 'down' => 0, 'unknown' => 0];
                    $serviceUp = max(0, (int) ($serviceSummary['up'] ?? 0));
                    $serviceDown = max(0, (int) ($serviceSummary['down'] ?? 0));
                    $serviceUnknown = max(0, (int) ($serviceSummary['unknown'] ?? 0));
                    $totalServices = $serviceUp + $serviceDown + $serviceUnknown;
                    if ($status === 'down' && $totalServices > 0) {
                        $serviceUp = 0;
                        $serviceDown = $totalServices;
                        $serviceUnknown = 0;
                    }
                    ?>
                    <tr data-server-id="<?= e((string) $sid) ?>" data-detail-url="<?= e(app_url('admin/server-detail.php?id=' . $sid)) ?>" class="dashboard-row-link" tabindex="0" role="link" aria-label="Open details for <?= e((string) $row['name']) ?>">
                        <td>
                            <span class="table-cell-truncate" title="<?= e((string) $row['name']) ?>">
                                <?= e((string) $row['name']) ?>
                            </span>
                        </td>
                        <td><?= e($row['location'] ?? '-') ?></td>
                        <td class="font-mono"><?= e(dashboard_uptime_compact((int) ($row['uptime'] ?? 0))) ?></td>
                        <td>
                            <div class="cpu-cell">
                                <div class="cpu-value font-mono"><?= e(number_format((float) ($row['cpu_load'] ?? 0), 2)) ?></div>
                                <svg class="cpu-sparkline" width="60" height="18"><polyline fill="none" stroke="var(--sv-muted)" stroke-width="1.5" points="0,16.0 60,16.0"/></svg>
                            </div>
                        </td>
                        <td>
                            <div class="resource-cell">
                                <div class="resource-label">
                                    <span><?= e(formatBytes((int) ($row['ram_used'] ?? 0))) ?> / <?= e(formatBytes((int) ($row['ram_total'] ?? 0))) ?></span>
                                    <span class="font-mono"><?= e(number_format((float) $ramPct, 1)) ?>%</span>
                                </div>
                                <div class="progress resource-progress" role="progressbar" aria-label="RAM usage" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e((string) round($ramPct, 1)) ?>">
                                    <div class="progress-bar resource-progress-bar <?= e($ramPct >= 80 ? 'is-critical' : ($ramPct > 60 ? 'is-warning' : 'is-ok')) ?>" style="<?= e('--target-width:' . number_format((float) $ramPct, 1, '.', '') . '%') ?>"></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="resource-cell">
                                <div class="resource-label">
                                    <span><?= e(formatBytes((int) ($row['hdd_used'] ?? 0))) ?> / <?= e(formatBytes((int) ($row['hdd_total'] ?? 0))) ?></span>
                                    <span class="font-mono"><?= e(number_format((float) $hddPct, 1)) ?>%</span>
                                </div>
                                <div class="progress resource-progress" role="progressbar" aria-label="Disk usage" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e((string) round($hddPct, 1)) ?>">
                                    <div class="progress-bar resource-progress-bar <?= e($hddPct >= 80 ? 'is-critical' : ($hddPct > 60 ? 'is-warning' : 'is-ok')) ?>" style="<?= e('--target-width:' . number_format((float) $hddPct, 1, '.', '') . '%') ?>"></div>
                                </div>
                            </div>
                        </td>
                        <td class="d-none d-xl-table-cell"><code><?= e((string) ($row['panel_profile'] ?? 'generic')) ?></code></td>
                        <td>
                            <?php if ($serviceDown > 0 || $serviceUnknown > 0): ?>
                                <?php if ($serviceDown > 0): ?>
                                    <span class="text-danger fw-semibold me-2">
                                        <i class="ti ti-arrow-down-circle me-1" aria-label="down"></i><span class="font-mono"><?= e((string) $serviceDown) ?></span>
                                    </span>
                                <?php endif; ?>
                                <?php if ($serviceUnknown > 0): ?>
                                    <span class="text-warning fw-semibold">
                                        <i class="ti ti-help-circle me-1" aria-label="unknown"></i><span class="font-mono"><?= e((string) $serviceUnknown) ?></span>
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-success fw-semibold">
                                    <i class="ti ti-arrow-up-circle me-1" aria-label="up"></i><span class="font-mono"><?= e((string) $serviceUp) ?></span>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="net-line"><i class="ti ti-arrow-down" aria-label="In"></i> <span class="font-mono"><?= e(formatNetworkBps((int) ($row['network_in_bps'] ?? 0))) ?></span></div>
                            <div class="net-line text-secondary"><i class="ti ti-arrow-up" aria-label="Out"></i> <span class="font-mono"><?= e(formatNetworkBps((int) ($row['network_out_bps'] ?? 0))) ?></span></div>
                        </td>
                        <?php $mailQueue = max(0, (int) ($row['mail_queue_total'] ?? 0)); ?>
                        <td class="font-mono"><?= e((string) $mailQueue) ?></td>
                        <td><span class="badge <?= e('badge-' . $status) ?> text-uppercase"><?= e($status) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Recent Alerts</h2>
            <a href="<?= e(app_url('admin/alert-logs.php')) ?>" class="btn btn-sm btn-outline-info">View All</a>
        </div>
        <ul class="list-group list-group-flush">
            <?php if (empty($recentAlerts)): ?>
                <li class="list-group-item bg-transparent table-empty">No alerts yet.</li>
            <?php endif; ?>
                <?php foreach ($recentAlerts as $alert): ?>
                    <?php
                    $sev = (string) $alert['severity'];
                    $sevClass = match ($sev) {
                    'danger' => 'badge-severity badge-severity-danger',
                    'warning' => 'badge-severity badge-severity-warning',
                    'success' => 'badge-severity badge-severity-success',
                    default => 'badge-severity badge-severity-info',
                };
                ?>
                <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center">
                    <div>
                        <span class="badge <?= e($sevClass) ?> text-uppercase me-2"><?= e($sev) ?></span>
                        <?= e((string) $alert['title']) ?>
                    </div>
                    <small class="text-secondary"><?= e((string) $alert['created_at']) ?></small>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
</main>
<script>
window.SERVMON_API_STATUS = "<?= e(app_url('api/status.php?include_inactive=1')) ?>";
window.SERVMON_ADMIN_DETAIL_BASE = "<?= e(app_url('admin/server-detail.php?id=')) ?>";
</script>
<script src="<?= e(asset_url('assets/js/dashboard.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
