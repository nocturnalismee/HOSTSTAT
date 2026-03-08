<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/maintenance.php';
require_once __DIR__ . '/../includes/settings.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('danger', 'Invalid server.');
    redirect('/admin/servers.php');
}

$server = db_one(
    'SELECT s.id, s.name, s.location, s.provider, s.label, s.host, s.type, s.agent_mode, s.active, s.maintenance_mode, s.maintenance_until,
            m.recorded_at AS last_seen, m.uptime, m.ram_total, m.ram_used, m.hdd_total, m.hdd_used, m.cpu_load, m.mail_mta, m.mail_queue_total,
            m.network_in_bps, m.network_out_bps, m.panel_profile
     FROM servers s' . latest_metric_join_sql('s', 'm') . '
     WHERE s.id = :id LIMIT 1',
    [':id' => $id]
);
if ($server === null) {
    flash_set('danger', 'Server not found.');
    redirect('/admin/servers.php');
}

$statusOnlineMinutes = max(1, (int) setting_get('alert_down_minutes'));
$mailQueueWarnThreshold = max(0, (int) setting_get('threshold_mail_queue'));
$mailQueueCriticalThreshold = max(
    $mailQueueWarnThreshold,
    (int) setting_get('threshold_mail_queue_critical')
);
$status = serverStatusFromLastSeen($server['last_seen'] ?? null, (int) ($server['active'] ?? 0) === 1, $statusOnlineMinutes);
$ramPct = calculateUsagePercent((int) ($server['ram_used'] ?? 0), (int) ($server['ram_total'] ?? 0));
$hddPct = calculateUsagePercent((int) ($server['hdd_used'] ?? 0), (int) ($server['hdd_total'] ?? 0));
$serverMetaItems = [
    ['label' => 'Host', 'value' => (string) ($server['host'] ?? '-')],
    ['label' => 'Location', 'value' => (string) ($server['location'] ?? '-')],
    ['label' => 'Provider', 'value' => (string) ($server['provider'] ?? '-')],
    ['label' => 'Type', 'value' => (string) ($server['type'] ?? '-')],
    ['label' => 'Label', 'value' => (string) ($server['label'] ?? '-')],
    ['label' => 'Panel', 'value' => (string) ($server['panel_profile'] ?? 'generic')],
];
$services = db_all(
    'SELECT service_group, service_key, unit_name, last_status, updated_at
     FROM server_service_states
     WHERE server_id = :id
     ORDER BY service_group ASC, service_key ASC',
    [':id' => $id]
);
$historyEndpoint = app_url('api/status.php?id=' . $id . '&history=30m&points=1200');
$historyBootstrap = db_all(
    'SELECT
        DATE_FORMAT(recorded_at, "%Y-%m-%d %H:%i:%s") AS recorded_at,
        ram_used, hdd_used, cpu_load, network_in_bps, network_out_bps
     FROM metrics
     WHERE server_id = :id AND recorded_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
     ORDER BY recorded_at ASC
     LIMIT 1200',
    [':id' => $id]
);

$title = APP_NAME . ' - Server Details';
$activeNav = 'servers';
require_once __DIR__ . '/../includes/layout/head.php';
require_once __DIR__ . '/../includes/layout/nav.php';
require_once __DIR__ . '/../includes/layout/flash.php';
?>
<main class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title"><?= e((string) $server['name']) ?></h1>
            <ul class="page-subtitle server-meta-list" aria-label="Server metadata">
                <?php foreach ($serverMetaItems as $meta): ?>
                    <li class="server-meta-chip">
                        <span class="server-meta-key"><?= e((string) $meta['label']) ?></span>
                        <span class="server-meta-value"><?= e((string) $meta['value']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="toolbar-actions align-items-center">
            <span class="badge <?= e('badge-' . $status) ?> text-uppercase"><?= e($status) ?></span>
            <a class="btn btn-outline-info btn-sm" href="<?= e(app_url('admin/disk-health-detail.php?id=' . (int) $server['id'])) ?>">Disk Health</a>
            <?php if (has_role('admin')): ?>
                <a class="btn btn-soft btn-sm" href="<?= e(app_url('admin/server-edit.php?id=' . (int) $server['id'])) ?>">Edit</a>
            <?php endif; ?>
        </div>
    </section>
    <?php if ((int) ($server['maintenance_mode'] ?? 0) === 1): ?>
        <div class="alert alert-warning py-2">
            Maintenance mode is active. <?= e(maintenance_display_text($server)) ?>.
        </div>
    <?php endif; ?>

    <section class="row g-3" data-ui-section>
        <div class="col-md-3">
            <div class="card card-neon p-3 h-100 server-stat-card">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="text-secondary small">Uptime</div>
                    <i class="ti ti-history stat-icon"></i>
                </div>
                <div class="h5 mb-0 stat-value"><?= e(formatUptime((int) ($server['uptime'] ?? 0))) ?></div>
                <div class="small text-secondary stat-meta">Agent: <?= e((string) ($server['agent_mode'] ?? 'push')) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-neon p-3 h-100 server-stat-card">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="text-secondary small">CPU Load</div>
                    <i class="ti ti-cpu stat-icon"></i>
                </div>
                <div class="h5 mb-0 stat-value"><?= e(number_format((float) ($server['cpu_load'] ?? 0), 2)) ?></div>
                <div class="small text-secondary mt-2 cpu-summary-grid">
                    <div class="d-flex justify-content-between"><span>High</span><span id="cpuLoadHigh"><?= e(number_format((float) ($server['cpu_load'] ?? 0), 2)) ?></span></div>
                    <div class="d-flex justify-content-between"><span>Low</span><span id="cpuLoadLow"><?= e(number_format((float) ($server['cpu_load'] ?? 0), 2)) ?></span></div>
                    <div class="d-flex justify-content-between"><span>Daily Avg</span><span id="cpuLoadDailyAvg"><?= e(number_format((float) ($server['cpu_load'] ?? 0), 2)) ?></span></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-neon p-3 h-100 server-stat-card">
                <?php $queueTotalTop = (int) ($server['mail_queue_total'] ?? 0); ?>
                <?php
                if ($queueTotalTop >= $mailQueueCriticalThreshold) {
                    $queueState = 'Danger';
                    $queueClass = 'text-danger';
                } elseif ($queueTotalTop >= $mailQueueWarnThreshold) {
                    $queueState = 'Warning';
                    $queueClass = 'text-warning';
                } else {
                    $queueState = 'Normal';
                    $queueClass = 'text-success';
                }
                ?>
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="text-secondary small">Mail Queue</div>
                    <i class="ti ti-mail stat-icon"></i>
                </div>
                <div class="h5 mb-0 stat-value <?= e($queueClass) ?>"><?= e((string) $queueTotalTop) ?> emails</div>
                <div class="small text-secondary stat-meta"><?= e((string) ($server['mail_mta'] ?? 'none')) ?> | <?= e($queueState) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-neon p-3 h-100 server-stat-card">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="text-secondary small">Last Seen</div>
                    <i class="ti ti-calendar-check stat-icon"></i>
                </div>
                <div class="h5 mb-0 stat-value"><?= e((string) ($server['last_seen'] ?? '-')) ?></div>
                <div class="small text-secondary stat-meta">State: <?= e(strtoupper($status)) ?></div>
            </div>
        </div>
    </section>

    <section class="card card-neon p-3" data-ui-section>
        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <?php $ramPctSafe = max(0, min(100, (float) $ramPct)); ?>
                <?php $ramTone = $ramPctSafe >= 80 ? 'critical' : ($ramPctSafe > 60 ? 'warning' : 'ok'); ?>
                <div class="usage-ring-block">
                    <h2 class="h6 mb-3">RAM</h2>
                    <div class="usage-ring-layout">
                        <div class="usage-ring usage-ring-<?= e($ramTone) ?>" style="--sv-pct: <?= e(number_format($ramPctSafe, 1, '.', '')) ?>;" role="img" aria-label="RAM usage <?= e(number_format($ramPctSafe, 1)) ?> percent">
                            <div class="usage-ring-inner">
                                <span class="usage-ring-value"><?= e(number_format($ramPctSafe, 1)) ?>%</span>
                            </div>
                        </div>
                        <div class="usage-ring-meta">
                            <div class="usage-ring-title">Memory Utilization</div>
                            <div class="usage-ring-desc"><?= e(formatBytes((int) ($server['ram_used'] ?? 0))) ?> / <?= e(formatBytes((int) ($server['ram_total'] ?? 0))) ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <?php $hddPctSafe = max(0, min(100, (float) $hddPct)); ?>
                <?php $hddTone = $hddPctSafe >= 80 ? 'critical' : ($hddPctSafe > 60 ? 'warning' : 'ok'); ?>
                <div class="usage-ring-block">
                    <h2 class="h6 mb-3">Disk</h2>
                    <div class="usage-ring-layout">
                        <div class="usage-ring usage-ring-<?= e($hddTone) ?>" style="--sv-pct: <?= e(number_format($hddPctSafe, 1, '.', '')) ?>;" role="img" aria-label="Disk usage <?= e(number_format($hddPctSafe, 1)) ?> percent">
                            <div class="usage-ring-inner">
                                <span class="usage-ring-value"><?= e(number_format($hddPctSafe, 1)) ?>%</span>
                            </div>
                        </div>
                        <div class="usage-ring-meta">
                            <div class="usage-ring-title">Disk Utilization</div>
                            <div class="usage-ring-desc"><?= e(formatBytes((int) ($server['hdd_used'] ?? 0))) ?> / <?= e(formatBytes((int) ($server['hdd_total'] ?? 0))) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft">
            <h2 class="h6 mb-0">Service Status</h2>
        </div>
        <div class="table-responsive table-shell">
            <table class="table servmon-table mb-0">
                <thead>
                <tr>
                    <th>Group</th>
                    <th>Service</th>
                    <th>Unit</th>
                    <th>Status</th>
                    <th>Updated At</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($services)): ?>
                    <tr><td colspan="5" class="table-empty">No service data yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($services as $svc): ?>
                    <?php
                    $svcStatus = (string) ($svc['last_status'] ?? 'unknown');
                    $svcClass = match ($svcStatus) {
                        'up' => 'badge-online',
                        'down' => 'badge-down',
                        default => 'badge-pending',
                    };
                    ?>
                    <tr>
                        <td><?= e((string) ($svc['service_group'] ?? '-')) ?></td>
                        <td><?= e((string) ($svc['service_key'] ?? '-')) ?></td>
                        <td><code><?= e((string) ($svc['unit_name'] ?? '-')) ?></code></td>
                        <td><span class="badge <?= e($svcClass) ?> text-uppercase"><?= e($svcStatus) ?></span></td>
                        <td><?= e((string) ($svc['updated_at'] ?? '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Historical Charts</h2>
            <div class="d-flex flex-wrap gap-2">
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-soft" data-range="5m">5m</button>
                    <button type="button" class="btn btn-soft active" data-range="30m">30m</button>
                    <button type="button" class="btn btn-soft" data-range="24h">24h</button>
                    <button type="button" class="btn btn-soft" data-range="7d">7d</button>
                    <button type="button" class="btn btn-soft" data-range="30d">30d</button>
                </div>
                <button type="button" class="btn btn-soft btn-sm" data-reset-zoom>Reset Zoom</button>
            </div>
        </div>
        <div class="card-body">
            <p class="small text-secondary mb-3">Tip: use mouse wheel to zoom and drag inside the plot to pan, then click <strong>Reset Zoom</strong>.</p>
            <div class="row g-3">
                <div class="col-lg-6"><div class="card bg-surface border-soft p-3"><h3 class="h6 mb-3">RAM Usage</h3><div id="ramHistoryChart" style="height: 220px;"></div></div></div>
                <div class="col-lg-6"><div class="card bg-surface border-soft p-3"><h3 class="h6 mb-3">Disk Usage</h3><div id="diskHistoryChart" style="height: 220px;"></div></div></div>
                <div class="col-lg-6"><div class="card bg-surface border-soft p-3"><h3 class="h6 mb-3">CPU Load</h3><div id="cpuHistoryChart" style="height: 220px;"></div></div></div>
                <div class="col-lg-6"><div class="card bg-surface border-soft p-3"><h3 class="h6 mb-3">Network In/Out</h3><div id="networkHistoryChart" style="height: 220px;"></div></div></div>
            </div>
        </div>
    </section>
</main>
<script>
window.SERVMON_HISTORY_BOOTSTRAP = <?= json_encode($historyBootstrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= e(asset_url('assets/js/detail.js')) ?>"></script>
  <script>
  const baseHistoryEndpoint = "<?= e(app_url('api/status.php?id=' . $id . '&points=1200')) ?>";
  if (Array.isArray(window.SERVMON_HISTORY_BOOTSTRAP) && window.SERVMON_HISTORY_BOOTSTRAP.length > 0) {
    bootstrapHistory(window.SERVMON_HISTORY_BOOTSTRAP);
  }
  let activeRange = "30m";
  loadHistory("<?= e($historyEndpoint) ?>");
  document.querySelectorAll("[data-range]").forEach((button) => {
    button.addEventListener("click", () => {
      activeRange = button.dataset.range;
      document.querySelectorAll("[data-range]").forEach((btn) => btn.classList.remove("active"));
      button.classList.add("active");
      loadHistory(`${baseHistoryEndpoint}&history=${activeRange}`);
    });
  });
  setInterval(() => loadHistory(`${baseHistoryEndpoint}&history=${activeRange}`), 30000);
</script>
<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
