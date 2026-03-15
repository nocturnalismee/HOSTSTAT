<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/settings.php';

$latestMetricJoin = latest_metric_join_sql('s', 'm');
$rows = db_all(
    'SELECT s.id, s.name, s.location, s.type, s.active,
            m.recorded_at AS last_seen, m.uptime, m.ram_total, m.ram_used, m.hdd_total, m.hdd_used, m.cpu_load, m.network_in_bps, m.network_out_bps, m.mail_mta, m.mail_queue_total
     FROM servers s' . $latestMetricJoin . '
     WHERE s.active = 1'
);
$statusOnlineMinutes = max(1, (int) setting_get('alert_down_minutes'));

sortServersBySeverity($rows, $statusOnlineMinutes);

$total = count($rows);
$online = 0;
$down = 0;
$pending = 0;
foreach ($rows as $row) {
    $status = serverStatusFromLastSeen($row['last_seen'] ?? null, (int) ($row['active'] ?? 0) === 1, $statusOnlineMinutes);
    if ($status === 'online') {
        $online++;
    } elseif ($status === 'down') {
        $down++;
    } else {
        $pending++;
    }
}

// Using calculateUsagePercent() from helpers.php (properly rounded to 1 decimal)

$title = APP_NAME . ' - Public Status';
$uiSettings = settings_get_all();
$brandingLogoRaw = trim((string) ($uiSettings['branding_logo_url'] ?? ''));
$brandingLogoUrl = '';
if ($brandingLogoRaw !== '') {
    if (preg_match('/^(https?:)?\/\//i', $brandingLogoRaw) === 1 || str_starts_with($brandingLogoRaw, 'data:')) {
        $brandingLogoUrl = $brandingLogoRaw;
    } else {
        $brandingLogoUrl = app_url(ltrim($brandingLogoRaw, '/'));
    }
}
require_once __DIR__ . '/includes/layout/head.php';
?>
<main class="container py-4">
    <header class="mb-2 py-1">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3">
            <div>
                <h1 class="h3 mb-1 d-flex align-items-center gap-2">
                    <?php if ($brandingLogoUrl !== ''): ?>
                        <img class="servmon-brand-logo" src="<?= e($brandingLogoUrl) ?>" alt="<?= e(APP_NAME) ?> logo">
                    <?php endif; ?>
                    <span><?= e(APP_NAME) ?></span>
                </h1>
                <p class="text-secondary mb-0">A Lightweight server monitoring system</p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-info" type="button" data-theme-toggle title="Toggle Theme" aria-label="Toggle Theme">
                    <i class="ti ti-contrast-2"></i>
                </button>
                <a class="btn btn-outline-light" href="<?= e(app_url('auth/login.php')) ?>" title="Admin Login" aria-label="Admin Login">
                    <i class="ti ti-login-2"></i>
                </a>
            </div>
        </div>
    </header>

    <div class="row g-3 mb-4 summary-grid">
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-total p-3">
                <div class="summary-card-head">
                    <span class="summary-card-label">Total Servers</span>
                    <i class="ti ti-server-2 summary-card-icon" aria-hidden="true"></i>
                </div>
                <div class="summary-card-value" data-public-total><?= e((string) $total) ?></div>
                <div class="summary-card-subtitle">Monitored hosts</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-online p-3">
                <div class="summary-card-head">
                    <span class="summary-card-label">Online</span>
                    <i class="ti ti-arrow-up-circle summary-card-icon" aria-hidden="true"></i>
                </div>
                <div class="summary-card-value" data-public-online><?= e((string) $online) ?></div>
                <div class="summary-card-subtitle">Healthy status</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-down p-3">
                <div class="summary-card-head">
                    <span class="summary-card-label">Down</span>
                    <i class="ti ti-alert-triangle summary-card-icon" aria-hidden="true"></i>
                </div>
                <div class="summary-card-value" data-public-down><?= e((string) $down) ?></div>
                <div class="summary-card-subtitle">Needs attention</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-pending p-3">
                <div class="summary-card-head">
                    <span class="summary-card-label">Pending</span>
                    <i class="ti ti-history summary-card-icon" aria-hidden="true"></i>
                </div>
                <div class="summary-card-value" data-public-pending><?= e((string) $pending) ?></div>
                <div class="summary-card-subtitle">Awaiting check-in</div>
            </div>
        </div>
    </div>

    <div class="card card-neon">
        <div class="table-responsive">
            <table class="table servmon-table public-summary-table mb-0">
                <colgroup>
                    <col style="width: 9rem;">
                    <col style="width: 8.5rem;">
                    <col style="width: 6.5rem;">
                    <col style="width: 6rem;">
                    <col style="width: 19rem;">
                    <col style="width: 19rem;">
                    <col style="width: 7rem;">
                    <col style="width: 9rem;">
                    <col style="width: 6rem;">
                </colgroup>
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Location</th>
                    <th>Status</th>
                    <th>Uptime</th>
                    <th>RAM</th>
                    <th>Disk</th>
                    <th>CPU</th>
                    <th>NET</th>
                    <th>QUEUE</th>
                </tr>
                </thead>
                <tbody data-public-server-table>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $status = serverStatusFromLastSeen($row['last_seen'] ?? null, (int) ($row['active'] ?? 0) === 1, $statusOnlineMinutes);
                    $ramUsed = max(0, (int) ($row['ram_used'] ?? 0));
                    $ramTotal = max(0, (int) ($row['ram_total'] ?? 0));
                    $ramPct = calculateUsagePercent($ramUsed, $ramTotal);
                    $diskUsed = max(0, (int) ($row['hdd_used'] ?? 0));
                    $diskTotal = max(0, (int) ($row['hdd_total'] ?? 0));
                    $diskPct = calculateUsagePercent($diskUsed, $diskTotal);
                    
                    $score = calculateSeverityScore($row, $statusOnlineMinutes);
                    $rowClass = '';
                    if ($score >= 500) {
                        $rowClass = 'server-row-critical';
                    } elseif ($score >= 100) {
                        $rowClass = 'server-row-warning';
                    }
                    ?>
                    <tr<?= $rowClass !== '' ? ' class="' . e($rowClass) . '"' : '' ?>>
                        <td>
                            <span class="table-cell-truncate" title="<?= e((string) $row['name']) ?>">
                                <?= e((string) $row['name']) ?>
                            </span>
                        </td>
                        <td><?= e($row['location'] ?? '-') ?></td>
                        <td><span class="badge <?= e('badge-' . $status) ?> text-uppercase"><?= e($status) ?></span></td>
                        <td class="font-mono"><?= e(formatUptime((int) ($row['uptime'] ?? 0))) ?></td>
                        <td>
                            <div class="resource-cell">
                                <div class="resource-label">
                                    <span><?= e(formatBytes($ramUsed)) ?> / <?= e(formatBytes($ramTotal)) ?></span>
                                    <span class="font-mono"><?= e(number_format($ramPct, 1)) ?>%</span>
                                </div>
                                <div class="progress resource-progress" role="progressbar" aria-label="RAM usage" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e((string) round($ramPct, 1)) ?>">
                                    <div class="progress-bar resource-progress-bar <?= e($ramPct >= 80 ? 'is-critical' : ($ramPct > 60 ? 'is-warning' : 'is-ok')) ?>" style="<?= e('--target-width:' . number_format($ramPct, 1, '.', '') . '%') ?>"></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="resource-cell">
                                <div class="resource-label">
                                    <span><?= e(formatBytes($diskUsed)) ?> / <?= e(formatBytes($diskTotal)) ?></span>
                                    <span class="font-mono"><?= e(number_format($diskPct, 1)) ?>%</span>
                                </div>
                                <div class="progress resource-progress" role="progressbar" aria-label="Disk usage" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e((string) round($diskPct, 1)) ?>">
                                    <div class="progress-bar resource-progress-bar <?= e($diskPct >= 80 ? 'is-critical' : ($diskPct > 60 ? 'is-warning' : 'is-ok')) ?>" style="<?= e('--target-width:' . number_format($diskPct, 1, '.', '') . '%') ?>"></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="cpu-cell">
                                <div class="cpu-value font-mono"><?= e(number_format((float) ($row['cpu_load'] ?? 0), 2)) ?></div>
                                <svg class="cpu-sparkline" width="60" height="18"><polyline fill="none" stroke="var(--sv-muted)" stroke-width="1.5" points="0,16.0 60,16.0"/></svg>
                            </div>
                        </td>
                        <td>
                            <div class="net-line"><i class="ti ti-arrow-down" aria-label="In"></i><span class="font-mono"><?= e(formatNetworkBps((int) ($row['network_in_bps'] ?? 0))) ?></span></div>
                            <div class="net-line text-secondary"><i class="ti ti-arrow-up" aria-label="Out"></i><span class="font-mono"><?= e(formatNetworkBps((int) ($row['network_out_bps'] ?? 0))) ?></span></div>
                        </td>
                        <?php $mailQueue = max(0, (int) ($row['mail_queue_total'] ?? 0)); ?>
                        <td class="font-mono"><?= e((string) $mailQueue) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<script>window.SERVMON_API_STATUS = "<?= e(app_url('api/status.php')) ?>";window.SERVMON_API_SSE = "<?= e(app_url('api/sse.php')) ?>";</script>
<script src="<?= e(asset_url('assets/js/public.js')) ?>"></script>
<?php require_once __DIR__ . '/includes/layout/footer.php'; ?>
